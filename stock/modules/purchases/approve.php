<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
ensurePurchaseWorkflowSchema($pdo);

if ($id <= 0) {
    redirect('index.php');
}

$chk = $pdo->prepare('SELECT status, procurement_workflow FROM stocks_purchase_orders WHERE id = ?');
$chk->execute([$id]);
$row = $chk->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    flash('success', 'Purchase order not found.', 'error');
    redirect('index.php');
}

$wf = $row['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
$st = (string) ($row['status'] ?? '');
$mayApprove = in_array($st, purchaseAwaitingApprovalStatuses(), true)
    || ($st === PURCHASE_STATUS_PENDING && !isSupplierLinkWorkflow($wf));
$mayApprove = $mayApprove && !in_array($st, [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_SUPPLIER], true);

if (!$mayApprove) {
    flash('success', 'This order is not waiting for approval.', 'error');
    redirect('index.php');
}

$stmt = $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Approved', updated_at = NOW() WHERE id = ?");
if ($stmt->execute([$id])) {
    flash('success', 'Purchase Order approved successfully.');
} else {
    flash('success', 'Failed to approve order.', 'error');
}

redirect('../shipments/create.php?purchase_id=' . $id);


