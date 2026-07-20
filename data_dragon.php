<?php
/**
 * Helper para Data Dragon (CDN oficial de Riot).
 * Cachea versión, campeones e items localmente. Refresca cada 24h o cuando cambie el parche.
 */

const DD_CACHE_DIR   = __DIR__ . '/cache';
const DD_CACHE_TTL   = 86400; // 24h
const DD_LOCALE      = 'es_ES';
const DD_BASE_CDN    = 'https://ddragon.leagueoflegends.com';

function dd_http_get(string $url): ?string {
    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'lol-coach/1.0'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    return $data === false ? null : $data;
}

function dd_cache_path(string $name): string {
    return DD_CACHE_DIR . '/' . $name;
}

function dd_cache_get(string $name): ?string {
    $p = dd_cache_path($name);
    if (!file_exists($p)) return null;
    if (time() - filemtime($p) > DD_CACHE_TTL) return null;
    return file_get_contents($p);
}

function dd_cache_put(string $name, string $content): void {
    if (!is_dir(DD_CACHE_DIR)) mkdir(DD_CACHE_DIR, 0777, true);
    file_put_contents(dd_cache_path($name), $content);
}

function dd_get_version(): string {
    $cached = dd_cache_get('version.txt');
    if ($cached) return trim($cached);

    $json = dd_http_get(DD_BASE_CDN . '/api/versions.json');
    if (!$json) return '14.24.1'; // fallback razonable
    $versions = json_decode($json, true);
    $v = $versions[0] ?? '14.24.1';
    dd_cache_put('version.txt', $v);
    return $v;
}

/**
 * Lista compacta de campeones: [id => {name, title, tags, blurb, image}]
 */
function dd_get_champions(): array {
    $cached = dd_cache_get('champions.json');
    if ($cached) return json_decode($cached, true);

    $version = dd_get_version();
    $url = DD_BASE_CDN . "/cdn/{$version}/data/" . DD_LOCALE . "/champion.json";
    $json = dd_http_get($url);
    if (!$json) return [];

    $raw = json_decode($json, true);
    $out = [];
    foreach ($raw['data'] ?? [] as $id => $c) {
        $out[$id] = [
            'id'    => $id,
            'name'  => $c['name'],
            'title' => $c['title'],
            'tags'  => $c['tags'],
            'blurb' => $c['blurb'],
            'image' => $c['image']['full'],
        ];
    }
    ksort($out);
    dd_cache_put('champions.json', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * Detalle de un campeón con pasiva y habilidades resumidas.
 */
function dd_get_champion_detail(string $id): ?array {
    $file = "champion_{$id}.json";
    $cached = dd_cache_get($file);
    if ($cached) return json_decode($cached, true);

    $version = dd_get_version();
    $url = DD_BASE_CDN . "/cdn/{$version}/data/" . DD_LOCALE . "/champion/{$id}.json";
    $json = dd_http_get($url);
    if (!$json) return null;

    $raw = json_decode($json, true);
    $c = $raw['data'][$id] ?? null;
    if (!$c) return null;

    $spells = [];
    foreach ($c['spells'] ?? [] as $i => $s) {
        $key = ['Q','W','E','R'][$i] ?? '?';
        $spells[$key] = [
            'name'    => $s['name'],
            'summary' => dd_strip_tags($s['description']),
        ];
    }

    $summary = [
        'id'      => $id,
        'name'    => $c['name'],
        'title'   => $c['title'],
        'tags'    => $c['tags'],
        'lore'    => mb_substr($c['lore'] ?? '', 0, 300),
        'passive' => [
            'name'    => $c['passive']['name'] ?? '',
            'summary' => dd_strip_tags($c['passive']['description'] ?? ''),
        ],
        'spells'  => $spells,
    ];

    dd_cache_put($file, json_encode($summary, JSON_UNESCAPED_UNICODE));
    return $summary;
}

/**
 * Items relevantes (legendarios + botas + anti-heal + antiescudo + tanques).
 * Devuelve lista compacta para el contexto del prompt.
 */
function dd_get_items(): array {
    $cached = dd_cache_get('items.json');
    if ($cached) return json_decode($cached, true);

    $version = dd_get_version();
    $url = DD_BASE_CDN . "/cdn/{$version}/data/" . DD_LOCALE . "/item.json";
    $json = dd_http_get($url);
    if (!$json) return [];

    $raw = json_decode($json, true);
    $out = [];
    foreach ($raw['data'] ?? [] as $id => $it) {
        // Sólo items de Summoner's Rift, comprables (aceptamos consumibles como pociones)
        $maps = $it['maps'] ?? [];
        if (empty($maps['11'])) continue;
        if (isset($it['inStore']) && $it['inStore'] === false) continue;
        // Descartar auras, jungle-only trinkets, quest items sin precio
        $gold = $it['gold']['total'] ?? 0;
        if ($gold <= 0 && !dd_is_boots($it)) continue;

        $out[$id] = [
            'id'    => $id,
            'name'  => $it['name'],
            'gold'  => $gold,
            'desc'  => dd_strip_tags($it['description'] ?? ''),
            'plain' => $it['plaintext'] ?? '',
            'tags'  => $it['tags'] ?? [],
            'image' => $it['image']['full'] ?? '',
        ];
    }
    dd_cache_put('items.json', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

function dd_is_boots(array $it): bool {
    return in_array('Boots', $it['tags'] ?? [], true);
}

function dd_strip_tags(string $html): string {
    $t = preg_replace('/<br\s*\/?>/i', ' ', $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

/**
 * URLs a iconos oficiales.
 */
function dd_champion_icon_url(string $id): string {
    $version = dd_get_version();
    return DD_BASE_CDN . "/cdn/{$version}/img/champion/{$id}.png";
}

function dd_item_icon_url(string $imageFile): string {
    $version = dd_get_version();
    return DD_BASE_CDN . "/cdn/{$version}/img/item/{$imageFile}";
}

function dd_summoner_spell_icon_url(string $imageFile): string {
    $version = dd_get_version();
    return DD_BASE_CDN . "/cdn/{$version}/img/spell/{$imageFile}";
}

// ---------------------------------------------------------------------------
// Resolvers por ID numérico (lo que devuelve la Riot API)
// ---------------------------------------------------------------------------
function dd_item_by_id(int $id): ?array {
    $items = dd_get_items();
    return $items[$id] ?? null;
}

function dd_summoner_by_riot_id(int $riotSpellId): ?array {
    // summoner.json usa 'key' como string numérica del ID interno
    // Cache el índice por ID
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        $version = dd_get_version();
        $url = DD_BASE_CDN . "/cdn/{$version}/data/" . DD_LOCALE . "/summoner.json";
        $cached = dd_cache_get('summoners_by_id.json');
        if ($cached) {
            $byId = json_decode($cached, true);
        } else {
            $raw = @file_get_contents($url);
            if ($raw) {
                $j = json_decode($raw, true);
                foreach ($j['data'] ?? [] as $key => $s) {
                    $id = (int)($s['key'] ?? 0);
                    if ($id > 0) {
                        $byId[$id] = [
                            'id'   => $key,
                            'name' => $s['name'],
                            'icon' => dd_summoner_spell_icon_url($s['image']['full']),
                        ];
                    }
                }
                dd_cache_put('summoners_by_id.json', json_encode($byId, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    return $byId[$riotSpellId] ?? null;
}

function dd_rune_by_id(int $runeId): ?array {
    // Búsqueda plana en el árbol completo de runas
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        foreach (dd_get_runes() as $tree) {
            $byId[$tree['id']] = ['type' => 'tree', 'name' => $tree['name'], 'icon' => $tree['icon']];
            foreach ($tree['slots'] as $slot) {
                foreach ($slot as $r) {
                    $byId[$r['id']] = ['type' => 'rune', 'name' => $r['name'], 'icon' => $r['icon'], 'tree_id' => $tree['id']];
                }
            }
        }
    }
    return $byId[$runeId] ?? null;
}

function dd_perk_style_by_id(int $styleId): ?array {
    return dd_rune_by_id($styleId);
}

function dd_stat_shard_by_id(int $shardId): ?array {
    // Fragmentos: IDs son 5001-5008 aprox
    static $map = null;
    if ($map === null) {
        $cdn = DD_BASE_CDN . '/cdn/img/perk-images/StatMods/';
        $map = [
            5001 => ['name' => 'Vida escalada',       'icon' => $cdn . 'StatModsHealthScalingIcon.png'],
            5002 => ['name' => 'Armadura',             'icon' => $cdn . 'StatModsArmorIcon.png'],
            5003 => ['name' => 'Resistencia mágica',   'icon' => $cdn . 'StatModsMagicResIcon.png'],
            5005 => ['name' => 'Velocidad de ataque',  'icon' => $cdn . 'StatModsAttackSpeedIcon.png'],
            5007 => ['name' => 'Aceleración habilidad','icon' => $cdn . 'StatModsCDRScalingIcon.png'],
            5008 => ['name' => 'Ataque adaptable',     'icon' => $cdn . 'StatModsAdaptiveForceIcon.png'],
            5010 => ['name' => 'Velocidad movimiento', 'icon' => $cdn . 'StatModsMovementSpeedIcon.png'],
            5011 => ['name' => 'Vida (plana)',         'icon' => $cdn . 'StatModsHealthPlusIcon.png'],
            5013 => ['name' => 'Tenacidad',            'icon' => $cdn . 'StatModsTenacityIcon.png'],
        ];
    }
    return $map[$shardId] ?? null;
}

/**
 * URL del emblema de rango (Iron, Bronze, Silver, Gold, Platinum, Emerald, Diamond, Master, Grandmaster, Challenger).
 * Los emblemas oficiales vienen del CDN Riot (no Data Dragon; los sirve raw.communitydragon o el propio Riot).
 */
function dd_rank_emblem_url(string $tier): string {
    $tier = ucfirst(strtolower($tier));
    // Emblemas nuevos (Season 14+) alojados en el CDN oficial de Riot
    return "https://raw.communitydragon.org/latest/plugins/rcp-fe-lol-static-assets/global/default/ranked-emblem/emblem-" . strtolower($tier) . ".png";
}


// ---------------------------------------------------------------------------
// Runas, hechizos y lookup por nombre
// ---------------------------------------------------------------------------

/**
 * Devuelve el arbol de runas (5 estilos con sus 4 slots y runas).
 * Estructura: [ style_id => { id, key, name, icon, slots: [ [ {id,key,name,icon} ]... ] } ]
 */
function dd_get_runes(): array {
    $cached = dd_cache_get('runes.json');
    if ($cached) return json_decode($cached, true);

    $version = dd_get_version();
    $url = DD_BASE_CDN . "/cdn/{$version}/data/" . DD_LOCALE . "/runesReforged.json";
    $json = dd_http_get($url);
    if (!$json) return [];

    $raw = json_decode($json, true);
    $out = [];
    foreach ($raw as $tree) {
        $slots = [];
        foreach ($tree['slots'] ?? [] as $slot) {
            $runes = [];
            foreach ($slot['runes'] ?? [] as $r) {
                $runes[] = [
                    'id'    => $r['id'],
                    'key'   => $r['key'],
                    'name'  => $r['name'],
                    'short' => $r['shortDesc'] ?? '',
                    'icon'  => DD_BASE_CDN . '/cdn/img/' . $r['icon'],
                ];
            }
            $slots[] = $runes;
        }
        $out[$tree['id']] = [
            'id'    => $tree['id'],
            'key'   => $tree['key'],
            'name'  => $tree['name'],
            'icon'  => DD_BASE_CDN . '/cdn/img/' . $tree['icon'],
            'slots' => $slots,
        ];
    }
    dd_cache_put('runes.json', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * Índice plano: [ name_lowercase => rune_data ] para búsqueda rápida.
 */
function dd_rune_index(): array {
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    foreach (dd_get_runes() as $tree) {
        // el árbol también se busca por nombre
        $idx[dd_normalize($tree['name'])] = ['type' => 'tree'] + $tree;
        foreach ($tree['slots'] as $slot) {
            foreach ($slot as $r) {
                $idx[dd_normalize($r['name'])] = ['type' => 'rune', 'tree_id' => $tree['id']] + $r;
            }
        }
    }
    return $idx;
}

function dd_find_rune(string $name): ?array {
    $idx = dd_rune_index();
    $key = dd_normalize($name);
    if (isset($idx[$key])) return $idx[$key];

    // Aliases comunes que el LLM tiende a inventar
    $aliases = [
        'aeryinvocada'   => 'invocaraaery',
        'invocaraery'    => 'invocaraaery',
        'aery'           => 'invocaraaery',
        'conqueror'      => 'conquistador',
        'electrocute'    => 'electrocutar',
        'darkharvest'    => 'siega',
        'darksharvest'   => 'siega',
        'segar'          => 'siega',
        'presionletal'   => 'presionletal',
        'lethaltempo'    => 'ritmomortal',
        'ritmoletal'     => 'ritmomortal',
        'graspoftheundead' => 'toquedelamuerte',
        'agarredelonomuerto' => 'toquedelamuerte',
        'guardian'       => 'guardian',
        'aftershock'     => 'sismo',
        'phaserush'      => 'fasegalopante',
        'fasesombria'    => 'fasegalopante',
        'arcanecomet'    => 'cometaarcano',
        'firststrike'    => 'primerataque',
        'glacialaugment' => 'augmentoglacial',
        'unsealedspellbook' => 'librodehechizosabierto',
        'fleetfootwork'  => 'juegodepiernas',
        'presenceofmind' => 'presenciamental',
        'triumph'        => 'triunfo',
        'legendalacrity' => 'leyendarapidez',
        'laststand'      => 'ultimaresistencia',
        // árboles ES
        'hechiceria'     => 'brujeria',
        'determinacion'  => 'valor',
    ];
    if (isset($aliases[$key], $idx[$aliases[$key]])) return $idx[$aliases[$key]];

    // Fallback: matching por substring
    foreach ($idx as $k => $r) {
        if (str_contains($k, $key) || str_contains($key, $k)) return $r;
    }

    // Match por palabras clave
    $tokens = dd_keywords($name);
    if (empty($tokens)) return null;
    foreach (dd_get_runes() as $tree) {
        foreach ($tree['slots'] as $slot) {
            foreach ($slot as $r) {
                $runeTokens = dd_keywords($r['name']);
                if (count(array_intersect($tokens, $runeTokens)) === count($tokens)) return $r;
            }
        }
        // También el árbol
        if (count(array_intersect($tokens, dd_keywords($tree['name']))) === count($tokens)) return $tree;
    }
    return null;
}

/**
 * Hechizos de invocador: [ key => {name, icon, description} ]
 */
function dd_get_summoner_spells(): array {
    $cached = dd_cache_get('summoners.json');
    if ($cached) return json_decode($cached, true);

    $version = dd_get_version();
    $url = DD_BASE_CDN . "/cdn/{$version}/data/" . DD_LOCALE . "/summoner.json";
    $json = dd_http_get($url);
    if (!$json) return [];

    $raw = json_decode($json, true);
    $out = [];
    foreach ($raw['data'] ?? [] as $key => $s) {
        // Filtrar solo hechizos de SR (game modes CLASSIC)
        $modes = $s['modes'] ?? [];
        if (!in_array('CLASSIC', $modes, true)) continue;
        $out[$key] = [
            'id'    => $key,
            'name'  => $s['name'],
            'short' => $s['description'] ?? '',
            'icon'  => dd_summoner_spell_icon_url($s['image']['full']),
        ];
    }
    dd_cache_put('summoners.json', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

function dd_summoner_index(): array {
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    foreach (dd_get_summoner_spells() as $s) {
        $idx[dd_normalize($s['name'])] = $s;
    }
    return $idx;
}

function dd_find_summoner(string $name): ?array {
    $idx = dd_summoner_index();
    $key = dd_normalize($name);
    if (isset($idx[$key])) return $idx[$key];

    // Aliases EN -> ES por si el LLM devuelve el nombre en inglés
    $aliases = [
        'flash' => 'destello', 'heal' => 'curar', 'ignite' => 'prender',
        'teleport' => 'teleportar', 'exhaust' => 'extenuacion',
        'barrier' => 'barrera', 'ghost' => 'fantasmal', 'cleanse' => 'limpiar',
        'smite' => 'aplastar', 'clarity' => 'claridad',
    ];
    if (isset($aliases[$key], $idx[$aliases[$key]])) return $idx[$aliases[$key]];
    return null;
}

/**
 * Índice de items por nombre normalizado.
 */
function dd_item_index(): array {
    static $idx = null;
    if ($idx !== null) return $idx;
    $idx = [];
    foreach (dd_get_items() as $it) {
        $idx[dd_normalize($it['name'])] = $it;
    }
    return $idx;
}

function dd_find_item(string $name): ?array {
    $needle = dd_normalize($name);
    $idx = dd_item_index();
    if (isset($idx[$needle])) return $idx[$needle];

    // Substring directo
    foreach ($idx as $k => $it) {
        if (str_starts_with($k, $needle) || str_contains($k, $needle)) return $it;
    }

    // Match por palabras clave: quitamos stopwords y buscamos si TODAS aparecen
    $tokens = dd_keywords($name);
    if (empty($tokens)) return null;

    // Escoge el item con más solapamiento y ratio ≥ 0.6
    $best = null; $bestScore = 0;
    foreach (dd_get_items() as $it) {
        $itemTokens = dd_keywords($it['name']);
        $overlap = count(array_intersect($tokens, $itemTokens));
        $ratio = $overlap / max(count($tokens), count($itemTokens));
        if ($ratio >= 0.6 && $overlap > $bestScore) {
            $bestScore = $overlap;
            $best = $it;
        }
    }
    return $best;
}

function dd_keywords(string $s): array {
    $stop = ['de','del','la','el','los','las','al','en','a','un','una','y','o'];
    $s = mb_strtolower($s);
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',':'=>' ']);
    $words = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($words as $w) {
        if (in_array($w, $stop, true)) continue;
        // Quitar 's' final (bota/botas, placa/placas)
        if (strlen($w) > 3 && str_ends_with($w, 's')) $w = substr($w, 0, -1);
        $out[] = $w;
    }
    return array_values(array_unique($out));
}

function dd_item_icon_by_name(string $name): ?string {
    $it = dd_find_item($name);
    return $it ? dd_item_icon_url($it['image']) : null;
}

/**
 * Genera un catálogo compacto (nombres oficiales) para inyectar en el prompt del LLM
 * y evitar que invente nombres que Data Dragon no reconoce.
 */
function dd_build_catalog_for_prompt(): string {
    $out = "CATÁLOGO OFICIAL DEL PARCHE (usa EXACTAMENTE estos nombres):\n\n";

    // Hechizos
    $spells = dd_get_summoner_spells();
    $names = array_map(fn($s) => $s['name'], $spells);
    sort($names);
    $out .= "HECHIZOS DE INVOCADOR: " . implode(', ', $names) . "\n\n";

    // Runas por árbol
    $out .= "ÁRBOLES DE RUNAS Y SUS RUNAS:\n";
    foreach (dd_get_runes() as $tree) {
        $runeNames = [];
        foreach ($tree['slots'] as $slot) {
            foreach ($slot as $r) $runeNames[] = $r['name'];
        }
        $out .= "- {$tree['name']}: " . implode(', ', $runeNames) . "\n";
    }
    $out .= "\n";

    // Items agrupados por precio/rol
    $items = dd_get_items();
    $starting = [];  // ≤ 500g
    $components = []; // 500-1500g
    $boots = [];
    $legendary = []; // ≥ 2000g
    foreach ($items as $it) {
        $n = $it['name'];
        if (dd_is_boots($it)) $boots[$n] = $it['gold'];
        elseif ($it['gold'] <= 500) $starting[$n] = $it['gold'];
        elseif ($it['gold'] >= 2000) $legendary[$n] = $it['gold'];
        // saltamos componentes de rango medio para no inflar demasiado
    }
    asort($boots);
    ksort($starting);
    ksort($legendary);

    $out .= "STARTING ITEMS (≤500g): " . implode(', ', array_keys($starting)) . "\n\n";
    $out .= "BOTAS: " . implode(', ', array_keys($boots)) . "\n\n";
    $out .= "ITEMS LEGENDARIOS (≥2000g): " . implode(', ', array_keys($legendary)) . "\n";

    return $out;
}

function dd_normalize(string $s): string {
    $s = mb_strtolower(trim($s));
    // quitar tildes
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    $s = preg_replace("/[^a-z0-9]/", '', $s);
    return $s;
}
