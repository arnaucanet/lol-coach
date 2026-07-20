<?php
/**
 * Endpoint principal. Recibe la composición, monta el prompt enriquecido con datos
 * de Data Dragon, llama a la API de OpenAI en modo JSON estructurado y post-procesa
 * la respuesta añadiendo iconos oficiales de items, runas y hechizos.
 */
require __DIR__ . '/data_dragon.php';

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Falta config.php. Copia config.example.php y añade tu API key.']);
    exit;
}
$config = require __DIR__ . '/config.php';

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido.']);
    exit;
}

foreach (['playerChampion', 'playerRole', 'laneOpponent', 'allies', 'enemies'] as $f) {
    if (empty($input[$f])) {
        http_response_code(400);
        echo json_encode(['error' => "Falta campo: $f"]);
        exit;
    }
}

$champions = dd_get_champions();
$version   = dd_get_version();

function resolve_champion_id(string $nameOrId, array $champions): ?string {
    if (isset($champions[$nameOrId])) return $nameOrId;
    $needle = mb_strtolower(trim($nameOrId));
    foreach ($champions as $id => $c) {
        if (mb_strtolower($c['name']) === $needle) return $id;
    }
    $norm = preg_replace('/[^a-z0-9]/', '', $needle);
    foreach ($champions as $id => $c) {
        if (preg_replace('/[^a-z0-9]/', '', mb_strtolower($c['name'])) === $norm) return $id;
    }
    return null;
}

function fetch_champ_summary(string $nameOrId, array $champions): array {
    $id = resolve_champion_id($nameOrId, $champions);
    if (!$id) return ['name' => $nameOrId, 'error' => 'no encontrado'];
    $d = dd_get_champion_detail($id);
    if (!$d) return ['name' => $champions[$id]['name'] ?? $nameOrId, 'error' => 'detalle no disponible'];
    return $d;
}

$player  = fetch_champ_summary($input['playerChampion'], $champions);
$rival   = fetch_champ_summary($input['laneOpponent'],   $champions);
$allies  = [];
$enemies = [];
foreach (['top','jng','mid','adc','supp'] as $lane) {
    if (!empty($input['allies'][$lane])) {
        $allies[$lane] = fetch_champ_summary($input['allies'][$lane], $champions);
    }
    if (!empty($input['enemies'][$lane])) {
        $enemies[$lane] = fetch_champ_summary($input['enemies'][$lane], $champions);
    }
}

function fmt_champion(array $c, string $prefix = ''): string {
    if (!empty($c['error'])) return "{$prefix}{$c['name']} (datos no disponibles)";
    $tags = implode('/', $c['tags'] ?? []);
    $out  = "{$prefix}{$c['name']} — {$c['title']} [{$tags}]\n";
    if (!empty($c['passive']['name'])) {
        $out .= "  Pasiva ({$c['passive']['name']}): " . mb_substr($c['passive']['summary'], 0, 200) . "\n";
    }
    foreach ($c['spells'] ?? [] as $key => $s) {
        $out .= "  {$key} ({$s['name']}): " . mb_substr($s['summary'], 0, 150) . "\n";
    }
    return $out;
}

function fmt_champion_short(array $c): string {
    if (!empty($c['error'])) return "{$c['name']} (datos no disponibles)";
    $tags = implode('/', $c['tags'] ?? []);
    return "{$c['name']} [{$tags}]";
}

// -------------------------------------------------------------------------
// Contexto de la partida
// -------------------------------------------------------------------------
$context  = "PARCHE: {$version}\n\n";
$context .= "=== MI CAMPEÓN ({$input['playerRole']}) ===\n" . fmt_champion($player);
$context .= "\n=== RIVAL DE LÍNEA ===\n" . fmt_champion($rival);

// Para ADC/Support: describir el 2v2 botlane con detalle
$myRole = strtolower($input['playerRole']);
if (in_array($myRole, ['adc','support'])) {
    $context .= "\n=== BOTLANE 2v2 ===\n";
    $context .= "Aliados bot: " . fmt_champion_short($allies['adc'] ?? []) . " + " . fmt_champion_short($allies['supp'] ?? []) . "\n";
    $context .= "Enemigos bot: " . fmt_champion_short($enemies['adc'] ?? []) . " + " . fmt_champion_short($enemies['supp'] ?? []) . "\n";
    // Detalle completo de los 4 botlaners
    foreach (['adc','supp'] as $l) {
        if (!empty($enemies[$l])) $context .= "\n[ENEMIGO BOT " . strtoupper($l) . "]\n" . fmt_champion($enemies[$l]);
        if (!empty($allies[$l]))  $context .= "\n[ALIADO BOT "  . strtoupper($l) . "]\n" . fmt_champion($allies[$l]);
    }
}

$context .= "\n=== ALIADOS (composición) ===\n";
foreach ($allies as $lane => $c) $context .= strtoupper($lane) . ": " . fmt_champion_short($c) . "\n";
$context .= "\n=== ENEMIGOS (composición) ===\n";
foreach ($enemies as $lane => $c) $context .= strtoupper($lane) . ": " . fmt_champion_short($c) . "\n";

// Si viene contexto extra (por ejemplo del coach_from_live), lo añadimos
if (!empty($input['extra_context']) && is_string($input['extra_context'])) {
    $context = $input['extra_context'] . "\n\n" . $context;
}

// Catálogo oficial para que el LLM no invente nombres
$catalog = dd_build_catalog_for_prompt();

// -------------------------------------------------------------------------
// Prompt: JSON estructurado + análisis profesional
// -------------------------------------------------------------------------
$systemPrompt = <<<PROMPT
Eres un entrenador de League of Legends con experiencia en Challenger EUW. Tu análisis debe sonar como el de un coach profesional: identifica ventanas concretas, matchups específicos y numera niveles clave. NO des consejos genéricos.

## MENTALIDAD DE ANÁLISIS

Antes de responder, razona internamente sobre:
1. **Potencial de all-in a nivel 1-2**: ¿Alguno de los 4 en botlane tiene ejecución fuerte a nivel 1 (Thresh Q, Blitzcrank Q, Leona E, Nautilus Q, Braum Q pasiva, Morgana Q)? ¿El ADC enemigo tiene daño instantáneo (Jinx W, Caitlyn Q trap, Draven Q)? ¿El aliado tiene sustain o escape?
2. **Ventajas de nivel 2/3**: ¿Quién llega antes al lvl 2 (Cait, MF, Draven trade fuerte al 2)? ¿Quién explota lvl 3 (Nautilus E, Leona W)?
3. **Fase de líneas ganadora o perdedora**: Sé HONESTO. Si el matchup es perdedor, dilo claramente ("perdéis prio hasta nivel 6, jugad debajo de torre").
4. **Peligro de jungla enemigo con este support**: soft CC vs hard CC afecta muchísimo la ganeabilidad.
5. **Ventana de kill del jungla enemigo en tu línea**: si su support es engage + jungla es diver (Amumu, Nocturne, Vi, Sejuani), el gank es letal a partir de nivel 3.
6. **Power spikes específicos**: no digas "nivel 6". Di "nivel 6 cuando puedas activar tu ulti + Amumu ya tenga rango de R para chain CC".

## DEVUELVE JSON EXACTO (sin markdown, sin bloques de código):

{
  "analysis": {
    "matchup":      "2-3 frases DENSAS: identifica el matchup 1v1 o 2v2, quién gana early (con motivo), niveles clave donde puedes tradear/all-in, y qué evitar. Menciona habilidades concretas por nombre.",
    "gank_risk":    "2 frases: probabilidad real de gank (bajo/medio/alto), a qué nivel se vuelve peligroso el jungla enemigo, y cómo posicionarte. Ejemplo: 'Alto — Amumu con R+Q a nivel 6 mata garantizado si estás sobreextendido. Mantén la ola cerca de torre hasta que Jarvan IV te llegue con visión de tribush.'",
    "power_spikes": "1-2 frases: 2-3 power spikes CONCRETOS de tu campeón para ESTA partida. Ejemplo: 'Nivel 2 con Q+W tienes trade ganado vs Kassadin. Nivel 6 con ulti + Bota + Poción puedes solo-kill si baila su E. Power real en nivel 9 con Q maxeada.'"
  },
  "runes": {
    "keystone":       "runa principal EXACTA del catálogo",
    "primary_tree":   "árbol EXACTO del catálogo (Precisión, Dominación, Brujería, Valor, Inspiración)",
    "primary_runes":  ["3 runas del árbol principal, EXACTAS del catálogo"],
    "secondary_tree": "árbol secundario, EXACTO",
    "secondary_runes":["2 runas del secundario, EXACTAS"],
    "shards":         ["Ofensivo", "Flexible", "Defensivo"]
  },
  "summoner_spells": ["2 hechizos EN ESPAÑOL EXACTO: Destello, Curar, Prender, Teleportar, Extenuación, Barrera, Fantasmal, Limpiar, Aplastar"],
  "skill_order": {
    "priority": ["Q","E","W"],
    "sequence": ["18 valores Q/W/E/R para niveles 1-18. R en 6, 11, 16."]
  },
  "build": {
    "starting_items": ["nombres EXACTOS del catálogo (ej: Espada de Doran, Poción de vida, Piedra del centinela sombrío...)"],
    "first_back":     ["qué comprar con ~1300g después del primer back"],
    "core_items":     ["3 items legendarios ESENCIALES en orden de compra, EXACTOS del catálogo"],
    "boots":          "botas del catálogo",
    "situational":    ["2-3 items opcionales según cómo vaya la partida"],
    "counter_items":  [{"name":"item EXACTO","target":"campeón enemigo concreto","reason":"por qué específico"}]
  },
  "macro": {
    "teamfight_role": "1-2 frases con tu objetivo concreto en teamfights + con quién debes jugar cerca (peel al ADC, flanqueo con jungla, iniciar sobre backline enemiga...)",
    "win_condition":  "1-2 frases con la condición clara de victoria: qué objetivo priorizar (Herald, Grubs, Dragón, Barón), splitpush o teamfight, timing del scaling"
  },
  "critical_tip": "UNA frase con la regla de oro NO OBVIA que decide esta partida (ej: 'No vayas al 2v2 sin cooldown de Sentencia de Muerte del Thresh o mueres al primer enganche')."
}

## REGLAS ESTRICTAS
- USA EXCLUSIVAMENTE los nombres de items, runas y hechizos del catálogo de abajo. NO inventes ni traduzcas al inglés.
- Sé específico. En cada campo cita habilidades por nombre, nombres de campeones enemigos concretos, niveles exactos.
- Si el matchup es perdedor, dilo. No suavices.
- No repitas info entre campos.
- Devuelve SÓLO el JSON válido, sin markdown alrededor.

## CATÁLOGO

{$catalog}
PROMPT;

$payload = [
    'model' => $config['openai_model'] ?? 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => "Analiza esta partida:\n\n" . $context],
    ],
    'temperature'     => 0.6,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['openai_api_key'],
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Error de red: ' . $curlErr]);
    exit;
}

$decoded = json_decode($response, true);
if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode([
        'error'   => 'OpenAI HTTP ' . $httpCode,
        'details' => $decoded['error']['message'] ?? $response,
    ]);
    exit;
}

$raw = $decoded['choices'][0]['message']['content'] ?? '';
$analysis = json_decode($raw, true);
if (!$analysis) {
    http_response_code(502);
    echo json_encode(['error' => 'Respuesta no es JSON válido', 'raw' => $raw]);
    exit;
}

// -------------------------------------------------------------------------
// Enriquecer con iconos oficiales
// -------------------------------------------------------------------------
function enrich_item_list(array $names): array {
    $out = [];
    foreach ($names as $name) {
        $it = dd_find_item((string)$name);
        $out[] = [
            'name' => (string)$name,
            'icon' => $it ? dd_item_icon_url($it['image']) : null,
            'gold' => $it['gold'] ?? null,
        ];
    }
    return $out;
}

function enrich_rune_list(array $names): array {
    $out = [];
    foreach ($names as $name) {
        $r = dd_find_rune((string)$name);
        $out[] = [
            'name' => (string)$name,
            'icon' => $r['icon'] ?? null,
        ];
    }
    return $out;
}

function enrich_summoners(array $names): array {
    $out = [];
    foreach ($names as $name) {
        $s = dd_find_summoner((string)$name);
        $out[] = [
            'name' => (string)$name,
            'icon' => $s['icon'] ?? null,
        ];
    }
    return $out;
}

// Runas
if (isset($analysis['runes'])) {
    $r = &$analysis['runes'];
    $r['keystone_data']       = ['name' => $r['keystone'] ?? '', 'icon' => dd_find_rune($r['keystone'] ?? '')['icon'] ?? null];
    $r['primary_tree_data']   = ['name' => $r['primary_tree'] ?? '',   'icon' => dd_find_rune($r['primary_tree'] ?? '')['icon'] ?? null];
    $r['secondary_tree_data'] = ['name' => $r['secondary_tree'] ?? '', 'icon' => dd_find_rune($r['secondary_tree'] ?? '')['icon'] ?? null];
    $r['primary_runes_data']   = enrich_rune_list($r['primary_runes']   ?? []);
    $r['secondary_runes_data'] = enrich_rune_list($r['secondary_runes'] ?? []);
    unset($r);
}

// Hechizos
if (isset($analysis['summoner_spells'])) {
    $analysis['summoner_spells_data'] = enrich_summoners($analysis['summoner_spells']);
}

// Build
if (isset($analysis['build'])) {
    $b = &$analysis['build'];
    $b['starting_items_data'] = enrich_item_list($b['starting_items'] ?? []);
    $b['first_back_data']     = enrich_item_list($b['first_back']     ?? []);
    $b['core_items_data']     = enrich_item_list($b['core_items']     ?? []);
    $b['situational_data']    = enrich_item_list($b['situational']    ?? []);
    $b['boots_data']          = enrich_item_list([$b['boots'] ?? ''])[0] ?? null;
    if (!empty($b['counter_items'])) {
        foreach ($b['counter_items'] as &$ci) {
            $it = dd_find_item($ci['name'] ?? '');
            $ci['icon'] = $it ? dd_item_icon_url($it['image']) : null;
        }
        unset($ci);
    }
    unset($b);
}

echo json_encode([
    'analysis' => $analysis,
    'usage'    => $decoded['usage'] ?? null,
    'version'  => $version,
    'model'    => $payload['model'],
], JSON_UNESCAPED_UNICODE);
