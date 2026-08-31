<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_settings.php';
requireLogin();

$page_title = 'Journal Configuration';

global $pdo;

$salesRevenueDefaultLabel = '4001 - Sales Revenue';
$salesRevenueGlId = accounting_resolve_default_sales_revenue_gl_account_id($pdo);
if ($salesRevenueGlId) {
    $salesRevStmt = $pdo->prepare('SELECT code, name FROM erp_accounts WHERE id = ? LIMIT 1');
    $salesRevStmt->execute([$salesRevenueGlId]);
    $salesRevRow = $salesRevStmt->fetch(PDO::FETCH_ASSOC);
    if ($salesRevRow) {
        $salesRevenueDefaultLabel = accounting_settings_format_gl_account_label($salesRevRow);
    }
}

$journals = [
    [
        'id' => 'gen',
        'name' => 'General Journal',
        'code' => 'GEN',
        'type' => 'General',
        'debit_display' => '—',
        'credit_display' => '—',
        'status' => 'active',
        'is_default' => 'yes',
        'description' => 'Default journal for adjustments, accruals, and entries that do not belong to a specialized journal.',
        'default_debit' => null,
        'default_credit' => null,
        'currency' => 'TZS',
        'posting_sequence' => 'JE-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => true,
        'created_by' => 'System Admin',
        'created_at' => '15 Jan 2024, 10:00',
    ],
    [
        'id' => 'sal',
        'name' => 'Sales Journal',
        'code' => 'SAL',
        'type' => 'Sales',
        'debit_display' => '1200 - Accounts Receivable',
        'credit_display' => $salesRevenueDefaultLabel,
        'status' => 'active',
        'is_default' => 'yes',
        'description' => 'Customer invoicing and sales revenue recognition.',
        'default_debit' => '1200 - Accounts Receivable',
        'default_credit' => $salesRevenueDefaultLabel,
        'currency' => 'TZS',
        'posting_sequence' => 'SJ-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => true,
        'created_by' => 'System Admin',
        'created_at' => '15 Jan 2024, 10:15',
    ],
    [
        'id' => 'bnk',
        'name' => 'Bank Journal',
        'code' => 'BNK',
        'type' => 'Bank',
        'debit_display' => '1002 - Bank',
        'credit_display' => '1001 - Cash',
        'status' => 'active',
        'is_default' => 'no',
        'description' => 'Bank receipts, payments, and bank charges.',
        'default_debit' => '1002 - Bank',
        'default_credit' => '1001 - Cash',
        'currency' => 'TZS',
        'posting_sequence' => 'BJ-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => true,
        'created_by' => 'System Admin',
        'created_at' => '20 Jan 2024, 09:20',
    ],
    [
        'id' => 'pur',
        'name' => 'Purchase Journal',
        'code' => 'PUR',
        'type' => 'Purchase',
        'debit_display' => '5100 - Purchases',
        'credit_display' => '2200 - Tax Payable',
        'status' => 'active',
        'is_default' => 'no',
        'description' => 'Supplier bills and purchase expenses.',
        'default_debit' => '5100 - Purchases',
        'default_credit' => '2200 - Tax Payable',
        'currency' => 'TZS',
        'posting_sequence' => 'PJ-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => true,
        'created_by' => 'Finance User',
        'created_at' => '22 Jan 2024, 14:00',
    ],
    [
        'id' => 'pym',
        'name' => 'Payment Voucher',
        'code' => 'PAY',
        'type' => 'Payments',
        'debit_display' => '—',
        'credit_display' => '1002 - Bank',
        'status' => 'active',
        'is_default' => 'yes',
        'description' => 'Outgoing payments and vendor settlements.',
        'default_debit' => null,
        'default_credit' => '1002 - Bank',
        'currency' => 'TZS',
        'posting_sequence' => 'PV-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => true,
        'created_by' => 'Finance User',
        'created_at' => '01 Feb 2024, 08:30',
    ],
    [
        'id' => 'csh',
        'name' => 'Cash Journal',
        'code' => 'CSH',
        'type' => 'Cash',
        'debit_display' => '1001 - Cash',
        'credit_display' => '—',
        'status' => 'active',
        'is_default' => 'no',
        'description' => 'Petty cash and on-hand cash movements.',
        'default_debit' => '1001 - Cash',
        'default_credit' => null,
        'currency' => 'TZS',
        'posting_sequence' => 'CJ-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => false,
        'created_by' => 'Finance User',
        'created_at' => '05 Feb 2024, 11:45',
    ],
    [
        'id' => 'pay',
        'name' => 'Payroll Journal',
        'code' => 'PAYRL',
        'type' => 'Payroll',
        'debit_display' => '5001 - Salary & Wage Expense',
        'credit_display' => '2002 - NSSF Payable',
        'status' => 'active',
        'is_default' => 'no',
        'description' => 'Payroll accruals, statutory deductions, and net pay (adjust default accounts to match your chart).',
        'default_debit' => '5001 - Salary & Wage Expense',
        'default_credit' => '2002 - NSSF Payable',
        'currency' => 'TZS',
        'posting_sequence' => 'PR-',
        'allow_manual' => true,
        'allow_posting' => true,
        'allow_reverse' => true,
        'created_by' => 'HR Admin',
        'created_at' => '10 Mar 2024, 16:00',
    ],
    [
        'id' => 'opn',
        'name' => 'Opening Journal',
        'code' => 'OPN',
        'type' => 'General',
        'debit_display' => '—',
        'credit_display' => '—',
        'status' => 'inactive',
        'is_default' => 'no',
        'description' => 'Legacy opening balance journal (archived).',
        'default_debit' => null,
        'default_credit' => null,
        'currency' => 'TZS',
        'posting_sequence' => 'OP-',
        'allow_manual' => false,
        'allow_posting' => false,
        'allow_reverse' => false,
        'created_by' => 'System Admin',
        'created_at' => '01 Jan 2023, 00:00',
    ],
];

$totalJournals = count($journals);
$activeCount = count(array_filter($journals, function ($j) {
    return ($j['status'] ?? '') === 'active';
}));
$inactiveCount = $totalJournals - $activeCount;
$defaultCount = count(array_filter($journals, function ($j) {
    return ($j['is_default'] ?? '') === 'yes';
}));

$company_display = $_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company');
$jeModuleRaw = isset($_GET['module']) ? (string) $_GET['module'] : 'balances';
$jeModuleEsc = htmlspecialchars($jeModuleRaw, ENT_QUOTES, 'UTF-8');

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<script src="https://cdn.tailwindcss.com"></script>
<style>
    .stock-dash {
        max-width: 1600px;
        margin: 0 auto;
        padding: 1.25rem 1.5rem 2rem;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }
    .stock-dash .dash-header h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.02em;
        margin: 0;
    }
    .stock-dash .dash-header p { color: #64748b; font-size: 0.875rem; margin: 0; }
    .stock-dash .dash-header--ledger {
        display: grid;
        grid-template-columns: minmax(0, auto) minmax(180px, 1fr) auto;
        align-items: center;
        gap: 1rem 1.25rem;
        margin-bottom: 1.5rem;
    }
    .stock-dash .dash-header--ledger .dash-header-title { min-width: 0; }
    .stock-dash .dash-header--ledger .dash-header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: flex-end;
    }
    .stock-dash .dash-alert {
        margin-top: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: #fff7ed;
        color: #c2410c;
        padding: 0.375rem 0.75rem;
        border-radius: 0.5rem;
        border: 1px solid #ffedd5;
        font-size: 0.8125rem;
        font-weight: 600;
    }
    @media (max-width: 992px) {
        .stock-dash { padding: 1rem; }
        .stock-dash .dash-header--ledger { grid-template-columns: 1fr; }
        .stock-dash .dash-header--ledger .dash-header-actions { justify-self: start; }
    }
    .stock-dash .btn-outline {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        background: #fff;
        color: #334155;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.15s;
        cursor: pointer;
        font-family: inherit;
    }
    .stock-dash .btn-outline:hover { background: #f8fafc; color: #334155; }
    .stock-dash .btn-blue {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        background: #2563eb;
        color: #fff;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid #2563eb;
        cursor: pointer;
        font-family: inherit;
    }
    .stock-dash .btn-blue:hover { background: #1d4ed8; color: #fff; }
    .stock-dash .btn-danger {
        background: #fff;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }
    .stock-dash .btn-danger:hover { background: #fef2f2; color: #b91c1c; }
    .stock-dash .kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (min-width: 992px) {
        .stock-dash .kpi-grid--four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .stock-dash .kpi-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1.25rem 1.5rem;
        min-height: 148px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        display: flex;
        flex-direction: column;
    }
    .stock-dash .kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        margin-bottom: 1rem;
    }
    .stock-dash .kpi-icon--total { background: #dbeafe; color: #2563eb; }
    .stock-dash .kpi-icon--active { background: #dcfce7; color: #16a34a; }
    .stock-dash .kpi-icon--inactive { background: #fee2e2; color: #dc2626; }
    .stock-dash .kpi-icon--default { background: #ede9fe; color: #7c3aed; }
    .stock-dash .kpi-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
    .stock-dash .kpi-label { font-size: 0.8rem; font-weight: 500; color: #64748b; margin-top: 0.35rem; }
    .stock-dash .kpi-sub { font-size: 0.75rem; color: #94a3b8; margin-top: auto; padding-top: 0.75rem; }
    .stock-dash .dash-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        margin-bottom: 1.25rem;
    }
    .stock-dash .dash-card-h {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .stock-dash .dash-card-h h3 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    .stock-dash .dash-card-b { padding: 1rem 1.25rem; }
    .stock-dash .tx-search-form {
        width: 100%;
        min-width: 0;
        max-width: 520px;
        justify-self: center;
    }
    .stock-dash .tx-search-wrap {
        display: flex;
        align-items: stretch;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 0 0 0 0.875rem;
        min-height: 44px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .stock-dash .tx-search-wrap:focus-within {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    }
    .stock-dash .tx-search-wrap > i {
        color: #94a3b8;
        display: flex;
        align-items: center;
        margin-right: 0.5rem;
    }
    .stock-dash .tx-search-input {
        flex: 1;
        min-width: 0;
        border: none;
        outline: none;
        background: transparent;
        font-family: inherit;
        font-size: 0.875rem;
        color: #0f172a;
        padding: 0.625rem 0.75rem 0.625rem 0;
    }
    .stock-dash .tx-search-input::placeholder { color: #94a3b8; }
    .stock-dash .ledger-table { width: 100%; margin: 0; font-size: 0.8125rem; min-width: 980px; }
    .stock-dash .ledger-table thead th {
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
        padding: 0.65rem 0.75rem;
    }
    .stock-dash .ledger-table td {
        padding: 0.65rem 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: middle;
    }
    .stock-dash .ledger-table tbody tr { cursor: pointer; transition: background 0.12s; }
    .stock-dash .ledger-table tbody tr:hover { background: #f8fafc; }
    .stock-dash .ledger-table tbody tr.selected {
        background: #eff6ff;
        outline: 2px solid #93c5fd;
        outline-offset: -2px;
    }
    .stock-dash .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .stock-dash .status-pill {
        border-radius: 999px;
        font-size: 0.6875rem;
        font-weight: 700;
        padding: 0.2rem 0.55rem;
        display: inline-flex;
    }
    .stock-dash .status-active { background: #dcfce7; color: #15803d; }
    .stock-dash .status-inactive { background: #fee2e2; color: #b91c1c; }
    .stock-dash .default-yes { color: #2563eb; font-weight: 700; font-size: 0.8125rem; }
    .stock-dash .default-no { color: #64748b; font-weight: 600; font-size: 0.8125rem; }
    .stock-dash .action-btn {
        width: 28px;
        height: 28px;
        border: 1px solid #e2e8f0;
        border-radius: 0.375rem;
        background: #fff;
        color: #2563eb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }
    .stock-dash .action-btn:hover { background: #eff6ff; }
    .stock-dash .jconf-detail-hd {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        background: #f8fafc;
    }
    .stock-dash .jconf-detail-hd h2 {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 700;
        color: #0f172a;
    }
    .stock-dash .jconf-detail-hd .meta { font-size: 0.8125rem; color: #64748b; margin-top: 0.25rem; }
    .stock-dash .jconf-tabs {
        display: flex;
        gap: 0.25rem;
        flex-wrap: wrap;
        padding: 0 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        background: #fff;
    }
    .stock-dash .jconf-tabs button {
        border: 0;
        background: transparent;
        padding: 0.75rem 0.875rem;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #64748b;
        border-bottom: 2px solid transparent;
        margin-bottom: -1px;
        cursor: pointer;
        font-family: inherit;
    }
    .stock-dash .jconf-tabs button:hover { color: #2563eb; }
    .stock-dash .jconf-tabs button.active { color: #2563eb; border-bottom-color: #2563eb; }
    .stock-dash .jconf-tab-panel { display: none; padding: 1.25rem; }
    .stock-dash .jconf-tab-panel.active { display: block; }
    .stock-dash .jconf-grid3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.25rem 1.5rem;
    }
    .stock-dash .jconf-fg label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        margin-bottom: 0.35rem;
    }
    .stock-dash .jconf-fg .val { font-size: 0.875rem; color: #0f172a; font-weight: 500; }
    .stock-dash .jconf-check {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8125rem;
        color: #0f172a;
        margin-bottom: 0.65rem;
    }
    .stock-dash .jconf-check i { color: #16a34a; }
    .stock-dash .jconf-detail-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    @media (max-width: 1100px) {
        .stock-dash .jconf-grid3 { grid-template-columns: 1fr; }
    }
</style>

<main class="main-content">
<div class="stock-dash">
    <div class="dash-header dash-header--ledger">
        <div class="dash-header-title">
            <h1>Journal Configuration</h1>
            <p class="mt-1"><?= date('l, d M Y') ?> · <?= htmlspecialchars($company_display) ?></p>
            <?php if ($inactiveCount > 0): ?>
            <div class="dash-alert">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                <?= (int) $inactiveCount ?> inactive journal<?= $inactiveCount === 1 ? '' : 's' ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="tx-search-form">
            <div class="tx-search-wrap">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" id="jconfSearch" class="tx-search-input" placeholder="Search journals…" autocomplete="off" aria-label="Search journals">
            </div>
        </div>
        <div class="dash-header-actions">
            <a href="journal-entries.php?module=<?= $jeModuleEsc ?>" class="btn-outline"><i class="fas fa-book"></i> Journal Entries</a>
            <button type="button" class="btn-blue" id="jconfNewJournal"><i class="fas fa-plus"></i> New Journal</button>
        </div>
    </div>

    <div class="kpi-grid kpi-grid--four">
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--total"><i class="far fa-file-lines"></i></div>
            <div class="kpi-value"><?= (int) $totalJournals ?></div>
            <div class="kpi-label">Total Journals</div>
            <div class="kpi-sub">Configured in system</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--active"><i class="fas fa-circle-check"></i></div>
            <div class="kpi-value"><?= (int) $activeCount ?></div>
            <div class="kpi-label">Active Journals</div>
            <div class="kpi-sub">Available for posting</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--inactive"><i class="fas fa-ban"></i></div>
            <div class="kpi-value"><?= (int) $inactiveCount ?></div>
            <div class="kpi-label">Inactive Journals</div>
            <div class="kpi-sub">Archived or disabled</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--default"><i class="fas fa-star"></i></div>
            <div class="kpi-value"><?= (int) $defaultCount ?></div>
            <div class="kpi-label">Default Journals</div>
            <div class="kpi-sub">Auto-selected on create</div>
        </div>
    </div>

    <div class="dash-card">
        <div class="dash-card-h">
            <h3><i class="fas fa-list text-blue-600 me-1"></i> Journals</h3>
            <span class="text-xs font-semibold text-slate-500"><?= (int) $totalJournals ?> journal<?= $totalJournals === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-wrap">
            <table class="table ledger-table mb-0" id="jconfTable">
                <thead>
                    <tr>
                        <th style="width:48px;">#</th>
                        <th>Journal name</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Default debit account</th>
                        <th>Default credit account</th>
                        <th>Status</th>
                        <th>Is default</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="jconfTbody">
                    <?php foreach ($journals as $idx => $j): ?>
                        <tr data-id="<?= htmlspecialchars($j['id']) ?>" tabindex="0" role="button">
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars($j['name']) ?></td>
                            <td><strong><?= htmlspecialchars($j['code']) ?></strong></td>
                            <td><?= htmlspecialchars($j['type']) ?></td>
                            <td><?= htmlspecialchars($j['debit_display']) ?></td>
                            <td><?= htmlspecialchars($j['credit_display']) ?></td>
                            <td>
                                <?php if (($j['status'] ?? '') === 'active'): ?>
                                    <span class="status-pill status-active">Active</span>
                                <?php else: ?>
                                    <span class="status-pill status-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= ($j['is_default'] ?? '') === 'yes' ? 'default-yes' : 'default-no' ?>">
                                    <?= ($j['is_default'] ?? '') === 'yes' ? 'Yes' : 'No' ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="#" class="action-btn jconf-edit" data-id="<?= htmlspecialchars($j['id']) ?>" title="Edit" aria-label="Edit" onclick="event.stopPropagation();"><i class="fas fa-pen"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="dash-card" id="jconfDetail" style="margin-bottom:0;">
        <div class="jconf-detail-hd">
            <div>
                <h2 id="detailTitle">General Journal <span style="color:#64748b;font-weight:600;">(GEN)</span></h2>
                <p class="meta" id="detailMeta">General · Active</p>
            </div>
            <div class="jconf-detail-actions">
                <button type="button" class="btn-outline" id="detailEditBtn"><i class="fas fa-pen"></i> Edit journal</button>
                <button type="button" class="btn-outline btn-danger" id="detailDeactivateBtn"><i class="fas fa-power-off"></i> Deactivate</button>
            </div>
        </div>
        <div class="jconf-tabs" role="tablist">
            <button type="button" class="active" data-tab="general" role="tab" aria-selected="true">General information</button>
            <button type="button" data-tab="accounts" role="tab" aria-selected="false">Accounts</button>
            <button type="button" data-tab="settings" role="tab" aria-selected="false">Settings</button>
            <button type="button" data-tab="permissions" role="tab" aria-selected="false">Permissions</button>
            <button type="button" data-tab="history" role="tab" aria-selected="false">History</button>
        </div>
        <div id="panel-general" class="jconf-tab-panel active" role="tabpanel">
            <div class="jconf-grid3">
                <div>
                    <div class="jconf-fg"><label>Journal name</label><div class="val" id="d_name">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Journal code</label><div class="val" id="d_code">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Journal type</label><div class="val" id="d_type">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Status</label><div class="val" id="d_status">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Description</label><div class="val" id="d_desc" style="line-height:1.5;">—</div></div>
                </div>
                <div>
                    <div class="jconf-fg"><label>Default debit account</label><div class="val" id="d_debit">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Default credit account</label><div class="val" id="d_credit">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Currency</label><div class="val" id="d_currency">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Posting sequence</label><div class="val" id="d_seq">—</div></div>
                </div>
                <div>
                    <div class="jconf-check" id="chk_manual"><i class="fas fa-check"></i> Allow manual entries</div>
                    <div class="jconf-check" id="chk_post"><i class="fas fa-check"></i> Allow posting</div>
                    <div class="jconf-check" id="chk_rev"><i class="fas fa-check"></i> Allow reverse</div>
                    <div class="jconf-check" id="chk_def"><i class="fas fa-check"></i> Is default journal</div>
                    <div class="jconf-fg" style="margin-top:16px;"><label>Created by</label><div class="val" id="d_by">—</div></div>
                    <div class="jconf-fg" style="margin-top:12px;"><label>Created at</label><div class="val" id="d_at">—</div></div>
                </div>
            </div>
        </div>
        <div id="panel-accounts" class="jconf-tab-panel" role="tabpanel" hidden>
            <p style="color:#64748b;margin:0;">Default GL mapping for this journal. Override per line when posting if needed.</p>
            <div class="jconf-grid3" style="margin-top:14px;">
                <div class="jconf-fg"><label>Debit template</label><div class="val" id="a_debit">—</div></div>
                <div class="jconf-fg"><label>Credit template</label><div class="val" id="a_credit">—</div></div>
            </div>
        </div>
        <div id="panel-settings" class="jconf-tab-panel" role="tabpanel" hidden>
            <div class="jconf-grid3">
                <div class="jconf-fg"><label>Posting prefix</label><div class="val" id="s_seq">—</div></div>
                <div class="jconf-fg"><label>Currency</label><div class="val" id="s_cur">—</div></div>
            </div>
        </div>
        <div id="panel-permissions" class="jconf-tab-panel" role="tabpanel" hidden>
            <p style="color:#64748b;">Role-based access for this journal is managed in <strong>Users &amp; Roles</strong>. Finance roles can post by default.</p>
        </div>
        <div id="panel-history" class="jconf-tab-panel" role="tabpanel" hidden>
            <p style="color:#64748b;">No audit log entries in this build. Activity history can be connected to your audit table later.</p>
        </div>
    </div>
</div>
</main>

<script>
(() => {
    const rows = <?= json_encode($journals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const byId = Object.fromEntries(rows.map(r => [r.id, r]));

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function setCheck(el, on) {
        if (!el) return;
        el.style.display = 'flex';
        el.querySelector('i').className = on ? 'fas fa-check' : 'fas fa-xmark';
        el.style.opacity = on ? '1' : '0.45';
    }

    function fillDetail(r) {
        if (!r) return;
        document.getElementById('detailTitle').innerHTML = `${escapeHtml(r.name)} <span style="color:#64748b;font-weight:600;">(${escapeHtml(r.code)})</span>`;
        document.getElementById('detailMeta').textContent = `${r.type} · ${r.status === 'active' ? 'Active' : 'Inactive'}`;
        document.getElementById('d_name').textContent = r.name;
        document.getElementById('d_code').textContent = r.code;
        document.getElementById('d_type').textContent = r.type;
        document.getElementById('d_status').textContent = r.status === 'active' ? 'Active' : 'Inactive';
        document.getElementById('d_desc').textContent = r.description || '—';
        document.getElementById('d_debit').textContent = r.default_debit || r.debit_display || '—';
        document.getElementById('d_credit').textContent = r.default_credit || r.credit_display || '—';
        document.getElementById('d_currency').textContent = r.currency || 'TZS';
        document.getElementById('d_seq').textContent = (r.posting_sequence || '') + '#####';
        document.getElementById('d_by').textContent = r.created_by || '—';
        document.getElementById('d_at').textContent = r.created_at || '—';
        document.getElementById('a_debit').textContent = r.default_debit || r.debit_display || '—';
        document.getElementById('a_credit').textContent = r.default_credit || r.credit_display || '—';
        document.getElementById('s_seq').textContent = (r.posting_sequence || '') + '#####';
        document.getElementById('s_cur').textContent = r.currency || 'TZS';
        setCheck(document.getElementById('chk_manual'), !!r.allow_manual);
        setCheck(document.getElementById('chk_post'), !!r.allow_posting);
        setCheck(document.getElementById('chk_rev'), !!r.allow_reverse);
        setCheck(document.getElementById('chk_def'), r.is_default === 'yes');
        const deact = document.getElementById('detailDeactivateBtn');
        if (deact) {
            deact.style.display = r.status === 'active' ? 'inline-flex' : 'none';
        }
    }

    function selectRow(tr) {
        document.querySelectorAll('#jconfTbody tr').forEach(t => t.classList.remove('selected'));
        tr.classList.add('selected');
        const id = tr.getAttribute('data-id');
        activeJournalId = id;
        fillDetail(byId[id]);
    }

    document.querySelectorAll('#jconfTbody tr').forEach(tr => {
        tr.addEventListener('click', () => selectRow(tr));
        tr.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectRow(tr); }
        });
    });

    document.querySelectorAll('.jconf-tabs button').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.getAttribute('data-tab');
            document.querySelectorAll('.jconf-tabs button').forEach(b => {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            document.querySelectorAll('.jconf-tab-panel').forEach(p => {
                const show = p.id === 'panel-' + tab;
                p.classList.toggle('active', show);
                p.hidden = !show;
            });
        });
    });

    const search = document.getElementById('jconfSearch');
    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('#jconfTbody tr').forEach(tr => {
                const t = tr.textContent.toLowerCase();
                tr.style.display = !q || t.includes(q) ? '' : 'none';
            });
        });
    }

    let activeJournalId = null;
    function openCreatePage(journalId) {
        const u = new URL('journal-create.php', window.location.href);
        u.searchParams.set('module', '<?= $jeModuleEsc ?>');
        if (journalId && byId[journalId]) {
            const r = byId[journalId];
            u.searchParams.set('name', r.name || '');
            u.searchParams.set('code', r.code || '');
            u.searchParams.set('type', (r.type || '').toLowerCase());
            u.searchParams.set('prefix', r.posting_sequence || r.code || 'JNL');
            u.searchParams.set('description', r.description || '');
        }
        window.location.href = u.toString();
    }

    document.getElementById('jconfNewJournal')?.addEventListener('click', () => {
        openCreatePage(null);
    });
    document.getElementById('detailEditBtn')?.addEventListener('click', () => {
        openCreatePage(activeJournalId);
    });
    document.querySelectorAll('.jconf-edit').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            const id = a.getAttribute('data-id');
            const tr = document.querySelector(`#jconfTbody tr[data-id="${id}"]`);
            if (tr) selectRow(tr);
            openCreatePage(id);
        });
    });

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            const el = document.getElementById('jconfSearch');
            if (el) {
                e.preventDefault();
                el.focus();
                el.select();
            }
        }
    });

    const first = document.querySelector('#jconfTbody tr');
    if (first) selectRow(first);
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>

