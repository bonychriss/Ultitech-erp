<?php
/**
 * View Trip Details (Redesigned)
 * Handles trip overview, stop-by-stop itinerary, and status updates.
 */
require_once '../includes/functions.php';
requireLogin();

// Debugging (optional, keep for now)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$tripId = $_GET['trip_id'] ?? null;
$trip = null;
$error = null;

if (!$tripId) {
    header("Location: index.php?error=Missing Trip ID");
    exit;
}

try {
    // 1. Fetch Trip Details
    $stmt = $pdo->prepare("SELECT t.*, u.full_name as driver_name 
                          FROM delivery_trips t 
                          LEFT JOIN users u ON t.driver_id = u.id 
                          WHERE t.id = ?");
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trip) {
        $error = "Trip not found.";
    } else {
        // 2. Fetch Orders/Stops in the trip (joining with DN to check for client signatures)
        $ordersQuery = $pdo->prepare("SELECT o.*, dn.receiver_signature_path as dn_sig 
                                      FROM delivery_orders o 
                                      LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
                                      WHERE o.trip_id = ? ORDER BY o.id ASC");
        $ordersQuery->execute([$tripId]);
        $list = $ordersQuery->fetchAll(PDO::FETCH_ASSOC);

        // 3. Calculate Stats
        $totalStops = count($list);
        $completedStops = 0;
        foreach ($list as $stop) {
            if (in_array($stop['status'], ['delivered', 'completed', 'failed', 'returned'])) {
                $completedStops++;
            }
        }
    }
} catch (Exception $e) {
    $error = "Database Error: " . $e->getMessage();
}

$pageTitle = $trip ? "Trip Reference: " . $trip['trip_ref'] : "View Trip";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $pageTitle ?> - <?= COMPANY_NAME ?></title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Condensed:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        :root {
            --vfd-blue: #0088cc;
            --vfd-dark: #006699;
            --vfd-bg: #f4f7f9;
            --text-main: #1a1a1a;
            --text-muted: #666;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
        }

        body {
            background-color: var(--vfd-bg);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        /* Responsive Container */
        .trip-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 16px;
        }

        /* Premium Header */
        .page-header {
            background: #fff;
            padding: 16px;
            margin: -16px -16px 20px -16px;
            border-bottom: 1px solid #e1e8ed;
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .back-btn {
            color: var(--text-main);
            text-decoration: none;
            font-size: 24px;
            margin-right: 16px;
            display: flex;
            align-items: center;
        }
        .header-title h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }
        .header-title p {
            margin: 0;
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: #fff;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .stat-box .label {
            font-size: 10px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .stat-box .value {
            font-size: 18px;
            font-weight: 700;
            font-family: 'Roboto Condensed', sans-serif;
            color: var(--vfd-blue);
        }

        /* Itinerary Section */
        .section-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 12px;
            padding-left: 4px;
        }

        /* Stop Cards - Mobile Flattened Design */
        .stop-card {
            background: #fff;
            margin-bottom: 1px; /* Divider effect */
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            position: relative;
        }
        
        /* Desktop Card Style */
        @media (min-width: 768px) {
            .stop-card {
                border-radius: 12px;
                margin-bottom: 16px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                border: 1px solid #eee;
            }
            .trip-container {
                padding: 40px 20px;
            }
        }

        @media (max-width: 767px) {
            .trip-container { padding: 0; }
            .page-header { margin: 0 0 16px 0; }
            .stats-grid { padding: 0 16px; }
            .section-label { padding-left: 16px; }
            /* Remove shadows/borders on mobile for edge-to-edge feel */
            .stop-card {
                border-bottom: 1px solid #f0f0f0;
            }
        }

        /* Sidebar Layout Adjustments */
        @media (min-width: 1024px) {
            .bottom-actions {
                left: 250px !important;
                width: calc(100% - 250px) !important;
                max-width: none !important;
                margin: 0 !important;
                display: flex;
                justify-content: center;
            }
            .bottom-actions form, .bottom-actions .btn-main {
                max-width: 600px;
            }
        }
        body.sidebar-collapsed .bottom-actions {
            left: 72px !important;
            width: calc(100% - 72px) !important;
        }

        .stop-num {
            width: 24px;
            height: 24px;
            background: var(--vfd-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .stop-content { flex-grow: 1; }
        .stop-name {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .stop-meta {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .status-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-pending { background: #eee; color: #666; }
        .status-in_transit { background: #eff6ff; color: #1e40af; }
        .status-delivered { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }

        /* Journey Info */
        .journey-box {
            margin: 12px 0;
            padding: 10px;
            background: #fcfcfc;
            border: 1px solid #f0f0f0;
            border-radius: 6px;
            position: relative;
        }
        .journey-step {
            display: flex;
            gap: 10px;
            font-size: 12px;
            position: relative;
        }
        .journey-step:first-child { margin-bottom: 8px; }
        .step-icon {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: 4px;
            flex-shrink: 0;
        }
        .icon-warehouse { background: #9ca3af; }
        .icon-client { background: var(--vfd-blue); }
        .step-conn {
            position: absolute;
            left: 3px;
            top: 12px;
            bottom: -4px;
            width: 2px;
            background: #e5e7eb;
        }
        .step-label { font-weight: 700; color: var(--text-muted); width: 60px; }
        .step-value { font-weight: 500; color: var(--text-main); }

        /* Verification Row */
        .verification-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }
        .v-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 8px;
            border-radius: 4px;
            background: #f9fafb;
            border: 1px solid #eee;
        }
        .v-badge svg { width: 14px; height: 14px; }
        .v-badge.success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .v-badge.pending { background: #fffbeb; border-color: #fef3c7; color: #92400e; }

        .stop-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
        }
        .btn-view {
            padding: 8px 16px;
            background: var(--vfd-blue);
            color: #fff;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            text-align: center;
        }

        /* Bottom Actions */
        .bottom-actions {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 16px;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.05);
            display: flex;
            gap: 12px;
            max-width: 600px;
            margin: 0 auto;
        }
        .btn-main {
            flex-grow: 1;
            background: var(--vfd-blue);
            color: #fff;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
        }
        .btn-main.complete {
            background: var(--success);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }

        /* Admin Styles */
        .admin-section {
            background: #fff;
            padding: 16px;
            border-radius: 8px;
            margin-top: 24px;
            border: 1px dashed var(--danger);
        }

        .error-message {
            background: #fee2e2;
            color: #b91c1c;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
            margin: 40px 16px;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="trip-container">
        <?php if ($error): ?>
            <div class="error-message">
                <h2>Opps!</h2>
                <p><?= htmlspecialchars($error) ?></p>
                <a href="index.php" class="btn-main" style="display:inline-block; margin-top:10px;">Back to Dashboard</a>
            </div>
        <?php elseif ($trip): ?>
            
            <header class="page-header">
                <a href="index.php" class="back-btn">&larr;</a>
                <div class="header-title">
                    <h1><?= htmlspecialchars($trip['trip_ref'] ?? '') ?></h1>
                    <p><?= htmlspecialchars($trip['vehicle_id'] ?: 'No Vehicle Assigned') ?> • <?= htmlspecialchars($trip['driver_name'] ?? 'Unknown Driver') ?></p>
                </div>
            </header>

            <div class="stats-grid">
                <div class="stat-box">
                    <div class="label">Trip Status</div>
                    <div class="value" style="color:<?= $trip['status'] === 'completed' ? 'var(--success)' : 'var(--vfd-blue)' ?>;">
                        <?= strtoupper(str_replace('_', ' ', $trip['status'])) ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="label">Stops</div>
                    <div class="value"><?= $totalStops ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Completed</div>
                    <div class="value"><?= $completedStops ?></div>
                </div>
            </div>

            <div class="section-label">ITINERARY / STOPS</div>
            
            <div class="itinerary-list" style="<?= $trip['status'] !== 'completed' ? 'margin-bottom: 100px;' : 'margin-bottom: 40px;' ?>">
                <?php if (empty($list)): ?>
                    <div style="text-align:center; padding:40px; color:var(--text-muted);">
                        No stops found for this trip.
                    </div>
                <?php else: ?>
                    <?php foreach ($list as $index => $stop): 
                        $isDriverAccomplished = in_array($stop['status'], ['delivered', 'completed', 'failed', 'returned']);
                        // Check if order has a client signature OR if the linked DN has a signature
                        $hasOrderClientSig = (strpos($stop['signature_path'] ?? '', 'client_') !== false);
                        $hasDNSig = !empty($stop['dn_sig']);
                        $isClientSigned = $hasOrderClientSig || $hasDNSig;
                    ?>
                        <div class="stop-card">
                            <div class="stop-num"><?= $index + 1 ?></div>
                            <div class="stop-content">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <div class="stop-name"><?= htmlspecialchars($stop['client_name'] ?? 'Unknown Customer') ?></div>
                                    <span class="status-badge status-<?= $stop['status'] ?>">
                                        <?= str_replace('_', ' ', $stop['status'] ?? '') ?>
                                    </span>
                                </div>
                                
                                <div class="stop-meta">
                                    <span>REF: <?= htmlspecialchars($stop['invoice_ref'] ?? 'N/A') ?></span>
                                    <span>TYPE: <?= htmlspecialchars($stop['package_description'] ?: 'Standard Package') ?></span>
                                </div>

                                <!-- Journey Info (Warehouse to Client) -->
                                <div class="journey-box">
                                    <div class="journey-step">
                                        <div class="step-conn"></div>
                                        <div class="step-icon icon-warehouse"></div>
                                        <div class="step-label">FROM</div>
                                        <div class="step-value"><?= htmlspecialchars($stop['pickup_location'] ?: 'MAIN WAREHOUSE') ?></div>
                                    </div>
                                    <div class="journey-step">
                                        <div class="step-icon icon-client"></div>
                                        <div class="step-label">TO</div>
                                        <div class="step-value"><?= htmlspecialchars($stop['delivery_address'] ?: 'Customer Address') ?></div>
                                    </div>
                                </div>

                                <!-- Verification Status -->
                                <div class="verification-row">
                                    <div class="v-badge <?= $isDriverAccomplished ? 'success' : '' ?>">
                                        <?php if($isDriverAccomplished): ?>
                                            <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                            DRIVER: DONE
                                        <?php else: ?>
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            DRIVER: PENDING
                                        <?php endif; ?>
                                    </div>
                                    <div class="v-badge <?= $isClientSigned ? 'success' : 'pending' ?>">
                                        <?php if($isClientSigned): ?>
                                            <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                            CLIENT: SIGNED
                                        <?php else: ?>
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                            CLIENT: UNSIGNED
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="stop-actions">
                                    <a href="<?= $isDriverAccomplished ? 'order_details.php' : 'process_delivery.php' ?>?order_id=<?= $stop['id'] ?>" class="btn-view" style="width: 100%;">
                                        <?= $isDriverAccomplished ? 'VIEW / UPDATE DETAILS' : 'PROCESS DELIVERY' ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($trip['status'] !== 'completed'): ?>
            <div class="bottom-actions">
                <?php if ($trip['status'] === 'loading'): ?>
                    <form action="update_trip.php" method="POST" style="width: 100%;">
                        <input type="hidden" name="trip_id" value="<?= $trip['id'] ?>">
                        <input type="hidden" name="action" value="start_trip">
                        <button type="submit" class="btn-main">START TRIP</button>
                    </form>
                <?php elseif ($trip['status'] === 'in_transit' && $completedStops === $totalStops && $totalStops > 0): ?>
                    <form action="update_trip.php" method="POST" style="width: 100%;">
                        <input type="hidden" name="trip_id" value="<?= $trip['id'] ?>">
                        <input type="hidden" name="action" value="complete_trip">
                        <button type="submit" class="btn-main complete">COMPLETE TRIP</button>
                    </form>
                <?php else: ?>
                    <div class="btn-main" style="background:#ccc; cursor:not-allowed;">
                        <?= $totalStops > 0 ? 'TRIP IN PROGRESS' : 'ADD STOPS FIRST' ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (isAdmin()): ?>
                <div class="trip-container" style="padding-top:0;">
                    <div class="admin-section">
                        <p style="margin:0 0 10px 0; font-size:12px; font-weight:700; color:var(--danger);">ADMIN ACTIONS</p>
                        <form action="delete_trip.php" method="POST" onsubmit="return confirm('Careful! This will delete the trip record. Order assignments will be removed. Continue?')">
                            <input type="hidden" name="trip_id" value="<?= $trip['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" style="background:none; border:1px solid var(--danger); color:var(--danger); padding:6px 12px; border-radius:4px; font-size:12px; cursor:pointer;">
                                DELETE TRIP PERMANENTLY
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
    </main>

</body>
</html>
