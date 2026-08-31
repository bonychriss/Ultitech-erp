<?php
require_once '../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    
    if ($action === 'run_payroll') {
        if (empty($_POST['month']) || empty($_POST['employees']) || !is_array($_POST['employees'])) {
            throw new Exception('Month and employee data are required');
        }
        
        $month = $_POST['month'] . '-01';
        $employees = $_POST['employees'];
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("DELETE FROM erp_payroll WHERE payroll_month = ? AND status = 'draft'");
        $stmt->execute([$month]);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_payroll WHERE payroll_month = ? AND status != 'draft'");
        $stmt->execute([$month]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot re-run payroll. Some records are already approved or paid.');
        }
        
        $sql = "INSERT INTO erp_payroll (payroll_month, employee_id, basic_salary, allowances, deductions, net_salary, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)";
        $stmt = $pdo->prepare($sql);
        
        foreach ($employees as $empId => $data) {
            $basic = floatval($data['basic']);
            $allow = floatval($data['allowances']);
            $deduct = floatval($data['deductions']);
            $net = $basic + $allow - $deduct;
            
            $stmt->execute([$month, $empId, $basic, $allow, $deduct, $net, $_SESSION['user_id']]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Payroll generated successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
