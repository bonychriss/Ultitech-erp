<?php
require_once '../includes/functions.php';
requireLogin();

// Handle Self-Create Trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_self_trip') {
    try {
        $tripRef = 'TRIP-' . date('Ymd') . '-' . rand(100, 999);
        $driverId = $_SESSION['user_id']; // Current logged in user is the driver
        $vehicleId = $_POST['vehicle_id'];
        
        // Create trip with status 'loading' so they can start adding items
        $stmt = $pdo->prepare("INSERT INTO delivery_trips (trip_ref, driver_id, vehicle_id, status) VALUES (?, ?, ?, 'loading')");
        $stmt->execute([$tripRef, $driverId, $vehicleId]);
        
        $newTripId = $pdo->lastInsertId();
        
        // Redirect to Manifest to add stops/scan items
        header("Location: manifest.php?trip_id=" . $newTripId);
        exit;
    } catch (Exception $e) {
        $error = "Error creating trip: " . $e->getMessage();
    }
}

// Simple Driver Logic: If trip_id param exists, show that trip. Else, show list of "My Planned/Active Trips"
$tripId = $_GET['trip_id'] ?? null;
$trip = null;

if ($tripId) {
    $stmt = $pdo->prepare("SELECT * FROM delivery_trips WHERE id = ?");
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Attempt to find latest active trip for current user
    // Assuming current user is valid driver (check logic later)
    $stmt = $pdo->prepare("SELECT * FROM delivery_trips WHERE driver_id = ? AND status IN ('planned','loading','in_transit') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $trip = $stmt->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = "Driver View";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; color: #1f2937; margin:0; }
        .bg-header { background: #2563eb; color: white; padding: 20px; padding-top: 40px; }
        .card { background: white; margin: 15px; padding: 15px; border-radius: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .btn-large { display: block; width: 100%; padding: 15px; background: #2563eb; color: white; text-align: center; text-decoration: none; font-weight: 600; margin-top: 10px; border: none; font-size: 16px; }
        .stop-item { border-bottom: 1px solid #eee; padding: 15px 0; display:flex; justify-content:space-between; align-items:center; }
        .stop-item:last-child { border-bottom: none; }
        .status-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; background: #eee; text-transform: uppercase; }
    </style>
</head>
<body>
    <?php if (!$trip): ?>
        <div class="bg-header">
            <h1 style="margin:0;">Start New Route</h1>
            <p>You have no active trips. Start one now.</p>
        </div>
        <div style="padding:20px;">
            <div class="card">
                <h3 style="margin-top:0;">Trip Details</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="create_self_trip">
                    
                    <label style="display:block; margin-bottom:5px; font-weight:600;">Vehicle ID / Plate Number</label>
                    <input type="text" name="vehicle_id" required placeholder="e.g. T 123 ABC" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:4px; margin-bottom:15px; font-size:16px;">
                    
                    <button type="submit" class="btn-large">CREATE & LOAD MANIFEST</button>
                </form>
            </div>
            
            <a href="index.php" style="display:block; text-align:center; margin-top:20px; color:#666; text-decoration:none;">Back to Dashboard</a>
        </div>
    <?php else: ?>
        <div class="bg-header">
            <div style="display:flex; justify-content:space-between;">
                <h1 style="margin:0; font-size:22px;">Trip: <?= $trip['trip_ref'] ?></h1>
                <span style="background:rgba(255,255,255,0.2); padding:4px 10px; font-size:12px;"><?= $trip['status'] ?></span>
            </div>
            <p style="margin:5px 0 0 0; opacity:0.9; font-size:14px;">Vehicle: <?= htmlspecialchars($trip['vehicle_id']) ?></p>
        </div>

        <!-- Action Area -->
        <div class="card">
            <h3 style="margin-top:0; font-size:16px;">Delivery Stops</h3>
            <?php
            $orders = $pdo->prepare("SELECT * FROM delivery_orders WHERE trip_id = ?");
            $orders->execute([$trip['id']]);
            $list = $orders->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <?php foreach($list as $order): ?>
                <div class="stop-item">
                    <div>
                        <div style="font-weight:600;"><?= htmlspecialchars($order['client_name']) ?></div>
                        <div style="font-size:12px; color:#666; margin-top:2px;"><?= htmlspecialchars($order['delivery_address']) ?></div>
                        <div style="font-size:11px; color:#999; margin-top:2px;">Ref: <?= htmlspecialchars($order['invoice_ref']) ?></div>
                    </div>
                    <div>
                        <span class="status-badge"><?= $order['status'] ?></span>
                        <div style="margin-top:8px;">
                            <?php if($order['status'] == 'pending'): ?>
                                <a href="process_delivery.php?order_id=<?= $order['id'] ?>" class="btn" style="background:#2563eb; color:white; border:none; padding:6px 12px; font-size:12px; cursor:pointer; text-decoration:none; display:inline-block;">Start</a>
                            <?php endif; ?>
                            <?php if($order['status'] == 'delivered'): ?>
                                <span style="color:#059669; font-size:12px;">Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if(empty($list)): ?>
                <p style="text-align:center; color:#999;">No stops in this trip.</p>
            <?php endif; ?>
        </div>
        
        <div style="padding:0 15px;">
            <?php if($trip['status'] == 'planned'): ?>
                <form method="post" action="update_trip.php">
                    <input type="hidden" name="action" value="start_trip">
                    <input type="hidden" name="trip_id" value="<?= $trip['id'] ?>">
                    <button class="btn-large">START TRIP</button>
                </form>
            <?php elseif($trip['status'] == 'in_transit'): ?>
                <form method="post" action="update_trip.php">
                     <button class="btn-large" style="background:#059669;">COMPLETE TRIP</button>
                </form>
            <?php endif; ?>
             <a href="index.php" style="display:block; text-align:center; margin-top:20px; color:#666; text-decoration:none;">Exit Driver View</a>
        </div>
    <?php endif; ?>
</body>
</html>
