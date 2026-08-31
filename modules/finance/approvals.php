<?php
// modules/finance/approvals.php
require_once '../../includes/functions.php';
requireLogin();

// Only Managers/Admins
if (!isAdmin() && !isFinance()) {
    die("Access Denied");
}

// Fetch Pending Reports
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name as employee_name,
    (SELECT GROUP_CONCAT(description SEPARATOR ', ') FROM expenses_requests WHERE report_id = r.id LIMIT 1) as combined_description
    FROM expenses_reports r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.status = 'submitted' 
    ORDER BY r.submitted_at ASC
");
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approvals - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <h2 class="fw-bold text-dark mb-4">Pending Approvals</h2>
        
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link" href="my_expenses.php">To Report</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_reports.php">My Reports</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="approvals.php">To Approve</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="categories.php">Categories</a>
            </li>
        </ul>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Employee</th>
                            <th>Report Title</th>
                            <th style="max-width: 300px;">Description</th>
                            <th>Submitted On</th>
                            <th>Total Amount</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                                    <p>No pending approvals. You're all caught up!</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reports as $r): ?>
                            <tr onclick="window.location='view_report.php?id=<?= $r['id'] ?>'" style="cursor: pointer;">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;font-size:0.8rem">
                                            <?= strtoupper(substr($r['employee_name'],0,1)) ?>
                                        </div>
                                        <span class="fw-medium"><?= htmlspecialchars($r['employee_name']) ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($r['report_title']) ?></td>
                                <td class="text-muted small text-truncate" style="max-width: 300px;">
                                    <?= htmlspecialchars(mb_strimwidth($r['combined_description'], 0, 80, "...")) ?>
                                </td>
                                <td><?= date('M d, H:i', strtotime($r['submitted_at'])) ?></td>
                                <td class="fw-bold"><?= number_format($r['total_amount']) ?> TZS</td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-primary">Review</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

</body>
</html>
