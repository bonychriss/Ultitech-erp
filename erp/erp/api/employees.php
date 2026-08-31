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
        if (empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['join_date']) || empty($_POST['basic_salary'])) {
            throw new Exception('Name, join date, and salary are required');
        }
        
        $sql = "INSERT INTO erp_employees (first_name, last_name, email, phone, department_id, position, join_date, basic_salary, bank_name, bank_account_number, status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['department_id'] ?: null,
            $_POST['position'] ?? null,
            $_POST['join_date'],
            floatval($_POST['basic_salary']),
            $_POST['bank_name'] ?? null,
            $_POST['bank_account_number'] ?? null,
            $_POST['status'] ?? 'active',
            $_POST['user_id'] ?: null
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Employee created successfully']);
        
    } elseif ($action === 'update') {
        if (empty($_POST['id']) || empty($_POST['first_name']) || empty($_POST['last_name'])) {
            throw new Exception('ID and name are required');
        }
        
        $sql = "UPDATE erp_employees SET first_name = ?, last_name = ?, email = ?, phone = ?, department_id = ?, position = ?, join_date = ?, basic_salary = ?, bank_name = ?, bank_account_number = ?, status = ?, user_id = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['department_id'] ?: null,
            $_POST['position'] ?? null,
            $_POST['join_date'],
            floatval($_POST['basic_salary']),
            $_POST['bank_name'] ?? null,
            $_POST['bank_account_number'] ?? null,
            $_POST['status'],
            $_POST['user_id'] ?: null,
            $_POST['id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Employee updated successfully']);
        
    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('ID is required');
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_payroll WHERE employee_id = ?");
        $stmt->execute([$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete employee with payroll history');
        }
        
        $stmt = $pdo->prepare("DELETE FROM erp_employees WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Employee deleted successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
