<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    
    if ($action === 'create') {
        if (empty($_POST['employee_id']) || empty($_POST['leave_type']) || empty($_POST['start_date']) || empty($_POST['end_date'])) {
            throw new Exception('All fields are required');
        }
        
        $sql = "INSERT INTO erp_leave_requests (employee_id, leave_type, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['employee_id'],
            $_POST['leave_type'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['reason'] ?? null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Leave request submitted successfully']);
        
    } elseif ($action === 'update_status') {
        if (empty($_POST['id']) || empty($_POST['status'])) {
            throw new Exception('ID and status are required');
        }
        
        $sql = "UPDATE erp_leave_requests SET status = ?, approved_by = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['status'], $_SESSION['user_id'], $_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Leave status updated successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
