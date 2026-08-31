<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO erp_opportunities (name, customer_id, lead_id, amount, stage, probability, expected_close_date, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'],
            $_POST['customer_id'] ?: null,
            $_POST['lead_id'] ?: null,
            $_POST['amount'],
            $_POST['stage'],
            $_POST['probability'],
            $_POST['expected_close_date'],
            $_POST['assigned_to'] ?: null
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    }
    
    elseif ($action === 'update_stage') {
        $stmt = $pdo->prepare("UPDATE erp_opportunities SET stage = ?, probability = ? WHERE id = ?");
        $stmt->execute([$_POST['stage'], $_POST['probability'], $_POST['id']]);
        echo json_encode(['success' => true]);
    }
    
    else {
        throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
