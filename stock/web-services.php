<?php
/**
 * POST endpoint for the webServices React desk.
 * GET is rendered by erp-laravel Stock DeskShell.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/includes/web-services-lib.php';
requireLogin();

if (!function_exists('isUltimate') || !isUltimate()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Ultimate company only.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    if ((string) ($_GET['ajax'] ?? '') !== 'sync') {
        echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $before = array_map(static fn ($row) => (int) $row['id'], webSyncActiveProducts($pdo));
    $result = webSyncRun();
    webSyncMarkSent($pdo, $before);
    $result['pending'] = count(webSyncPending($pdo));
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
