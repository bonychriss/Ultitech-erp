<?php
// stock/modules/products/api/import-init.php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../import_helpers.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isUltimate = stock_import_is_ultimate();
$modes = $isUltimate ? ['general'] : ['spare_part', 'truck'];

echo json_encode([
    'ok' => true,
    'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
    'isUltimate' => $isUltimate,
    'modes' => $modes,
    'defaultMode' => $isUltimate ? 'general' : 'spare_part',
    'productsListUrl' => 'index.php',
    'templateUrlBase' => 'download_import_template.php',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
