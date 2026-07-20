<?php
/**
 * GET /champion_stats.php?champion=Yasuo&role=mid&region=euw
 *
 * Devuelve estadísticas REALES agregadas de partidas de Challenger:
 *   - Winrate, pickrate, banrate (medidos)
 *   - Variantes de build agrupadas por primer legendario
 *   - Runas más usadas
 *   - Hechizos de invocador más usados
 *   - Skill priority (max order Q/W/E)
 *   - Counters (matchups perdidos)
 *   - Synergies (compañeros ganadores)
 *   - Botas más usadas
 *   - Situacionales frecuentes
 *
 * Fuente: top 30 jugadores challenger de la región + sus últimas 5 partidas SoloQ ranked.
 * Cache 6h por (champion, role, región).
 */
require __DIR__ . '/riot.php';

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(1200); // Hasta 20 min para casos extremos (All Tiers Global cold cache)
@ini_set('max_execution_time', 1200);

$champion = trim($_GET['champion'] ?? '');
$role     = strtolower(trim($_GET['role'] ?? 'mid'));
$region   = strtolower(trim($_GET['region'] ?? 'euw'));
$rank     = strtolower(trim($_GET['rank'] ?? 'challenger'));  // challenger | grandmaster | master | masterplus

if (!$champion) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta champion']);
    exit;
}

$validRanks = [
    'all',
    'challenger', 'grandmaster', 'master', 'masterplus',
    'diamond', 'diamondplus',
    'emerald', 'emeraldplus',
    'platinum', 'platinumplus',
    'gold', 'goldplus',
    'silver', 'bronze', 'iron',
];
if (!in_array($rank, $validRanks, true)) $rank = 'challenger';

$isGlobal = $region === 'global';
if (!$isGlobal) {
    try { riot_resolve_region($region); }
    catch (Throwable $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit; }
}

// ---- Resolver campeón ------------------------------------------------
$champions = dd_get_champions();
$champId = null;
$champNumericId = null;
foreach ($champions as $id => $c) {
    if (strcasecmp($c['name'], $champion) === 0 || $id === $champion) {
        $champId = $id;
        break;
    }
}
if (!$champId) {
    http_response_code(404);
    echo json_encode(['error' => "Campeón no encontrado: {$champion}"]);
    exit;
}
// Encontrar el ID numérico (Riot API usa números)
$idMap = riot_load_champion_id_map();
foreach ($idMap as $num => $key) {
    if ($key === $champId) { $champNumericId = $num; break; }
}
if (!$champNumericId) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo mapear champId']);
    exit;
}

// Mapa lane frontend → riot teamPosition
$laneMap = [
    'top'   => 'TOP',
    'jng'   => 'JUNGLE', 'jungle' => 'JUNGLE',
    'mid'   => 'MIDDLE',
    'adc'   => 'BOTTOM', 'bot' => 'BOTTOM', 'bottom' => 'BOTTOM',
    'supp'  => 'UTILITY', 'sup' => 'UTILITY', 'support' => 'UTILITY',
];
$riotRole = $laneMap[$role] ?? strtoupper($role);

// ---- Cache -----------------------------------------------------------
$version = dd_get_version();
$cacheKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $champId . '_' . $role . '_' . $region . '_' . $rank . '_' . $version));
$cachePath = __DIR__ . '/cache/champstats_' . $cacheKey . '.json';

if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 6 * 3600) {
    header('X-Cache: HIT');
    readfile($cachePath);
    exit;
}

// ---- Tiers incluidos según rank + prioridad ------------------------
// Devuelve lista de tiers en orden descendente (de más alto a más bajo)
function tiers_for_rank(string $rankKey): array {
    switch ($rankKey) {
        case 'challenger':    return ['CHALLENGER'];
        case 'grandmaster':   return ['GRANDMASTER'];
        case 'master':        return ['MASTER'];
        case 'masterplus':    return ['CHALLENGER', 'GRANDMASTER', 'MASTER'];
        case 'diamond':       return ['DIAMOND'];
        case 'diamondplus':   return ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND'];
        case 'emerald':       return ['EMERALD'];
        case 'emeraldplus':   return ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND', 'EMERALD'];
        case 'platinum':      return ['PLATINUM'];
        case 'platinumplus':  return ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND', 'EMERALD', 'PLATINUM'];
        case 'gold':          return ['GOLD'];
        case 'goldplus':      return ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND', 'EMERALD', 'PLATINUM', 'GOLD'];
        case 'silver':        return ['SILVER'];
        case 'bronze':        return ['BRONZE'];
        case 'iron':          return ['IRON'];
        case 'all':           return ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND', 'EMERALD', 'PLATINUM', 'GOLD', 'SILVER', 'BRONZE', 'IRON'];
        default:              return ['CHALLENGER'];
    }
}

// Fetch entries de un tier concreto en una región
function fetch_tier_entries(string $reg, string $tier): array {
    try {
        if ($tier === 'CHALLENGER')  return riot_challenger_league($reg)['body']['entries'] ?? [];
        if ($tier === 'GRANDMASTER') return riot_grandmaster_league($reg)['body']['entries'] ?? [];
        if ($tier === 'MASTER')      return riot_master_league($reg)['body']['entries'] ?? [];
        // Tiers inferiores: usar league-exp-v4 (División I = jugadores más top del tier)
        $resp = riot_league_exp_entries($reg, $tier, 'I', 1);
        return $resp['status'] === 200 && is_array($resp['body']) ? $resp['body'] : [];
    } catch (Throwable $e) {
        return [];
    }
}

// ---- Función: obtener entries agregadas por región+rank ------------
$fetchEntriesForRegion = function(string $reg, string $rankKey, int $maxPlayers): array {
    $tiers = tiers_for_rank($rankKey);
    if (empty($tiers)) return [];
    $perTier = max(3, (int)ceil($maxPlayers / count($tiers)));
    $out = [];
    foreach ($tiers as $tier) {
        $entries = fetch_tier_entries($reg, $tier);
        if (empty($entries)) continue;
        usort($entries, fn($a, $b) => ($b['leaguePoints'] ?? 0) <=> ($a['leaguePoints'] ?? 0));
        foreach (array_slice($entries, 0, $perTier) as $e) $out[] = $e;
    }
    return $out;
};

// ---- Pool de partidas ------------------------------------------------
$poolCacheKey = "pool_{$region}_{$rank}";
$poolCachePath = __DIR__ . '/cache/' . $poolCacheKey . '.json';
$matchesByRegion = []; // Map: matchId => region (para saber a qué región pedir el detalle)

// Cache del pool 12h (compartido entre todos los campeones para esa combinación región+rank)
if (file_exists($poolCachePath) && (time() - filemtime($poolCachePath)) < 12 * 3600) {
    $matchesByRegion = json_decode(file_get_contents($poolCachePath), true) ?: [];
} else {
    try {
        $regionsToSample = $isGlobal ? riot_global_regions() : [$region];
        $playersPerRegion = $isGlobal ? 15 : 30;

        foreach ($regionsToSample as $reg) {
            $entries = $fetchEntriesForRegion($reg, $rank, $playersPerRegion);
            if (empty($entries)) continue;

            // Los entries ya vienen distribuidos entre tiers; los ordenamos globalmente por LP
            usort($entries, fn($a, $b) => ($b['leaguePoints'] ?? 0) <=> ($a['leaguePoints'] ?? 0));
            $topEntries = array_slice($entries, 0, $playersPerRegion);

            // 20 partidas por jugador × 30 jugadores = 600 IDs por región
            // Global: 20 × 15 × 3 = 900 IDs. Con deduplicación quedan ~400-600 únicas
            $matchesPerPlayer = $isGlobal ? 20 : 25;
            foreach ($topEntries as $e) {
                $puuid = $e['puuid'] ?? null;
                if (!$puuid) continue;
                try {
                    $idsResp = riot_match_ids($reg, $puuid, $matchesPerPlayer, 420);
                    if ($idsResp['status'] !== 200) continue;
                    foreach (($idsResp['body'] ?? []) as $mid) $matchesByRegion[$mid] = $reg;
                } catch (Throwable $e) {}
            }
        }
        file_put_contents($poolCachePath, json_encode($matchesByRegion));
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => 'Error construyendo pool: ' . $e->getMessage()]);
        exit;
    }
}
$matchIds = array_keys($matchesByRegion);
// Mezclar para muestreo equitativo entre regiones (evita que 'global' inspeccione solo las de la primera región)
shuffle($matchIds);

if (empty($matchIds)) {
    http_response_code(502);
    echo json_encode(['error' => 'No hay partidas de challenger disponibles']);
    exit;
}

// ---- Iterar partidas y filtrar por campeón + rol ---------------------
$matches = [];         // partidas donde nuestro campeón aparece en el rol solicitado
$totalGamesInspected = 0;
$bansCount = 0;
$picksAcrossAllMatches = 0; // veces que el campeón se picó en cualquier rol
// Con dev key (100 req/2min), cada 100 partidas nuevas = 2 min de espera obligatoria.
// Cap 250 = ~4-5 min primera vez, luego instantáneo por 12h.
// Si consigues Personal API key (3000 req/10s), sube esto a 1000+ sin problema.
$maxMatchesToInspect = min(count($matchIds), 250);

// Agrupar los match IDs por región y hacer batches paralelos
$idsToProcess = array_slice($matchIds, 0, $maxMatchesToInspect);
$byRegion = []; // region => [mid, mid, ...]
foreach ($idsToProcess as $mid) {
    $mReg = $matchesByRegion[$mid] ?? ($isGlobal ? 'euw' : $region);
    $byRegion[$mReg][] = $mid;
}

foreach ($byRegion as $mReg => $mids) {
    $batchResults = riot_match_details_batch($mReg, $mids);
    foreach ($batchResults as $mid => $detail) {
        if ($detail['status'] !== 200) continue;
        $info = $detail['body']['info'] ?? [];
        if (($info['queueId'] ?? 0) !== 420) continue;
        $totalGamesInspected++;

        // Bans
        foreach ($info['teams'] ?? [] as $team) {
            foreach ($team['bans'] ?? [] as $b) {
                if (($b['championId'] ?? 0) === $champNumericId) $bansCount++;
            }
        }

        // Picks
        foreach ($info['participants'] ?? [] as $p) {
            if (($p['championId'] ?? 0) !== $champNumericId) continue;
            $picksAcrossAllMatches++;
            if (($p['teamPosition'] ?? '') === $riotRole) {
                $matches[] = ['match' => $mid, 'info' => $info, 'player' => $p];
            }
        }
    }
}

if (empty($matches)) {
    // Fallback: aunque no haya en el rol pedido, devolvemos qué encontramos
    http_response_code(200);
    echo json_encode([
        'champion' => $champions[$champId]['name'],
        'champion_icon' => dd_champion_icon_url($champId),
        'role' => $role,
        'region' => $region,
        'patch' => $version,
        'data_source' => [
            'type' => 'aggregated',
            'matches_inspected' => $totalGamesInspected,
            'games_with_champion_any_role' => $picksAcrossAllMatches,
            'games_matched' => 0,
            'rank_filter' => $rank,
            'is_global' => $isGlobal,
        ],
        'error' => "Este campeón no aparece en el rol {$role} en las últimas partidas de {$rank}" . ($isGlobal ? ' Global' : ' de ' . strtoupper($region)) . ". Prueba otro rol o baja el rango.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Agregación ------------------------------------------------------
$totalMatchedGames = count($matches);
$wins = 0;
$startingSetsCount    = []; // "id1|id2|id3" => count
$startingSetsWins     = [];
$mainItemStats        = []; // itemId => {games, wins}
$coreByMainStats      = []; // mainItemId => "id1|id2|id3" => {games, wins}
$startingByMainStats  = []; // mainItemId => "starting sig" => {games, wins}
$bootsStats           = []; // itemId => {games, wins}
$bootsByMainStats     = []; // mainItemId => bootId => {games, wins}
$runesByMainStats     = []; // mainItemId => "keystone_id" => {games, wins, primary, secondary, primary_runes[], secondary_runes[]}
$summonersByMainStats = []; // mainItemId => "s1|s2" => {games, wins}
$situationalItems     = []; // itemId => {games} (items comprados que no son core/boots)
$counters             = []; // championId enemy → {games, wins}
$synergies            = []; // championId ally  → {games, wins}
$roleFrequency        = []; // teamPosition where champion is played (across all inspected)

// Lista de items comunes que son "botas"
$allItems = dd_get_items();
$isBoots = function(int $itemId) use ($allItems): bool {
    $it = $allItems[$itemId] ?? null;
    return $it && in_array('Boots', $it['tags'] ?? [], true);
};
$isLegendary = function(int $itemId) use ($allItems): bool {
    $it = $allItems[$itemId] ?? null;
    if (!$it) return false;
    return ($it['gold'] ?? 0) >= 2000 && !in_array('Boots', $it['tags'] ?? [], true);
};

foreach ($matches as $m) {
    $info = $m['info'];
    $p    = $m['player'];
    $win  = (bool)($p['win'] ?? false);
    if ($win) $wins++;

    // Items del jugador en el orden en que están en el inventario (0-5, ignoramos 6 trinket)
    $finalItems = [];
    for ($i = 0; $i <= 5; $i++) {
        $iid = $p['item' . $i] ?? 0;
        if ($iid > 0) $finalItems[] = $iid;
    }

    // Detectar primer legendario comprado (aproximación: el primer legendario en el inventario final)
    $mainLegendary = null;
    foreach ($finalItems as $iid) {
        if ($isLegendary($iid)) { $mainLegendary = $iid; break; }
    }
    if (!$mainLegendary && !empty($finalItems)) $mainLegendary = $finalItems[0];

    // Botas
    $boots = null;
    foreach ($finalItems as $iid) if ($isBoots($iid)) { $boots = $iid; break; }

    // Core: los 3 primeros legendarios del inventario final
    $legendariesInInv = array_values(array_filter($finalItems, $isLegendary));
    $core = array_slice($legendariesInInv, 0, 3);
    $situational = array_slice($legendariesInInv, 3);

    // Registrar main item
    if ($mainLegendary) {
        if (!isset($mainItemStats[$mainLegendary])) $mainItemStats[$mainLegendary] = ['games' => 0, 'wins' => 0];
        $mainItemStats[$mainLegendary]['games']++;
        if ($win) $mainItemStats[$mainLegendary]['wins']++;

        // Core path bajo este main
        if (!empty($core)) {
            $coreKey = implode('|', $core);
            $coreByMainStats[$mainLegendary][$coreKey] ??= ['games' => 0, 'wins' => 0];
            $coreByMainStats[$mainLegendary][$coreKey]['games']++;
            if ($win) $coreByMainStats[$mainLegendary][$coreKey]['wins']++;
        }

        // Botas bajo este main
        if ($boots) {
            $bootsByMainStats[$mainLegendary][$boots] ??= ['games' => 0, 'wins' => 0];
            $bootsByMainStats[$mainLegendary][$boots]['games']++;
            if ($win) $bootsByMainStats[$mainLegendary][$boots]['wins']++;
        }
    }

    // Botas globales
    if ($boots) {
        $bootsStats[$boots] ??= ['games' => 0, 'wins' => 0];
        $bootsStats[$boots]['games']++;
        if ($win) $bootsStats[$boots]['wins']++;
    }

    // Situacionales
    foreach ($situational as $iid) {
        $situationalItems[$iid] ??= ['games' => 0];
        $situationalItems[$iid]['games']++;
    }

    // Runas
    $perks = $p['perks'] ?? [];
    $keystone = null;
    $primaryStyle = $perks['styles'][0]['style'] ?? 0;
    $secondaryStyle = $perks['styles'][1]['style'] ?? 0;
    $primaryRunes = [];
    $secondaryRunes = [];
    $shards = $perks['statPerks'] ?? [];

    foreach ($perks['styles'] ?? [] as $sIdx => $style) {
        $selections = $style['selections'] ?? [];
        if ($sIdx === 0) {
            foreach ($selections as $selIdx => $sel) {
                if ($selIdx === 0) $keystone = $sel['perk'];
                else $primaryRunes[] = $sel['perk'];
            }
        } else {
            foreach ($selections as $sel) $secondaryRunes[] = $sel['perk'];
        }
    }

    if ($mainLegendary && $keystone) {
        $runesByMainStats[$mainLegendary][$keystone] ??= [
            'games' => 0, 'wins' => 0,
            'primary_style' => $primaryStyle,
            'secondary_style' => $secondaryStyle,
            'primary_runes' => $primaryRunes,
            'secondary_runes' => $secondaryRunes,
            'shards' => $shards,
        ];
        $runesByMainStats[$mainLegendary][$keystone]['games']++;
        if ($win) $runesByMainStats[$mainLegendary][$keystone]['wins']++;
    }

    // Hechizos
    $s1 = $p['summoner1Id'] ?? 0;
    $s2 = $p['summoner2Id'] ?? 0;
    $sPair = min($s1, $s2) . '|' . max($s1, $s2);
    if ($mainLegendary) {
        $summonersByMainStats[$mainLegendary][$sPair] ??= ['games' => 0, 'wins' => 0, 's1' => min($s1,$s2), 's2' => max($s1,$s2)];
        $summonersByMainStats[$mainLegendary][$sPair]['games']++;
        if ($win) $summonersByMainStats[$mainLegendary][$sPair]['wins']++;
    }

    // Starting items: items 0-1 (los 2 primeros del inventario típicamente son starters de partida)
    // Riot no da esta info directamente. Aproximación: extraer los items iniciales del itemsPurchased desde timeline sería lo ideal, pero cuesta 1 request extra por match.
    // Alternativa: usar heurística — los items de precio ≤ 500 en el orden en que aparecen no funciona porque son "consumidos".
    // MEJOR: dejamos este dato basado en items iniciales comunes por rol (o simplemente no lo mostramos)
    // Vamos a intentar detectar starters comunes por presencia en el inventario final de items ≤ 500g que no sean botas
    // Como en late game se venden, no aparecerán. Nos rendimos con starting_items para MVP.

    // Counters + synergies
    $myTeam = $p['teamId'];
    $myPos  = $p['teamPosition'] ?? '';
    foreach ($info['participants'] ?? [] as $other) {
        $oid = $other['championId'] ?? 0;
        if ($oid === $champNumericId) continue;
        if (($other['teamId'] ?? 0) === $myTeam) {
            // Aliado
            $synergies[$oid] ??= ['games' => 0, 'wins' => 0];
            $synergies[$oid]['games']++;
            if ($win) $synergies[$oid]['wins']++;
        } else {
            // Enemigo — sólo contamos como counter si es del mismo rol
            if (($other['teamPosition'] ?? '') === $myPos) {
                $counters[$oid] ??= ['games' => 0, 'wins' => 0];
                $counters[$oid]['games']++;
                if ($win) $counters[$oid]['wins']++;
            }
        }
    }
}

// ---- Post-procesamiento ---------------------------------------------
$champInfo = ['id' => $champId, 'name' => $champions[$champId]['name'], 'icon' => dd_champion_icon_url($champId)];

// Ordenar main items por popularidad
arsort($mainItemStats);
$topMainItems = array_slice($mainItemStats, 0, 4, true);

$buildVariants = [];
foreach ($topMainItems as $mainId => $mainStat) {
    $mainIt = dd_item_by_id($mainId);
    if (!$mainIt) continue;

    // Core paths bajo este main
    $corePaths = [];
    $coreCounts = $coreByMainStats[$mainId] ?? [];
    arsort($coreCounts);
    foreach (array_slice($coreCounts, 0, 2, true) as $coreKey => $cs) {
        $ids = array_map('intval', explode('|', $coreKey));
        $itemsData = [];
        foreach ($ids as $iid) {
            $it = dd_item_by_id($iid);
            $itemsData[] = ['name' => $it['name'] ?? '?', 'icon' => $it ? dd_item_icon_url($it['image']) : null, 'gold' => $it['gold'] ?? null];
        }
        $corePaths[] = [
            'items_data' => $itemsData,
            'pick_rate' => round(100 * $cs['games'] / $mainStat['games'], 1),
            'win_rate'  => $cs['games'] > 0 ? round(100 * $cs['wins'] / $cs['games'], 1) : 0,
            'games'     => $cs['games'],
        ];
    }

    // Botas bajo este main
    $bootsList = [];
    $bootsCounts = $bootsByMainStats[$mainId] ?? [];
    arsort($bootsCounts);
    foreach (array_slice($bootsCounts, 0, 3, true) as $bootId => $bs) {
        $it = dd_item_by_id($bootId);
        $bootsList[] = [
            'item_data' => ['name' => $it['name'] ?? '?', 'icon' => $it ? dd_item_icon_url($it['image']) : null, 'gold' => $it['gold'] ?? null],
            'pick_rate' => round(100 * $bs['games'] / $mainStat['games'], 1),
            'win_rate'  => $bs['games'] > 0 ? round(100 * $bs['wins'] / $bs['games'], 1) : 0,
        ];
    }

    // Runas más usadas bajo este main
    $runeVariants = $runesByMainStats[$mainId] ?? [];
    uasort($runeVariants, fn($a, $b) => $b['games'] <=> $a['games']);
    $topRuneEntry = null;
    foreach ($runeVariants as $keystoneId => $r) { $topRuneEntry = ['keystone_id' => $keystoneId] + $r; break; }
    $runesFmt = null;
    if ($topRuneEntry) {
        $enrichRune = function($id) {
            $r = dd_rune_by_id($id);
            return $r ? ['name' => $r['name'], 'icon' => $r['icon']] : ['name' => "Rune $id", 'icon' => null];
        };
        $enrichShard = function($id) {
            $s = dd_stat_shard_by_id($id);
            return $s ?: ['name' => "Shard $id", 'icon' => null];
        };
        $ks = $enrichRune($topRuneEntry['keystone_id']);
        $primaryTree   = dd_perk_style_by_id($topRuneEntry['primary_style']);
        $secondaryTree = dd_perk_style_by_id($topRuneEntry['secondary_style']);
        $runesFmt = [
            'keystone'      => $ks['name'],
            'keystone_data' => $ks,
            'primary_tree'      => $primaryTree['name'] ?? '?',
            'primary_tree_data' => $primaryTree,
            'primary_runes'      => array_map(fn($id) => $enrichRune($id)['name'], $topRuneEntry['primary_runes']),
            'primary_runes_data' => array_map($enrichRune, $topRuneEntry['primary_runes']),
            'secondary_tree'      => $secondaryTree['name'] ?? '?',
            'secondary_tree_data' => $secondaryTree,
            'secondary_runes'      => array_map(fn($id) => $enrichRune($id)['name'], $topRuneEntry['secondary_runes']),
            'secondary_runes_data' => array_map($enrichRune, $topRuneEntry['secondary_runes']),
            'shards' => array_map(fn($id) => ($enrichShard($id)['name'] ?? '?'), $topRuneEntry['shards']),
            'pick_rate' => round(100 * $topRuneEntry['games'] / $mainStat['games'], 1),
            'win_rate'  => $topRuneEntry['games'] > 0 ? round(100 * $topRuneEntry['wins'] / $topRuneEntry['games'], 1) : 0,
        ];
    }

    // Hechizos más usados bajo este main
    $summVariants = $summonersByMainStats[$mainId] ?? [];
    uasort($summVariants, fn($a, $b) => $b['games'] <=> $a['games']);
    $topSumm = null;
    foreach ($summVariants as $s) { $topSumm = $s; break; }
    $summFmt = null;
    if ($topSumm) {
        $s1Data = dd_summoner_by_riot_id($topSumm['s1']);
        $s2Data = dd_summoner_by_riot_id($topSumm['s2']);
        $summFmt = [
            'spells'      => [$s1Data['name'] ?? '?', $s2Data['name'] ?? '?'],
            'spells_data' => [
                ['name' => $s1Data['name'] ?? '?', 'icon' => $s1Data['icon'] ?? null],
                ['name' => $s2Data['name'] ?? '?', 'icon' => $s2Data['icon'] ?? null],
            ],
            'pick_rate' => round(100 * $topSumm['games'] / $mainStat['games'], 1),
            'win_rate'  => $topSumm['games'] > 0 ? round(100 * $topSumm['wins'] / $topSumm['games'], 1) : 0,
        ];
    }

    $buildVariants[] = [
        'main_item'      => $mainIt['name'],
        'main_item_data' => ['name' => $mainIt['name'], 'icon' => dd_item_icon_url($mainIt['image']), 'gold' => $mainIt['gold'] ?? null],
        'label'          => '',
        'games'          => $mainStat['games'],
        'pick_rate'      => round(100 * $mainStat['games'] / $totalMatchedGames, 1),
        'win_rate'       => $mainStat['games'] > 0 ? round(100 * $mainStat['wins'] / $mainStat['games'], 1) : 0,
        'starting_sets'  => [], // Requiere timeline; omitido en MVP
        'core_paths'     => $corePaths,
        'boots'          => $bootsList,
        'runes'          => $runesFmt,
        'summoner_spells'=> $summFmt,
        'situational_items' => [],
    ];
}

// Situacionales globales (top 6 por juego)
arsort($situationalItems);
$situationalFormatted = [];
foreach (array_slice($situationalItems, 0, 6, true) as $iid => $s) {
    $it = dd_item_by_id($iid);
    if (!$it) continue;
    $situationalFormatted[] = [
        'item_data' => ['name' => $it['name'], 'icon' => dd_item_icon_url($it['image']), 'gold' => $it['gold'] ?? null],
        'pick_rate' => round(100 * $s['games'] / $totalMatchedGames, 1),
        'reason'    => '',
    ];
}

// Counters: enemigos con winrate <= 45% con >= 2 partidas
$countersOut = [];
foreach ($counters as $cid => $s) {
    if ($s['games'] < 2) continue;
    $wr = round(100 * $s['wins'] / $s['games']);
    if ($wr <= 45) {
        $key = riot_champion_key_from_id($cid);
        if (!$key) continue;
        $countersOut[] = [
            'champion'      => $champions[$key]['name'] ?? $key,
            'champion_key'  => $key,
            'champion_icon' => dd_champion_icon_url($key),
            'games'         => $s['games'],
            'wins'          => $s['wins'],
            'losses'        => $s['games'] - $s['wins'],
            'winrate'       => $wr,
            'difficulty'    => $wr <= 30 ? 'Alto' : ($wr <= 40 ? 'Medio' : 'Bajo'),
            'reason'        => "Perdiste {$s['games']} de " . ($s['games']) . " (WR {$wr}%)",
        ];
    }
}
usort($countersOut, fn($a, $b) => $a['winrate'] <=> $b['winrate']);
$countersOut = array_slice($countersOut, 0, 6);

// Synergies: aliados con winrate >= 60% y >= 2 partidas
$synergiesOut = [];
foreach ($synergies as $cid => $s) {
    if ($s['games'] < 2) continue;
    $wr = round(100 * $s['wins'] / $s['games']);
    if ($wr >= 60) {
        $key = riot_champion_key_from_id($cid);
        if (!$key) continue;
        $synergiesOut[] = [
            'champion'      => $champions[$key]['name'] ?? $key,
            'champion_key'  => $key,
            'champion_icon' => dd_champion_icon_url($key),
            'games'         => $s['games'],
            'wins'          => $s['wins'],
            'winrate'       => $wr,
            'reason'        => "Ganado {$s['wins']}/{$s['games']} partidas juntos",
        ];
    }
}
usort($synergiesOut, fn($a, $b) => $b['winrate'] <=> $a['winrate']);
$synergiesOut = array_slice($synergiesOut, 0, 6);

// Tier basado en WR global
$winRate = round(100 * $wins / $totalMatchedGames, 1);
$pickRateGlobal = round(100 * $picksAcrossAllMatches / max($totalGamesInspected, 1), 1);
$banRate  = round(100 * $bansCount / max($totalGamesInspected, 1), 1);

$tier = 'B';
if ($winRate >= 55 && $pickRateGlobal >= 3) $tier = 'S+';
elseif ($winRate >= 53 || ($winRate >= 51 && $pickRateGlobal >= 5)) $tier = 'S';
elseif ($winRate >= 50) $tier = 'A';
elseif ($winRate >= 47) $tier = 'B';
else $tier = 'C';

// ---- Salida ---------------------------------------------------------
$output = [
    'stats' => [
        'champion'      => $champInfo['name'],
        'champion_icon' => $champInfo['icon'],
        'role'          => $role,
        'region'        => $region,
        'patch'         => $version,
        'tier'          => $tier,
        'win_rate'      => $winRate,
        'pick_rate'     => $pickRateGlobal,
        'ban_rate'      => $banRate,
        'games'         => $totalMatchedGames,
        'wins'          => $wins,
        'losses'        => $totalMatchedGames - $wins,
        'data_source' => [
            'type' => 'aggregated',
            'matches_inspected' => $totalGamesInspected,
            'games_with_champion' => $totalMatchedGames,
            'games_with_champion_any_role' => $picksAcrossAllMatches,
            'bans_count' => $bansCount,
            'top_players_sampled' => $isGlobal ? (15 * count(riot_global_regions())) : 30,
            'queue' => 'SoloQ Ranked (420)',
            'rank_filter' => $rank,
            'is_global' => $isGlobal,
            'regions_sampled' => $isGlobal ? riot_global_regions() : [$region],
        ],
        'build_variants' => $buildVariants,
        'situational_items' => $situationalFormatted,
        'counters' => $countersOut,
        'synergies' => $synergiesOut,
    ],
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE);
file_put_contents($cachePath, $json);
echo $json;
