<?php
/**
 * GET /runes.php
 * Devuelve el árbol completo de runas del parche actual + metadatos de fragmentos.
 * Se cachea 24h. El frontend lo pide una sola vez al arrancar.
 */
require __DIR__ . '/data_dragon.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$trees = array_values(dd_get_runes());

// Fragmentos (stat shards) — Data Dragon no los expone; iconos oficiales de Riot en CDN
$cdn = 'https://ddragon.leagueoflegends.com/cdn/img/perk-images/StatMods/';
$shards = [
    'offense' => [
        ['name' => 'Ataque adaptable', 'icon' => $cdn . 'StatModsAdaptiveForceIcon.png'],
        ['name' => 'Velocidad de ataque', 'icon' => $cdn . 'StatModsAttackSpeedIcon.png'],
        ['name' => 'Aceleración de habilidad', 'icon' => $cdn . 'StatModsCDRScalingIcon.png'],
    ],
    'flex' => [
        ['name' => 'Ataque adaptable', 'icon' => $cdn . 'StatModsAdaptiveForceIcon.png'],
        ['name' => 'Velocidad de movimiento', 'icon' => $cdn . 'StatModsMovementSpeedIcon.png'],
        ['name' => 'Vida escalada', 'icon' => $cdn . 'StatModsHealthScalingIcon.png'],
    ],
    'defense' => [
        ['name' => 'Vida', 'icon' => $cdn . 'StatModsHealthPlusIcon.png'],
        ['name' => 'Tenacidad y resistencia a ralentización', 'icon' => $cdn . 'StatModsTenacityIcon.png'],
        ['name' => 'Vida escalada', 'icon' => $cdn . 'StatModsHealthScalingIcon.png'],
    ],
];

echo json_encode([
    'trees'  => $trees,
    'shards' => $shards,
], JSON_UNESCAPED_UNICODE);
