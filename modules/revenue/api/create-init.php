<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    require_once __DIR__ . '/../includes/revenue-create-lib.php';

    echo json_encode(array_merge(revenue_build_create_init($pdo), [
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
    ]));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
