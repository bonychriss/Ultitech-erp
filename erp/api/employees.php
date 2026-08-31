<?php
require_once '../../includes/functions.php';
require_once '../includes/ActivityLogger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    $logger = new ActivityLogger($pdo);

    if ($action === 'create') {
        // Validate required fields
        if (empty($_POST['first_name']) || empty($_POST['last_name']) || empty($_POST['email'])) {
            throw new Exception("Name and Email are required.");
        }

        $stmt = $pdo->prepare("INSERT INTO erp_employees (employee_code, first_name, last_name, email, phone, position, join_date, basic_salary, status, bank_name, bank_account_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_POST['employee_code'],
            $_POST['first_name'],
            $_POST['last_name'],
            $_POST['email'],
            $_POST['phone'],
            $_POST['position'],
            $_POST['join_date'],
            $_POST['basic_salary'],
            $_POST['status'],
            $_POST['bank_name'],
            $_POST['bank_account_number']
        ]);
        
        $id = $pdo->lastInsertId();
        $logger->log('hr', $id, 'created', "Employee {$_POST['first_name']} {$_POST['last_name']} created.");
        
        echo json_encode(['success' => true, 'id' => $id]);

    } else {
        throw new Exception("Invalid action.");
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
