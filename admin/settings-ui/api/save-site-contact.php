<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

adminSettingsUiRequireAccess();

if (!function_exists('isUltimateSystemAdmin') || !isUltimateSystemAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Only the UltiTech system administrator can change website contact details.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'message' => 'Send the details as JSON.']);
    exit;
}

require_once dirname(__DIR__, 3) . '/includes/site-contact.php';

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

$result = erp_site_contact_save($data);
if (!$result['ok']) {
    http_response_code(422);
}

echo json_encode($result);
