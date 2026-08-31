<?php
require_once '../includes/functions.php';
requireLogin();

// Initialize Deliveries Schema on first load
ensureDeliveriesSchema();

$pageTitle = "Delivery Logistics";
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
            color: #fff;
            font-family: var(--font-primary);
            font-weight: 500;
            border-radius: 0;
            padding: 6px 12px;
            font-size: 13px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
            background: #1d4ed8;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <div class="header-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
            <div>
                <h1 style="font-size: 20px; color: #111827; margin:0;">Delivery Logistics</h1>
                <p style="color: #6b7280; margin: 2px 0 0 0; font-size: 13px;">Manage trips, manifests, and PPE compliance</p>
            </div>
            <a href="../select-module.php" class="btn" style="background:#fff; color:#374151; border:1px solid #d1d5db;">&larr; Back to Modules</a>
        </div>

        <div class="quick-actions">
            <!-- Navigation Links -->
            <a href="trips.php" class="btn-action">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>All Trips</span>
            </a>
            <a href="trips.php?action=new" class="btn-action">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"></path></svg>
                <span>New Trip Manifest</span>
            </a>
            <a href="view_trip.php" class="btn-action">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                <span>Driver View (Active)</span>
            </a>
        </div>

        <div class="dashboard-grid">
            <div class="stat-card">
                <h3>Active Trips</h3>
                <div class="value">
                    <?php 
                    $activeTrips = $pdo->query("SELECT COUNT(*) FROM delivery_trips WHERE status IN ('loading', 'in_transit')")->fetchColumn();
                    echo number_format($activeTrips);
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Pending Deliveries Today</h3>
                <div class="value" style="color:#2563eb;">
                    <?php 
                    // MVP: Just counting pending orders
                    $pending = $pdo->query("SELECT COUNT(*) FROM delivery_orders WHERE status = 'pending'")->fetchColumn();
                    echo number_format($pending); 
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Exceptions (Returns/Rejections)</h3>
                <div class="value" style="color:#ef4444;">
                    <?php 
                    $exceptions = $pdo->query("SELECT COUNT(*) FROM delivery_orders WHERE status IN ('returned', 'failed') OR id IN (SELECT delivery_order_id FROM delivery_items WHERE status='rejected')")->fetchColumn();
                    echo number_format($exceptions);
                    ?>
                </div>
            </div>
        </div>

        <div class="alert-section">
            <div class="section-header" style="padding: 12px 16px; border-bottom: 1px solid #f3f4f6; margin-bottom:0;">
                <h3 style="padding:0; border:none;">Recent Trips</h3>
                <a href="trips.php" style="font-size:12px; color:#2563eb; text-decoration:none;">View All &rarr;</a>
            </div>
            <table class="alert-table">
                <thead>
                    <tr>
                        <th>Trip Ref</th>
                        <th>Driver/Vehicle</th>
                        <th>Status</th>
                        <th>Stops</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->query("SELECT t.*, 
                        (SELECT COUNT(*) FROM delivery_orders WHERE trip_id = t.id) as stop_count,
                        u.full_name as driver_name
                        FROM delivery_trips t 
                        LEFT JOIN erp_users u ON t.driver_id = u.id 
                        ORDER BY t.created_at DESC LIMIT 5");
                    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($trips)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#9ca3af; padding:30px;">No trips recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($trips as $trip): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($trip['trip_ref']) ?></td>
                                <td>
                                    <?= htmlspecialchars($trip['driver_name']) ?><br>
                                    <span style="color:#9ca3af; font-size:11px;"><?= htmlspecialchars($trip['vehicle_id']) ?></span>
                                </td>
                                <td>
                                    <span style="
                                        padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 500;
                                        background: <?= $trip['status']=='completed'?'#ecfdf5':($trip['status']=='in_transit'?'#eff6ff':'#f3f4f6') ?>; 
                                        color: <?= $trip['status']=='completed'?'#059669':($trip['status']=='in_transit'?'#2563eb':'#4b5563') ?>;
                                        text-transform: capitalize;
                                    "><?= str_replace('_', ' ', $trip['status']) ?></span>
                                </td>
                                <td><?= $trip['stop_count'] ?> stops</td>
                                <td><?= date('d M H:i', strtotime($trip['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</body>
</html>
