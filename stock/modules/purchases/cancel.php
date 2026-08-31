<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
if ($id <= 0) {
    redirect('index.php');
}

ensurePurchaseWorkflowSchema($pdo);

try {
    $stmt = $pdo->prepare('SELECT status, procurement_workflow FROM stocks_purchase_orders WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        flash('success', 'Purchase order not found.', 'error');
        redirect('index.php');
    }
    $wf = $row['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
    if (!in_array($row['status'] ?? '', purchaseCancelableStatuses($wf), true)) {
        flash('success', 'This order cannot be cancelled in its current status.', 'error');
        redirect('index.php');
    }

    $upd = $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Cancelled', updated_at = NOW() WHERE id = ?");
    $upd->execute([$id]);
    if ($upd->rowCount() > 0) {
        flash('success', 'Purchase order cancelled.');
    } else {
        flash('success', 'Could not cancel order.', 'error');
    }
} catch (Throwable $e) {
    flash('success', 'Error: ' . $e->getMessage(), 'error');
}

redirect('index.php');
