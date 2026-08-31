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
            --primary: #2C3E50;
            --accent: #F4B400;
            --bg: #F1F5F9;
            --card-bg: #FFFFFF;
            --text: #1E293B;
            --border: #E2E8F0;
            --success: #10B981;
            --danger: #EF4444;
        }

        body.dashboard { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; color: var(--text); }
        * { box-sizing: border-box; }

        /* LAYOUT */
        .main-container {
            max-width: 1200px; margin: 0 auto; padding: 20px;
        }

        .header-dash { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .header-dash h1 { margin: 0; font-size: 24px; color: var(--primary); }

        /* STATS CARDS */
        .dashboard-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: var(--card-bg); padding: 20px; border-radius: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; transition: transform 0.2s; border:none;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-info h3 { margin: 0 0 5px 0; color: #64748B; font-size: 13px; text-transform: uppercase; font-family:'Inter'; letter-spacing:0; }
        .stat-info .value { font-size: 24px; font-weight: 700; color: var(--primary); font-family:'Inter'; }
        
        .icon-box { width: 45px; height: 45px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .blue { background: #E0F2FE; color: #0284C7; }
        .green { background: #DCFCE7; color: #16A34A; }
        .orange { background: #FFF7ED; color: #EA580C; }

        /* QUICK ACTIONS */
        .quick-actions { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px; }
        .btn-action {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: var(--card-bg); border-radius: 12px; padding: 20px; text-decoration: none;
            color: var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.05); transition: 0.2s; border: 1px solid transparent;
        }
        .btn-action:hover { border-color: var(--accent); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .btn-action svg { width: 24px; height: 24px; color: var(--accent); margin-bottom: 10px; }
        .btn-action span { font-weight: 600; font-size: 14px; }

        /* RECENT ACTIVITY */
        .alert-section { background: var(--card-bg); border-radius: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; }
        .alert-section h3 { padding: 15px 20px; border-bottom: 1px solid var(--border); margin: 0; font-size: 15px; background: #F8FAFC; color: var(--primary); }
        
        .alert-table { width: 100%; border-collapse: collapse; }
        .alert-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748B; padding: 12px 20px; background: #F8FAFC; border-bottom: 1px solid var(--border); font-family: 'Inter'; font-weight: 600; }
        .alert-table td { padding: 12px 20px; font-size: 13px; border-bottom: 1px solid #F1F5F9; color: var(--text); font-family: 'Inter'; }
        .alert-table tr:last-child td { border: none; }

        @media (max-width: 768px) {
            .dashboard-grid, .quick-actions { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <div class="main-container">
        <div class="header-dash">
            <div>
                <h1>Stock Management</h1>
                <span style="font-size:13px; color:#64748B;">Overview of your inventory and orders</span>
            </div>
            <a href="../select-module.php" style="background:#fff; border:1px solid #ddd; padding:8px 15px; border-radius:6px; cursor:pointer; text-decoration:none; color:#1E293B; font-size:13px; font-weight:600;">&larr; Back to Modules</a>
        </div>

        <!-- 1. Stats -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Total Items</h3>
                    <div class="value">
                    <?php 
                    $curr = $pdo->query("SELECT COUNT(*) FROM stocks_items")->fetchColumn();
                    echo number_format($curr);
                    ?>
                    </div>
                </div>
                <div class="icon-box blue"><i class="fas fa-cubes"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Low Stock Alerts</h3>
                    <div class="value" style="color:#EF4444;">
                    <?php 
                    $lowMsg = $pdo->query("SELECT COUNT(*) FROM stocks_items WHERE stock_quantity <= reorder_point")->fetchColumn();
                    echo number_format($lowMsg);
                    ?>
                    </div>
                </div>
                <div class="icon-box orange"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <h3>Open POs</h3>
                    <div class="value" style="color:#2563eb;">
                    <?php 
                    $pos = $pdo->query("SELECT COUNT(*) FROM stocks_purchase_orders WHERE status NOT IN ('received', 'cancelled')")->fetchColumn();
                    echo number_format($pos);
                    ?>
                    </div>
                </div>
                <div class="icon-box green"><i class="fas fa-file-invoice-dollar"></i></div>
            </div>
        </div>

        <div class="quick-actions">
            <!-- Navigation Links -->
            <a href="items.php" class="btn-action">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <span>Items Master</span>
            </a>
            <a href="procurement.php" class="btn-action">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Purchase Order</span>
            </a>
            <a href="receive.php" class="btn-action">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                <span>Receive Stock (GRN)</span>
            </a>
            <a href="suppliers.php" class="btn-action">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Suppliers</span>
            </a>
        </div>

        <div class="alert-section">
            <h3>Recent Activity</h3>
            <div class="table-wrap">
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
        </div>

    </div>
</body>
</html>
