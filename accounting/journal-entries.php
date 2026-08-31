<?php
require_once '../includes/functions.php';
global $pdo;

$journals = [];
try {
    $journals = $pdo->query("
        SELECT
            je.*,
            COALESCE(SUM(ji.debit), 0) AS total_debit,
            COALESCE(SUM(ji.credit), 0) AS total_credit
        FROM erp_journal_entries je
        LEFT JOIN erp_journal_items ji ON ji.journal_id = je.id
        GROUP BY je.id
        ORDER BY je.date DESC, je.id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $journals = [];
}

$totalEntries = count($journals);
$draftEntries = 0;
$postedEntries = 0;
$lockedEntries = 0;
$totalAmount = 0.0;

foreach ($journals as &$je) {
    $status = strtolower((string) ($je['status'] ?? 'posted'));
    if (!in_array($status, ['draft', 'posted', 'locked'], true)) {
        $status = 'posted';
    }
    $je['_status'] = $status;

    if ($status === 'draft') {
        $draftEntries++;
    } elseif ($status === 'locked') {
        $lockedEntries++;
    } else {
        $postedEntries++;
    }

    $totalAmount += (float) ($je['total_debit'] ?? 0);
}
unset($je);

$company_display = $_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company');
$jeModule = isset($_GET['module']) ? (string) $_GET['module'] : 'balances';
$jeModuleEsc = htmlspecialchars($jeModule, ENT_QUOTES, 'UTF-8');

if (!function_exists('je_format_amount')) {
    function je_format_amount(float $n): string
    {
        if ($n >= 1000000) {
            return 'TZS ' . number_format($n / 1000000, 1) . 'M';
        }
        if ($n >= 1000) {
            return 'TZS ' . number_format($n / 1000, 1) . 'K';
        }

        return 'TZS ' . number_format($n, 0);
    }
}
?>
<?php
$page_title = 'Journal Entries';
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
    }
    .stock-dash .dash-header--ledger .dash-header-title { min-width: 0; flex-shrink: 0; }
    .stock-dash .dash-header--ledger .dash-header-actions {
        flex-shrink: 0;
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
        .stock-dash .dash-header--ledger {
            grid-template-columns: 1fr;
            align-items: stretch;
        }
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
    }
    .stock-dash .btn-blue:hover { background: #1d4ed8; color: #fff; }
    .stock-dash .kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (min-width: 768px) {
        .stock-dash .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1200px) {
        .stock-dash .kpi-grid--five { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    }
    .stock-dash .kpi-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1.25rem 1.5rem;
        min-height: 148px;
        height: 100%;
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
        flex-shrink: 0;
    }
    .stock-dash .kpi-icon--total { background: #dbeafe; color: #2563eb; }
    .stock-dash .kpi-icon--draft { background: #ffedd5; color: #ea580c; }
    .stock-dash .kpi-icon--posted { background: #dcfce7; color: #16a34a; }
    .stock-dash .kpi-icon--locked { background: #ede9fe; color: #7c3aed; }
    .stock-dash .kpi-icon--amount { background: #e0f2fe; color: #0284c7; }
    .stock-dash .kpi-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.1;
    }
    .stock-dash .kpi-value--sm { font-size: 1.35rem; }
    .stock-dash .kpi-value--money {
        font-size: clamp(0.875rem, 2.2vw, 1.2rem);
        line-height: 1.25;
        word-break: break-word;
    }
    .stock-dash .kpi-label {
        font-size: 0.8rem;
        font-weight: 500;
        color: #64748b;
        margin-top: 0.35rem;
    }
    .stock-dash .kpi-sub {
        font-size: 0.75rem;
        color: #94a3b8;
        margin-top: auto;
        padding-top: 0.75rem;
    }
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
    .stock-dash .filter-grid {
        display: grid;
        grid-template-columns: 1.3fr repeat(4, minmax(0, 1fr)) auto;
        gap: 0.75rem;
        align-items: end;
    }
    .stock-dash .filter-grid label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 600;
    }
    .stock-dash .filter-control {
        width: 100%;
        height: 38px;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        padding: 0 0.65rem;
        font-size: 0.8125rem;
        color: #0f172a;
        background: #fff;
        font-family: inherit;
    }
    .stock-dash .filter-panel:not(.is-open) { display: none; }
    .stock-dash .tx-search-form {
        width: 100%;
        min-width: 0;
        max-width: 520px;
        justify-self: center;
    }
    .stock-dash .tx-search-wrap {
        display: flex;
        align-items: stretch;
        gap: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 0 0 0 0.875rem;
        min-height: 44px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        font-family: inherit;
    }
    .stock-dash .tx-search-wrap:focus-within {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    }
    .stock-dash .tx-search-wrap > i {
        color: #94a3b8;
        font-size: 1rem;
        flex-shrink: 0;
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
        padding: 0.625rem 0.5rem 0.625rem 0;
        line-height: 1.25;
    }
    .stock-dash .tx-search-input::placeholder { color: #94a3b8; }
    .stock-dash .tx-search-kbd {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        font-size: 0.6875rem;
        font-weight: 600;
        color: #64748b;
        border: 1px solid #e2e8f0;
        border-radius: 0.375rem;
        padding: 0.15rem 0.4rem;
        margin-right: 0.65rem;
        background: #f8fafc;
    }
    .stock-dash .ledger-table { width: 100%; margin: 0; font-size: 0.8125rem; min-width: 1100px; }
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
        white-space: nowrap;
    }
    .stock-dash .ledger-table tbody tr:hover { background: #f8fafc; }
    .stock-dash .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .stock-dash .je-ref { color: #2563eb; font-weight: 700; }
    .stock-dash .status-pill {
        border-radius: 999px;
        font-size: 0.6875rem;
        font-weight: 700;
        padding: 0.2rem 0.55rem;
        display: inline-flex;
    }
    .stock-dash .status-posted { background: #dcfce7; color: #15803d; }
    .stock-dash .status-draft { background: #fef3c7; color: #b45309; }
    .stock-dash .status-locked { background: #ede9fe; color: #6d28d9; }
    .stock-dash .diff-ok { color: #16a34a; font-weight: 700; }
    .stock-dash .diff-bad { color: #dc2626; font-weight: 700; }
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
    .stock-dash .ledger-footer {
        padding: 0.75rem 1.25rem;
        border-top: 1px solid #f1f5f9;
        font-size: 0.75rem;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .stock-dash .ledger-pager {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        flex-wrap: wrap;
    }
    .stock-dash .ledger-pager span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 0.5rem;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
    }
    .stock-dash .ledger-pager .is-active {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }
    @media (max-width: 1200px) {
        .stock-dash .filter-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 640px) {
        .stock-dash .filter-grid { grid-template-columns: 1fr; }
    }
</style>

<main class="main-content">
<div class="stock-dash">
    <div class="dash-header dash-header--ledger mb-5">
        <div class="dash-header-title">
            <h1>Journal Entries</h1>
            <p class="mt-1"><?= date('l, d M Y') ?> · <?= htmlspecialchars($company_display) ?></p>
            <?php if ($draftEntries > 0): ?>
            <div class="dash-alert">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                <?= number_format($draftEntries) ?> draft entr<?= $draftEntries === 1 ? 'y' : 'ies' ?> pending review
            </div>
            <?php endif; ?>
        </div>
        <div class="tx-search-form">
            <div class="tx-search-wrap">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input id="topSearchInput" type="search" class="tx-search-input" placeholder="Search journal entries…" autocomplete="off" aria-label="Search journal entries">
                <span class="tx-search-kbd">Ctrl+K</span>
            </div>
        </div>
        <div class="dash-header-actions">
            <button type="button" class="btn-outline" id="jrnlFiltersToggle" title="Filters"><i class="fas fa-sliders-h"></i> Filters</button>
            <a href="#" class="btn-outline"><i class="fas fa-upload"></i> Import</a>
            <a href="#" class="btn-outline"><i class="fas fa-download"></i> Export</a>
            <a href="create-journal.php?module=<?= $jeModuleEsc ?>" class="btn-blue"><i class="fas fa-plus"></i> New Journal Entry</a>
        </div>
    </div>

    <div class="kpi-grid kpi-grid--five">
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--total"><i class="far fa-file-lines"></i></div>
            <div class="kpi-value"><?= number_format($totalEntries) ?></div>
            <div class="kpi-label">Total Entries</div>
            <div class="kpi-sub">All journal records</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--draft"><i class="far fa-pen-to-square"></i></div>
            <div class="kpi-value"><?= number_format($draftEntries) ?></div>
            <div class="kpi-label">Draft Entries</div>
            <div class="kpi-sub">Awaiting posting</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--posted"><i class="far fa-circle-check"></i></div>
            <div class="kpi-value"><?= number_format($postedEntries) ?></div>
            <div class="kpi-label">Posted Entries</div>
            <div class="kpi-sub">Finalized in ledger</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--locked"><i class="fas fa-lock"></i></div>
            <div class="kpi-value"><?= number_format($lockedEntries) ?></div>
            <div class="kpi-label">Locked Entries</div>
            <div class="kpi-sub">Closed for editing</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon kpi-icon--amount"><i class="fas fa-coins"></i></div>
            <div class="kpi-value kpi-value--sm kpi-value--money"><?= je_format_amount($totalAmount) ?></div>
            <div class="kpi-label">Total Amount (TZS)</div>
            <div class="kpi-sub">Sum of debits shown</div>
        </div>
    </div>

    <div class="dash-card filter-panel" id="jrnlFilterPanel">
        <div class="dash-card-h">
            <h3><i class="fas fa-filter text-blue-600 me-1"></i> Filters</h3>
            <button type="button" class="btn-outline" id="resetFiltersBtn" style="padding:0.35rem 0.75rem;font-size:0.75rem;"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
        <div class="dash-card-b">
            <div class="filter-grid">
                <div>
                    <label for="tableSearchInput">Search</label>
                    <input id="tableSearchInput" class="filter-control" type="text" placeholder="Reference, description, note…">
                </div>
                <div>
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter" class="filter-control">
                        <option value="all">All Statuses</option>
                        <option value="posted">Posted</option>
                        <option value="draft">Draft</option>
                        <option value="locked">Locked</option>
                    </select>
                </div>
                <div>
                    <label for="dateFilter">Date</label>
                    <input id="dateFilter" class="filter-control" type="date">
                </div>
                <div>
                    <label for="journalTypeFilter">Journal</label>
                    <select id="journalTypeFilter" class="filter-control">
                        <option value="all">All Journals</option>
                        <option value="sales">Sales Journal</option>
                        <option value="bank">Bank Journal</option>
                        <option value="general">General Journal</option>
                        <option value="payment">Payment Voucher</option>
                        <option value="purchase">Purchase Journal</option>
                        <option value="payroll">Payroll Journal</option>
                    </select>
                </div>
                <div>
                    <label for="postedByFilter">Posted By</label>
                    <select id="postedByFilter" class="filter-control">
                        <option value="all">All Users</option>
                        <option value="finance user">Finance User</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="dash-card" style="margin-bottom:0;">
        <div class="dash-card-h">
            <h3><i class="fas fa-book text-blue-600 me-1"></i> Journal Entries</h3>
            <span class="text-xs font-semibold text-slate-500"><?= number_format($totalEntries) ?> row<?= $totalEntries === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-wrap">
            <table class="table ledger-table mb-0" id="journalTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Reference</th>
                        <th>Date</th>
                        <th>Journal</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th class="text-end">Debit (TZS)</th>
                        <th class="text-end">Credit (TZS)</th>
                        <th class="text-end">Difference</th>
                        <th>Posted By</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($journals)): ?>
                    <tr><td colspan="11" class="text-center py-5 text-muted">No journal entries yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($journals as $i => $je): ?>
                        <?php
                        $desc = trim((string) ($je['description'] ?? '-'));
                        $journalType = 'General Journal';
                        $tagMap = [
                            'General Journal' => 'General Journal',
                            'Sales Journal' => 'Sales Journal',
                            'Bank Journal' => 'Bank Journal',
                            'Purchase Journal' => 'Purchase Journal',
                            'Payment Voucher' => 'Payment Voucher',
                            'Payroll Journal' => 'Payroll Journal',
                        ];
                        if (preg_match('/^\[([^\]]+)\]/', $desc, $tagMatch)) {
                            $tag = trim($tagMatch[1]);
                            if (isset($tagMap[$tag])) {
                                $journalType = $tagMap[$tag];
                            }
                        }
                        if ($journalType === 'General Journal' && $desc !== '') {
                            $descLower = strtolower($desc);
                            if (strpos($descLower, 'sale') !== false) {
                                $journalType = 'Sales Journal';
                            } elseif (strpos($descLower, 'bank') !== false || strpos($descLower, 'payment') !== false) {
                                $journalType = 'Bank Journal';
                            } elseif (strpos($descLower, 'purchase') !== false || strpos($descLower, 'supplier') !== false) {
                                $journalType = 'Purchase Journal';
                            } elseif (strpos($descLower, 'voucher') !== false) {
                                $journalType = 'Payment Voucher';
                            } elseif (strpos($descLower, 'payroll') !== false) {
                                $journalType = 'Payroll Journal';
                            }
                        }
                        $status = $je['_status'];
                        $totalDebit = (float) ($je['total_debit'] ?? 0);
                        $totalCredit = (float) ($je['total_credit'] ?? 0);
                        $difference = $totalDebit - $totalCredit;
                        $postedBy = 'Finance User';
                        $viewUrl = 'view-journal.php?id=' . (int) ($je['id'] ?? 0) . '&module=' . urlencode($jeModule);
                        ?>
                        <tr data-status="<?= htmlspecialchars($status) ?>" data-journal="<?= htmlspecialchars(strtolower(str_replace(' Journal', '', $journalType))) ?>" data-posted-by="<?= htmlspecialchars(strtolower($postedBy)) ?>">
                            <td><?= $i + 1 ?></td>
                            <td class="je-ref"><?= htmlspecialchars((string) ($je['entry_number'] ?? '-')) ?></td>
                            <td><?= !empty($je['date']) ? htmlspecialchars(date('j/n/Y', strtotime((string) $je['date']))) : '-' ?></td>
                            <td><?= htmlspecialchars($journalType) ?></td>
                            <td style="max-width:240px;white-space:normal;"><?= htmlspecialchars($desc !== '' ? $desc : '-') ?></td>
                            <td>
                                <?php if ($status === 'draft'): ?>
                                    <span class="status-pill status-draft">Draft</span>
                                <?php elseif ($status === 'locked'): ?>
                                    <span class="status-pill status-locked">Locked</span>
                                <?php else: ?>
                                    <span class="status-pill status-posted">Posted</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format($totalDebit, 2) ?></td>
                            <td class="text-end"><?= number_format($totalCredit, 2) ?></td>
                            <td class="text-end <?= abs($difference) < 0.01 ? 'diff-ok' : 'diff-bad' ?>"><?= number_format($difference, 2) ?></td>
                            <td><?= htmlspecialchars($postedBy) ?></td>
                            <td class="text-end">
                                <a href="<?= htmlspecialchars($viewUrl) ?>" class="action-btn" title="View"><i class="far fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="ledger-footer">
            <div>Showing 1 to <?= min(10, $totalEntries) ?> of <?= number_format($totalEntries) ?> entries</div>
            <div class="ledger-pager">
                <span><i class="fas fa-angle-left"></i></span>
                <span class="is-active">1</span>
                <span>2</span>
                <span>3</span>
                <span><i class="fas fa-angle-right"></i></span>
            </div>
        </div>
    </div>
</div>
</main>

<script>
(() => {
    const topSearch = document.getElementById('topSearchInput');
    const tableSearch = document.getElementById('tableSearchInput');
    const statusFilter = document.getElementById('statusFilter');
    const journalTypeFilter = document.getElementById('journalTypeFilter');
    const postedByFilter = document.getElementById('postedByFilter');
    const dateFilter = document.getElementById('dateFilter');
    const resetBtn = document.getElementById('resetFiltersBtn');
    const rows = document.querySelectorAll('#journalTable tbody tr');

    const applyFilters = () => {
        const q1 = (topSearch?.value || '').toLowerCase().trim();
        const q2 = (tableSearch?.value || '').toLowerCase().trim();
        const status = statusFilter?.value || 'all';
        const journal = journalTypeFilter?.value || 'all';
        const postedBy = postedByFilter?.value || 'all';
        const dateVal = dateFilter?.value || '';

        rows.forEach((row) => {
            if (row.children.length < 4) return;
            const text = row.textContent.toLowerCase();
            const rowStatus = row.getAttribute('data-status') || '';
            const rowJournal = row.getAttribute('data-journal') || '';
            const rowPostedBy = row.getAttribute('data-posted-by') || '';
            const rowDate = row.children[2] ? row.children[2].textContent.trim() : '';
            const formattedDate = dateVal ? new Date(dateVal).toLocaleDateString('en-GB').replace(/\//g, '/') : '';

            const matchSearch = (!q1 || text.includes(q1)) && (!q2 || text.includes(q2));
            const matchStatus = status === 'all' || rowStatus === status;
            const matchJournal = journal === 'all' || rowJournal.includes(journal);
            const matchUser = postedBy === 'all' || rowPostedBy.includes(postedBy);
            const matchDate = !dateVal || rowDate === formattedDate;

            row.style.display = matchSearch && matchStatus && matchJournal && matchUser && matchDate ? '' : 'none';
        });
    };

    [topSearch, tableSearch, statusFilter, journalTypeFilter, postedByFilter, dateFilter].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', applyFilters);
        el.addEventListener('change', applyFilters);
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            if (topSearch) topSearch.value = '';
            if (tableSearch) tableSearch.value = '';
            if (statusFilter) statusFilter.value = 'all';
            if (journalTypeFilter) journalTypeFilter.value = 'all';
            if (postedByFilter) postedByFilter.value = 'all';
            if (dateFilter) dateFilter.value = '';
            applyFilters();
        });
    }

    const filtersToggle = document.getElementById('jrnlFiltersToggle');
    const filterPanel = document.getElementById('jrnlFilterPanel');
    if (filtersToggle && filterPanel) {
        filtersToggle.addEventListener('click', () => {
            filterPanel.classList.toggle('is-open');
            if (filterPanel.classList.contains('is-open')) {
                const first = filterPanel.querySelector('input, select');
                if (first) setTimeout(() => first.focus(), 150);
            }
        });
    }

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            const ts = document.getElementById('topSearchInput');
            if (ts) {
                e.preventDefault();
                ts.focus();
                ts.select();
            }
        }
    });
})();
</script>
<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>

