<?php
require_once '../includes/functions.php';
requireLogin();

if (!isset($_GET['order_id'])) {
    die("Order ID missing");
}
$orderId = $_GET['order_id'];

// Fetch Order
$stmt = $pdo->prepare("SELECT * FROM delivery_orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) die("Order not found");

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim($_POST['reason']);
    $evidencePath = null;

    // Handle Photo Upload
    if (isset($_FILES['evidence_photo']) && $_FILES['evidence_photo']['error'] == 0) {
        $ext = pathinfo($_FILES['evidence_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'return_' . $orderId . '_' . time() . '.' . $ext;
        $targetDir = '../uploads/evidence/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        $evidencePath = $targetDir . $filename;
        move_uploaded_file($_FILES['evidence_photo']['tmp_name'], $evidencePath);
    }

    // Update Order Status to 'failed' (or 'returned' if that fits better, using failed for generic unaccepted)
    // Using 'failed' as it implies delivery failure. 'returned' might imply goods physically back at warehouse.
    // Let's use 'failed' for now as per ENUM options (we have failed, returned).
    $newStatus = 'failed'; 

    $stmtUp = $pdo->prepare("UPDATE delivery_orders SET status = ?, failure_reason = ? WHERE id = ?");
    // We need to ensure failure_reason column exists? 
    // Wait, the schema didn't have failure_reason. 
    // I should check schema or just append to 'notes' or 'package_description'.
    // Or add the column?
    // Let's safe bet: Update 'notes' or 'package_description' prepending the issue?
    // Actually, let's try to add the column dynamically if missing, or just use a generic way.
    // Better: Add column `failure_reason` TEXT NULL.
    
    // Quick schema patch
    try {
        $pdo->query("SELECT failure_reason FROM delivery_orders LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE delivery_orders ADD COLUMN failure_reason TEXT NULL");
    }

    $stmtUp->execute([$newStatus, $reason, $orderId]);

    // Save Evidence
    if ($evidencePath) {
        $stmtEv = $pdo->prepare("INSERT INTO delivery_evidence (delivery_order_id, type, file_path) VALUES (?, 'photo_issue', ?)");
        $stmtEv->execute([$orderId, $evidencePath]);
    }

    // Notify Creator
    if (!empty($order['created_by'])) {
        createSystemNotification(
            $order['created_by'],
            "Delivery Rejected",
            "Delivery #{$order['invoice_ref']} was not accepted by client. Reason: $reason",
            "deliveries/index.php",
            "danger"
        );
    }

    header("Location: view_trip.php?trip_id=" . $order['trip_id']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Unaccepted Delivery - <?= COMPANY_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .card { background: white; padding: 30px; border-radius: 8px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        
        .header-danger { border-left: 4px solid #ef4444; padding-left: 15px; margin-bottom: 25px; }
        .header-danger h1 { margin: 0; font-size: 20px; font-weight: 700; color: #111827; }
        .header-danger p { margin: 5px 0 0 0; color: #6b7280; font-size: 14px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; font-size: 13px; color: #374151; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { width: 100%; border: 1px solid #d1d5db; padding: 12px; border-radius: 6px; font-size: 14px; font-family: 'Inter', sans-serif; transition: border-color 0.15s ease-in-out; }
        .form-control:focus { outline: none; border-color: #ef4444; ring: 2px solid #fecaca; }
        
        textarea.form-control { resize: vertical; min-height: 120px; }

        .file-upload-wrapper { position: relative; border: 2px dashed #d1d5db; border-radius: 8px; padding: 20px; text-align: center; background: #f9fafb; transition: all 0.2s; }
        .file-upload-wrapper:hover { border-color: #ef4444; background: #fef2f2; }
        .file-upload-wrapper input[type="file"] { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .file-icon { font-size: 24px; margin-bottom: 10px; display: block; }
        .file-text { font-size: 13px; color: #6b7280; font-weight: 500; }

        .btn-submit { display: block; width: 100%; background: #ef4444; color: white; padding: 14px; border: none; border-radius: 6px; font-weight: 600; font-size: 15px; cursor: pointer; transition: background 0.2s; }
        .btn-submit:hover { background: #dc2626; }
        
        .btn-cancel { display: block; width: 100%; background: white; color: #4b5563; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 500; font-size: 14px; text-align: center; text-decoration: none; margin-top: 12px; transition: background 0.2s; }
        .btn-cancel:hover { background: #f3f4f6; }
    </style>
</head>
<body class="dashboard">
    <?php require_once '../includes/header_employee.php'; ?>
    <main class="main-content">
        <div class="container">
            <div class="card">
                <div class="header-danger">
                    <h1>Report Rejected Delivery</h1>
                    <p>
                        Invoice: <strong><?= htmlspecialchars($order['invoice_ref']) ?></strong> &bull; Client: <?= htmlspecialchars($order['client_name']) ?>
                    </p>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Reason for Rejection</label>
                        <textarea name="reason" class="form-control" required placeholder="Describe why the client rejected the delivery (e.g., damaged goods, wrong items, closed)..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Photo Evidence / Site Photo</label>
                        <div class="file-upload-wrapper">
                            <input type="file" name="evidence_photo" accept="image/*" capture="environment" required>
                            <span class="file-icon">
                                <!-- Heroicons camera outline -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 32px; height: 32px; color: #6b7280;">
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                                </svg>
                            </span>
                            <span class="file-text" style="display: block; margin-top: 5px;">Tap to Take Photo or Upload Evidence</span>
                        </div>
                    </div>

                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn-submit">Submit Rejection Report</button>
                        <a href="process_delivery.php?order_id=<?= $orderId ?>" class="btn-cancel">Cancel & Return</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>
