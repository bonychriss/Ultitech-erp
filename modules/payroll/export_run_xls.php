<?php
// modules/payroll/export_run_xls.php
session_start();
require_once __DIR__ . '/config/database.php';

define('ALLOW_ANONYMOUS_PAYROLL', true);
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

$filename = "Payroll_" . date('M_Y', strtotime($run['year'].'-'.$run['month'].'-01')) . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=$filename");
header("Pragma: no-cache");
header("Expires: 0");

?>
<style>
    .header-main { background-color: #1e293b; color: #ffffff; font-weight: normal; border: 1px solid #000; }
    .header-accent { background-color: #059669; color: #ffffff; font-weight: normal; border: 1px solid #000; }
    td { border: 1px solid #e2e8f0; padding: 5px; }
    .val-positive { color: #059669; }
    .val-negative { color: #e11d48; }
    .total-label { background-color: #f8fafc; font-weight: bold; color: #64748b; text-align: right; }
    .total-value { font-weight: bold; color: #059669; font-size: 14px; }
</style>
<table>
    <thead>
        <tr>
            <th class="header-main">Employee Name</th>
            <th class="header-main">Department</th>
            <th class="header-main">Basic Salary</th>
            <th class="header-main">Allowances</th>
            <th class="header-main">Monthly Adj.</th>
            <th class="header-main">Gross Salary</th>
            <th class="header-main">Deductions</th>
            <th class="header-accent">Net Salary</th>
            <th class="header-main">Bank Name</th>
            <th class="header-main">Account Number</th>
            <th class="header-main">Tax (PAYE)</th>
            <th class="header-main">NSSF</th>
            <th class="header-main">TIN Number</th>
            <th class="header-main">NSSF Number</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $total_net = 0;
        foreach ($slips as $slip): 
            $total_net += (float)$slip['net_salary'];
        ?>
        <tr>
            <td><?= htmlspecialchars($slip['full_name']) ?></td>
            <td><?= htmlspecialchars($slip['department']) ?></td>
            <td><?= number_format($slip['basic_salary'], 2, '.', '') ?></td>
            <td><?= number_format($slip['total_allowances'], 2, '.', '') ?></td>
            <td><?= number_format($slip['monthly_adjustment'], 2, '.', '') ?></td>
            <td><?= number_format($slip['gross_salary'], 2, '.', '') ?></td>
            <td><?= number_format($slip['other_deductions'], 2, '.', '') ?></td>
            <td><?= number_format($slip['net_salary'], 2, '.', '') ?></td>
            <td><?= htmlspecialchars($slip['bank_name']) ?></td>
            <td><?= htmlspecialchars($slip['account_number']) ?></td>
            <td><?= number_format($slip['tax_deduction'], 2, '.', '') ?></td>
            <td><?= number_format($slip['nssf_deduction'], 2, '.', '') ?></td>
            <td><?= htmlspecialchars($slip['tin_number']) ?></td>
            <td><?= htmlspecialchars($slip['nssf_number']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr><td colspan="14"></td></tr>
        <tr>
            <td colspan="12"></td>
            <td class="total-label">TOTAL PAYOUT</td>
            <td class="total-value"><?= number_format($total_net, 2, '.', '') ?></td>
        </tr>
    </tbody>
</table>
