<?php
/**
 * POST endpoint for the Website module.
 * GET is rendered by erp-laravel WebsitePageController.
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
    $action = (string) ($_GET['ajax'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $run = (string) ($_GET['run'] ?? '');
    if ($action === 'cancel') {
        webSyncRequestCancel($userId, $run);
        echo json_encode(['ok' => true, 'cancelled' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action !== 'sync') {
        echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $before = webSyncActiveProducts($pdo);
    $removed = array_map(static fn ($row) => (int) $row['id'], webSyncChanges($pdo)['deleted']);
    $result = webSyncRun($userId, $run);
    if (!empty($result['cancelled'])) {
        echo json_encode([
            'ok' => false,
            'cancelled' => true,
            'error' => 'Sync cancelled.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    webSyncMarkSent($pdo, $before);
    webSyncForget($pdo, $removed);
    $changes = webSyncChanges($pdo);
    $result['pending'] = count($changes['pending']);
    $result['edited'] = count($changes['edited']);
    $result['deleted'] = count($changes['deleted']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
