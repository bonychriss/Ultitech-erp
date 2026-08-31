<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../load-data.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $companyId = (int) currentCompanyId();
    $payload = ai_assistant_load_init_payload($pdo, $userId, $companyId, $_GET);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load AI assistant.'], JSON_UNESCAPED_UNICODE);
}
