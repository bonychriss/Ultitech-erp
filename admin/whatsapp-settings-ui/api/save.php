<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    whatsappSettingsUiRequireAdmin();
    $pdo = whatsappSettingsUiPdo();
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $payload = whatsappSettingsUiSavePayload($pdo, is_array($body) ? $body : []);
    echo json_encode([
        'success' => true,
        'message' => 'WhatsApp settings saved.',
        'data' => $payload,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'Access') ? 403 : 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
