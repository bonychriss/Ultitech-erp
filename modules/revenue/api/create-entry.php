<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }

    if (!function_exists('verify_csrf') || !verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }

    require_once __DIR__ . '/../includes/revenue-create-lib.php';

    $file = isset($_FILES['attachment']) ? $_FILES['attachment'] : null;
    $result = revenue_process_create_entry($pdo, $_POST, $file);

    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
