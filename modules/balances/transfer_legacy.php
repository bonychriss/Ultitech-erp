<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once 'functions.php';

requireLogin();
ensureBalancesSchema();

$page_title = 'Internal Transfer';

$form = [
    'from_account' => (int) ($_POST['from_account'] ?? 0),
    'to_account' => (int) ($_POST['to_account'] ?? 0),
    'transfer_date' => (string) ($_POST['transfer_date'] ?? date('Y-m-d')),
    'amount' => (string) ($_POST['amount'] ?? ''),
    'currency' => (string) ($_POST['currency'] ?? 'TZS'),
    'exchange_rate' => (string) ($_POST['exchange_rate'] ?? '1.00'),
    'reference_no' => (string) ($_POST['reference_no'] ?? ('REF-' . date('Ymd-His'))),
    'transfer_method' => (string) ($_POST['transfer_method'] ?? ''),
    'description' => (string) ($_POST['description'] ?? ''),
    'status' => (string) ($_POST['status'] ?? 'Draft'),
];

$accounts = $pdo->query("SELECT id, name, type, currency, current_balance FROM financial_accounts WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$accountMap = [];
foreach ($accounts as $acc) {
    $accountMap[(int) $acc['id']] = $acc;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form['reference_no'] = 'ITR-' . date('Ymd-His');
    $form['status'] = 'Draft';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromAccount = (int) $form['from_account'];
    $toAccount = (int) $form['to_account'];
    $amount = (float) $form['amount'];
    $date = clean_input($form['transfer_date']);
    $description = clean_input($form['description']);
    $referenceNo = clean_input($form['reference_no']);
    $fromType = (string) ($accountMap[$fromAccount]['type'] ?? 'bank');
    $toType = (string) ($accountMap[$toAccount]['type'] ?? 'bank');
    $transferMethod = balancesTransferMethodLabel(
        balancesAccountLiquidityBucket($fromType),
        balancesAccountLiquidityBucket($toType)
    );

    if ($fromAccount === 0 || $toAccount === 0) {
        $_SESSION['error'] = 'Please select both source and destination accounts.';
    } elseif ($fromAccount === $toAccount) {
        $_SESSION['error'] = 'Source and destination accounts cannot be the same.';
    } elseif ($amount <= 0) {
        $_SESSION['error'] = 'Amount must be greater than zero.';
    } else {
        try {
            $pdo->beginTransaction();
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $fromName = $accountMap[$fromAccount]['name'] ?? ('Account #' . $fromAccount);
            $toName = $accountMap[$toAccount]['name'] ?? ('Account #' . $toAccount);
            $narration = trim('Internal transfer [' . $referenceNo . '] ' . $description . ' | Method: ' . $transferMethod);

            $stmtOut = $pdo->prepare(
                "INSERT INTO account_transactions
                (account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by)
                VALUES (?, ?, 'debit', ?, 'transfer_out', NULL, ?, ?)"
            );
            $stmtOut->execute([$fromAccount, $date, $amount, 'Transfer to ' . $toName . ' - ' . $narration, $userId]);

            $stmtIn = $pdo->prepare(
                "INSERT INTO account_transactions
                (account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by)
                VALUES (?, ?, 'credit', ?, 'transfer_in', NULL, ?, ?)"
            );
            $stmtIn->execute([$toAccount, $date, $amount, 'Transfer from ' . $fromName . ' - ' . $narration, $userId]);

            $pdo->commit();

            recalculateBalance($fromAccount);
            recalculateBalance($toAccount);

            $_SESSION['success'] = 'Transfer created successfully.';
            $redirectQs = ['module' => 'balances'];
            if (!empty($_GET['company_slug'])) {
                $redirectQs['company_slug'] = (string) $_GET['company_slug'];
            }
            redirect('transfer.php?' . http_build_query($redirectQs));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = 'Transfer failed: ' . $e->getMessage();
        }
    }
}

if ((int) $form['from_account'] > 0 && (int) $form['to_account'] > 0) {
    $fromType = (string) ($accountMap[(int) $form['from_account']]['type'] ?? 'bank');
    $toType = (string) ($accountMap[(int) $form['to_account']]['type'] ?? 'bank');
    $form['transfer_method'] = balancesTransferMethodLabel(
        balancesAccountLiquidityBucket($fromType),
        balancesAccountLiquidityBucket($toType)
    );
}

$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$qs = [];
if (!empty($_GET['module'])) {
    $qs['module'] = (string) $_GET['module'];
}
if (!empty($_GET['company_slug'])) {
    $qs['company_slug'] = (string) $_GET['company_slug'];
}
$queryString = $qs !== [] ? '?' . http_build_query($qs) : '';
$formAction = 'transfer.php' . $queryString;
$historyUrl = 'transactions.php' . $queryString;
$cancelUrl = $historyUrl;
$sessionError = trim((string) ($_SESSION['error'] ?? ''));
if ($sessionError !== '') {
    unset($_SESSION['error']);
}
$selectChevron = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";
// JSON balances for live account helpers
$accountBalancesJson = [];
foreach ($accounts as $acc) {
    $accountBalancesJson[(int) $acc['id']] = [
        'name' => (string) ($acc['name'] ?? ''),
        'balance' => (float) ($acc['current_balance'] ?? 0),
        'currency' => (string) ($acc['currency'] ?? 'TZS'),
        'bucket' => balancesAccountLiquidityBucket((string) ($acc['type'] ?? 'bank')),
    ];
}

include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .employee-header { display: none !important; }
    .mobile-topbar { display: none; }
    .main-content-wrapper { padding: 2rem; }
    .page-shell { padding-left: 4rem; }
    .editor-shell { max-width: 1140px; margin: 0 auto; }
    .editor-topbar {
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
    }
    .editor-layout { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 2rem; align-items: start; }
    .section-nav { position: sticky; top: 96px; align-self: start; }
    .section-nav ul { list-style: none; margin: 0; padding: 0; }
    .section-nav li + li { margin-top: 0.5rem; }
    .section-nav a {
        display: block; padding: 0.45rem 0.75rem; border-radius: 8px; color: #64748b;
        font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.2s ease;
    }
    .section-nav a:hover { background: #eff6ff; color: #2563eb; }
    .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
    .editor-main { min-width: 0; }
    .editor-section { padding-bottom: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    .editor-section:last-of-type { margin-bottom: 1.5rem; }
    .section-header { margin-bottom: 1.25rem; }
    .form-row { display: grid; grid-template-columns: 210px 1fr; align-items: start; margin-bottom: 24px; }
    .form-row:last-child { margin-bottom: 0; }
    .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; }
    .form-label span { color: #ef4444; margin-left: 2px; }
    .form-input {
        width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
        font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
    }
    .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
    .form-input::placeholder { color: #94a3b8; opacity: 1; font-weight: 400; }
    select.form-input.is-placeholder { color: #94a3b8; }
    select.form-input.is-placeholder option { color: #1e293b; }
    select.form-input.is-placeholder option[value=""] { color: #94a3b8; }
    .form-input-readonly { background: #f8fafc; border-style: dashed; cursor: default; }
    .form-input-readonly.has-value { color: #0f172a; font-weight: 600; }
    .form-input-readonly.is-empty { color: #94a3b8; font-weight: 400; }
    .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }
    .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0; }
    .transfer-accounts-row {
        display: grid; grid-template-columns: 1fr 48px 1fr; gap: 12px; align-items: start;
    }
    .transfer-arrow {
        width: 40px; height: 40px; border-radius: 999px; border: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: center; color: #7c3aed;
        background: #faf5ff; margin-top: 36px;
    }
    .btn-save {
        background: #7c3aed; color: white; padding: 14px 48px; border-radius: 12px; font-weight: 600;
        font-size: 15px; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
    }
    .btn-save:hover { background: #6d28d9; }
    .btn-cancel, .btn-draft {
        border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s;
        cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; font-weight: 600;
    }
    .btn-cancel:hover, .btn-draft:hover { background: #f3e8ff; color: #6d28d9; }
    .btn-draft { border-color: #e2e8f0; color: #64748b; background: #fff; }
    .btn-draft:hover { background: #f8fafc; color: #334155; }
    .topbar-link {
        color: #64748b; font-size: 14px; font-weight: 500; text-decoration: none;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .topbar-link:hover { color: #7c3aed; }
    @media (max-width: 768px) {
        .mobile-topbar {
            display: flex; align-items: center; gap: 12px;
            position: sticky; top: 0; z-index: 60;
            background: #fff; border-bottom: 1px solid #e5e7eb;
            padding: 12px 16px; margin: -1rem -1rem 1rem -1rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }
        .mobile-topbar .mt-back {
            width: 40px; height: 40px; border-radius: 999px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            color: #1e293b; background: #f1f5f9; text-decoration: none; font-size: 15px;
        }
        .mobile-topbar .mt-back:active { background: #e2e8f0; }
        .mobile-topbar .mt-title { flex: 1; min-width: 0; }
        .mobile-topbar .mt-title h1 {
            font-size: 16px; font-weight: 600; color: #0f172a; margin: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .mobile-topbar .mt-title p { font-size: 12px; color: #94a3b8; margin: 2px 0 0; }
        .mobile-topbar .mt-action {
            width: 40px; height: 40px; border-radius: 999px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            color: #7c3aed; background: #faf5ff; text-decoration: none; font-size: 15px;
        }
        .editor-topbar { display: none !important; }
        .section-nav { display: none !important; }
        .section-header { display: none !important; }
        .editor-section { padding-bottom: 1.25rem; margin-bottom: 1.25rem; }
    }
    @media (max-width: 992px) {
        .main-content-wrapper { padding: 1rem !important; }
        .page-shell { padding-left: 0; }
        .editor-topbar { flex-direction: column; align-items: flex-start; }
        .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
        .section-nav { position: static; }
        .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .section-nav li + li { margin-top: 0; }
        .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
        .form-label { padding-top: 0; }
        .transfer-accounts-row { grid-template-columns: 1fr; }
        .transfer-arrow { margin: 0 auto; }
        .btn-save { width: 100%; padding: 14px 24px; }
    }
</style>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="mobile-topbar">
            <a href="<?= $esc($cancelUrl) ?>" class="mt-back" aria-label="Back">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="mt-title">
                <h1>Internal Transfer</h1>
                <p>Transfer funds between accounts</p>
            </div>
            <a href="<?= $esc($historyUrl) ?>" class="mt-action" aria-label="Transfer History">
                <i class="fas fa-clock-rotate-left"></i>
            </a>
        </div>
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">Internal Transfer</h1>
                <p class="text-sm text-slate-500 mt-1">Transfer funds between your internal accounts</p>
            </div>
            <div class="flex items-center gap-4 flex-wrap">
                <a href="<?= $esc($historyUrl) ?>" class="topbar-link">
                    <i class="fas fa-clock-rotate-left text-xs"></i> Transfer History
                </a>
                <a href="<?= $esc($cancelUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-arrow-left text-xs"></i> Back
                </a>
            </div>
        </div>

        <?php if ($sessionError !== ''): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
            <?= $esc($sessionError) ?>
        </div>
        <?php endif; ?>
        <form id="transferForm" method="post" action="<?= $esc($formAction) ?>">
            <input type="hidden" name="status" id="status" value="<?= $esc($form['status']) ?>">

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#general-info" class="is-active">General</a></li>
                        <li><a href="#accounts">Accounts</a></li>
                        <li><a href="#amount">Amount</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="general-info">
                        <div class="section-header">
                            <h2 class="section-title">General Information</h2>
                            <p class="section-subtitle">Date, reference, and description for this transfer.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="transfer_date">Transfer Date <span>*</span></label>
                            <div>
                                <input id="transfer_date" type="date" name="transfer_date" class="form-input" value="<?= $esc($form['transfer_date']) ?>" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="reference_no">Reference No <span>*</span></label>
                            <div>
                                <input id="reference_no" type="text" name="reference_no" class="form-input" value="<?= $esc($form['reference_no']) ?>" placeholder="e.g. ITR-20260303-120000" required>
                                <div class="help-text">Unique reference for this internal transfer.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="description">Description</label>
                            <div>
                                <input id="description" type="text" name="description" class="form-input" value="<?= $esc($form['description']) ?>" placeholder="e.g. Transfer from Petty Cash to CRDB Bank">
                                <div class="help-text">Optional note shown on account transactions.</div>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="accounts">
                        <div class="section-header">
                            <h2 class="section-title">Accounts</h2>
                            <p class="section-subtitle">Select source and destination accounts.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label">Transfer Route <span>*</span></label>
                            <div class="transfer-accounts-row">
                                <div>
                                    <select id="from_account" name="from_account" class="form-input appearance-none pr-10<?= (int) $form['from_account'] === 0 ? ' is-placeholder' : '' ?>" required style="<?= $esc($selectChevron) ?>">
                                        <option value=""<?= (int) $form['from_account'] === 0 ? ' selected' : '' ?>>Select source account</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= (int) $acc['id'] ?>" data-balance="<?= (float) ($acc['current_balance'] ?? 0) ?>"<?= (int) $form['from_account'] === (int) $acc['id'] ? ' selected' : '' ?>>
                                                <?= $esc($acc['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text" id="fromBalanceNote">Available balance: —</div>
                                </div>
                                <div class="transfer-arrow" aria-hidden="true"><i class="fas fa-arrow-right"></i></div>
                                <div>
                                    <select id="to_account" name="to_account" class="form-input appearance-none pr-10<?= (int) $form['to_account'] === 0 ? ' is-placeholder' : '' ?>" required style="<?= $esc($selectChevron) ?>">
                                        <option value=""<?= (int) $form['to_account'] === 0 ? ' selected' : '' ?>>Select destination account</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= (int) $acc['id'] ?>" data-balance="<?= (float) ($acc['current_balance'] ?? 0) ?>"<?= (int) $form['to_account'] === (int) $acc['id'] ? ' selected' : '' ?>>
                                                <?= $esc($acc['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="help-text" id="toBalanceNote">Available balance: —</div>
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="transfer_method_display">Transfer Method</label>
                            <div>
                                <input type="hidden" name="transfer_method" id="transfer_method" value="<?= $esc($form['transfer_method']) ?>">
                                <input type="text" id="transfer_method_display" class="form-input form-input-readonly<?= $form['transfer_method'] !== '' ? ' has-value' : ' is-empty' ?>" readonly
                                       value="<?= $esc($form['transfer_method']) ?>" placeholder="Select source and destination accounts">
                                <div class="help-text">Set automatically from account types (e.g. Bank to Bank for CRDB → CRDB).</div>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="amount">
                        <div class="section-header">
                            <h2 class="section-title">Amount</h2>
                            <p class="section-subtitle">Currency and transfer amount.</p>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="currency">Currency <span>*</span></label>
                            <div>
                                <select id="currency" name="currency" class="form-input appearance-none pr-10" style="<?= $esc($selectChevron) ?>">
                                    <option value="TZS"<?= $form['currency'] === 'TZS' ? ' selected' : '' ?>>TZS — Tanzanian Shilling</option>
                                    <option value="USD"<?= $form['currency'] === 'USD' ? ' selected' : '' ?>>USD — US Dollar</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="amount">Amount <span>*</span></label>
                            <div>
                                <input id="amount" type="number" min="0" step="0.01" name="amount" class="form-input" value="<?= $esc($form['amount']) ?>" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="exchange_rate">Exchange Rate</label>
                            <div>
                                <input id="exchange_rate" type="number" min="0" step="0.0001" name="exchange_rate" class="form-input" value="<?= $esc($form['exchange_rate']) ?>" placeholder="1.00">
                                <div class="help-text">Use 1.00 when both accounts use the same currency.</div>
                            </div>
                        </div>
                        <div class="form-row">
                            <label class="form-label" for="attachment">Attachment</label>
                            <div>
                                <input id="attachment" type="file" class="form-input" style="padding-top: 10px;">
                                <div class="help-text">Optional supporting document (not stored yet).</div>
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-start gap-4 mb-20 flex-wrap">
                        <a href="<?= $esc($cancelUrl) ?>" class="btn-cancel px-8 py-3 rounded-xl">Cancel</a>
                        <button type="button" class="btn-draft px-8 py-3 rounded-xl" id="btnDraft">Save as Draft</button>
                        <button type="button" class="btn-save" id="btnPost"><i class="fas fa-paper-plane mr-2"></i> Post Transfer</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const accounts = <?= json_encode($accountBalancesJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const fromSel = document.getElementById('from_account');
    const toSel = document.getElementById('to_account');
    const statusEl = document.getElementById('status');
    const form = document.getElementById('transferForm');

    function fmt(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function syncSelectPlaceholder(sel) {
        if (!sel) return;
        sel.classList.toggle('is-placeholder', sel.value === '' || sel.value === '0');
    }

    function balanceNote(sel, noteEl) {
        if (!sel || !noteEl) return;
        const id = parseInt(sel.value, 10);
        if (!id || !accounts[id]) {
            noteEl.textContent = 'Available balance: —';
            return;
        }
        const cur = accounts[id].currency || 'TZS';
        noteEl.textContent = 'Available balance: ' + cur + ' ' + fmt(accounts[id].balance);
    }

    const BUCKET_LABEL = { cash: 'Cash', bank: 'Bank', mobile: 'Mobile Money' };

    function resolveTransferMethod(fromId, toId) {
        if (!fromId || !toId || !accounts[fromId] || !accounts[toId]) {
            return '';
        }
        const from = BUCKET_LABEL[accounts[fromId].bucket] || 'Bank';
        const to = BUCKET_LABEL[accounts[toId].bucket] || 'Bank';
        return from + ' to ' + to;
    }

    function syncTransferMethod() {
        const fromId = fromSel ? parseInt(fromSel.value, 10) : 0;
        const toId = toSel ? parseInt(toSel.value, 10) : 0;
        const method = resolveTransferMethod(fromId, toId);
        const hidden = document.getElementById('transfer_method');
        const display = document.getElementById('transfer_method_display');
        if (hidden) hidden.value = method;
        if (display) {
            display.value = method;
            display.classList.toggle('has-value', method !== '');
            display.classList.toggle('is-empty', method === '');
        }
    }

    function syncAccountFields() {
        syncTransferMethod();
        syncSelectPlaceholder(fromSel);
        syncSelectPlaceholder(toSel);
        balanceNote(fromSel, document.getElementById('fromBalanceNote'));
        balanceNote(toSel, document.getElementById('toBalanceNote'));
    }

    [fromSel, toSel].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', syncAccountFields);
        el.addEventListener('change', syncAccountFields);
    });

    function submitTransfer(status) {
        if (statusEl) statusEl.value = status;
        if (!form) return;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    document.getElementById('btnDraft')?.addEventListener('click', function () {
        submitTransfer('Draft');
    });
    document.getElementById('btnPost')?.addEventListener('click', function () {
        submitTransfer('Posted');
    });

    document.querySelectorAll('.section-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            document.querySelectorAll('.section-nav a').forEach(function (a) { a.classList.remove('is-active'); });
            link.classList.add('is-active');
        });
    });

    syncAccountFields();
})();
</script>

<?php
$bal_lottie_okay_label = 'Close';
$bal_lottie_view_label = 'View Transfers';
$bal_lottie_view_url = $historyUrl;
include __DIR__ . '/includes/footer.php';
?>
