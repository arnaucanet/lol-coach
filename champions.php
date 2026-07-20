<?php
/**
 * Devuelve la lista compacta de campeones para poblar los selects del frontend.
 * GET /champions.php
 */
require __DIR__ . '/data_dragon.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$champions = dd_get_champions();
$version   = dd_get_version();

$out = [];
foreach ($champions as $id => $c) {
    $out[] = [
        'id'    => $id,
        'name'  => $c['name'],
        'title' => $c['title'],
        'tags'  => $c['tags'],
        'icon'  => dd_champion_icon_url($id),
    ];
}

echo json_encode([
    'version'   => $version,
    'champions' => $out,
], JSON_UNESCAPED_UNICODE);
