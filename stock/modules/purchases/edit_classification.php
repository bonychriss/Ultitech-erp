<?php
/**
 * Edit PO type (Internal / Abroad) and payment voucher links.
 * Renders the same UI as domestic_create.php with non-editable sections locked.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

$company_id = (int) (currentCompanyId() ?? 0);
if ($company_id <= 0 && function_exists('defaultCompanyId')) {
    $company_id = (int) (defaultCompanyId() ?? 0);
}

$id = (int) ($_GET['id'] ?? $_POST['po_id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

if (!function_exists('canEditStockPurchasePoClassification') || !canEditStockPurchasePoClassification()) {
    flash('success', 'You are not allowed to edit purchase order type and vouchers. Enable this in Settings ? Stock purchase & vouchers.', 'error');
    redirect('index.php');
}

$po = function_exists('fetchStockPurchaseOrderById')
    ? fetchStockPurchaseOrderById($pdo, $id, true)
    : null;

if (!$po) {
    flash('success', 'Purchase order not found (ID: ' . $id . ').', 'error');
    redirect('index.php');
}

$poStatus = (string) ($po['status'] ?? '');
if (!stockPurchasePoStatusAllowsClassificationEdit($poStatus)) {
    flash('success', 'This purchase order cannot be edited (status: ' . $poStatus . ').', 'error');
    $viewUrl = 'view_po.php?id=' . $id;
    redirect($viewUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_classification'])) {
    $purchaseType = ($_POST['purchase_type'] ?? 'domestic') === 'import' ? 'import' : 'domestic';
    $currencyCode = strtoupper(trim((string) ($_POST['currency'] ?? '')));
    if ($currencyCode === '') {
        $currencyCode = null;
    }
    $voucherIds = [];
    $voucherIdsRaw = trim((string) ($_POST['payment_voucher_ids'] ?? ''));
    if ($voucherIdsRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $voucherIdsRaw) as $vidToken) {
            $vid = (int) $vidToken;
            if ($vid > 0) {
                $voucherIds[$vid] = $vid;
            }
        }
    }
    if (empty($voucherIds) && !empty($_POST['payment_voucher_ids']) && is_array($_POST['payment_voucher_ids'])) {
        foreach ($_POST['payment_voucher_ids'] as $vid) {
            $vid = (int) $vid;
            if ($vid > 0) {
                $voucherIds[$vid] = $vid;
            }
        }
    }
    $result = updateStockPurchasePoClassification($pdo, $company_id, $id, $purchaseType, array_values($voucherIds), $currencyCode);
    if ($result['ok']) {
        flash('success', $result['message']);
        redirect('edit_classification.php?id=' . $id);
    }
    $GLOBALS['classificationEditError'] = $result['message'];
    $po = fetchStockPurchaseOrderById($pdo, $id, true) ?: $po;
}

define('STOCK_PO_CLASSIFICATION_EDIT', true);
$GLOBALS['stockPoClassificationEditPoId'] = $id;
$GLOBALS['stockPoClassificationEditPo'] = $po;
$GLOBALS['stockPoClassificationEditPoTable'] = (string) ($po['_po_table'] ?? 'stocks_purchase_orders');

require __DIR__ . '/domestic_create.php';
