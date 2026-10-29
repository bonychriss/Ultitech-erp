<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 3) . '/includes/welcome-characters.php';

header('Content-Type: application/json; charset=utf-8');

adminSettingsUiRequireAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
$field = trim((string) ($data['business_field'] ?? ''));
$fields = companyBusinessFields();

if (!array_key_exists($field, $fields)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Choose a company field.']);
    exit;
}

if (!saveCompanyBusinessField($field)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save the company field. Please try again.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Company field set to ' . $fields[$field]['label'] . '. Welcome emails now use the matching character.',
    'current' => $field,
]);
