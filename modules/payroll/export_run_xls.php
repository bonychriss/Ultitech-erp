<?php
// modules/payroll/export_run_xls.php — Excel-style payroll register
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/payroll-lib.php';

define('ALLOW_ANONYMOUS_PAYROLL', true);
if (!isset($_GET['id'])) {
    die('Run ID required');
}
$run_id = (int) $_GET['id'];

payrollDeskEnsureExcelPayrollSchema($pdo);

$stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?');
$stmt->execute([$run_id]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$run) {
    die('Run not found');
}

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
$slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

$period = date('M_Y', strtotime(($run['year'] ?? date('Y')) . '-' . ($run['month'] ?? date('n')) . '-01'));
$filename = 'Payroll_Register_' . $period . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

$fmt = static function ($n): string {
    return number_format((float) $n, 2, '.', '');
};

$totals = [
    'basic' => 0.0,
    'allowances' => 0.0,
    'bonus' => 0.0,
    'gross' => 0.0,
    'nssf' => 0.0,
    'taxable' => 0.0,
    'paye' => 0.0,
    'deductions' => 0.0,
    'net' => 0.0,
    'employerNssf' => 0.0,
    'sdl' => 0.0,
    'wcf' => 0.0,
    'employerCost' => 0.0,
];
?>
<style>
    .header-main { background-color: #1e293b; color: #ffffff; font-weight: bold; border: 1px solid #000; }
    .header-employer { background-color: #7c3aed; color: #ffffff; font-weight: bold; border: 1px solid #000; }
    .header-accent { background-color: #059669; color: #ffffff; font-weight: bold; border: 1px solid #000; }
    td { border: 1px solid #e2e8f0; padding: 5px; }
    .total-row td { background-color: #f8fafc; font-weight: bold; }
</style>
<table>
    <thead>
        <tr>
            <th class="header-main">SN</th>
            <th class="header-main">NAME OF THE EMPLOYEE</th>
            <th class="header-main">DEPARTMENT</th>
            <th class="header-main">BASIC SALARIES</th>
            <th class="header-main">OVERTIME &amp; ALLOWANCES</th>
            <th class="header-main">BONUS / COMMISSION</th>
            <th class="header-main">GROSS SALARIES</th>
            <th class="header-main">EMPLOYEE NSSF 10%</th>
            <th class="header-main">TAXABLE SALARY</th>
            <th class="header-main">PAYE</th>
            <th class="header-main">TOTAL DEDUCTIONS</th>
            <th class="header-accent">NET SALARIES</th>
            <th class="header-employer">EMPLOYER NSSF 10%</th>
            <th class="header-employer">SDL 3.5%</th>
            <th class="header-employer">WCF 0.5%</th>
            <th class="header-employer">EMPLOYER COST</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($slips as $i => $slip):
            $basic = (float) ($slip['basic_salary'] ?? 0);
            $allowances = (float) ($slip['total_allowances'] ?? 0);
            $bonus = (float) ($slip['bonus_commission'] ?? 0);
            $gross = (float) ($slip['gross_salary'] ?? 0);
            $nssf = (float) ($slip['nssf_deduction'] ?? 0);
            $taxable = (float) ($slip['taxable_salary'] ?? max(0, $gross - $nssf));
            $paye = (float) ($slip['tax_deduction'] ?? 0);
            $other = (float) ($slip['other_deductions'] ?? 0);
            $deductions = $nssf + $paye + $other;
            $net = (float) ($slip['net_salary'] ?? 0);
            $employerNssf = (float) ($slip['employer_nssf'] ?? 0);
            $sdl = (float) ($slip['sdl_amount'] ?? 0);
            $wcf = (float) ($slip['wcf_amount'] ?? 0);
            $employerCost = (float) ($slip['employer_cost'] ?? ($gross + $employerNssf + $sdl + $wcf));

            $totals['basic'] += $basic;
            $totals['allowances'] += $allowances;
            $totals['bonus'] += $bonus;
            $totals['gross'] += $gross;
            $totals['nssf'] += $nssf;
            $totals['taxable'] += $taxable;
            $totals['paye'] += $paye;
            $totals['deductions'] += $deductions;
            $totals['net'] += $net;
            $totals['employerNssf'] += $employerNssf;
            $totals['sdl'] += $sdl;
            $totals['wcf'] += $wcf;
            $totals['employerCost'] += $employerCost;
        ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars((string) ($slip['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($slip['department'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= $fmt($basic) ?></td>
            <td><?= $fmt($allowances) ?></td>
            <td><?= $fmt($bonus) ?></td>
            <td><?= $fmt($gross) ?></td>
            <td><?= $fmt($nssf) ?></td>
            <td><?= $fmt($taxable) ?></td>
            <td><?= $fmt($paye) ?></td>
            <td><?= $fmt($deductions) ?></td>
            <td><?= $fmt($net) ?></td>
            <td><?= $fmt($employerNssf) ?></td>
            <td><?= $fmt($sdl) ?></td>
            <td><?= $fmt($wcf) ?></td>
            <td><?= $fmt($employerCost) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td></td>
            <td>GRAND TOTALS</td>
            <td></td>
            <td><?= $fmt($totals['basic']) ?></td>
            <td><?= $fmt($totals['allowances']) ?></td>
            <td><?= $fmt($totals['bonus']) ?></td>
            <td><?= $fmt($totals['gross']) ?></td>
            <td><?= $fmt($totals['nssf']) ?></td>
            <td><?= $fmt($totals['taxable']) ?></td>
            <td><?= $fmt($totals['paye']) ?></td>
            <td><?= $fmt($totals['deductions']) ?></td>
            <td><?= $fmt($totals['net']) ?></td>
            <td><?= $fmt($totals['employerNssf']) ?></td>
            <td><?= $fmt($totals['sdl']) ?></td>
            <td><?= $fmt($totals['wcf']) ?></td>
            <td><?= $fmt($totals['employerCost']) ?></td>
        </tr>
    </tbody>
</table>
