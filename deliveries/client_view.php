<?php
require_once '../includes/functions.php';

// Public page, but sanitize input
if (!isset($_GET['order_id'])) {
    die("ID missing");
}
$orderId = (int)$_GET['order_id'];

// Fetch Order
$stmt = $pdo->prepare("SELECT * FROM delivery_orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Delivery record not found.");
}

// Prepare file paths
// The paths in DB are relative to web root usually or inside assets?
// Review create_delivery.php: 'assets/uploads/deliveries/...' (relative to web root index typically, but here we are in /deliveries/)
// DB stores: 'assets/uploads/deliveries/inv_....pdf'
// We need to resolve them relative to this file.
// This file is in /deliveries/
// DB path is relative to / (based on previous findings).
// So correct path for link is '../' + $db_path.

$invoiceUrl = $order['invoice_file'] ? '../' . $order['invoice_file'] : null;
$receiptUrl = $order['receipt_file'] ? '../' . $order['receipt_file'] : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Documents - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); width: 100%; max-width: 400px; text-align: center; margin: 20px; }
        .logo { max-height: 60px; margin-bottom: 20px; }
        h1 { font-size: 20px; color: #111827; margin: 0 0 5px 0; }
        p { color: #6b7280; font-size: 14px; margin: 0 0 25px 0; line-height: 1.5; }
        .btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px; margin-bottom: 12px; border-radius: 8px; font-weight: 600; text-decoration: none; font-size: 15px; transition: all 0.2s; box-sizing: border-box; }
        .btn-primary { background: #2563eb; color: white; border: none; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
        .btn-secondary:hover { background: #f9fafb; border-color: #9ca3af; }
        .icon { width: 20px; height: 20px; }
        .meta { background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: left; }
        .meta-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
        .meta-row:last-child { margin-bottom: 0; }
        .label { color: #6b7280; }
        .value { font-weight: 600; color: #111827; }
    </style>
</head>
<body>
    <div class="card">
        <?php 
        $logoUrl = getCompanyLogoUrl();
        if(!empty($logoUrl)): 
        ?>
            <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="logo" onerror="this.style.display='none'">
        <?php endif; ?>
        
        <h1>Delivery Documents</h1>
        <p>Access documents for your delivery below.</p>

        <div class="meta">
            <div class="meta-row">
                <span class="label">Invoice Ref:</span>
                <span class="value"><?= htmlspecialchars($order['invoice_ref'] ?? 'N/A') ?></span>
            </div>
            <div class="meta-row">
                <span class="label">Client:</span>
                <span class="value"><?= htmlspecialchars($order['client_name']) ?></span>
            </div>
            <div class="meta-row">
                <span class="label">Date:</span>
                <span class="value"><?= date('d M Y', strtotime($order['created_at'])) ?></span>
            </div>
        </div>

        <?php if($invoiceUrl): ?>
            <a href="<?= htmlspecialchars($invoiceUrl) ?>" target="_blank" download class="btn btn-primary">
                <!-- Heroicons arrow-down-tray -->
                <svg class="icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M12 9.75V1.5m0 0l3 3m-3-3l-3 3M12 18.75V9.75" /></svg>
                Download Invoice
            </a>
        <?php else: ?>
            <div class="btn btn-secondary" style="opacity: 0.6; cursor: not-allowed;">No Invoice Attached</div>
        <?php endif; ?>

        <?php if($receiptUrl): ?>
            <a href="<?= htmlspecialchars($receiptUrl) ?>" target="_blank" download class="btn btn-secondary">
                <!-- Heroicons document -->
                <svg class="icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                Download Receipt
            </a>
        <?php else: ?>
             <div class="btn btn-secondary" style="opacity: 0.6; cursor: not-allowed;">No Receipt Attached</div>
        <?php endif; ?>

    </div>
</body>
</html>
