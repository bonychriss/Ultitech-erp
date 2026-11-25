<?php

require_once __DIR__ . '/../includes/statement-lib.php';

customerStatementDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || !isset($input['csrf_token'])) {
        throw new RuntimeException('Missing required parameters.');
    }

    $id = (int)$input['id'];
    $csrfToken = $input['csrf_token'];

    if (!hash_equals($csrfToken, $_SESSION['csrf_token'] ?? '')) {
        throw new RuntimeException('Invalid CSRF token.');
    }

    $pdo = customer_statement_pdo();
    
    $stmt = $pdo->prepare('
        SELECT id FROM customer_statement_history 
        WHERE id = :id 
        LIMIT 1
    ');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Statement not found.');
    }

    $deleteStmt = $pdo->prepare('
        DELETE FROM customer_statement_history 
        WHERE id = :id 
        LIMIT 1
    ');
    $result = $deleteStmt->execute([':id' => $id]);

    if (!$result || $deleteStmt->rowCount() === 0) {
        throw new RuntimeException('Failed to delete statement.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Statement deleted successfully.',
        'deleted_id' => $id,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
