<?php
require_once '../../includes/functions.php';

global $pdo;

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO erp_leads (first_name, last_name, email, phone, company, source, status, assigned_to, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email'],
            $_POST['phone'],
            $_POST['company'],
            $_POST['source'],
            'new',
            $_POST['assigned_to'] ?: null,
            $_POST['notes']
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    }
    
    elseif ($action === 'update_status') {
        $stmt = $pdo->prepare("UPDATE erp_leads SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['status'], $_POST['id']]);
        echo json_encode(['success' => true]);
    }
    
    elseif ($action === 'convert') {
        $pdo->beginTransaction();
        
        // 1. Get Lead Details
        $stmt = $pdo->prepare("SELECT * FROM erp_leads WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $lead = $stmt->fetch();
        
        if (!$lead) throw new Exception("Lead not found");
        
        // 2. Create Customer
        $code = 'CUST-' . strtoupper(substr($lead['last_name'], 0, 3)) . '-' . rand(1000, 9999);
        $stmt = $pdo->prepare("INSERT INTO erp_customers (customer_code, name, email, phone, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $code,
            $lead['first_name'] . ' ' . $lead['last_name'],
            $lead['email'],
            $lead['phone'],
            $_SESSION['user_id']
        ]);
        $customerId = $pdo->lastInsertId();
        
        // 3. Update Lead Status
        $stmt = $pdo->prepare("UPDATE erp_leads SET status = 'converted' WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'customer_id' => $customerId]);
    }
    
    else {
        throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
