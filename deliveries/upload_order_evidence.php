<?php
require_once '../includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$orderId = $_POST['order_id'] ?? null;
if (!$orderId) {
    die("Missing Order ID");
}

try {
    // 1. Validate Order Existence
    $stmt = $pdo->prepare("SELECT id, invoice_ref FROM delivery_orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) die("Order not found.");

    // 2. Process Uploads
    if (isset($_FILES['evidence_files'])) {
        $files = $_FILES['evidence_files'];
        $uploadedCount = 0;

        foreach ($files['name'] as $i => $name) {
            if ($files['error'][$i] === 0) {
                $tmpName = $files['tmp_name'][$i];
                $size = $files['size'][$i];
                $type = $files['type'][$i];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                // Basic Security
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($ext, $allowed)) continue;
                if ($size > 5 * 1024 * 1024) continue; // 5MB limit

                $newFilename = 'pod_' . $orderId . '_' . time() . '_' . $i . '.' . $ext;
                $targetDir = '../uploads/evidence';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                
                $targetPath = $targetDir . '/' . $newFilename;
                $dbPath = 'uploads/evidence/' . $newFilename;

                if (move_uploaded_file($tmpName, $targetPath)) {
                    // Log to Database
                    $stmtIns = $pdo->prepare("INSERT INTO delivery_evidence (delivery_order_id, type, file_path, created_at) VALUES (?, 'photo_extra', ?, NOW())");
                    $stmtIns->execute([$orderId, $dbPath]);
                    $uploadedCount++;
                }
            }
        }

        if ($uploadedCount > 0) {
            set_flash('success', "Successfully uploaded $uploadedCount evidence photo(s).");
        } else {
            set_flash('error', "No valid photos were uploaded.");
        }
    }
} catch (Exception $e) {
    set_flash('error', "Error: " . $e->getMessage());
}

header("Location: order_details.php?order_id=" . $orderId);
exit;
