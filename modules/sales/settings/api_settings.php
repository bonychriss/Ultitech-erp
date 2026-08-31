<?php

require_once __DIR__ . '/includes/settings-lib.php';

settingsDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

global $pdo;

$companyId = (int) (currentCompanyId() ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode(sales_settings_fetch($pdo, $companyId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $result = sales_settings_save($pdo, $companyId, $_POST, $_FILES);
    if (!$result['success']) {
        http_response_code(500);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
