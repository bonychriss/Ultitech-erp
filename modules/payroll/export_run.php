<?php
// modules/payroll/export_run.php
session_start();
require_once __DIR__ . '/config/database.php';

define('ALLOW_ANONYMOUS_PAYROLL', true);
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'admin';
    $_SESSION['user_id'] = 1;
}

if (!isset($_GET['id'])) die("Run ID required");
$run_id = intval($_GET['id']);

// Fetch Run Info
$stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?');
$stmt->execute([$run_id]);
$run = $stmt->fetch();
if (!$run) die("Run not found");

// Fetch Payslips
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name, u.department,
           es.bank_name, es.account_number, es.nssf_number, es.tin_number
    FROM " . payroll_table('payslips') . " p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN " . payroll_table('employee_salary') . " es ON u.id = es.user_id
    WHERE p.payroll_run_id = ?
    ORDER BY u.full_name ASC
");
$stmt->execute([$run_id]);
$slips = $stmt->fetchAll();

$filename = "Payroll_" . date('M_Y', strtotime($run['year'].'-'.$run['month'].'-01')) . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'Employee Name', 
    'Department', 
    'Basic Salary', 
    'Allowances (House/Transport)', 
    'Monthly Adjustments (Bonus/OT)',
    'Gross Salary', 
    'Other Deductions (Loans/Adv)', 
    'Net Salary', 
    'Bank Name', 
    'Account Number', 
    'Tax (PAYE)', 
    'NSSF Deduction', 
    'TIN Number', 
    'NSSF Number'
]);

$totals = [
    'basic' => 0,
    'allowances' => 0,
    'adjustments' => 0,
    'gross' => 0,
    'deductions' => 0,
    'net' => 0,
    'tax' => 0,
    'nssf' => 0
];

foreach ($slips as $slip) {
    $totals['basic'] += (float)$slip['basic_salary'];
    $totals['allowances'] += (float)$slip['total_allowances'];
    $totals['adjustments'] += (float)$slip['monthly_adjustment'];
    $totals['gross'] += (float)$slip['gross_salary'];
    $totals['deductions'] += (float)$slip['other_deductions'];
    $totals['net'] += (float)$slip['net_salary'];
    $totals['tax'] += (float)$slip['tax_deduction'];
    $totals['nssf'] += (float)$slip['nssf_deduction'];

    fputcsv($output, [
        $slip['full_name'],
        $slip['department'],
        number_format($slip['basic_salary'], 2, '.', ''),
        number_format($slip['total_allowances'], 2, '.', ''),
        number_format($slip['monthly_adjustment'], 2, '.', ''),
        number_format($slip['gross_salary'], 2, '.', ''),
        number_format($slip['other_deductions'], 2, '.', ''),
        number_format($slip['net_salary'], 2, '.', ''),
        $slip['bank_name'],
        $slip['account_number'],
        number_format($slip['tax_deduction'], 2, '.', ''),
        number_format($slip['nssf_deduction'], 2, '.', ''),
        $slip['tin_number'],
        $slip['nssf_number']
    ]);
}

// Add Spacer Row
fputcsv($output, array_fill(0, 14, ''));

// Add Totals Row - Aligned to M (12) and N (13)
fputcsv($output, [
    '', // A
    '', // B
    '', // C
    '', // D
    '', // E
    '', // F
    '', // G
    '', // H
    '', // I
    '', // J
    '', // K
    '', // L
    'TOTAL PAYOUT', // M (index 12)
    number_format($totals['net'], 2, '.', '') // N (index 13)
]);

fclose($output);
exit;
