<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

// Domestic delivery for stocks_purchase_orders / stocks_po_items.
// Creates pending warehouse receipts only — on-hand stock is added when the store accepts.

$poId = (int) ($_GET['id'] ?? 0);
$ref = clean_input($_GET['ref'] ?? '');
$warehouseId = (int) ($_GET['warehouse_id'] ?? 1);
if ($warehouseId <= 0) {
    $warehouseId = 1;
}

if ($poId <= 0) {
    flash('success_type', 'error');
    flash('success', 'Invalid purchase order id.');
    redirect('index.php');
}

try {
    $stmtPo = $pdo->prepare("
        SELECT p.*, s.name AS supplier_name
        FROM stocks_purchase_orders p
        LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id
        WHERE p.id = ? AND p.company_id = ?
        LIMIT 1
    ");
    $stmtPo->execute([$poId, $company_id]);
    $po = $stmtPo->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        throw new Exception('Purchase order not found.');
    }

    $stmtItems = $pdo->prepare("
        SELECT pi.id, pi.qty_ordered, pi.qty_received
        FROM stocks_po_items pi
        WHERE pi.po_id = ? AND pi.company_id = ?
    ");
    $stmtItems->execute([$poId, $company_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('No items found for this purchase order.');
    }

    $receiveQuantities = [];
    foreach ($items as $it) {
        $toReceive = max(0, (float) ($it['qty_ordered'] ?? 0) - (float) ($it['qty_received'] ?? 0));
        if ($toReceive > 0) {
            $receiveQuantities[(int) $it['id']] = $toReceive;
        }
    }

    if ($receiveQuantities === []) {
        flash('success', 'Nothing to receive for PO: ' . ($po['po_number'] ?? ('#' . $poId)));
        redirect('index.php');
    }

    if (!function_exists('stockPoRecordSupplierDelivery')) {
        throw new Exception('Delivery workflow is not available on this server yet.');
    }

    $notes = $ref !== '' ? ('Ref: ' . $ref) : '';
    $result = stockPoRecordSupplierDelivery(
        $pdo,
        $poId,
        $warehouseId,
        $receiveQuantities,
        $notes,
        isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'stocks'
    );

    if (!($result['ok'] ?? false)) {
        throw new Exception((string) ($result['message'] ?? 'Failed to record delivery.'));
    }

    flash('success', (string) ($result['message'] ?? ('Delivery recorded for PO: ' . ($po['po_number'] ?? ('#' . $poId)))));
    redirect('index.php');
} catch (Exception $e) {
    flash('success_type', 'error');
    flash('success', 'Error receiving PO: ' . $e->getMessage());
    redirect('index.php');
}
