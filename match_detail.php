<?php
/**
 * GET /match_detail.php?matchId=EUW1_xxxx&region=euw
 * Devuelve una partida completa: 10 jugadores con builds, runas, damage share, KP, KDA.
 * Objetivos: dragon, herald, barón. Duración por fase.
 */
require __DIR__ . '/riot.php';

header('Content-Type: application/json; charset=utf-8');

$matchId = trim($_GET['matchId'] ?? '');
$region  = trim($_GET['region']  ?? 'euw');

if (!$matchId) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta matchId']);
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
    $detail = riot_match_detail($region, $matchId);
    if ($detail['status'] === 404) {
        http_response_code(404);
        echo json_encode(['error' => 'Partida no encontrada']);
        exit;
    }
    if ($detail['status'] !== 200) {
        http_response_code(502);
        echo json_encode(['error' => "Riot API HTTP {$detail['status']}"]);
        exit;
    }

    $info    = $detail['body']['info']     ?? [];
    $metadata= $detail['body']['metadata'] ?? [];
    $champs  = dd_get_champions();
    $participants = $info['participants'] ?? [];

    // Calcular team totals para %DMG y KP
    $teamStats = [100 => ['kills' => 0, 'damage' => 0, 'gold' => 0], 200 => ['kills' => 0, 'damage' => 0, 'gold' => 0]];
    foreach ($participants as $p) {
        $t = $p['teamId'] ?? 0;
        if (!isset($teamStats[$t])) continue;
        $teamStats[$t]['kills']  += $p['kills'] ?? 0;
        $teamStats[$t]['damage'] += $p['totalDamageDealtToChampions'] ?? 0;
        $teamStats[$t]['gold']   += $p['goldEarned'] ?? 0;
    }

    $players = [];
    foreach ($participants as $p) {
        $cid = $p['championId'] ?? 0;
        $key = riot_champion_key_from_id($cid);
        $teamId = $p['teamId'] ?? 0;
        $duration = $info['gameDuration'] ?? 0;

        // Items
        $items = [];
        for ($i = 0; $i <= 6; $i++) {
            $iid = $p['item' . $i] ?? 0;
            if ($iid > 0) {
                $it = dd_item_by_id($iid);
                $items[] = ['id' => $iid, 'name' => $it['name'] ?? '', 'icon' => $it ? dd_item_icon_url($it['image']) : null];
            } else {
                $items[] = null;
            }
        }

        // Runas
        $perks = $p['perks'] ?? [];
        $primaryStyle   = null;
        $secondaryStyle = null;
        $keystone = null;
        if (!empty($perks['styles'])) {
            foreach ($perks['styles'] as $style) {
                $styleName = dd_perk_style_by_id($style['style'] ?? 0);
                if (($style['description'] ?? '') === 'primaryStyle') {
                    $primaryStyle = $styleName;
                    if (!empty($style['selections'][0]['perk'])) {
                        $keystone = dd_rune_by_id($style['selections'][0]['perk']);
                    }
                } else {
                    $secondaryStyle = $styleName;
                }
            }
        }

        // Hechizos
        $s1 = dd_summoner_by_riot_id($p['summoner1Id'] ?? 0);
        $s2 = dd_summoner_by_riot_id($p['summoner2Id'] ?? 0);

        $csTotal = ($p['totalMinionsKilled'] ?? 0) + ($p['neutralMinionsKilled'] ?? 0);
        $tstats  = $teamStats[$teamId];
        $players[] = [
            'puuid'        => $p['puuid']  ?? '',
            'riotId'       => trim(($p['riotIdGameName'] ?? '') . '#' . ($p['riotIdTagline'] ?? '')),
            'championId'   => $cid,
            'championKey'  => $key,
            'championName' => $key ? ($champs[$key]['name'] ?? $key) : "Champion {$cid}",
            'championIcon' => $key ? dd_champion_icon_url($key) : null,
            'teamId'       => $teamId,
            'role'         => $p['teamPosition'] ?? '',
            'win'          => (bool)($p['win'] ?? false),
            'kills'        => $p['kills'] ?? 0,
            'deaths'       => $p['deaths'] ?? 0,
            'assists'      => $p['assists'] ?? 0,
            'kda'          => ($p['deaths'] ?? 0) > 0 ? round((($p['kills'] + $p['assists']) / $p['deaths']), 2) : ($p['kills'] + $p['assists']),
            'cs'           => $csTotal,
            'csPerMin'     => $duration > 0 ? round($csTotal / ($duration / 60), 1) : 0,
            'gold'         => $p['goldEarned'] ?? 0,
            'damage'       => $p['totalDamageDealtToChampions'] ?? 0,
            'damageTaken'  => $p['totalDamageTaken'] ?? 0,
            'damageShare'  => $tstats['damage'] > 0 ? round(100 * ($p['totalDamageDealtToChampions'] ?? 0) / $tstats['damage']) : 0,
            'goldShare'    => $tstats['gold']   > 0 ? round(100 * ($p['goldEarned'] ?? 0) / $tstats['gold']) : 0,
            'kp'           => $tstats['kills']  > 0 ? round(100 * (($p['kills'] ?? 0) + ($p['assists'] ?? 0)) / $tstats['kills']) : 0,
            'vision'       => $p['visionScore'] ?? 0,
            'wardsPlaced'  => $p['wardsPlaced'] ?? 0,
            'wardsKilled'  => $p['wardsKilled'] ?? 0,
            'level'        => $p['champLevel'] ?? 0,
            'items'        => $items,
            'summoners'    => [
                ['name' => $s1['name'] ?? '?', 'icon' => $s1['icon'] ?? null],
                ['name' => $s2['name'] ?? '?', 'icon' => $s2['icon'] ?? null],
            ],
            'runes'        => [
                'keystone'  => $keystone,
                'primary'   => $primaryStyle,
                'secondary' => $secondaryStyle,
            ],
        ];
    }

    // Objetivos por equipo
    $teams = [];
    foreach ($info['teams'] ?? [] as $t) {
        $obj = $t['objectives'] ?? [];
        $teams[] = [
            'teamId'   => $t['teamId'] ?? 0,
            'win'      => (bool)($t['win'] ?? false),
            'kills'    => $teamStats[$t['teamId']]['kills'] ?? 0,
            'gold'     => $teamStats[$t['teamId']]['gold'] ?? 0,
            'objectives' => [
                'baron'      => $obj['baron']['kills']      ?? 0,
                'dragon'     => $obj['dragon']['kills']     ?? 0,
                'horde'      => $obj['horde']['kills']      ?? 0,
                'riftHerald' => $obj['riftHerald']['kills'] ?? 0,
                'tower'      => $obj['tower']['kills']      ?? 0,
                'inhibitor'  => $obj['inhibitor']['kills']  ?? 0,
            ],
            'bans'     => array_map(fn($b) => riot_champion_key_from_id($b['championId'] ?? 0), $t['bans'] ?? []),
        ];
    }

    echo json_encode([
        'matchId'   => $matchId,
        'region'    => $region,
        'gameMode'  => $info['gameMode'] ?? '',
        'queueId'   => $info['queueId'] ?? 0,
        'duration'  => $info['gameDuration'] ?? 0,
        'endedAt'   => $info['gameEndTimestamp'] ?? 0,
        'players'   => $players,
        'teams'     => $teams,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
