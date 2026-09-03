<?php
// stock/modules/purchases/delete.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';

requireLogin();
$company_id = stockPurchaseActiveCompanyId();

if (!stockPurchaseIsAdmin()) {
    stockPurchaseSetFlash('Access denied: only administrators can delete purchase orders.', 'error');
    redirect('index.php');
}

$id = (int) ($_GET['id'] ?? 0);
$source = strtolower(trim((string) ($_GET['source'] ?? 'stock')));
if ($source !== 'legacy') {
    $source = 'stock';
}

if ($id <= 0) {
    stockPurchaseSetFlash('Invalid purchase order.', 'error');
    redirect('index.php');
}

try {
    $po = loadStockPurchaseOrderForDelete($pdo, $id, $source, $company_id);
    if (!$po) {
        stockPurchaseSetFlash('Purchase order not found or you do not have access to delete it.', 'error');
        redirect('index.php');
    }

    $poTable = (string) ($po['_po_table'] ?? 'stocks_purchase_orders');
    $label = trim((string) ($po['po_number'] ?? $po['purchase_no'] ?? ('PO#' . $id)));

    $pdo->beginTransaction();

    if ($poTable === 'stocks_purchase_orders') {
        if (tableExists('stocks_purchase_attachments', $pdo)) {
            $pdo->prepare('DELETE FROM stocks_purchase_attachments WHERE purchase_id = ?')->execute([$id]);
        }
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
        stockPurchaseSetFlash('Purchase order ' . ($label !== '' ? $label : ('#' . $id)) . ' deleted successfully.');
    } else {
        $pdo->rollBack();
        stockPurchaseSetFlash('Purchase order not found or could not be deleted.', 'error');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    stockPurchaseSetFlash('Error deleting purchase order: ' . $e->getMessage(), 'error');
}

redirect('index.php');
