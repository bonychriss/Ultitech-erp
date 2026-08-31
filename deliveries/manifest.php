<?php
require_once '../includes/functions.php';
requireLogin();

if (!isset($_GET['trip_id'])) {
    die("Trip ID not provided");
}
$tripId = $_GET['trip_id'];

// Fetch Trip Details
$start = $pdo->prepare("SELECT * FROM delivery_trips WHERE id = ?");
$start->execute([$tripId]);
$trip = $start->fetch(PDO::FETCH_ASSOC);

if (!$trip) die("Trip not found");

// Handle Add Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_order') {
    try {
        $stmt = $pdo->prepare("INSERT INTO delivery_orders (trip_id, client_name, delivery_address, invoice_ref, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([
            $tripId,
            $_POST['client_name'],
            $_POST['delivery_address'],
            $_POST['invoice_ref']
        ]);
        // Ideally we would add items here too, but for simplicity we'll just create the order structure first
        // If we want items, we need a second step or a more complex form. 
        // For MVP, let's assume 'Items' are entered after, or abstract them.
        // Let's create a placeholder item for now if items are required.
        $orderId = $pdo->lastInsertId();
        
        // Add default item if provided (or just keep it empty)
        if (!empty($_POST['item_desc'])) {
             $stmtItem = $pdo->prepare("INSERT INTO delivery_items (delivery_order_id, item_name, quantity_ordered) VALUES (?, ?, ?)");
             $stmtItem->execute([$orderId, $_POST['item_desc'], $_POST['qty'] ?? 1]);
        }
        
    } catch (Exception $e) {
        $error = "Error adding order: " . $e->getMessage();
    }
}

$pageTitle = "Trip Manifest: " . $trip['trip_ref'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Roboto:wght@400;500;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-primary: 'Inter', sans-serif;
            --font-heading: 'Roboto', sans-serif;
            --font-data: 'Source Sans 3', sans-serif;
            --primary-color: #2563eb;
            --bg-color: #f3f4f6;
            --text-main: #1f2937;
        }
        body { font-family: var(--font-primary); background-color: var(--bg-color); color: var(--text-main); }
        .main-content { padding: 20px; }
        .card { background: #fff; padding: 20px; border: 1px solid #e5e7eb; margin-bottom: 20px; }
        .btn { background: var(--primary-color); color: white; padding: 8px 16px; border: none; font-size: 13px; cursor: pointer; }
        input, textarea { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; font-family: var(--font-data); }
        label { font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    
    <main class="main-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <div>
                <a href="trips.php" style="font-size:12px; color:#6b7280; text-decoration:none;">&larr; Back to Trips</a>
                <h1 style="margin:4px 0 0 0; font-size:20px; font-family:var(--font-heading);">Manifest: <?= $trip['trip_ref'] ?></h1>
                <p style="font-size:13px; color:#6b7280; margin:0;">Vehicle: <?= htmlspecialchars($trip['vehicle_id']) ?></p>
            </div>
            <div style="display:flex; gap:10px;">
                <button onclick="document.forms[0].submit()" class="btn" style="background:none; color:#2563eb; padding:0; text-decoration:underline; border:none;">Print</button>
                <a href="view_trip.php?trip_id=<?= $tripId ?>" class="btn" style="text-decoration:none; background:#059669;">Finish Loading & Go to Driver View</a>
            </div>
        </div>

        <div style="display:flex; gap:20px; flex-wrap:wrap;">
            <!-- Left: Add Order Form -->
            <div class="card" style="flex:1; min-width:300px;">
                <h3 style="margin-top:0;">Add Stop / Order</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add_order">
                    
                    <label>Client Name</label>
                    <input type="text" name="client_name" required placeholder="e.g. ABC Construction">

                    <label>Delivery Address</label>
                    <textarea name="delivery_address" rows="2" required placeholder="Site location..."></textarea>

                    <label>Invoice / Ref #</label>
                    <input type="text" name="invoice_ref" required placeholder="INV-2024-001">

                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
                    
                    <label>Item Description (Summary)</label>
                    <input type="text" name="item_desc" placeholder="e.g. 50x Safety Helmets">

                    <label>Quantity</label>
                    <input type="number" name="qty" value="1" style="width:80px;">

                    <button type="submit" class="btn" style="margin-top:10px; width:100%;">Add to Manifest</button>
                </form>
            </div>

            <!-- Right: Current Manifest -->
            <div class="card" style="flex:2; min-width:300px;">
                <h3 style="margin-top:0;">Current Orders</h3>
                <?php
                $orders = $pdo->prepare("SELECT * FROM delivery_orders WHERE trip_id = ? ORDER BY id ASC");
                $orders->execute([$tripId]);
                $list = $orders->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead style="background:#f9fafb; text-align:left;">
                        <tr>
                            <th style="padding:8px;">Client</th>
                            <th style="padding:8px;">Address</th>
                            <th style="padding:8px;">Ref</th>
                            <th style="padding:8px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($list as $o): ?>
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:10px 8px; font-weight:500;"><?= htmlspecialchars($o['client_name']) ?></td>
                                <td style="padding:10px 8px; color:#6b7280;"><?= htmlspecialchars($o['delivery_address']) ?></td>
                                <td style="padding:10px 8px; font-family:var(--font-data);">
                                    <?= htmlspecialchars($o['invoice_ref']) ?>
                                    <?php if(!empty($o['delivery_note_id'])): ?>
                                        <br><a href="view_delivery_note.php?id=<?= $o['delivery_note_id'] ?>" target="_blank" style="color:#2563eb; text-decoration:none; font-size:11px;">📋 Delivery Note</a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 8px;"><?= $o['status'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($list)): ?>
                            <tr><td colspan="4" style="text-align:center; padding:20px; color:#9ca3af;">No orders added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
