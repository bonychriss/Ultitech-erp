<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../lib.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function system_font_api_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    system_font_api_json(['ok' => false, 'error' => 'Unauthorized.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $config = systemFontUiBuildInitialConfig($userId);
    system_font_api_json(['ok' => true, 'data' => $config]);
}

if ($method !== 'POST') {
    system_font_api_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$rawBody = file_get_contents('php://input') ?: '';
$body = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$postedKey = isset($body['userFont']) ? (string) $body['userFont'] : '';
$saveKey = $postedKey === '' || $postedKey === 'company' ? null : $postedKey;

if (!saveUserFontKey($userId, $saveKey)) {
    system_font_api_json(['ok' => false, 'error' => 'Could not save your font preference. Please try again.'], 500);
}

$config = systemFontUiBuildInitialConfig($userId);
$message = ($config['selectedKey'] ?? '') === ''
    ? 'Your font preference was cleared. Using the company default.'
    : 'Your font preference was saved.';

system_font_api_json([
    'ok' => true,
    'message' => $message,
    'data' => $config,
]);
