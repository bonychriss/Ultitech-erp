<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../load-data.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $query = $_GET;
    $query['skip_ai'] = 1;
    $payload = deliveries_load_dashboard_payload($pdo, $query);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
    );
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not encode dashboard payload.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $json;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
