<?php
require_once 'includes/functions.php';

// Strict Admin Check
if (!isAdmin()) {
    die("Access Denied: System Administrators Only.");
}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // User Management Actions (Lock/Unlock)
    if (in_array($_POST['action'], ['lock_user', 'unlock_user']) && isset($_POST['target_id'])) {
        $targetId = intval($_POST['target_id']);
        // Prevent locking self or super admin
        if ($targetId != $_SESSION['user_id']) { 
            if ($_POST['action'] === 'lock_user') {
                $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND username != 'admin'");
                $stmt->execute([$targetId]);
            } elseif ($_POST['action'] === 'unlock_user') {
                $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                $stmt->execute([$targetId]);
            }
        }
        header("Location: system-status.php");
        exit();
    }
    
    // Suggestion Actions
    if ($_POST['action'] === 'update_suggestion' && isset($_POST['sug_id'])) {
        $sugId = intval($_POST['sug_id']);
        $newStatus = $_POST['status']; // pending, accomplished, impossible
        $stmt = $pdo->prepare("UPDATE developer_suggestions SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $sugId]);
        header("Location: system-status.php");
        exit();
    }

    // Toggle Notice Page
    if ($_POST['action'] === 'toggle_notice') {
        $newState = (int)$_POST['state']; // 1 or 0
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('notice_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$newState, $newState]);
        header("Location: system-status.php");
        exit();
    }

    // Reset Revenue Module
    if ($_POST['action'] === 'confirm_reset_revenue') {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("TRUNCATE TABLE revenue_collections");
            $pdo->exec("TRUNCATE TABLE revenue_entries");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            header("Location: system-status.php?msg=revenue_reset_success");
        } catch (Exception $e) {
            header("Location: system-status.php?error=reset_failed");
        }
        exit();
    }

    // Reset Stock Module
    if ($_POST['action'] === 'confirm_reset_stock') {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            // Define stock tables to truncate
            $tables = [
                'products',
                'stock',
                'suppliers',
                'categories',
                'purchases',
                'purchase_items', // if exists
                'shipments',
                'stock_transactions', // if exists
                'stock_movements' // if exists
            ];
            
            foreach ($tables as $table) {
                try {
                    $pdo->exec("TRUNCATE TABLE $table");
                } catch (Exception $ex) {
                    // Ignore if table doesn't exist
                }
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            header("Location: system-status.php?msg=stock_reset_success");
        } catch (Exception $e) {
            header("Location: system-status.php?error=reset_failed&detail=" . urlencode($e->getMessage()));
        }
        exit();
    }

    // Reset Sales Module (Added)
    if ($_POST['action'] === 'confirm_reset_sales') {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $tables = [
                'sales_commissions',
                'stock_reservations',
                'delivery_items',
                'delivery_notes',
                'payments',
                'invoices',
                'sales_order_items',
                'sales_orders',
                'customers'
            ];
            
            foreach ($tables as $table) {
                try {
                    $pdo->exec("TRUNCATE TABLE $table");
                } catch (Exception $ex) {}
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            header("Location: system-status.php?msg=sales_reset_success");
        } catch (Exception $e) {
            header("Location: system-status.php?error=reset_failed&detail=" . urlencode($e->getMessage()));
        }
        exit();
    }
}

// Fetch Notice Status
$noticeEnabled = 1; // Default
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'notice_enabled'");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false) {
        $noticeEnabled = (int)$val;
    }
} catch (Exception $e) {}

// Fetch System Stats
try {
    global $pdo;
    
    // User Stats
    // ... (existing code)

    // Fetch Suggestions
    $stmt = $pdo->query("SELECT s.*, u.full_name FROM developer_suggestions s JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC");
    $suggestions = $stmt->fetchAll();

} catch (PDOException $e) {
    // If suggestions table is missing, just use empty array
    $suggestions = [];
}

try {
    // User Stats
    $stmt = $pdo->query("SELECT COUNT(*) as total, 
                                SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active 
                         FROM users");
    $userStats = $stmt->fetch();

    // Vouchers Stats (assuming basic ERP capability)
    $stmt = $pdo->query("SELECT COUNT(*) as total, 
                                SUM(total_amount) as total_value 
                         FROM payment_vouchers WHERE status='approved'");
    $voucherStats = $stmt->fetch();
    
    // --- MODULE STATISTICS ---
    
    // Purchasing: Pending Orders
    $poPendingAmount = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM erp_purchase_orders WHERE status = 'pending'");
        $poPending = $stmt->fetch()['count'];
    } catch(Exception $e) { $poPending = 0; }

    // Meetings: Active Rooms
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM active_meetings WHERE status = 'active' AND last_activity > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $activeMeetings = $stmt->fetch()['count'];
    } catch(Exception $e) { $activeMeetings = 0; }
    
    // Petty Cash: Balance (Optional, if table exists)
    $pettyBalance = 0;
    try {
         // Check if table exists first or just try query
         $stmt = $pdo->query("SELECT balance FROM petty_cash_accounts LIMIT 1"); // Example
         // $pettyBalance = ...
    } catch(Exception $e) {}


    // --- ERROR LOG READER ---
    $logs = [];
    $logFile = ini_get('error_log');
    if (!$logFile || !file_exists($logFile)) {
        $logFile = __DIR__ . '/error_log'; // Fallback
    }
    
    if (file_exists($logFile) && is_readable($logFile)) {
        // Read last 20 lines
        $lines = file($logFile);
        $logs = array_slice($lines, -20);
        $logs = array_reverse($logs); // Newest first
    } else {
        $logs[] = "No readable error log found at: " . htmlspecialchars($logFile);
    }

    // Detailed User List
    $stmt = $pdo->query("SELECT id, full_name, username, role, department, created_at, is_active 
                         FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Status - <?= COMPANY_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-body: #f3f4f6; /* Light gray background */
            --card-bg: #ffffff; /* White cards */
            --text-main: #111827; /* Dark text */
            --text-muted: #6b7280;
            --accent: #3b82f6;
            --success: #10b981;
            --gold: #f59e0b;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 20px;
        }

        .header h1 { margin: 0; font-size: 24px; color: #111827; font-weight: 600; }
        .back-btn {
            color: var(--text-muted);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: color 0.2s;
        }
        .back-btn:hover { color: var(--accent); }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 0; /* Sharp corners */
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .stat-value { font-size: 28px; font-weight: 700; margin: 10px 0 5px; color: #111827; }
        .stat-label { font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .icon-box { font-size: 24px; color: var(--accent); margin-bottom: 10px; }

        /* Developer Card */
        .dev-card {
            background: #ffffff;
            border: 1px solid var(--gold);
            text-align: center;
            position: relative;
            overflow: hidden;
            border-radius: 0; /* Sharp corners */
        }
        .dev-card::before {
            content: 'PREMIUM';
            position: absolute;
            top: 12px;
            right: -30px;
            background: var(--gold);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 4px 30px;
            transform: rotate(45deg);
        }
        .dev-avatar {
            width: 80px;
            height: 80px;
            border-radius: 0; /* Sharp avatar */
            background: #f9fafb;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--gold);
            font-size: 32px;
            color: var(--gold);
        }
        .rating-stars { color: var(--gold); margin: 10px 0; }
        
        /* Table */
        .table-container {
            background: var(--card-bg);
            border-radius: 0; /* Sharp */
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f9fafb; padding: 15px 20px; font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; }
        td { padding: 15px 20px; border-bottom: 1px solid #e5e7eb; font-size: 14px; white-space: nowrap; color: #374151; }
        tr:last-child td { border-bottom: none; }
        
        .role-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 4px 8px;
            border-radius: 0; /* Sharp */
            font-size: 11px;
            font-weight: 600;
        }
        .role-badge.admin { background: #dbeafe; color: #1e40af; }

        .status-dot { height: 8px; width: 8px; border-radius: 0; /* Sharp dot */ display: inline-block; margin-right: 6px; }
        .green { background: var(--success); }
        .gray { background: var(--text-muted); }

        .btn-lock, .btn-unlock {
            border: none;
            padding: 6px 12px;
            border-radius: 0; /* Sharp */
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }
        .btn-lock { background: #fee2e2; color: #b91c1c; }
        .btn-lock:hover { background: #ef4444; color: white; }
        
        .btn-unlock { background: #d1fae5; color: #047857; }
        .btn-unlock:hover { background: #10b981; color: white; }

        /* Mobile Optimization */
        @media (max-width: 768px) {
            body { padding: 15px; }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            
            .header > div:last-child {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 15px;
            }

            .grid {
                grid-template-columns: repeat(2, 1fr); /* Two columns on mobile */
                gap: 10px; /* Tighter gap */
            }

            .card {
                padding: 12px; /* Reduced padding */
            }

            .table-container {
                overflow-x: auto; /* Horizontal scroll for tables */
                -webkit-overflow-scrolling: touch;
            }
            
            /* Make key stats text smaller/tighter on mobile */
            .stat-value { font-size: 18px; margin: 5px 0 2px; }
            .stat-label { font-size: 10px; line-height: 1.2; }
            .icon-box { font-size: 18px; margin-bottom: 5px; }
        }
    </style>
</head>
<body>

    <div class="header">
        <div>
            <h1>System Status Dashboard</h1>
            <div style="font-size:13px; color:#64748b; margin-top:5px;">Live monitoring for <?= COMPANY_NAME ?></div>
        </div>
        <div style="display:flex; align-items:center; gap:20px;">
            <!-- Notice Toggle -->
            <form method="POST" style="margin:0; display:flex; align-items:center;">
                <input type="hidden" name="action" value="toggle_notice">
                <input type="hidden" name="state" value="<?= $noticeEnabled ? '0' : '1' ?>">
                <button type="submit" 
                        style="border:none; padding:8px 16px; border-radius:0; cursor:pointer; font-weight:600; font-size:12px; display:flex; align-items:center; gap:8px; transition:all 0.2s;
                               <?= $noticeEnabled ? 'background:#ef4444; color:white;' : 'background:#22c55e; color:white;' ?>">
                    <?php if($noticeEnabled): ?>
                        <i class="fas fa-toggle-on" style="font-size:16px;"></i> Force Notice ON
                    <?php else: ?>
                        <i class="fas fa-toggle-off" style="font-size:16px;"></i> Force Notice OFF
                    <?php endif; ?>
                </button>
            </form>

            <!-- Reset Revenue Toggle -->
            <a href="?view=confirm_reset" 
               style="border:1px solid #ef4444; color:#ef4444; padding:8px 16px; font-weight:600; font-size:12px; text-decoration:none; transition:all 0.2s;"
               onmouseover="this.style.background='#ef4444'; this.style.color='white';"
               onmouseout="this.style.background='none'; this.style.color='#ef4444';">
                <i class="fas fa-trash-alt"></i> Reset Revenue
            </a>

            <!-- Reset Stock Toggle -->
            <a href="?view=confirm_reset_stock" 
               style="border:1px solid #f59e0b; color:#f59e0b; padding:8px 16px; font-weight:600; font-size:12px; text-decoration:none; transition:all 0.2s;"
               onmouseover="this.style.background='#f59e0b'; this.style.color='white';"
               onmouseout="this.style.background='none'; this.style.color='#f59e0b';">
                <i class="fas fa-boxes"></i> Reset Stock
            </a>

            <!-- Reset Sales Toggle -->
            <a href="?view=confirm_reset_sales" 
               style="border:1px solid #3b82f6; color:#3b82f6; padding:8px 16px; font-weight:600; font-size:12px; text-decoration:none; transition:all 0.2s;"
               onmouseover="this.style.background='#3b82f6'; this.style.color='white';"
               onmouseout="this.style.background='none'; this.style.color='#3b82f6';">
                <i class="fas fa-shopping-cart"></i> Reset Sales
            </a>

            <!-- Reset Sales Toggle -->
            <a href="?view=confirm_reset_sales" 
               style="border:1px solid #3b82f6; color:#3b82f6; padding:8px 16px; font-weight:600; font-size:12px; text-decoration:none; transition:all 0.2s;"
               onmouseover="this.style.background='#3b82f6'; this.style.color='white';"
               onmouseout="this.style.background='none'; this.style.color='#3b82f6';">
                <i class="fas fa-shopping-cart"></i> Reset Sales
            </a>

            <a href="select-module.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Hub
            </a>
        </div>
    </div>

    <?php if (isset($_GET['view']) && $_GET['view'] === 'confirm_reset'): ?>
        <!-- Reset Confirmation UI -->
        <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.9); z-index:1000; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
            <div style="background:white; border:1px solid #ef4444; padding:40px; text-align:center; max-width:400px; box-shadow:0 10px 30px rgba(0,0,0,0.1);">
                <i class="fas fa-exclamation-triangle" style="font-size:48px; color:#ef4444; margin-bottom:20px;"></i>
                <h2 style="margin:0 0 10px; color:#111827;">Reset Revenue Module?</h2>
                <p style="color:#6b7280; font-size:14px; line-height:1.6; margin-bottom:30px;">
                    This will permanently delete ALL transactions, collections, and approvals in the Revenue Module. <br><strong>This action cannot be undone.</strong>
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="confirm_reset_revenue">
                    <div style="display:flex; gap:10px;">
                        <a href="system-status.php" style="flex:1; padding:12px; background:#f3f4f6; color:#374151; font-weight:600; text-decoration:none;">Cancel</a>
                        <button type="submit" style="flex:1; padding:12px; background:#ef4444; color:white; border:none; font-weight:600; cursor:pointer;">Yes, Delete All</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['view']) && $_GET['view'] === 'confirm_reset_stock'): ?>
        <!-- Reset Confirmation UI for Stock -->
        <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.9); z-index:1000; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
            <div style="background:white; border:1px solid #f59e0b; padding:40px; text-align:center; max-width:400px; box-shadow:0 10px 30px rgba(0,0,0,0.1);">
                <i class="fas fa-exclamation-triangle" style="font-size:48px; color:#f59e0b; margin-bottom:20px;"></i>
                <h2 style="margin:0 0 10px; color:#111827;">Reset Stock Module?</h2>
                <p style="color:#6b7280; font-size:14px; line-height:1.6; margin-bottom:30px;">
                    This will permanently delete ALL products, suppliers, categories, stock levels, and purchase orders. <br><strong>This action cannot be undone.</strong>
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="confirm_reset_stock">
                    <div style="display:flex; gap:10px;">
                        <a href="system-status.php" style="flex:1; padding:12px; background:#f3f4f6; color:#374151; font-weight:600; text-decoration:none;">Cancel</a>
                        <button type="submit" style="flex:1; padding:12px; background:#f59e0b; color:white; border:none; font-weight:600; cursor:pointer;">Yes, Delete All</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['view']) && $_GET['view'] === 'confirm_reset_sales'): ?>
        <!-- Reset Confirmation UI for Sales -->
        <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.9); z-index:1000; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(5px);">
            <div style="background:white; border:1px solid #3b82f6; padding:40px; text-align:center; max-width:400px; box-shadow:0 10px 30px rgba(0,0,0,0.1);">
                <i class="fas fa-exclamation-triangle" style="font-size:48px; color:#3b82f6; margin-bottom:20px;"></i>
                <h2 style="margin:0 0 10px; color:#111827;">Reset Sales Module?</h2>
                <p style="color:#6b7280; font-size:14px; line-height:1.6; margin-bottom:30px;">
                    This will permanently delete ALL customers, sales orders, invoices, payments, and delivery records. <br><strong>This action cannot be undone.</strong>
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="confirm_reset_sales">
                    <div style="display:flex; gap:10px;">
                        <a href="system-status.php" style="flex:1; padding:12px; background:#f3f4f6; color:#374151; font-weight:600; text-decoration:none;">Cancel</a>
                        <button type="submit" style="flex:1; padding:12px; background:#3b82f6; color:white; border:none; font-weight:600; cursor:pointer;">Yes, Delete All</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>



    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'revenue_reset_success'): ?>
        <div style="background:#d1fae5; color:#065f46; padding:15px; margin-bottom:20px; font-weight:600; font-size:14px; border:1px solid #10b981;">
            <i class="fas fa-check-circle"></i> Revenue Module has been successfully reset.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'stock_reset_success'): ?>
        <div style="background:#d1fae5; color:#065f46; padding:15px; margin-bottom:20px; font-weight:600; font-size:14px; border:1px solid #10b981;">
            <i class="fas fa-check-circle"></i> Stock Management Module has been successfully reset.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'sales_reset_success'): ?>
        <div style="background:#d1fae5; color:#065f46; padding:15px; margin-bottom:20px; font-weight:600; font-size:14px; border:1px solid #10b981;">
            <i class="fas fa-check-circle"></i> Sales Management Module has been successfully reset.
        </div>
    <?php endif; ?>

    <!-- Key Stats -->
    <div class="grid">
        <div class="card">
            <div class="icon-box"><i class="fas fa-server"></i></div>
            <div class="stat-value">99.9%</div>
            <div class="stat-label">System Uptime</div>
        </div>
        <div class="card">
            <div class="icon-box"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?= $userStats['total'] ?></div>
            <div class="stat-label">Registered Accounts</div>
        </div>
        <div class="card">
            <div class="icon-box"><i class="fas fa-database"></i></div>
            <div class="stat-value"><?= number_format($voucherStats['total_value']) ?></div>
            <div class="stat-label">Total Volume Processed (TZS)</div>
        </div>
        
        <div class="card">
            <div class="icon-box"><i class="fas fa-video"></i></div>
            <div class="stat-value"><?= $activeMeetings ?></div>
            <div class="stat-label">Active Meetings</div>
        </div>
        <div class="card">
            <div class="icon-box"><i class="fas fa-shopping-cart"></i></div>
            <div class="stat-value" style="color:#f59e0b;"><?= $poPending ?></div>
            <div class="stat-label">Pending POs</div>
        </div>
        
        <!-- Developer Rating Widget -->
        <div class="card dev-card">
            <div class="dev-avatar">
                <i class="fas fa-code"></i>
            </div>
            <div style="font-weight:700; font-size:16px;">System Architect</div>
            <div style="color:#94a3b8; font-size:12px;">Developed by You</div>
            <div class="rating-stars">
                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i>
            </div>
            <div style="font-size:11px; opacity:0.7;">Verified Expert Developer</div>
        </div>
    </div>

    <!-- Error Logs -->
    <h3 style="margin-bottom:15px; font-weight:500; color:#ef4444;"><i class="fas fa-bug"></i> Recent System Errors</h3>
    <div style="background:#ffffff; color:#b91c1c; padding:20px; border-radius:0; font-family:monospace; font-size:12px; height:200px; overflow-y:auto; margin-bottom:30px; border:1px solid #fee2e2;">
        <?php foreach($logs as $line): ?>
            <div style="border-bottom:1px solid #f3f4f6; padding:4px 0;"><?= htmlspecialchars($line) ?></div>
        <?php endforeach; ?>
        <?php if(empty($logs)): ?>
            <div style="color:#22c55e;">No recent errors found. System is healthy.</div>
        <?php endif; ?>
    </div>

    <!-- Error Logs -->
    <!-- ... (existing error log code) ... -->

    <!-- Developer Suggestions Board -->
    <h3 style="margin-bottom:15px; font-weight:500; color:#facc15;"><i class="fas fa-lightbulb"></i> Developer Suggestions Board</h3>
    <div class="table-container" style="margin-bottom:30px;">
        <table>
            <thead>
                <tr>
                    <th style="width:150px;">Suggested By</th>
                    <th>Suggestion</th>
                    <th style="width:120px;">Date</th>
                    <th style="width:150px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($suggestions as $s): ?>
                <tr>
                    <td style="color:#94a3b8; font-size:12px;"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?= nl2br(htmlspecialchars($s['suggestion'])) ?></td>
                    <td style="color:#64748b; font-size:11px;"><?= date('M d', strtotime($s['created_at'])) ?></td>
                    <td>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="update_suggestion">
                            <input type="hidden" name="sug_id" value="<?= $s['id'] ?>">
                            <select name="status" onchange="this.form.submit()" 
                                    style="padding:4px; border-radius:0; font-size:11px; font-weight:600; 
                                           border:none; cursor:pointer;
                                           background: <?= $s['status']=='accomplished'?'#22c55e':($s['status']=='impossible'?'#ef4444':'#f59e0b') ?>; 
                                           color: <?= $s['status']=='pending'?'#000':'#fff' ?>;">
                                <option value="pending" <?= $s['status']=='pending'?'selected':'' ?>>Pending</option>
                                <option value="accomplished" <?= $s['status']=='accomplished'?'selected':'' ?>>Accomplished</option>
                                <option value="impossible" <?= $s['status']=='impossible'?'selected':'' ?>>Impossible</option>
                            </select>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($suggestions)): ?>
                <tr><td colspan="4" style="text-align:center; color:#64748b;">No suggestions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- User Audit -->
    <h3 style="margin-bottom:15px; font-weight:500;">User Activity & Access Control</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role / Access Level</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></div>
                        <div style="font-size:12px; color:#64748b;">@<?= htmlspecialchars($u['username']) ?></div>
                    </td>
                    <td>
                        <span class="role-badge <?= $u['role'] === 'admin' ? 'admin' : '' ?>">
                            <?= strtoupper($u['role']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($u['department']) ?></td>
                    <td>
                        <?php if($u['is_active']): ?>
                            <span style="color:#22c55e;"><span class="status-dot green"></span>Active</span>
                        <?php else: ?>
                            <span style="color:#94a3b8;"><span class="status-dot gray"></span>Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#94a3b8;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if (strtolower($u['username']) !== 'admin' && $u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                                <?php if($u['is_active']): ?>
                                    <input type="hidden" name="action" value="lock_user">
                                    <button class="btn-lock" title="Block Access"><i class="fas fa-lock"></i> Lock</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="unlock_user">
                                    <button class="btn-unlock" title="Restore Access"><i class="fas fa-unlock"></i> Unlock</button>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <span style="font-size:11px; color:#64748b; font-style:italic;">Protected</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
