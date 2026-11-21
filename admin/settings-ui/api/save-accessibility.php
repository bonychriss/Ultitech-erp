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
if (!is_array($data)) {
    $data = [];
}

if (!function_exists('erp_save_accessibility_prefs') || !function_exists('erp_get_accessibility_prefs')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Accessibility helpers are unavailable.']);
    exit;
}

if (!erp_save_accessibility_prefs($data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save accessibility settings. Please try again.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Accessibility settings updated.',
    'accessibility' => erp_get_accessibility_prefs(),
]);
