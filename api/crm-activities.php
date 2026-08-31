<?php
require_once '../../includes/functions.php';

global $pdo;

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO erp_crm_activities (type, subject, description, lead_id, opportunity_id, customer_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['type'],
            $_POST['subject'],
            $_POST['description'],
            $_POST['lead_id'] ?: null,
            $_POST['opportunity_id'] ?: null,
            $_POST['customer_id'] ?: null,
            $_POST['due_date'] ?: null,
            $_SESSION['user_id']
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    }
    
    elseif ($action === 'complete') {
        $stmt = $pdo->prepare("UPDATE erp_crm_activities SET completed = 1 WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
    }
    
    else {
        throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
