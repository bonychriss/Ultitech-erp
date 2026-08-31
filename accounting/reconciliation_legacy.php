<?php

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$page_title = 'Bank Reconciliation';
$company_display = $_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company');

$bankLines = [
    ['id' => 'b1', 'date' => '2026-05-12', 'desc' => 'Customer Deposit - JOHN KAMAU', 'ref' => 'CRDB-88291', 'amount' => 1250000, 'status' => 'unmatched'],
    ['id' => 'b2', 'date' => '2026-05-14', 'desc' => 'POS Settlement - Branch 03', 'ref' => 'POS-44102', 'amount' => 842500, 'status' => 'suggested'],
    ['id' => 'b3', 'date' => '2026-05-18', 'desc' => 'Bank Charges - May', 'ref' => 'CHG-00912', 'amount' => -15600, 'status' => 'matched'],
    ['id' => 'b4', 'date' => '2026-05-22', 'desc' => 'Supplier Payment - ABC Ltd', 'ref' => 'FT-22019', 'amount' => -450000, 'status' => 'unmatched'],
];

$systemLines = [
    ['id' => 's1', 'date' => '2026-05-12', 'source' => 'Revenue', 'ref' => 'RCP-10492', 'party' => 'John Kamau', 'amount' => 1250000, 'status' => 'unmatched'],
    ['id' => 's2', 'date' => '2026-05-14', 'source' => 'Journal Entry', 'ref' => 'JE-2026-000118', 'party' => '-', 'amount' => 842500, 'status' => 'suggested'],
    ['id' => 's3', 'date' => '2026-05-18', 'source' => 'Journal Entry', 'ref' => 'JE-2026-000120', 'party' => '-', 'amount' => -15600, 'status' => 'matched'],
    ['id' => 's4', 'date' => '2026-05-21', 'source' => 'Payment Voucher', 'ref' => 'PV-2026-000044', 'party' => 'ABC Ltd', 'amount' => -450000, 'status' => 'unmatched'],
];

$cashLines = [
    ['id' => 'c1', 'date' => '2026-05-05', 'desc' => 'Petty Cash Top-up', 'ref' => 'PC-001', 'amount' => 500000, 'status' => 'unmatched'],
    ['id' => 'c2', 'date' => '2026-05-09', 'desc' => 'Office Supplies - Cash', 'ref' => 'CSH-882', 'amount' => -82000, 'status' => 'matched'],
];

$cashSystem = [
    ['id' => 'cs1', 'date' => '2026-05-05', 'source' => 'Petty Cash', 'ref' => 'PC-REQ-12', 'party' => 'Finance', 'amount' => 500000, 'status' => 'unmatched'],
    ['id' => 'cs2', 'date' => '2026-05-09', 'source' => 'Expenses', 'ref' => 'EXP-2210', 'party' => '-', 'amount' => -82000, 'status' => 'matched'],
];

$mobileLines = [
    ['id' => 'm1', 'date' => '2026-05-16', 'desc' => 'M-Pesa In - Customer XYZ', 'ref' => 'MP-99102', 'amount' => 320000, 'status' => 'suggested'],
];

$mobileSystem = [
    ['id' => 'ms1', 'date' => '2026-05-16', 'source' => 'Revenue', 'ref' => 'RCP-99102', 'party' => 'Customer XYZ', 'amount' => 320000, 'status' => 'suggested'],
];

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
    .stock-dash .dash-header p { color: #64748b; font-size: 0.875rem; margin: 0.25rem 0 0; }
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
        cursor: pointer;
        transition: background 0.15s;
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
    }
    .stock-dash .btn-blue:hover { background: #1d4ed8; color: #fff; }
    .stock-dash .btn-green {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        background: #16a34a;
        color: #fff;
        font-size: 0.8125rem;
        font-weight: 600;
        border: 1px solid #16a34a;
        cursor: pointer;
    }
    .stock-dash .btn-green:hover { background: #15803d; color: #fff; }
    .stock-dash .kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (min-width: 992px) {
        .stock-dash .kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
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
        flex-shrink: 0;
    }
    .stock-dash .kpi-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.1;
    }
    .stock-dash .kpi-value--sm { font-size: 1.35rem; }
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
        border-radius: 0.75rem;
        overflow: hidden;
        margin-bottom: 1.25rem;
    }
    .stock-dash .dash-card-h {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .stock-dash .dash-card-h h3 {
        font-size: 0.875rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
    }
    .stock-dash .dash-card-b { padding: 1rem; }
    .stock-dash .filter-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }
    @media (min-width: 992px) {
        .stock-dash .filter-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .stock-dash .filter-grid .span-2 { grid-column: span 2; }
    }
    .stock-dash .filter-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        color: #64748b;
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .stock-dash .filter-ctl {
        width: 100%;
        height: 40px;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        padding: 0 0.75rem;
        font-size: 0.8125rem;
        background: #fff;
        color: #0f172a;
    }
    .stock-dash .rec-tabs {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }
    .stock-dash .rec-tab {
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 999px;
        padding: 0.45rem 1rem;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
    }
    .stock-dash .rec-tab.active {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }
    .stock-dash .rec-pane { display: none; }
    .stock-dash .rec-pane.active { display: block; }
    .stock-dash .rec-grid3 {
        display: grid;
        grid-template-columns: 1fr 120px 1fr;
        gap: 0.75rem;
        align-items: start;
    }
    @media (max-width: 1100px) {
        .stock-dash .rec-grid3 { grid-template-columns: 1fr; }
        .stock-dash .rec-mid {
            flex-direction: row;
            flex-wrap: wrap;
            justify-content: center;
            padding-top: 0;
        }
        .stock-dash .rec-mid .btn-outline,
        .stock-dash .rec-mid .btn-blue { width: auto; }
    }
    .stock-dash .rec-section-title {
        font-size: 0.875rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 0.65rem;
    }
    .stock-dash .rec-mid {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        align-items: stretch;
        padding-top: 1.75rem;
    }
    .stock-dash .rec-mid .btn-outline,
    .stock-dash .rec-mid .btn-blue {
        width: 100%;
        justify-content: center;
        font-size: 0.75rem;
        padding: 0.45rem 0.65rem;
    }
    .stock-dash .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .stock-dash .ledger-table {
        width: 100%;
        min-width: 480px;
        margin: 0;
        font-size: 0.8125rem;
        border-collapse: collapse;
    }
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
        text-align: left;
    }
    .stock-dash .ledger-table td {
        padding: 0.65rem 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        vertical-align: middle;
    }
    .stock-dash .ledger-table tbody tr:hover { background: #f8fafc; }
    .stock-dash .ledger-table td.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    .stock-dash .rec-pill {
        display: inline-flex;
        padding: 0.15rem 0.55rem;
        border-radius: 999px;
        font-size: 0.6875rem;
        font-weight: 700;
    }
    .stock-dash .rec-pill.un { background: #fee2e2; color: #b91c1c; }
    .stock-dash .rec-pill.sg { background: #fef3c7; color: #b45309; }
    .stock-dash .rec-pill.ok { background: #dcfce7; color: #15803d; }
    .stock-dash .rec-compare {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }
    @media (max-width: 640px) {
        .stock-dash .rec-compare,
        .stock-dash .rec-metrics { grid-template-columns: 1fr; }
    }
    .stock-dash .rec-box {
        border: 1px solid #f1f5f9;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        background: #f8fafc;
        font-size: 0.8125rem;
        color: #334155;
        line-height: 1.5;
    }
    .stock-dash .rec-box strong { color: #0f172a; }
    .stock-dash .rec-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 0.75rem;
    }
    .stock-dash .rec-metric {
        border: 1px solid #f1f5f9;
        border-radius: 0.5rem;
        padding: 0.65rem 0.75rem;
        background: #fff;
        font-size: 0.75rem;
        color: #64748b;
    }
    .stock-dash .rec-metric b {
        display: block;
        margin-top: 0.25rem;
        font-size: 0.9375rem;
        color: #0f172a;
    }
    .stock-dash .rec-foot-kv {
        font-size: 0.8125rem;
        color: #64748b;
        margin-right: 1rem;
        display: inline-block;
        line-height: 1.6;
    }
    .stock-dash .rec-foot-kv b { color: #0f172a; margin-left: 0.25rem; font-weight: 700; }
    .stock-dash .rec-foot-kv b.text-danger { color: #dc2626; }
</style>

<main class="main-content">
<div class="stock-dash">
    <div class="dash-header flex flex-wrap items-start justify-between gap-4 mb-5">
        <div>
            <h1>Bank Reconciliation</h1>
            <p><?= date('l, d M Y') ?> · <?= htmlspecialchars($company_display) ?></p>
            <p class="mt-1" style="max-width:36rem;">Match bank statement lines with system transactions and finalize reconciliation.</p>
        </div>
        <div class="dash-header-actions flex flex-wrap items-center gap-2">
            <button type="button" class="btn-outline"><i class="bi bi-file-earmark-arrow-up"></i> Import Statement</button>
            <button type="button" class="btn-outline"><i class="bi bi-magic"></i> Match Rules</button>
            <button type="button" class="btn-blue"><i class="bi bi-play-fill"></i> Start Reconciliation</button>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon bg-red-50 text-red-600"><i class="bi bi-bank2"></i></div>
            <div class="kpi-value">24</div>
            <div class="kpi-label">Unmatched Bank Lines</div>
            <div class="kpi-sub">TZS 12,450,000.00</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-amber-50 text-amber-600"><i class="bi bi-hdd-stack"></i></div>
            <div class="kpi-value">16</div>
            <div class="kpi-label">Unmatched System Entries</div>
            <div class="kpi-sub">TZS 8,920,000.00</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-green-50 text-green-600"><i class="bi bi-link-45deg"></i></div>
            <div class="kpi-value">128</div>
            <div class="kpi-label">Matched This Period</div>
            <div class="kpi-sub">TZS 186,200,000.00</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon bg-violet-50 text-violet-600"><i class="bi bi-scale"></i></div>
            <div class="kpi-value kpi-value--sm">325,000.00</div>
            <div class="kpi-label">Closing Difference</div>
            <div class="kpi-sub">0.10% of statement balance</div>
        </div>
    </div>

    <div class="dash-card">
        <div class="dash-card-b">
            <div class="filter-grid">
                <div>
                    <label class="filter-label" for="recAccount">Account</label>
                    <select class="filter-ctl" id="recAccount">
                        <option>CRDB Bank</option>
                        <option>NMB Bank</option>
                        <option>Cash on Hand</option>
                        <option>M-Pesa</option>
                    </select>
                </div>
                <div>
                    <label class="filter-label">Statement Period</label>
                    <input class="filter-ctl" type="text" value="01/05/2026 - 31/05/2026" readonly>
                </div>
                <div>
                    <label class="filter-label" for="recStatus">Status</label>
                    <select class="filter-ctl" id="recStatus">
                        <option>All Statuses</option>
                        <option>Unmatched</option>
                        <option>Suggested Match</option>
                        <option>Matched</option>
                    </select>
                </div>
                <div class="span-2">
                    <label class="filter-label" for="recSearch">Search</label>
                    <input class="filter-ctl" id="recSearch" type="search" placeholder="Search descriptions or references...">
                </div>
            </div>
        </div>
    </div>

    <div class="rec-tabs" role="tablist">
        <button type="button" class="rec-tab active" data-pane="bank">Bank</button>
        <button type="button" class="rec-tab" data-pane="cash">Cash</button>
        <button type="button" class="rec-tab" data-pane="mobile">Mobile Money</button>
    </div>

    <?php
    $renderPane = static function (string $id, array $bank, array $sys): void {
        $e = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $active = $id === 'bank' ? ' active' : '';
        echo '<div class="rec-pane' . $active . '" id="pane-' . $e($id) . '" data-pane="' . $e($id) . '">';

        echo '<div class="dash-card">';
        echo '<div class="dash-card-b">';
        echo '<div class="rec-grid3">';
        echo '<div><div class="rec-section-title">Bank Statement Lines</div>';
        echo '<div class="table-wrap"><table class="ledger-table" data-side="bank"><thead><tr><th style="width:32px;"></th><th>Date</th><th>Description</th><th>Reference</th><th class="num">Amount (TZS)</th><th>Status</th></tr></thead><tbody>';
        foreach ($bank as $row) {
            $st = strtolower((string) ($row['status'] ?? ''));
            $pill = $st === 'matched' ? 'ok' : ($st === 'suggested' ? 'sg' : 'un');
            $lbl = $st === 'matched' ? 'Matched' : ($st === 'suggested' ? 'Suggested' : 'Unmatched');
            $amt = (float) ($row['amount'] ?? 0);
            $amtStr = number_format(abs($amt), 2);
            if ($amt < 0) {
                $amtStr = '(' . $amtStr . ')';
            }
            echo '<tr data-id="' . $e((string) $row['id']) . '" data-date="' . $e((string) $row['date']) . '" data-desc="' . $e((string) $row['desc']) . '" data-ref="' . $e((string) $row['ref']) . '" data-amount="' . $e((string) $row['amount']) . '">';
            echo '<td><input type="checkbox" class="chk-bank"></td>';
            echo '<td>' . $e((string) $row['date']) . '</td>';
            echo '<td>' . $e((string) $row['desc']) . '</td>';
            echo '<td>' . $e((string) $row['ref']) . '</td>';
            echo '<td class="num">' . $e($amtStr) . '</td>';
            echo '<td><span class="rec-pill ' . $pill . '">' . $e($lbl) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';

        echo '<div class="rec-mid">';
        echo '<button type="button" class="btn-outline" id="btnAuto' . $e($id) . '"><i class="bi bi-lightning"></i> Auto Match</button>';
        echo '<button type="button" class="btn-blue" id="btnMatch' . $e($id) . '"><i class="bi bi-link-45deg"></i> Match Selected</button>';
        echo '<button type="button" class="btn-outline"><i class="bi bi-scissors"></i> Split Match</button>';
        echo '<button type="button" class="btn-outline"><i class="bi bi-sliders"></i> Adjustment</button>';
        echo '</div>';

        echo '<div><div class="rec-section-title">System Transactions</div>';
        echo '<div class="table-wrap"><table class="ledger-table" data-side="sys"><thead><tr><th style="width:32px;"></th><th>Date</th><th>Source</th><th>Reference</th><th>Customer / Vendor</th><th class="num">Amount (TZS)</th><th>Status</th></tr></thead><tbody>';
        foreach ($sys as $row) {
            $st = strtolower((string) ($row['status'] ?? ''));
            $pill = $st === 'matched' ? 'ok' : ($st === 'suggested' ? 'sg' : 'un');
            $lbl = $st === 'matched' ? 'Matched' : ($st === 'suggested' ? 'Suggested' : 'Unmatched');
            $amt = (float) ($row['amount'] ?? 0);
            $amtStr = number_format(abs($amt), 2);
            if ($amt < 0) {
                $amtStr = '(' . $amtStr . ')';
            }
            echo '<tr data-id="' . $e((string) $row['id']) . '" data-date="' . $e((string) $row['date']) . '" data-source="' . $e((string) $row['source']) . '" data-ref="' . $e((string) $row['ref']) . '" data-party="' . $e((string) $row['party']) . '" data-amount="' . $e((string) $row['amount']) . '">';
            echo '<td><input type="checkbox" class="chk-sys"></td>';
            echo '<td>' . $e((string) $row['date']) . '</td>';
            echo '<td>' . $e((string) $row['source']) . '</td>';
            echo '<td>' . $e((string) $row['ref']) . '</td>';
            echo '<td>' . $e((string) $row['party']) . '</td>';
            echo '<td class="num">' . $e($amtStr) . '</td>';
            echo '<td><span class="rec-pill ' . $pill . '">' . $e($lbl) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
        echo '</div></div></div>';

        echo '<div class="dash-card" id="detail-' . $e($id) . '">';
        echo '<div class="dash-card-h"><h3>Selected Match Details</h3></div>';
        echo '<div class="dash-card-b">';
        echo '<div class="rec-compare">';
        echo '<div class="rec-box" id="bankBox-' . $e($id) . '"><strong>Bank line</strong><br>Select a bank statement row.</div>';
        echo '<div class="rec-box" id="sysBox-' . $e($id) . '"><strong>System entry</strong><br>Select a system transaction row.</div>';
        echo '</div>';
        echo '<div class="rec-metrics">';
        echo '<div class="rec-metric">Amount difference<b id="amtDiff-' . $e($id) . '">-</b></div>';
        echo '<div class="rec-metric">Reference similarity<b id="refSim-' . $e($id) . '">-</b></div>';
        echo '<div class="rec-metric">Date variance<b id="dateVar-' . $e($id) . '">-</b></div>';
        echo '</div>';
        echo '<div class="flex flex-wrap gap-2 mt-3">';
        echo '<button type="button" class="btn-blue" id="confirm-' . $e($id) . '"><i class="bi bi-check-lg"></i> Confirm Match</button>';
        echo '<button type="button" class="btn-outline"><i class="bi bi-receipt"></i> Create Bank Charge</button>';
        echo '<button type="button" class="btn-outline"><i class="bi bi-pencil"></i> Create Adjustment Entry</button>';
        echo '</div></div></div>';

        echo '</div>';
    };

    $renderPane('bank', $bankLines, $systemLines);
    $renderPane('cash', $cashLines, $cashSystem);
    $renderPane('mobile', $mobileLines, $mobileSystem);
    ?>

    <div class="dash-card">
        <div class="dash-card-b flex flex-wrap items-center justify-between gap-3">
            <div>
                <span class="rec-foot-kv">Opening balance<b>TZS 45,200,000.00</b></span>
                <span class="rec-foot-kv">Statement balance<b>TZS 48,925,000.00</b></span>
                <span class="rec-foot-kv">Matched total<b>TZS 3,120,000.00</b></span>
                <span class="rec-foot-kv">Adjustments<b>TZS 0.00</b></span>
                <span class="rec-foot-kv">System balance<b>TZS 48,600,000.00</b></span>
                <span class="rec-foot-kv">Difference<b class="text-danger">TZS 325,000.00</b></span>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn-outline">Save Draft</button>
                <button type="button" class="btn-green"><i class="bi bi-flag-fill"></i> Finalize Reconciliation</button>
            </div>
        </div>
    </div>
</div>
</main>

<script>
(() => {
    const panes = document.querySelectorAll('.rec-pane');
    const tabs = document.querySelectorAll('.rec-tab');

    function activate(paneId) {
        panes.forEach(p => p.classList.toggle('active', p.id === 'pane-' + paneId));
        tabs.forEach(t => t.classList.toggle('active', t.dataset.pane === paneId));
    }

    tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.pane)));

    function parseAmt(v) {
        const n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    function bindPane(paneId) {
        const pane = document.getElementById('pane-' + paneId);
        if (!pane) return;
        const bankRows = Array.from(pane.querySelectorAll('table[data-side="bank"] tbody tr'));
        const sysRows = Array.from(pane.querySelectorAll('table[data-side="sys"] tbody tr'));
        const bankBox = document.getElementById('bankBox-' + paneId);
        const sysBox = document.getElementById('sysBox-' + paneId);
        const amtDiff = document.getElementById('amtDiff-' + paneId);
        const refSim = document.getElementById('refSim-' + paneId);
        const dateVar = document.getElementById('dateVar-' + paneId);

        function selectedRow(rows) {
            return rows.find(r => r.querySelector('input[type="checkbox"]')?.checked) || null;
        }

        function fmtMoney(n) {
            const abs = Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return n < 0 ? '(' + abs + ')' : abs;
        }

        function updateDetail() {
            const br = selectedRow(bankRows);
            const sr = selectedRow(sysRows);
            if (br && bankBox) {
                bankBox.innerHTML = '<strong>Bank line</strong><br>' +
                    'Date: ' + br.dataset.date + '<br>' +
                    'Description: ' + br.dataset.desc + '<br>' +
                    'Reference: ' + br.dataset.ref + '<br>' +
                    'Amount: TZS ' + fmtMoney(parseAmt(br.dataset.amount));
            } else if (bankBox) {
                bankBox.innerHTML = '<strong>Bank line</strong><br>Select a bank statement row.';
            }
            if (sr && sysBox) {
                sysBox.innerHTML = '<strong>System entry</strong><br>' +
                    'Date: ' + sr.dataset.date + '<br>' +
                    'Source: ' + sr.dataset.source + '<br>' +
                    'Reference: ' + sr.dataset.ref + '<br>' +
                    'Party: ' + sr.dataset.party + '<br>' +
                    'Amount: TZS ' + fmtMoney(parseAmt(sr.dataset.amount));
            } else if (sysBox) {
                sysBox.innerHTML = '<strong>System entry</strong><br>Select a system transaction row.';
            }
            if (br && sr && amtDiff && refSim && dateVar) {
                const da = parseAmt(br.dataset.amount) - parseAmt(sr.dataset.amount);
                amtDiff.textContent = 'TZS ' + fmtMoney(da);
                const rb = (br.dataset.ref || '').toLowerCase();
                const rs = (sr.dataset.ref || '').toLowerCase();
                let sim = 0;
                if (rb && rs) {
                    let hits = 0;
                    for (let i = 0; i < Math.min(rb.length, rs.length); i++) if (rb[i] === rs[i]) hits++;
                    sim = Math.round((hits / Math.max(rb.length, rs.length)) * 100);
                }
                refSim.textContent = sim + '%';
                const db = new Date(br.dataset.date);
                const ds = new Date(sr.dataset.date);
                const diffDays = Math.round(Math.abs(db - ds) / 86400000);
                dateVar.textContent = diffDays + ' days';
            } else {
                if (amtDiff) amtDiff.textContent = '-';
                if (refSim) refSim.textContent = '-';
                if (dateVar) dateVar.textContent = '-';
            }
        }

        function wireGroup(rows) {
            rows.forEach(r => {
                const cb = r.querySelector('input[type="checkbox"]');
                if (!cb) return;
                cb.addEventListener('change', () => {
                    if (cb.checked) {
                        rows.forEach(rr => {
                            if (rr !== r) {
                                const c = rr.querySelector('input[type="checkbox"]');
                                if (c) c.checked = false;
                            }
                        });
                    }
                    updateDetail();
                });
            });
        }

        wireGroup(bankRows);
        wireGroup(sysRows);

        const search = document.getElementById('recSearch');
        const status = document.getElementById('recStatus');
        function rowVisible(tr) {
            const q = (search && search.value ? search.value.toLowerCase() : '');
            const st = (status && status.value ? status.value.toLowerCase() : 'all');
            const txt = tr.textContent.toLowerCase();
            if (q && !txt.includes(q)) return false;
            if (st === 'all statuses' || st === 'all') return true;
            const pill = tr.querySelector('.rec-pill');
            const p = (pill ? pill.textContent : '').toLowerCase();
            if (st === 'unmatched' && !p.includes('unmatched')) return false;
            if (st === 'suggested match' && !p.includes('suggested')) return false;
            if (st === 'matched' && !p.includes('matched')) return false;
            return true;
        }
        function applyFilters() {
            [...bankRows, ...sysRows].forEach(tr => {
                tr.style.display = rowVisible(tr) ? '' : 'none';
            });
        }
        if (search) search.addEventListener('input', applyFilters);
        if (status) status.addEventListener('change', applyFilters);

        const btnAuto = document.getElementById('btnAuto' + paneId);
        if (btnAuto) btnAuto.addEventListener('click', () => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'info', title: 'Auto match', text: 'Demo: matching rules would run here.', confirmButtonColor: '#2563eb' });
            }
        });
        const btnMatch = document.getElementById('btnMatch' + paneId);
        if (btnMatch) btnMatch.addEventListener('click', () => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Matched', text: 'Demo: selected rows would be linked.', confirmButtonColor: '#2563eb' });
            }
        });
        const confirm = document.getElementById('confirm-' + paneId);
        if (confirm) confirm.addEventListener('click', () => {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Confirmed', text: 'Demo: match confirmed for selected rows.', confirmButtonColor: '#2563eb' });
            }
        });
    }

    bindPane('bank');
    bindPane('cash');
    bindPane('mobile');
})();
</script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
