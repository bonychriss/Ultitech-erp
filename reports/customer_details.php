<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

$customerId = $_GET['id'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-6 months'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (!$customerId) {
    die("Customer ID required");
}

// 1. Fetch Customer Info
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    die("Customer not found");
}

// 2. Fetch Invoice Summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(total_amount) as total_amount
    FROM invoices 
    WHERE customer_id = ? AND status != 'cancelled' AND created_at BETWEEN ? AND ?
");
$stmt->execute([$customerId, $startDate, $endDate]);
$invoiceSummary = $stmt->fetch();

// 3. Fetch Quotation/Order Summary (All active non-invoiced)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(total_amount) as total_amount
    FROM sales_orders 
    WHERE customer_id = ? AND status NOT IN ('cancelled', 'invoiced', 'paid') AND created_at BETWEEN ? AND ?
");
$stmt->execute([$customerId, $startDate, $endDate]);
$quoteSummary = $stmt->fetch();

// 4. Monthly Breakdown
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as inv_count,
        SUM(total_amount) as inv_amount
    FROM invoices 
    WHERE customer_id = ? AND status != 'cancelled' AND created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$customerId, $startDate, $endDate]);
$invMonthly = $stmt->fetchAll(PDO::FETCH_UNIQUE);

$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as quote_count,
        SUM(total_amount) as quote_amount
    FROM sales_orders 
    WHERE customer_id = ? AND status != 'cancelled' AND created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$customerId, $startDate, $endDate]);
$quoteMonthly = $stmt->fetchAll(PDO::FETCH_UNIQUE);

$allMonths = array_unique(array_merge(array_keys($invMonthly), array_keys($quoteMonthly)));
rsort($allMonths);

$monthlyBreakdown = [];
foreach ($allMonths as $m) {
    $monthlyBreakdown[] = [
        'month' => $m,
        'inv_count' => $invMonthly[$m]['inv_count'] ?? 0,
        'inv_amount' => $invMonthly[$m]['inv_amount'] ?? 0,
        'quote_count' => $quoteMonthly[$m]['quote_count'] ?? 0,
        'quote_amount' => $quoteMonthly[$m]['quote_amount'] ?? 0
    ];
}

// 5. Recent Invoices
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? AND status != 'cancelled' ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$customerId]);
$recentInvoices = $stmt->fetchAll();

// 6. Recent Orders/Quotes
$stmt = $pdo->prepare("SELECT * FROM sales_orders WHERE customer_id = ? AND status != 'cancelled' ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$customerId]);
$recentQuotes = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer History | <?= htmlspecialchars($customer['company_name']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6; --secondary: #10b981; --accent: #f59e0b; --danger: #ef4444; 
            --bg-primary: #f8fafc; --bg-secondary: #ffffff; --text-primary: #1e293b; --text-secondary: #64748b; --border: #e2e8f0;
        }
        body { background: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', sans-serif; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn-back { display: inline-flex; align-items: center; padding: 8px 16px; background: white; border: 1px solid var(--border); border-radius: 8px; text-decoration: none; color: var(--text-primary); transition: all 0.2s; }
        .btn-back:hover { transform: translateX(-4px); }
        
        .profile-card { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 30px; display: flex; align-items: center; gap: 20px; }
        .avatar { width: 70px; height: 70px; background: var(--primary); color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; }
        .profile-info h1 { margin: 0; font-size: 1.5rem; }
        
        .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px; }
        .metric-card { background: white; padding: 24px; border-radius: 16px; border-left: 6px solid var(--primary); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .metric-card.quote { border-left-color: var(--accent); }
        .metric-label { font-size: 0.875rem; color: var(--text-secondary); }
        .metric-value { font-size: 1.75rem; font-weight: 700; margin: 8px 0; }
        
        .detail-card { background: white; padding: 24px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .detail-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        
        .table-custom { width: 100%; border-collapse: collapse; }
        .table-custom th { text-align: left; font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; padding: 12px 8px; }
        .table-custom td { padding: 12px 8px; border-bottom: 1px solid var(--border); font-size: 0.875rem; }
        
        .doc-link { color: var(--primary); text-decoration: none; font-weight: 600; }
        .doc-link:hover { text-decoration: underline; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #dcfce7; color: #166534; } .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef9c3; color: #854d0e; } .badge-secondary { background: #f1f5f9; color: #475569; }
        .badge-info { background: #e0f2fe; color: #075985; } .badge-danger { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div style="display: flex; align-items: center; gap: 20px;">
            <a href="sales.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i> Back</a>
            <h2 style="margin: 0;">Customer Deep-Dive</h2>
        </div>
        
        <form method="GET" style="display: flex; gap: 8px; background: white; padding: 6px; border-radius: 8px; border: 1px solid var(--border);">
            <input type="hidden" name="id" value="<?= $customerId ?>">
            <input type="date" name="start_date" value="<?= $startDate ?>">
            <span style="align-self: center;">to</span>
            <input type="date" name="end_date" value="<?= $endDate ?>">
            <button type="submit" style="background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer;">Filter</button>
        </form>
    </div>

    <div class="profile-card">
        <div class="avatar"><?= strtoupper(substr($customer['company_name'], 0, 1)) ?></div>
        <div class="profile-info">
            <h1><?= htmlspecialchars($customer['company_name']) ?></h1>
            <p style="margin: 4px 0 0; color: var(--text-secondary);">
                <i class="fas fa-user me-1"></i> <?= htmlspecialchars($customer['contact_person']) ?> | 
                <i class="fas fa-id-card me-1"></i> TIN: <?= htmlspecialchars($customer['tin'] ?: 'N/A') ?>
            </p>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Lifetime Revenue (Filtered Period)</div>
            <div class="metric-value">TSH <?= number_format($invoiceSummary['total_amount'] ?: 0, 0) ?></div>
            <div class="metric-count text-secondary small"><?= number_format($invoiceSummary['total_count'] ?: 0) ?> Finalized Invoices</div>
        </div>
        <div class="metric-card quote">
            <div class="metric-label">Open Pipeline Value</div>
            <div class="metric-value">TSH <?= number_format($quoteSummary['total_amount'] ?: 0, 0) ?></div>
            <div class="metric-count text-secondary small"><?= number_format($quoteSummary['total_count'] ?: 0) ?> Quotations/Orders</div>
        </div>
    </div>

    <!-- Monthly Table -->
    <div class="detail-card">
        <div class="detail-header">
            <h3><i class="fas fa-chart-line me-2 text-primary"></i> Business History by Month</h3>
        </div>
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align: center;">Invoices</th>
                    <th style="text-align: right;">Amount</th>
                    <th style="text-align: center;">Quotes</th>
                    <th style="text-align: right;">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyBreakdown as $m): ?>
                <tr>
                    <td style="font-weight: 600;"><?= date('F Y', strtotime($m['month'] . '-01')) ?></td>
                    <td style="text-align: center;"><span class="badge badge-success"><?= $m['inv_count'] ?></span></td>
                    <td style="text-align: right; font-weight: 600;">TSH <?= number_format($m['inv_amount'], 0) ?></td>
                    <td style="text-align: center;"><span class="badge badge-warning"><?= $m['quote_count'] ?></span></td>
                    <td style="text-align: right;">TSH <?= number_format($m['quote_amount'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        <!-- Invoices -->
        <div class="detail-card">
            <h3><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> Recent Invoices</h3>
            <table class="table-custom">
                <thead><tr><th>No.</th><th>Date</th><th style="text-align: right;">Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($recentInvoices as $inv): ?>
                    <tr>
                        <td><a href="../modules/sales/invoices/view.php?id=<?= $inv['id'] ?>" class="doc-link"><?= $inv['invoice_number'] ?></a></td>
                        <td><?= date('M d, Y', strtotime($inv['created_at'])) ?></td>
                        <td style="text-align: right; font-weight: 600;">TSH <?= number_format($inv['total_amount'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Quotes -->
        <div class="detail-card">
            <h3><i class="fas fa-comment-dots me-2 text-accent"></i> Recent Quotations</h3>
            <table class="table-custom">
                <thead><tr><th>Ref.</th><th>Status</th><th style="text-align: right;">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($recentQuotes as $q): ?>
                    <tr>
                        <td><a href="../modules/sales/orders/view.php?id=<?= $q['id'] ?>" class="doc-link"><?= $q['order_number'] ?></a></td>
                        <td>
                            <?php
                                $statusClass = 'badge-secondary';
                                if ($q['status'] === 'quotation') $statusClass = 'badge-primary';
                                if ($q['status'] === 'confirmed') $statusClass = 'badge-info';
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= ucfirst($q['status']) ?></span>
                        </td>
                        <td style="text-align: right; font-weight: 600;">TSH <?= number_format($q['total_amount'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
