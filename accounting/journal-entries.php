<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
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
$unbalancedEntries = 0;
$thisMonthEntries = 0;
$totalDebitSum = 0.0;
$totalCreditSum = 0.0;
$monthKey = date('Y-m');

foreach ($journals as &$je) {
    $status = strtolower((string) ($je['status'] ?? 'posted'));
    if (!in_array($status, ['draft', 'posted', 'locked'], true)) {
        $status = 'posted';
    }
    $je['_status'] = $status;

    $debit = (float) ($je['total_debit'] ?? 0);
    $credit = (float) ($je['total_credit'] ?? 0);
    $je['_debit'] = $debit;
    $je['_credit'] = $credit;
    $je['_diff'] = $debit - $credit;
    $je['_unbalanced'] = abs($debit - $credit) >= 0.01;

    if ($status === 'draft') {
        $draftEntries++;
    } elseif ($status === 'locked') {
        $lockedEntries++;
    } else {
        $postedEntries++;
    }
    if ($je['_unbalanced']) {
        $unbalancedEntries++;
        if ($status !== 'draft') {
            $je['_status'] = 'unbalanced';
        }
    }

    $totalDebitSum += $debit;
    $totalCreditSum += $credit;

    $jeDate = (string) ($je['date'] ?? '');
    if ($jeDate !== '' && str_starts_with($jeDate, $monthKey)) {
        $thisMonthEntries++;
    }

    $desc = trim((string) ($je['description'] ?? ''));
    $journalType = 'General Journal';
    if (preg_match('/^\[([^\]]+)\]/', $desc, $tagMatch)) {
        $tag = trim($tagMatch[1]);
        $tagMap = [
            'General Journal' => 'General Journal',
            'Sales Journal' => 'Sales Journal',
            'Bank Journal' => 'Bank Journal',
            'Purchase Journal' => 'Purchase Journal',
            'Payment Voucher' => 'Payment Voucher',
            'Payroll Journal' => 'Payroll Journal',
        ];
        if (isset($tagMap[$tag])) {
            $journalType = $tagMap[$tag];
        }
    } elseif ($desc !== '') {
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
    $je['_journal_type'] = $journalType;
    $je['_desc'] = $desc !== '' ? $desc : '—';

    $entryNo = trim((string) ($je['entry_number'] ?? ''));
    $ref = trim((string) ($je['reference'] ?? ''));
    $je['_is_reversal'] = (stripos($desc, 'Reversal of ') === 0) || (stripos($ref, 'REV:') === 0);
    $je['_reversal_id'] = 0;
}
unset($je);

// Map original entry_number → reversal journal id (for Actions menu)
$reversalBySource = [];
try {
    $hasReferenceCol = function_exists('columnExists') && columnExists('erp_journal_entries', 'reference', $pdo);
    $revSelect = $hasReferenceCol
        ? 'SELECT id, reference, description, entry_number FROM erp_journal_entries WHERE reference LIKE \'REV:%\' OR description LIKE \'Reversal of %\' ORDER BY id DESC'
        : 'SELECT id, description, entry_number FROM erp_journal_entries WHERE description LIKE \'Reversal of %\' ORDER BY id DESC';
    $revRows = $pdo->query($revSelect)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($revRows as $rr) {
        $src = '';
        if ($hasReferenceCol) {
            $ref = trim((string) ($rr['reference'] ?? ''));
            if (stripos($ref, 'REV:') === 0) {
                $src = trim(substr($ref, 4));
            }
        }
        if ($src === '') {
            $d = (string) ($rr['description'] ?? '');
            if (preg_match('/^Reversal of\s+(JE-[\w-]+)/i', $d, $m)) {
                $src = trim($m[1]);
            }
        }
        if ($src !== '' && !isset($reversalBySource[$src])) {
            $reversalBySource[$src] = (int) ($rr['id'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $reversalBySource = [];
}
foreach ($journals as &$je) {
    $entryNo = trim((string) ($je['entry_number'] ?? ''));
    if ($entryNo === '') {
        $entryNo = 'JE-' . str_pad((string) ((int) ($je['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
    }
    $revId = (int) ($reversalBySource[$entryNo] ?? 0);
    $je['_reversal_id'] = $revId;
    $hasLines = ((float) ($je['_debit'] ?? 0) + (float) ($je['_credit'] ?? 0)) > 0.00001;
    $je['_can_reverse'] = empty($je['_is_reversal']) && $revId <= 0 && $hasLines;
}
unset($je);

$jeModule = isset($_GET['module']) ? (string) $_GET['module'] : 'balances';
$jeModuleEsc = htmlspecialchars($jeModule, ENT_QUOTES, 'UTF-8');
$createUrl = 'create-journal.php?module=' . rawurlencode($jeModule);

if (!function_exists('je_format_money')) {
    function je_format_money(float $n): string
    {
        return 'TZS ' . number_format($n, 2);
    }
}
if (!function_exists('je_money_size_class')) {
    function je_money_size_class(string $formatted): string
    {
        $digits = preg_replace('/\D/', '', $formatted) ?? '';
        $len = strlen($digits);
        if ($len >= 13) {
            return ' is-xxl';
        }
        if ($len >= 11) {
            return ' is-xl';
        }
        if ($len >= 9) {
            return ' is-lg';
        }
        return '';
    }
}

$page_title = 'Journal Entries';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<style>
.je-desk{--je-blue:#2563eb;--je-ink:#0f172a;--je-muted:#64748b;--je-line:#e2e8f0;--je-soft:#f8fafc;--je-kpi-radius:14px;max-width:1600px;margin:0 auto;padding:1.25rem 1.5rem 2rem;font-family:Inter,system-ui,-apple-system,sans-serif;color:var(--je-ink)}
.je-desk *{box-sizing:border-box}
.je-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
.je-hero h1{margin:0;font-size:1.85rem;font-weight:800;letter-spacing:-.03em;line-height:1.15}
.je-hero p{margin:.35rem 0 0;color:var(--je-muted);font-size:.9rem}
.je-hero-actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;position:relative}
.je-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem 1rem;border-radius:.65rem;font-size:.8125rem;font-weight:650;text-decoration:none;border:1px solid var(--je-line);background:#fff;color:#334155;cursor:pointer;font-family:inherit}
.je-btn:hover{background:var(--je-soft);color:#0f172a}
.je-btn-primary{background:var(--je-blue);border-color:var(--je-blue);color:#fff}
.je-btn-primary:hover{background:#1d4ed8;color:#fff}
.je-kpi-grid{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:stretch;gap:.55rem;width:100%;margin-bottom:1rem}
.je-kpi-grid>.je-kpi-card{flex:1 1 0;min-width:0}
.je-kpi-card{width:100%;min-height:0;padding:.5rem .7rem;border:1px solid #e8edf3;border-radius:var(--je-kpi-radius);background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;align-items:center;gap:.5rem;text-align:left;overflow:hidden}
.je-kpi-icon{width:1.75rem;height:1.75rem;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem}
.je-kpi-icon--blue{background:#eef2ff;color:#4f46e5}
.je-kpi-icon--green{background:#ecfdf5;color:#059669}
.je-kpi-icon--teal{background:#ecfdf5;color:#059669}
.je-kpi-icon--orange{background:#fffbeb;color:#d97706}
.je-kpi-icon--red{background:#fef2f2;color:#dc2626}
.je-kpi-icon--purple{background:#f5f3ff;color:#7c3aed}
.je-kpi-body{min-width:0;flex:1 1 auto}
.je-kpi-label{font-size:.7rem;font-weight:500;color:#94a3b8;line-height:1.2}
.je-kpi-value{font-size:clamp(.95rem,1.4vw,1.15rem);font-weight:600;line-height:1.1;margin-top:.05rem;color:#0f172a;min-width:0;max-width:100%;overflow:hidden}
.je-kpi-value--money{font-size:clamp(.78rem,1.2vw,.95rem);font-weight:600;font-variant-numeric:tabular-nums;letter-spacing:-.01em;white-space:nowrap}
.je-kpi-value--money.is-lg{font-size:clamp(.7rem,1.05vw,.85rem)}
.je-kpi-value--money.is-xl{font-size:clamp(.64rem,.95vw,.78rem);letter-spacing:-.02em}
.je-kpi-value--money.is-xxl{font-size:clamp(.56rem,.85vw,.7rem);letter-spacing:-.03em}
.je-kpi-value--warn{color:#dc2626}
.je-kpi-foot{display:block;margin-top:0;font-size:.6rem;font-weight:400;color:#94a3b8;line-height:1.2}
.je-toolbar{display:flex;gap:.65rem;flex-wrap:wrap;align-items:center;margin-bottom:.9rem;background:#fff;border:1px solid var(--je-line);border-radius:.9rem;padding:.7rem .8rem;box-shadow:0 1px 2px rgba(15,23,42,.03)}
.je-search{position:relative;flex:1 1 240px;min-width:200px}
.je-search i{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.8rem}
.je-search input{width:100%;border:1px solid var(--je-line);border-radius:.6rem;padding:.55rem .75rem .55rem 2.1rem;font-size:.8125rem;background:var(--je-soft);color:var(--je-ink)}
.je-search input:focus{outline:none;border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.je-search .kbd{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);font-size:.65rem;color:#94a3b8;border:1px solid #e2e8f0;border-radius:.35rem;padding:.1rem .35rem;background:#fff}
.je-select,.je-date{border:1px solid var(--je-line);border-radius:.6rem;padding:.55rem .75rem;font-size:.8125rem;background:#fff;color:#334155;min-width:120px}
.je-results-head{display:flex;align-items:center;justify-content:space-between;padding:0 0 .55rem}
.je-results-count{font-size:.9rem;font-weight:700;color:#64748b}
.je-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;width:100%;max-width:100%;overscroll-behavior:contain;scrollbar-width:thin}
.je-table-wrap::-webkit-scrollbar{width:10px;height:10px}
.je-table-wrap::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:8px}
.je-table{width:max-content;min-width:100%;table-layout:auto;border-collapse:separate;border-spacing:0;font-size:.78rem}
.je-table th{text-align:left;padding:.55rem .45rem;background:#f8fafc;color:#0f172a;font-size:.75rem;font-weight:600;border-bottom:1px solid #e2e8f0;white-space:nowrap;vertical-align:middle}
.je-table th:first-child,.je-table td:first-child{padding-left:.75rem}
.je-table th:last-child,.je-table td:last-child{padding-right:.75rem}
.je-table thead th{position:sticky;top:0;z-index:4;background:#f8fafc;box-shadow:inset 0 -1px 0 #e2e8f0}
.je-table th.col-ref,.je-table td.col-ref{position:sticky;left:0;z-index:3;background:#fff;width:7.25rem;min-width:7.25rem;max-width:7.25rem;padding-right:.3rem}
.je-table th.col-date,.je-table td.col-date{width:6.25rem;min-width:6.25rem;max-width:6.25rem;padding-left:.25rem;padding-right:.35rem}
.je-table thead th.col-ref{z-index:5;background:#f8fafc}
.je-table tbody tr:hover td.col-ref{background:#fffbeb}
.je-table td{padding:.55rem .45rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155}
.je-table tbody tr:hover{background:#fffbeb}
.je-table th.col-debit,.je-table th.col-credit,.je-table td.col-debit,.je-table td.col-credit{text-align:right;font-variant-numeric:tabular-nums;padding-left:.35rem;padding-right:.45rem}
.je-table th.col-status,.je-table td.col-status{text-align:center;padding-left:.3rem;padding-right:.3rem}
.je-table th.col-actions,.je-table td.col-actions{text-align:right;white-space:nowrap;padding-left:.35rem}
.je-table td.col-ref,.je-table td.col-date,.je-table td.col-journal,.je-table td.col-debit,.je-table td.col-credit,.je-table td.col-status{white-space:nowrap}
.je-table td.col-desc{max-width:22rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569}
.je-row-clickable{cursor:pointer}
.je-row-clickable:focus-visible{outline:2px solid #d97706;outline-offset:-2px}
.je-ref{display:block;color:#000;font-weight:700;white-space:nowrap;text-decoration:none}
.je-ref:hover{color:#0f172a}
.je-amt{display:inline-block;font-size:.72rem;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap;letter-spacing:-.02em;color:#16a34a}
.je-amt-muted{color:#475569;font-weight:600}
.je-type{display:inline-flex;font-size:.75rem;font-weight:700;line-height:1.3;white-space:nowrap;color:#c2410c}
.je-type-sales{color:#2563eb}
.je-type-bank{color:#0284c7}
.je-type-purchase{color:#7c3aed}
.je-type-payroll{color:#0f766e}
.je-status{display:inline-flex;padding:.12rem .4rem;border-radius:9999px;font-size:.68rem;font-weight:700}
.je-status-posted{background:#dcfce7;color:#166534}
.je-status-draft{background:#dbeafe;color:#1d4ed8}
.je-status-locked{background:#ede9fe;color:#6d28d9}
.je-status-unbalanced{background:#ffedd5;color:#c2410c}
.je-actions-btn{width:2rem;height:2rem;border:1px solid #e2e8f0;border-radius:.55rem;background:#fff;color:#64748b;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;padding:0;font:inherit}
.je-actions-btn:hover,.je-actions-btn[aria-expanded="true"]{background:#f8fafc;color:#0f172a;border-color:#cbd5e1}
.je-row-actions{position:relative;display:inline-flex;justify-content:flex-end}
.je-actions-menu,.je-more-menu{position:absolute;top:calc(100% + 6px);right:0;min-width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.14);padding:6px;z-index:80;display:none}
.je-actions-menu.is-open,.je-more-menu.is-open{display:block}
.je-actions-menu.is-fixed{position:fixed;top:auto;right:auto;z-index:10050}
.je-actions-menu a,.je-actions-menu button,.je-more-menu a,.je-more-menu button{width:100%;border:0;background:transparent;display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;text-align:left;font-family:inherit}
.je-actions-menu a:hover,.je-actions-menu button:hover,.je-more-menu a:hover,.je-more-menu button:hover{background:#f8fafc}
.je-actions-menu i,.je-more-menu i{width:16px;color:#2563eb;text-align:center}
.je-actions-menu .is-danger{color:#b91c1c}
.je-actions-menu .is-danger i{color:#dc2626}
.je-actions-menu form{margin:0}
.je-more-wrap{position:relative;display:inline-flex}
.je-footer{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;padding:.85rem 0 0;color:var(--je-muted);font-size:.78rem}
.je-pager{display:flex;gap:.35rem;align-items:center}
.je-pager span{min-width:1.85rem;height:1.85rem;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--je-line);border-radius:.45rem;background:#fff;font-weight:600;font-size:.75rem}
.je-pager .is-active{background:var(--je-blue);border-color:var(--je-blue);color:#fff}
.je-empty{text-align:center;padding:2.5rem 1rem;color:#94a3b8}
@media (max-width:920px){
  .je-kpi-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem}
  .je-kpi-grid>.je-kpi-card{flex:none;width:100%}
  .je-kpi-card{padding:.65rem .7rem}
  .je-kpi-value{font-size:1rem}
  .je-kpi-value--money{font-size:.78rem}
}
@media (max-width:768px){
  .je-desk{padding:1rem}
  .je-hero h1{font-size:1.5rem}
}
</style>

<main class="main-content">
  <div class="je-desk">
    <div class="je-hero">
      <div>
        <h1>Journal Entries</h1>
        <p>Keep debits and credits balanced.</p>
      </div>
      <div class="je-hero-actions">
        <a class="je-btn je-btn-primary" href="<?= htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-plus"></i> Create Entry</a>
        <div class="je-more-wrap" id="jeMoreWrap">
          <button type="button" class="je-btn" id="jeMoreActionsBtn" aria-haspopup="true" aria-expanded="false"><i class="fas fa-ellipsis"></i> More Actions</button>
          <div class="je-more-menu" id="jeMoreMenu" role="menu" hidden>
            <a role="menuitem" href="<?= htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-plus"></i> Create Entry</a>
            <button type="button" role="menuitem" id="jeExportCsvBtn"><i class="fas fa-file-csv"></i> Export CSV</button>
            <button type="button" role="menuitem" id="jeResetFiltersMenu"><i class="fas fa-filter-circle-xmark"></i> Clear Filters</button>
          </div>
        </div>
      </div>
    </div>

    <div class="je-kpi-grid" aria-label="Journal summary">
      <div class="je-kpi-card" title="<?= number_format($totalEntries) ?> entries">
        <div class="je-kpi-icon je-kpi-icon--blue" aria-hidden="true"><i class="far fa-file-lines"></i></div>
        <div class="je-kpi-body">
          <div class="je-kpi-label">Total Entries</div>
          <div class="je-kpi-value"><?= number_format($totalEntries) ?></div>
          <div class="je-kpi-foot"><?= number_format($postedEntries) ?> posted · <?= number_format($draftEntries) ?> draft</div>
        </div>
      </div>
      <?php
        $debitLabel = je_format_money($totalDebitSum);
        $creditLabel = je_format_money($totalCreditSum);
      ?>
      <div class="je-kpi-card" title="<?= htmlspecialchars($debitLabel, ENT_QUOTES, 'UTF-8') ?>">
        <div class="je-kpi-icon je-kpi-icon--green" aria-hidden="true"><i class="fas fa-arrow-down"></i></div>
        <div class="je-kpi-body">
          <div class="je-kpi-label">Total Debit</div>
          <div class="je-kpi-value je-kpi-value--money<?= je_money_size_class($debitLabel) ?>"><?= htmlspecialchars($debitLabel, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="je-kpi-foot">Sum of line debits</div>
        </div>
      </div>
      <div class="je-kpi-card" title="<?= htmlspecialchars($creditLabel, ENT_QUOTES, 'UTF-8') ?>">
        <div class="je-kpi-icon je-kpi-icon--teal" aria-hidden="true"><i class="fas fa-arrow-up"></i></div>
        <div class="je-kpi-body">
          <div class="je-kpi-label">Total Credit</div>
          <div class="je-kpi-value je-kpi-value--money<?= je_money_size_class($creditLabel) ?>"><?= htmlspecialchars($creditLabel, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="je-kpi-foot">Sum of line credits</div>
        </div>
      </div>
      <div class="je-kpi-card" title="<?= number_format($unbalancedEntries) ?> unbalanced">
        <div class="je-kpi-icon je-kpi-icon--<?= $unbalancedEntries > 0 ? 'red' : 'orange' ?>" aria-hidden="true"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="je-kpi-body">
          <div class="je-kpi-label">Unbalanced</div>
          <div class="je-kpi-value<?= $unbalancedEntries > 0 ? ' je-kpi-value--warn' : '' ?>"><?= number_format($unbalancedEntries) ?></div>
          <div class="je-kpi-foot"><?= $unbalancedEntries > 0 ? 'Requires attention' : 'All balanced' ?></div>
        </div>
      </div>
      <div class="je-kpi-card" title="<?= number_format($thisMonthEntries) ?> this month">
        <div class="je-kpi-icon je-kpi-icon--purple" aria-hidden="true"><i class="far fa-calendar"></i></div>
        <div class="je-kpi-body">
          <div class="je-kpi-label">This Month</div>
          <div class="je-kpi-value"><?= number_format($thisMonthEntries) ?></div>
          <div class="je-kpi-foot"><?= htmlspecialchars(date('F Y'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </div>
    </div>

    <div class="je-toolbar">
      <div class="je-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input id="jeSearchInput" type="search" placeholder="Search journal entries..." autocomplete="off" aria-label="Search journal entries">
        <span class="kbd">Ctrl+K</span>
      </div>
      <select id="jeStatusFilter" class="je-select" aria-label="Status">
        <option value="all">Status</option>
        <option value="posted">Posted</option>
        <option value="draft">Draft</option>
        <option value="locked">Locked</option>
        <option value="unbalanced">Unbalanced</option>
      </select>
      <select id="jeJournalFilter" class="je-select" aria-label="Journal">
        <option value="all">Journal</option>
        <option value="general">General Journal</option>
        <option value="sales">Sales Journal</option>
        <option value="purchase">Purchase Journal</option>
        <option value="bank">Bank Journal</option>
        <option value="payroll">Payroll Journal</option>
        <option value="payment">Payment Voucher</option>
      </select>
      <input id="jeDateFilter" class="je-date" type="date" aria-label="Date">
      <button type="button" class="je-btn" id="jeResetFilters"><i class="fas fa-filter"></i> More Filters</button>
    </div>

    <div class="je-results-head">
      <div class="je-results-count" id="jeShowingLabel"><?= number_format(min(100, $totalEntries)) ?> entries</div>
    </div>

    <div class="je-table-wrap">
      <table class="je-table" id="jeTable">
        <colgroup>
          <col class="col-ref">
          <col class="col-date">
          <col class="col-desc">
          <col class="col-journal">
          <col class="col-debit">
          <col class="col-credit">
          <col class="col-status">
          <col class="col-actions">
        </colgroup>
        <thead>
          <tr>
            <th class="col-ref">JE Number</th>
            <th class="col-date">Date</th>
            <th class="col-desc">Description</th>
            <th class="col-journal">Journal</th>
            <th class="col-debit">Debit</th>
            <th class="col-credit">Credit</th>
            <th class="col-status">Status</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($journals)): ?>
            <tr><td colspan="8"><div class="je-empty">No journal entries yet.</div></td></tr>
          <?php else: ?>
            <?php foreach ($journals as $je): ?>
              <?php
              $viewUrl = 'view-journal.php?id=' . (int) ($je['id'] ?? 0) . '&module=' . rawurlencode($jeModule);
              $status = (string) ($je['_status'] ?? 'posted');
              $statusClass = 'je-status-posted';
              $pillLabel = 'Posted';
              if ($status === 'draft') {
                  $statusClass = 'je-status-draft';
                  $pillLabel = 'Draft';
              } elseif ($status === 'locked') {
                  $statusClass = 'je-status-locked';
                  $pillLabel = 'Locked';
              } elseif ($status === 'unbalanced' || !empty($je['_unbalanced'])) {
                  $statusClass = 'je-status-unbalanced';
                  $pillLabel = 'Unbalanced';
                  $status = 'unbalanced';
              }
              $journalType = (string) ($je['_journal_type'] ?? 'General Journal');
              $journalKey = strtolower(str_replace([' Journal', ' Voucher'], '', $journalType));
              $typeClass = 'je-type';
              if ($journalKey === 'sales') {
                  $typeClass .= ' je-type-sales';
              } elseif ($journalKey === 'bank') {
                  $typeClass .= ' je-type-bank';
              } elseif ($journalKey === 'purchase') {
                  $typeClass .= ' je-type-purchase';
              } elseif ($journalKey === 'payroll') {
                  $typeClass .= ' je-type-payroll';
              }
              $entryNo = trim((string) ($je['entry_number'] ?? ''));
              if ($entryNo === '') {
                  $entryNo = 'JE-' . str_pad((string) ((int) ($je['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
              }
              $dateLabel = '—';
              if (!empty($je['date'])) {
                  $ts = strtotime((string) $je['date']);
                  if ($ts !== false) {
                      $dateLabel = date('d/m/Y', $ts);
                  }
              }
              $csvUrl = $viewUrl . '&download=journal';
              $pdfUrl = $viewUrl . '&auto_pdf=1';
              $editUrl = 'create-journal.php?id=' . (int) ($je['id'] ?? 0) . '&module=' . rawurlencode($jeModule);
              $canEdit = $status !== 'locked';
              $canReverse = !empty($je['_can_reverse']);
              $reversalId = (int) ($je['_reversal_id'] ?? 0);
              $reversalUrl = $reversalId > 0
                  ? ('view-journal.php?id=' . $reversalId . '&module=' . rawurlencode($jeModule))
                  : '';
              ?>
              <tr
                class="je-row-clickable"
                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                data-journal="<?= htmlspecialchars($journalKey, ENT_QUOTES, 'UTF-8') ?>"
                data-date="<?= htmlspecialchars((string) ($je['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"
                data-entry="<?= htmlspecialchars($entryNo, ENT_QUOTES, 'UTF-8') ?>"
                tabindex="0"
              >
                <td class="col-ref">
                  <a class="je-ref" href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($entryNo, ENT_QUOTES, 'UTF-8') ?></a>
                </td>
                <td class="col-date"><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="col-desc" title="<?= htmlspecialchars((string) $je['_desc'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $je['_desc'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="col-journal"><span class="<?= htmlspecialchars($typeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($journalType, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="col-debit"><span class="je-amt"><?= number_format((float) $je['_debit'], 2) ?></span></td>
                <td class="col-credit"><span class="je-amt je-amt-muted"><?= number_format((float) $je['_credit'], 2) ?></span></td>
                <td class="col-status"><span class="je-status <?= $statusClass ?>"><?= htmlspecialchars($pillLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="col-actions">
                  <div class="je-row-actions" data-je-ignore>
                    <button type="button" class="je-actions-btn" aria-haspopup="true" aria-expanded="false" title="Actions" aria-label="Actions for <?= htmlspecialchars($entryNo, ENT_QUOTES, 'UTF-8') ?>">
                      <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
                    </button>
                    <div class="je-actions-menu" role="menu" hidden>
                      <a role="menuitem" href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-eye"></i> View</a>
                      <?php if ($canEdit): ?>
                        <a role="menuitem" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-pen"></i> Edit</a>
                      <?php else: ?>
                        <button type="button" role="menuitem" disabled style="opacity:.5;cursor:not-allowed;" title="Locked entries cannot be edited"><i class="fas fa-pen"></i> Edit</button>
                      <?php endif; ?>
                      <a role="menuitem" href="<?= htmlspecialchars($csvUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-csv"></i> Download CSV</a>
                      <a role="menuitem" href="<?= htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-pdf"></i> Download PDF</a>
                      <?php if ($canReverse): ?>
                        <form method="post" action="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" data-je-reverse>
                          <input type="hidden" name="reverse_entry" value="1">
                          <button type="submit" role="menuitem" class="is-danger"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
                        </form>
                      <?php elseif ($reversalUrl !== ''): ?>
                        <a role="menuitem" href="<?= htmlspecialchars($reversalUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-rotate-left"></i> View Reversal</a>
                      <?php else: ?>
                        <button type="button" role="menuitem" disabled style="opacity:.5;cursor:not-allowed;" title="<?= !empty($je['_is_reversal']) ? 'Already a reversal' : 'Nothing to reverse' ?>"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="je-footer">
      <div id="jeShowingFooter">Showing <?= number_format(min(100, $totalEntries)) ?> of <?= number_format($totalEntries) ?> entries</div>
      <div class="je-pager">
        <span><i class="fas fa-angle-left"></i></span>
        <span class="is-active">1</span>
        <span>2</span>
        <span>3</span>
        <span><i class="fas fa-angle-right"></i></span>
      </div>
    </div>
  </div>
</main>

<script>
(function () {
  var search = document.getElementById('jeSearchInput');
  var statusFilter = document.getElementById('jeStatusFilter');
  var journalFilter = document.getElementById('jeJournalFilter');
  var dateFilter = document.getElementById('jeDateFilter');
  var resetBtn = document.getElementById('jeResetFilters');
  var rows = Array.prototype.slice.call(document.querySelectorAll('#jeTable tbody tr'));
  var showing = document.getElementById('jeShowingLabel');
  var showingFooter = document.getElementById('jeShowingFooter');
  var openMenu = null;
  var openBtn = null;
  var openHome = null;

  function clearFilters() {
    if (search) search.value = '';
    if (statusFilter) statusFilter.value = 'all';
    if (journalFilter) journalFilter.value = 'all';
    if (dateFilter) dateFilter.value = '';
    applyFilters();
  }

  function applyFilters() {
    var q = (search && search.value || '').toLowerCase().trim();
    var status = statusFilter && statusFilter.value || 'all';
    var journal = journalFilter && journalFilter.value || 'all';
    var dateVal = dateFilter && dateFilter.value || '';
    var visible = 0;

    rows.forEach(function (row) {
      if (!row.getAttribute('data-status')) return;
      var text = (row.textContent || '').toLowerCase();
      var rowStatus = row.getAttribute('data-status') || '';
      var rowJournal = row.getAttribute('data-journal') || '';
      var rowDate = row.getAttribute('data-date') || '';
      var ok = (!q || text.indexOf(q) !== -1)
        && (status === 'all' || rowStatus === status)
        && (journal === 'all' || rowJournal.indexOf(journal) !== -1)
        && (!dateVal || rowDate === dateVal);
      row.style.display = ok ? '' : 'none';
      if (ok) visible += 1;
    });

    if (showing) showing.textContent = visible + ' entries';
    if (showingFooter) showingFooter.textContent = 'Showing ' + visible + ' of <?= (int) $totalEntries ?> entries';
  }

  function closeOpenMenu() {
    if (!openMenu) return;
    openMenu.classList.remove('is-open', 'is-fixed');
    openMenu.setAttribute('hidden', '');
    openMenu.style.left = '';
    openMenu.style.top = '';
    if (openHome && openMenu.parentNode !== openHome) {
      openHome.appendChild(openMenu);
    }
    if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    openMenu = null;
    openBtn = null;
    openHome = null;
  }

  function positionFixedMenu(btn, menu) {
    var rect = btn.getBoundingClientRect();
    var menuWidth = Math.max(200, menu.offsetWidth || 200);
    var left = rect.right - menuWidth;
    if (left < 8) left = 8;
    if (left + menuWidth > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - menuWidth - 8);
    }
    var top = rect.bottom + 6;
    menu.classList.add('is-fixed');
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    var mh = menu.offsetHeight || 0;
    if (top + mh > window.innerHeight - 8 && rect.top > mh + 12) {
      menu.style.top = (rect.top - mh - 6) + 'px';
    }
  }

  function openActionsMenu(btn, menu, home) {
    closeOpenMenu();
    openBtn = btn;
    openMenu = menu;
    openHome = home;
    menu.removeAttribute('hidden');
    menu.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    document.body.appendChild(menu);
    positionFixedMenu(btn, menu);
  }

  [search, statusFilter, journalFilter, dateFilter].forEach(function (el) {
    if (!el) return;
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      clearFilters();
      if (search) search.focus();
    });
  }

  rows.forEach(function (row) {
    var href = row.getAttribute('data-href');
    if (!href) return;
    row.addEventListener('click', function (e) {
      if (e.target.closest('[data-je-ignore], a, button, .je-actions-menu, .je-more-menu')) return;
      window.location.href = href;
    });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        if (e.target.closest('[data-je-ignore], a, button')) return;
        e.preventDefault();
        window.location.href = href;
      }
    });
  });

  document.querySelectorAll('.je-row-actions').forEach(function (wrap) {
    var btn = wrap.querySelector('.je-actions-btn');
    var menu = wrap.querySelector('.je-actions-menu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (openMenu === menu) {
        closeOpenMenu();
        return;
      }
      openActionsMenu(btn, menu, wrap);
    });
  });

  document.querySelectorAll('form[data-je-reverse]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var entry = '';
      var row = form.closest('tr');
      if (row) entry = row.getAttribute('data-entry') || '';
      var msg = entry
        ? ('Create a reversing journal entry for ' + entry + '? Debits and credits will be swapped.')
        : 'Create a reversing journal entry? Debits and credits will be swapped.';
      if (!window.confirm(msg)) {
        e.preventDefault();
      }
    });
  });

  var moreBtn = document.getElementById('jeMoreActionsBtn');
  var moreMenu = document.getElementById('jeMoreMenu');
  var moreWrap = document.getElementById('jeMoreWrap');
  if (moreBtn && moreMenu && moreWrap) {
    moreMenu.removeAttribute('hidden');
    moreBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = moreMenu.classList.toggle('is-open');
      moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) closeOpenMenu();
    });
  }

  var exportBtn = document.getElementById('jeExportCsvBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      if (moreMenu) moreMenu.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
      var lines = [['JE Number', 'Date', 'Description', 'Journal', 'Debit', 'Credit', 'Status']];
      rows.forEach(function (row) {
        if (!row.getAttribute('data-status') || row.style.display === 'none') return;
        var cells = row.querySelectorAll('td');
        if (cells.length < 7) return;
        lines.push([
          (cells[0].textContent || '').trim(),
          (cells[1].textContent || '').trim(),
          (cells[2].textContent || '').trim(),
          (cells[3].textContent || '').trim(),
          (cells[4].textContent || '').trim().replace(/,/g, ''),
          (cells[5].textContent || '').trim().replace(/,/g, ''),
          (cells[6].textContent || '').trim()
        ]);
      });
      var csv = lines.map(function (cols) {
        return cols.map(function (c) {
          var v = String(c == null ? '' : c);
          if (/[",\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
          return v;
        }).join(',');
      }).join('\n');
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'journal-entries.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    });
  }

  var resetMenuBtn = document.getElementById('jeResetFiltersMenu');
  if (resetMenuBtn) {
    resetMenuBtn.addEventListener('click', function () {
      if (moreMenu) moreMenu.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
      clearFilters();
      if (search) search.focus();
    });
  }

  document.addEventListener('click', function (e) {
    if (openMenu && !e.target.closest('.je-actions-menu') && !e.target.closest('.je-actions-btn')) {
      closeOpenMenu();
    }
    if (moreMenu && moreWrap && !moreWrap.contains(e.target)) {
      moreMenu.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeOpenMenu();
      if (moreMenu) moreMenu.classList.remove('is-open');
      if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      if (!search) return;
      e.preventDefault();
      search.focus();
      search.select();
    }
  });

  window.addEventListener('scroll', function () {
    if (openMenu && openBtn) positionFixedMenu(openBtn, openMenu);
  }, true);
  window.addEventListener('resize', closeOpenMenu);
})();
</script>
<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
