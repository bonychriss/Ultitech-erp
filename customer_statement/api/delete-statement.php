<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/statement-lib.php';
customerStatementDeskRequireAccess();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !verify_csrf($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Session expired. Reload the page and try again.']);
        exit;
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid statement ID.']);
        exit;
    }

    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        throw new RuntimeException('Cannot determine the current company.');
    }

    if (!customer_statement_delete_history(customer_statement_pdo(), $companyId, $id)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Statement not found.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Statement deleted successfully.',
        'deleted_id' => $id,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
} catch (Throwable $e) {
    error_log('customer statement delete: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to delete statement. Please try again.']);
}
