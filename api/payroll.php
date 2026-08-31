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

    if ($action === 'run_payroll') {
        $month = $_POST['month'];
        $employees = $_POST['employees'] ?? [];

        if (empty($month)) throw new Exception("Month required");
        if (empty($employees)) throw new Exception("No employees to process");

        $pdo->beginTransaction();

        // 1. Check if payroll exists for this month and delete/overwrite or error?
        // Usually we shouldn't overwrite if paid. If draft, maybe ok. 
        // For simplicity, let's delete existing DRAFT entries for this month.
        $stmt = $pdo->prepare("DELETE FROM erp_payroll WHERE DATE_FORMAT(payroll_month, '%Y-%m') = ? AND status = 'draft'");
        $stmt->execute([$month]);
        
        // 2. Insert Records
        $stmt = $pdo->prepare("INSERT INTO erp_payroll (payroll_month, employee_id, basic_salary, allowances, deductions, net_salary, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)");
        
        $payrollDate = $month . '-01'; // Store as YYYY-MM-01

        foreach ($employees as $empId => $data) {
            $basic = floatval($data['basic']);
            $allow = floatval($data['allowances']);
            $deduct = floatval($data['deductions']);
            $net = $basic + $allow - $deduct;

            // Optional: Basic validation
            if ($net < 0) throw new Exception("Net salary cannot be negative for employee ID $empId");

            $stmt->execute([
                $payrollDate,
                $empId,
                $basic,
                $allow,
                $deduct,
                $net,
                $_SESSION['user_id'] ?? 1 // Fallback to 1 if session issue
            ]);
        }

        // 3. Log Activity
        $logger->log('hr', 0, 'payroll_run', "Payroll generated for $month");

        $pdo->commit();
        echo json_encode(['success' => true]);
        
    } else {
        throw new Exception("Invalid action");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
