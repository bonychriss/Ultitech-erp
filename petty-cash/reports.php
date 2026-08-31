<?php
require_once '../../includes/functions.php';
requireFinanceOrAdmin(); // Only finance users and admins can access
ensurePettyCashSchema();

global $pdo;
$user_id = $_SESSION['user_id'] ?? 0;

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
$category = $_GET['category'] ?? '';

// Build filters
$filters = [
    'date_from' => $date_from,
    'date_to' => $date_to
];

if ($category) {
    $filters['category'] = $category;
}

// Get vouchers
$vouchers = getAllPettyCashVouchers($filters);

// Calculate statistics
$total_amount = array_sum(array_column($vouchers, 'amount'));
$approved_vouchers = array_filter($vouchers, fn($v) => $v['status'] === 'approved');
$approved_amount = array_sum(array_column($approved_vouchers, 'amount'));

// Group by category
$by_category = [];
foreach ($vouchers as $v) {
    if (!isset($by_category[$v['category']])) {
        $by_category[$v['category']] = ['count' => 0, 'amount' => 0];
    }
    $by_category[$v['category']]['count']++;
    $by_category[$v['category']]['amount'] += $v['amount'];
}

// Get categories for filter
$categories = getPettyCashCategories();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petty Cash Reports</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        /* Header styles removed, using shared header */
        
        .main-content {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        h2 {
            font-size: 1.5rem;
            margin-bottom: 24px;
            color: #111827;
        }
        
        .section {
            background: white;
            padding: 24px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .section-header {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #111827;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .filter-form label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #555;
            font-size: 13px;
        }
        .filter-form input,
        .filter-form select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .stat-label {
            color: #555;
            font-size: 12px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #111827;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 12px;
            color: #555;
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 11px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .btn {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #1a73e8;
            color: white;
        }
        .btn-secondary {
            background: #fff;
            color: #202124;
            border: 1px solid #dadce0;
        }
        
        .icon {
            width: 18px;
            height: 18px;
        }
        
        .chart-bar {
            height: 24px;
            background: #1a73e8;
            border-radius: 2px;
            transition: width 0.3s;
        }
        
        @media (max-width: 768px) {
            .header-content { padding: 6px 12px; }
            .company-logo-img { height: 36px; }
            .header-info h1 { font-size: 12px; }
            .main-content { padding: 16px; }
            .filter-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="dashboard">
    <?php 
    $logoBase = '../../';
    include '../../includes/header_employee.php'; 
    ?>
    
    <main class="main-content">
        <div class="page-header" style="margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 700; color: #111827; margin: 0;">Petty Cash Reports</h2>
            <p style="color: #6b7280; font-size: 13px; margin-top: 4px;">View expenses and generate reports</p>
        </div>
        
        <!-- Filters -->
        <div class="section">
            <div class="section-header">Filters</div>
            <form method="GET" class="filter-form">
                <div>
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div>
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div>
                    <label>Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="11" cy="11" r="8" fill="none" stroke="currentColor" stroke-width="2"/>
                            <path d="m21 21-4.35-4.35" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Vouchers</div>
                <div class="stat-value"><?= count($vouchers) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">TSh <?= number_format($total_amount, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Approved Vouchers</div>
                <div class="stat-value"><?= count($approved_vouchers) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Approved Amount</div>
                <div class="stat-value">TSh <?= number_format($approved_amount, 2) ?></div>
            </div>
        </div>
        
        <!-- Expense by Category -->
        <div class="section">
            <div class="section-header">Expense by Category</div>
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Vouchers</th>
                        <th>Amount</th>
                        <th style="width: 40%;">Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($by_category)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 32px; color: #666;">No data for selected period</td></tr>
                    <?php else: ?>
                        <?php 
                        $max_amount = max(array_column($by_category, 'amount'));
                        foreach ($by_category as $cat => $data): 
                            $percentage = $max_amount > 0 ? ($data['amount'] / $max_amount) * 100 : 0;
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($cat) ?></strong></td>
                                <td><?= $data['count'] ?></td>
                                <td style="font-weight: 500;">TSh <?= number_format($data['amount'], 2) ?></td>
                                <td>
                                    <div class="chart-bar" style="width: <?= $percentage ?>%;"></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>

