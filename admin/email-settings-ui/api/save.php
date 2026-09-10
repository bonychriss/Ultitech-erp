<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    emailSettingsUiRequireAdmin();
    $pdo = emailSettingsUiPdo();

    $raw = file_get_contents('php://input');
    $payload = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $result = emailSettingsUiSave($pdo, $payload);
    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'data' => $result['data'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'Access') ? 403 : 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
