<?php
// stock/modules/purchases/delete.php
require_once '../../config/database.php';
require_once '../../config/functions.php';

requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

// 1. Restrict to Admin Only
if (!hasRole('admin')) {
    flash('success', 'Access Denied: Only Admins can delete Purchase Orders.', 'danger');
    redirect('index.php');
}

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = $_GET['id'];

try {
    // 2. Start Transaction
    $pdo->beginTransaction();

    $hasLegacyPurchases = false;
    $hasStockPurchases = false;
    try {
        $hasLegacyPurchases = (bool) $pdo->query("SHOW TABLES LIKE 'purchases'")->fetchColumn()
            && (bool) $pdo->query("SHOW TABLES LIKE 'purchase_items'")->fetchColumn();
        $hasStockPurchases = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_orders'")->fetchColumn()
            && (bool) $pdo->query("SHOW TABLES LIKE 'stocks_po_items'")->fetchColumn();
    } catch (Throwable $e) {
        $hasLegacyPurchases = false;
        $hasStockPurchases = false;
    }

    if ($hasStockPurchases) {
        // New stock schema
        $stmtItems = $pdo->prepare("DELETE FROM stocks_po_items WHERE po_id = ? AND company_id = ?");
        $stmtItems->execute([$id, $company_id]);

        $stmt = $pdo->prepare("DELETE FROM stocks_purchase_orders WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $company_id]);
    } elseif ($hasLegacyPurchases) {
        // Legacy schema
        $stmtItems = $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ? AND company_id = ?");
        $stmtItems->execute([$id, $company_id]);

        $stmt = $pdo->prepare("DELETE FROM purchases WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $company_id]);
    } else {
        throw new Exception("No compatible purchase order tables found (expected stocks_purchase_orders/stocks_po_items or purchases/purchase_items).");
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
