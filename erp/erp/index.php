<?php
require_once '../includes/functions.php';

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


// Notifications (only if file exists)
if (file_exists('includes/notifications.php')) {
    try {
        require_once 'includes/notifications.php';
        $unreadCount = get_unread_count($_SESSION['user_id'] ?? 0);
        $notifications = get_unread_notifications($_SESSION['user_id'] ?? 0);
    } catch (Exception $e) {
        // Fail silently if notifications table is missing or other error
        $unreadCount = 0;
        $notifications = [];
    }
} else {
    $unreadCount = 0;
    $notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ERP Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; min-height: 100vh; }
        
        /* Main Content Styles */
        .main-content {
            margin-left: 220px;
            padding: 24px;
            min-height: 100vh;
        }
        
        .header { 
            background: white;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .header h1 { font-size: 2rem; font-weight: 600; margin-bottom: 8px; color: #202124; }
        .header p { color: #5f6368; }
        
        .stats-section { margin-bottom: 40px; }
        .stats-title { font-size: 0.75rem; text-transform: uppercase; color: #5f6368; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 16px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .stat-card { background: white; padding: 24px; border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s, box-shadow 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .stat-label { color: #5f6368; font-size: 0.875rem; margin-bottom: 8px; }
        .stat-value { font-size: 2rem; font-weight: 600; color: #202124; }
        
        .section { background: white; border: none; margin-bottom: 24px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .section-header { padding: 20px 24px; border-bottom: 1px solid #f1f3f4; font-weight: 600; font-size: 1rem; display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; background: #f8f9fa; }
        .table td { padding: 12px 16px; border-top: 1px solid #f1f3f4; }
        .table tr:hover { background: #f8f9fa; }
        
        .btn { padding: 8px 16px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        .badge { display: inline-block; padding: 4px 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-info { background: #e8f0fe; color: #1967d2; }
        
        /* Override any conflicting styles */
        .main-content .header {
            margin-left: 0 !important;
        }
        
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 16px; }
            .header { padding: 24px; }
            .header h1 { font-size: 1.5rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .stat-value { font-size: 1.75rem; }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

    <style>
        /* Top Bar Styles */
        .top-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding: 16px 32px;
            background: white;
            border-bottom: 1px solid #241f1fff;
            margin: -24px -24px 32px -24px; /* Counteract main-content padding */
        }
        .top-bar-item {
            margin-left: 24px;
            color: #5f6368;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: color 0.2s;
        }
        .top-bar-item:hover {
            color: #202124;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .user-profile:hover {
            background: #f1f3f4;
        }
        .user-avatar {
            width: 32px;
            height: 32px;
            background: #1a73e8;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .notification-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #d93025;
            color: white;
            font-size: 0.65rem;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            border: 2px solid white;
            font-weight: 600;
        }
        /* Notification Dropdown */
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            width: 360px;
            max-height: 400px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        .notification-dropdown.show { display: block; }
        .notification-header {
            padding: 16px;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            font-size: 0.9rem;
            color: #202124;
        }
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f3f4;
            cursor: pointer;
            transition: background 0.2s;
        }
        .notification-item:hover { background: #f8f9fa; }
        .notification-item:last-child { border-bottom: none; }
        .notification-title {
            font-weight: 500;
            font-size: 0.875rem;
            color: #202124;
            margin-bottom: 4px;
        }
        .notification-message {
            font-size: 0.8rem;
            color: #5f6368;
            margin-bottom: 4px;
        }
        .notification-time {
            font-size: 0.75rem;
            color: #9aa0a6;
        }
        .notification-empty {
            padding: 32px 16px;
            text-align: center;
            color: #5f6368;
            font-size: 0.875rem;
        }
    </style>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-bar">
            <a href="#" class="top-bar-item" title="Help Center">
                <svg xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 0 24 24" width="24" fill="#5f6368"><path d="M0 0h24v24H0z" fill="none"/><path d="M11 18h2v-2h-2v2zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4z"/></svg>
            </a>
            <div class="top-bar-item" style="position: relative;" id="notificationContainer">
                <a href="#" id="notificationBell" title="Notifications">
                    <svg xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 0 24 24" width="24" fill="#000000"><path d="M0 0h24v24H0z" fill="none"/><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></svg>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notification-badge"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">Notifications</div>
                    <?php if (empty($notifications)): ?>
                        <div class="notification-empty">No new notifications</div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notification-item" data-id="<?= $notif['id'] ?>" onclick="markAsRead(<?= $notif['id'] ?>, '<?= htmlspecialchars($notif['link'] ?? '#', ENT_QUOTES) ?>')">
                                <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                <?php if (!empty($notif['message'])): ?>
                                    <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                                <?php endif; ?>
                                <div class="notification-time"><?= date('M d, Y H:i', strtotime($notif['created_at'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="top-bar-item user-profile">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?>
                </div>
                <div style="display: flex; flex-direction: column; align-items: flex-start;">
                    <span style="font-weight: 500; font-size: 0.9rem; color: #202124;"><?= htmlspecialchars($_SESSION['name'] ?? 'User') ?></span>
                    <span style="font-size: 0.75rem; color: #5f6368;">Admin</span>
                </div>
                <i class="fas fa-chevron-down" style="font-size: 0.75rem; margin-left: 8px; color: #5f6368;"></i>
            </div>
        </div>

        
        <!-- Sales Metrics -->
        <div class="stats-section">
            <div class="stats-title">Sales Performance</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="stat-label">Revenue (This Month)</div>
                            <div class="stat-value">TSh <?= number_format($stats['revenue']) ?></div>
                        </div>
                        <div style="background: #f1f3f4; padding: 14px;"><i class="fas fa-dollar-sign" style="color: #5f6368; font-size: 1.5rem;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="stat-label">Invoices (This Month)</div>
                            <div class="stat-value"><?= number_format($stats['invoices']) ?></div>
                        </div>
                        <div style="background: #f1f3f4; padding: 14px;"><i class="fas fa-file-invoice" style="color: #5f6368; font-size: 1.5rem;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="stat-label">Active Customers</div>
                            <div class="stat-value"><?= number_format($stats['customers']) ?></div>
                        </div>
                        <div style="background: #f1f3f4; padding: 14px;"><i class="fas fa-users" style="color: #5f6368; font-size: 1.5rem;"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Operations Metrics -->
        <div class="stats-section">
            <div class="stats-title">Operations</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="stat-label">Products in Catalog</div>
                            <div class="stat-value"><?= number_format($stats['products']) ?></div>
                        </div>
                        <div style="background: #f1f3f4; padding: 14px;"><i class="fas fa-box" style="color: #5f6368; font-size: 1.5rem;"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                        <div style="background: #f1f3f4; padding: 14px;"><i class="fas fa-truck-loading" style="color: #5f6368; font-size: 1.5rem;"></i></div>
                    </div>
                    <div style="margin-top: 16px; border-top: 1px solid #f1f3f4; padding-top: 16px;">
                        <a href="run_replenishment_test.php" target="_blank" style="color: #1a73e8; font-size: 0.85rem; text-decoration: none; font-weight: 500;">Run Auto-Replenishment &rarr;</a>
                    </div>
                </div>
                <div class="stat-card">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="stat-label">Purchase Orders (MTD)</div>
                            <div class="stat-value"><?= number_format($stats['pos']) ?></div>
                        </div>
                        <div style="background: #f1f3f4; padding: 14px;"><i class="fas fa-shopping-cart" style="color: #5f6368; font-size: 1.5rem;"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- HR Metrics -->
        <div class="stats-section">
            <div class="stats-title">Human Resources</div>
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 240px));">
                <div class="stat-card" style="padding: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <div class="stat-label" style="font-size: 0.75rem;">Active Employees</div>
                            <div class="stat-value" style="font-size: 1.5rem;"><?= number_format($stats['employees']) ?></div>
                        </div>
                        <div style="background: #f1f3f4; padding: 10px;"><i class="fas fa-user-tie" style="color: #5f6368; font-size: 1.25rem;"></i></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="stats-title" style="margin-top: 20px;">Recent Activity</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="section">
                <div class="section-header">
                    <span><i class="fas fa-file-invoice"></i> Recent Invoices</span>
                    <a href="sales/invoices.php" class="btn btn-secondary" style="padding: 6px 14px; font-size: 0.75rem;">View All â†’</a>
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
                            <tr><td colspan="4" style="text-align: center; padding: 32px; color: #5f6368;">No recent invoices</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentInvoices as $inv): ?>
                                <tr>
                                    <td><a href="sales/view-invoice.php?id=<?= $inv['id'] ?>" style="color: #1a73e8; text-decoration: none; font-weight: 500;"><?= htmlspecialchars($inv['invoice_number']) ?></a></td>
                                    <td><?= htmlspecialchars($inv['customer_name']) ?></td>
                                    <td style="font-weight: 500;">TSh <?= number_format($inv['total']) ?></td>
                                    <td><span class="badge badge-<?= $inv['status'] == 'paid' ? 'success' : 'warning' ?>"><?= ucfirst($inv['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <div class="section-header">
                    <span><i class="fas fa-shopping-cart"></i> Recent Purchase Orders</span>
                    <a href="purchasing/purchase-orders.php" class="btn btn-secondary" style="padding: 6px 14px; font-size: 0.75rem;">View All â†’</a>
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
                            <tr><td colspan="4" style="text-align: center; padding: 32px; color: #5f6368;">No recent purchase orders</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentPOs as $po): ?>
                                <tr>
                                    <td><a href="purchasing/view-po.php?id=<?= $po['id'] ?>" style="color: #1a73e8; text-decoration: none; font-weight: 500;"><?= htmlspecialchars($po['po_number']) ?></a></td>
                                    <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                    <td style="font-weight: 500;">TSh <?= number_format($po['total']) ?></td>
                                    <td><span class="badge badge-<?= $po['status'] == 'completed' ? 'success' : 'info' ?>"><?= ucfirst($po['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Notification dropdown toggle
        const notificationBell = document.getElementById('notificationBell');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationContainer = document.getElementById('notificationContainer');
        
        if (notificationBell && notificationDropdown) {
            notificationBell.addEventListener('click', function(e) {
                e.preventDefault();
                notificationDropdown.classList.toggle('show');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationContainer.contains(e.target)) {
                    notificationDropdown.classList.remove('show');
                }
            });
        }
        
        // Mark notification as read
        function markAsRead(notificationId, link) {
            fetch('includes/notifications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_read&id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove the notification item from the dropdown
                    const item = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
                    if (item) {
                        item.remove();
                    }
                    
                    // Update badge count
                    const badge = document.querySelector('.notification-badge');
                    if (badge) {
                        const currentCount = parseInt(badge.textContent);
                        if (currentCount > 1) {
                            badge.textContent = currentCount - 1;
                        } else {
                            badge.remove();
                        }
                    }
                    
                    // Check if dropdown is now empty
                    const remainingItems = document.querySelectorAll('.notification-item');
                    if (remainingItems.length === 0) {
                        notificationDropdown.innerHTML = '<div class="notification-header">Notifications</div><div class="notification-empty">No new notifications</div>';
                    }
                    
                    // Navigate to link if provided
                    if (link && link !== '#') {
                        window.location.href = link;
                    }
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
            });
        }
    </script>
</body>
</html>

