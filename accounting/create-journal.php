<?php

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

$accounts = [];
try {
    $accounts = $pdo->query('SELECT * FROM erp_accounts ORDER BY code ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $accounts = [];
}

/**
 * Best-effort GL picks for a two-line payroll accrual template (debit expense, credit liability or cash).
 */
function journal_resolve_payroll_line_accounts(array $accounts): array
{
    $debit = null;
    $credit = null;
    foreach ($accounts as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $type = strtolower((string) ($a['type'] ?? ''));
        $blob = strtolower(trim((string) ($a['code'] ?? '') . ' ' . (string) ($a['name'] ?? '')));
        if ($type === 'expense' || preg_match('/\b(salary|salaries|wage|wages|payroll|staff\s*cost|personnel|employment)\b/u', $blob)) {
            if ($debit === null) {
                $debit = $id;
            }
        }
    }
    if ($debit === null) {
        foreach ($accounts as $a) {
            if (strtolower((string) ($a['type'] ?? '')) === 'expense') {
                $debit = (int) $a['id'];
                break;
            }
        }
    }
    $liabilityPick = null;
    foreach ($accounts as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (strtolower((string) ($a['type'] ?? '')) !== 'liability') {
            continue;
        }
        $blob = strtolower(trim((string) ($a['code'] ?? '') . ' ' . (string) ($a['name'] ?? '')));
        if (preg_match('/\b(payroll|salary|wage|payable|nssf|nhif|paye|statutory|withhold|deduction)\b/u', $blob)) {
            if ($liabilityPick === null) {
                $liabilityPick = $id;
            }
        }
    }
    if ($liabilityPick === null) {
        foreach ($accounts as $a) {
            if (strtolower((string) ($a['type'] ?? '')) === 'liability') {
                $liabilityPick = (int) $a['id'];
                break;
            }
        }
    }
    $assetCash = null;
    foreach ($accounts as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $type = strtolower((string) ($a['type'] ?? ''));
        $blob = strtolower(trim((string) ($a['code'] ?? '') . ' ' . (string) ($a['name'] ?? '')));
        if ($type === 'asset' && preg_match('/\b(bank|cash|mobile|mpesa)\b/u', $blob)) {
            $assetCash = $id;
            break;
        }
    }
    if ($assetCash === null) {
        foreach ($accounts as $a) {
            if (strtolower((string) ($a['type'] ?? '')) === 'asset') {
                $assetCash = (int) $a['id'];
                break;
            }
        }
    }
    $credit = $liabilityPick ?: $assetCash;

    return ['debit' => $debit, 'credit' => $credit];
}

$payrollLineAccounts = journal_resolve_payroll_line_accounts($accounts);

$nextSeq = 1;
try {
    $last = $pdo->query("SELECT MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)) FROM erp_journal_entries")->fetchColumn();
    $nextSeq = (int) ($last ?: 0) + 1;
} catch (Throwable $e) {
    $nextSeq = 1;
}
$previewRef = 'JE-' . date('Y') . '-' . str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);

$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$page_title = 'Create Journal Entry';

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    .employee-header { display: none !important; }
    .main-content.cje-shell {
        margin-top: 0 !important;
        padding: 14px 0 28px !important;
        background: #f9fafb;
        font-family: "Inter", "Segoe UI", Roboto, Arial, sans-serif;
        font-size: 14px;
        color: #0f172a;
    }
    .cje-inner { padding-left: 16px; padding-right: 16px; max-width: 100%; box-sizing: border-box; }
    .cje-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
    .cje-title { margin: 0; font-size: 34px; font-weight: 800; color: #0b1f5d; line-height: 1.1; }
    .cje-sub { margin: 6px 0 0; font-size: 14px; color: #64748b; }
    .cje-bc { margin-top: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13px; color: #64748b; }
    .cje-bc a { color: #2563eb; text-decoration: none; font-weight: 600; }
    .cje-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .cje-btn {
        border: 1px solid #dbe2ea;
        background: #fff;
        color: #0f172a;
        border-radius: 8px;
        padding: 9px 14px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }
    .cje-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .cje-btn.success { background: #16a34a; border-color: #16a34a; color: #fff; }
    .cje-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 14px; align-items: start; }
    .cje-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        margin-bottom: 14px;
        overflow: hidden;
    }
    .cje-card > .hd {
        padding: 12px 16px;
        border-bottom: 1px solid #eef2f7;
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .cje-card > .hd .num {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        background: #eff6ff;
        color: #2563eb;
        font-size: 13px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .cje-card .bd { padding: 16px; }
    .cje-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
    .cje-row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px 16px; }
    .cje-fg label { display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px; }
    .cje-fg label .opt { color: #94a3b8; font-weight: 500; }
    .cje-inp, .cje-sel, .cje-ta {
        width: 100%;
        border: 1px solid #dbe2ea;
        border-radius: 8px;
        padding: 0 11px;
        height: 40px;
        font-size: 14px;
        color: #0f172a;
        background: #fff;
        box-sizing: border-box;
    }
    .cje-inp[readonly] { background: #f8fafc; color: #475569; }
    .cje-ta { height: auto; min-height: 88px; padding: 10px 11px; resize: vertical; line-height: 1.45; }
    .cje-period { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .cje-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #dcfce7; color: #15803d; }
    .cje-table-wrap { overflow: auto; border: 1px solid #eef2f7; border-radius: 8px; }
    .cje-table { width: 100%; min-width: 920px; border-collapse: collapse; font-size: 13px; }
    .cje-table th, .cje-table td { border-bottom: 1px solid #eef2f7; padding: 8px 8px; vertical-align: middle; }
    .cje-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; background: #fafafa; white-space: nowrap; }
    .cje-table td .cje-inp, .cje-table td .cje-sel { height: 36px; font-size: 13px; }
    .cje-table .act { text-align: center; white-space: nowrap; }
    .cje-add { margin-top: 12px; display: inline-flex; align-items: center; gap: 6px; }
    .cje-totals { margin-top: 14px; padding: 12px 14px; background: #f8fafc; border-radius: 8px; border: 1px solid #eef2f7; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; font-weight: 600; font-size: 14px; }
    .cje-balance-bar { margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; }
    .cje-balance-bar.ok { background: #dcfce7; color: #15803d; }
    .cje-balance-bar.no { background: #fef2f2; color: #b91c1c; }
    .cje-drop {
        border: 2px dashed #cbd5e1;
        border-radius: 10px;
        padding: 22px;
        text-align: center;
        color: #64748b;
        font-size: 13px;
        background: #fafafa;
    }
    .cje-drop a { color: #2563eb; font-weight: 600; }
    .cje-side .cje-card .bd { font-size: 13px; color: #475569; line-height: 1.55; }
    .cje-side h4 { margin: 0 0 10px; font-size: 14px; font-weight: 700; color: #0f172a; }
    .cje-kv { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
    .cje-kv span:last-child { font-weight: 600; color: #0f172a; text-align: right; }
    .cje-draft-pill { display: inline-flex; padding: 3px 10px; border-radius: 999px; background: #fef3c7; color: #b45309; font-size: 11px; font-weight: 700; }
    .cje-tgl { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 12px; }
    .cje-tgl .lbl { font-weight: 600; color: #0f172a; font-size: 13px; }
    .cje-tgl .sub { font-size: 11px; color: #64748b; margin-top: 2px; }
    .cje-switch { width: 40px; height: 22px; position: relative; flex-shrink: 0; }
    .cje-switch input { display: none; }
    .cje-switch span { position: absolute; inset: 0; border-radius: 999px; background: #e2e8f0; cursor: pointer; transition: 0.2s; }
    .cje-switch span::before { content: ""; position: absolute; width: 18px; height: 18px; left: 2px; top: 2px; border-radius: 50%; background: #fff; transition: 0.2s; }
    .cje-switch input:checked + span { background: #2563eb; }
    .cje-switch input:checked + span::before { transform: translateX(18px); }
    #alertMessage { display: none; margin-bottom: 14px; padding: 12px 14px; border-radius: 8px; font-size: 14px; }
    #alertMessage.ok { display: block; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    #alertMessage.err { display: block; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .text-end { text-align: right; }
    @media (max-width: 1100px) {
        .cje-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 720px) {
        .cje-row2, .cje-row3, .cje-totals { grid-template-columns: 1fr; }
    }
</style>

<main class="main-content cje-shell">
    <div class="cje-inner">
        <div id="alertMessage" role="alert"></div>

        <div class="cje-top">
            <div>
                <h1 class="cje-title">Create Journal Entry</h1>
                <p class="cje-sub">Record a new journal entry with balanced debit and credit lines.</p>
                <nav class="cje-bc" aria-label="Breadcrumb">
                    <a href="../index.php">Home</a>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    <a href="#">Finance &amp; Accounting</a>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    <a href="journal-entries.php?module=<?= $jeModule ?>">Journal Entries</a>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    <span>Create Journal Entry</span>
                </nav>
            </div>
            <div class="cje-actions">
                <button type="button" class="cje-btn" id="btnDraft">Save as Draft</button>
                <button type="submit" form="createJournalForm" class="cje-btn success" id="postBtn"><i class="fas fa-check"></i> Post Entry</button>
                <a class="cje-btn" href="journal-entries.php?module=<?= $jeModule ?>">Cancel</a>
            </div>
        </div>

        <form id="createJournalForm" method="post" action="#" autocomplete="off">
            <input type="hidden" name="action" value="create">

            <div class="cje-grid">
                <div>
                    <section class="cje-card">
                        <div class="hd"><span class="num">1</span> Journal Entry Information</div>
                        <div class="bd">
                            <div class="cje-row3">
                                <div class="cje-fg">
                                    <label for="journal_type">Journal</label>
                                    <select class="cje-sel" id="journal_type" name="journal_type">
                                        <option value="general">General Journal</option>
                                        <option value="sales">Sales Journal</option>
                                        <option value="bank">Bank Journal</option>
                                        <option value="purchase">Purchase Journal</option>
                                        <option value="payment">Payment Voucher</option>
                                        <option value="payroll">Payroll Journal</option>
                                    </select>
                                </div>
                                <div class="cje-fg">
                                    <label for="reference_preview">Reference No.</label>
                                    <input class="cje-inp" id="reference_preview" type="text" value="<?= htmlspecialchars($previewRef) ?>" readonly aria-readonly="true">
                                </div>
                                <div class="cje-fg">
                                    <label for="transaction_date">Transaction date</label>
                                    <input class="cje-inp" id="transaction_date" name="date" type="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                                </div>
                            </div>
                            <div class="cje-row2" style="margin-top:14px;">
                                <div class="cje-fg">
                                    <label for="posting_date">Posting date</label>
                                    <input class="cje-inp" id="posting_date" type="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                                </div>
                                <div class="cje-fg">
                                    <label>Period</label>
                                    <div class="cje-period">
                                        <input class="cje-inp" id="period_display" type="text" value="<?= htmlspecialchars(date('F Y')) ?>" readonly style="flex:1;min-width:0;">
                                        <span class="cje-badge">Open</span>
                                    </div>
                                </div>
                            </div>
                            <div class="cje-fg" style="margin-top:14px;">
                                <label for="description">Description</label>
                                <textarea class="cje-ta" id="description" name="description" rows="3" placeholder="Main description for this journal entry"></textarea>
                            </div>
                            <div class="cje-fg" style="margin-top:14px;">
                                <label for="notes">Notes <span class="opt">(optional)</span></label>
                                <textarea class="cje-ta" id="notes" name="notes" rows="2" placeholder="Internal notes"></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="cje-card">
                        <div class="hd"><span class="num">2</span> Journal Entry Lines</div>
                        <div class="bd">
                            <div class="cje-table-wrap">
                                <table class="cje-table" id="linesTable">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;">#</th>
                                            <th>Account</th>
                                            <th>Description</th>
                                            <th class="text-end" style="min-width:110px;">Debit (TZS)</th>
                                            <th class="text-end" style="min-width:110px;">Credit (TZS)</th>
                                            <th>Department</th>
                                            <th>Project</th>
                                            <th class="act">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="lineItems"></tbody>
                                </table>
                            </div>
                            <button type="button" class="cje-btn cje-add" id="addLineBtn"><i class="fas fa-plus"></i> Add line</button>
                            <div class="cje-totals">
                                <div>Total Debit: <span id="totalDebit">0.00</span></div>
                                <div>Total Credit: <span id="totalCredit">0.00</span></div>
                                <div>Difference: <span id="totalDiff">0.00</span></div>
                            </div>
                            <div id="balanceBar" class="cje-balance-bar no">Debits and credits are not balanced yet.</div>
                        </div>
                    </section>

                    <section class="cje-card">
                        <div class="hd"><span class="num">3</span> Attachments <span class="opt" style="font-weight:500;color:#94a3b8;">(optional)</span></div>
                        <div class="bd">
                            <div class="cje-drop">
                                <i class="fas fa-cloud-arrow-up" style="font-size:22px;margin-bottom:8px;color:#94a3b8;"></i><br>
                                Drag and drop files here or <a href="#" id="browseFiles">browse files</a><br>
                                <span style="font-size:12px;">PDF, JPG, PNG, Excel — max 20MB each (stored locally in browser for this session only).</span>
                            </div>
                            <input type="file" id="attachmentInput" multiple accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx" style="display:none;">
                        </div>
                    </section>
                </div>

                <aside class="cje-side">
                    <div class="cje-card">
                        <div class="hd">Journal Summary</div>
                        <div class="bd">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                                <span class="cje-draft-pill" id="sumStatus">Draft</span>
                            </div>
                            <div class="cje-kv"><span>Journal</span><span id="sumJournal">General Journal</span></div>
                            <div class="cje-kv"><span>Reference</span><span id="sumRef"><?= htmlspecialchars($previewRef) ?></span></div>
                            <div class="cje-kv"><span>Transaction date</span><span id="sumDate"><?= htmlspecialchars(date('d/m/Y')) ?></span></div>
                            <div class="cje-kv"><span>Period</span><span id="sumPeriod"><?= htmlspecialchars(date('F Y')) ?></span></div>
                            <div class="cje-kv" style="align-items:flex-start;"><span>Description</span><span id="sumDesc" style="font-weight:500;max-width:180px;">—</span></div>
                        </div>
                    </div>
                    <div class="cje-card">
                        <div class="hd">Entry Settings</div>
                        <div class="bd">
                            <div class="cje-kv"><span>Currency</span><span>TZS</span></div>
                            <div class="cje-kv"><span>Exchange rate</span><span>1.00</span></div>
                            <div class="cje-tgl">
                                <div><div class="lbl">Multi currency</div><div class="sub">Post in foreign currency.</div></div>
                                <label class="cje-switch"><input type="checkbox" id="togMulti"><span></span></label>
                            </div>
                            <div class="cje-tgl">
                                <div><div class="lbl">Allow tax</div><div class="sub">Enable tax lines on entry.</div></div>
                                <label class="cje-switch"><input type="checkbox" id="togTax"><span></span></label>
                            </div>
                            <div class="cje-tgl" style="margin-bottom:0;">
                                <div><div class="lbl">Recurring entry</div><div class="sub">Repeat on a schedule.</div></div>
                                <label class="cje-switch"><input type="checkbox" id="togRecur"><span></span></label>
                            </div>
                        </div>
                    </div>
                    <div class="cje-card">
                        <div class="hd">Audit Information</div>
                        <div class="bd">
                            <div class="cje-kv"><span>Created by</span><span><?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'User')) ?></span></div>
                            <div class="cje-kv"><span>Created on</span><span><?= htmlspecialchars(date('d M Y h:i A')) ?></span></div>
                            <div class="cje-kv"><span>Last modified by</span><span>—</span></div>
                            <div class="cje-kv"><span>Last modified on</span><span>—</span></div>
                            <div class="cje-kv"><span>Posted by</span><span>—</span></div>
                            <div class="cje-kv" style="margin-bottom:0;"><span>Posted on</span><span>—</span></div>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</main>

<script>
(() => {
    const accounts = <?= json_encode($accounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const payrollLineAccounts = <?= json_encode($payrollLineAccounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const journalLabels = {
        general: 'General Journal',
        sales: 'Sales Journal',
        bank: 'Bank Journal',
        purchase: 'Purchase Journal',
        payment: 'Payment Voucher',
        payroll: 'Payroll Journal'
    };
    let lineCount = 0;
    const tbody = document.getElementById('lineItems');
    const form = document.getElementById('createJournalForm');
    const alertEl = document.getElementById('alertMessage');

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
    }

    function accountOptions() {
        if (!accounts.length) {
            return '<option value="">No accounts found</option>';
        }
        return '<option value="">Select account</option>' + accounts.map(a =>
            `<option value="${esc(a.id)}">${esc(a.code)} — ${esc(a.name)}</option>`
        ).join('');
    }

    function addLine() {
        const i = lineCount++;
        const tr = document.createElement('tr');
        tr.className = 'je-line';
        tr.dataset.lineIndex = String(i);
        tr.innerHTML = `
            <td class="line-no">1</td>
            <td><select class="cje-sel" name="items[${i}][account_id]" required>${accountOptions()}</select></td>
            <td><input class="cje-inp" type="text" name="items[${i}][line_desc]" placeholder="Line description"></td>
            <td><input class="cje-inp text-end debit-inp" type="number" step="0.01" name="items[${i}][debit]" placeholder="0.00" min="0"></td>
            <td><input class="cje-inp text-end credit-inp" type="number" step="0.01" name="items[${i}][credit]" placeholder="0.00" min="0"></td>
            <td><select class="cje-sel" name="items[${i}][department]"><option value="">—</option><option>Operations</option><option>Finance</option><option>Sales</option></select></td>
            <td><select class="cje-sel" name="items[${i}][project]"><option value="">—</option><option>General</option><option>Project A</option><option>Project B</option></select></td>
            <td class="act"><button type="button" class="cje-btn" style="padding:6px 10px;" title="Remove line" data-remove><i class="fas fa-trash"></i></button></td>`;
        tbody.appendChild(tr);
        tr.querySelector('[data-remove]').addEventListener('click', () => { tr.remove(); renumber(); calculateTotals(); });
        tr.querySelectorAll('.debit-inp, .credit-inp').forEach(inp => inp.addEventListener('input', calculateTotals));
        tr.querySelectorAll('.debit-inp').forEach(inp => inp.addEventListener('input', () => {
            if (parseFloat(inp.value) > 0) {
                const c = tr.querySelector('.credit-inp');
                if (c) c.value = '';
            }
        }));
        tr.querySelectorAll('.credit-inp').forEach(inp => inp.addEventListener('input', () => {
            if (parseFloat(inp.value) > 0) {
                const d = tr.querySelector('.debit-inp');
                if (d) d.value = '';
            }
        }));
        renumber();
        calculateTotals();
    }

    function renumber() {
        document.querySelectorAll('#lineItems tr').forEach((tr, idx) => {
            const c = tr.querySelector('.line-no');
            if (c) c.textContent = String(idx + 1);
        });
    }

    function linesArePristine() {
        const rows = document.querySelectorAll('#lineItems tr');
        if (!rows.length) {
            return true;
        }
        for (const tr of rows) {
            const acc = tr.querySelector('select[name*="[account_id]"]');
            const d = parseFloat(tr.querySelector('.debit-inp')?.value || '0') || 0;
            const c = parseFloat(tr.querySelector('.credit-inp')?.value || '0') || 0;
            if (acc && acc.value) {
                return false;
            }
            if (d > 0 || c > 0) {
                return false;
            }
        }
        return true;
    }

    function applyPayrollTemplate() {
        const desc = document.getElementById('description');
        if (desc && !desc.value.trim()) {
            desc.value = 'Payroll for the period — gross salaries and payroll accrual (enter amounts to match your payslip; split deductions on extra lines if needed).';
        }
        tbody.innerHTML = '';
        lineCount = 0;
        addLine();
        addLine();
        const rows = document.querySelectorAll('#lineItems tr');
        if (rows[0]) {
            const s0 = rows[0].querySelector('select[name*="[account_id]"]');
            if (s0 && payrollLineAccounts.debit) {
                s0.value = String(payrollLineAccounts.debit);
            }
            const ld0 = rows[0].querySelector('input[name*="[line_desc]"]');
            if (ld0) {
                ld0.value = 'Salaries & wages expense (gross)';
            }
        }
        if (rows[1]) {
            const s1 = rows[1].querySelector('select[name*="[account_id]"]');
            if (s1 && payrollLineAccounts.credit) {
                s1.value = String(payrollLineAccounts.credit);
            }
            const ld1 = rows[1].querySelector('input[name*="[line_desc]"]');
            if (ld1) {
                ld1.value = 'Payroll payable / bank (net or clearing — adjust to chart)';
            }
        }
        calculateTotals();
    }

    function calculateTotals() {
        let td = 0, tc = 0;
        document.querySelectorAll('#lineItems tr').forEach(tr => {
            const d = tr.querySelector('.debit-inp');
            const c = tr.querySelector('.credit-inp');
            td += parseFloat(d && d.value ? d.value : '0') || 0;
            tc += parseFloat(c && c.value ? c.value : '0') || 0;
        });
        document.getElementById('totalDebit').textContent = td.toFixed(2);
        document.getElementById('totalCredit').textContent = tc.toFixed(2);
        const diff = td - tc;
        document.getElementById('totalDiff').textContent = diff.toFixed(2);
        const bar = document.getElementById('balanceBar');
        const ok = Math.abs(diff) < 0.01 && td > 0;
        bar.className = 'cje-balance-bar ' + (ok ? 'ok' : 'no');
        bar.textContent = ok ? 'Debits and credits are balanced.' : 'Debits and credits are not balanced yet.';
        document.getElementById('postBtn').disabled = !ok;
    }

    function syncSummary() {
        const j = document.getElementById('journal_type');
        const key = j ? j.value : 'general';
        document.getElementById('sumJournal').textContent = journalLabels[key] || key;
        const dt = document.getElementById('transaction_date');
        if (dt && dt.value) {
            const d = new Date(dt.value + 'T12:00:00');
            document.getElementById('sumDate').textContent = d.toLocaleDateString('en-GB');
        }
        const desc = (document.getElementById('description') && document.getElementById('description').value.trim()) || '—';
        document.getElementById('sumDesc').textContent = desc.length > 120 ? desc.slice(0, 117) + '…' : desc;
    }

    document.getElementById('addLineBtn').addEventListener('click', addLine);

    ['transaction_date', 'description'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', syncSummary);
        if (el) el.addEventListener('change', syncSummary);
    });

    const journalSel = document.getElementById('journal_type');
    if (journalSel) {
        journalSel.addEventListener('change', function() {
            if (this.value === 'payroll' && linesArePristine()) {
                applyPayrollTemplate();
            }
            syncSummary();
        });
    }

    document.getElementById('browseFiles').addEventListener('click', (e) => {
        e.preventDefault();
        document.getElementById('attachmentInput').click();
    });

    document.getElementById('btnDraft').addEventListener('click', () => {
        const payload = {
            journal_type: document.getElementById('journal_type').value,
            date: document.getElementById('transaction_date').value,
            description: document.getElementById('description').value,
            notes: document.getElementById('notes').value,
            lines: Array.from(document.querySelectorAll('#lineItems tr')).map(tr => ({
                account_id: tr.querySelector('select[name*="[account_id]"]')?.value,
                debit: tr.querySelector('.debit-inp')?.value,
                credit: tr.querySelector('.credit-inp')?.value
            }))
        };
        try {
            localStorage.setItem('journal_entry_draft', JSON.stringify(payload));
        } catch (err) {}
        alertEl.className = 'ok';
        alertEl.textContent = 'Draft saved in this browser. Use Post Entry to submit to the server.';
        alertEl.style.display = 'block';
    });

    function buildDescription() {
        const j = document.getElementById('journal_type');
        const jLabel = journalLabels[j.value] || j.value;
        let body = (document.getElementById('description').value || '').trim();
        const notes = (document.getElementById('notes').value || '').trim();
        const prefix = '[' + jLabel + ']';
        if (notes) {
            body = body + (body ? '\n\n' : '') + 'Notes: ' + notes;
        }
        return prefix + (body ? '\n' + body : '');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        alertEl.style.display = 'none';
        alertEl.className = '';
        const postBtn = document.getElementById('postBtn');
        postBtn.disabled = true;
        const prevLabel = postBtn.innerHTML;
        postBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting…';

        const fd = new FormData();
        fd.append('action', 'create');
        fd.append('date', document.getElementById('transaction_date').value);
        fd.append('description', buildDescription());

        const rows = Array.from(document.querySelectorAll('#lineItems tr'));
        let idx = 0;
        rows.forEach(tr => {
            const acc = tr.querySelector('select[name*="[account_id]"]');
            const deb = tr.querySelector('.debit-inp');
            const cre = tr.querySelector('.credit-inp');
            if (!acc || !acc.value) return;
            const d = parseFloat(deb && deb.value ? deb.value : '0') || 0;
            const c = parseFloat(cre && cre.value ? cre.value : '0') || 0;
            if (d <= 0 && c <= 0) return;
            fd.append('items[' + idx + '][account_id]', acc.value);
            fd.append('items[' + idx + '][debit]', String(d));
            fd.append('items[' + idx + '][credit]', String(c));
            idx++;
        });

        try {
            const response = await fetch('../api/journal-entries.php', { method: 'POST', body: fd });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Failed to create journal entry');
            alertEl.className = 'ok';
            alertEl.textContent = 'Journal entry posted. Redirecting…';
            alertEl.style.display = 'block';
            setTimeout(() => { window.location.href = 'view-journal.php?id=' + encodeURIComponent(result.id) + '&module=<?= $jeModule ?>'; }, 900);
        } catch (err) {
            alertEl.className = 'err';
            alertEl.textContent = err.message || 'Error';
            alertEl.style.display = 'block';
            postBtn.disabled = false;
            postBtn.innerHTML = prevLabel;
        }
    });

    addLine();
    addLine();
    syncSummary();
    calculateTotals();
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
