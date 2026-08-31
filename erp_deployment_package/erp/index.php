<?php
require_once '../includes/functions.php';
requireLogin();

global $pdo;

// Get statistics
$stats = [
    'customers' => $pdo->query("SELECT COUNT(*) FROM erp_customers")->fetchColumn(),
    'products' => $pdo->query("SELECT COUNT(*) FROM erp_products")->fetchColumn(),
    'invoices' => $pdo->query("SELECT COUNT(*) FROM erp_invoices WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE)")->fetchColumn(),
    'revenue' => $pdo->query("SELECT COALESCE(SUM(total), 0) FROM erp_invoices WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE)")->fetchColumn(),
    'suppliers' => $pdo->query("SELECT COUNT(*) FROM erp_suppliers")->fetchColumn(),
    'pos' => $pdo->query("SELECT COUNT(*) FROM erp_purchase_orders WHERE MONTH(order_date) = MONTH(CURRENT_DATE)")->fetchColumn(),
    'employees' => $pdo->query("SELECT COUNT(*) FROM erp_employees WHERE status = 'active'")->fetchColumn(),
];

// Recent invoices
$recentInvoices = $pdo->query("SELECT i.*, c.name as customer_name FROM erp_invoices i JOIN erp_customers c ON i.customer_id = c.id ORDER BY i.invoice_date DESC LIMIT 5")->fetchAll();

// Recent POs
$recentPOs = $pdo->query("SELECT po.*, s.name as supplier_name FROM erp_purchase_orders po JOIN erp_suppliers s ON po.supplier_id = s.id ORDER BY po.order_date DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; display: flex; min-height: 100vh; }
        
        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background: #1a1c20; /* Dark sidebar */
            color: #e8eaed;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #3c4043;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-nav {
            padding: 10px 0;
            flex: 1;
        }
        .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 24px;
            color: #9aa0a6;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 0.95rem;
        }
        .nav-item:hover, .nav-item.active {
            background: #303134;
            color: white;
        }
        .nav-icon {
            margin-right: 12px;
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }
        .nav-group-title {
            padding: 16px 24px 8px;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #5f6368;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 250px; /* Match sidebar width */
            padding: 24px;
            width: calc(100% - 250px);
        }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-radius: 8px; margin-bottom: 24px; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .stat-label { color: #5f6368; font-size: 0.875rem; margin-bottom: 8px; }
        .stat-value { font-size: 2rem; font-weight: 600; color: #202124; }
        
        .section { background: white; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 24px; overflow: hidden; }
        .section-header { padding: 16px 20px; border-bottom: 1px solid #e0e0e0; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; background: #f8f9fa; }
        .table td { padding: 12px 16px; border-top: 1px solid #f1f3f4; }
        .table tr:hover { background: #f8f9fa; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-info { background: #e8f0fe; color: #1967d2; }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <span>ðŸš€ ERP System</span>
        </div>
        <div class="sidebar-nav">
            <a href="index.php" class="nav-item active">
                <span class="nav-icon">ðŸ“Š</span> Dashboard
            </a>
            
            <div class="nav-group-title">CRM & Sales</div>
            <a href="crm/leads.php" class="nav-item">
                <span class="nav-icon">ðŸŽ¯</span> Leads
            </a>
            <a href="crm/opportunities.php" class="nav-item">
                <span class="nav-icon">ðŸ’¼</span> Pipeline
            </a>
            <a href="sales/invoices.php" class="nav-item">
                <span class="nav-icon">ðŸ“„</span> Invoices
            </a>
            <a href="sales/quotes.php" class="nav-item">
                <span class="nav-icon">ðŸ“‹</span> Quotes
            </a>
            
            <div class="nav-group-title">Operations</div>
            <a href="inventory/batches.php" class="nav-item">
                <span class="nav-icon">ðŸ“¦</span> Inventory
            </a>
            <a href="sales/delivery-notes.php" class="nav-item">
                <span class="nav-icon">ðŸšš</span> Deliveries
            </a>
            <a href="purchasing/purchase-orders.php" class="nav-item">
                <span class="nav-icon">ðŸ›’</span> Purchasing
            </a>
            
            <div class="nav-group-title">Finance & HR</div>
            <a href="accounting/profit-loss.php" class="nav-item">
                <span class="nav-icon">ðŸ’°</span> Accounting
            </a>
            <a href="accounting/journal-entries.php" class="nav-item">
                <span class="nav-icon">ðŸ“’</span> Journals
            </a>
            <a href="accounting/ledger.php" class="nav-item">
                <span class="nav-icon">ðŸ“š</span> General Ledger
            </a>
            <a href="accounting/chart-of-accounts.php" class="nav-item">
                <span class="nav-icon">ðŸ—‚ï¸</span> Chart of Accounts
            </a>
            <a href="banking/bank-accounts.php" class="nav-item">
                <span class="nav-icon">ðŸ¦</span> Bank Reconciliation
            </a>
            <a href="hr/employees.php" class="nav-item">
                <span class="nav-icon">ðŸ‘¥</span> HR & Payroll
            </a>
            
            <div class="nav-group-title">Analysis</div>
            <a href="reports/index.php" class="nav-item">
                <span class="nav-icon">ðŸ“ˆ</span> Reports
            </a>
            <a href="settings/index.php" class="nav-item">
                <span class="nav-icon">âš™ï¸</span> Settings
            </a>
        </div>
        <div style="padding: 20px; border-top: 1px solid #3c4043;">
            <a href="../employee/dashboard.php" class="nav-item" style="color: #e8eaed;">
                <span class="nav-icon">â¬…ï¸</span> Back to Portal
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1>Dashboard Overview</h1>
            <div>
                <a href="sales/create-invoice.php" class="btn btn-primary">+ New Invoice</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Revenue (This Month)</div>
                <div class="stat-value" style="color: #137333;">TSh <?= number_format($stats['revenue']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Invoices</div>
                <div class="stat-value"><?= number_format($stats['invoices']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Customers</div>
                <div class="stat-value"><?= number_format($stats['customers']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Products</div>
                <div class="stat-value"><?= number_format($stats['products']) ?></div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="section">
                <div class="section-header">
                    <span>Recent Invoices</span>
                    <a href="sales/invoices.php" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View All</a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentInvoices)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 20px;">No recent invoices</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentInvoices as $inv): ?>
                                <tr>
                                    <td><a href="sales/view-invoice.php?id=<?= $inv['id'] ?>" style="color: #1a73e8; text-decoration: none;"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                                    <td><?= htmlspecialchars($inv['customer_name']) ?></td>
                                    <td><?= number_format($inv['total']) ?></td>
                                    <td><span class="badge badge-<?= $inv['status'] == 'paid' ? 'success' : 'warning' ?>"><?= ucfirst($inv['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <span>Recent Purchase Orders</span>
                    <a href="purchasing/purchase-orders.php" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">View All</a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Supplier</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPOs)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 20px;">No recent POs</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentPOs as $po): ?>
                                <tr>
                                    <td><a href="purchasing/view-po.php?id=<?= $po['id'] ?>" style="color: #1a73e8; text-decoration: none;"><?= htmlspecialchars($po['po_number']) ?></a></td>
                                    <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                    <td><?= number_format($po['total_amount']) ?></td>
                                    <td><span class="badge badge-<?= $po['status'] == 'received' ? 'success' : 'warning' ?>"><?= ucfirst($po['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

