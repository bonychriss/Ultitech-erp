<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
// requireRole(['admin', 'procurement']);

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = $_GET['id'];

try {
    $pdo->beginTransaction();

    // 1. Get Purchase Details
    $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? AND status IN ('Pending', 'Approved')");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch();

    if (!$purchase) {
        throw new Exception("Purchase not found or already processed.");
    }

    // 2. Get purchase items
    $stmtItems = $pdo->prepare("SELECT * FROM purchase_items WHERE purchase_id = ?");
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 3. Update Stock for each item
    $ref = $_GET['ref'] ?? '';
    $refNote = $ref ? " (Ref: $ref)" : "";
    
    foreach ($items as $item) {
        $qty = $item['quantity'];
        $prodId = $item['product_id'];

        // Check if stock row exists
        $stmtCheck = $pdo->prepare("SELECT id FROM stock WHERE product_id = ?");
        $stmtCheck->execute([$prodId]);
        $stock = $stmtCheck->fetch();

        if ($stock) {
            $pdo->prepare("UPDATE stock SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?")->execute([$qty, $stock['id']]);
        } else {
            $pdo->prepare("INSERT INTO stock (product_id, quantity, location, last_updated) VALUES (?, ?, 'Warehouse A', NOW())")->execute([$prodId, $qty]);
        }

        // Log Movement
        $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_at) VALUES (?, 'in', ?, 'purchase', ?, ?, NOW())")
            ->execute([$prodId, $qty, $id, "Received PO " . $purchase['purchase_no'] . $refNote]);
    }

    // 4. Update Purchase Status
    $stmt = $pdo->prepare("UPDATE purchases SET status = 'Received' WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();
    flash('success', 'Purchase received successfully. Stock updated.');

} catch (Exception $e) {
    $pdo->rollBack();
    flash('success', 'Error: ' . $e->getMessage(), 'danger');
}

redirect('index.php');
?>
