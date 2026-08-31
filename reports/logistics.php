<?php
require_once '../includes/functions.php';
requireLogin();

global $pdo;

// Date Range Filter
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-12 months'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Helper function for growth %
function getGrowth($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return (($current - $previous) / $previous) * 100;
}

// --- 1. DELIVERIES & DISPATCH METRICS (Unified Master DB) ---
$deliveryMetrics = ['total_deliveries' => 0, 'completed_deliveries' => 0, 'pending_deliveries' => 0, 'in_transit_deliveries' => 0, 'cancelled_deliveries' => 0, 'avg_delivery_time_hours' => 0];
$dispatchMetrics = ['total_dispatches' => 0, 'completed_dispatches' => 0, 'pending_dispatches' => 0, 'active_dispatches' => 0, 'total_route_value' => 0, 'avg_route_value' => 0];

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_deliveries,
            COUNT(CASE WHEN status IN ('delivered', 'completed') THEN 1 END) as completed_deliveries,
            COUNT(CASE WHEN status IN ('pending', 'request_pending') THEN 1 END) as pending_deliveries,
            COUNT(CASE WHEN status IN ('in_transit', 'loading') THEN 1 END) as in_transit_deliveries,
            COUNT(CASE WHEN status IN ('cancelled', 'rejected', 'failed') THEN 1 END) as cancelled_deliveries,
            AVG(CASE WHEN status IN ('delivered', 'completed') AND completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, completed_at) ELSE NULL END) as avg_delivery_time_hours,
            SUM(route_price) as total_route_value
        FROM (
            SELECT status, created_at, completion_time as completed_at, 0 as route_price FROM new_trading_voucher_db.delivery_orders
            UNION ALL
            SELECT CASE WHEN signature_path IS NOT NULL AND signature_path != '' THEN 'delivered' ELSE 'pending' END as status, created_at, created_at as completed_at, route_price FROM new_trading_voucher_db.dispatch_notes
            UNION ALL
            SELECT status, created_at, created_at as completed_at, 0 as route_price FROM new_trading_voucher_db.shipments
            UNION ALL
            SELECT 'delivered' as status, created_at, created_at as completed_at, 0 as route_price FROM new_trading_voucher_db.delivery_notes
        ) as unified
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $res = $stmt->fetch();
    if ($res) {
        $deliveryMetrics = $res;
        $dispatchMetrics = array_merge($dispatchMetrics, [
            'total_dispatches' => $res['total_deliveries'],
            'completed_dispatches' => $res['completed_deliveries'],
            'pending_dispatches' => $res['pending_deliveries'],
            'active_dispatches' => $res['in_transit_deliveries'],
            'total_route_value' => $res['total_route_value'],
            'avg_route_value' => $res['total_deliveries'] > 0 ? $res['total_route_value'] / $res['total_deliveries'] : 0
        ]);
    }
} catch (Exception $e) { 
    if ($debug) echo "<div style='background: #fee; border: 1px solid #f00; padding: 10px; margin: 10px;'>Metric Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}


// --- 3. RECENT DELIVERIES (Unified) ---
$recentDeliveries = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT 
                invoice_ref as dispatch_number,
                pickup_location as dispatch_from,
                client_name as dispatch_to,
                package_description as contents,
                status,
                created_at,
                completion_time as completed_at,
                TIMESTAMPDIFF(HOUR, created_at, completion_time) as duration_hours,
                'Order' as source,
                0 as route_price,
                created_by
            FROM new_trading_voucher_db.delivery_orders
            UNION ALL
            SELECT 
                dispatch_number,
                dispatch_from,
                dispatch_to,
                contents,
                CASE WHEN signature_path IS NOT NULL AND signature_path != '' THEN 'delivered' ELSE 'pending' END as status,
                created_at,
                created_at as completed_at,
                0 as duration_hours,
                'Dispatch' as source,
                route_price,
                created_by
            FROM new_trading_voucher_db.dispatch_notes
            UNION ALL
            SELECT 
                CONCAT('SHP-', id) as dispatch_number,
                'Warehouse' as dispatch_from,
                'Destination' as dispatch_to,
                'Shipment Record' as contents,
                status,
                created_at,
                created_at as completed_at,
                0 as duration_hours,
                'Shipment' as source,
                0 as route_price,
                created_by
            FROM new_trading_voucher_db.shipments
            UNION ALL
            SELECT 
                CONCAT('DN-', id) as dispatch_number,
                'Warehouse' as dispatch_from,
                'Client' as dispatch_to,
                item_description as contents,
                'delivered' as status,
                created_at,
                created_at as completed_at,
                0 as duration_hours,
                'Note' as source,
                0 as route_price,
                created_by
            FROM new_trading_voucher_db.delivery_notes
        ) as unified
        WHERE created_at BETWEEN ? AND ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$startDate, $endDate]);
    $rawDeliveries = $stmt->fetchAll();
    
    // Join with users for display names
    foreach ($rawDeliveries as $delivery) {
        $uStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $uStmt->execute([$delivery['created_by']]);
        $delivery['created_by_name'] = $uStmt->fetchColumn() ?: 'System';
        $recentDeliveries[] = $delivery;
    }
} catch (Exception $e) { 
    if ($debug) echo "<div style='background: #fee; border: 1px solid #f00; padding: 10px; margin: 10px;'>Recent Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}


$recentDispatches = $recentDeliveries;

// --- 5. PERFORMANCE BY ROUTE (Unified) ---
$routePerformance = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            destination,
            COUNT(*) as delivery_count,
            COUNT(CASE WHEN status IN ('delivered', 'completed') THEN 1 END) as successful_deliveries,
            AVG(CASE WHEN status IN ('delivered', 'completed') AND completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, completed_at) ELSE NULL END) as avg_delivery_time,
            SUM(route_price) as total_revenue
        FROM (
            SELECT client_name as destination, status, created_at, completion_time as completed_at, 0 as route_price FROM new_trading_voucher_db.delivery_orders
            UNION ALL
            SELECT dispatch_to as destination, CASE WHEN signature_path IS NOT NULL AND signature_path != '' THEN 'delivered' ELSE 'pending' END as status, created_at, created_at as completed_at, route_price FROM new_trading_voucher_db.dispatch_notes
            UNION ALL
            SELECT 'Destination' as destination, status, created_at, created_at as completed_at, 0 as route_price FROM new_trading_voucher_db.shipments
            UNION ALL
            SELECT 'Client' as destination, 'delivered' as status, created_at, created_at as completed_at, 0 as route_price FROM new_trading_voucher_db.delivery_notes
        ) as unified
        WHERE created_at BETWEEN ? AND ?
        GROUP BY destination
        ORDER BY delivery_count DESC
        LIMIT 10
    ");
    $stmt->execute([$startDate, $endDate]);
    $routePerformance = $stmt->fetchAll();
} catch (Exception $e) { 
    if ($debug) echo "<div style='background: #fee; border: 1px solid #f00; padding: 10px; margin: 10px;'>Recent Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}


$dispatchRoutePerformance = $routePerformance;

// --- 7. WEEKLY TRENDS (Unified) ---
$weeklyDeliveryTrends = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%u') as week,
            COUNT(*) as deliveries,
            COUNT(CASE WHEN status IN ('delivered', 'completed') THEN 1 END) as completed,
            SUM(route_price) as total_value
        FROM (
            SELECT status, created_at, completion_time as completed_at, 0 as route_price FROM new_trading_voucher_db.delivery_orders
            UNION ALL
            SELECT CASE WHEN signature_path IS NOT NULL AND signature_path != '' THEN 'delivered' ELSE 'pending' END as status, created_at, created_at as completed_at, route_price FROM new_trading_voucher_db.dispatch_notes
            UNION ALL
            SELECT status, created_at, created_at as completed_at, 0 as route_price FROM new_trading_voucher_db.shipments
            UNION ALL
            SELECT 'delivered' as status, created_at, created_at as completed_at, 0 as route_price FROM new_trading_voucher_db.delivery_notes
        ) as unified
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(created_at, '%Y-%u')
        ORDER BY week DESC
        LIMIT 12
    ");
    $stmt->execute([$startDate, $endDate]);
    $weeklyDeliveryTrends = $stmt->fetchAll();
} catch (Exception $e) { 
    if ($debug) echo "<div style='background: #fee; border: 1px solid #f00; padding: 10px; margin: 10px;'>Recent Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}


$weeklyDispatchTrends = $weeklyDeliveryTrends;

// --- 9. DRIVER PERFORMANCE (Unified) ---
$driverPerformance = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.full_name as driver_name,
            COUNT(*) as total_deliveries,
            COUNT(CASE WHEN unified.status IN ('delivered', 'completed') THEN 1 END) as successful_deliveries,
            AVG(CASE WHEN unified.status IN ('delivered', 'completed') AND unified.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, unified.created_at, unified.completed_at) ELSE NULL END) as avg_delivery_time,
            SUM(unified.route_price) as total_revenue
        FROM users u
        INNER JOIN (
            SELECT created_at, completion_time as completed_at, status, 0 as route_price, created_by as user_id FROM new_trading_voucher_db.delivery_orders
            UNION ALL
            SELECT created_at, created_at as completed_at, CASE WHEN signature_path IS NOT NULL AND signature_path != '' THEN 'delivered' ELSE 'pending' END as status, route_price, created_by as user_id FROM new_trading_voucher_db.dispatch_notes
            UNION ALL
            SELECT created_at, created_at as completed_at, status, 0 as route_price, created_by as user_id FROM new_trading_voucher_db.shipments
            UNION ALL
            SELECT created_at, created_at as completed_at, 'delivered' as status, 0 as route_price, created_by as user_id FROM new_trading_voucher_db.delivery_notes
        ) as unified ON u.id = unified.user_id
        WHERE unified.created_at BETWEEN ? AND ?
        GROUP BY u.id
        HAVING total_deliveries > 0
        ORDER BY successful_deliveries DESC
        LIMIT 10
    ");
    $stmt->execute([$startDate, $endDate]);
    $driverPerformance = $stmt->fetchAll();
} catch (Exception $e) { 
    if ($debug) echo "<div style='background: #fee; border: 1px solid #f00; padding: 10px; margin: 10px;'>Recent Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}


$dispatcherPerformance = []; // Fallback empty

// Calculate completion rates
$deliveryCompletionRate = $deliveryMetrics['total_deliveries'] > 0 ? 
    ($deliveryMetrics['completed_deliveries'] / $deliveryMetrics['total_deliveries']) * 100 : 0;

$dispatchCompletionRate = $dispatchMetrics['total_dispatches'] > 0 ? 
    ($dispatchMetrics['completed_dispatches'] / $dispatchMetrics['total_dispatches']) * 100 : 0;

// Handle CSV Export
if ($_GET['action'] == 'export') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="logistics_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, ['Logistics Report - ' . date('Y-m-d')]);
    fputcsv($output, []);
    fputcsv($output, ['Period:', 'Last 30 Days']);
    fputcsv($output, []);
    
    // Delivery Metrics
    fputcsv($output, ['Delivery Metrics']);
    fputcsv($output, ['Total Deliveries', 'Completed', 'Pending', 'In Transit', 'Cancelled', 'Completion Rate %']);
    fputcsv($output, [
        $deliveryMetrics['total_deliveries'],
        $deliveryMetrics['completed_deliveries'],
        $deliveryMetrics['pending_deliveries'],
        $deliveryMetrics['in_transit_deliveries'],
        $deliveryMetrics['cancelled_deliveries'],
        round($deliveryCompletionRate, 1)
    ]);
    fputcsv($output, []);
    
    // Dispatch Metrics
    fputcsv($output, ['Dispatch Metrics']);
    fputcsv($output, ['Total Dispatches', 'Completed', 'Pending', 'Active', 'Completion Rate %', 'Total Route Value']);
    fputcsv($output, [
        $dispatchMetrics['total_dispatches'],
        $dispatchMetrics['completed_dispatches'],
        $dispatchMetrics['pending_dispatches'],
        $dispatchMetrics['active_dispatches'],
        round($dispatchCompletionRate, 1),
        $dispatchMetrics['total_route_value']
    ]);
    fputcsv($output, []);
    
    // Recent Deliveries
    fputcsv($output, ['Recent Deliveries']);
    fputcsv($output, ['Dispatch #', 'From', 'To', 'Contents', 'Status', 'Created', 'Duration (Hours)', 'Created By', 'Route Price']);
    foreach ($recentDeliveries as $delivery) {
        fputcsv($output, [
            $delivery['dispatch_number'],
            $delivery['dispatch_from'],
            $delivery['dispatch_to'],
            $delivery['contents'],
            $delivery['status'],
            $delivery['created_at'],
            $delivery['duration_hours'],
            $delivery['created_by_name'],
            $delivery['route_price']
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logistics Analytics - ERP System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --info: #06b6d4;
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .btn-back:hover {
            transform: translateX(-4px);
            border-color: var(--primary);
            color: var(--primary);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .header {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header p {
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-export {
            background: var(--success);
            color: white;
        }

        .btn-export:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .metric-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .metric-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .growth-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .growth-up {
            background: #dcfce7;
            color: #166534;
        }

        .growth-down {
            background: #fee2e2;
            color: #991b1b;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .chart-card.col-12 {
            grid-column: 1 / -1;
        }

        .chart-card.col-8 {
            grid-column: span 2;
        }

        .chart-card.col-4 {
            grid-column: span 1;
        }

        .chart-header {
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .chart-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .data-table {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        th {
            text-align: left;
            padding: 12px;
            background: var(--bg-secondary);
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 2px solid var(--border);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }

        tr:hover {
            background: var(--bg-secondary);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-purple { background: #ede9fe; color: #6b21a8; }

        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }
        .text-info { color: var(--info); }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--border);
        }

        .tab {
            padding: 12px 20px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--bg-secondary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .chart-card.col-8,
            .chart-card.col-4 {
                grid-column: 1;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header" style="flex-direction: column; align-items: flex-start; gap: 15px;">
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
            <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                <div>
                    <h1><i class="fas fa-truck"></i> Logistics Analytics</h1>
                    <p style="color: var(--text-secondary); margin-top: 8px;">Delivery and dispatch performance tracking</p>
                </div>
                <div class="header-actions">
                <form class="period-selector" method="GET">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 13px; color: var(--text-secondary); font-weight: 500;">Period:</span>
                        <input type="date" name="start_date" value="<?php echo $startDate ?>" class="date-input">
                        <span style="color: var(--text-secondary);">to</span>
                        <input type="date" name="end_date" value="<?php echo $endDate ?>" class="date-input">
                        <button type="submit" class="btn btn-primary" style="padding: 8px 16px;">Update</button>
                    </div>
                </form>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-file-export"></i> Export Report
                </button>
            </div>
            </div>
        </div>

        <?php if ($debug): ?>
        <div style="background: #eff6ff; border: 1px solid #3b82f6; color: #1e40af; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
            <strong>ℹ️ Debug Mode Active:</strong> Verbose SQL errors will be displayed below if any occur.
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('deliveries')">
                <i class="fas fa-box"></i> Deliveries
            </button>
            <button class="tab" onclick="showTab('dispatch')">
                <i class="fas fa-route"></i> Dispatch
            </button>
        </div>

        <!-- Deliveries Tab -->
        <div id="deliveries-tab" class="tab-content active">
            <!-- Key Delivery Metrics -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Total Deliveries</div>
                    <div class="metric-value">
                        <?php echo number_format($deliveryMetrics['total_deliveries']) ?>
                        <span class="growth-badge growth-up">
                            <i class="fas fa-check"></i> <?php echo round($deliveryCompletionRate, 1) ?>%
                        </span>
                    </div>
                    <div class="metric-subtitle"><?php echo number_format($deliveryMetrics['completed_deliveries']) ?> completed</div>
                </div>

                <div class="metric-card success">
                    <div class="metric-label">Pending</div>
                    <div class="metric-value text-warning"><?php echo number_format($deliveryMetrics['pending_deliveries']) ?></div>
                    <div class="metric-subtitle">Awaiting processing</div>
                </div>

                <div class="metric-card warning">
                    <div class="metric-label">In Transit</div>
                    <div class="metric-value text-info"><?php echo number_format($deliveryMetrics['in_transit_deliveries']) ?></div>
                    <div class="metric-subtitle">Currently on route</div>
                </div>

                <div class="metric-card danger">
                    <div class="metric-label">Avg Delivery Time</div>
                    <div class="metric-value"><?php echo round($deliveryMetrics['avg_delivery_time_hours'], 1) ?>h</div>
                    <div class="metric-subtitle">Hours to completion</div>
                </div>
            </div>

            <!-- Delivery Charts -->
            <div class="charts-grid">
                <!-- Weekly Delivery Trends -->
                <div class="chart-card col-8">
                    <div class="chart-header">
                        <div class="chart-title">📈 Weekly Delivery Trends</div>
                        <div class="chart-subtitle">Delivery volume and completion over time</div>
                    </div>
                    <div class="chart-container">
                        <canvas id="deliveryTrendsChart"></canvas>
                    </div>
                </div>

                <!-- Route Performance -->
                <div class="chart-card col-4">
                    <div class="chart-header">
                        <div class="chart-title">🗺️ Route Performance</div>
                        <div class="chart-subtitle">Top destinations by volume</div>
                    </div>
                    <div class="chart-container">
                        <canvas id="routePerformanceChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Deliveries Table -->
            <div class="chart-card col-12">
                <div class="chart-header">
                    <div class="chart-title">📦 Recent Deliveries</div>
                    <div class="chart-subtitle">Latest delivery activities</div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Dispatch #</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Contents</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDeliveries as $delivery): ?>
                            <tr>
                                <td>
                                    <span class="badge <?php echo $delivery['source'] == 'Order' ? 'badge-info' : 'badge-purple' ?>" style="font-size: 10px; opacity: 0.8;">
                                        <?php echo $delivery['source'] ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($delivery['dispatch_number']) ?></strong></td>
                                <td><?php echo htmlspecialchars($delivery['dispatch_from']) ?></td>
                                <td><?php echo htmlspecialchars($delivery['dispatch_to']) ?></td>
                                <td><?php echo htmlspecialchars(substr($delivery['contents'], 0, 50)) ?><?php echo strlen($delivery['contents']) > 50 ? '...' : '' ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $delivery['status'] == 'delivered' ? 'badge-success' : 
                                        ($delivery['status'] == 'pending' ? 'badge-warning' : 
                                        ($delivery['status'] == 'in_transit' ? 'badge-info' : 'badge-danger')); 
                                    ?>">
                                        <?php echo ucfirst($delivery['status']) ?>
                                    </span>
                                </td>
                                <td><?php echo $delivery['duration_hours'] ? round($delivery['duration_hours'], 1) . 'h' : 'N/A' ?></td>
                                <td style="font-weight: 600;">TSh <?php echo number_format($delivery['route_price']) ?></td>
                                <td><?php echo htmlspecialchars($delivery['created_by_name']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($debug): ?>
        <div style="background: #eff6ff; border: 1px solid #3b82f6; color: #1e40af; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
            <strong>ℹ️ Debug Mode Active:</strong> Verbose SQL errors will be displayed below if any occur.
        </div>
        <?php endif; ?>

        <!-- Dispatch Tab -->
        <div id="dispatch-tab" class="tab-content">
            <!-- Key Dispatch Metrics -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-label">Total Dispatches</div>
                    <div class="metric-value">
                        <?php echo number_format($dispatchMetrics['total_dispatches']) ?>
                        <span class="growth-badge growth-up">
                            <i class="fas fa-check"></i> <?php echo round($dispatchCompletionRate, 1) ?>%
                        </span>
                    </div>
                    <div class="metric-subtitle"><?php echo number_format($dispatchMetrics['completed_dispatches']) ?> completed</div>
                </div>

                <div class="metric-card success">
                    <div class="metric-label">Pending</div>
                    <div class="metric-value text-warning"><?php echo number_format($dispatchMetrics['pending_dispatches']) ?></div>
                    <div class="metric-subtitle">Awaiting assignment</div>
                </div>

                <div class="metric-card warning">
                    <div class="metric-label">Active</div>
                    <div class="metric-value text-info"><?php echo number_format($dispatchMetrics['active_dispatches']) ?></div>
                    <div class="metric-subtitle">Currently in progress</div>
                </div>

                <div class="metric-card info">
                    <div class="metric-label">Total Route Value</div>
                    <div class="metric-value">TSh <?php echo number_format($dispatchMetrics['total_route_value']) ?></div>
                    <div class="metric-subtitle">Value of all routes</div>
                </div>
            </div>

            <!-- Dispatch Charts -->
            <div class="charts-grid">
                <!-- Weekly Dispatch Trends -->
                <div class="chart-card col-8">
                    <div class="chart-header">
                        <div class="chart-title">📊 Weekly Dispatch Trends</div>
                        <div class="chart-subtitle">Dispatch volume and completion over time</div>
                    </div>
                    <div class="chart-container">
                        <canvas id="dispatchTrendsChart"></canvas>
                    </div>
                </div>

                <!-- Dispatch Route Performance -->
                <div class="chart-card col-4">
                    <div class="chart-header">
                        <div class="chart-title">🚚 Dispatch Routes</div>
                        <div class="chart-subtitle">Top dispatch destinations</div>
                    </div>
                    <div class="chart-container">
                        <canvas id="dispatchRouteChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Dispatches Table -->
            <div class="chart-card col-12">
                <div class="chart-header">
                    <div class="chart-title">🚛 Recent Dispatches</div>
                    <div class="chart-subtitle">Latest dispatch activities</div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Dispatch #</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Contents</th>
                                <th>Status</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDispatches as $dispatch): ?>
                            <tr>
                                <td>
                                    <span class="badge <?php echo $dispatch['source'] == 'Order' ? 'badge-info' : 'badge-purple' ?>" style="font-size: 10px; opacity: 0.8;">
                                        <?php echo $dispatch['source'] ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($dispatch['dispatch_number']) ?></strong></td>
                                <td><?php echo htmlspecialchars($dispatch['dispatch_from']) ?></td>
                                <td><?php echo htmlspecialchars($dispatch['dispatch_to']) ?></td>
                                <td><?php echo htmlspecialchars(substr($dispatch['contents'], 0, 50)) ?><?php echo strlen($dispatch['contents']) > 50 ? '...' : '' ?></td>
                                <td>
                                    <span class="badge <?php 
                                        echo $dispatch['status'] == 'completed' || $dispatch['status'] == 'delivered' ? 'badge-success' : 
                                        ($dispatch['status'] == 'pending' ? 'badge-warning' : 
                                        ($dispatch['status'] == 'in_progress' ? 'badge-info' : 'badge-danger')); 
                                    ?>">
                                        <?php echo ucfirst($dispatch['status']) ?>
                                    </span>
                                </td>
                                <td><?php echo $dispatch['duration_hours'] ? round($dispatch['duration_hours'], 1) . 'h' : 'N/A' ?></td>
                                <td style="font-weight: 600;">TSh <?php echo number_format($dispatch['route_price']) ?></td>
                                <td><?php echo htmlspecialchars($dispatch['created_by_name']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($debug): ?>
        <div style="background: #eff6ff; border: 1px solid #3b82f6; color: #1e40af; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px;">
            <strong>ℹ️ Debug Mode Active:</strong> Verbose SQL errors will be displayed below if any occur.
        </div>
        <?php endif; ?>
    </div>

    <!-- JavaScript for Tabs and Charts -->
    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Delivery Trends Chart
        const deliveryTrendsCtx = document.getElementById('deliveryTrendsChart').getContext('2d');
        new Chart(deliveryTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($w) { return 'Week ' . substr($w['week'], -2); }, $weeklyDeliveryTrends)) ?>,
                datasets: [{
                    label: 'Deliveries',
                    data: <?php echo json_encode(array_map(function($w) { return $w['deliveries']; }, $weeklyDeliveryTrends)) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Completed',
                    data: <?php echo json_encode(array_map(function($w) { return $w['completed']; }, $weeklyDeliveryTrends)) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Route Performance Chart
        const routePerfCtx = document.getElementById('routePerformanceChart').getContext('2d');
        new Chart(routePerfCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($r) { return $r['destination']; }, $routePerformance)) ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(function($r) { return $r['delivery_count']; }, $routePerformance)) ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        // Dispatch Trends Chart
        const dispatchTrendsCtx = document.getElementById('dispatchTrendsChart').getContext('2d');
        new Chart(dispatchTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($w) { return 'Week ' . substr($w['week'], -2); }, $weeklyDispatchTrends)) ?>,
                datasets: [{
                    label: 'Dispatches',
                    data: <?php echo json_encode(array_map(function($w) { return $w['dispatches']; }, $weeklyDispatchTrends)) ?>,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Completed',
                    data: <?php echo json_encode(array_map(function($w) { return $w['completed']; }, $weeklyDispatchTrends)) ?>,
                    borderColor: '#06b6d4',
                    backgroundColor: 'rgba(6, 182, 212, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Dispatch Route Chart
        const dispatchRouteCtx = document.getElementById('dispatchRouteChart').getContext('2d');
        new Chart(dispatchRouteCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($r) { return $r['destination']; }, $dispatchRoutePerformance)) ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(function($r) { return $r['dispatch_count']; }, $dispatchRoutePerformance)) ?>,
                    backgroundColor: ['#8b5cf6', '#06b6d4', '#f59e0b', '#ef4444', '#10b981'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
