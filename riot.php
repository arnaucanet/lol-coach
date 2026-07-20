<?php
/**
 * Cliente Riot Games API con:
 * - Multi-región dinámica (region param en cada request)
 * - Rate limiter interno (20 req/s, 100 req/2min por dev key)
 * - Cache tiered por tipo de recurso
 */

require_once __DIR__ . '/data_dragon.php';

const RIOT_CACHE_DIR = __DIR__ . '/cache/riot';

// -----------------------------------------------------------------------
// Mapeo de regiones
// -----------------------------------------------------------------------
const RIOT_REGION_MAP = [
    // ~~ platform → regional
    'euw'  => ['platform' => 'euw1', 'regional' => 'europe',   'label' => 'Europa Oeste'],
    'eune' => ['platform' => 'eun1', 'regional' => 'europe',   'label' => 'Europa Nórdica y Este'],
    'na'   => ['platform' => 'na1',  'regional' => 'americas', 'label' => 'Norteamérica'],
    'br'   => ['platform' => 'br1',  'regional' => 'americas', 'label' => 'Brasil'],
    'lan'  => ['platform' => 'la1',  'regional' => 'americas', 'label' => 'Latinoamérica Norte'],
    'las'  => ['platform' => 'la2',  'regional' => 'americas', 'label' => 'Latinoamérica Sur'],
    'oce'  => ['platform' => 'oc1',  'regional' => 'sea',      'label' => 'Oceanía'],
    'kr'   => ['platform' => 'kr',   'regional' => 'asia',     'label' => 'Corea'],
    'jp'   => ['platform' => 'jp1',  'regional' => 'asia',     'label' => 'Japón'],
    'tr'   => ['platform' => 'tr1',  'regional' => 'europe',   'label' => 'Turquía'],
    'ru'   => ['platform' => 'ru',   'regional' => 'europe',   'label' => 'Rusia'],
    'ph'   => ['platform' => 'ph2',  'regional' => 'sea',      'label' => 'Filipinas'],
    'sg'   => ['platform' => 'sg2',  'regional' => 'sea',      'label' => 'Singapur'],
    'th'   => ['platform' => 'th2',  'regional' => 'sea',      'label' => 'Tailandia'],
    'tw'   => ['platform' => 'tw2',  'regional' => 'sea',      'label' => 'Taiwan'],
    'vn'   => ['platform' => 'vn2',  'regional' => 'sea',      'label' => 'Vietnam'],
];

function riot_region_list(): array {
    $out = [];
    foreach (RIOT_REGION_MAP as $code => $info) {
        $out[] = ['code' => $code, 'label' => $info['label']];
    }
    return $out;
}

function riot_resolve_region(string $regionCode): array {
    $code = strtolower(trim($regionCode));
    if (!isset(RIOT_REGION_MAP[$code])) {
        throw new InvalidArgumentException("Región desconocida: {$regionCode}. Usa: " . implode(', ', array_keys(RIOT_REGION_MAP)));
    }
    return RIOT_REGION_MAP[$code];
}

// -----------------------------------------------------------------------
// Config
// -----------------------------------------------------------------------
function riot_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        if (!file_exists(__DIR__ . '/config.php')) throw new RuntimeException('Falta config.php');
        $cfg = require __DIR__ . '/config.php';
        if (empty($cfg['riot_api_key'])) {
            throw new RuntimeException("Falta 'riot_api_key' en config.php. Consíguela en https://developer.riotgames.com/");
        }
    }
    return $cfg;
}

// -----------------------------------------------------------------------
// Rate limiter (dev key: 20 req/s, 100 req/2min)
// -----------------------------------------------------------------------
function riot_rate_limit_check(): void {
    $limitFile = RIOT_CACHE_DIR . '/rate_limit.json';
    if (!is_dir(RIOT_CACHE_DIR)) mkdir(RIOT_CACHE_DIR, 0777, true);

    // Loop hasta que haya cuota disponible (respetamos 20/s y 100/2min)
    while (true) {
        $now = microtime(true);
        $timestamps = [];
        if (file_exists($limitFile)) {
            $timestamps = json_decode(file_get_contents($limitFile), true) ?: [];
        }
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $now - 120));

        // 20 req / 1 seg
        $lastSec = array_filter($timestamps, fn($t) => $t > $now - 1);
        if (count($lastSec) >= 20) {
            $wait = min($lastSec) + 1 - $now;
            if ($wait > 0) usleep((int)($wait * 1_000_000) + 10_000);
            continue;
        }
        // 100 req / 120 seg — esperar el tiempo necesario si estamos al tope
        if (count($timestamps) >= 100) {
            $wait = min($timestamps) + 120 - $now;
            if ($wait > 0) {
                // Duerme el tiempo real que haga falta (puede ser hasta 2 minutos)
                sleep((int)ceil($wait));
                continue;
            }
        }

        // Registrar y salir
        $timestamps[] = microtime(true);
        file_put_contents($limitFile, json_encode($timestamps), LOCK_EX);
        break;
    }
}

// -----------------------------------------------------------------------
// Cache
// -----------------------------------------------------------------------
function riot_cache_get(string $key, ?int $ttl): ?string {
    if ($ttl === null) return null;
    $path = RIOT_CACHE_DIR . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key);
    if (!file_exists($path)) return null;
    if ($ttl > 0 && (time() - filemtime($path)) > $ttl) return null;
    return file_get_contents($path);
}

function riot_cache_put(string $key, string $content): void {
    if (!is_dir(RIOT_CACHE_DIR)) mkdir(RIOT_CACHE_DIR, 0777, true);
    $path = RIOT_CACHE_DIR . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key);
    file_put_contents($path, $content);
}

// -----------------------------------------------------------------------
// HTTP request
// -----------------------------------------------------------------------
function riot_request(string $host, string $path, ?string $cacheKey = null, ?int $ttl = null): array {
    if ($cacheKey && $ttl !== null) {
        $cached = riot_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return ['status' => 200, 'body' => json_decode($cached, true), 'raw' => $cached, 'cached' => true];
        }
    }

    riot_rate_limit_check();
    $cfg = riot_config();
    $url = "https://{$host}{$path}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Riot-Token: ' . $cfg['riot_api_key']],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200 && $cacheKey && $ttl !== null) {
        riot_cache_put($cacheKey, $raw);
    }

    return [
        'status' => $status,
        'body'   => $raw ? json_decode($raw, true) : null,
        'raw'    => $raw ?: null,
        'cached' => false,
    ];
}

/**
 * Batch de requests paralelas con curl_multi. Respeta rate limit reservando
 * cuota ANTES de lanzar el batch (bloquea si no hay hueco).
 *
 * $items: array de ['host' => ..., 'path' => ..., 'cache_key' => ..., 'ttl' => ..., 'id' => ...]
 * Retorna: array de ['id' => ..., 'status' => ..., 'body' => ..., 'raw' => ...]
 *
 * $concurrency: cuántos requests en paralelo simultáneamente (default 15, tope 20 por rate limit/s)
 */
function riot_multi_get(array $items, int $concurrency = 15): array {
    $results = [];
    if (empty($items)) return $results;

    // Servir desde cache primero
    $toFetch = [];
    foreach ($items as $it) {
        if (!empty($it['cache_key']) && isset($it['ttl'])) {
            $cached = riot_cache_get($it['cache_key'], $it['ttl']);
            if ($cached !== null) {
                $results[$it['id']] = [
                    'id' => $it['id'],
                    'status' => 200,
                    'body' => json_decode($cached, true),
                    'raw' => $cached,
                    'cached' => true,
                ];
                continue;
            }
        }
        $toFetch[] = $it;
    }
    if (empty($toFetch)) return $results;

    $cfg = riot_config();
    $token = $cfg['riot_api_key'];

    // Procesar en batches de $concurrency
    foreach (array_chunk($toFetch, $concurrency) as $batch) {
        // Reservar cuota rate limit para todo el batch antes de lanzar
        for ($i = 0; $i < count($batch); $i++) riot_rate_limit_check();

        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $it) {
            $ch = curl_init("https://{$it['host']}{$it['path']}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['X-Riot-Token: ' . $token],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'item' => $it];
        }

        // Ejecutar todos en paralelo
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.1);
        } while ($running > 0);

        // Recoger resultados
        foreach ($handles as $h) {
            $raw    = curl_multi_getcontent($h['ch']);
            $status = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            $item   = $h['item'];

            if ($status === 200 && !empty($item['cache_key']) && isset($item['ttl'])) {
                riot_cache_put($item['cache_key'], $raw);
            }
            $results[$item['id']] = [
                'id'     => $item['id'],
                'status' => $status,
                'body'   => $raw ? json_decode($raw, true) : null,
                'raw'    => $raw ?: null,
                'cached' => false,
            ];

            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
        }
        curl_multi_close($mh);
    }

    return $results;
}

/**
 * Wrapper: fetch múltiples match details en paralelo. Cacheado forever.
 */
function riot_match_details_batch(string $region, array $matchIds): array {
    $r = riot_resolve_region($region);
    $host = $r['regional'] . '.api.riotgames.com';
    $items = [];
    foreach ($matchIds as $mid) {
        $items[] = [
            'id' => $mid,
            'host' => $host,
            'path' => "/lol/match/v5/matches/{$mid}",
            'cache_key' => "match_{$mid}",
            'ttl' => 0, // forever
        ];
    }
    return riot_multi_get($items, 15);
}

// -----------------------------------------------------------------------
// Endpoints Riot — todos aceptan $region
// -----------------------------------------------------------------------
function riot_account_by_riot_id(string $region, string $gameName, string $tagLine): array {
    $r = riot_resolve_region($region);
    $host = $r['regional'] . '.api.riotgames.com';
    return riot_request(
        $host,
        "/riot/account/v1/accounts/by-riot-id/" . rawurlencode($gameName) . "/" . rawurlencode($tagLine),
        "acc_{$region}_{$gameName}_{$tagLine}",
        7 * 86400
    );
}

function riot_account_by_puuid(string $region, string $puuid): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['regional'] . '.api.riotgames.com',
        "/riot/account/v1/accounts/by-puuid/{$puuid}",
        "acc_puuid_{$puuid}",
        7 * 86400
    );
}

function riot_summoner_by_puuid(string $region, string $puuid): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/summoner/v4/summoners/by-puuid/{$puuid}",
        "sum_{$region}_{$puuid}",
        3600
    );
}

function riot_league_by_puuid(string $region, string $puuid): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/league/v4/entries/by-puuid/{$puuid}",
        "lg_{$region}_{$puuid}",
        900
    );
}

function riot_mastery_top(string $region, string $puuid, int $count = 15): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/champion-mastery/v4/champion-masteries/by-puuid/{$puuid}/top?count={$count}",
        "mst_{$region}_{$puuid}_{$count}",
        3600
    );
}

function riot_mastery_by_champion(string $region, string $puuid, int $championId): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/champion-mastery/v4/champion-masteries/by-puuid/{$puuid}/by-champion/{$championId}",
        "mstc_{$region}_{$puuid}_{$championId}",
        3600
    );
}

function riot_match_ids(string $region, string $puuid, int $count = 20, ?int $queueId = null): array {
    $r = riot_resolve_region($region);
    $qs = "count={$count}";
    if ($queueId) $qs .= "&queue={$queueId}";
    return riot_request(
        $r['regional'] . '.api.riotgames.com',
        "/lol/match/v5/matches/by-puuid/{$puuid}/ids?{$qs}",
        "mids_{$region}_{$puuid}_{$count}_" . ($queueId ?: 'all'),
        300
    );
}

function riot_match_detail(string $region, string $matchId): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['regional'] . '.api.riotgames.com',
        "/lol/match/v5/matches/{$matchId}",
        "match_{$matchId}",
        0 // partidas terminadas nunca cambian
    );
}

function riot_match_timeline(string $region, string $matchId): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['regional'] . '.api.riotgames.com',
        "/lol/match/v5/matches/{$matchId}/timeline",
        "timeline_{$matchId}",
        0
    );
}

function riot_active_game(string $region, string $puuid): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/spectator/v5/active-games/by-summoner/{$puuid}",
        "live_{$region}_{$puuid}",
        60
    );
}

function riot_challenger_league(string $region, string $queue = 'RANKED_SOLO_5x5'): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/league/v4/challengerleagues/by-queue/{$queue}",
        "chall_{$region}_{$queue}",
        1800
    );
}

function riot_grandmaster_league(string $region, string $queue = 'RANKED_SOLO_5x5'): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/league/v4/grandmasterleagues/by-queue/{$queue}",
        "gm_{$region}_{$queue}",
        1800
    );
}

function riot_master_league(string $region, string $queue = 'RANKED_SOLO_5x5'): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/league/v4/masterleagues/by-queue/{$queue}",
        "master_{$region}_{$queue}",
        1800
    );
}

/**
 * Devuelve las regiones que componen "global" (las 3 más pobladas).
 */
function riot_global_regions(): array {
    return ['euw', 'na', 'kr'];
}

/**
 * League-exp-v4: entries para tiers desde DIAMOND hasta IRON.
 * $tier: 'DIAMOND' | 'EMERALD' | 'PLATINUM' | 'GOLD' | 'SILVER' | 'BRONZE' | 'IRON'
 * $division: 'I' | 'II' | 'III' | 'IV'
 */
function riot_league_exp_entries(string $region, string $tier, string $division = 'I', int $page = 1, string $queue = 'RANKED_SOLO_5x5'): array {
    $r = riot_resolve_region($region);
    return riot_request(
        $r['platform'] . '.api.riotgames.com',
        "/lol/league-exp/v4/entries/{$queue}/{$tier}/{$division}?page={$page}",
        "expleague_{$region}_{$tier}_{$division}_p{$page}",
        3600
    );
}

// -----------------------------------------------------------------------
// Helper: mapping championId (número) → key (string) usando Data Dragon
// -----------------------------------------------------------------------
function riot_load_champion_id_map(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cachedFile = __DIR__ . '/cache/champion_id_map.json';
    if (file_exists($cachedFile) && (time() - filemtime($cachedFile)) < 86400) {
        $cache = json_decode(file_get_contents($cachedFile), true);
        return $cache;
    }

    $version = dd_get_version();
    $url = "https://ddragon.leagueoflegends.com/cdn/{$version}/data/es_ES/champion.json";
    $data = @file_get_contents($url);
    if (!$data) return [];
    $raw = json_decode($data, true);
    $cache = [];
    foreach ($raw['data'] ?? [] as $id => $c) $cache[(int)$c['key']] = $id;
    file_put_contents($cachedFile, json_encode($cache));
    return $cache;
}

function riot_champion_key_from_id(int $championId): ?string {
    return riot_load_champion_id_map()[$championId] ?? null;
}
