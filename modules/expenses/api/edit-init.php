<?php
require_once '../../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/../includes/currency_helpers.php';
require_once __DIR__ . '/../includes/balances_integration.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$id = (int) ($_GET['id'] ?? 0);

try {
    expenses_ensure_schema($pdo);
    $draft = expenses_fetch_editable_draft($pdo, $id);
    if ($draft === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Draft expense not found or cannot be edited.']);
        exit;
    }

    $ctx = expenses_build_desk_form_init($pdo);

    echo json_encode(array_merge($ctx, [
        'csrf_token' => csrf_token(),
        'preview_expense_number' => (string) ($draft['expense_number'] ?? ''),
        'draft' => expenses_draft_to_form_fields($pdo, $draft),
        'edit_id' => $id,
    ]));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
