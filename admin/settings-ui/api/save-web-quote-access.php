<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

adminSettingsUiRequireAccess();

$quoteLib = dirname(__DIR__, 3) . '/modules/sales/quote-requests/includes/quote-requests-lib.php';
if (!is_file($quoteLib)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Quote access library missing.']);
    exit;
}
require_once $quoteLib;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
$userIds = $data['user_ids'] ?? ($_POST['user_ids'] ?? []);
if (!is_array($userIds)) {
    $userIds = [];
}

if (!salesQuoteRequestsSetAllowedUserIds($userIds)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save website quote permissions.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Permissions updated.',
    'user_ids' => salesQuoteRequestsAllowedUserIds(),
]);
