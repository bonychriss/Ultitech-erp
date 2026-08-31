<?php
// modules/expenses/view.php
require_once '../../includes/functions.php';
require_once __DIR__ . '/includes/balances_integration.php';
requireLogin();

$id = $_GET['id'] ?? null;
$pv_id = $_GET['pv_id'] ?? null;

global $pdo;
$expense = null;
$voucher = null;
$voucher_items = [];

if ($id) {
    $sql = "SELECT e.*, u.signature_path as creator_signature, u.full_name as creator_name
            FROM erp_expenses e 
            LEFT JOIN users u ON e.created_by = u.id
            WHERE e.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($expense) {
        $expense['category_name'] = expenses_resolve_category_name($pdo, (int) ($expense['account_id'] ?? 0));
        $expense['source_fund_name'] = expenses_resolve_source_account_name($pdo, (int) ($expense['source_account_id'] ?? 0));
    }
    if ($expense && $expense['pv_id']) $pv_id = $expense['pv_id'];
}

$attachments = [];
if ($pv_id) {
    $stmt = $pdo->prepare("SELECT pv.*, u.full_name as creator_name, u.signature_path as creator_signature FROM payment_vouchers pv LEFT JOIN users u ON pv.created_by = u.id WHERE pv.id = ?");
    $stmt->execute([$pv_id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($voucher) {
        $stmt = $pdo->prepare("SELECT * FROM voucher_items WHERE voucher_id = ? ORDER BY id");
        $stmt->execute([$pv_id]);
        $voucher_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT * FROM voucher_attachments WHERE voucher_id = ? ORDER BY id");
        $stmt->execute([$pv_id]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!$expense && !$voucher) die("Record not found.");

// -- PREPARE UNIFIED TICKET DATA --
$t_ref = $expense ? $expense['expense_number'] : ($voucher ? $voucher['voucher_no'] : 'N/A');
$t_payee = $expense ? $expense['payee'] : ($voucher ? $voucher['payee_name'] : 'N/A');
$t_amount = $expense ? $expense['amount'] : ($voucher ? $voucher['total_amount'] : 0);
$t_currency = $expense ? $expense['currency_code'] : ($voucher ? ($voucher['currency'] ?: 'TSh') : 'TSh');
$t_date = $expense ? $expense['date'] : ($voucher ? $voucher['date_created'] : date('Y-m-d'));
$t_memo = $expense ? $expense['description'] : ($voucher ? $voucher['description'] : 'N/A');
$t_method = ($expense && $expense['payment_method'] === 'voucher') || $pv_id ? 'Bank Transfer' : ($expense ? ucfirst($expense['payment_method']) : 'N/A');
$t_category = ($expense && $expense['category_name']) ? $expense['category_name'] : 'Operational Expense';
$t_source = ($expense && $expense['source_fund_name']) ? $expense['source_fund_name'] : (($voucher && $voucher['payment_account_id']) ? 'Financial Account' : 'Internal Float');

$isPaid = (isset($voucher['is_paid']) && (int)$voucher['is_paid'] === 1);
$isPosted = (isset($voucher['is_posted']) && (int)$voucher['is_posted'] === 1)
    || ($expense && (int)($expense['is_posted'] ?? 0) === 1);

$expense_sub_accounts = expenses_fetch_expense_sub_accounts($pdo);
$expenseTree = expenses_build_expense_account_tree($pdo);
$expenseMainAccounts = $expenseTree['mains'];
$expenseChildrenByParent = $expenseTree['children'];
$expenseAccountsHierarchical = $expenseTree['hierarchical'];
$payment_accounts = expenses_fetch_payment_accounts($pdo);
$financial_accounts = $payment_accounts;
$categories_list = $expense_sub_accounts;

$expensePresetMainId = '';
$expensePresetSubId = '';
if ($expense) {
    $expensePresetSubId = (string) ((int) ($expense['account_id'] ?? 0));
    $presetFa = expenses_resolve_financial_account($pdo, (int) ($expense['account_id'] ?? 0));
    if ($presetFa) {
        $expensePresetMainId = (string) ((int) ($presetFa['parent_id'] ?? 0));
    }
}

$receiptCompany = function_exists('getCompanyInfo') ? getCompanyInfo() : [];
$receiptCompanyName = trim((string) ($receiptCompany['company_name'] ?? ($_SESSION['company_name'] ?? 'Company')));
$receiptLogoUrl = function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : '';
if ($receiptLogoUrl === '') {
    $fallbackLogo = 'assets/images/Untitled.jpg';
    $fallbackDisk = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fallbackLogo);
    if (is_file($fallbackDisk)) {
        $receiptLogoUrl = function_exists('app_url') ? app_url('/' . $fallbackLogo) : '../../' . $fallbackLogo;
    }
}

$hasReceiptAttachments = !empty($attachments)
    || ($voucher && !empty($voucher['swift_document']))
    || ($expense && !empty($expense['attachment']));

// Resolve receipt signature names and image URLs (same approach as voucher view)
$authorizedByName = 'System Administrator';
$authorizedSigUrl = '';
$approvedByName = '';
$approvedSigUrl = '';
$signaturesByName = [];

$nameKey = static function ($name): string {
    return function_exists('normalizePersonNameKey')
        ? normalizePersonNameKey($name)
        : strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));
};

try {
    $uStmt = $pdo->query("SELECT LOWER(full_name) AS name, signature_path FROM users WHERE signature_path IS NOT NULL AND signature_path != ''");
    while ($uRow = $uStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $nameKey($uRow['name'] ?? '');
        $url = mediaUrlFromPath($uRow['signature_path']);
        if ($key !== '' && $url !== '') {
            $signaturesByName[$key] = $url;
        }
    }
} catch (Throwable $e) {
}

if ($pv_id) {
    try {
        $apStmt = $pdo->prepare("SELECT approver_name, signature_path FROM approvals WHERE voucher_id = ? AND status = 'approved' AND signature_path IS NOT NULL AND signature_path != ''");
        $apStmt->execute([$pv_id]);
        while ($row = $apStmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $nameKey($row['approver_name'] ?? '');
            $url = mediaUrlFromPath($row['signature_path']);
            if ($key !== '' && $url !== '') {
                $signaturesByName[$key] = $url;
            }
        }
    } catch (Throwable $e) {
    }
}

if ($voucher) {
    $authorizedByName = trim((string) ($voucher['prepared_by'] ?: ($voucher['creator_name'] ?? ''))) ?: 'System Administrator';
    $approvedByName = trim((string) ($voucher['general_manager'] ?? ''));

    $authKey = $nameKey($authorizedByName);
    if ($authKey !== '' && isset($signaturesByName[$authKey])) {
        $authorizedSigUrl = $signaturesByName[$authKey];
    }

    if ($approvedByName !== '') {
        $apprKey = $nameKey($approvedByName);
        if (isset($signaturesByName[$apprKey])) {
            $approvedSigUrl = $signaturesByName[$apprKey];
        }
    }

    if ($approvedSigUrl === '' && !empty($voucher['approved_by'])) {
        $rawPath = getUserSignaturePathById((int) $voucher['approved_by']);
        if ($rawPath) {
            $approvedSigUrl = mediaUrlFromPath($rawPath);
        }
    }
} elseif ($expense) {
    $authorizedByName = trim((string) ($expense['creator_name'] ?? '')) ?: 'System Administrator';
}

if ($authorizedSigUrl === '') {
    $rawPath = $expense['creator_signature'] ?? ($voucher['creator_signature'] ?? null);
    if ($rawPath) {
        $authorizedSigUrl = mediaUrlFromPath($rawPath);
    }
}

if ($authorizedSigUrl === '' && $authorizedByName !== 'System Administrator') {
    $rawPath = getUserSignaturePathByName($authorizedByName);
    if ($rawPath) {
        $authorizedSigUrl = mediaUrlFromPath($rawPath);
    }
}

if ($approvedSigUrl === '' && $approvedByName !== '') {
    $rawPath = getUserSignaturePathByName($approvedByName);
    if ($rawPath) {
        $approvedSigUrl = mediaUrlFromPath($rawPath);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Receipt - <?= $t_ref ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        body { background-color: #f1f5f9; font-family: 'Inter', sans-serif; color: #0f172a; line-height: 1.5; }
        .main-content { padding: 40px 20px; max-width: 1100px; margin: 0 auto; }

        .receipt-layout {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: 28px;
            margin: 0 auto;
        }
        .receipt-layout-main {
            flex: 0 1 500px;
            width: 100%;
            max-width: 500px;
        }
        
        /* PREMIUM RECEIPT TICKET */
        .receipt-container {
            background: #fff;
            width: 100%;
            max-width: 500px;
            margin: 0;
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04), 0 1px 3px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .receipt-header {
            padding: 30px 40px;
            text-align: center;
            background: #fff;
            border-bottom: 2px dashed #f1f5f9;
        }
        .receipt-header img { width: 100px; height: auto; margin-bottom: 12px; }
        .receipt-header .company-name { font-size: 0.9rem; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; }
        .receipt-header .document-type { font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; margin-top: 4px; }

        .receipt-attachments-panel {
            flex: 0 0 240px;
            max-width: 240px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding-top: 30px;
        }
        .receipt-attachments-panel .attachments-label {
            font-size: 0.65rem;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 2px;
        }
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.75rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .attachment-item .attachment-icon {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .attachment-item .attachment-meta {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }
        .attachment-item .attachment-title {
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }
        .attachment-item .attachment-sub {
            font-size: 0.62rem;
            color: #94a3b8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .attachment-item .attachment-view {
            flex-shrink: 0;
            width: 26px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            color: #475569;
            text-decoration: none;
            font-size: 0.75rem;
        }
        .attachment-item .attachment-view:hover { background: #fff; color: #0f172a; }

        /* The side cut-outs for the ticket look */
        .receipt-container::before, .receipt-container::after {
            content: "";
            position: absolute;
            top: 145px;
            width: 24px;
            height: 24px;
            background-color: #f1f5f9;
            border-radius: 50%;
            z-index: 10;
        }
        .receipt-container::before { left: -12px; border: 1px solid #e2e8f0; }
        .receipt-container::after { right: -12px; border: 1px solid #e2e8f0; }

        .receipt-body { padding: 25px 40px 40px; }
        
        .receipt-amount-section { margin-bottom: 30px; text-align: center; }
        .receipt-amount-section .label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 5px; }
        .receipt-amount-section .amount-value { font-size: 2.2rem; font-weight: 800; color: #0f172a; letter-spacing: -1px; }
        .receipt-amount-section .currency { font-size: 1rem; color: #64748b; font-weight: 600; margin-right: 5px; }
        
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .info-item { display: flex; flex-direction: column; }
        .info-item.full-width { grid-column: span 2; }
        .info-item .label { font-size: 0.65rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .info-item .value { font-size: 0.85rem; color: #1e293b; font-weight: 600; }
        
        .items-breakdown { margin-top: 10px; border-top: 1px solid #f1f5f9; padding-top: 20px; }
        .breakdown-title { font-size: 0.7rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 12px; }
        .breakdown-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.8rem; }
        .breakdown-row .desc { color: #475569; font-weight: 500; }
        .breakdown-row .amt { color: #1e293b; font-weight: 600; }

        .stamp-container {
            position: absolute;
            top: 160px;
            right: 40px;
            pointer-events: none;
            z-index: 5;
            transform: rotate(-12deg);
        }
        .stamp-box {
            border: 3px solid #16a34a;
            border-radius: 8px;
            padding: 5px 12px;
            color: #16a34a;
            text-transform: uppercase;
            font-weight: 900;
            font-size: 1.2rem;
            letter-spacing: 2px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            opacity: 0.8;
            mask-image: radial-gradient(circle, black 30%, transparent 150%);
        }

        .receipt-footer {
            padding: 30px 40px;
            background: #fafafa;
            border-top: 1px solid #f1f5f9;
        }
        .signature-grid { display: flex; justify-content: space-between; gap: 20px; }
        .sig-item { text-align: center; border-top: 1px solid #e2e8f0; padding-top: 8px; flex: 1; }
        .sig-item .sig-name { font-size: 0.75rem; font-weight: 700; color: #334155; display: block; }
        .sig-item .sig-label { font-size: 0.6rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; }

        .action-bar {
            width: 100%;
            max-width: 500px;
            margin: 25px 0 0;
            background: #fff;
            padding: 15px 25px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        
        @media (max-width: 860px) {
            .receipt-layout { flex-direction: column; align-items: center; }
            .receipt-layout-main { max-width: 500px; }
            .receipt-attachments-panel {
                flex: none;
                max-width: 500px;
                width: 100%;
                padding-top: 0;
            }
        }

        @media print { .no-print { display: none !important; } body { background: #fff; padding: 0; } .receipt-layout { display: block; } .receipt-container { box-shadow: none; border: 1px solid #000; margin-top: 0; max-width: 100%; } }
    </style>
</head>

<body>

<div class="layout-main-wrapper">
    <?php include_once '../../includes/sidebar.php'; ?>
    
    <div class="flex-grow-1 overflow-auto">
        <?php include '../../includes/header_employee.php'; ?>

        <div class="main-content">
            <div class="d-flex justify-content-between mb-4 no-print">
                <a href="index.php" class="text-decoration-none text-muted small"><i class="bi bi-chevron-left me-1"></i> Dashboard</a>
                <button onclick="window.print()" class="btn btn-sm btn-outline-dark px-3 py-1 text-uppercase fw-bold" style="font-size: 11px; letter-spacing: 1px;"><i class="bi bi-printer me-2"></i>Print Receipt</button>
            </div>

            <!-- UNIFIED PREMIUM RECEIPT TICKET -->
            <div class="receipt-layout">
                <div class="receipt-layout-main">
            <div class="receipt-container">
                <!-- PAID/POSTED STAMP -->
                <?php if ($isPosted): ?>
                <div class="stamp-container">
                    <div class="stamp-box">PAID</div>
                </div>
                <?php endif; ?>

                <div class="receipt-header">
                    <?php if ($receiptLogoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($receiptLogoUrl) ?>" alt="<?= htmlspecialchars($receiptCompanyName) ?> logo">
                    <?php endif; ?>
                    <div class="company-name"><?= htmlspecialchars($receiptCompanyName) ?></div>
                    <div class="document-type">Official Transaction Receipt</div>
                </div>
                
                <div class="receipt-body">
                    <div class="receipt-amount-section">
                        <span class="label">Total Amount Paid</span>
                        <span class="amount-value"><span class="currency"><?= $t_currency ?></span><?= number_format($t_amount, 2) ?></span>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <span class="label">Date & Time</span>
                            <span class="value"><?= date('d M, Y', strtotime($t_date)) ?> • <?= date('h:i A', strtotime($expense['created_at'] ?? 'now')) ?></span>
                        </div>
                        <div class="info-item text-end">
                            <span class="label">Reference No.</span>
                            <span class="value text-uppercase"><?= $t_ref ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Paid To (Beneficiary)</span>
                            <span class="value"><?= htmlspecialchars($t_payee) ?></span>
                        </div>
                        <div class="info-item text-end">
                            <span class="label">Payment Mode</span>
                            <span class="value"><?= $t_method ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Source Fund</span>
                            <span class="value"><?= htmlspecialchars($t_source) ?></span>
                        </div>
                        <div class="info-item text-end">
                            <span class="label">Category</span>
                            <span class="value text-truncate" style="max-width: 150px;"><?= $t_category ?></span>
                        </div>
                        <div class="info-item full-width mt-2">
                            <span class="label">Transaction Memo</span>
                            <span class="value" style="font-weight: 400; font-style: italic; color: #475569;">"<?= htmlspecialchars($t_memo) ?>"</span>
                        </div>
                    </div>

                    <!-- VOUCHER ITEM BREAKDOWN (If applicable) -->
                    <?php if (!empty($voucher_items)): ?>
                    <div class="items-breakdown">
                        <div class="breakdown-title">Itemized Breakdown</div>
                        <?php foreach ($voucher_items as $item): ?>
                        <div class="breakdown-row">
                            <span class="desc"><?= htmlspecialchars($item['name']) ?><br><small class="text-muted"><?= htmlspecialchars($item['payment_type']) ?></small></span>
                            <span class="amt"><?= $t_currency ?> <?= number_format($item['amount'], 2) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- QR Code Verification -->
                    <div class="text-center mt-5 opacity-50">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?= urlencode($t_ref . ' | ' . $t_amount) ?>" alt="QR" style="width: 60px;">
                        <div class="mt-2" style="font-size: 10px; color: #94a3b8; letter-spacing: 1px;">DIGITAL VERIFICATION TOKEN</div>
                    </div>
                </div>

                <div class="receipt-footer">
                    <div class="signature-grid">
                        <div class="sig-item">
                            <?php if ($authorizedSigUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($authorizedSigUrl) ?>" alt="Signature" style="max-height: 40px; margin-bottom: 5px; filter: contrast(1.2) brightness(0.9);" onerror="this.style.display='none';">
                            <?php else: ?>
                                <div style="height: 40px;"></div>
                            <?php endif; ?>
                            <span class="sig-name"><?= htmlspecialchars($authorizedByName) ?></span>
                            <span class="sig-label">Authorized By</span>
                        </div>
                        <?php if ($approvedByName !== ''): ?>
                        <div class="sig-item">
                            <?php if ($approvedSigUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($approvedSigUrl) ?>" alt="Signature" style="max-height: 40px; margin-bottom: 5px; filter: contrast(1.2) brightness(0.9);" onerror="this.style.display='none';">
                            <?php else: ?>
                                <div style="height: 40px;"></div>
                            <?php endif; ?>
                            <span class="sig-name"><?= htmlspecialchars($approvedByName) ?></span>
                            <span class="sig-label">Approved By</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ACTION BAR SECTION -->
            <div class="action-bar no-print">
                <div class="d-flex align-items-center">
                    <div class="icon-box bg-primary bg-opacity-10 text-primary rounded-circle p-3 me-3">
                        <i class="bi bi-shield-check fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size: 14px;">Ledger Audit State</div>
                        <div class="text-muted small">
                            <?php 
                                if ($isPosted) echo "Transaction recorded in financial records.";
                                elseif ($expense && $expense['status'] === 'pending') echo "Record awaiting administrative review.";
                                else echo "Verified. Awaiting ledger posting.";
                            ?>
                        </div>
                    </div>
                </div>
                <div id="action-buttons">
                    <?php if ($expense && $expense['status'] === 'pending' && (isAdmin() || isFinance())): ?>
                        <button onclick="approveRecord(<?= $expense['id'] ?>)" class="btn btn-warning px-4 fw-bold">Approve Record</button>
                    <?php elseif (!$isPosted): ?>
                        <button onclick="openPostModal(<?= $voucher ? $voucher['id'] : $expense['id'] ?>, '<?= $voucher ? $voucher['voucher_no'] : $expense['expense_number'] ?>', '<?= $voucher ? 'voucher' : 'receipt' ?>')" class="btn btn-post px-4 fw-bold">Post to Ledger</button>
                    <?php else: ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-4 py-2" style="font-size: 12px;"><i class="bi bi-check-circle-fill me-2"></i>POSTED</span>
                    <?php endif; ?>
                </div>
            </div>
                </div>

                <?php if ($hasReceiptAttachments): ?>
                <aside class="receipt-attachments-panel no-print">
                    <div class="attachments-label"><i class="bi bi-paperclip me-1"></i>Supporting Documents</div>

                    <?php if ($expense && $expense['attachment']): ?>
                        <?php $expProxy = '../../proxy_pdf.php?file=' . urlencode(ltrim($expense['attachment'], '/')); ?>
                        <div class="attachment-item">
                            <div class="attachment-icon bg-primary-subtle text-primary"><i class="bi bi-file-earmark-image"></i></div>
                            <div class="attachment-meta">
                                <div class="attachment-title">Digital Receipt Copy</div>
                                <div class="attachment-sub">Expense Original</div>
                            </div>
                            <a href="<?= $expProxy ?>" target="_blank" class="attachment-view" title="View"><i class="bi bi-eye"></i></a>
                        </div>
                    <?php endif; ?>

                    <?php if ($voucher && $voucher['swift_document']): ?>
                        <?php $swiftProxy = '../../proxy_pdf.php?file=' . urlencode(ltrim($voucher['swift_document'], '/')); ?>
                        <div class="attachment-item">
                            <div class="attachment-icon bg-warning-subtle text-warning"><i class="bi bi-file-earmark-pdf"></i></div>
                            <div class="attachment-meta">
                                <div class="attachment-title">Swift Document proof</div>
                                <div class="attachment-sub">Voucher Transaction</div>
                            </div>
                            <a href="<?= $swiftProxy ?>" target="_blank" class="attachment-view" title="View"><i class="bi bi-eye"></i></a>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($attachments as $att): ?>
                        <?php $attProxy = '../../proxy_pdf.php?file=' . urlencode(ltrim($att['file_path'], '/')); ?>
                        <div class="attachment-item">
                            <div class="attachment-icon bg-secondary-subtle text-secondary">
                                <i class="bi <?= (strpos($att['mime_type'], 'pdf') !== false) ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' ?>"></i>
                            </div>
                            <div class="attachment-meta">
                                <div class="attachment-title"><?= htmlspecialchars($att['original_name']) ?></div>
                                <div class="attachment-sub"><?= number_format($att['size_bytes'] / 1024, 1) ?> KB</div>
                            </div>
                            <a href="<?= $attProxy ?>" target="_blank" class="attachment-view" title="View"><i class="bi bi-eye"></i></a>
                        </div>
                    <?php endforeach; ?>
                </aside>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- POST TO LEDGER MODAL -->
<div class="modal fade" id="postingModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-dark text-white">
        <h6 class="modal-title fw-bold">Post to Ledger</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <form id="postingForm">
            <input type="hidden" id="post-v-id">
            <div class="mb-3">
                <label class="form-label small fw-bold">Voucher No.</label>
                <input type="text" id="post-v-no" class="form-control bg-light" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Payment Account (Chart of Accounts)</label>
                <select id="post-source-account" class="form-select" required>
                    <option value="">-- Select payment account --</option>
                    <?php foreach ($financial_accounts as $fa): ?>
                        <option value="<?= (int) $fa['id'] ?>"<?= $expense && (int)($expense['source_account_id'] ?? 0) === (int)$fa['id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($fa['label'] ?? $fa['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($expenseAccountsHierarchical): ?>
            <div class="mb-3">
                <label class="form-label small fw-bold">Main Expense Account</label>
                <select id="post-main-account" class="form-select" required>
                    <option value="">-- Select main account --</option>
                    <?php foreach ($expenseMainAccounts as $main): ?>
                        <option value="<?= (int) $main['id'] ?>"><?= htmlspecialchars($main['label'] ?? $main['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold">Expense Sub-account</label>
                <select id="post-category-id" class="form-select" required disabled>
                    <option value="">-- Select main account first --</option>
                </select>
            </div>
            <?php else: ?>
            <div class="mb-4">
                <label class="form-label small fw-bold">Expense Sub-account (Chart of Accounts)</label>
                <select id="post-category-id" class="form-select" required>
                    <option value="">-- Select expense sub-account --</option>
                    <?php foreach ($categories_list as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"<?= $expense && (int)($expense['account_id'] ?? 0) === (int)$cat['id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($cat['label'] ?? $cat['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="button" onclick="submitPost()" class="btn btn-dark w-100 py-2 fw-bold">Finalize Posting</button>
        </form>
      </div>
    </div>
  </div>
</div><!-- Scripts & Approval Logic -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const expenseChildrenByParent = <?= json_encode($expenseChildrenByParent, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const expensePresetMainId = <?= json_encode($expensePresetMainId) ?>;
    const expensePresetSubId = <?= json_encode($expensePresetSubId) ?>;

    function rebuildPostSubAccounts() {
        const mainSelect = document.getElementById('post-main-account');
        const subSelect = document.getElementById('post-category-id');
        if (!mainSelect || !subSelect) return;

        const mainId = String(mainSelect.value || '');
        subSelect.innerHTML = '';
        subSelect.disabled = true;

        if (!mainId) {
            subSelect.innerHTML = '<option value="">-- Select main account first --</option>';
            return;
        }

        const options = expenseChildrenByParent[mainId] || expenseChildrenByParent[parseInt(mainId, 10)] || [];
        if (!options.length) {
            subSelect.innerHTML = '<option value="">No sub-accounts under this category</option>';
            return;
        }

        subSelect.disabled = false;
        subSelect.innerHTML = '<option value="">-- Select expense sub-account --</option>';
        options.forEach(function (opt) {
            const el = document.createElement('option');
            el.value = String(opt.id);
            el.textContent = opt.label || opt.name || ('Account #' + opt.id);
            if (expensePresetSubId && String(expensePresetSubId) === String(opt.id)) {
                el.selected = true;
            }
            subSelect.appendChild(el);
        });
    }

    document.getElementById('post-main-account')?.addEventListener('change', rebuildPostSubAccounts);
    if (document.getElementById('post-main-account') && expensePresetMainId) {
        document.getElementById('post-main-account').value = String(expensePresetMainId);
        rebuildPostSubAccounts();
    }

    async function approveRecord(id) {
        const res = await fetch('api/expenses.php', { method: 'POST', body: JSON.stringify({ action: 'approve', id: id }) });
        const r = await res.json();
        if (r.success) {
            Swal.fire({ title: "Approved", text: r.message || "Posted to Chart of Accounts.", icon: "success" }).then(() => location.reload());
        }
    }
    
    let postModal = null;
    let currentSourceType = 'voucher';

    function openPostModal(id, no, sourceType = 'voucher') {
        currentSourceType = sourceType;
        document.getElementById('post-v-id').value = id;
        document.getElementById('post-v-no').value = no;
        postModal = new bootstrap.Modal(document.getElementById('postingModal'));
        postModal.show();
    }

    async function submitPost() {
        const vId = document.getElementById('post-v-id').value;
        const data = {
            voucher_id: currentSourceType === 'voucher' ? vId : null,
            expense_id: currentSourceType === 'receipt' ? vId : null,
            source_account_id: document.getElementById('post-source-account').value,
            category_id: document.getElementById('post-category-id').value
        };

        if (!data.source_account_id || !data.category_id) {
            Swal.fire("Required", "Please select both source account and category.", "warning");
            return;
        }

        Swal.fire({ title: 'Posting to Ledger...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        try {
            const res = await fetch('api/post_voucher.php', { method: 'POST', body: JSON.stringify(data) });
            const r = await res.json();
            if (r.success) {
                if(postModal) postModal.hide();
                Swal.fire({ title: "Posted!", html: `<p>Amount: ${r.posted_amount}</p><p>New Balance: ${r.remaining_balance}</p>`, icon: "success" }).then(() => location.reload());
            } else {
                Swal.fire("Error", r.error || "Failed to post.", "error");
            }
        } catch (e) {
            Swal.fire("System Error", "Communication failed.", "error");
        }
    }
</script>
</body>
</html>
