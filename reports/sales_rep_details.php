<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

$userId = $_GET['id'] ?? null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-3 months'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (!$userId) {
    die("User ID required");
}

// 1. Fetch User Info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die("User not found");
}

// 2. Fetch Invoice Summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(total_amount) as total_amount
    FROM invoices 
    WHERE created_by = ? AND status != 'cancelled' AND created_at BETWEEN ? AND ?
");
$stmt->execute([$userId, $startDate, $endDate]);
$invoiceSummary = $stmt->fetch();

// 3. Fetch Quotation Summary (All active non-invoiced orders)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(total_amount) as total_amount
    FROM sales_orders 
    WHERE created_by = ? AND status NOT IN ('cancelled', 'invoiced', 'paid') AND created_at BETWEEN ? AND ?
");
$stmt->execute([$userId, $startDate, $endDate]);
$quoteSummary = $stmt->fetch();

// 4. Fetch Recent Invoices
$stmt = $pdo->prepare("
    SELECT i.*, c.company_name 
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.created_by = ? AND i.status != 'cancelled' 
    ORDER BY i.created_at DESC LIMIT 10
");
$stmt->execute([$userId]);
$recentInvoices = $stmt->fetchAll();

// 5. Fetch Recent Quotations (Inclusive of all statuses to show pipeline)
$stmt = $pdo->prepare("
    SELECT so.*, c.company_name 
    FROM sales_orders so 
    JOIN customers c ON so.customer_id = c.id 
    WHERE so.created_by = ? 
    ORDER BY so.created_at DESC LIMIT 10
");
$stmt->execute([$userId]);
$recentQuotes = $stmt->fetchAll();

// 6. Monthly Breakdown (Invoices & Quotes combined)
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as inv_count,
        SUM(total_amount) as inv_amount
    FROM invoices 
    WHERE created_by = ? AND status != 'cancelled' AND created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$userId, $startDate, $endDate]);
$invMonthly = $stmt->fetchAll(PDO::FETCH_UNIQUE);

$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as quote_count,
        SUM(total_amount) as quote_amount
    FROM sales_orders 
    WHERE created_by = ? AND status != 'cancelled' AND created_at BETWEEN ? AND ?
    GROUP BY month
");
$stmt->execute([$userId, $startDate, $endDate]);
$quoteMonthly = $stmt->fetchAll(PDO::FETCH_UNIQUE);

// Combine months
$allMonths = array_unique(array_merge(array_keys($invMonthly), array_keys($quoteMonthly)));
rsort($allMonths); // Newest month first

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Rep Performance | <?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #3b82f6;
            --secondary: #10b981;
            --accent: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Inter', -apple-system, sans-serif;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-back:hover {
            background: var(--bg-primary);
            transform: translateX(-4px);
        }

        .rep-profile {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 40px;
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .rep-avatar {
            width: 80px;
            height: 80px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
        }

        .rep-info h1 { margin: 0; font-size: 1.5rem; }
        .rep-info p { margin: 4px 0 0; color: var(--text-secondary); }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 40px;
        }

        .metric-card {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: 16px;
            border-left: 6px solid var(--primary);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .metric-card.quote { border-left-color: var(--accent); }

        .metric-label { font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 8px; }
        .metric-value { font-size: 1.75rem; font-weight: 700; }
        .metric-count { font-size: 0.9rem; color: var(--text-secondary); margin-top: 4px; }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
        }

        .detail-card {
            background: var(--bg-secondary);
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
        }

        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }

        .table-custom th { text-align: left; font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; padding: 12px 8px; }
        .table-custom td { padding: 12px 8px; border-bottom: 1px solid var(--border); font-size: 0.875rem; }

        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #e0f2fe; color: #075985; }

        .doc-link {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .doc-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div style="display: flex; align-items: center; gap: 20px;">
            <a href="sales.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i> Back</a>
            <h2 style="margin: 0;">Rep Performance Details</h2>
        </div>
        
        <form class="filter-form" method="GET">
            <input type="hidden" name="id" value="<?= $userId ?>">
            <div style="display: flex; gap: 8px; align-items: center; background: white; padding: 6px; border-radius: 8px; border: 1px solid var(--border);">
                <input type="date" name="start_date" value="<?= $startDate ?>">
                <span style="color: var(--text-secondary);">to</span>
                <input type="date" name="end_date" value="<?= $endDate ?>">
                <button type="submit" style="background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer;">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </form>
    </div>

    <div class="rep-profile">
        <div class="rep-avatar">
            <?= strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)) ?>
        </div>
        <div class="rep-info">
            <h1><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></h1>
            <p><?= htmlspecialchars($user['role'] ?: 'Sales Representative') ?> | <?= htmlspecialchars($user['email']) ?></p>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Total Finalized Invoices</div>
            <div class="metric-value">TSH <?= number_format($invoiceSummary['total_amount'] ?: 0, 0) ?></div>
            <div class="metric-count"><?= number_format($invoiceSummary['total_count'] ?: 0) ?> Records in period</div>
        </div>
        <div class="metric-card quote">
            <div class="metric-label">Total Pipeline (Quotations & Orders)</div>
            <div class="metric-value">TSH <?= number_format($quoteSummary['total_amount'] ?: 0, 0) ?></div>
            <div class="metric-count"><?= number_format($quoteSummary['total_count'] ?: 0) ?> Active Proposals/Orders</div>
        </div>
    </div>

    <!-- Monthly Breakdown Table -->
    <div class="detail-card" style="margin-bottom: 40px;">
        <div class="detail-header">
            <h3><i class="fas fa-calendar-alt me-2 text-primary"></i> Monthly Performance Summary</h3>
            <span class="text-secondary small">Month-over-Month tracking</span>
        </div>
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align: center;">Invoices</th>
                    <th style="text-align: right;">Invoice Amount</th>
                    <th style="text-align: center;">Quotations</th>
                    <th style="text-align: right;">Quotation Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyBreakdown as $m): ?>
                <tr>
                    <td style="font-weight: 600;"><?= date('F Y', strtotime($m['month'] . '-01')) ?></td>
                    <td style="text-align: center;"><span class="badge badge-success"><?= $m['inv_count'] ?></span></td>
                    <td style="text-align: right; font-weight: 600;">TSH <?= number_format($m['inv_amount'], 0) ?></td>
                    <td style="text-align: center;"><span class="badge badge-warning"><?= $m['quote_count'] ?></span></td>
                    <td style="text-align: right; font-weight: 600; color: var(--text-secondary);">TSH <?= number_format($m['quote_amount'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($monthlyBreakdown)): ?>
                <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-secondary);">No monthly data for this period</td></tr>
                <?php else: ?>
                <tr style="background: #f8fafc; font-weight: 700;">
                    <td>TOTAL</td>
                    <td style="text-align: center;"><?= number_format(array_sum(array_column($monthlyBreakdown, 'inv_count'))) ?></td>
                    <td style="text-align: right;">TSH <?= number_format(array_sum(array_column($monthlyBreakdown, 'inv_amount')), 0) ?></td>
                    <td style="text-align: center;"><?= number_format(array_sum(array_column($monthlyBreakdown, 'quote_count'))) ?></td>
                    <td style="text-align: right;">TSH <?= number_format(array_sum(array_column($monthlyBreakdown, 'quote_amount')), 0) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="details-grid">
        <!-- Invoices List -->
        <div class="detail-card">
            <div class="detail-header">
                <h3><i class="fas fa-file-invoice-dollar me-2 text-primary"></i> Recent Invoices</h3>
            </div>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Number</th>
                        <th>Customer</th>
                        <th style="text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentInvoices as $inv): ?>
                    <tr>
                        <td><?= date('M d', strtotime($inv['created_at'])) ?></td>
                        <td class="fw-bold">
                            <a href="../modules/sales/invoices/view.php?id=<?= $inv['id'] ?>" class="doc-link">
                                <?= $inv['invoice_number'] ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($inv['company_name']) ?></td>
                        <td style="text-align: right; font-weight: 600;">TSH <?= number_format($inv['total_amount'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentInvoices)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 20px;">No invoices found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Quotations List -->
        <div class="detail-card">
            <div class="detail-header">
                <h3><i class="fas fa-comment-dots me-2 text-accent"></i> Recent Quotations</h3>
            </div>
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th style="text-align: right;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentQuotes as $quote): ?>
                    <tr>
                        <td><?= date('M d', strtotime($quote['created_at'])) ?></td>
                        <td class="fw-bold">
                            <a href="../modules/sales/orders/view.php?id=<?= $quote['id'] ?>" class="doc-link">
                                <?= $quote['order_number'] ?>
                            </a>
                        </td>
                        <td>
                            <?php
                                $statusClass = 'badge-secondary';
                                if ($quote['status'] === 'quotation') $statusClass = 'badge-primary';
                                if ($quote['status'] === 'confirmed') $statusClass = 'badge-info';
                                if ($quote['status'] === 'invoiced' || $quote['status'] === 'paid') $statusClass = 'badge-success';
                                if ($quote['status'] === 'cancelled') $statusClass = 'badge-danger';
                                if ($quote['status'] === 'on_hold') $statusClass = 'badge-warning';
                            ?>
                            <span class="badge <?= $statusClass ?>"><?= ucfirst($quote['status']) ?></span>
                        </td>
                        <td style="text-align: right; font-weight: 600;">TSH <?= number_format($quote['total_amount'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentQuotes)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 20px;">No quotations found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
