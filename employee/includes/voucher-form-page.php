<?php
if (!isset($vfMode)) { $vfMode = 'create'; }
$isVfEdit = ($vfMode === 'edit');
$vfVoucher = $vfVoucher ?? [];
$vfVoucherId = isset($vfVoucherId) ? (int)$vfVoucherId : (int)($vfVoucher['id'] ?? 0);
$vfExistingItems = $vfExistingItems ?? [];
$vfAttachments = $vfAttachments ?? [];
$vfIsDraftView = !empty($vfIsDraftView);
$voucherModuleQs = $voucherModuleQs ?? '';
$payees = $payees ?? [];
$salesOrders = $salesOrders ?? [];
$allUsers = $allUsers ?? [];
$financeUsers = $financeUsers ?? [];
$error = $error ?? '';
$success = $success ?? '';
$voucherCreateSuccess = $voucherCreateSuccess ?? null;

$vfPayeeName = $isVfEdit ? trim((string)($vfVoucher['payee_name'] ?? '')) : '';
$vfSelectedPayeeId = '';
if ($isVfEdit && $vfPayeeName !== '') {
    foreach ($payees as $p) {
        if (isset($p['name']) && strcasecmp(trim((string)$p['name']), $vfPayeeName) === 0) {
            $vfSelectedPayeeId = (string)($p['id'] ?? '');
            break;
        }
    }
}
$vfDateCreated = $isVfEdit ? (string)($vfVoucher['date_created'] ?? date('Y-m-d')) : date('Y-m-d');
$vfCurrency = $isVfEdit ? (string)($vfVoucher['currency'] ?? 'TZS') : 'TZS';
$vfDescription = $isVfEdit ? (string)($vfVoucher['description'] ?? '') : '';
$vfSupportingDocs = $isVfEdit ? (int)($vfVoucher['supporting_documents'] ?? 0) : 0;
$vfPurpose = $isVfEdit ? resolvePaymentVoucherPurposeFromRow($vfVoucher ?? []) : 'general';
$vfRestricted = $isVfEdit && !empty($vfVoucher['is_restricted']);
$vfApplicant = $isVfEdit ? trim((string)($vfVoucher['applicant'] ?? '')) : '';
$vfDeptMgr = $isVfEdit ? trim((string)($vfVoucher['department_manager'] ?? '')) : '';
$vfCheckedBy = $isVfEdit ? trim((string)($vfVoucher['checked_by'] ?? '')) : '';
$vfPreparedBy = $isVfEdit ? trim((string)($vfVoucher['prepared_by'] ?? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''))) : trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
$vfGm = $isVfEdit ? trim((string)($vfVoucher['general_manager'] ?? '')) : '';
$vfVoucherNo = $isVfEdit ? trim((string)($vfVoucher['voucher_no'] ?? '')) : '';
$vfStatusLabel = $isVfEdit ? trim((string)($vfVoucher['status'] ?? 'Pending')) : 'Draft';
$vfTotalAmount = $isVfEdit ? (float)($vfVoucher['total_amount'] ?? 0) : 0;

$vfSelectedSalesOrderIds = [];
if ($isVfEdit) {
    $rawSo = trim((string)($vfVoucher['linked_sales_order_ids'] ?? ''));
    if ($rawSo !== '') {
        $decoded = json_decode($rawSo, true);
        if (is_array($decoded)) {
            foreach ($decoded as $sid) { $sid = (int)$sid; if ($sid > 0) { $vfSelectedSalesOrderIds[$sid] = $sid; } }
        } else {
            foreach (preg_split('/\s*,\s*/', $rawSo) as $sid) { $sid = (int)$sid; if ($sid > 0) { $vfSelectedSalesOrderIds[$sid] = $sid; } }
        }
    }
    if (empty($vfSelectedSalesOrderIds) && !empty($vfVoucher['linked_sales_order_id'])) {
        $sid = (int)$vfVoucher['linked_sales_order_id'];
        if ($sid > 0) { $vfSelectedSalesOrderIds[$sid] = $sid; }
    }
}
$vfSelectedSalesOrderIds = array_values($vfSelectedSalesOrderIds);
$vfSalesOrderLabel = '';
if (count($vfSelectedSalesOrderIds) === 1) {
    $onlyId = (int)$vfSelectedSalesOrderIds[0];
    foreach ($salesOrders as $__soTmp) {
        $tmpId = (int)($__soTmp['id'] ?? 0);
        if ($tmpId === $onlyId) {
            $tmpNo = (string)($__soTmp['order_number'] ?? ('SO-' . $tmpId));
            $tmpCustomer = (string)($__soTmp['customer_name'] ?? 'Unknown Customer');
            $vfSalesOrderLabel = $tmpNo . ' - ' . $tmpCustomer;
            break;
        }
    }
} elseif (count($vfSelectedSalesOrderIds) > 1) {
    $vfSalesOrderLabel = count($vfSelectedSalesOrderIds) . ' sales orders selected';
}

$vfCancelUrl = isset($vfCancelUrl) ? (string) $vfCancelUrl : ('dashboard.php' . $voucherModuleQs);
$vfViewUrl = isset($vfViewUrl) ? (string) $vfViewUrl : ($isVfEdit && $vfVoucherId > 0 ? ('view-voucher.php?id=' . $vfVoucherId . $voucherModuleQs) : '');
$vfLimitedEditMode = !empty($vfLimitedEditMode);
$vfFieldDisabled = $vfLimitedEditMode ? ' disabled' : '';
$vfFieldReadonly = $vfLimitedEditMode ? ' readonly' : '';
$vfFieldRequired = $vfLimitedEditMode ? '' : ' required';

$vfCssV = (string) time();
$vfStyleCss = function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css';
$vfFormCss = function_exists('app_url') ? app_url('/assets/css/voucher-form.css') : '../assets/css/voucher-form.css';
$vfVoucherJs = function_exists('app_url') ? app_url('/assets/js/voucher-v5.v10.js') : '../assets/js/voucher-v5.v10.js';
$vfVoucherJsLegacy = function_exists('app_url') ? app_url('/assets/js/voucher.js') : '../assets/js/voucher.js';
$vfDeleteAttachmentUrl = function_exists('app_url') ? app_url('/delete_attachment.php') : '../delete_attachment.php';
$vfProxyPdfBase = function_exists('app_url') ? app_url('/proxy_pdf.php') : '../proxy_pdf.php';
$vfLottieSrc = function_exists('app_url') ? app_url('/loading/Book%20Loader.lottie') : '../loading/Book%20Loader.lottie';
?><!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isVfEdit ? 'Edit' : 'Create' ?> Payment Voucher - Ultimate General Trading</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if (function_exists('renderSystemFontHeadMarkup')) { renderSystemFontHeadMarkup(); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($vfStyleCss) ?>?v=<?= $vfCssV ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($vfFormCss) ?>?v=<?= $vfCssV ?>">
    <style>#loading-overlay{opacity:0;visibility:hidden;pointer-events:none}#loading-overlay.is-visible{opacity:1;visibility:visible;pointer-events:auto}
    .vf-limited-edit-mode .add-item,.vf-limited-edit-mode .btn-official,.vf-limited-edit-mode .remove-item,.vf-limited-edit-mode #approvals-card,.vf-limited-edit-mode .cv-payee-row .btn-outline{display:none!important}
    .vf-limited-edit-mode #voucher-items-container input,.vf-limited-edit-mode #voucher-items-container select,.vf-limited-edit-mode #payee_select,.vf-limited-edit-mode #currency,.vf-limited-edit-mode #date_created,.vf-limited-edit-mode #supporting_documents,.vf-limited-edit-mode #description{pointer-events:none;background:#f3f4f6}
    </style>
    <style>
    body.dashboard.vf-voucher-form,
    body.dashboard.vf-voucher-form button,
    body.dashboard.vf-voucher-form input,
    body.dashboard.vf-voucher-form select,
    body.dashboard.vf-voucher-form textarea {
        font-family: var(--erp-font-family, 'Poppins', system-ui, -apple-system, sans-serif) !important;
    }
    body.dashboard.vf-voucher-form {
        background: #f8fafc;
        color: #0f172a;
    }
    body.dashboard.vf-voucher-form .main-content {
        max-width: none;
        margin: 0;
        padding: 32px;
    }
    body.dashboard.vf-voucher-form .cv-page-header,
    body.dashboard.vf-voucher-form .form-container {
        max-width: 1320px;
        margin-left: auto;
        margin-right: auto;
    }
    body.dashboard.vf-voucher-form .cv-page-header {
        background: transparent;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        padding: 0;
        margin-bottom: 32px;
        gap: 18px;
        align-items: center;
    }
    body.dashboard.vf-voucher-form .cv-page-title {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        color: #1e293b;
        line-height: 1.2;
        letter-spacing: 0;
    }
    body.dashboard.vf-voucher-form .cv-page-desc {
        margin: 6px 0 0;
        font-size: 14px;
        color: #334155;
        line-height: 1.45;
    }
    body.dashboard.vf-voucher-form .cv-actions-right {
        gap: 12px;
    }
    body.dashboard.vf-voucher-form .cv-back-link {
        color: #94a3b8;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        white-space: nowrap;
    }
    body.dashboard.vf-voucher-form .cv-back-link:hover {
        color: #475569;
        text-decoration: none;
    }
    body.dashboard.vf-voucher-form .form-container,
    body.dashboard.vf-voucher-form .voucher-layout,
    body.dashboard.vf-voucher-form .voucher-main {
        padding: 0;
        background: transparent;
        border: 0;
        box-shadow: none;
    }
    body.dashboard.vf-voucher-form form.voucher-form-layout {
        display: block !important;
    }
    body.dashboard.vf-voucher-form .cv-card,
    body.dashboard.vf-voucher-form .voucher-summary {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    body.dashboard.vf-voucher-form .cv-card {
        margin-bottom: 24px;
        overflow: hidden;
    }
    body.dashboard.vf-voucher-form .cv-card-header {
        padding: 24px 32px;
        border-bottom: 1px solid #f1f5f9;
        background: #fff;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot {
        display: inline-flex;
        width: 28px;
        height: 28px;
        border-radius: 8px;
        align-items: center;
        justify-content: center;
        color: #ffffff !important;
        font-size: 12px;
        flex-shrink: 0;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot.dot-voucher {
        background: #3b82f6 !important;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot.dot-payment {
        background: #10b981 !important;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot.dot-description {
        background: #f59e0b !important;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot.dot-docs {
        background: #8b5cf6 !important;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot.dot-link {
        background: #6366f1 !important;
    }
    body.dashboard.vf-voucher-form .cv-card-header .dot.dot-approvals {
        background: #ef4444 !important;
    }
    body.dashboard.vf-voucher-form .cv-card-body {
        padding: 32px;
    }
    body.dashboard.vf-voucher-form .cv-card .form-row,
    body.dashboard.vf-voucher-form .cv-split-2,
    body.dashboard.vf-voucher-form .cv-split-3,
    body.dashboard.vf-voucher-form .cv-approvals-row {
        gap: 24px;
    }
    body.dashboard.vf-voucher-form .cv-split-3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        align-items: stretch;
    }
    body.dashboard.vf-voucher-form .cv-card .form-group {
        margin-bottom: 0;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .cv-card-header {
        padding: 14px 18px;
        font-size: 20px;
        font-weight: 700;
        border-bottom: 1px solid #e9eef5;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .cv-card-body {
        padding: 14px 18px 16px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .form-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px 24px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .cv-general-col {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .form-group {
        display: grid;
        grid-template-columns: 138px minmax(0, 1fr);
        align-items: center;
        column-gap: 14px;
        row-gap: 4px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .form-group > label {
        margin-bottom: 0;
        font-size: 13px;
        font-weight: 600;
        color: #111827;
        line-height: 1.2;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .form-group > input,
    body.dashboard.vf-voucher-form #voucher-details-card .form-group > select {
        grid-column: 2;
        min-height: 40px;
        padding: 8px 12px;
        border-radius: 0 !important;
        border: 1px solid #4b5563 !important;
        font-size: 13px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .form-group > input:focus,
    body.dashboard.vf-voucher-form #voucher-details-card .form-group > select:focus {
        border-color: #334155 !important;
        box-shadow: none !important;
        outline: none;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .form-group .help-text {
        grid-column: 2;
        margin-top: 0;
        font-size: 11px;
        line-height: 1.35;
        color: #334155;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .cv-payee-row {
        grid-column: 2;
        display: flex;
        align-items: stretch;
        gap: 12px;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .cv-payee-row .cv-payee-select-wrap {
        flex: 1;
        min-width: 0;
    }
    body.dashboard.vf-voucher-form #voucher-details-card .cv-payee-row .cv-payee-select-wrap select {
        width: 100%;
    }
    body.dashboard.vf-voucher-form .cv-card .form-group label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
    }
    body.dashboard.vf-voucher-form .cv-card .form-group input,
    body.dashboard.vf-voucher-form .cv-card .form-group select,
    body.dashboard.vf-voucher-form .cv-card .form-group textarea,
    body.dashboard.vf-voucher-form #voucher-items-container input,
    body.dashboard.vf-voucher-form #voucher-items-container select,
    body.dashboard.vf-voucher-form #voucher-items-container textarea {
        width: 100%;
        min-height: 46px;
        padding: 12px 16px;
        border: 1px solid #475569;
        border-radius: 10px !important;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        font-weight: 500;
        box-shadow: none;
    }
    body.dashboard.vf-voucher-form .cv-card .form-group input:focus,
    body.dashboard.vf-voucher-form .cv-card .form-group select:focus,
    body.dashboard.vf-voucher-form .cv-card .form-group textarea:focus,
    body.dashboard.vf-voucher-form #voucher-items-container input:focus,
    body.dashboard.vf-voucher-form #voucher-items-container select:focus,
    body.dashboard.vf-voucher-form #voucher-items-container textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    body.dashboard.vf-voucher-form .help-text {
        font-size: 12px;
        color: #334155;
        line-height: 1.5;
    }
    body.dashboard.vf-voucher-form input::placeholder,
    body.dashboard.vf-voucher-form textarea::placeholder {
        color: #334155;
        opacity: 1;
    }
    body.dashboard.vf-voucher-form #supporting-docs-card #description {
        min-height: 220px;
        padding: 14px 16px;
        line-height: 1.5;
    }
    body.dashboard.vf-voucher-form .cv-payee-row {
        display: flex;
        align-items: stretch;
        gap: 12px;
    }
    body.dashboard.vf-voucher-form .cv-payee-row .btn-outline,
    body.dashboard.vf-voucher-form .cv-btn,
    body.dashboard.vf-voucher-form .cv-btn.cv-btn-primary,
    body.dashboard.vf-voucher-form .cv-btn.cv-btn-submit,
    body.dashboard.vf-voucher-form .add-item,
    body.dashboard.vf-voucher-form .btn-official {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 9px 14px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.2;
        text-decoration: none;
    }
    body.dashboard.vf-voucher-form .cv-payee-row .btn-outline,
    body.dashboard.vf-voucher-form .cv-btn,
    body.dashboard.vf-voucher-form .btn-official {
        background: #fff;
        border: 1px solid #cbd5e1;
        color: #334155;
        box-shadow: none;
    }
    body.dashboard.vf-voucher-form .cv-payee-row .btn-outline:hover,
    body.dashboard.vf-voucher-form .cv-btn:hover,
    body.dashboard.vf-voucher-form .btn-official:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        color: #1e293b;
    }
    body.dashboard.vf-voucher-form .cv-btn.cv-btn-primary,
    body.dashboard.vf-voucher-form .cv-btn.cv-btn-submit,
    body.dashboard.vf-voucher-form .add-item {
        background: #3b82f6 !important;
        border: 1px solid #3b82f6 !important;
        color: #fff !important;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
    }
    body.dashboard.vf-voucher-form .cv-btn.cv-btn-primary:hover,
    body.dashboard.vf-voucher-form .cv-btn.cv-btn-submit:hover,
    body.dashboard.vf-voucher-form .add-item:hover {
        background: #2563eb !important;
        border-color: #2563eb !important;
        color: #fff !important;
    }
    body.dashboard.vf-voucher-form .voucher-items h3 {
        display: none;
    }
    body.dashboard.vf-voucher-form .voucher-items-intro {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 16px;
    }
    body.dashboard.vf-voucher-form .voucher-items-tools {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    body.dashboard.vf-voucher-form .voucher-items-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 14px 10px;
        margin: 16px 0 0;
        color: #1e293b;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }
    html:not([data-theme="dark"]) body.dashboard.vf-voucher-form .so-picker-trigger,
    html:not([data-theme="dark"]) body.dashboard.vf-voucher-form .so-picker-search,
    html:not([data-theme="dark"]) body.dashboard.vf-voucher-form .so-option-number,
    html:not([data-theme="dark"]) body.dashboard.vf-voucher-form .so-option-customer,
    html:not([data-theme="dark"]) body.dashboard.vf-voucher-form .so-option-salesperson,
    html:not([data-theme="dark"]) body.dashboard.vf-voucher-form .so-picker-meta {
        color: #0f172a !important;
    }
    body.dashboard.vf-voucher-form .voucher-item {
        padding: 14px 10px;
        margin: 0;
        border-bottom: 1px solid #e2e8f0;
        background: transparent;
        align-items: center;
    }
    body.dashboard.vf-voucher-form .voucher-item .remove-item {
        width: 34px !important;
        height: 34px !important;
        border-radius: 8px !important;
        border: 1px solid #fecaca !important;
        background: #fff !important;
        color: #ef4444 !important;
    }
    body.dashboard.vf-voucher-form .total-amount {
        margin-top: 16px;
        padding: 14px 16px;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        color: #1e3a8a;
    }
    body.dashboard.vf-voucher-form .voucher-summary {
        padding: 18px;
        top: 32px;
    }
    body.dashboard.vf-voucher-form .voucher-summary h3 {
        margin: 0 0 10px;
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
    }
    body.dashboard.vf-voucher-form .voucher-summary-row {
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 12px;
    }
    body.dashboard.vf-voucher-form .voucher-summary-row .value-total {
        color: #2563eb;
        font-weight: 800;
    }
    body.dashboard.vf-voucher-form .cv-form-actions--after-approvals {
        display: flex !important;
        align-items: center;
        justify-content: flex-end;
        flex-direction: row !important;
        gap: 16px;
        margin: 8px 0 80px;
        padding-top: 0;
        border-top: 0;
        flex-wrap: nowrap;
    }
    @media (min-width: 768px) {
        body.dashboard.vf-voucher-form .cv-form-actions--after-approvals {
            display: flex !important;
        }
    }
    body.dashboard.vf-voucher-form .cv-form-actions--after-approvals .cv-btn-form-action,
    body.dashboard.vf-voucher-form .cv-form-actions--after-approvals .cv-btn {
        min-height: 50px;
        padding: 14px 32px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 700;
        width: auto;
        min-width: 170px;
    }
    body.dashboard.vf-voucher-form .cv-form-actions--after-approvals .cv-btn-form-action:not(.cv-btn-submit):not(.cv-btn-primary) {
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #64748b;
        box-shadow: none;
    }
    body.dashboard.vf-voucher-form .cv-form-actions--after-approvals .cv-btn-form-action:not(.cv-btn-submit):not(.cv-btn-primary):hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #475569;
    }
    @media (max-width: 992px) {
        body.dashboard.vf-voucher-form .main-content {
            padding: 16px;
        }
        body.dashboard.vf-voucher-form .cv-page-header,
        body.dashboard.vf-voucher-form .form-container {
            max-width: none;
        }
        body.dashboard.vf-voucher-form .cv-page-header {
            flex-direction: column;
            align-items: flex-start;
            margin-bottom: 24px;
        }
        body.dashboard.vf-voucher-form #voucher-details-card .form-row {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        body.dashboard.vf-voucher-form #voucher-details-card .form-group {
            grid-template-columns: 1fr;
            row-gap: 6px;
        }
        body.dashboard.vf-voucher-form #voucher-details-card .form-group > input,
        body.dashboard.vf-voucher-form #voucher-details-card .form-group > select,
        body.dashboard.vf-voucher-form #voucher-details-card .form-group .help-text {
            grid-column: 1;
        }
        body.dashboard.vf-voucher-form .cv-card-body {
            padding: 20px;
        }
        body.dashboard.vf-voucher-form .cv-card .form-row,
        body.dashboard.vf-voucher-form .cv-split-2,
        body.dashboard.vf-voucher-form .cv-split-3,
        body.dashboard.vf-voucher-form .cv-approvals-row {
            grid-template-columns: 1fr !important;
            gap: 20px;
        }
        body.dashboard.vf-voucher-form .voucher-summary {
            position: static;
        }
        body.dashboard.vf-voucher-form .voucher-items-header {
            display: none;
        }
        body.dashboard.vf-voucher-form .voucher-items-intro {
            flex-direction: column;
        }
        body.dashboard.vf-voucher-form .voucher-item {
            grid-template-columns: 1fr !important;
            gap: 12px;
            padding: 14px 0;
        }
        body.dashboard.vf-voucher-form .cv-payee-row {
            flex-direction: column;
        }
        body.dashboard.vf-voucher-form .cv-form-actions--after-approvals .cv-btn-form-action,
        body.dashboard.vf-voucher-form .cv-form-actions--after-approvals .cv-btn {
            width: auto;
            min-width: 160px;
        }
        body.dashboard.vf-voucher-form .cv-form-actions--after-approvals {
            justify-content: flex-end;
            flex-direction: row !important;
            flex-wrap: wrap;
        }
    }
    </style>
    <!-- dotLottie Player -->
    <script src="https://unpkg.com/@dotlottie/player-component@latest/dist/dotlottie-player.js"></script>
</head>

<body class="dashboard vf-voucher-form<?= $isVfEdit ? ' vf-edit-voucher' : '' ?><?= $vfLimitedEditMode ? ' vf-limited-edit-mode' : '' ?>">
    <!-- Loading Overlay -->
    <div id="loading-overlay" role="alertdialog" aria-busy="true" aria-live="polite">
        <div class="erp-loader-wrapper">
            <span class="erp-loader"></span>
            <div class="erp-loading-text">Processing Voucher...</div>
        </div>
    </div>
    <?php
    $hideHeaderCompanyBranding = true;
    // Lighter header on this heavy form page (avoids loading 60+ notification rows before HTML).
    $suppressHeaderPaymentVoucherNotifications = false;
    if (function_exists('getNotificationCentreFeedPaged')) {
        $GLOBALS['_ultitech_skip_nc_feed_in_header'] = true;
    }
    require_once __DIR__ . '/../../includes/header_employee.php';
    unset($GLOBALS['_ultitech_skip_nc_feed_in_header']);
    ?>

    <main class="main-content">
        <?php if (!$isVfEdit && $voucherCreateSuccess): ?>
            <?php $vcVariant = ($voucherCreateSuccess['variant'] ?? 'success') === 'warning' ? 'warning' : 'success'; ?>
            <div class="d-md-none voucher-create-success-sheet-backdrop" id="voucherCreateSuccessBackdrop" aria-hidden="true"></div>
            <div class="d-md-none voucher-create-success-sheet" id="voucherCreateSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="voucherCreateSuccessSheetTitle">
                <div class="voucher-create-success-sheet-handle" aria-hidden="true"></div>
                <div class="px-4 pb-4 pt-0 text-center">
                    <?php if ($vcVariant === 'warning'): ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-15 text-warning mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-exclamation-triangle fa-lg"></i>
                        </div>
                    <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center mb-2" style="min-height: 140px;">
                            <dotlottie-player
                                src="<?= htmlspecialchars($vfLottieSrc) ?>"
                                background="transparent"
                                speed="1"
                                style="width: 150px; height: 150px;"
                                loop
                                autoplay>
                            </dotlottie-player>
                        </div>
                    <?php endif; ?>
                    <h2 id="voucherCreateSuccessSheetTitle" class="h5 fw-bold text-dark mb-2"><?php echo htmlspecialchars($voucherCreateSuccess['title'] ?? 'Success'); ?></h2>
                    <p class="text-secondary mb-4 small"><?php echo htmlspecialchars($voucherCreateSuccess['message'] ?? ''); ?></p>
                    <a href="dashboard.php<?php echo htmlspecialchars($voucherModuleQs); ?>" class="btn voucher-create-success-btn w-100 py-2 rounded-pill fw-semibold border-0 d-inline-flex align-items-center justify-content-center" id="voucherCreateSuccessDismiss">
                        Go to dashboard
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <header class="cv-page-header">
            <div class="cv-actions-left">
                <?php if ($isVfEdit): ?>
                    <h1 class="cv-page-title">Edit Payment Voucher<?= $vfVoucherNo !== '' ? ' — ' . htmlspecialchars($vfVoucherNo) : '' ?></h1>
                    <p class="cv-page-desc">Update voucher details, payment items, and supporting documents.</p>
                <?php else: ?>
                    <h1 class="cv-page-title">Create Payment Voucher</h1>
                    <p class="cv-page-desc">Record a payment to a supplier, vendor, or individual.</p>
                <?php endif; ?>
            </div>
            <div class="cv-actions-right">
                <?php if ($isVfEdit && $vfViewUrl !== ''): ?>
                    <a href="<?= $vfCancelUrl ?>" class="cv-btn">Cancel</a>
                    <a href="<?= htmlspecialchars($vfViewUrl) ?>" class="cv-btn">View Voucher</a>
                    <button type="button" class="cv-btn cv-btn-primary" onclick="document.getElementById('voucherForm').requestSubmit();">Update &amp; Close</button>
                <?php else: ?>
                    <a href="<?= $vfCancelUrl ?>" class="cv-back-link">
                        <i class="fas fa-arrow-left text-xs"></i> Back to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <div class="form-container">
            <div class="voucher-layout">
                <div class="voucher-main">

            <?php if ($error): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    <?= htmlspecialchars($success) ?>
                    <?php
                    // Construct mock voucher array for notification logic
                    $mockVoucher = [
                        'voucher_no' => $voucher_no ?? '',
                        'payee_name' => $payee_name ?? '',
                        'total_amount' => $total_amount ?? 0,
                        'currency' => $currency ?? 'TZS',
                        'prepared_by' => $prepared_by ?? '',
                        'applicant' => $applicant ?? '',
                        'department_manager' => $department_manager ?? '',
                        'checked_by' => $checked_by ?? '',
                        'status' => STATUS_PENDING
                    ];

                    $notifyTarget = getVoucherNotificationTarget($mockVoucher, $_SESSION['full_name'] ?? '');
                    $waLink = ($notifyTarget && !empty($notifyTarget['link'])) ? $notifyTarget['link'] : null;
                    $notifyRole = ($notifyTarget) ? $notifyTarget['role'] : '';
                    ?>
                    <?php if ($waLink): ?>
                        <div style="margin-top: 10px;">
                            <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" class="btn"
                                style="background-color: #25D366; border-color: #25D366; color: white; display: inline-flex; align-items: center; gap: 8px; text-decoration: none;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                                    viewBox="0 0 16 16">
                                    <path
                                        d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592zm3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.729.729 0 0 0-.529.247c-.182.198-.691.677-.691 1.654 0 .977.71 1.916.81 2.049.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232z" />
                                </svg>
                                Notify <?= htmlspecialchars($notifyRole) ?> via WhatsApp
                            </a>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 10px;">
                        <a href="dashboard.php" style="color: white; text-decoration: underline;">Go to Dashboard</a>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="voucherForm" class="voucher-form-layout" enctype="multipart/form-data"<?= $isVfEdit ? '' : '' ?>>
                <?php if (!$isVfEdit): ?>
                <input type="hidden" name="action" value="create" />
                <?php endif; ?>
                <?php if ($vfLimitedEditMode): ?>
                <input type="hidden" name="limited_classification_update" value="1">
                <?php endif; ?>
                <section class="cv-card" id="voucher-details-card">
                    <div class="cv-card-header"><span class="dot dot-voucher"><i class="fas fa-file-invoice"></i></span> General Information</div>
                    <div class="cv-card-body">
                        <div class="form-row">
                            <!-- Left Column -->
                            <div class="cv-general-col">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="payee_select">Payee Name *</label>
                                    <div class="cv-payee-row">
                                        <div class="cv-payee-select-wrap">
                                            <select id="payee_select" name="payee_id"<?= $vfFieldRequired ?> onchange="updatePayeeName(this)"<?= $vfFieldDisabled ?>>
                                                <option value="">— Select Payee —</option>
                                                <?php if ($isVfEdit && $vfPayeeName !== '' && $vfSelectedPayeeId === ''): ?>
                                                    <option value="" data-name="<?= htmlspecialchars($vfPayeeName) ?>" selected><?= htmlspecialchars($vfPayeeName) ?> (current)</option>
                                                <?php endif; ?>
                                                <?php foreach ($payees as $p): ?>
                                                    <?php $pid = (string)($p['id'] ?? ''); ?>
                                                    <option value="<?= htmlspecialchars($pid) ?>" data-name="<?= htmlspecialchars($p['name']) ?>" <?= ($vfSelectedPayeeId !== '' && $vfSelectedPayeeId === $pid) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($p['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="payee_name" id="payee_name" value="<?= htmlspecialchars($vfPayeeName) ?>">
                                        </div>
                                        <?php if (!$vfLimitedEditMode): ?>
                                        <button type="button" class="btn-outline" onclick="openAddPayeeModal()" title="Create a new payee">
                                            <i class="fas fa-plus" aria-hidden="true"></i> New Payee
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="help-text">Choose an existing payee or create one without leaving this page.</div>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="currency">Currency</label>
                                    <select id="currency" name="currency"<?= $vfFieldDisabled ?>>
                                        <option value="TZS" <?= $vfCurrency === 'TZS' ? 'selected' : '' ?>>TZS</option>
                                        <option value="USD" <?= $vfCurrency === 'USD' ? 'selected' : '' ?>>USD</option>
                                        <option value="CNY" <?= $vfCurrency === 'CNY' ? 'selected' : '' ?>>CNY</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="supporting_documents">Supporting Documents (Qty.)</label>
                                    <input type="number" id="supporting_documents" name="supporting_documents" min="0" placeholder="e.g. 6" value="<?= (int)$vfSupportingDocs ?>"<?= $vfFieldReadonly ?>>
                                    <div class="help-text">Number of attachments (invoices, receipts, etc.).</div>
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div class="cv-general-col">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="date_created">Date *</label>
                                    <input type="date" id="date_created" name="date_created"<?= $vfFieldRequired ?> value="<?= htmlspecialchars($vfDateCreated) ?>"<?= $vfFieldDisabled ?>>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="voucher_purpose">Purpose</label>
                                    <select id="voucher_purpose" name="voucher_purpose">
                                        <option value="general" <?= $vfPurpose === 'general' ? 'selected' : '' ?>>General Payment</option>
                                        <option value="stock_purchase" <?= $vfPurpose === 'stock_purchase' ? 'selected' : '' ?>>Stock Purchase</option>
                                    </select>
                                    <div class="help-text">Choose Stock Purchase to link this voucher to a purchase order later.</div>
                                </div>
                                <?php if ((isAdmin() || (function_exists('isFinance') && isFinance())) && !$vfLimitedEditMode): ?>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label for="is_restricted">Restricted Access (Confidential)</label>
                                        <div style="display:flex; align-items:center; gap:8px; min-height:38px;">
                                            <input type="checkbox" id="is_restricted" name="is_restricted" value="1" style="width:auto; margin:0;" <?= $vfRestricted ? 'checked' : '' ?>>
                                            <span class="help-text" style="margin-top:0;">Only Finance and Admins can view this voucher.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="cv-card">
                    <div class="cv-card-header"><span class="dot dot-payment"><i class="fas fa-credit-card"></i></span> Payment Details</div>
                    <div class="cv-card-body">
                <div class="voucher-items">
                    <div class="voucher-items-intro">
                        <div class="help-text">Add one or more items to specify what this payment is for.</div>
                        <?php if (!$vfLimitedEditMode): ?>
                        <div class="voucher-items-tools">
                            <button type="button" class="add-item" onclick="addVoucherItem()">Add Item</button>
                            <button type="button" class="btn-official" onclick="openExpenseModal()">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px; height:14px;">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                Budget Type Reference
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="voucher-items-header" class="voucher-items-header">
                        <div>Payment Type</div>
                        <div>Budget Type</div>
                        <div>Name</div>
                        <div>Amount</div>
                        <div>Item Description</div>
                        <div></div>
                    </div>
                    <div id="voucher-items-container">
                        <!-- Items will be added here dynamically -->
                        <!-- Server-side fallback (in case JS is blocked): one minimal row so POST contains arrays -->
                        <div class="voucher-item no-label server-fallback" style="display:none">
                            <div class="form-group no-label">
                                <select aria-label="Payment Type" name="payment_type[]">
                                    <option value="">Select Type</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Cash Payment">Cash Payment</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Mobile Payment">Mobile Payment</option>
                                </select>
                            </div>
                            <div class="form-group no-label">
                                <select aria-label="Budget Type" name="budget_type[]">
                                    <option value="">Select Budget</option>
                                    <option value="Operational Expenses">Operational Expenses</option>
                                    <option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>
                                    <option value="Employee Costs">Employee Costs</option>
                                    <option value="Sales &amp; Marketing">Sales &amp; Marketing</option>
                                    <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>
                                    <option value="Administration &amp; Management">Administration &amp; Management
                                    </option>
                                    <option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital
                                        Expenditure (CAPEX)</option>
                                    <option value="Financial Obligations">Financial Obligations</option>
                                    <option value="Tax &amp; Compliance">Tax &amp; Compliance</option>
                                    <option value="Others / Miscellaneous">Others / Miscellaneous</option>
                                </select>
                            </div>
                            <div class="form-group no-label">
                                <input aria-label="Name" type="text" name="name[]" value="" placeholder="Payee"
                                    readonly>
                            </div>
                            <div class="form-group no-label">
                                <input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0"
                                    placeholder="0.00">
                            </div>
                            <div class="form-group no-label">
                                <input aria-label="Item Description" type="text" name="item_description[]"
                                    placeholder="Description">
                            </div>
                            <div></div>
                        </div>
                        <noscript>
                            <style>
                                .server-fallback {
                                    display: grid !important;
                                }
                            </style>
                            <div style="font-size:12px;color:#b45309;margin-top:4px;">JavaScript is disabled or blocked.
                                Please fill this single item and submit.</div>
                        </noscript>
                    </div>

                    <div class="total-amount">
                        Total Amount: <span id="currency-symbol"><?= htmlspecialchars($vfCurrency) ?></span> <span id="total-amount"><?= $isVfEdit ? number_format($vfTotalAmount, 2) : '0.00' ?></span>
                    </div>
                </div>
                    </div>
                </section>

                <div class="cv-split-3">
                    <section class="cv-card" id="supporting-docs-card">
                        <div class="cv-card-header"><span class="dot dot-description"><i class="fas fa-align-left"></i></span> Description / Justification</div>
                        <div class="cv-card-body">
                            <div class="form-group mb-0">
                                <label for="description">Description *</label>
                                <textarea id="description" name="description" rows="10"<?= $vfFieldRequired ?> placeholder="What is this payment for? Include brief purpose and key items."<?= $vfFieldReadonly ?>><?= htmlspecialchars($vfDescription) ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="cv-card">
                        <div class="cv-card-header"><span class="dot dot-docs"><i class="fas fa-paperclip"></i></span> Supporting Documents</div>
                        <div class="cv-card-body">
                            <div class="form-group mb-0">
                                <label for="supporting_files">Upload files (Images, PDF, Office docs)</label>
                                <div class="cv-upload-box" id="supporting-files-box">
                                    <label for="supporting_files" class="cv-upload-trigger">
                                        <span class="cv-upload-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                                        <span>Drag &amp; drop files here or click to browse</span>
                                        <span class="cv-upload-btn">Choose File</span>
                                    </label>
                                    <input type="file" id="supporting_files" class="cv-upload-input" name="supporting_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx,image/*,application/pdf" onchange="(function(i){var info=document.getElementById('supporting-files-selected');var ind=document.getElementById('supporting-files-indicator');var txt=document.getElementById('supporting-files-indicator-text');var box=document.getElementById('supporting-files-box');if(!info){return;}var n=(i.files&&i.files.length)?i.files.length:0;if(n===0){info.textContent='No file chosen';if(ind){ind.classList.remove('is-visible');}if(box){box.classList.remove('has-files');}return;}info.textContent=(n===1)?i.files[0].name:(n+' files selected');if(txt){txt.textContent=(n===1)?'1 file attached':(n+' files attached');}if(ind){ind.classList.add('is-visible');}if(box){box.classList.add('has-files');}})(this)">
                                </div>
                                <div class="help-text" id="supporting-files-selected">No file chosen</div>
                                <div class="cv-files-indicator" id="supporting-files-indicator">
                                    <i class="fas fa-check-circle"></i>
                                    <span id="supporting-files-indicator-text">Files attached</span>
                                </div>
                                <div class="help-text">Attach invoices, receipts, quotations, etc. (Images, PDFs, Excel, Word). Max ~10MB per file.</div>
                                <?php if ($isVfEdit && !empty($vfAttachments)): ?>
                                <div class="form-group" style="margin-top:14px;">
                                    <label>Existing attachments (<?= count($vfAttachments) ?>)</label>
                                    <div class="vf-existing-attachments" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                                        <?php foreach ($vfAttachments as $att):
                                            $rel = ltrim($att['file_path'], '/');
                                            $proxyLink = $vfProxyPdfBase . '?file=' . urlencode($rel);
                                            $name = $att['original_name'];
                                            $aid = (int)($att['id'] ?? 0);
                                        ?>
                                        <div class="attachment-item" id="att-<?= $aid ?>" style="display:inline-block;position:relative;">
                                            <a href="<?= htmlspecialchars($proxyLink) ?>" target="_blank" class="cv-btn" style="padding:6px 10px;font-size:12px;text-decoration:none;"><?= htmlspecialchars($name) ?></a>
                                            <?php if (!$vfLimitedEditMode): ?>
                                            <button type="button" onclick="deleteAttachment(<?= $aid ?>)" title="Delete" style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;border:1px solid #fff;background:#111;color:#fff;cursor:pointer;line-height:1;">&times;</button>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php elseif ($isVfEdit): ?>
                                <div class="help-text" style="margin-top:10px;">No attachments yet.</div>
                                <?php endif; ?>
                                
                            </div>
                        </div>
                    </section>

                    <section class="cv-card">
                        <div class="cv-card-header"><span class="dot dot-link"><i class="fas fa-link"></i></span> Link Sales Order(s) (from Sales Module)</div>
                        <div class="cv-card-body">
                            <div class="form-group mb-0">
                                <?php
                                    $selectedSalesOrderIds = $vfSelectedSalesOrderIds;
                                    $selectedSalesOrderLabel = $vfSalesOrderLabel;
                                    if (!$isVfEdit) {
                                        $selectedSalesOrderIdsRaw = trim((string) ($_POST['linked_sales_order_ids'] ?? ''));
                                        $selectedSalesOrderIds = [];
                                        if ($selectedSalesOrderIdsRaw !== '') {
                                            foreach (preg_split('/\s*,\s*/', $selectedSalesOrderIdsRaw) as $sid) {
                                                $sid = (int) $sid;
                                                if ($sid > 0) {
                                                    $selectedSalesOrderIds[$sid] = $sid;
                                                }
                                            }
                                        } elseif (isset($_POST['linked_sales_order_id']) && (int) $_POST['linked_sales_order_id'] > 0) {
                                            $sid = (int) $_POST['linked_sales_order_id'];
                                            $selectedSalesOrderIds[$sid] = $sid;
                                        }
                                        $selectedSalesOrderIds = array_values($selectedSalesOrderIds);
                                        $selectedSalesOrderLabel = '';
                                        if (count($selectedSalesOrderIds) === 1) {
                                            $onlyId = (int) $selectedSalesOrderIds[0];
                                            foreach ($salesOrders as $__soTmp) {
                                                $tmpId = (int) ($__soTmp['id'] ?? 0);
                                                if ($tmpId === $onlyId) {
                                                    $tmpNo = (string) ($__soTmp['order_number'] ?? ('SO-' . $tmpId));
                                                    $tmpCustomer = (string) ($__soTmp['customer_name'] ?? 'Unknown Customer');
                                                    $selectedSalesOrderLabel = $tmpNo . ' - ' . $tmpCustomer;
                                                    break;
                                                }
                                            }
                                        } elseif (count($selectedSalesOrderIds) > 1) {
                                            $selectedSalesOrderLabel = count($selectedSalesOrderIds) . ' sales orders selected';
                                        }
                                    }
                                ?>
                                <div class="so-picker" id="sales-order-picker">
                                    <input type="hidden" id="linked_sales_order_ids" name="linked_sales_order_ids" value="<?= htmlspecialchars(implode(',', $selectedSalesOrderIds)) ?>">
                                    <input type="hidden" id="linked_sales_order_id" name="linked_sales_order_id" value="<?= !empty($selectedSalesOrderIds) ? (int) $selectedSalesOrderIds[0] : '' ?>">
                                    <button type="button" class="so-picker-trigger" id="so-picker-trigger">
                                        <span id="so-picker-trigger-label"><?= htmlspecialchars($selectedSalesOrderLabel !== '' ? $selectedSalesOrderLabel : 'Search sales order by number, customer, or status...') ?></span>
                                        <i class="fas fa-chevron-down so-caret"></i>
                                    </button>
                                    <div class="so-picker-dropdown" id="so-picker-dropdown">
                                        <div class="so-picker-search-wrap">
                                            <input type="text" id="so-picker-search" class="so-picker-search" placeholder="Search sales order by number, customer, or status...">
                                        </div>
                                        <div class="so-picker-chips" id="so-picker-chips">
                                            <button type="button" class="so-chip active" data-so-chip="all">All</button>
                                            <button type="button" class="so-chip" data-so-chip="paid">Paid</button>
                                            <button type="button" class="so-chip" data-so-chip="invoiced">Invoiced</button>
                                            <button type="button" class="so-chip" data-so-chip="partial">Partial</button>
                                        </div>
                                        <div class="so-picker-results" id="so-picker-results">
                                            <?php foreach ($salesOrders as $so): ?>
                                                <?php
                                                    $soId = (int) ($so['id'] ?? 0);
                                                    $soNo = (string) ($so['order_number'] ?? ('SO-' . $soId));
                                                    $soCustomer = (string) ($so['customer_name'] ?? 'Unknown Customer');
                                                    $soSalesperson = (string) ($so['salesperson_name'] ?? 'Unassigned');
                                                    $soStatusRaw = trim((string) ($so['status'] ?? ''));
                                                    $soStatusLower = strtolower($soStatusRaw);
                                                    $soStatusClass = 'other';
                                                    if (strpos($soStatusLower, 'paid') !== false) {
                                                        $soStatusClass = 'paid';
                                                    } elseif (strpos($soStatusLower, 'invoice') !== false) {
                                                        $soStatusClass = 'invoiced';
                                                    } elseif (strpos($soStatusLower, 'partial') !== false) {
                                                        $soStatusClass = 'partial';
                                                    }
                                                ?>
                                                <div
                                                    class="so-option<?= in_array($soId, $selectedSalesOrderIds, true) ? ' is-selected' : '' ?>"
                                                    data-so-id="<?= $soId ?>"
                                                    data-so-number="<?= htmlspecialchars($soNo, ENT_QUOTES) ?>"
                                                    data-so-customer="<?= htmlspecialchars($soCustomer, ENT_QUOTES) ?>"
                                                    data-so-salesperson="<?= htmlspecialchars($soSalesperson, ENT_QUOTES) ?>"
                                                    data-so-status="<?= htmlspecialchars($soStatusLower, ENT_QUOTES) ?>"
                                                    data-so-chip="<?= htmlspecialchars($soStatusClass, ENT_QUOTES) ?>"
                                                >
                                                    <span class="so-option-check"><i class="fas fa-check"></i></span>
                                                    <div class="so-option-main">
                                                        <div class="so-option-number"><?= htmlspecialchars($soNo) ?></div>
                                                        <span class="so-badge <?= htmlspecialchars($soStatusClass) ?>"><?= htmlspecialchars($soStatusRaw !== '' ? ucfirst($soStatusRaw) : 'Open') ?></span>
                                                    </div>
                                                    <div class="so-option-subrow">
                                                        <div class="so-option-customer"><?= htmlspecialchars($soCustomer) ?></div>
                                                        <div class="so-option-salesperson" title="<?= htmlspecialchars($soSalesperson) ?>">Salesperson: <?= htmlspecialchars($soSalesperson) ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="so-picker-empty" id="so-picker-empty" style="display:none;">No matching sales orders found.</div>
                                        </div>
                                        <div class="so-picker-meta">
                                            <div class="so-picker-meta-left">
                                                <i class="fas fa-search" aria-hidden="true"></i>
                                                <span id="so-picker-meta">Type to search more results...</span>
                                            </div>
                                            <div class="so-picker-meta-right">
                                                <span>Show</span>
                                                <select id="so-picker-page-size" class="so-picker-page-size" aria-label="Sales orders per page">
                                                    <option value="5" selected>5</option>
                                                    <option value="15">15</option>
                                                    <option value="25">25</option>
                                                    <option value="50">50</option>
                                                    <option value="100">100</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="help-text">Selected sales order(s) will appear as PDF file(s) when this voucher is viewed.</div>
                            </div>
                        </div>
                    </section>
                </div>

                <?php if (!$vfLimitedEditMode): ?>
                <section class="cv-card" id="approvals-card">
                    <div class="cv-card-header"><span class="dot dot-approvals"><i class="fas fa-user-check"></i></span> Approval Routing</div>
                    <div class="cv-card-body">
                        <input type="hidden" id="prepared_by" name="prepared_by" value="<?= htmlspecialchars($vfPreparedBy) ?>">
                        <div class="form-row cv-approvals-row">
                            <div class="form-group">
                                <label for="applicant">Applicant</label>
                                <select id="applicant" name="applicant" required>
                                    <option value="">— Select user —</option>
                                    <?php foreach ($allUsers as $u):
                                        $name = trim((string) ($u['full_name'] ?? ''));
                                        if ($name === '') { continue; } ?>
                                        <option value="<?= htmlspecialchars($name) ?>" <?= ($vfApplicant === $name ? 'selected' : '') ?>>
                                            <?= htmlspecialchars($name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="department_manager">Department Manager</label>
                                <select id="department_manager" name="department_manager" required>
                                    <option value="">— Select user —</option>
                                    <?php foreach ($allUsers as $u):
                                        $name = trim((string) ($u['full_name'] ?? ''));
                                        if ($name === '') { continue; } ?>
                                        <option value="<?= htmlspecialchars($name) ?>" <?= ($vfDeptMgr === $name ? 'selected' : '') ?>>
                                            <?= htmlspecialchars($name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="checked_by">Checked By</label>
                                <?php
                                    $vfCheckedIsFinance = false;
                                    foreach (($financeUsers ?? []) as $fu) {
                                        if (isset($fu['full_name']) && $fu['full_name'] === $vfCheckedBy) {
                                            $vfCheckedIsFinance = true;
                                            break;
                                        }
                                    }
                                ?>
                                <select id="checked_by" name="checked_by" required>
                                    <option value="">— Select user —</option>
                                    <?php if ($vfCheckedBy !== '' && !$vfCheckedIsFinance): ?>
                                        <option value="<?= htmlspecialchars($vfCheckedBy) ?>" selected>(Current) <?= htmlspecialchars($vfCheckedBy) ?> — non-Finance</option>
                                    <?php endif; ?>
                                    <?php foreach (($financeUsers ?? []) as $u):
                                        $name = trim((string) ($u['full_name'] ?? ''));
                                        if ($name === '') { continue; } ?>
                                        <option value="<?= htmlspecialchars($name) ?>" <?= ($vfCheckedIsFinance && $vfCheckedBy === $name ? 'selected' : '') ?>>
                                            <?= htmlspecialchars($name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text">Only Finance department users can be selected for Checked By.</div>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <div class="cv-form-actions cv-form-actions--after-approvals">
                    <a href="<?= $vfCancelUrl ?>" class="cv-btn cv-btn-form-action">Cancel</a>
                    <?php if ($isVfEdit && $vfViewUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($vfViewUrl) ?>" class="cv-btn cv-btn-form-action">View Voucher</a>
                    <?php endif; ?>
                    <?php if (!$isVfEdit): ?>
                        <button type="button" class="cv-btn cv-btn-form-action" onclick="saveAsDraft()">Save Draft</button>
                        <button type="submit" class="cv-btn cv-btn-submit cv-btn-form-action">Create Voucher</button>
                    <?php else: ?>
                        <button type="button" class="cv-btn cv-btn-primary cv-btn-form-action" onclick="document.getElementById('voucherForm').requestSubmit();">Update Voucher</button>
                    <?php endif; ?>
                </div>

                <!-- General Manager hidden to reduce confusion; set during approval -->
                <input type="hidden" id="general_manager" name="general_manager" value="<?= htmlspecialchars($vfGm) ?>">
            </form>
                </div>
            </div>
        </div>
    </main>

    </div><!-- /.flex-grow-1 (header_employee.php) -->
    </div><!-- /.layout-main-wrapper -->

    <?php if ($isVfEdit): ?>
    <script>window.__VF_SUPPRESS_AUTO_ITEM = true;</script>
    <?php endif; ?>
    <script src="<?= htmlspecialchars($vfVoucherJs) ?>?v=11" onerror="this.dataset.err=1"></script>
    <script>
        // Hard kill any residual lock icons from cached legacy scripts
        (function () {
            function removeLocks() { document.querySelectorAll('.lock-icon').forEach(el => el.remove()); }
            document.addEventListener('DOMContentLoaded', removeLocks);
            var mo = new MutationObserver(removeLocks); mo.observe(document.documentElement, { childList: true, subtree: true });
        })();
    </script>
    <script>
        // Robust fallback: if voucher-v4.js didn't load (404/cache/CDN) or addVoucherItem is undefined,
        // try loading the legacy voucher.js; as a last resort, define a minimal inline addVoucherItem.
        (function () {
            function loadScript(src, cb) {
                try {
                    var s = document.createElement('script');
                    s.src = src;
                    s.onload = function () { try { cb && cb(null); } catch (_) { } };
                    s.onerror = function () { try { cb && cb(new Error('load-failed')); } catch (_) { } };
                    document.head.appendChild(s);
                } catch (e) { try { cb && cb(e); } catch (_) { } }
            }
            function defineMinimalVoucherFns() {
                if (typeof window.removeVoucherItem !== 'function') {
                    window.removeVoucherItem = function (id) { var el = document.getElementById(id); if (el) { el.remove(); } };
                }
                if (typeof window.calculateTotal !== 'function') {
                    window.calculateTotal = function () {
                        var total = 0;
                        document.querySelectorAll('input[name="amount[]"]').forEach(function (inp) {
                            var v = parseFloat(inp.value || '0'); if (!isNaN(v)) total += v;
                        });
                        var t = document.getElementById('total-amount'); if (t) { t.textContent = total.toFixed(2); }
                    };
                }
                if (typeof window.addVoucherItem !== 'function') {
                    window.addVoucherItem = function () {
                        try {
                            var c = document.getElementById('voucher-items-container'); if (!c) return;
                            var payee = (document.getElementById('payee_name') || {}).value || '';
                            var idx = (c.children.length || 0) + 1;
                            var div = document.createElement('div');
                            div.className = 'voucher-item no-label';
                            div.id = 'item-' + idx;
                            div.innerHTML = '\n        <div class="form-group no-label">\n            <select aria-label="Payment Type" name="payment_type[]" required>\n                <option value="">Select Type</option>\n                <option value="Bank Transfer">Bank Transfer</option>\n                <option value="Cash Payment">Cash Payment</option>\n                <option value="Cheque">Cheque</option>\n                <option value="Mobile Payment">Mobile Payment</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <select aria-label="Budget Type" name="budget_type[]" required>\n                <option value="">Select Budget</option>\n                <option value="Operational Expenses">Operational Expenses</option>\n                <option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>\n                <option value="Employee Costs">Employee Costs</option>\n                <option value="Sales &amp; Marketing">Sales &amp; Marketing</option>\n                <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>\n                <option value="Administration &amp; Management">Administration &amp; Management</option>\n                <option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option>\n                <option value="Financial Obligations">Financial Obligations</option>\n                <option value="Tax &amp; Compliance">Tax &amp; Compliance</option>\n                <option value="Others / Miscellaneous">Others / Miscellaneous</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Name" type="text" name="name[]" required placeholder="e.g. NAFIS" value="' + (payee || '') + '" readonly>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required value="" oninput="calculateTotal()" placeholder="0.00">\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Item Description" type="text" name="item_description[]" placeholder="e.g. Masks, Reflector" value="">\n        </div>\n        <button type="button" class="icon-btn icon-danger remove-item" title="Delete item" aria-label="Delete item" onclick="removeVoucherItem(\'item-' + idx + '\')" style="justify-self:end; align-self:center;">\n            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">\n                <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n            </svg>\n        </button>';
                            c.appendChild(div);
                            if (typeof window.calculateTotal === 'function') { window.calculateTotal(); }
                        } catch (e) { /* silent */ }
                    };
                }
            }
            function ensureVoucher() {
                try {
                    if (typeof window.addVoucherItem === 'function') return; // all good
                    // Try fallback to legacy file
                    loadScript('<?= htmlspecialchars($vfVoucherJsLegacy) ?>?v=1004', function () { // updated budgets list; v1004 bust
                        if (typeof window.addVoucherItem !== 'function') {
                            defineMinimalVoucherFns();
                        }
                    });
                } catch (e) { defineMinimalVoucherFns(); }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', ensureVoucher);
            } else { ensureVoucher(); }
        })();
    </script>
    <?php if ($isVfEdit): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var container = document.getElementById('voucher-items-container');
            var existingItems = <?= json_encode($vfExistingItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            var pSelect = document.getElementById('payee_select');
            var payeeHidden = document.getElementById('payee_name');
            if (pSelect && pSelect.value && typeof updatePayeeName === 'function') {
                updatePayeeName(pSelect);
            } else if (payeeHidden) {
                payeeHidden.value = <?= json_encode($vfPayeeName) ?>;
            }
            function initItems() {
                if (typeof window.addVoucherItem !== 'function') {
                    setTimeout(initItems, 50);
                    return;
                }
                if (container) container.innerHTML = '';
                if (existingItems && existingItems.length > 0) {
                    existingItems.forEach(function (item) { window.addVoucherItem(item); });
                } else {
                    window.addVoucherItem();
                }
                if (typeof window.calculateTotal === 'function') window.calculateTotal();
                if (typeof window.updateCurrencySymbol === 'function') window.updateCurrencySymbol();
            }
            initItems();
        });
        function deleteAttachment(id) {
            if (typeof Swal === 'undefined') {
                if (confirm('Delete this attachment permanently?')) { /* fallback */ }
                else return;
            }
            Swal.fire({
                title: 'Delete Attachment?',
                text: 'Are you sure you want to delete this attachment permanently?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#000',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                var formData = new FormData();
                formData.append('attachment_id', id);
                fetch('<?= htmlspecialchars($vfDeleteAttachmentUrl) ?>', { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            var item = document.getElementById('att-' + id);
                            if (item) item.remove();
                            var countInput = document.getElementById('supporting_documents');
                            if (countInput) {
                                var currentVal = parseInt(countInput.value, 10) || 0;
                                countInput.value = Math.max(0, currentVal - 1);
                            }
                            Swal.fire('Deleted!', 'The attachment has been removed.', 'success');
                        } else {
                            Swal.fire('Error', data.error || 'Failed to delete attachment', 'error');
                        }
                    })
                    .catch(function () {
                        Swal.fire('Error', 'A system error occurred.', 'error');
                    });
            });
        }
    </script>
    <?php endif; ?>
    <script>
        // Defensive enhancer: guarantee Add Item always works even if primary script failed mid-file
        document.addEventListener('DOMContentLoaded', function () {
            var addBtn = document.querySelector('.add-item');
            if (!addBtn) return;
            // If the onclick handler failed because addVoucherItem not defined, attach a safe listener
            addBtn.addEventListener('click', function (ev) {
                if (typeof window.addVoucherItem === 'function') return; // native handler will run
                console.warn('[voucher] addVoucherItem missing at click time; defining minimal fallback');
                try {
                    // Define a minimal label-free fallback then execute
                    window.addVoucherItem = function () {
                        var c = document.getElementById('voucher-items-container'); if (!c) return;
                        var payee = (document.getElementById('payee_name') || {}).value || '';
                        var idx = (c.children.length || 0) + 1;
                        var div = document.createElement('div');
                        div.className = 'voucher-item no-label';
                        div.id = 'item-' + idx;
                        div.innerHTML = '\n        <div class="form-group no-label">\n            <select aria-label="Payment Type" name="payment_type[]" required>\n                <option value="">Select Type</option>\n                <option value="Bank Transfer">Bank Transfer</option>\n                <option value="Cash Payment">Cash Payment</option>\n                <option value="Cheque">Cheque</option>\n                <option value="Mobile Payment">Mobile Payment</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <select aria-label="Budget Type" name="budget_type[]" required>\n                <option value="">Select Budget</option>\n                <option value="Operational Expenses">Operational Expenses</option>\n                <option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>\n                <option value="Employee Costs">Employee Costs</option>\n                <option value="Sales &amp; Marketing">Sales &amp; Marketing</option>\n                <option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>\n                <option value="Administration &amp; Management">Administration &amp; Management</option>\n                <option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option>\n                <option value="Financial Obligations">Financial Obligations</option>\n                <option value="Tax &amp; Compliance">Tax &amp; Compliance</option>\n                <option value="Others / Miscellaneous">Others / Miscellaneous</option>\n            </select>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Name" type="text" name="name[]" required placeholder="e.g. NAFIS" value="' + payee + '" readonly>\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required value="" oninput="calculateTotal()" placeholder="0.00">\n        </div>\n        <div class="form-group no-label">\n            <input aria-label="Item Description" type="text" name="item_description[]" placeholder="e.g. Masks, Reflector" value="">\n        </div>\n        <button type="button" class="icon-btn icon-danger remove-item" title="Delete item" aria-label="Delete item" onclick="removeVoucherItem(\'item-' + idx + '\')" style="justify-self:end; align-self:center;">\n            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">\n                <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n            </svg>\n        </button>';
                        c.appendChild(div);
                        if (typeof window.calculateTotal === 'function') { window.calculateTotal(); }
                    };
                    // Execute immediately after defining
                    window.addVoucherItem();
                } catch (e) { console.error(e); }
            }, { once: true }); // only run this shim once
        });
    </script>
    <script>
        // Fallback label fix in case a cached voucher.js still renders the old text
        (function () {
            function fixLabels() {
                try {
                    var labels = document.querySelectorAll('.voucher-item label');
                    labels.forEach(function (l) {
                        if (!l) return;
                        var t = (l.textContent || '').trim().toLowerCase();
                        if (t === 'name/description') { l.textContent = 'Name'; }
                    });
                } catch (e) { /* ignore */ }
            }
            document.addEventListener('DOMContentLoaded', function () {
                fixLabels();
                setTimeout(fixLabels, 100); // after initial addVoucherItem()
                // Also patch when user adds new items
                var addBtn = document.querySelector('.add-item');
                if (addBtn) {
                    addBtn.addEventListener('click', function () { setTimeout(fixLabels, 50); });
                }
            });
        })();
    </script>
    <script>
        function saveAsDraft() {
            try {
                // Manual draft: just persist to localStorage (no server round-trip) and show toast
                if (typeof window.saveDraft === 'function') { window.saveDraft(false); }
            } catch (e) { console && console.error && console.error(e); }
        }

        // Client-side validation and confirmation for full submission
        document.addEventListener('DOMContentLoaded', function () {
            var vfLimitedEditMode = <?= $vfLimitedEditMode ? 'true' : 'false' ?>;
            // Check for auto-open modal (from sidebar link)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('open_new_payee') || (urlParams.get('action') === 'create_payee') || (urlParams.get('action') === 'quick_add_payee')) {
                openAddPayeeModal();
            }

            var form = document.getElementById('voucherForm');
            if (!form) return;

            if (vfLimitedEditMode) {
                form.removeAttribute('onsubmit');
                window.validateForm = function () { return true; };
            }

            // Initial update of hidden field if dropdown has valid selection
            var pSelect = document.getElementById('payee_select');
            if (pSelect && pSelect.value) { updatePayeeName(pSelect); }

            form.addEventListener('submit', function (e) {
                try {
                    if (vfLimitedEditMode || (form.querySelector('input[name="limited_classification_update"]') && form.querySelector('input[name="limited_classification_update"]').value === '1')) {
                        const overlayLimited = document.getElementById('loading-overlay');
                        if (overlayLimited) {
                            overlayLimited.classList.add('is-visible');
                        }
                        return;
                    }

                    var actionField = form.querySelector('input[name="action"]');
                    var actionVal = actionField ? actionField.value : '<?= $isVfEdit ? 'update' : 'create' ?>';
                    if (actionVal === 'draft') { return; }

                    var missing = [];
                    // Check hidden field first (populated by select), fallback to select value
                    var payee = (document.getElementById('payee_name') || {}).value ||
                        (document.getElementById('payee_select') || {}).value || '';
                    var dateC = (document.getElementById('date_created') || {}).value || '';
                    var desc = (document.getElementById('description') || {}).value || '';
                    var applicant = (document.getElementById('applicant') || {}).value || '';
                    var deptMgr = (document.getElementById('department_manager') || {}).value || '';
                    var preparedBy = (document.getElementById('prepared_by') || {}).value || '';
                    var checkedBy = (document.getElementById('checked_by') || {}).value || '';

                    if (!payee.trim()) missing.push('Payee Name');
                    if (!dateC.trim()) missing.push('Date');
                    if (!desc.trim()) missing.push('Description');
                    if (!applicant.trim()) missing.push('Applicant');
                    if (!deptMgr.trim()) missing.push('Department Manager');
                    if (!preparedBy.trim()) missing.push('Prepared By');
                    if (!checkedBy.trim()) missing.push('Checked By');

                    // Validate at least one valid item
                    var types = Array.from(form.querySelectorAll('select[name="payment_type[]"], input[name="payment_type[]"]'));
                    var budgets = Array.from(form.querySelectorAll('select[name="budget_type[]"], input[name="budget_type[]"]'));
                    var names = Array.from(form.querySelectorAll('input[name="name[]"]'));
                    var amounts = Array.from(form.querySelectorAll('input[name="amount[]"]'));
                    var validCount = 0;
                    var maxLen = Math.max(types.length, budgets.length, names.length, amounts.length);
                    for (var i = 0; i < maxLen; i++) {
                        var t = (types[i] && types[i].value || '').trim();
                        var b = (budgets[i] && budgets[i].value || '').trim();
                        var n = (names[i] && names[i].value || '').trim();
                        var a = parseFloat((amounts[i] && amounts[i].value || '0').replace(/,/g, ''));
                        if (t && b && n && !isNaN(a) && a > 0) { validCount++; }
                    }
                    if (validCount === 0) {
                        Swal.fire({ icon: 'error', title: 'Invalid Items', text: 'Please add at least one item with Type, Budget, Name and Amount > 0.' });
                        e.preventDefault();
                        return;
                    }

                    if (missing.length > 0) {
                        e.preventDefault();
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Missing Information', 
                            text: 'Please complete the following before submitting: ' + missing.join(', ') 
                        });
                        return;
                    }

                    // All validations passed, show high-fidelity loading animation
                    const overlay = document.getElementById('loading-overlay');
                    if (overlay) {
                        overlay.classList.add('is-visible');
                    }

                    // Voucher Summary already provides a full pre-submit overview.
                    // Submit directly without extra confirmation popup/sheet.
                    return;

                    // SweetAlert2 Confirmation (desktop); mobile uses native bottom sheet â€” Swal sets inline styles that keep the popup centered
                    var currency = (document.getElementById('currency') || {}).value || '';
                    var totalText = (document.getElementById('total-amount') || {}).textContent || '';

                    function openNativeVoucherConfirmSheet(opts) {
                        var payee = opts.payee || '';
                        var cur = opts.currency || '';
                        var total = opts.totalText || '';
                        var onConfirm = opts.onConfirm || function () {};
                        var onCancel = opts.onCancel || function () {};
                        var backdrop = document.createElement('div');
                        backdrop.className = 'voucher-confirm-native-backdrop';
                        var sheet = document.createElement('div');
                        sheet.className = 'voucher-confirm-native-sheet';
                        sheet.setAttribute('role', 'dialog');
                        sheet.setAttribute('aria-modal', 'true');
                        sheet.setAttribute('aria-labelledby', 'vcnTitle');

                        function esc(s) {
                            var d = document.createElement('div');
                            d.textContent = s;
                            return d.innerHTML;
                        }

                        sheet.innerHTML =
                            '<div class="voucher-confirm-native-handle" aria-hidden="true"></div>' +
                            '<div style="padding:0 1rem 0.75rem;">' +
                            '<div class="voucher-confirm-native-icon" aria-hidden="true">?</div>' +
                            '<h2 id="vcnTitle" class="voucher-confirm-native-title">Confirm Submission</h2>' +
                            '<p style="margin:0 0 0.5rem; font-size:0.875rem; color:#6b7280; text-align:center;">Please confirm all details are correct before submitting.</p>' +
                            '<div class="vcn-summary">' +
                            '<div style="display:flex; justify-content:space-between; margin-bottom:6px; padding-bottom:6px; border-bottom:1px dashed #e5e7eb;">' +
                            '<span style="color:#6b7280;">Payee</span> <strong style="color:#111;">' + esc(payee) + '</strong></div>' +
                            '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                            '<span style="color:#6b7280;">Total Amount</span> <strong style="color:#059669; font-size:16px;">' + esc((cur + ' ' + total).trim()) + '</strong></div>' +
                            '</div>' +
                            '<div class="voucher-confirm-native-btns">' +
                            '<button type="button" class="vcn-cancel">Cancel</button>' +
                            '<button type="button" class="vcn-submit">Yes, Submit Voucher</button>' +
                            '</div></div>';

                        document.body.appendChild(backdrop);
                        document.body.appendChild(sheet);
                        document.body.classList.add('voucher-confirm-native-open');

                        function closeSheet() {
                            backdrop.classList.remove('is-visible');
                            sheet.classList.remove('is-visible');
                            document.body.classList.remove('voucher-confirm-native-open');
                            document.removeEventListener('keydown', onKey);
                            window.setTimeout(function () {
                                backdrop.remove();
                                sheet.remove();
                            }, 320);
                        }

                        function onKey(ev) {
                            if (ev.key === 'Escape') {
                                ev.preventDefault();
                                closeSheet();
                                onCancel();
                            }
                        }
                        document.addEventListener('keydown', onKey);

                        requestAnimationFrame(function () {
                            backdrop.classList.add('is-visible');
                            sheet.classList.add('is-visible');
                        });

                        backdrop.addEventListener('click', function () {
                            closeSheet();
                            onCancel();
                        });
                        sheet.querySelector('.vcn-cancel').addEventListener('click', function () {
                            closeSheet();
                            onCancel();
                        });
                        sheet.querySelector('.vcn-submit').addEventListener('click', function () {
                            closeSheet();
                            onConfirm();
                        });
                    }

                    if (window.matchMedia('(max-width: 767.98px)').matches) {
                        e.preventDefault();
                        openNativeVoucherConfirmSheet({
                            payee: payee,
                            currency: currency,
                            totalText: totalText,
                            onConfirm: function () {
                                form.submit();
                            },
                            onCancel: function () {}
                        });
                        return;
                    }

                    if (typeof Swal !== 'undefined') {
                        e.preventDefault(); // Stop form submission
                        
                        var htmlMsg = '<div style="text-align:left; margin-top:10px;">' +
                            '<p style="margin-bottom:8px; font-size:15px;">Please confirm all details are correct before submitting.</p>' +
                            '<div style="background:#f9fafb; padding:12px; border-radius:8px; border:1px solid #e5e7eb; font-size:14px;">' +
                            '<div style="display:flex; justify-content:space-between; margin-bottom:6px; padding-bottom:6px; border-bottom:1px dashed #e5e7eb;">' + 
                                '<span style="color:#6b7280;">Payee</span> <strong style="color:#111;">' + payee + '</strong></div>' +
                            '<div style="display:flex; justify-content:space-between; align-items:center;">' + 
                                '<span style="color:#6b7280;">Total Amount</span> <strong style="color:#059669; font-size:16px;">' + (currency || '') + ' ' + totalText + '</strong></div>' +
                            '</div></div>';

                        Swal.fire({
                            title: 'Confirm Submission',
                            html: htmlMsg,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonColor: '#10b981',
                            cancelButtonColor: '#6b7280',
                            confirmButtonText: 'Yes, Submit Voucher',
                            cancelButtonText: 'Cancel',
                            reverseButtons: true,
                            focusConfirm: false,
                            heightAuto: false,
                            scrollbarPadding: false
                        }).then((result) => {
                            if (result.isConfirmed) {
                                form.submit();
                            }
                        });
                        return; // Exit handler, waiting for promise
                        
                    } else {
                        // Fallback if Swal fails to load
                        var msg = 'Please confirm all details are correct before submitting.\n\n' +
                            'Payee: ' + payee + '\n' +
                            'Total: ' + (currency || '') + ' ' + totalText + '\n\n' +
                            'Submit this voucher?';
                        if (!confirm(msg)) {
                            e.preventDefault();
                            return;
                        }
                    }
                } catch (err) {
                    // On error, allow native required validations to run
                }
            });
        });

        // Payee Helpers
        function updatePayeeName(select) {
            if (!select) return;
            var name = '';
            if (select.selectedIndex >= 0) {
                name = select.options[select.selectedIndex].getAttribute('data-name') || '';
            }
            
            var hidden = document.getElementById('payee_name');
            if (hidden) hidden.value = name;

            // Update all existing items
            document.querySelectorAll('input[name="name[]"]').forEach(function(inp) {
                inp.value = name;
            });
            
            // Sync with global if exists
            if (typeof window.updatePayeeNames === 'function') {
                window.updatePayeeNames(name);
            }
        }

        function openAddPayeeModal() {
            document.getElementById('newPayeeModal').style.display = 'flex';
        }
        function closeAddPayeeModal() {
            document.getElementById('newPayeeModal').style.display = 'none';
        }

        function ajaxCreatePayee() {
            var name = document.getElementById('new_payee_name').value.trim();
            var type = document.getElementById('new_payee_type').value;
            var tin = document.getElementById('new_payee_tin').value.trim();
            var contactPerson = (document.getElementById('new_payee_contact_person') || {}).value ? document.getElementById('new_payee_contact_person').value.trim() : '';
            var phone = (document.getElementById('new_payee_phone') || {}).value ? document.getElementById('new_payee_phone').value.trim() : '';
            var email = (document.getElementById('new_payee_email') || {}).value ? document.getElementById('new_payee_email').value.trim() : '';
            var addressNotes = (document.getElementById('new_payee_address') || {}).value ? document.getElementById('new_payee_address').value.trim() : '';
            var contactParts = [];
            if (contactPerson) contactParts.push('Contact: ' + contactPerson);
            if (phone) contactParts.push('Phone: ' + phone);
            if (email) contactParts.push('Email: ' + email);
            if (addressNotes) contactParts.push('Address/Notes: ' + addressNotes);
            var contact = contactParts.join(' | ');

            if (!name) { 
                Swal.fire({ icon: 'error', title: 'Missing Information', text: 'Please enter a Payee Name' });
                return; 
            }
            if (!phone && !email) { 
                Swal.fire({ icon: 'error', title: 'Missing Information', text: 'Please enter Contact Details' });
                return; 
            }

            var formData = new FormData();
            formData.append('action', 'ajax_create_payee');
            formData.append('name', name);
            formData.append('type', type);
            formData.append('tin', tin);
            formData.append('contact', contact);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Add to dropdown
                        var select = document.getElementById('payee_select');
                        var opt = document.createElement('option');
                        opt.value = data.id;
                        opt.textContent = data.name;
                        opt.setAttribute('data-name', data.name);
                        opt.selected = true;
                        // Find insertion point to keep alphabetical order (optional, simply appending for now)
                        select.appendChild(opt);

                        updatePayeeName(select);
                        closeAddPayeeModal();
                        alert('Payee created successfully!');
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(e => {
                    console.error(e);
                    alert('Network error occurred');
                });
        }
        document.addEventListener('click', function (e) {
            var modal = document.getElementById('newPayeeModal');
            if (!modal) return;
            if (e.target === modal) {
                closeAddPayeeModal();
            }
        });
    </script>

    <!-- New Payee Modal -->
    <div id="newPayeeModal" class="payee-modal-overlay">
        <div class="payee-modal-card">
            <div class="payee-modal-header">
                <div class="payee-modal-title-wrap">
                    <span class="payee-modal-icon"><i class="fas fa-user-plus"></i></span>
                    <div>
                        <h3 class="payee-modal-title">Add New Payee</h3>
                        <p class="payee-modal-subtitle">Create a new supplier, customer, or beneficiary for this voucher.</p>
                    </div>
                </div>
                <button type="button" class="payee-modal-close" onclick="closeAddPayeeModal()" aria-label="Close">&times;</button>
            </div>
            <div class="payee-modal-body">
                <div class="payee-grid">
                    <div class="payee-field">
                        <label for="new_payee_name">Payee Name *</label>
                        <input type="text" id="new_payee_name" placeholder="e.g. Supplier XYZ">
                    </div>
                    <div class="payee-field">
                        <label for="new_payee_type">Payee Type *</label>
                        <select id="new_payee_type">
                            <option value="Supplier">Supplier</option>
                            <option value="Employee">Employee</option>
                            <option value="Government">Government</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="payee-field">
                        <label for="new_payee_tin">TIN (Optional)</label>
                        <input type="text" id="new_payee_tin" placeholder="e.g. 123-456-789">
                    </div>
                    <div class="payee-field">
                        <label for="new_payee_contact_person">Contact Person</label>
                        <input type="text" id="new_payee_contact_person" placeholder="e.g. John Doe">
                    </div>
                    <div class="payee-field">
                        <label for="new_payee_phone">Phone Number *</label>
                        <input type="text" id="new_payee_phone" placeholder="e.g. +255 712 345 678">
                    </div>
                    <div class="payee-field">
                        <label for="new_payee_email">Email Address *</label>
                        <input type="email" id="new_payee_email" placeholder="e.g. john.doe@example.com">
                    </div>
                    <div class="payee-field span-2">
                        <label for="new_payee_address">Address / Notes</label>
                        <textarea id="new_payee_address" rows="2" placeholder="e.g. Physical address, additional notes, or payment instructions..."></textarea>
                    </div>
                </div>
                <div class="payee-tip">
                    <i class="fas fa-info-circle"></i>
                    <span>The payee will be available immediately after saving and can be used for future vouchers.</span>
                </div>
                <div class="payee-modal-actions">
                    <button type="button" class="payee-btn payee-btn-secondary" onclick="closeAddPayeeModal()">Cancel</button>
                    <button type="button" class="payee-btn payee-btn-primary" onclick="ajaxCreatePayee()">Save Payee</button>
                </div>
            </div>
        </div>
    </div>
    </script>
    <!-- Expense Categories Modal -->
    <div id="expenseModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:white; padding:25px; border-radius:8px; width:90%; max-width:900px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:10px;">
                <h3 style="margin:0; font-size:20px; color:#1f2937;">ðŸ“‹ Budget Type Reference</h3>
                <button type="button" onclick="closeExpenseModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#6b7280;">&times;</button>
            </div>
            
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
                <!-- Category 1 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">1. Operational Expenses ðŸ§¾</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Office Rent</li>
                        <li>Utilities (Electricity, Water, Internet)</li>
                        <li>Communication (Phone, Airtime, Data)</li>
                        <li>Transport & Fuel</li>
                        <li>Stationery & Office Supplies</li>
                        <li>Repairs & Maintenance</li>
                        <li>Cleaning & Sanitation</li>
                        <li>Licenses & Permits</li>
                    </ul>
                </div>

                <!-- Category 2 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">2. Procurement & Supplies ðŸ‘·â€â™‚ï¸</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Purchase of PPE & Safety Items</li>
                        <li>Purchase of Construction Materials</li>
                        <li>Freight & Clearing Charges</li>
                        <li>Customs Duties & Taxes</li>
                        <li>Supplier Payments</li>
                        <li>Warehouse Costs (Loading, Offloading)</li>
                    </ul>
                </div>

                <!-- Category 3 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">3. Employee Costs ðŸ‘©ðŸ’¼</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Salaries & Wages</li>
                        <li>Overtime</li>
                        <li>Allowances (Travel, Meal, Field)</li>
                        <li>Commissions</li>
                        <li>NSSF & WCF Contributions</li>
                        <li>PAYE & Statutory Deductions</li>
                        <li>Staff Welfare</li>
                        <li>Training & Development</li>
                    </ul>
                </div>

                <!-- Category 4 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">4. Sales & Marketing ðŸ“¢</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Advertising & Promotion</li>
                        <li>Branding & Printing Materials</li>
                        <li>Client Gifts & CSR</li>
                        <li>Marketing Campaigns</li>
                        <li>Trade Shows / Exhibitions</li>
                    </ul>
                </div>

                <!-- Category 5 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">5. Logistics & Delivery ðŸšš</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Transport Hire / Delivery Charges</li>
                        <li>Vehicle Fuel & Maintenance</li>
                        <li>Insurance (Goods in Transit, Vehicle)</li>
                        <li>Freight Forwarding</li>
                        <li>Port Handling & Clearance Fees</li>
                    </ul>
                </div>

                <!-- Category 6 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">6. Admin & Management ðŸ’¼</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Directorsâ€™ Allowances</li>
                        <li>Professional Fees (Audit, Legal)</li>
                        <li>Consultancy Fees</li>
                        <li>Subscription & Membership Fees</li>
                        <li>Software & IT Support</li>
                        <li>Bank Charges & Service Fees</li>
                    </ul>
                </div>

                <!-- Category 7 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">7. Projects & CAPEX ðŸ—</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Renovation & Construction</li>
                        <li>Machinery & Equipment</li>
                        <li>Office Furniture & Fixtures</li>
                        <li>Vehicle Purchase</li>
                        <li>Computer & IT Equipment</li>
                    </ul>
                </div>

                 <!-- Category 8 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">8. Financial Obligations ðŸ’³</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Loan Repayments</li>
                        <li>Interest Payments</li>
                        <li>FDR Transfers</li>
                        <li>Shareholder Withdrawals</li>
                        <li>Insurance Premiums</li>
                    </ul>
                </div>

                <!-- Category 9 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">9. Tax & Compliance âš–</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>VAT Payments</li>
                        <li>Withholding Tax (WHT)</li>
                        <li>Income Tax</li>
                        <li>SDL</li>
                        <li>TRA Penalties & Fines</li>
                    </ul>
                </div>

                <!-- Category 10 -->
                <div>
                    <h4 style="color:#0f766e; margin:0 0 8px; font-size:15px; border-bottom: 2px solid #e5e7eb; padding-bottom:4px;">10. Others / Miscellaneous ðŸ¤</h4>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:#4b5563; line-height:1.6;">
                        <li>Donations & Charity</li>
                        <li>Miscellaneous Expenses</li>
                        <li>Write-offs / Bad Debts</li>
                        <li>Exchange Rate Difference</li>
                    </ul>
                </div>
            </div>

            <div style="margin-top:20px; text-align:right;">
                <button type="button" onclick="closeExpenseModal()" style="padding:10px 24px; background:#111827; color:white; border:none; border-radius:6px; cursor:pointer;">Close Reference</button>
            </div>
        </div>
    </div>

    <script>
        function openExpenseModal() {
            document.getElementById('expenseModal').style.display = 'flex';
        }
        function closeExpenseModal() {
            document.getElementById('expenseModal').style.display = 'none';
        }
        // Close on click outside
        document.getElementById('expenseModal').addEventListener('click', function(e) {
            if(e.target === this) closeExpenseModal();
        });
    </script>

    <script>
    (function () {
        var sheet = document.getElementById('voucherCreateSuccessSheet');
        var backdrop = document.getElementById('voucherCreateSuccessBackdrop');
        var btn = document.getElementById('voucherCreateSuccessDismiss');
        if (!sheet || !backdrop) return;

        var mq = window.matchMedia('(max-width: 767.98px)');
        var autoTimer;
        var dashHref = <?php echo json_encode('dashboard.php' . $voucherModuleQs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function goDashboard() {
            window.location.href = dashHref;
        }

        function openSheet() {
            if (!mq.matches) return;
            sheet.setAttribute('aria-hidden', 'false');
            document.body.classList.add('voucher-create-success-sheet-open');
            requestAnimationFrame(function () {
                backdrop.classList.add('is-visible');
                sheet.classList.add('is-visible');
            });
            window.clearTimeout(autoTimer);
            autoTimer = window.setTimeout(function () {
                closeSheet(true);
            }, 6000);
        }

        function closeSheet(fromTimer) {
            window.clearTimeout(autoTimer);
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('voucher-create-success-sheet-open');
            window.setTimeout(function () {
                if (!sheet.classList.contains('is-visible')) {
                    sheet.setAttribute('aria-hidden', 'true');
                }
                if (fromTimer) goDashboard();
            }, 350);
        }

        function init() {
            if (mq.matches) openSheet();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }

        mq.addEventListener('change', function (e) {
            if (!e.matches) {
                backdrop.classList.remove('is-visible');
                sheet.classList.remove('is-visible');
                document.body.classList.remove('voucher-create-success-sheet-open');
            }
        });

        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                window.clearTimeout(autoTimer);
                backdrop.classList.remove('is-visible');
                sheet.classList.remove('is-visible');
                document.body.classList.remove('voucher-create-success-sheet-open');
                goDashboard();
            });
        }
        backdrop.addEventListener('click', function () {
            closeSheet(true);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sheet.classList.contains('is-visible')) {
                closeSheet(true);
            }
        });
    })();
    </script>
    <script>
    (function () {
        window.handleSupportingFilesChange = function (inputEl) {
            var fileInput = inputEl || document.getElementById('supporting_files');
            var fileInfo = document.getElementById('supporting-files-selected');
            var fileIndicator = document.getElementById('supporting-files-indicator');
            var fileIndicatorText = document.getElementById('supporting-files-indicator-text');
            var fileBox = document.getElementById('supporting-files-box');
            if (!fileInput || !fileInfo) return;

            var files = Array.from(fileInput.files || []);
            if (files.length === 0) {
                fileInfo.textContent = 'No file chosen';
                if (fileIndicator) fileIndicator.classList.remove('is-visible');
                if (fileBox) fileBox.classList.remove('has-files');
                return;
            }

            if (files.length === 1) {
                fileInfo.textContent = files[0].name;
            } else {
                fileInfo.textContent = files.length + ' files selected';
            }

            if (fileIndicator) {
                if (fileIndicatorText) {
                    fileIndicatorText.textContent = files.length === 1
                        ? '1 file attached'
                        : (files.length + ' files attached');
                }
                fileIndicator.classList.add('is-visible');
            }
            if (fileBox) fileBox.classList.add('has-files');
        };

        document.addEventListener('change', function (e) {
            if (e.target && e.target.id === 'supporting_files') {
                window.handleSupportingFilesChange(e.target);
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            var fileInput = document.getElementById('supporting_files');
            if (fileInput) window.handleSupportingFilesChange(fileInput);
        });
    })();
    </script>
    <script>
    (function () {
        function initSalesOrderPicker() {
            var picker = document.getElementById('sales-order-picker');
            if (!picker) return;

            var hiddenInput = document.getElementById('linked_sales_order_ids');
            var hiddenPrimaryInput = document.getElementById('linked_sales_order_id');
            var trigger = document.getElementById('so-picker-trigger');
            var triggerLabel = document.getElementById('so-picker-trigger-label');
            var dropdown = document.getElementById('so-picker-dropdown');
            var searchInput = document.getElementById('so-picker-search');
            var results = document.getElementById('so-picker-results');
            var emptyState = document.getElementById('so-picker-empty');
            var chips = Array.from(document.querySelectorAll('#so-picker-chips [data-so-chip]'));
            var meta = document.getElementById('so-picker-meta');
            var pageSizeSelect = document.getElementById('so-picker-page-size');
            var options = Array.from(results ? results.querySelectorAll('.so-option') : []);
            var activeChip = 'all';
            var displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : '5', 10) || 5;

            function sanitize(s) {
                return String(s || '').toLowerCase();
            }

            function getSelectedIds() {
                var raw = String(hiddenInput ? hiddenInput.value : '').trim();
                if (!raw) return [];
                return raw.split(',').map(function (v) { return parseInt(v, 10) || 0; }).filter(function (v) { return v > 0; });
            }

            function setSelectedIds(ids) {
                var uniq = [];
                var seen = {};
                ids.forEach(function (id) {
                    var n = parseInt(id, 10) || 0;
                    if (n > 0 && !seen[n]) {
                        seen[n] = true;
                        uniq.push(n);
                    }
                });
                if (hiddenInput) hiddenInput.value = uniq.join(',');
                if (hiddenPrimaryInput) hiddenPrimaryInput.value = uniq.length ? String(uniq[0]) : '';
                options.forEach(function (opt) {
                    var id = parseInt(opt.getAttribute('data-so-id') || '0', 10) || 0;
                    opt.classList.toggle('is-selected', uniq.indexOf(id) !== -1);
                });
                return uniq;
            }

            function currentSelectedLabel() {
                var selectedIds = getSelectedIds();
                if (!selectedIds.length) return '';
                if (selectedIds.length === 1) {
                    var selected = options.find(function (opt) {
                        return String(opt.getAttribute('data-so-id') || '') === String(selectedIds[0]);
                    });
                    if (!selected) return '';
                    var no = selected.getAttribute('data-so-number') || '';
                    var customer = selected.getAttribute('data-so-customer') || '';
                    return (no + ' - ' + customer).trim();
                }
                return selectedIds.length + ' sales orders selected';
            }

            function updateTriggerText() {
                if (!triggerLabel) return;
                var text = currentSelectedLabel();
                triggerLabel.textContent = text || 'Search sales order by number, customer, or status...';
            }

            function closeDropdown() {
                picker.classList.remove('open');
                if (dropdown) {
                    dropdown.classList.remove('is-upward');
                    dropdown.style.left = '';
                    dropdown.style.top = '';
                    dropdown.style.maxHeight = '';
                    dropdown.style.width = '';
                }
            }

            function updateDropdownPosition() {
                if (!picker || !dropdown || !results) return;
                var rect = picker.getBoundingClientRect();
                var left = Math.max(8, rect.left);
                var viewportH = window.innerHeight || document.documentElement.clientHeight || 0;
                var gap = 8;
                var spaceBelow = Math.max(120, viewportH - rect.bottom - gap);
                var spaceAbove = Math.max(120, rect.top - gap);
                // Per request: always open upward.
                var openUpward = true;
                var top = Math.max(gap, rect.top - gap);
                var width = Math.max(280, rect.width);

                dropdown.style.left = left + 'px';
                dropdown.style.top = top + 'px';
                dropdown.style.width = width + 'px';
                dropdown.classList.toggle('is-upward', openUpward);

                var maxDrop = Math.max(220, Math.min(560, openUpward ? spaceAbove : spaceBelow));
                dropdown.style.maxHeight = maxDrop + 'px';

                var chromeHeight = 118; // search + chips + footer allowance
                var resultsMax = Math.max(130, maxDrop - chromeHeight);
                results.style.maxHeight = resultsMax + 'px';

                // For upward mode, place panel so its bottom sits above the trigger.
                var panelHeight = Math.min(maxDrop, dropdown.scrollHeight || maxDrop);
                var upwardTop = Math.max(gap, rect.top - gap - panelHeight);
                dropdown.style.top = upwardTop + 'px';
            }

            function openDropdown() {
                picker.classList.add('open');
                updateDropdownPosition();
                if (searchInput) searchInput.focus();
                applyFilters();
            }

            function toggleSelected(optionEl) {
                if (!optionEl || !hiddenInput) return;
                var targetId = parseInt(optionEl.getAttribute('data-so-id') || '0', 10) || 0;
                if (!targetId) return;
                var selectedIds = getSelectedIds();
                var idx = selectedIds.indexOf(targetId);
                if (idx === -1) {
                    selectedIds.push(targetId);
                } else {
                    selectedIds.splice(idx, 1);
                }
                setSelectedIds(selectedIds);
                updateTriggerText();
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function passesChip(optionEl) {
                if (activeChip === 'all') return true;
                return (optionEl.getAttribute('data-so-chip') || '') === activeChip;
            }

            function passesSearch(optionEl, query) {
                if (!query) return true;
                var haystack = sanitize(
                    (optionEl.getAttribute('data-so-number') || '') + ' ' +
                    (optionEl.getAttribute('data-so-customer') || '') + ' ' +
                    (optionEl.getAttribute('data-so-salesperson') || '') + ' ' +
                    (optionEl.getAttribute('data-so-status') || '')
                );
                return haystack.indexOf(query) !== -1;
            }

            function applyFilters() {
                var query = sanitize(searchInput ? searchInput.value : '').trim();
                var filtered = [];
                options.forEach(function (opt) {
                    if (passesChip(opt) && passesSearch(opt, query)) filtered.push(opt);
                });
                var visibleRows = filtered.slice(0, displayLimit);

                options.forEach(function (opt) { opt.style.display = 'none'; });
                visibleRows.forEach(function (opt) { opt.style.display = ''; });

                var visible = visibleRows.length;
                var total = filtered.length;
                if (emptyState) emptyState.style.display = total === 0 ? '' : 'none';
                if (meta) {
                    meta.textContent = total > 0
                        ? ('Showing 1 to ' + visible + ' of ' + total + ' sales orders')
                        : 'Type to search more results...';
                }
                if (picker.classList.contains('open')) updateDropdownPosition();
            }

            if (trigger) {
                trigger.addEventListener('click', function () {
                    if (picker.classList.contains('open')) {
                        closeDropdown();
                    } else {
                        openDropdown();
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : '5', 10) || 5;
                    applyFilters();
                });
            }

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    activeChip = chip.getAttribute('data-so-chip') || 'all';
                    chips.forEach(function (c) {
                        c.classList.toggle('active', c === chip);
                    });
                    displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : '5', 10) || 5;
                    applyFilters();
                });
            });

            if (pageSizeSelect) {
                pageSizeSelect.addEventListener('change', function () {
                    displayLimit = parseInt(pageSizeSelect.value || '5', 10) || 5;
                    applyFilters();
                });
            }

            options.forEach(function (opt) {
                opt.addEventListener('click', function () {
                    toggleSelected(opt);
                });
            });

            document.addEventListener('click', function (e) {
                if (!picker.contains(e.target)) {
                    closeDropdown();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeDropdown();
            });

            window.addEventListener('resize', function () {
                if (picker.classList.contains('open')) updateDropdownPosition();
            });
            document.addEventListener('scroll', function () {
                if (picker.classList.contains('open')) updateDropdownPosition();
            }, true);

            updateTriggerText();
            setSelectedIds(getSelectedIds());
            applyFilters();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSalesOrderPicker);
        } else {
            initSalesOrderPicker();
        }
    })();
    </script>
    <script>
    (function () {
        function isFilledField(el) {
            if (!el || el.disabled) return false;
            var tag = (el.tagName || '').toLowerCase();
            var type = (el.type || '').toLowerCase();

            if (type === 'hidden' || type === 'checkbox' || type === 'radio') return false;
            if (type === 'file') return !!(el.files && el.files.length > 0);
            if (tag === 'select') return String(el.value || '').trim() !== '';
            return String(el.value || '').trim() !== '';
        }

        function getCardAccent(card) {
            var dot = card ? card.querySelector('.cv-card-header .dot') : null;
            if (!dot) return '#2563eb';
            var computed = window.getComputedStyle(dot);
            return computed.backgroundColor || '#2563eb';
        }

        function toSoftAccent(color) {
            var c = (color || '').trim();
            if (c.indexOf('rgba(') === 0) return c.replace(/rgba\(([^,]+),([^,]+),([^,]+),([^)]+)\)/, 'rgba($1,$2,$3,0.22)');
            if (c.indexOf('rgb(') === 0) return c.replace(/rgb\(([^,]+),([^,]+),([^)]+)\)/, 'rgba($1,$2,$3,0.22)');
            return 'rgba(37,99,235,0.22)';
        }

        function refreshCardFields(card) {
            if (!card) return;
            var accent = getCardAccent(card);
            var softAccent = toSoftAccent(accent);
            card.style.setProperty('--card-accent', accent);
            card.style.setProperty('--card-accent-soft', softAccent);

            var fields = card.querySelectorAll('input, select, textarea');
            fields.forEach(function (field) {
                if (isFilledField(field)) {
                    field.classList.add('field-filled');
                } else {
                    field.classList.remove('field-filled');
                }
            });
        }

        function refreshAllCards() {
            document.querySelectorAll('.cv-card').forEach(refreshCardFields);
        }

        document.addEventListener('input', function (e) {
            var card = e.target && e.target.closest ? e.target.closest('.cv-card') : null;
            if (card) refreshCardFields(card);
        });

        document.addEventListener('change', function (e) {
            var card = e.target && e.target.closest ? e.target.closest('.cv-card') : null;
            if (card) refreshCardFields(card);
        });

        document.addEventListener('DOMContentLoaded', function () {
            refreshAllCards();
            var itemsContainer = document.getElementById('voucher-items-container');
            if (itemsContainer && window.MutationObserver) {
                var mo = new MutationObserver(refreshAllCards);
                mo.observe(itemsContainer, { childList: true, subtree: true });
            }
        });
    })();
    </script>
    <script>
    (function () {
        function updateVoucherSummary() {
            var payeeSelect = document.getElementById('payee_select');
            var currencySelect = document.getElementById('currency');
            var purposeSelect = document.getElementById('voucher_purpose');
            var totalNode = document.getElementById('total-amount');
            var payeeNode = document.getElementById('vs-payee');
            var currencyNode = document.getElementById('vs-currency');
            var purposeNode = document.getElementById('vs-purpose');
            var itemsNode = document.getElementById('vs-items');
            var totalSummaryNode = document.getElementById('vs-total');

            if (!payeeNode || !currencyNode || !purposeNode || !itemsNode || !totalSummaryNode) return;

            var payeeText = '-';
            if (payeeSelect && payeeSelect.selectedIndex > 0) {
                payeeText = (payeeSelect.options[payeeSelect.selectedIndex].textContent || '').trim();
            }
            payeeNode.textContent = payeeText || '-';

            var currencyCode = currencySelect ? (currencySelect.value || 'TZS') : 'TZS';
            currencyNode.textContent = currencyCode;

            var purposeText = 'General Payment';
            if (purposeSelect && purposeSelect.selectedIndex >= 0) {
                purposeText = (purposeSelect.options[purposeSelect.selectedIndex].textContent || 'General Payment').trim();
            }
            purposeNode.textContent = purposeText;

            var itemRows = document.querySelectorAll('#voucher-items-container .voucher-item:not(.server-fallback)');
            itemsNode.textContent = String(itemRows.length);

            var totalText = totalNode ? (totalNode.textContent || '0.00').trim() : '0.00';
            totalSummaryNode.textContent = currencyCode + ' ' + totalText;
        }

        function wireSummaryEvents() {
            var form = document.getElementById('voucherForm');
            var itemsContainer = document.getElementById('voucher-items-container');
            if (!form) return;

            form.addEventListener('input', updateVoucherSummary);
            form.addEventListener('change', updateVoucherSummary);

            if (itemsContainer && window.MutationObserver) {
                var mo = new MutationObserver(updateVoucherSummary);
                mo.observe(itemsContainer, { childList: true, subtree: true });
            }

            // keep summary synced after total recalculations
            var _origCalc = window.calculateTotal;
            if (typeof _origCalc === 'function') {
                window.calculateTotal = function () {
                    _origCalc.apply(this, arguments);
                    updateVoucherSummary();
                };
            }

            updateVoucherSummary();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', wireSummaryEvents);
        } else {
            wireSummaryEvents();
        }
    })();
    </script>
    <?php if (!$isVfEdit): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Swal === 'undefined') return;
        if (!window.matchMedia('(min-width: 768px)').matches) return;
        var d = <?php echo json_encode($voucherCreateSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        if (!d) return;
        var isWarn = d.variant === 'warning';
        var opts = {
            title: d.title || 'Success',
            confirmButtonColor: '#2563EB',
            confirmButtonText: 'OK',
            width: '440px',
            padding: '1.25rem'
        };
        if (isWarn) {
            opts.text = d.message || '';
            opts.icon = 'warning';
        } else {
            opts.icon = false;
            opts.html = '<div id="voucher-success-lottie-root"></div>';
            opts.didOpen = function () {
                var root = document.getElementById('voucher-success-lottie-root');
                if (!root) return;
                root.innerHTML = '';
                var wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;flex-direction:column;align-items:center;margin:20px 0;gap:20px;';
                
                var loaderHtml = '<div class="erp-loader-wrapper">' +
                                 '<span class="erp-loader"></span>' +
                                 '<div class="erp-loading-text" style="color:#BA2908;font-size:12px;">COMPLETE</div>' +
                                 '</div>';
                
                wrap.innerHTML = loaderHtml;
                
                var pMsg = document.createElement('p');
                pMsg.style.cssText = 'margin:10px 0 0;color:#1e293b;font-size:15px;line-height:1.5;font-weight:500;text-align:center;';
                pMsg.textContent = d.message || '';
                
                root.appendChild(wrap);
                root.appendChild(pMsg);
            };
        }
        Swal.fire(opts).then(function () {
            window.location.href = 'dashboard.php<?php echo htmlspecialchars($voucherModuleQs, ENT_QUOTES, 'UTF-8'); ?>';
        });
    });
    </script>
    <?php endif; ?>
</body>

</html>
