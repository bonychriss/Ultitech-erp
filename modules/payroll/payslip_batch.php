<?php
// modules/payroll/payslip_batch.php
require_once __DIR__ . '/config/database.php';

// Strict Access Control
define('ALLOW_ANONYMOUS_PAYROLL', true);
requireFinanceOrAdmin();
$run_id = intval($_GET['id']);

// Fetch Run Info
$stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?');
$stmt->execute([$run_id]);
$run = $stmt->fetch();
if (!$run) die("Run not found");

// Fetch All Payslips
$stmt = $pdo->prepare("
    SELECT p.*, pr.month, pr.year, u.full_name, u.role, u.department,
           es.bank_name, es.account_number, es.nssf_number, es.tin_number
    FROM " . payroll_table('payslips') . " p
    JOIN " . payroll_table('payroll_runs') . " pr ON p.payroll_run_id = pr.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN " . payroll_table('employee_salary') . " es ON u.id = es.user_id
    WHERE pr.id = ?
    ORDER BY u.full_name ASC
");
$stmt->execute([$run_id]);
$slips = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch Payslips - <?= date('F Y', strtotime($run['year'].'-'.$run['month'].'-01')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; padding: 20px; }
        .payslip-page { 
            background: white; 
            width: 100%;
            max-width: 210mm; /* A4 Width Limit */
            min-height: 297mm; 
            padding: 20mm; 
            margin: 0 auto 20px auto; 
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            position: relative;
            box-sizing: border-box;
        }

        /* Mobile Adjustments */
        @media (max-width: 768px) {
            body { padding: 10px; }
            .payslip-page {
                padding: 15px;
                margin-bottom: 10px;
            }
            .info-group div { font-size: 11px; }
            .table-details th, .table-details td { font-size: 11px; padding: 5px; }
            .net-pay-box .h4 { font-size: 1.1rem; }
        }
        
        @media print {
            body { background: white; padding: 0 !important; }
            .payslip-page { box-shadow: none; margin: 0; page-break-after: always; width: 210mm !important; max-width: none !important; padding: 20mm !important; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="container no-print mb-4 text-center">
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i> This view shows all payslips for the period. Use the browser print button (Ctrl+P) to print all at once.
        </div>
        <button onclick="window.print()" class="btn btn-primary btn-lg"><i class="bi bi-printer me-2"></i> Print All Payslips</button>
        <a href="view_run.php?id=<?= $run_id ?>" class="btn btn-outline-secondary btn-lg">Back to Run</a>
    </div>

    <?php foreach ($slips as $slip): ?>
    <div class="payslip-page">
        <!-- Header -->
        <div class="company-header d-flex justify-content-between align-items-end">
            <div>
                <h4 class="mb-0 fw-bold"><?= defined('COMPANY_NAME') ? COMPANY_NAME : 'COMPANY NAME' ?></h4>
                <small class="text-muted">Payroll System - Employee Payslip</small>
            </div>
            <div class="text-end">
                <h3 class="payslip-title mb-0">Payslip</h3>
                <div>Period: <strong><?= date('F Y', mktime(0,0,0,$slip['month'], 1, $slip['year'])) ?></strong></div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="row g-3 mb-4">
            <div class="col-7">
                <div class="row g-2">
                    <div class="col-12"><div class="info-group"><label>Employee</label><div><?= htmlspecialchars($slip['full_name']) ?></div></div></div>
                    <div class="col-6"><div class="info-group"><label>Department</label><div><?= htmlspecialchars($slip['department']) ?></div></div></div>
                    <div class="col-6"><div class="info-group"><label>Designation</label><div><?= ucfirst($slip['role']) ?></div></div></div>
                </div>
            </div>
            <div class="col-5">
                <div class="row g-2">
                    <div class="col-6"><div class="info-group"><label>Bank</label><div><?= htmlspecialchars($slip['bank_name'] ?? '-') ?></div></div></div>
                    <div class="col-6"><div class="info-group"><label>Acc No.</label><div><?= htmlspecialchars($slip['account_number'] ?? '-') ?></div></div></div>
                    <div class="col-6"><div class="info-group"><label>NSSF No.</label><div><?= htmlspecialchars($slip['nssf_number'] ?? '-') ?></div></div></div>
                    <div class="col-6"><div class="info-group"><label>TIN No.</label><div><?= htmlspecialchars($slip['tin_number'] ?? '-') ?></div></div></div>
                </div>
            </div>
        </div>

        <!-- Table -->
        <table class="table table-bordered table-sm table-details mb-4">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="amount-col">Earnings</th>
                    <th class="amount-col">Deductions</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Basic Salary</td>
                    <td class="amount-col"><?= number_format($slip['basic_salary'], 2) ?></td>
                    <td class="amount-col"></td>
                </tr>
                <tr>
                    <td>Allowances</td>
                    <td class="amount-col"><?= number_format($slip['total_allowances'], 2) ?></td>
                    <td class="amount-col"></td>
                </tr>
                <tr class="table-light">
                    <td class="fw-bold text-end">Gross Salary</td>
                    <td class="amount-col fw-bold"><?= number_format($slip['gross_salary'], 2) ?></td>
                    <td></td>
                </tr>
                <tr>
                    <td>NSSF (Social Security)</td>
                    <td></td>
                    <td class="amount-col text-danger"><?= number_format($slip['nssf_deduction'], 2) ?></td>
                </tr>
                <tr>
                    <td>P.A.Y.E (Tax)</td>
                    <td></td>
                    <td class="amount-col text-danger"><?= number_format($slip['tax_deduction'], 2) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td class="text-end">Total Amount</td>
                    <td class="amount-col"><?= number_format($slip['gross_salary'], 2) ?></td>
                    <td class="amount-col text-danger"><?= number_format($slip['nssf_deduction'] + $slip['tax_deduction'], 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Net -->
        <div class="row justify-content-end">
            <div class="col-5">
                <div class="net-pay-box">
                    <small class="text-uppercase fw-bold text-success">Net Pay</small>
                    <div class="h4 mb-0 fw-bold">TZS <?= number_format($slip['net_salary'], 2) ?></div>
                </div>
            </div>
        </div>

        <div style="margin-top: 40px; border-top: 1px dashed #ccc; padding-top: 10px;" class="text-center text-muted small">
            Computed by ERP Payroll Module. Employee Signature: _______________________
        </div>
    </div>
    <?php endforeach; ?>
</body>
</html>
