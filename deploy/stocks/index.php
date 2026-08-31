<?php
require_once '../includes/functions.php';
requireLogin();

// Initialize Database Schema on first load
ensureStocksSchema();

$pageTitle = "Stock Management";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?> - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <!-- Import Requested Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Roboto:wght@400;500;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-primary: 'Inter', sans-serif;
            --font-heading: 'Roboto', sans-serif;
            --font-data: 'Source Sans 3', sans-serif;
            --primary-color: #2563eb;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        body {
            font-family: var(--font-primary);
            background-color: var(--bg-color);
            color: var(--text-main);
        }
        h1, h2, h3, h4 {
            font-family: var(--font-heading);
            letter-spacing: -0.02em;
        }
        .main-content {
            padding: 20px;
        }
        
        /* Stats Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin: 16px 0 24px 0;
        }
        .stat-card {
            background: var(--card-bg);
            padding: 16px;
            border-radius: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid rgba(229, 231, 235, 0.5);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .stat-card h3 {
            margin: 0 0 8px 0;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            font-family: var(--font-heading);
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid #e5e7eb;
            padding: 8px 14px;
            border-radius: 0;
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            font-size: 13px;
            font-family: var(--font-primary);
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn-action:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: #eff6ff;
            transform: translateY(-1px);
        }
        .btn-action svg {
            color: #9ca3af;
            transition: color 0.2s;
            width: 16px;
            height: 16px;
        }
        .btn-action:hover svg {
            color: var(--primary-color);
        }

        /* Tables & Lists */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .alert-section {
            background: #fff;
            border-radius: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid rgba(229, 231, 235, 0.5);
            overflow: hidden;
        }
        .alert-section h3 {
            padding: 12px 16px;
            margin: 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            color: #111827;
        }
        .alert-table {
            width: 100%;
            border-collapse: collapse;
        }
        .alert-table th {
            background: #f9fafb;
            padding: 8px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--font-primary);
        }
        .alert-table td {
            padding: 10px 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
            color: #374151;
            font-family: var(--font-data);
        }
        .alert-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Buttons */
        .btn {
            background-color: var(--primary-color);
            font-family: var(--font-primary);
            font-weight: 500;
            border-radius: 0;
            padding: 6px 12px;
            font-size: 13px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .btn:hover {
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <div class="header-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
            <div>
                <h1 style="font-size: 20px; color: #111827; margin:0;">Stock Management</h1>
                <p style="color: #6b7280; margin: 2px 0 0 0; font-size: 13px;">Overview of your inventory and orders</p>
            </div>
            <a href="../select-module.php" class="btn" style="background:#fff; color:#374151; border:1px solid #d1d5db;">&larr; Back to Modules</a>
        </div>

        <div class="quick-actions">
            <!-- Navigation Links -->
            <a href="items.php" class="btn-action">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <span>Items</span>
            </a>
            <a href="procurement.php" class="btn-action">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Purchase Order</span>
            </a>
            <a href="suppliers.php" class="btn-action">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Suppliers</span>
            </a>
        </div>

        <div class="dashboard-grid">
            <div class="stat-card">
                <h3>Total Items</h3>
                <div class="value">
                    <?php 
                    $curr = $pdo->query("SELECT COUNT(*) FROM stocks_items")->fetchColumn();
                    echo number_format($curr);
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Low Stock Alerts</h3>
                <div class="value" style="color:#ef4444;">
                    <?php 
                    // MVP placeholder
                    echo "0"; 
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Open Purchase Orders</h3>
                <div class="value" style="color:#2563eb;">
                    <?php 
                    $pos = $pdo->query("SELECT COUNT(*) FROM stocks_purchase_orders WHERE status NOT IN ('received', 'cancelled')")->fetchColumn();
                    echo number_format($pos);
                    ?>
                </div>
            </div>
        </div>

        <div class="alert-section">
            <h3>Recent Activity</h3>
            <table class="alert-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5" style="text-align:center; color:#9ca3af; padding:30px;">No recent transactions recorded</td>
                    </tr>
                </tbody>
            </table>
        </div>

    </main>
</body>
</html>
