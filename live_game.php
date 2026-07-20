<?php
/**
 * GET /live_game.php?puuid=...&region=euw
 * Devuelve la partida en vivo enriquecida con:
 *  - Runas y hechizos reales de cada jugador
 *  - Rango de cada jugador (SoloQ)
 *  - Mastery del jugador en el campeón que está jugando
 *  - Detección de duos (jugadores del mismo equipo con misma etiqueta de partida)
 */
require __DIR__ . '/riot.php';

header('Content-Type: application/json; charset=utf-8');

$puuid  = trim($_GET['puuid'] ?? '');
$region = trim($_GET['region'] ?? 'euw');

if (!$puuid) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta parámetro puuid']);
    exit;
}

try {
    riot_resolve_region($region);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

try {
    $live = riot_active_game($region, $puuid);
    if ($live['status'] === 404) {
        http_response_code(404);
        echo json_encode(['error' => 'El jugador no está en partida ahora mismo.']);
        exit;
    }
    if (in_array($live['status'], [401, 403])) {
        http_response_code(401);
        echo json_encode(['error' => 'API key inválida o caducada.']);
        exit;
    }
    if ($live['status'] !== 200) {
        http_response_code(502);
        echo json_encode(['error' => "Riot API HTTP {$live['status']}"]);
        exit;
    }

    $body   = $live['body'];
    $champs = dd_get_champions();

    // -------- Enriquecer cada participante -------------------------------
    $enrichPlayer = function(array $p) use ($region, $champs, $puuid) {
        $cid = $p['championId'] ?? 0;
        $key = riot_champion_key_from_id($cid);

        // Hechizos
        $s1 = dd_summoner_by_riot_id($p['spell1Id'] ?? 0);
        $s2 = dd_summoner_by_riot_id($p['spell2Id'] ?? 0);

        // Runas (perks): estilo primario + secundario + selecciones
        $perks = $p['perks'] ?? [];
        $primaryStyle   = dd_perk_style_by_id($perks['perkStyle']    ?? 0);
        $secondaryStyle = dd_perk_style_by_id($perks['perkSubStyle'] ?? 0);
        $selectedIds    = $perks['perkIds'] ?? [];
        // Convertir IDs a nombres+iconos
        $keystoneData = null;
        $primaryRunes   = [];
        $secondaryRunes = [];
        $shards         = [];
        foreach ($selectedIds as $idx => $rid) {
            $r = dd_rune_by_id($rid);
            if ($r) {
                if ($idx === 0)             $keystoneData = $r;
                elseif ($idx >= 1 && $idx <= 3) $primaryRunes[] = $r;
                elseif ($idx >= 4 && $idx <= 5) $secondaryRunes[] = $r;
            } else {
                // Puede ser shard
                $sh = dd_stat_shard_by_id($rid);
                if ($sh) $shards[] = $sh;
            }
        }

        // Rango SoloQ (llamada extra: league v4)
        $rank = null;
        $pp = $p['puuid'] ?? '';
        if ($pp) {
            try {
                $lg = riot_league_by_puuid($region, $pp);
                if ($lg['status'] === 200 && is_array($lg['body'])) {
                    foreach ($lg['body'] as $entry) {
                        if (($entry['queueType'] ?? '') === 'RANKED_SOLO_5x5') {
                            $wins = $entry['wins'] ?? 0;
                            $losses = $entry['losses'] ?? 0;
                            $rank = [
                                'tier'    => $entry['tier'] ?? '',
                                'rank'    => $entry['rank'] ?? '',
                                'lp'      => $entry['leaguePoints'] ?? 0,
                                'wins'    => $wins,
                                'losses'  => $losses,
                                'winrate' => ($wins + $losses) > 0 ? round(100 * $wins / ($wins + $losses)) : 0,
                                'emblem'  => dd_rank_emblem_url($entry['tier'] ?? 'unranked'),
                            ];
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {}
        }

        // Mastery en el campeón que juega
        $mastery = null;
        if ($pp && $cid > 0) {
            try {
                $mst = riot_mastery_by_champion($region, $pp, $cid);
                if ($mst['status'] === 200 && !empty($mst['body'])) {
                    $mastery = [
                        'level'  => $mst['body']['championLevel'] ?? 0,
                        'points' => $mst['body']['championPoints'] ?? 0,
                    ];
                }
            } catch (Throwable $e) {}
        }

        // Riot ID limpio
        $riotId = trim(($p['riotId'] ?? ''));
        if (!$riotId) {
            $g = $p['riotIdGameName'] ?? '';
            $t = $p['riotIdTagline'] ?? '';
            $riotId = $g && $t ? "$g#$t" : ($p['summonerName'] ?? '');
        }

        return [
            'championId'    => $cid,
            'championKey'   => $key,
            'name'          => $key ? ($champs[$key]['name'] ?? $key) : "Champion {$cid}",
            'icon'          => $key ? dd_champion_icon_url($key) : null,
            'riotId'        => $riotId,
            'puuid'         => $pp,
            'summonerSpells'=> [
                ['name' => $s1['name'] ?? '?', 'icon' => $s1['icon'] ?? null],
                ['name' => $s2['name'] ?? '?', 'icon' => $s2['icon'] ?? null],
            ],
            'keystone'      => $keystoneData,
            'primaryTree'   => $primaryStyle,
            'secondaryTree' => $secondaryStyle,
            'primaryRunes'  => $primaryRunes,
            'secondaryRunes'=> $secondaryRunes,
            'shards'        => $shards,
            'rank'          => $rank,
            'mastery'       => $mastery,
            'isMe'          => $pp === $puuid,
        ];
    };

    $blue = [];
    $red  = [];
    $mySide = null;

    foreach ($body['participants'] ?? [] as $p) {
        $enriched = $enrichPlayer($p);
        if (($p['teamId'] ?? 0) === 100) $blue[] = $enriched;
        else                              $red[]  = $enriched;
        if ($enriched['isMe']) $mySide = ($p['teamId'] === 100) ? 'blue' : 'red';
    }

    // Adivinar carriles por orden de picks (spectator no da rol asignado)
    $assignLanes = function(array $team): array {
        $lanes = ['top', 'jng', 'mid', 'adc', 'supp'];
        $out = ['top' => null, 'jng' => null, 'mid' => null, 'adc' => null, 'supp' => null];
        foreach ($team as $i => $entry) {
            $lane = $lanes[$i] ?? null;
            if ($lane) $out[$lane] = $entry;
        }
        return $out;
    };

    echo json_encode([
        'gameId'    => $body['gameId'] ?? null,
        'gameMode'  => $body['gameMode'] ?? '',
        'gameType'  => $body['gameType'] ?? '',
        'queueId'   => $body['gameQueueConfigId'] ?? 0,
        'startedAt' => $body['gameStartTime'] ?? 0,
        'lengthS'   => $body['gameLength'] ?? 0,
        'mySide'    => $mySide,
        'region'    => $region,
        'teams' => [
            'blue' => $assignLanes($blue),
            'red'  => $assignLanes($red),
        ],
        'note' => 'Los carriles son estimados por orden de picks. Reordénalos manualmente si es necesario.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
