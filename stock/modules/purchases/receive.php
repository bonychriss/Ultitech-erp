<?php
/**
 * Legacy one-click receive — now records supplier delivery + pending warehouse
 * receipts only. On-hand stock is added when the store accepts the PO.
 */
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
$warehouseId = (int) ($_GET['warehouse_id'] ?? 1);
if ($warehouseId <= 0) {
    $warehouseId = 1;
}

try {
    if (!function_exists('ensureLegacyPurchaseItemsReceivedColumn')) {
        require_once __DIR__ . '/purchase_workflow.php';
    }
    if (function_exists('ensureLegacyPurchaseItemsReceivedColumn')) {
        ensureLegacyPurchaseItemsReceivedColumn($pdo);
    }

    $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? AND status NOT IN ('Cancelled', 'Received')");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        throw new Exception('Purchase not found or already fully in stock.');
    }

    $stmtItems = $pdo->prepare('SELECT id, quantity, qty_received FROM purchase_items WHERE purchase_id = ?');
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $receiveQuantities = [];
    foreach ($items as $item) {
        $toReceive = max(0, (float) ($item['quantity'] ?? 0) - (float) ($item['qty_received'] ?? 0));
        if ($toReceive > 0) {
            $receiveQuantities[(int) $item['id']] = $toReceive;
        }
    }

    if ($receiveQuantities === []) {
        flash('success', 'Nothing left to receive for this purchase order.');
        redirect('index.php');
    }

    if (!function_exists('stockPoRecordSupplierDelivery')) {
        throw new Exception('Delivery workflow is not available on this server yet.');
    }

    $ref = trim((string) ($_GET['ref'] ?? ''));
    $notes = $ref !== '' ? ('Ref: ' . $ref) : '';
    $result = stockPoRecordSupplierDelivery(
        $pdo,
        $id,
        $warehouseId,
        $receiveQuantities,
        $notes,
        isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'legacy'
    );

    if (!($result['ok'] ?? false)) {
        throw new Exception((string) ($result['message'] ?? 'Failed to record delivery.'));
    }

    flash('success', (string) ($result['message'] ?? 'Delivery recorded. Awaiting warehouse acceptance.'));
} catch (Exception $e) {
    flash('success', 'Error: ' . $e->getMessage(), 'danger');
}

redirect('index.php');
