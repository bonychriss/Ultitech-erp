<?php
// modules/finance/my_reports.php
require_once '../../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];

// Fetch My Reports
$stmt = $pdo->prepare("SELECT * FROM expenses_reports WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

function getStatusBadge($status) {
    $colors = [
        'draft' => 'bg-secondary',
        'submitted' => 'bg-primary',
        'approved' => 'bg-success',
        'refused' => 'bg-danger',
        'posted' => 'bg-info text-dark',
        'paid' => 'bg-success'
    ];
    $c = $colors[$status] ?? 'bg-secondary';
    return "<span class='badge $c'>" . ucfirst($status) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Reports - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold text-dark mb-1">Expense Reports</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php">Finance</a></li>
                        <li class="breadcrumb-item active">My Reports</li>
                    </ol>
                </nav>
            </div>
        </div>
        
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link" href="my_expenses.php">To Report</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="my_reports.php">My Reports</a>
            </li>
            <?php if (isAdmin() || isFinance()): ?>
            <li class="nav-item">
                <a class="nav-link" href="approvals.php">To Approve</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="categories.php">Categories</a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Report Title</th>
                                <th>Date Created</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reports)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="fas fa-folder-open fa-2x mb-2"></i>
                                        <p>No reports found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reports as $r): ?>
                                <tr onclick="window.location='view_report.php?id=<?= $r['id'] ?>'" style="cursor: pointer;">
                                    <td class="ps-4 fw-medium"><?= htmlspecialchars($r['report_title']) ?></td>
                                    <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
                                    <td class="fw-bold text-dark"><?= number_format($r['total_amount']) ?> TZS</td>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                    <td class="text-end pe-4">
                                        <i class="fas fa-chevron-right text-muted"></i>
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
</div>

</body>
</html>
