<?php
/**
 * GET /regions.php
 * Devuelve la lista de regiones disponibles para el dropdown del frontend.
 */
require __DIR__ . '/riot.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

echo json_encode(['regions' => riot_region_list()], JSON_UNESCAPED_UNICODE);
