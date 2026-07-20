<?php
/**
 * POST /coach_from_live.php
 * body: { region, puuid, myLane, allies:{lane:championId}, enemies:{lane:championId} }
 *
 * Este endpoint es un wrapper alrededor de api.php pero enriquece el prompt con
 * DATOS REALES de la partida en vivo (runas, hechizos, rangos, mastery) que
 * saca de live_game.php. Da al coach información mucho más profunda.
 */
require __DIR__ . '/riot.php';

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Falta config.php']);
    exit;
}
$config = require __DIR__ . '/config.php';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$region = trim($in['region'] ?? 'euw');
$puuid  = trim($in['puuid'] ?? '');
$myLane = strtolower($in['myLane'] ?? 'mid');

if (!$puuid) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta puuid']);
    exit;
}

try {
    // Refetch live game (viene de cache 60s si es misma partida)
    $live = riot_active_game($region, $puuid);
    if ($live['status'] !== 200) {
        http_response_code(400);
        echo json_encode(['error' => 'No se pudo obtener la partida en vivo']);
        exit;
    }

    $body = $live['body'];
    $champs = dd_get_champions();

    // Localizar al jugador y determinar lados
    $participants = $body['participants'] ?? [];
    $mySide = null;
    $me = null;
    foreach ($participants as $p) {
        if (($p['puuid'] ?? '') === $puuid) {
            $me = $p;
            $mySide = ($p['teamId'] === 100) ? 100 : 200;
            break;
        }
    }
    if (!$me) {
        http_response_code(400);
        echo json_encode(['error' => 'No estás en la partida en vivo']);
        exit;
    }

    $allies = []; $enemies = [];
    foreach ($participants as $p) {
        $isAlly = ($p['teamId'] === $mySide);
        if ($isAlly) $allies[]  = $p;
        else         $enemies[] = $p;
    }
    // Asignar por orden de picks
    $lanes = ['top', 'jng', 'mid', 'adc', 'supp'];
    $alliesByLane  = [];
    $enemiesByLane = [];
    foreach ($allies  as $i => $p) if (isset($lanes[$i])) $alliesByLane[$lanes[$i]]  = $p;
    foreach ($enemies as $i => $p) if (isset($lanes[$i])) $enemiesByLane[$lanes[$i]] = $p;

    // Localizar mi lane real si el usuario nos lo dice
    // (buscar mi puuid en los enriched)
    $myLaneKey = null;
    foreach ($alliesByLane as $lane => $p) {
        if (($p['puuid'] ?? '') === $puuid) { $myLaneKey = $lane; break; }
    }
    $myLaneKey = $myLaneKey ?: $myLane;

    // Construir contexto enriquecido
    $describePlayer = function(array $p) use ($region, $champs) {
        $cid = $p['championId'] ?? 0;
        $key = riot_champion_key_from_id($cid);
        $name = $key ? ($champs[$key]['name'] ?? $key) : "Champion {$cid}";

        // Hechizos
        $s1 = dd_summoner_by_riot_id($p['spell1Id'] ?? 0);
        $s2 = dd_summoner_by_riot_id($p['spell2Id'] ?? 0);
        $summs = [$s1['name'] ?? '?', $s2['name'] ?? '?'];

        // Keystone (perkIds[0])
        $keystone = null;
        $perkIds = $p['perks']['perkIds'] ?? [];
        if (!empty($perkIds[0])) {
            $r = dd_rune_by_id($perkIds[0]);
            if ($r) $keystone = $r['name'];
        }
        $primaryStyle   = dd_perk_style_by_id($p['perks']['perkStyle']    ?? 0)['name'] ?? '?';
        $secondaryStyle = dd_perk_style_by_id($p['perks']['perkSubStyle'] ?? 0)['name'] ?? '?';

        // Rango (llamada extra)
        $rankStr = 'Sin rango';
        try {
            $lg = riot_league_by_puuid($region, $p['puuid'] ?? '');
            if ($lg['status'] === 200 && is_array($lg['body'])) {
                foreach ($lg['body'] as $e) {
                    if (($e['queueType'] ?? '') === 'RANKED_SOLO_5x5') {
                        $wins = $e['wins'] ?? 0; $l = $e['losses'] ?? 0;
                        $wr = ($wins + $l) > 0 ? round(100 * $wins / ($wins + $l)) : 0;
                        $rankStr = "{$e['tier']} {$e['rank']} · {$wr}% WR ({$wins}W/{$l}L)";
                        break;
                    }
                }
            }
        } catch (Throwable $e) {}

        // Mastery
        $mastStr = 'Sin datos';
        try {
            $mst = riot_mastery_by_champion($region, $p['puuid'] ?? '', $cid);
            if ($mst['status'] === 200 && !empty($mst['body'])) {
                $lvl = $mst['body']['championLevel'] ?? 0;
                $pts = $mst['body']['championPoints'] ?? 0;
                $ptsFmt = $pts >= 1000000 ? round($pts/1000000, 1).'M' : ($pts >= 1000 ? round($pts/1000).'K' : $pts);
                $mastStr = "M{$lvl} · {$ptsFmt} pts";
            }
        } catch (Throwable $e) {}

        return [
            'name'    => $name,
            'summs'   => $summs,
            'keystone'=> $keystone ?: '?',
            'trees'   => "$primaryStyle / $secondaryStyle",
            'rank'    => $rankStr,
            'mastery' => $mastStr,
        ];
    };

    // Enriquecer todos
    $enrichedAllies  = array_map($describePlayer, $alliesByLane);
    $enrichedEnemies = array_map($describePlayer, $enemiesByLane);

    // Construir el bloque de contexto para el LLM
    $ctx = "PARTIDA EN VIVO — DATOS REALES DE LA API DE RIOT\n";
    $ctx .= "Región: " . strtoupper($region) . " · Cola: " . ($body['gameQueueConfigId'] ?? '?') . "\n\n";

    $ctx .= "=== TU EQUIPO ===\n";
    foreach ($enrichedAllies as $lane => $e) {
        $tag = $lane === $myLaneKey ? ' ← ERES TÚ' : '';
        $ctx .= strtoupper($lane) . ": {$e['name']} — Rango: {$e['rank']} · Mastery: {$e['mastery']} · Runa principal: {$e['keystone']} ({$e['trees']}) · Hechizos: " . implode('+', $e['summs']) . $tag . "\n";
    }
    $ctx .= "\n=== EQUIPO ENEMIGO ===\n";
    foreach ($enrichedEnemies as $lane => $e) {
        $tag = $lane === $myLaneKey ? ' ← RIVAL DIRECTO' : '';
        $ctx .= strtoupper($lane) . ": {$e['name']} — Rango: {$e['rank']} · Mastery: {$e['mastery']} · Runa principal: {$e['keystone']} ({$e['trees']}) · Hechizos: " . implode('+', $e['summs']) . $tag . "\n";
    }

    // Construir payload para el api.php interno reutilizando su lógica
    // Aquí sí llamamos a nuestro propio api.php pasándole el contexto extra vía un parámetro
    $championsMap = dd_get_champions();
    $convertLaneMap = function(array $laneMap) use ($championsMap) {
        $out = ['top' => null, 'jng' => null, 'mid' => null, 'adc' => null, 'supp' => null];
        foreach ($laneMap as $lane => $p) {
            $cid = $p['championId'] ?? 0;
            $key = riot_champion_key_from_id($cid);
            if ($key) $out[$lane] = $key;
        }
        return $out;
    };
    $alliesInput  = $convertLaneMap($alliesByLane);
    $enemiesInput = $convertLaneMap($enemiesByLane);

    // Mi campeón y rival
    $myChamp     = $alliesInput[$myLaneKey]  ?? null;
    $rivalChamp  = $enemiesInput[$myLaneKey] ?? null;
    if (!$myChamp || !$rivalChamp) {
        http_response_code(400);
        echo json_encode(['error' => "No se pudo identificar tu campeón o el del rival en el carril {$myLaneKey}"]);
        exit;
    }

    // Delegamos al api.php pasando extra_context
    $roleLabel = ['top' => 'Top', 'jng' => 'Jungla', 'mid' => 'Mid', 'adc' => 'ADC', 'supp' => 'Support'][$myLaneKey] ?? 'Mid';

    // Llamamos internamente al endpoint api.php
    $payload = [
        'playerChampion' => $myChamp,
        'playerRole'     => $roleLabel,
        'laneOpponent'   => $rivalChamp,
        'allies'         => $alliesInput,
        'enemies'        => $enemiesInput,
        'extra_context'  => $ctx,
    ];

    $ch = curl_init('http://127.0.0.1/lol-coach/api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 90,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($code);
    echo $resp;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
