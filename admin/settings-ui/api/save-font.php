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
$fontKey = (string) ($data['system_font'] ?? $_POST['system_font'] ?? 'dm_sans');

if (!function_exists('saveSystemFontKey') || !saveSystemFontKey($fontKey)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save font selection. Please try again.']);
    exit;
}

$def = function_exists('getSystemFontDefinition') ? getSystemFontDefinition($fontKey) : [];
echo json_encode([
    'ok' => true,
    'message' => 'System font updated successfully.',
    'font' => [
        'current' => $fontKey,
        'label' => (string) ($def['label'] ?? $fontKey),
        'stack' => (string) ($def['stack'] ?? ''),
        'google' => (string) ($def['google'] ?? ''),
    ],
]);
