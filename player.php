<?php
/**
 * GET /player.php?riotId=Nombre%23TAG&region=euw
 * Devuelve perfil completo: rangos con emblemas, mastery top 15, últimas 15 partidas,
 * champion pool (WR por campeón), detección de duos frecuentes.
 */
require __DIR__ . '/riot.php';

header('Content-Type: application/json; charset=utf-8');

$riotId = trim($_GET['riotId'] ?? '');
$region = trim($_GET['region'] ?? 'euw');

if (!$riotId || !str_contains($riotId, '#')) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato: Nombre#TAG (ej: Faker#KR1)']);
    exit;
}

[$gameName, $tagLine] = array_map('trim', explode('#', $riotId, 2));

try {
    riot_resolve_region($region);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

try {
    // 1. Cuenta
    $account = riot_account_by_riot_id($region, $gameName, $tagLine);
    if ($account['status'] === 404) {
        http_response_code(404);
        echo json_encode(['error' => "No se encontró {$riotId} en {$region}."]);
        exit;
    }
    if (in_array($account['status'], [401, 403])) {
        http_response_code(401);
        echo json_encode(['error' => 'API key inválida o caducada. Renuévala en developer.riotgames.com.']);
        exit;
    }
    if ($account['status'] !== 200) {
        http_response_code(502);
        echo json_encode(['error' => "Riot API HTTP {$account['status']}", 'details' => $account['body']]);
        exit;
    }
    $puuid = $account['body']['puuid'];

    // 2. Summoner
    $summoner = riot_summoner_by_puuid($region, $puuid);
    $sumBody  = $summoner['status'] === 200 ? $summoner['body'] : null;

    // 3. Rangos con emblemas
    $league = riot_league_by_puuid($region, $puuid);
    $ranks  = [];
    if ($league['status'] === 200 && is_array($league['body'])) {
        foreach ($league['body'] as $entry) {
            $wins = $entry['wins'] ?? 0;
            $losses = $entry['losses'] ?? 0;
            $ranks[] = [
                'queue'    => $entry['queueType'] ?? '',
                'tier'     => $entry['tier'] ?? '',
                'rank'     => $entry['rank'] ?? '',
                'lp'       => $entry['leaguePoints'] ?? 0,
                'wins'     => $wins,
                'losses'   => $losses,
                'winrate'  => ($wins + $losses) > 0 ? round(100 * $wins / ($wins + $losses)) : 0,
                'emblem'   => dd_rank_emblem_url($entry['tier'] ?? 'unranked'),
                'hotStreak'=> $entry['hotStreak'] ?? false,
                'veteran'  => $entry['veteran'] ?? false,
            ];
        }
    }

    // 4. Mastery top 15
    $mastery = riot_mastery_top($region, $puuid, 15);
    $topChamps = [];
    if ($mastery['status'] === 200 && is_array($mastery['body'])) {
        $champs = dd_get_champions();
        foreach ($mastery['body'] as $m) {
            $cid = $m['championId'];
            $key = riot_champion_key_from_id($cid);
            $topChamps[] = [
                'championId'  => $cid,
                'championKey' => $key,
                'name'        => $key ? ($champs[$key]['name'] ?? $key) : "Champion {$cid}",
                'icon'        => $key ? dd_champion_icon_url($key) : null,
                'level'       => $m['championLevel'] ?? 0,
                'points'      => $m['championPoints'] ?? 0,
                'lastPlay'    => $m['lastPlayTime'] ?? 0,
            ];
        }
    }

    // 5. Últimas 20 partidas (ranked cola)
    $matchIds = riot_match_ids($region, $puuid, 20);
    $matches  = [];
    $champStats = []; // WR/KDA por campeón
    $duoStats   = []; // puuid → contador de partidas juntos

    if ($matchIds['status'] === 200 && is_array($matchIds['body'])) {
        $champs = dd_get_champions();
        foreach ($matchIds['body'] as $mid) {
            $detail = riot_match_detail($region, $mid);
            if ($detail['status'] !== 200) continue;
            $info = $detail['body']['info'] ?? [];
            $participants = $info['participants'] ?? [];

            // Encontrar al jugador
            $me = null;
            foreach ($participants as $p) if (($p['puuid'] ?? '') === $puuid) { $me = $p; break; }
            if (!$me) continue;

            $key = riot_champion_key_from_id($me['championId']);
            $duration = $info['gameDuration'] ?? 0;
            $csTotal  = ($me['totalMinionsKilled'] ?? 0) + ($me['neutralMinionsKilled'] ?? 0);

            // Items del jugador
            $itemsData = [];
            for ($i = 0; $i <= 6; $i++) {
                $iid = $me['item' . $i] ?? 0;
                if ($iid > 0) {
                    $it = dd_item_by_id($iid);
                    $itemsData[] = [
                        'id' => $iid,
                        'name' => $it['name'] ?? '',
                        'icon' => $it ? dd_item_icon_url($it['image']) : null,
                    ];
                } else {
                    $itemsData[] = null;
                }
            }

            // Hechizos
            $s1 = dd_summoner_by_riot_id($me['summoner1Id'] ?? 0);
            $s2 = dd_summoner_by_riot_id($me['summoner2Id'] ?? 0);

            // Damage share (%DMG del equipo)
            $teamId = $me['teamId'] ?? 0;
            $teamDmg = 0;
            foreach ($participants as $p) if (($p['teamId'] ?? 0) === $teamId) $teamDmg += ($p['totalDamageDealtToChampions'] ?? 0);
            $dmgShare = $teamDmg > 0 ? round(100 * ($me['totalDamageDealtToChampions'] ?? 0) / $teamDmg) : 0;

            // KP%
            $teamKills = 0;
            foreach ($participants as $p) if (($p['teamId'] ?? 0) === $teamId) $teamKills += ($p['kills'] ?? 0);
            $kp = $teamKills > 0 ? round(100 * (($me['kills'] ?? 0) + ($me['assists'] ?? 0)) / $teamKills) : 0;

            $win = (bool)($me['win'] ?? false);
            $matches[] = [
                'matchId'      => $mid,
                'gameMode'     => $info['gameMode'] ?? '',
                'queueId'      => $info['queueId'] ?? 0,
                'durationS'    => $duration,
                'endedAt'      => $info['gameEndTimestamp'] ?? 0,
                'champion'     => $key ? ($champs[$key]['name'] ?? $key) : "Champion {$me['championId']}",
                'championKey'  => $key,
                'championIcon' => $key ? dd_champion_icon_url($key) : null,
                'role'         => $me['teamPosition'] ?? '',
                'win'          => $win,
                'kills'        => $me['kills'] ?? 0,
                'deaths'       => $me['deaths'] ?? 0,
                'assists'      => $me['assists'] ?? 0,
                'kda'          => ($me['deaths'] ?? 0) > 0
                    ? round((($me['kills'] + $me['assists']) / $me['deaths']), 2)
                    : ($me['kills'] + $me['assists']),
                'cs'           => $csTotal,
                'csPerMin'     => $duration > 0 ? round($csTotal / ($duration / 60), 1) : 0,
                'gold'         => $me['goldEarned'] ?? 0,
                'damage'       => $me['totalDamageDealtToChampions'] ?? 0,
                'damageShare'  => $dmgShare,
                'kp'           => $kp,
                'vision'       => $me['visionScore'] ?? 0,
                'items'        => $itemsData,
                'summoners'    => [
                    ['name' => $s1['name'] ?? '', 'icon' => $s1['icon'] ?? null],
                    ['name' => $s2['name'] ?? '', 'icon' => $s2['icon'] ?? null],
                ],
            ];

            // Actualizar champ stats
            $ck = $key ?: (string)$me['championId'];
            if (!isset($champStats[$ck])) {
                $champStats[$ck] = [
                    'championKey' => $key, 'name' => $key ? ($champs[$key]['name'] ?? $key) : '?',
                    'icon' => $key ? dd_champion_icon_url($key) : null,
                    'games' => 0, 'wins' => 0, 'kills' => 0, 'deaths' => 0, 'assists' => 0,
                ];
            }
            $champStats[$ck]['games']++;
            if ($win) $champStats[$ck]['wins']++;
            $champStats[$ck]['kills']   += $me['kills'] ?? 0;
            $champStats[$ck]['deaths']  += $me['deaths'] ?? 0;
            $champStats[$ck]['assists'] += $me['assists'] ?? 0;

            // Detectar duos (aliados frecuentes)
            foreach ($participants as $p) {
                if (($p['puuid'] ?? '') === $puuid) continue;
                if (($p['teamId'] ?? 0) !== $teamId) continue;
                $pp = $p['puuid'] ?? '';
                if (!$pp) continue;
                if (!isset($duoStats[$pp])) {
                    $duoStats[$pp] = [
                        'puuid' => $pp,
                        'riotId' => trim(($p['riotIdGameName'] ?? '') . '#' . ($p['riotIdTagline'] ?? '')),
                        'games' => 0, 'wins' => 0,
                    ];
                }
                $duoStats[$pp]['games']++;
                if ($win) $duoStats[$pp]['wins']++;
            }
        }
    }

    // Postprocesar champ stats (winrate + KDA)
    $champPool = [];
    foreach ($champStats as $cs) {
        $g = $cs['games'];
        $cs['winrate'] = $g > 0 ? round(100 * $cs['wins'] / $g) : 0;
        $cs['kda'] = $cs['deaths'] > 0
            ? round(($cs['kills'] + $cs['assists']) / $cs['deaths'], 2)
            : $cs['kills'] + $cs['assists'];
        $cs['avg_kills']   = $g > 0 ? round($cs['kills']   / $g, 1) : 0;
        $cs['avg_deaths']  = $g > 0 ? round($cs['deaths']  / $g, 1) : 0;
        $cs['avg_assists'] = $g > 0 ? round($cs['assists'] / $g, 1) : 0;
        $champPool[] = $cs;
    }
    usort($champPool, fn($a, $b) => $b['games'] <=> $a['games']);

    // Postprocesar duos: solo mostrar si >=2 partidas juntos
    $duos = [];
    foreach ($duoStats as $d) {
        if ($d['games'] >= 2 && $d['riotId'] !== '#') {
            $d['winrate'] = round(100 * $d['wins'] / $d['games']);
            $duos[] = $d;
        }
    }
    usort($duos, fn($a, $b) => $b['games'] <=> $a['games']);
    $duos = array_slice($duos, 0, 5);

    echo json_encode([
        'region'   => $region,
        'account'  => [
            'puuid'    => $puuid,
            'gameName' => $account['body']['gameName'] ?? $gameName,
            'tagLine'  => $account['body']['tagLine'] ?? $tagLine,
        ],
        'summoner' => $sumBody ? [
            'level'         => $sumBody['summonerLevel'] ?? 0,
            'profileIconId' => $sumBody['profileIconId'] ?? 0,
            'profileIcon'   => 'https://ddragon.leagueoflegends.com/cdn/' . dd_get_version() . '/img/profileicon/' . ($sumBody['profileIconId'] ?? 0) . '.png',
        ] : null,
        'ranks'     => $ranks,
        'mastery'   => $topChamps,
        'matches'   => $matches,
        'champPool' => $champPool,
        'duos'      => $duos,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
