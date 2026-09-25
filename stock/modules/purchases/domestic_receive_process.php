<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$poId = (int) ($_POST['po_id'] ?? 0);
$poTable = trim((string) ($_POST['po_table'] ?? 'stocks_purchase_orders'));
$notes = clean_input($_POST['notes'] ?? '');
$receiveQuantities = $_POST['receive_qty'] ?? [];
$userId = $_SESSION['user_id'] ?? null;
$warehouseId = (int) ($_POST['warehouse_id'] ?? 1);

if ($poId <= 0 || empty($receiveQuantities)) {
    flash('success_type', 'error');
    flash('success', 'Invalid submission data.');
    redirect('index.php');
}

if ($warehouseId <= 0) {
    $warehouseId = 1;
}

$source = ($poTable === 'purchases') ? 'legacy' : 'stocks';

// Import POs still need a linked shipment before delivery can be recorded.
if ($source === 'stocks') {
    try {
        $stmtPo = $pdo->prepare('SELECT purchase_type, status FROM stocks_purchase_orders WHERE id = ? LIMIT 1');
        $stmtPo->execute([$poId]);
        $poMeta = $stmtPo->fetch(PDO::FETCH_ASSOC) ?: [];
        $poPurchaseType = $poMeta['purchase_type'] ?? 'domestic';
        if (($poMeta['status'] ?? '') === 'Cancelled') {
            flash('success_type', 'error');
            flash('success', 'Cannot receive a cancelled order.');
            redirect('domestic_receive.php?id=' . $poId);
        }
        if ($poPurchaseType === 'import') {
            $shipmentFunctions = dirname(__DIR__, 2) . '/includes/shipment-functions.php';
            if (is_file($shipmentFunctions)) {
                require_once $shipmentFunctions;
            }
            if (function_exists('ensure_shipment_po_linking_schema')) {
                ensure_shipment_po_linking_schema($pdo);
            }
            if (function_exists('stocks_po_has_linked_shipment') && !stocks_po_has_linked_shipment($pdo, $poId)) {
                flash('success_type', 'error');
                flash('success', 'Create and link a shipment to this outdoor PO before recording delivery.');
                redirect('domestic_receive.php?id=' . $poId);
            }
        }
    } catch (Throwable $e) {
        flash('success_type', 'error');
        flash('success', 'Error validating purchase order: ' . $e->getMessage());
        redirect('domestic_receive.php?id=' . $poId);
    }
}

if (!function_exists('stockPoRecordSupplierDelivery')) {
    flash('success_type', 'error');
    flash('success', 'Delivery workflow is not available on this server yet. Please deploy the latest stock update.');
    redirect('domestic_receive.php?id=' . $poId);
}

$result = stockPoRecordSupplierDelivery(
    $pdo,
    $poId,
    $warehouseId,
    $receiveQuantities,
    $notes,
    $userId ? (int) $userId : null,
    $source
);

if ($result['ok'] ?? false) {
    flash('success', (string) ($result['message'] ?? 'Delivery recorded. Awaiting warehouse acceptance.'));
    redirect('index.php');
}

flash('success_type', 'error');
flash('success', (string) ($result['message'] ?? 'Error recording delivery.'));
redirect('domestic_receive.php?id=' . $poId);
