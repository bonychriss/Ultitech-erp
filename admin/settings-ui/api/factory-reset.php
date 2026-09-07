<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

adminSettingsUiRequireAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
$confirm = (string) ($data['confirm_reset'] ?? $_POST['confirm_reset'] ?? '');

$result = adminSettingsUiExecuteFactoryReset($confirm);
if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result);
