<?php declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/revenue_ledger.php';

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}
$_SESSION['active_module'] = 'revenue';

ensureRevenueSourceInvoiceSchema($pdo);
ensureRevenueLedgerSchema($pdo);

$invoiceRows = [];
try {
    $stmt = $pdo->query("
        SELECT
            i.id,
            i.invoice_number,
            i.invoice_date,
            i.customer_id,
            c.company_name AS customer_name,
            COALESCE(i.total_amount, i.total, 0) AS invoice_total,
            COALESCE(re.total_paid, 0) AS total_paid
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        LEFT JOIN revenue_entries re ON re.source_invoice_id = i.id
        ORDER BY i.id DESC
        LIMIT 250
    ");
    $invoiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $invoiceRows = [];
}

$customerRows = [];
try {
    $customerRows = $pdo->query("
        SELECT id, company_name
        FROM customers
        WHERE TRIM(COALESCE(company_name,'')) <> ''
        ORDER BY company_name ASC
        LIMIT 250
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $customerRows = [];
}

$firstInvoice = $invoiceRows[0] ?? null;
$defaultInvoiceId = (int) ($firstInvoice['id'] ?? 0);
$defaultCustomerName = (string) ($firstInvoice['customer_name'] ?? ($customerRows[0]['company_name'] ?? ''));
$defaultInvoiceNumber = (string) ($firstInvoice['invoice_number'] ?? '');
$defaultInvoiceDate = !empty($firstInvoice['invoice_date']) ? date('d M Y', strtotime((string) $firstInvoice['invoice_date'])) : 'N/A';
$defaultInvoiceTotal = (float) ($firstInvoice['invoice_total'] ?? 0);
$defaultPaid = (float) ($firstInvoice['total_paid'] ?? 0);
$defaultBalance = max(0.0, $defaultInvoiceTotal - $defaultPaid);

$employeeHeaderTitle = '';
$employeeHeaderSubtitle = '';
$employeeHeaderCenterHtml = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Credit Note - Revenue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        body.dashboard.cn-form-page { background:#f8fafc!important; color:#0f172a; font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        .cnf-wrap { max-width:none; width:calc(100% - 12px); margin:0 0 0 12px; padding:24px 24px 24px 20px; }
        .cnf-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin-bottom:16px; }
        .cnf-title { margin:0; font-size:34px; font-weight:800; line-height:1.08; }
        .cnf-bc { font-size:12px; color:#64748b; margin-top:8px; display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
        .cnf-bc a { color:#2563eb; text-decoration:none; font-weight:700; }
        .cnf-head-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .btn-cnf { border:1px solid #dbe2ea; background:#fff; color:#0f172a; border-radius:9px; font-size:13px; font-weight:700; padding:9px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
        .btn-cnf.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
        .cnf-grid { display:grid; grid-template-columns:minmax(0,1fr) 455px; gap:12px; align-items:start; }
        .cnf-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:12px; }
        .cnf-sec { margin-top:8px; }
        .cnf-sec:first-child { margin-top:0; }
        .cnf-sec-head { display:flex; align-items:center; gap:8px; margin-bottom:10px; font-size:15px; font-weight:800; color:#0f172a; }
        .cnf-num { width:22px; height:22px; border-radius:50%; background:#2563eb; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; }
        .cnf-row { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-bottom:10px; }
        .cnf-row.two { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .cnf-fg label { display:block; font-size:12px; color:#64748b; margin-bottom:5px; font-weight:700; }
        .cnf-fg label .req { color:#ef4444; margin-left:2px; }
        .cnf-inp,.cnf-sel,.cnf-ta { width:100%; border:1px solid #dbe2ea; border-radius:8px; height:38px; padding:0 10px; font-size:13px; background:#fff; }
        .cnf-ta { height:auto; min-height:76px; padding:8px 10px; resize:vertical; }
        .cnf-inp[readonly] { background:#f8fafc; color:#64748b; }
        .cnf-note-box { border:1px solid #dbe2ea; border-radius:8px; background:#f8fafc; padding:8px; font-size:12px; color:#64748b; line-height:1.45; margin-top:8px; }
        .cnf-note-box .bal { color:#16a34a; font-weight:800; }
        .cnf-items { border:1px solid #edf2f7; border-radius:10px; overflow:hidden; }
        .cnf-items table { width:100%; border-collapse:collapse; }
        .cnf-items th { background:#f8fafc; color:#64748b; font-size:11px; font-weight:800; padding:10px 8px; border-bottom:1px solid #edf2f7; text-transform:uppercase; letter-spacing:.02em; }
        .cnf-items td { border-bottom:1px solid #f1f5f9; padding:8px; font-size:12px; }
        .cnf-items td .cnf-inp,.cnf-items td .cnf-sel { height:34px; font-size:12px; }
        .cnf-items .num { text-align:right; font-variant-numeric:tabular-nums; }
        .cnf-add-item { margin-top:8px; border:1px dashed #cbd5e1; border-radius:8px; background:#fff; color:#334155; padding:7px 10px; font-size:12px; font-weight:700; }
        .cnf-upload { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .cnf-upload small { color:#64748b; font-size:11px; }
        .cnf-side-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:12px; margin-bottom:12px; }
        .cnf-side-card h4 { margin:0 0 8px; font-size:15px; font-weight:800; color:#0f172a; }
        .cnf-sum-row { display:flex; justify-content:space-between; gap:10px; font-size:12px; color:#475569; margin-bottom:7px; }
        .cnf-sum-row strong { color:#0f172a; font-weight:800; }
        .cnf-sum-row.total { margin-top:8px; padding-top:8px; border-top:1px solid #edf2f7; font-size:13px; }
        .cnf-words { margin-top:8px; border:1px solid #dbeafe; border-radius:8px; background:#eff6ff; padding:9px; font-size:12px; color:#1e3a8a; }
        .cnf-radio { display:flex; gap:8px; align-items:flex-start; margin-bottom:8px; font-size:12px; color:#334155; }
        .cnf-radio input { margin-top:2px; }
        .cnf-radio .hint { display:block; color:#64748b; font-size:11px; margin-top:2px; }
        @media (max-width: 1100px) { .cnf-grid { grid-template-columns:1fr; } }
        @media (max-width: 992px) { .cnf-wrap { width:100%; margin:0; padding:16px; } .cnf-row,.cnf-row.two { grid-template-columns:1fr; } .cnf-head { flex-direction:column; } }
    </style>
</head>
<body class="dashboard cn-form-page">
<?php require __DIR__ . '/includes/header_employee.php'; ?>
<div class="cnf-wrap">
    <form method="post" action="revenue_process.php" id="cnfForm">
        <input type="hidden" name="module" value="revenue">
        <input type="hidden" name="action" value="create_entry">
        <input type="hidden" name="payment_mode" value="Credit Note">
        <input type="hidden" name="tax_treatment" value="Exclusive">
        <input type="hidden" name="vat_rate" value="18">
        <input type="hidden" name="customer_name" id="cnfCustomerName" value="<?= h($defaultCustomerName) ?>">

        <div class="cnf-head">
            <div>
                <h1 class="cnf-title">New Credit Note</h1>
                <div class="cnf-bc">
                    <a href="revenue_entries.php?module=revenue">Revenues</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="revenue_entries.php?module=revenue">Revenue</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="revenue_credit_notes.php?module=revenue">Credit Notes</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>New Credit Note</span>
                </div>
            </div>
            <div class="cnf-head-actions">
                <a class="btn-cnf" href="revenue_credit_notes.php?module=revenue">Cancel</a>
                <button type="submit" name="approval_status" value="Pending" class="btn-cnf"><i class="far fa-floppy-disk"></i> Save as Draft</button>
                <button type="submit" name="approval_status" value="Ratified" class="btn-cnf primary"><i class="fas fa-plus"></i> Create Credit Note</button>
            </div>
        </div>

        <div class="cnf-grid">
            <div class="cnf-card">
                <div class="cnf-sec">
                    <div class="cnf-sec-head"><span class="cnf-num">1</span> Credit Note Information</div>
                    <div class="cnf-row two">
                        <div class="cnf-fg">
                            <label>Customer <span class="req">*</span></label>
                            <select class="cnf-sel" id="cnfCustomer">
                                <?php foreach ($customerRows as $c): ?>
                                    <option value="<?= h((string) $c['company_name']) ?>"<?= $defaultCustomerName === (string) $c['company_name'] ? ' selected' : '' ?>><?= h((string) $c['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cnf-fg">
                            <label>Invoice to Credit <span class="req">*</span></label>
                            <select class="cnf-sel" id="cnfInvoice" name="source_invoice_id" required>
                                <?php foreach ($invoiceRows as $inv): ?>
                                    <option value="<?= (int) $inv['id'] ?>"
                                            data-customer="<?= h((string) ($inv['customer_name'] ?? '')) ?>"
                                            data-invoice="<?= h((string) ($inv['invoice_number'] ?? '')) ?>"
                                            data-date="<?= h((string) ($inv['invoice_date'] ?? '')) ?>"
                                            data-total="<?= h(number_format((float) ($inv['invoice_total'] ?? 0), 2, '.', '')) ?>"
                                            data-paid="<?= h(number_format((float) ($inv['total_paid'] ?? 0), 2, '.', '')) ?>"
                                            <?= $defaultInvoiceId === (int) $inv['id'] ? ' selected' : '' ?>>
                                        <?= h((string) ($inv['invoice_number'] ?? 'INV-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="cnf-note-box" id="cnfInvoiceMeta">
                                Invoice Date: <strong><?= h($defaultInvoiceDate) ?></strong><br>
                                Invoice Amount: <strong>TZS <?= h(number_format($defaultInvoiceTotal, 2)) ?></strong><br>
                                Balance Due: <span class="bal">TZS <?= h(number_format($defaultBalance, 2)) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="cnf-row">
                        <div class="cnf-fg">
                            <label>Credit Note Date <span class="req">*</span></label>
                            <input class="cnf-inp" type="date" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="cnf-fg">
                            <label>Credit Note Number <span class="req">*</span></label>
                            <input class="cnf-inp" type="text" value="Auto Generated" readonly>
                        </div>
                        <div class="cnf-fg">
                            <label>Reference (Optional)</label>
                            <input class="cnf-inp" type="text" id="cnfReference" placeholder="e.g. CN reason or external ref">
                        </div>
                    </div>

                    <div class="cnf-row">
                        <div class="cnf-fg">
                            <label>Reason <span class="req">*</span></label>
                            <select class="cnf-sel" id="cnfReason">
                                <option>Returned Goods</option>
                                <option>Discount Given</option>
                                <option>Pricing Error</option>
                                <option>Credit Adjustment</option>
                            </select>
                        </div>
                        <div class="cnf-fg">
                            <label>Sub Reason</label>
                            <input class="cnf-inp" type="text" id="cnfSubReason" value="Damaged items">
                        </div>
                        <div class="cnf-fg">
                            <label>Department</label>
                            <select class="cnf-sel">
                                <option>Sales</option>
                                <option>Finance</option>
                                <option>Operations</option>
                            </select>
                        </div>
                    </div>

                    <div class="cnf-fg">
                        <label>Reason Description</label>
                        <textarea class="cnf-ta" id="cnfReasonDesc" placeholder="Describe why this credit note is issued...">Customer returned part of the goods due to quality issues.</textarea>
                    </div>
                    <input type="hidden" name="narration" id="cnfNarration">
                </div>

                <div class="cnf-sec">
                    <div class="cnf-sec-head"><span class="cnf-num">2</span> Credit Note Items</div>
                    <div class="cnf-items">
                        <table id="cnfItemsTable">
                            <thead>
                                <tr>
                                    <th style="width:36px;">#</th>
                                    <th>Item Description</th>
                                    <th style="width:78px;">Qty</th>
                                    <th style="width:110px;">Unit Price (TZS)</th>
                                    <th style="width:100px;">Discount (TZS)</th>
                                    <th style="width:90px;">Tax</th>
                                    <th style="width:120px;">Amount (TZS)</th>
                                    <th style="width:34px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="num">1</td>
                                    <td><input class="cnf-inp item-desc" value="Maize Flour 50kg"></td>
                                    <td><input class="cnf-inp item-qty" type="number" min="0" step="1" value="100"></td>
                                    <td><input class="cnf-inp item-price" type="number" min="0" step="0.01" value="2500"></td>
                                    <td><input class="cnf-inp item-discount" type="number" min="0" step="0.01" value="0"></td>
                                    <td><select class="cnf-sel item-tax"><option value="18" selected>VAT 18%</option><option value="0">No VAT</option></select></td>
                                    <td class="num item-total">250000.00</td>
                                    <td><button type="button" class="btn btn-link text-danger p-0 remove-item"><i class="fas fa-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="cnf-add-item" id="cnfAddItem"><i class="fas fa-plus"></i> Add Item</button>
                    <input type="hidden" name="amount_exclusive" id="cnfAmountExclusive" value="250000.00">
                </div>

                <div class="cnf-sec">
                    <div class="cnf-sec-head"><span class="cnf-num">3</span> Additional Information</div>
                    <div class="cnf-row two">
                        <div class="cnf-fg">
                            <label>Notes</label>
                            <textarea class="cnf-ta" id="cnfNotes">Partial return of items as agreed with the customer.</textarea>
                        </div>
                        <div class="cnf-fg">
                            <label>Attachments (Optional)</label>
                            <div class="cnf-upload">
                                <input type="file" name="attachment" class="cnf-inp" style="height:auto;padding:6px 10px;">
                                <small>PNG, JPG, PDF (max 5MB)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="cnf-side-card">
                    <h4>Credit Note Summary</h4>
                    <div class="cnf-sum-row"><span>Subtotal</span><strong id="sumSubtotal">TZS 250,000.00</strong></div>
                    <div class="cnf-sum-row"><span>Discount</span><strong id="sumDiscount">TZS 0.00</strong></div>
                    <div class="cnf-sum-row"><span>Tax (VAT 18%)</span><strong id="sumTax">TZS 45,000.00</strong></div>
                    <div class="cnf-sum-row total"><span>Total Credit Note Amount</span><strong id="sumTotal">TZS 295,000.00</strong></div>
                    <input type="hidden" name="vat_amount" id="cnfVatAmount" value="45000.00">
                    <div class="cnf-words">
                        <strong>Amount in Words</strong><br>
                        <span id="sumWords">Two Hundred Ninety Five Thousand Tanzanian Shillings Only</span>
                    </div>
                </div>

                <div class="cnf-side-card">
                    <h4>Application</h4>
                    <label class="cnf-radio">
                        <input type="radio" name="credit_application" value="apply" checked>
                        <span><strong>Apply to Invoice (Reduce Balance)</strong><span class="hint">This credit note amount will be deducted from the selected invoice balance.</span></span>
                    </label>
                    <label class="cnf-radio">
                        <input type="radio" name="credit_application" value="unapplied">
                        <span><strong>Leave as Unapplied Credit</strong><span class="hint">This will create a customer credit for future use.</span></span>
                    </label>
                </div>

                <div class="cnf-side-card">
                    <h4>Approval</h4>
                    <div class="cnf-fg" style="margin-bottom:8px;">
                        <label>Approval Required <span class="req">*</span></label>
                        <select class="cnf-sel">
                            <option selected>Yes</option>
                            <option>No</option>
                        </select>
                    </div>
                    <div class="cnf-fg" style="margin-bottom:8px;">
                        <label>Approver Role</label>
                        <select class="cnf-sel">
                            <option selected>Finance Manager</option>
                            <option>Accountant</option>
                            <option>Administrator</option>
                        </select>
                    </div>
                    <div class="cnf-fg">
                        <label>Notes for Approver (Optional)</label>
                        <textarea class="cnf-ta" style="min-height:82px;">Please review and approve this credit note.</textarea>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const invoiceEl = document.getElementById('cnfInvoice');
    const customerEl = document.getElementById('cnfCustomer');
    const customerHiddenEl = document.getElementById('cnfCustomerName');
    const invoiceMetaEl = document.getElementById('cnfInvoiceMeta');
    const tbody = document.querySelector('#cnfItemsTable tbody');
    const addBtn = document.getElementById('cnfAddItem');
    const amountExclEl = document.getElementById('cnfAmountExclusive');
    const vatAmountEl = document.getElementById('cnfVatAmount');
    const sumSubtotalEl = document.getElementById('sumSubtotal');
    const sumDiscountEl = document.getElementById('sumDiscount');
    const sumTaxEl = document.getElementById('sumTax');
    const sumTotalEl = document.getElementById('sumTotal');
    const sumWordsEl = document.getElementById('sumWords');
    const narrationEl = document.getElementById('cnfNarration');
    const reasonEl = document.getElementById('cnfReason');
    const subReasonEl = document.getElementById('cnfSubReason');
    const reasonDescEl = document.getElementById('cnfReasonDesc');
    const notesEl = document.getElementById('cnfNotes');
    const refEl = document.getElementById('cnfReference');

    function fmt(v) {
        return 'TZS ' + Number(v || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    function numberToWordsSimple(n) {
        if (!isFinite(n) || n <= 0) return 'Zero Tanzanian Shillings Only';
        const small = ['Zero','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
        const tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        function chunk(num) {
            let out = '';
            if (num >= 100) { out += small[Math.floor(num/100)] + ' Hundred '; num %= 100; }
            if (num >= 20) { out += tens[Math.floor(num/10)] + ' '; num %= 10; }
            if (num > 0) out += small[num] + ' ';
            return out.trim();
        }
        let num = Math.floor(n);
        const parts = [];
        const scales = ['','Thousand','Million','Billion'];
        let idx = 0;
        while (num > 0 && idx < scales.length) {
            const c = num % 1000;
            if (c > 0) parts.unshift(chunk(c) + (scales[idx] ? ' ' + scales[idx] : ''));
            num = Math.floor(num / 1000);
            idx++;
        }
        return parts.join(' ') + ' Tanzanian Shillings Only';
    }

    function updateInvoiceMeta() {
        if (!invoiceEl) return;
        const opt = invoiceEl.options[invoiceEl.selectedIndex];
        if (!opt) return;
        const customer = opt.dataset.customer || '';
        const dateRaw = opt.dataset.date || '';
        const total = parseFloat(opt.dataset.total || '0');
        const paid = parseFloat(opt.dataset.paid || '0');
        const bal = Math.max(0, total - paid);
        const dateTxt = dateRaw ? new Date(dateRaw).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : 'N/A';
        invoiceMetaEl.innerHTML =
            'Invoice Date: <strong>' + dateTxt + '</strong><br>' +
            'Invoice Amount: <strong>' + fmt(total) + '</strong><br>' +
            'Balance Due: <span class="bal">' + fmt(bal) + '</span>';
        if (customer && customerEl) customerEl.value = customer;
        if (customerHiddenEl && customerEl) customerHiddenEl.value = customerEl.value;
    }

    function updateRowNumbers() {
        [...tbody.querySelectorAll('tr')].forEach((tr, i) => {
            const first = tr.querySelector('td');
            if (first) first.textContent = String(i + 1);
        });
    }

    function computeTotals() {
        let subtotal = 0;
        let discount = 0;
        let tax = 0;
        [...tbody.querySelectorAll('tr')].forEach((tr) => {
            const qty = parseFloat((tr.querySelector('.item-qty') || {}).value || '0');
            const price = parseFloat((tr.querySelector('.item-price') || {}).value || '0');
            const dis = parseFloat((tr.querySelector('.item-discount') || {}).value || '0');
            const vat = parseFloat((tr.querySelector('.item-tax') || {}).value || '0');
            const lineBase = Math.max(0, (qty * price) - dis);
            const lineTax = lineBase * (vat / 100);
            const lineTotal = lineBase + lineTax;
            subtotal += lineBase;
            discount += dis;
            tax += lineTax;
            const totalCell = tr.querySelector('.item-total');
            if (totalCell) totalCell.textContent = Number(lineTotal).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        });
        const total = subtotal + tax;
        sumSubtotalEl.textContent = fmt(subtotal);
        sumDiscountEl.textContent = fmt(discount);
        sumTaxEl.textContent = fmt(tax);
        sumTotalEl.textContent = fmt(total);
        sumWordsEl.textContent = numberToWordsSimple(total);
        amountExclEl.value = subtotal.toFixed(2);
        vatAmountEl.value = tax.toFixed(2);
    }

    function addRow() {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="num">0</td>' +
            '<td><input class="cnf-inp item-desc" value=""></td>' +
            '<td><input class="cnf-inp item-qty" type="number" min="0" step="1" value="1"></td>' +
            '<td><input class="cnf-inp item-price" type="number" min="0" step="0.01" value="0"></td>' +
            '<td><input class="cnf-inp item-discount" type="number" min="0" step="0.01" value="0"></td>' +
            '<td><select class="cnf-sel item-tax"><option value="18" selected>VAT 18%</option><option value="0">No VAT</option></select></td>' +
            '<td class="num item-total">0.00</td>' +
            '<td><button type="button" class="btn btn-link text-danger p-0 remove-item"><i class="fas fa-trash"></i></button></td>';
        tbody.appendChild(tr);
        updateRowNumbers();
        computeTotals();
    }

    function syncNarration() {
        const reason = (reasonEl.value || '').trim();
        const sub = (subReasonEl.value || '').trim();
        const desc = (reasonDescEl.value || '').trim();
        const notes = (notesEl.value || '').trim();
        const ref = (refEl.value || '').trim();
        let text = 'Credit Note - ' + reason;
        if (sub) text += ' (' + sub + ')';
        if (desc) text += '. ' + desc;
        if (notes) text += ' Notes: ' + notes;
        if (ref) text += ' Ref: ' + ref;
        narrationEl.value = text;
    }

    document.addEventListener('input', function (e) {
        if (e.target.closest('#cnfItemsTable')) computeTotals();
        if (e.target === reasonEl || e.target === subReasonEl || e.target === reasonDescEl || e.target === notesEl || e.target === refEl) syncNarration();
        if (e.target === customerEl && customerHiddenEl) customerHiddenEl.value = customerEl.value;
    });
    document.addEventListener('change', function (e) {
        if (e.target === invoiceEl) updateInvoiceMeta();
        if (e.target.closest('#cnfItemsTable')) computeTotals();
        if (e.target === reasonEl) syncNarration();
        if (e.target === customerEl && customerHiddenEl) customerHiddenEl.value = customerEl.value;
    });
    document.addEventListener('click', function (e) {
        const rmBtn = e.target.closest('.remove-item');
        if (!rmBtn) return;
        const tr = rmBtn.closest('tr');
        if (!tr) return;
        if (tbody.querySelectorAll('tr').length <= 1) return;
        tr.remove();
        updateRowNumbers();
        computeTotals();
    });

    addBtn.addEventListener('click', addRow);
    updateInvoiceMeta();
    updateRowNumbers();
    computeTotals();
    syncNarration();
})();
</script>
</body>
</html>
