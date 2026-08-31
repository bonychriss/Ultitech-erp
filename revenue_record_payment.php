<?php declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/revenue_ledger.php';
require_once __DIR__ . '/includes/accounting_service.php';

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}
$_SESSION['active_module'] = 'revenue';

$entryId = (int) ($_GET['id'] ?? 0);
if ($entryId <= 0) {
    header('Location: revenue_entries.php?error=invalid_id');
    exit();
}

$stmt = $pdo->prepare("
    SELECT re.*,
           i.invoice_number AS linked_invoice_number,
           i.invoice_date,
           i.due_date AS invoice_due_date,
           c.company_name AS customer_name_resolved,
           c.customer_code AS customer_code_resolved
    FROM revenue_entries re
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    LEFT JOIN customers c ON c.id = i.customer_id
    WHERE re.id = ?
    LIMIT 1
");
$stmt->execute([$entryId]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entry) {
    header('Location: revenue_entries.php?error=not_found');
    exit();
}

$voucherId = (string) ($entry['voucher_number'] ?? ('REV-' . $entryId));
$invoiceNo = (string) ($entry['linked_invoice_number'] ?? $voucherId);
$customerName = trim((string) ($entry['customer_name_resolved'] ?? '')) !== '' ? (string) $entry['customer_name_resolved'] : (string) ($entry['customer_name'] ?? 'N/A');
$customerCode = (string) ($entry['customer_code_resolved'] ?? 'N/A');
$invoiceDate = !empty($entry['invoice_date']) ? date('d M Y', strtotime((string) $entry['invoice_date'])) : (!empty($entry['entry_date']) ? date('d M Y', strtotime((string) $entry['entry_date'])) : 'N/A');
$dueDate = !empty($entry['invoice_due_date']) ? date('d M Y', strtotime((string) $entry['invoice_due_date'])) : 'N/A';
$amountTotal = (float) ($entry['amount_total'] ?? 0);
$amountPaid = (float) ($entry['total_paid'] ?? 0);
try {
    $colStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_collected), 0) AS collected_sum FROM revenue_collections WHERE entry_id = ?');
    $colStmt->execute([$entryId]);
    $paidFromCollections = (float) $colStmt->fetchColumn();
    if ($paidFromCollections > 0.0001) {
        $amountPaid = $paidFromCollections;
    }
} catch (Throwable $e) {
    // revenue_collections missing â€” keep total_paid from revenue_entries
}
$amountDue = max(0.0, $amountTotal - $amountPaid);
$defaultPayment = $amountDue > 0 ? $amountDue : $amountTotal;
$paymentDate = date('Y-m-d');

// Payment Allocation chart: actual paid vs outstanding on the invoice (before this receipt)
$invAllocBase = $amountTotal > 0.0 ? $amountTotal : 1.0;
$allocPaidPct = max(0.0, min(100.0, ($amountPaid / $invAllocBase) * 100.0));
$allocRemainPct = max(0.0, min(100.0, ($amountDue / $invAllocBase) * 100.0));

$accounts = [];
try {
    $accStmt = $pdo->query("SELECT id, account_name FROM erp_bank_accounts WHERE status = 'active' ORDER BY account_name ASC");
    $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try {
        $accStmt = $pdo->query("SELECT id, name AS account_name FROM financial_accounts WHERE status = 'active' ORDER BY name ASC");
        $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $accounts = [];
    }
}

$employeeHeaderTitle = '';
$employeeHeaderSubtitle = '';
$employeeHeaderCenterHtml = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment - <?= h($voucherId) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        :root { --pr:#2563eb; --ok:#16a34a; --dn:#dc2626; --bd:#e5e7eb; --tx:#111827; --mu:#6b7280; --bg:#f9fafb; }
        body.rp-page { background: var(--bg)!important; color: var(--tx); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .rp-wrap { max-width: none; width: calc(100% - 12px); margin: 0 0 0 12px; padding: 24px 24px 24px 20px; }
        .rp-top { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:24px; }
        .rp-bc { font-size:13px; color:var(--mu); margin-bottom:8px; }
        .rp-bc a{ color:var(--pr); text-decoration:none; font-weight:600; }
        .rp-title { margin:0; font-size:34px; font-weight:800; line-height:1.08; }
        .rp-sub { margin-top:8px; color:var(--mu); font-size:14px; }
        .rp-layout { display:flex; flex-direction:column; gap:24px; }
        .rp-row-top {
            display:grid;
            grid-template-columns: minmax(0, 2.45fr) minmax(300px, 1fr);
            gap:20px;
            align-items: stretch;
        }
        .rp-row-top > .rp-card { margin-bottom: 0; min-height: 0; align-self: stretch; box-sizing: border-box; }
        .rp-row-body {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .rp-body-pair {
            display: grid;
            grid-template-columns: minmax(0, 2.45fr) minmax(300px, 1fr);
            gap: 20px;
            align-items: stretch;
        }
        .rp-body-stack {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .rp-body-col-side {
            display: flex;
            flex-direction: column;
            min-height: 0;
            align-self: stretch;
        }
        .rp-body-col-side > .rp-card-mid {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            margin-bottom: 0;
            min-height: 0;
        }
        .rp-body-col-side > .rp-card-mid .rp-alloc-note { margin-top: auto; }
        .rp-card { background:#fff; border:1px solid var(--bd); border-radius:10px; box-shadow:0 1px 2px rgba(0,0,0,.05); padding:22px; margin-bottom:24px; }
        .rp-card h3 { margin:0 0 18px; font-size:18px; font-weight:700; color:#1d4ed8; }
        .rp-form-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:18px 16px; }
        .rp-form-grid .full { grid-column: 1 / -1; }
        .rp-form-grid .span-2 { grid-column: span 2; }
        .form-label { font-size:12px; font-weight:700; color:var(--mu); margin-bottom:7px; }
        .req::after { content:" *"; color:#ef4444; }
        .form-control, .form-select { border:1px solid var(--bd); border-radius:8px; padding:11px 12px; font-size:14px; min-height:44px; }
        .rp-money { display:flex; align-items:center; border:1px solid var(--bd); border-radius:8px; overflow:hidden; }
        .rp-money span { background:#f3f4f6; color:#374151; padding:11px 12px; font-size:12px; font-weight:700; border-right:1px solid var(--bd); }
        .rp-money input { border:0; width:100%; padding:11px 12px; font-size:14px; }
        .rp-summary p { margin:0 0 12px; font-size:14px; display:flex; justify-content:space-between; gap:10px; }
        .rp-summary .amt { font-weight:700; }
        .rp-summary .paid { color:var(--ok); font-weight:800; }
        .rp-summary .bal { color:var(--dn); font-weight:800; }
        .rp-summary p:last-child { margin-bottom: 0; }
        .rp-alloc-wrap {
            display: flex;
            align-items: center;
            gap: 24px;
            margin: 0 0 16px;
            flex-wrap: wrap;
        }
        .rp-alloc-donut {
            --val: 0;
            width: 108px;
            height: 108px;
            border-radius: 50%;
            background: conic-gradient(var(--pr) calc(var(--val) * 1%), #e9edf3 0);
            position: relative;
            flex: 0 0 108px;
        }
        .rp-alloc-donut::before {
            content: "";
            position: absolute;
            inset: 14px;
            border-radius: 50%;
            background: #fff;
        }
        .rp-alloc-center {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            z-index: 1;
        }
        .rp-alloc-legend {
            display: flex;
            flex-direction: column;
            gap: 20px;
            flex: 1 1 120px;
            min-width: 0;
        }
        .rp-alloc-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .rp-alloc-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
        }
        .rp-alloc-dot.paid { background: var(--pr); }
        .rp-alloc-dot.rem { background: #cbd5e1; }
        .rp-alloc-l1 { font-size: 13px; font-weight: 700; color: #111827; margin: 0 0 2px; line-height: 1.2; }
        .rp-alloc-l2 {
            font-size: 12px;
            font-weight: 400;
            color: #6b7280;
            margin: 0;
            line-height: 1.35;
            font-variant-numeric: tabular-nums;
        }
        .rp-alloc-note {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            color: #166534;
            font-size: 12px;
            line-height: 1.45;
            padding: 12px 14px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .rp-alloc-note i {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
            font-size: 9px;
            color: #fff;
            background: #22c55e;
            border-radius: 50%;
        }
        .rp-table { width:100%; border-collapse:collapse; }
        .rp-table th { background:#f3f4f6; font-size:12px; font-weight:700; padding:12px 14px; border-bottom:1px solid var(--bd); text-align:left; }
        .rp-table td { padding:14px 14px; border-bottom:1px solid var(--bd); font-size:14px; vertical-align:middle; }
        .rp-table .num { text-align:right; font-variant-numeric:tabular-nums; }
        .rp-att { border:1px dashed #cbd5e1; border-radius:8px; padding:24px 20px; text-align:center; color:var(--mu); }
        .rp-actions { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:18px; padding-top:4px; }
        .rp-btn { border-radius:8px; border:1px solid transparent; padding:10px 14px; font-size:14px; font-weight:700; line-height:1; display:inline-flex; align-items:center; gap:8px; }
        .rp-pri{background:var(--pr); color:#fff;} .rp-suc{background:var(--ok); color:#fff;} .rp-sec{background:#fff; color:var(--tx); border-color:var(--bd);}
        .rp-subgrid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        .rp-apply-total { margin-top: 10px; text-align:right; font-size:14px; font-weight:700; color:#111827; }
        .rp-apply-total .amt { margin-left: 18px; color: var(--pr); font-weight:800; }
        @media (max-width: 992px) {
            .rp-wrap { width:100%; margin:0; padding:16px; }
            .rp-row-top { grid-template-columns: 1fr; }
            .rp-body-pair { grid-template-columns: 1fr; }
            .rp-form-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body class="dashboard rp-page">
<?php require __DIR__ . '/includes/header_employee.php'; ?>
<div class="rp-wrap">
    <div class="rp-top">
        <div>
            <div class="rp-bc">
                <a href="revenue_entries.php">Revenue Entries</a>
                <i class="fas fa-chevron-right mx-1"></i>
                <a href="revenue_details.php?id=<?= (int) $entryId ?>"><?= h($voucherId) ?></a>
                <i class="fas fa-chevron-right mx-1"></i>
                <span>Record Payment</span>
            </div>
            <h1 class="rp-title">Record Payment</h1>
            <p class="rp-sub">Record customer payment and update balances</p>
        </div>
    </div>

    <form action="revenue_process.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="collect_payment">
        <input type="hidden" name="entry_id" value="<?= (int) $entryId ?>">
        <div class="rp-layout">
            <div class="rp-row-top">
                <div class="rp-card">
                    <h3><i class="fas fa-circle-info me-2"></i>Payment Information</h3>
                    <div class="rp-form-grid">
                        <div>
                            <label class="form-label req">Payment Date</label>
                            <input type="date" name="collection_date" class="form-control" value="<?= h($paymentDate) ?>" required>
                        </div>
                        <div>
                            <label class="form-label req">Payment Method</label>
                            <select name="payment_method" class="form-select" required>
                                <option>Bank Transfer</option>
                                <option>Cash</option>
                                <option>Mobile Money</option>
                                <option>Cheque</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" value="TRF-<?= h(date('Ymd')) ?>-001">
                        </div>
                        <div>
                            <label class="form-label req">Payer Name</label>
                            <input type="text" name="payer_name" class="form-control" value="<?= h($customerName) ?>" required>
                        </div>
                        <div>
                            <label class="form-label req">Account / Bank</label>
                            <select name="account_id" class="form-select" required>
                                <option value="">Select account...</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= (int) $acc['id'] ?>"><?= h((string) $acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label req">Currency</label>
                            <select name="currency" class="form-select" required>
                                <option value="TZS" selected>TZS - Tanzanian Shilling</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label req">Payment Amount</label>
                            <div class="rp-money">
                                <span>TZS</span>
                                <input type="number" step="0.01" name="amount_collected" value="<?= h(number_format($defaultPayment, 2, '.', '')) ?>" max="<?= h(number_format($amountDue, 2, '.', '')) ?>" required>
                            </div>
                        </div>
                        <div class="span-2">
                            <label class="form-label">Payment Notes</label>
                            <textarea name="payment_notes" class="form-control" rows="3" placeholder="Add note for this payment...">Partial payment for invoice <?= h($invoiceNo) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="rp-card">
                    <h3><i class="far fa-file-lines me-2"></i>Invoice Summary</h3>
                    <p class="mb-2 d-flex justify-content-between"><span class="text-muted">Invoice Number</span><strong><?= h($invoiceNo) ?></strong></p>
                    <p class="mb-2 d-flex justify-content-between"><span class="text-muted">Customer</span><strong><?= h($customerName) ?></strong></p>
                    <p class="mb-2 d-flex justify-content-between"><span class="text-muted">Invoice Date</span><strong><?= h($invoiceDate) ?></strong></p>
                    <p class="mb-3 d-flex justify-content-between"><span class="text-muted">Due Date</span><strong><?= h($dueDate) ?></strong></p>
                    <hr>
                    <div class="rp-summary">
                        <p><span class="text-muted">Total Amount (TZS)</span><span class="amt"><?= h(number_format($amountTotal, 2)) ?></span></p>
                        <p><span class="text-muted">Total Paid (TZS)</span><span class="amt"><?= h(number_format($amountPaid, 2)) ?></span></p>
                        <p><span class="text-muted">Payment Applied</span><span class="paid"><?= h(number_format($defaultPayment, 2)) ?></span></p>
                        <p><span class="text-muted">Remaining Balance</span><span class="bal"><?= h(number_format(max(0, $amountDue - $defaultPayment), 2)) ?></span></p>
                    </div>
                </div>
            </div>

            <div class="rp-row-body">
                <div class="rp-body-pair">
                <div class="rp-body-stack">
                <div class="rp-card">
                    <h3><i class="fas fa-square-check me-2"></i>Apply Payment To</h3>
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Invoice Date</th>
                                <th>Due Date</th>
                                <th class="num">Total (TZS)</th>
                                <th class="num">Outstanding (TZS)</th>
                                <th class="num">Payment (TZS)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="checkbox" checked disabled class="me-2"><?= h($invoiceNo) ?></td>
                                <td><?= h($invoiceDate) ?></td>
                                <td><?= h($dueDate) ?></td>
                                <td class="num"><?= h(number_format($amountTotal, 2)) ?></td>
                                <td class="num" style="color:#dc2626;font-weight:700;"><?= h(number_format($amountDue, 2)) ?></td>
                                <td class="num"><?= h(number_format($defaultPayment, 2)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="rp-apply-total">
                        <span>Total Payment Applied</span>
                        <span class="amt">TZS <?= h(number_format($defaultPayment, 2)) ?></span>
                    </div>
                </div>

                    <div class="rp-subgrid">
                        <div class="rp-card mb-0">
                            <h3><i class="fas fa-paperclip me-2"></i>Attachments <small class="text-muted">(Optional)</small></h3>
                            <div class="rp-att">
                                <div class="mb-2"><i class="fas fa-cloud-arrow-up"></i></div>
                                <div>Drag and drop file here or</div>
                                <input type="file" name="payment_attachment" class="form-control mt-2">
                                <small class="d-block mt-2">Accepted: PDF/JPG/PNG (max 5MB)</small>
                            </div>
                        </div>

                        <div class="rp-card mb-0">
                            <h3><i class="far fa-note-sticky me-2"></i>Notes <small class="text-muted">(Optional)</small></h3>
                            <textarea name="internal_note" class="form-control" rows="6" placeholder="Add any additional notes..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="rp-body-col-side">
                <div class="rp-card rp-card-mid">
                    <h3>Payment Allocation</h3>
                    <div class="rp-alloc-wrap">
                        <div class="rp-alloc-donut" style="--val: <?= h(number_format($allocPaidPct, 1, '.', '')) ?>;" aria-hidden="true">
                            <div class="rp-alloc-center"><?= h(number_format($allocPaidPct, 1)) ?>%</div>
                        </div>
                        <div class="rp-alloc-legend">
                            <div class="rp-alloc-item">
                                <span class="rp-alloc-dot paid" aria-hidden="true"></span>
                                <div>
                                    <p class="rp-alloc-l1">Paid</p>
                                    <p class="rp-alloc-l2">TZS <?= h(number_format($amountPaid, 2)) ?> (<?= h(number_format($allocPaidPct, 1)) ?>%)</p>
                                </div>
                            </div>
                            <div class="rp-alloc-item">
                                <span class="rp-alloc-dot rem" aria-hidden="true"></span>
                                <div>
                                    <p class="rp-alloc-l1">Remaining</p>
                                    <p class="rp-alloc-l2">TZS <?= h(number_format($amountDue, 2)) ?> (<?= h(number_format($allocRemainPct, 1)) ?>%)</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="rp-alloc-note">
                        <i class="fas fa-check" aria-hidden="true"></i>
                        <span>This payment will be applied to the selected invoice(s) and the outstanding balance will be updated.</span>
                    </div>
                </div>
                </div>
                </div>

            </div>
        </div>

        <div class="rp-actions">
            <a href="revenue_details.php?id=<?= (int) $entryId ?>" class="rp-btn rp-sec">Cancel</a>
            <div class="d-flex gap-2">
                <button type="button" class="rp-btn rp-sec"><i class="far fa-floppy-disk"></i> Save as Draft</button>
                <button type="submit" class="rp-btn rp-pri"><i class="fas fa-circle-check"></i> Record Payment</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>

