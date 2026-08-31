<?php
// stock/modules/purchases/delete.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';

requireLogin();
$company_id = stockPurchaseActiveCompanyId();

// 1. Restrict to Admin Only
if (!hasRole('admin')) {
    flash('success', 'Access Denied: Only Admins can delete Purchase Orders.', 'danger');
    redirect('index.php');
}

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
if ($id <= 0) {
    redirect('index.php');
}

try {
    $po = loadStockPurchaseOrderForAccess($pdo, $id, $company_id, false);
    if (!$po) {
        flash('success', 'Purchase Order not found or could not be deleted.', 'danger');
        redirect('index.php');
    }

    $poTable = (string) ($po['_po_table'] ?? 'stocks_purchase_orders');

    // 2. Start Transaction
    $pdo->beginTransaction();

    if ($poTable === 'stocks_purchase_orders') {
        $pdo->prepare('DELETE FROM stocks_po_items WHERE po_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('DELETE FROM stocks_purchase_orders WHERE id = ?');
        $stmt->execute([$id]);
    } elseif (tableExists('purchases', $pdo) && tableExists('purchase_items', $pdo)) {
        $pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = ?')->execute([$id]);
        $stmt = $pdo->prepare('DELETE FROM purchases WHERE id = ?');
        $stmt->execute([$id]);
    } else {
        throw new Exception('No compatible purchase order tables found.');
    }

    if ($stmt->rowCount() > 0) {
        $pdo->commit();
        flash('success', 'Purchase Order deleted successfully.');
    } else {
        $pdo->rollBack();
        flash('success', 'Purchase Order not found or could not be deleted.', 'danger');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('success', 'Error deleting Purchase Order: ' . $e->getMessage(), 'danger');
}

redirect('index.php');
?>
