<?php
require_once '../includes/functions.php';
requireAdmin();

// Get statistics for reports
$stats_sql = "
    SELECT 
        DATE(date_created) as date,
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) as approved_amount,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
    FROM payment_vouchers 
    WHERE date_created >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(date_created)
    ORDER BY date DESC
";

$stmt = $pdo->prepare($stats_sql);
$stmt->execute();
$daily_stats = $stmt->fetchAll();

// Get department wise statistics
$dept_sql = "
    SELECT 
        u.department,
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN pv.status = 'approved' THEN pv.total_amount ELSE 0 END) as approved_amount,
        AVG(pv.total_amount) as avg_amount
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    GROUP BY u.department
    ORDER BY total_vouchers DESC
";

$stmt = $pdo->prepare($dept_sql);
$stmt->execute();
$dept_stats = $stmt->fetchAll();

// Export functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vouchers_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Voucher No',
        'Payee Name', 
        'Prepared By',
        'Department',
        'Amount',
        'Currency',
        'Status',
        'Date Created',
        'Date Approved'
    ]);
    
    // Get all vouchers for export
    $export_sql = "
        SELECT pv.voucher_no, pv.payee_name, pv.prepared_by, u.full_name, u.department,
               pv.total_amount, pv.currency, pv.status, pv.date_created, pv.approved_at
        FROM payment_vouchers pv
        LEFT JOIN users u ON pv.created_by = u.id
        ORDER BY pv.created_at DESC
    ";
    
    $stmt = $pdo->prepare($export_sql);
    $stmt->execute();
    
    while ($row = $stmt->fetch()) {
        $preparedBy = trim((string)($row['prepared_by'] ?? ''));
        if ($preparedBy === '' && !empty($row['full_name'])) {
            $preparedBy = $row['full_name'];
        }
        fputcsv($output, [
            $row['voucher_no'],
            $row['payee_name'],
            $preparedBy,
            $row['department'],
            $row['total_amount'],
            $row['currency'],
            ucfirst($row['status']),
            $row['date_created'],
            $row['approved_at'] ? date('Y-m-d H:i', strtotime($row['approved_at'])) : ''
        ]);
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Compact page-scoped tweaks for Reports only */
        body.dashboard .main-content { padding: 16px 14px; }

        /* Actions */
        body.dashboard .actions { margin-bottom: 16px; }
        body.dashboard .actions .btn { padding: 6px 12px; font-size: 12px; border-radius: 0; }

        /* Card containers */
        body.dashboard .form-container { padding: 16px; border-radius: 0; }
        body.dashboard .form-container h2 { font-size: 16px; margin-bottom: 10px; }

        /* Tables */
        body.dashboard .data-table { border-radius: 0; margin-bottom: 16px; }
        body.dashboard .data-table th { padding: 10px; font-size: 12px; }
        body.dashboard .data-table td { padding: 8px 10px; font-size: 12px; }

        @media (max-width: 640px) {
            body.dashboard .data-table th { padding: 8px; }
            body.dashboard .data-table td { padding: 6px 8px; }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="actions">
            <a href="dashboard.php" class="icon-link icon-neutral" title="Back" aria-label="Back">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <a href="?export=csv" class="btn btn-success">Export to CSV</a>
        </div>

        <div class="form-container">
            <h2>Daily Statistics (Last 30 Days)</h2>
            
            <?php if (empty($daily_stats)): ?>
                <p>No data available for the last 30 days.</p>
            <?php else: ?>
                <div class="table-wrap stacked-table">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Vouchers</th>
                            <th>Approved</th>
                            <th>Pending</th>
                            <th>Rejected</th>
                            <th>Approved Amount (TZS)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daily_stats as $stat): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($stat['date'])) ?></td>
                            <td><?= $stat['total_vouchers'] ?></td>
                            <td><?= $stat['approved_count'] ?></td>
                            <td><?= $stat['pending_count'] ?></td>
                            <td><?= $stat['rejected_count'] ?></td>
                            <td><?= number_format($stat['approved_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-container">
            <h2>Department Statistics</h2>
            
            <?php if (empty($dept_stats)): ?>
                <p>No department data available.</p>
            <?php else: ?>
                <div class="table-wrap stacked-table">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Total Vouchers</th>
                            <th>Total Approved Amount</th>
                            <th>Average Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dept_stats as $stat): ?>
                        <tr>
                            <td><?= htmlspecialchars($stat['department'] ?? 'Unknown') ?></td>
                            <td><?= $stat['total_vouchers'] ?></td>
                            <td>TZS <?= number_format($stat['approved_amount'], 2) ?></td>
                            <td>TZS <?= number_format($stat['avg_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
