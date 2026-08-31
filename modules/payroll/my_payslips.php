<?php
// modules/payroll/my_payslips.php
require_once __DIR__ . '/config/database.php';

// Access Control: Must be logged in
requireLogin();

$user_id = $_SESSION['user_id'];

// Fetch payslips for this user
$stmt = $pdo->prepare("
    SELECT p.*, pr.month, pr.year, pr.run_date
    FROM " . payroll_table('payslips') . " p
    JOIN " . payroll_table('payroll_runs') . " pr ON p.payroll_run_id = pr.id
    WHERE p.user_id = ? AND pr.status IN ('approved', 'paid') AND p.is_published = 1
    ORDER BY pr.year DESC, pr.month DESC
");
$stmt->execute([$user_id]);
$payslips = $stmt->fetchAll();

$userName = $_SESSION['full_name'] ?? 'Employee';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payslips - Staff ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .table-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
        .header-banner { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 30px 0; }
        .badge-paid { background-color: #dcfce7; color: #166534; font-weight: 600; }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include_once '../../sidebar.php'; ?>
        
        <div class="flex-grow-1">
            <?php include_once '../../includes/header_employee.php'; ?>
            
            <div class="header-banner mb-4">
                <div class="container-fluid px-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h3 mb-1">My Payslips</h1>
                            <p class="text-muted mb-0">View and download your monthly salary records</p>
                        </div>
                        <div class="text-end d-none d-md-block">
                            <i class="bi bi-wallet2 fs-1 text-primary opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container-fluid px-4">
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Period</th>
                                    <th>Run Date</th>
                                    <th>Basic Salary</th>
                                    <th>Net Pay</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($payslips) > 0): ?>
                                    <?php foreach ($payslips as $p): 
                                        $period = date('F Y', mktime(0, 0, 0, $p['month'], 1, $p['year']));
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?= $period ?></div>
                                            <div class="small text-muted">ID: #<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                        </td>
                                        <td><?= date('d M, Y', strtotime($p['run_date'])) ?></td>
                                        <td><?= number_format($p['basic_salary'], 2) ?></td>
                                        <td class="fw-bold text-primary"><?= number_format($p['net_salary'], 2) ?></td>
                                        <td>
                                            <?php if($p['status']=='paid'): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success">Paid</span>
                                            <?php else: ?>
                                                <span class="badge bg-info bg-opacity-10 text-info">Approved</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group">
                                                <a href="payslip.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="bi bi-eye me-1"></i> View
                                                </a>
                                                <a href="payslip.php?id=<?= $p['id'] ?>&download=1" class="btn btn-sm btn-outline-success" target="_blank">
                                                    <i class="bi bi-download me-1"></i> PDF
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="bi bi-file-earmark-text fs-1 d-block mb-3 opacity-25"></i>
                                            No payslips found in your record yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-white rounded border small text-muted">
                    <i class="bi bi-info-circle me-1 text-primary"></i> 
                    Issues with your payslip? Please contact the Finance or HR department for clarification.
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
