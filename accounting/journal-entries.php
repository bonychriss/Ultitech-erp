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
$employeeHeaderTitle = 'Journal Entries';
$employeeHeaderExtraClass = 'je-page-header';
$hideHeaderCompanyBranding = true;
$jeSearchHtml = '<div class="je-header-search">'
    . '<svg class="je-header-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>'
    . '<input id="jeSearchInput" class="je-header-search-input" style="background:transparent!important;border:0!important;border-radius:9999px!important;box-shadow:none!important" type="search" placeholder="Search JE #, description, journal" autocomplete="off" aria-label="Search journal entries">'
    . '<kbd class="je-header-search-kbd">Ctrl+K</kbd>'
    . '</div>';
$jeFilterBtnHtml = '<button type="button" class="je-filter-btn" id="jeFilterBtn" style="border-radius:.65rem!important" aria-haspopup="dialog" aria-expanded="false" aria-controls="jeFiltersModal" title="Filters">'
    . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 5H3"></path><path d="M12 19H3"></path><path d="M14 3v4"></path><path d="M16 17v4"></path><path d="M21 12h-9"></path><path d="M21 19h-5"></path><path d="M21 5h-7"></path><path d="M8 10v4"></path><path d="M8 12H3"></path></svg>'
    . '<i class="je-filter-dot" id="jeFilterDot" hidden></i>'
    . '</button>';
$jeCrumbHome = function_exists('company_dashboard_url') ? company_dashboard_url() : '../select-module.php';
$jeCrumbAccounting = function_exists('company_url')
    ? company_url('modules/accounting/index.php?module=' . rawurlencode($jeModule))
    : '../modules/accounting/index.php?module=' . rawurlencode($jeModule);
$employeeHeaderSubtitle = '<nav class="je-crumbs" aria-label="Breadcrumb">'
    . '<a href="' . htmlspecialchars($jeCrumbHome, ENT_QUOTES, 'UTF-8') . '" style="color:var(--je-crumb-link)!important">Home</a>'
    . '<span class="je-crumb-sep" aria-hidden="true" style="color:var(--je-crumb-sep)!important">/</span>'
    . '<a href="' . htmlspecialchars($jeCrumbAccounting, ENT_QUOTES, 'UTF-8') . '" style="color:var(--je-crumb-link)!important">Accounting</a>'
    . '<span class="je-crumb-sep" aria-hidden="true" style="color:var(--je-crumb-sep)!important">/</span>'
    . '<span class="je-crumb-current" aria-current="page" style="color:var(--je-crumb-current)!important">Journal Entries</span>'
    . '</nav>';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<style>
.employee-header.employee-header--page-context .header-content{display:flex!important;flex-wrap:nowrap!important;align-items:flex-start!important}
.employee-header .employee-header-page-heading{flex:1 1 auto!important;min-width:0!important}
.employee-header .header-right.header-actions-tray{position:static!important;flex:0 0 auto!important;align-self:flex-start!important;margin-left:auto!important;padding-top:4px!important;width:auto!important;height:auto!important}
html[data-theme="dark"] body .layout-main-wrapper header.employee-header.employee-header--page-context.je-page-header{background:#0f172a!important;background-color:#0f172a!important;border:0!important;border-bottom:0!important;box-shadow:none!important}
.je-page-header{--je-crumb-link:#64748b;--je-crumb-sep:#cbd5e1;--je-crumb-current:#0f172a}
html[data-theme="dark"] .je-page-header{--je-crumb-link:#94a3b8;--je-crumb-sep:#475569;--je-crumb-current:#e2e8f0}
.je-header-search{position:relative;display:flex;align-items:center;width:100%;height:40px;background:#fff;border:1px solid #e2e8f0;border-radius:9999px;transition:border-color .15s,box-shadow .15s}
.je-header-search:focus-within{border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(79,70,229,.12)}
.je-header-search-icon{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:#94a3b8;pointer-events:none}
.je-header-search-input{width:100%;height:100%;border:0;outline:none;background:transparent;border-radius:9999px;padding:.55rem 4rem .55rem 2.4rem;font-size:14px;color:#0f172a;font-family:inherit}
.je-header-search-input::-webkit-search-cancel-button{display:none}
.je-header-search-kbd{position:absolute;right:.6rem;top:50%;transform:translateY(-50%);font-size:.65rem;font-family:inherit;color:#94a3b8;border:1px solid #e2e8f0;border-radius:.35rem;padding:.1rem .35rem;background:#fff;box-shadow:none;pointer-events:none}
html[data-theme="dark"] .je-header-search{background:#1e293b;border-color:#94a3b8}
html[data-theme="dark"] .je-header-search:focus-within{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.25)}
html[data-theme="dark"] .je-header-search-input{color:#f8fafc}
html[data-theme="dark"] .je-header-search-input::placeholder{color:#64748b}
html[data-theme="dark"] .je-header-search-kbd{background:#0f172a;border-color:#334155;color:#94a3b8}
@media (max-width:767.98px){.je-header-search-kbd{display:none}.je-header-search-input{padding-right:1rem}}
.je-crumbs{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:13px;font-weight:500;line-height:1.3}
.je-crumbs a{text-decoration:none}
.je-crumbs a:hover{text-decoration:underline}
.je-crumb-current{font-weight:600}
.employee-header .employee-header-page-title{font-size:26px!important;font-weight:800!important;letter-spacing:-.01em!important;line-height:1.2!important}
.je-desk{--je-blue:#2563eb;--je-ink:#0f172a;--je-muted:#64748b;--je-line:#e2e8f0;--je-soft:#f8fafc;--je-kpi-radius:14px;max-width:1600px;margin:0 auto;padding:0.75rem 1.5rem 2rem;font-family:Inter,system-ui,-apple-system,sans-serif;color:var(--je-ink)}
.je-desk *{box-sizing:border-box}
.je-hero{display:grid;grid-template-columns:1fr minmax(16rem,36rem) 1fr;align-items:center;gap:1rem 1.25rem;margin:-2.5rem 0 1.25rem;position:relative;z-index:1000}
.je-hero-search{grid-column:2;width:100%;min-width:0;justify-self:center}
.je-hero-actions{grid-column:3;display:flex;gap:.6rem;flex-wrap:nowrap;align-items:center;justify-content:flex-end;position:relative}
@media (max-width:900px){
  .je-hero{grid-template-columns:1fr;gap:.75rem}
  .je-hero-search,.je-hero-actions{grid-column:1;justify-self:stretch}
}
.je-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem 1rem;border-radius:.65rem;font-size:.8125rem;font-weight:650;text-decoration:none;border:1px solid var(--je-line);background:#fff;color:#334155;cursor:pointer;font-family:inherit}
.je-btn:hover{background:var(--je-soft);color:#0f172a}
.je-hero-actions .je-btn{white-space:nowrap}
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
.je-filter-btn{position:relative;width:38px;height:38px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--je-line);border-radius:.65rem!important;background:#fff;color:#2563eb;cursor:pointer;padding:0}
.je-filter-btn:hover,.je-filter-btn[aria-expanded="true"]{background:#eff6ff}
.je-filter-dot{position:absolute;top:6px;right:6px;width:7px;height:7px;border-radius:50%;background:#2563eb}
.je-filter-dot[hidden]{display:none!important}
.je-active-filters{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 .6rem}
.je-active-filters[hidden]{display:none!important}
.je-filter-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 9px 4px 11px;border-radius:8px;border:1px solid #93c5fd;background:#eff6ff;color:#1e40af;font-size:11.5px;font-weight:600;font-family:inherit;line-height:1.5;cursor:pointer}
.je-filter-chip:hover{background:#dbeafe}
.je-filter-chip svg{flex-shrink:0;opacity:.75}
.je-filter-chip--clear{background:transparent;border-color:#cbd5e1;color:#475569;padding:4px 11px}
.je-filter-chip--clear:hover{background:#f1f5f9}
.je-filters-backdrop{position:fixed;inset:0;z-index:1100;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.45)}
.je-filters-backdrop[hidden]{display:none!important}
.je-filters-panel{width:384px;max-width:100%;max-height:calc(100vh - 32px);display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.18);overflow:hidden;font-family:var(--erp-font-family,'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif);color:#0f172a}
.je-filters-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:15px 16px 12px;border-bottom:1px solid #f1f5f9}
.je-filters-title{margin:0;font-size:15px;font-weight:700;line-height:1.2;color:#0f172a}
.je-filters-sub{margin:4px 0 0;font-size:12px;line-height:1.35;color:#64748b}
.je-filters-close{flex-shrink:0;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#475569;cursor:pointer;padding:0}
.je-filters-close:hover{background:#f8fafc;color:#0f172a}
.je-filters-body{flex:1 1 auto;overflow:auto;padding-bottom:14px}
.je-filters-section{padding:13px 16px 0}
.je-filters-section-label{margin-bottom:8px;font-size:10.9px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#64748b}
.je-date-presets{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.je-date-preset{padding:4.5px 10.5px;border:1px solid #e2e8f0;border-radius:9999px;background:#fff;color:#334155;font-size:11.5px;font-weight:600;font-family:inherit;cursor:pointer}
.je-date-preset:hover{background:#f8fafc}
.je-date-preset.is-active{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.je-filters-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.je-field{display:flex;flex-direction:column;gap:5px;min-width:0}
.je-field label{margin:0;font-size:11.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.je-field input,.je-field select{width:100%;height:34px;padding:6px 10px;border:1px solid #e2e8f0;background:#fff;color:#0f172a;font-size:13px;font-family:inherit}
.je-field input:focus,.je-field select:focus{outline:none;border-color:#93c5fd;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.je-filters-footer{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #f1f5f9}
.je-fbtn{display:inline-flex;align-items:center;justify-content:center;padding:.5rem 1rem;border-radius:10px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;border:1px solid transparent}
.je-fbtn-ghost{background:#fff;border-color:#e2e8f0;color:#334155}
.je-fbtn-ghost:hover{background:#f8fafc}
.je-fbtn-primary{background:#2563eb;border-color:#2563eb;color:#fff}
.je-fbtn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
.je-results{display:flex;flex-direction:column;min-width:0;font-family:var(--erp-font-family,'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif)}
.je-results-head{display:flex;align-items:center;justify-content:space-between;padding:0 0 .5rem;gap:.5rem}
.je-results-count{font-size:14px;font-weight:600;color:#94a3b8}
.je-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;width:100%;max-width:100%;overscroll-behavior:contain;scrollbar-width:thin}
.je-table-wrap::-webkit-scrollbar{width:10px;height:10px}
.je-table-wrap::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:8px}
.je-table{width:100%;min-width:900px;table-layout:auto;border-collapse:separate;border-spacing:0;font-size:13px}
.je-table th{text-align:left;padding:.75rem .85rem;background:#f8fafc;color:#000;font-size:13px;font-weight:600;border-bottom:1px solid #e2e8f0;white-space:nowrap;vertical-align:middle}
.je-table thead th{position:sticky;top:0;z-index:4;background:#f8fafc;box-shadow:inset 0 -1px 0 #e2e8f0}
.je-table th.col-ref,.je-table td.col-ref{position:sticky;left:0;z-index:3;background:#fff;width:8rem;min-width:8rem}
.je-table thead th.col-ref{z-index:5;background:#f8fafc}
.je-table td{padding:.6rem .85rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#0f172a}
.je-table tbody tr:last-child td{border-bottom:0}
.je-table tbody tr:hover,.je-table tbody tr:hover td.col-ref{background:#fafafa}
.je-table th.col-debit,.je-table th.col-credit,.je-table td.col-debit,.je-table td.col-credit{text-align:right;font-variant-numeric:tabular-nums}
.je-table th.col-actions,.je-table td.col-actions{text-align:right;white-space:nowrap}
.je-table td.col-ref,.je-table td.col-date,.je-table td.col-journal,.je-table td.col-debit,.je-table td.col-credit,.je-table td.col-status{white-space:nowrap}
.je-table td.col-date{color:#64748b}
.je-table td.col-desc{max-width:24rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569}
.je-row-clickable{cursor:pointer}
.je-row-clickable:focus-visible{outline:2px solid #a5b4fc;outline-offset:-2px}
.je-ref{display:block;color:#1d4ed8;font-weight:600;white-space:nowrap;text-decoration:none}
.je-ref:hover{color:#1e40af;text-decoration:underline}
.je-amt{display:inline-block;font-weight:500;font-variant-numeric:tabular-nums;white-space:nowrap;color:#0f172a}
.je-amt-muted{color:#0f172a}
.je-type{--je-fg:#c2410c;display:inline-flex;font-size:13px;font-weight:600;line-height:1.3;white-space:nowrap;color:var(--je-fg)}
.je-type-sales{--je-fg:#2563eb}
.je-type-bank{--je-fg:#0284c7}
.je-type-purchase{--je-fg:#7c3aed}
.je-type-payroll{--je-fg:#0f766e}
.je-status{display:inline-flex;align-items:center;padding:.2rem .55rem;border-radius:999px;font-size:.68rem;font-weight:700;color:var(--je-fg)}
.je-status-posted{background:#dcfce7;--je-fg:#15803d}
.je-status-draft{background:#f1f5f9;--je-fg:#475569}
.je-status-locked{background:#e0f2fe;--je-fg:#0369a1}
.je-status-unbalanced{background:#ffedd5;--je-fg:#c2410c}
.je-actions-btn{width:1.85rem;height:1.85rem;border:1px solid #e2e8f0;border-radius:.55rem;background:#fff;color:#64748b;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;padding:0;font:inherit}
.je-actions-btn:hover,.je-actions-btn[aria-expanded="true"]{background:#f8fafc;color:#0f172a;border-color:#cbd5e1}
.je-row-actions{position:relative;display:inline-flex;justify-content:flex-end}
.je-actions-menu{position:absolute;top:calc(100% + 6px);right:0;min-width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.14);padding:6px;z-index:80;display:none}
.je-actions-menu.is-open{display:block}
.je-actions-menu.is-fixed{position:fixed;top:auto;right:auto;z-index:10050}
.je-actions-menu a,.je-actions-menu button{width:100%;border:0;background:transparent;display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;text-align:left;font-family:inherit}
.je-actions-menu a:hover,.je-actions-menu button:hover{background:#f8fafc}
.je-actions-menu i{width:16px;color:#2563eb;text-align:center}
.je-actions-menu .is-danger{color:#b91c1c}
.je-actions-menu .is-danger i{color:#dc2626}
.je-actions-menu form{margin:0}
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
  .je-desk{padding:0.75rem 1rem 1.5rem}
}
.je-m-only{display:none!important}
.je-m-meta{display:inline-flex;align-items:center;gap:6px}
@media (max-width:640px){
  .je-m-only{display:inline-flex!important}
  .je-table-wrap{border:0;background:transparent;overflow:visible;border-radius:0}
  .je-table{min-width:0;display:block}
  .je-table colgroup,.je-table thead{display:none}
  .je-table tbody{display:flex;flex-direction:column;gap:10px}
  .je-table tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 10px;padding:12px 12px 14px;border:1px solid #dbeafe;border-radius:12px;background:#fff;position:relative}
  .je-table tbody tr td,.je-table tbody tr td.col-ref{display:block;padding:0;border:0;background:transparent;position:static;width:auto;min-width:0;max-width:none}
  .je-table tbody tr:hover,.je-table tbody tr:hover td.col-ref{background:#fff}
  .je-table tbody tr td.col-ref{grid-column:1;grid-row:1;display:flex;align-items:center;gap:8px}
  .je-table td.col-ref .je-ref{font-size:15px;font-weight:700}
  .je-table td.col-actions{grid-column:2;grid-row:1;justify-self:end;align-self:start}
  .je-table tbody tr td.col-date{grid-column:1/-1;grid-row:2;display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12px;color:#64748b}
  .je-table td.col-date .je-type{font-size:12px}
  .je-table td.col-date i{font-size:11px;opacity:.85}
  .je-table td.col-desc{grid-column:1/-1;grid-row:3;white-space:normal;overflow:visible;font-size:12.5px;line-height:1.45;color:#475569}
  .je-table td.col-journal,.je-table td.col-status{display:none}
  .je-table td.col-debit,.je-table td.col-credit{grid-row:4;text-align:left;border:1px solid #e2e8f0;border-radius:9px;padding:8px 10px}
  .je-table td.col-debit{grid-column:1}
  .je-table td.col-credit{grid-column:2}
  .je-table td.col-debit::before,.je-table td.col-credit::before{display:block;font-size:11px;font-weight:500;color:#64748b;margin-bottom:3px}
  .je-table td.col-debit::before{content:"Debit"}
  .je-table td.col-credit::before{content:"Credit"}
  .je-table td.col-debit .je-amt,.je-table td.col-credit .je-amt{font-weight:700;font-size:13px;white-space:normal}
  .je-table td.col-debit .je-amt::before,.je-table td.col-credit .je-amt::before{content:"TZS "}
  .je-table tbody tr td[colspan]{grid-column:1/-1}
}
html[data-theme="dark"] .je-desk{--je-ink:#e2e8f0;--je-muted:#94a3b8;--je-line:#334155;--je-soft:#0f172a}
html[data-theme="dark"] .je-kpi-card{background:#1e293b;border-color:#334155;box-shadow:none}
html[data-theme="dark"] .je-filter-btn{color:#60a5fa;background:#0f172a;border-color:#334155}
html[data-theme="dark"] .je-filter-btn:hover,html[data-theme="dark"] .je-filter-btn[aria-expanded="true"]{background:#1e293b}
html[data-theme="dark"] .je-filter-dot{background:#60a5fa}
html[data-theme="dark"] .je-filter-chip{background:rgba(37,99,235,.18);border-color:rgba(96,165,250,.5);color:#bfdbfe}
html[data-theme="dark"] .je-filter-chip:hover{background:rgba(37,99,235,.3)}
html[data-theme="dark"] .je-filter-chip--clear{background:transparent;border-color:#475569;color:#cbd5e1}
html[data-theme="dark"] .je-filter-chip--clear:hover{background:#1e293b}
html[data-theme="dark"] .je-filters-backdrop{background:rgba(2,6,23,.6)}
html[data-theme="dark"] .je-filters-panel{background:#1e293b;border-color:#334155;box-shadow:0 10px 30px rgba(0,0,0,.35);color:#f8fafc}
html[data-theme="dark"] .je-filters-head,html[data-theme="dark"] .je-filters-footer{border-color:#334155}
html[data-theme="dark"] .je-filters-title{color:#f8fafc}
html[data-theme="dark"] .je-filters-sub,html[data-theme="dark"] .je-filters-section-label,html[data-theme="dark"] .je-field label{color:#94a3b8}
html[data-theme="dark"] .je-filters-close,html[data-theme="dark"] .je-date-preset,html[data-theme="dark"] .je-fbtn-ghost{background:#0f172a;border-color:#334155;color:#e2e8f0}
html[data-theme="dark"] .je-filters-close:hover,html[data-theme="dark"] .je-date-preset:hover,html[data-theme="dark"] .je-fbtn-ghost:hover{background:#334155}
html[data-theme="dark"] .je-date-preset.is-active{background:rgba(37,99,235,.25);border-color:#3b82f6;color:#bfdbfe}
html[data-theme="dark"] .je-field input,html[data-theme="dark"] .je-field select{background:#0f172a;border-color:#334155;color:#f8fafc;color-scheme:dark}
html[data-theme="dark"] .je-field input:focus,html[data-theme="dark"] .je-field select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.25)}
html[data-theme="dark"] .je-kpi-value{color:#f1f5f9}
html[data-theme="dark"] .je-kpi-value--warn{color:#f87171}
html[data-theme="dark"] .je-kpi-icon--blue{background:rgba(79,70,229,.22);color:#a5b4fc}
html[data-theme="dark"] .je-kpi-icon--green,html[data-theme="dark"] .je-kpi-icon--teal{background:rgba(5,150,105,.22);color:#6ee7b7}
html[data-theme="dark"] .je-kpi-icon--orange{background:rgba(217,119,6,.22);color:#fbbf24}
html[data-theme="dark"] .je-kpi-icon--red{background:rgba(220,38,38,.22);color:#fca5a5}
html[data-theme="dark"] .je-kpi-icon--purple{background:rgba(124,58,237,.22);color:#c4b5fd}
html[data-theme="dark"] .je-btn{background:#1e293b;border-color:#334155;color:#e2e8f0}
html[data-theme="dark"] .je-btn:hover{background:#334155;color:#fff}
html[data-theme="dark"] .je-btn-primary,html[data-theme="dark"] .je-btn-primary i{color:#fff!important}
html[data-theme="dark"] .je-btn-primary{background:#2563eb;border-color:#2563eb}
html[data-theme="dark"] .je-btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
html[data-theme="dark"] .je-pager span{background:#1e293b;border-color:#334155;color:#e2e8f0}
html[data-theme="dark"] .je-pager .is-active{background:#2563eb;border-color:#2563eb;color:#fff}
html[data-theme="dark"] .je-status-posted{background:rgba(21,128,61,.28);--je-fg:#86efac}
html[data-theme="dark"] .je-status-draft{background:rgba(148,163,184,.18);--je-fg:#e2e8f0}
html[data-theme="dark"] .je-status-locked{background:rgba(3,105,161,.28);--je-fg:#7dd3fc}
html[data-theme="dark"] .je-status-unbalanced{background:rgba(194,65,12,.28);--je-fg:#fdba74}
html[data-theme="dark"] .je-type{--je-fg:#fb923c}
html[data-theme="dark"] .je-type-sales{--je-fg:#93c5fd}
html[data-theme="dark"] .je-type-bank{--je-fg:#7dd3fc}
html[data-theme="dark"] .je-type-purchase{--je-fg:#c4b5fd}
html[data-theme="dark"] .je-type-payroll{--je-fg:#5eead4}
html[data-theme="dark"] .je-table td.col-desc,html[data-theme="dark"] .je-table td.col-date{color:#cbd5e1}
html[data-theme="dark"] .je-actions-btn,html[data-theme="dark"] .je-actions-menu{background:#1e293b;border-color:#475569;color:#e2e8f0}
html[data-theme="dark"] .je-amt,html[data-theme="dark"] .je-table td{color:#e2e8f0}
html[data-theme="dark"] .je-table-wrap{background:#1e293b;border-color:#475569}
html[data-theme="dark"] .je-table thead th,html[data-theme="dark"] .je-table thead th.col-ref{background:#0f172a;color:#f8fafc;box-shadow:inset 0 -1px 0 #64748b}
html[data-theme="dark"] .je-table td,html[data-theme="dark"] .je-table td.col-ref{background:#1e293b;border-bottom-color:#334155}
html[data-theme="dark"] .je-table tbody tr:hover td{background:#334155}
html[data-theme="dark"] .je-ref{color:#93c5fd}
html[data-theme="dark"] .je-actions-menu{--je-menu-fg:#e2e8f0}
html[data-theme="dark"] .je-actions-menu a,html[data-theme="dark"] .je-actions-menu button{color:#e2e8f0}
html[data-theme="dark"] .je-actions-menu a:hover,html[data-theme="dark"] .je-actions-menu button:hover{background:#334155}
@media (max-width:640px){
  html[data-theme="dark"] .je-table-wrap{background:transparent}
  html[data-theme="dark"] .je-table tbody tr{background:#0f1b2e;border-color:#1e3a5f}
  html[data-theme="dark"] .je-table tbody tr td,html[data-theme="dark"] .je-table tbody tr td.col-ref,html[data-theme="dark"] .je-table tbody tr:hover td{background:transparent!important}
  html[data-theme="dark"] .je-table tbody tr:hover{background:#0f1b2e}
  html[data-theme="dark"] .je-table td.col-debit,html[data-theme="dark"] .je-table td.col-credit{border-color:#1f2d45}
  html[data-theme="dark"] .je-table td.col-desc,html[data-theme="dark"] .je-table td.col-date{color:#94a3b8}
  html[data-theme="dark"] .je-table td.col-debit::before,html[data-theme="dark"] .je-table td.col-credit::before{color:#8b9ab3}
}
</style>

<main class="main-content">
  <div class="je-desk">
    <div class="je-hero">
      <div class="je-hero-search"><?= $jeSearchHtml ?></div>
      <div class="je-hero-actions">
        <?= $jeFilterBtnHtml ?>
        <a class="je-btn je-btn-primary" style="color:#fff!important" href="<?= htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-plus" style="color:#fff!important"></i> Create Entry</a>
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

    <section class="je-results">
    <div class="je-results-head">
      <div class="je-results-count" id="jeShowingLabel"><?= number_format(min(100, $totalEntries)) ?> results</div>
    </div>
    <div class="je-active-filters" id="jeActiveFilters" aria-label="Active filters" hidden></div>

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
                  <span class="je-status je-m-only <?= $statusClass ?>" style="color:var(--je-fg)!important"><?= htmlspecialchars($pillLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td class="col-date">
                  <span class="je-m-meta"><i class="far fa-calendar je-m-only" aria-hidden="true"></i><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="je-m-meta je-m-only <?= htmlspecialchars($typeClass, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--je-fg)!important"><i class="fas fa-book-open" aria-hidden="true"></i><?= htmlspecialchars($journalType, ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td class="col-desc" title="<?= htmlspecialchars((string) $je['_desc'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $je['_desc'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="col-journal"><span class="<?= htmlspecialchars($typeClass, ENT_QUOTES, 'UTF-8') ?>" style="color:var(--je-fg)!important"><?= htmlspecialchars($journalType, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="col-debit"><span class="je-amt"><?= number_format((float) $je['_debit'], 2) ?></span></td>
                <td class="col-credit"><span class="je-amt je-amt-muted"><?= number_format((float) $je['_credit'], 2) ?></span></td>
                <td class="col-status"><span class="je-status <?= $statusClass ?>" style="color:var(--je-fg)!important"><?= htmlspecialchars($pillLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td class="col-actions">
                  <div class="je-row-actions" data-je-ignore>
                    <button type="button" class="je-actions-btn" aria-haspopup="true" aria-expanded="false" title="Actions" aria-label="Actions for <?= htmlspecialchars($entryNo, ENT_QUOTES, 'UTF-8') ?>">
                      <i class="fas fa-ellipsis-vertical" aria-hidden="true"></i>
                    </button>
                    <div class="je-actions-menu" role="menu" hidden>
                      <a role="menuitem" style="color:var(--je-menu-fg,#0f172a)!important" href="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-eye"></i> View</a>
                      <?php if ($canEdit): ?>
                        <a role="menuitem" style="color:var(--je-menu-fg,#0f172a)!important" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-pen"></i> Edit</a>
                      <?php else: ?>
                        <button type="button" role="menuitem" disabled style="opacity:.5;cursor:not-allowed;" title="Locked entries cannot be edited"><i class="fas fa-pen"></i> Edit</button>
                      <?php endif; ?>
                      <a role="menuitem" style="color:var(--je-menu-fg,#0f172a)!important" href="<?= htmlspecialchars($csvUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-csv"></i> Download CSV</a>
                      <a role="menuitem" style="color:var(--je-menu-fg,#0f172a)!important" href="<?= htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-pdf"></i> Download PDF</a>
                      <?php if ($canReverse): ?>
                        <form method="post" action="<?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?>" data-je-reverse>
                          <input type="hidden" name="reverse_entry" value="1">
                          <button type="submit" role="menuitem" class="is-danger"><i class="fas fa-rotate-left"></i> Reverse Entry</button>
                        </form>
                      <?php elseif ($reversalUrl !== ''): ?>
                        <a role="menuitem" style="color:var(--je-menu-fg,#0f172a)!important" href="<?= htmlspecialchars($reversalUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-rotate-left"></i> View Reversal</a>
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
    </section>
  </div>

  <div class="je-filters-backdrop" id="jeFiltersModal" role="presentation" hidden>
    <div class="je-filters-panel" role="dialog" aria-modal="true" aria-labelledby="jeFiltersTitle">
      <div class="je-filters-head">
        <div>
          <h2 class="je-filters-title" id="jeFiltersTitle">Filters</h2>
          <p class="je-filters-sub">Narrow the list by date, journal, and status.</p>
        </div>
        <button type="button" class="je-filters-close" id="jeFiltersClose" aria-label="Close filters" style="border-radius:8px!important">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>
        </button>
      </div>
      <div class="je-filters-body">
        <div class="je-filters-section">
          <div class="je-filters-section-label">Date range</div>
          <div class="je-date-presets" role="group" aria-label="Quick date ranges">
            <button type="button" class="je-date-preset" data-preset="month" style="border-radius:9999px!important">This month</button>
            <button type="button" class="je-date-preset" data-preset="30" style="border-radius:9999px!important">Last 30 days</button>
            <button type="button" class="je-date-preset" data-preset="clear" style="border-radius:9999px!important">Clear dates</button>
          </div>
          <div class="je-filters-grid">
            <div class="je-field">
              <label for="jeDateFrom">From</label>
              <input id="jeDateFrom" type="date" style="border-radius:8px!important">
            </div>
            <div class="je-field">
              <label for="jeDateTo">To</label>
              <input id="jeDateTo" type="date" style="border-radius:8px!important">
            </div>
          </div>
        </div>
        <div class="je-filters-section">
          <div class="je-filters-section-label">Details</div>
          <div class="je-filters-grid">
            <div class="je-field">
              <label for="jeStatusFilter">Status</label>
              <select id="jeStatusFilter" style="border-radius:8px!important">
                <option value="all">All</option>
                <option value="posted">Posted</option>
                <option value="draft">Draft</option>
                <option value="locked">Locked</option>
                <option value="unbalanced">Unbalanced</option>
              </select>
            </div>
            <div class="je-field">
              <label for="jeJournalFilter">Journal</label>
              <select id="jeJournalFilter" style="border-radius:8px!important">
                <option value="all">All</option>
                <option value="general">General Journal</option>
                <option value="sales">Sales Journal</option>
                <option value="purchase">Purchase Journal</option>
                <option value="bank">Bank Journal</option>
                <option value="payroll">Payroll Journal</option>
                <option value="payment">Payment Voucher</option>
              </select>
            </div>
          </div>
        </div>
      </div>
      <div class="je-filters-footer">
        <button type="button" class="je-fbtn je-fbtn-ghost" id="jeFiltersClear" style="border-radius:10px!important">Clear</button>
        <button type="button" class="je-fbtn je-fbtn-primary" id="jeFiltersApply" style="border-radius:10px!important">Apply filters</button>
      </div>
    </div>
  </div>
</main>

<script>
(function () {
  var search = document.getElementById('jeSearchInput');
  var statusFilter = document.getElementById('jeStatusFilter');
  var journalFilter = document.getElementById('jeJournalFilter');
  var dateFrom = document.getElementById('jeDateFrom');
  var dateTo = document.getElementById('jeDateTo');
  var filterBtn = document.getElementById('jeFilterBtn');
  var filterDot = document.getElementById('jeFilterDot');
  var modal = document.getElementById('jeFiltersModal');
  var modalClose = document.getElementById('jeFiltersClose');
  var modalClear = document.getElementById('jeFiltersClear');
  var modalApply = document.getElementById('jeFiltersApply');
  var chipsWrap = document.getElementById('jeActiveFilters');
  var presetBtns = Array.prototype.slice.call(document.querySelectorAll('.je-date-preset'));
  var rows = Array.prototype.slice.call(document.querySelectorAll('#jeTable tbody tr'));
  var showing = document.getElementById('jeShowingLabel');
  var showingFooter = document.getElementById('jeShowingFooter');
  var openMenu = null;
  var openBtn = null;
  var openHome = null;
  var filters = { status: 'all', journal: 'all', from: '', to: '' };

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function isoDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function displayDate(iso) {
    var p = (iso || '').split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : iso;
  }
  function presetRange(preset) {
    var today = new Date();
    if (preset === 'month') return { from: isoDate(new Date(today.getFullYear(), today.getMonth(), 1)), to: isoDate(today) };
    if (preset === '30') {
      var start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 29);
      return { from: isoDate(start), to: isoDate(today) };
    }
    return { from: '', to: '' };
  }
  function matchingPreset(from, to) {
    if (!from && !to) return '';
    var keys = ['month', '30'];
    for (var i = 0; i < keys.length; i++) {
      var r = presetRange(keys[i]);
      if (r.from === from && r.to === to) return keys[i];
    }
    return '';
  }
  function optionLabel(select, value) {
    if (!select) return value;
    for (var i = 0; i < select.options.length; i++) {
      if (select.options[i].value === value) return select.options[i].text;
    }
    return value;
  }
  function syncPresetButtons() {
    var active = matchingPreset(dateFrom ? dateFrom.value : '', dateTo ? dateTo.value : '');
    presetBtns.forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-preset') === active);
    });
  }

  function applyFilters() {
    var q = (search && search.value || '').toLowerCase().trim();
    var visible = 0;

    rows.forEach(function (row) {
      if (!row.getAttribute('data-status')) return;
      var text = (row.textContent || '').toLowerCase();
      var rowStatus = row.getAttribute('data-status') || '';
      var rowJournal = row.getAttribute('data-journal') || '';
      var rowDate = (row.getAttribute('data-date') || '').slice(0, 10);
      var ok = (!q || text.indexOf(q) !== -1)
        && (filters.status === 'all' || rowStatus === filters.status)
        && (filters.journal === 'all' || rowJournal.indexOf(filters.journal) !== -1)
        && (!filters.from || (rowDate !== '' && rowDate >= filters.from))
        && (!filters.to || (rowDate !== '' && rowDate <= filters.to));
      row.style.display = ok ? '' : 'none';
      if (ok) visible += 1;
    });

    if (showing) showing.textContent = visible + (visible === 1 ? ' result' : ' results');
    if (showingFooter) showingFooter.textContent = 'Showing ' + visible + ' of <?= (int) $totalEntries ?> entries';
    renderChips();
  }

  function chipButton(label, onRemove, extraClass) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'je-filter-chip' + (extraClass ? ' ' + extraClass : '');
    b.style.setProperty('border-radius', '8px', 'important');
    b.appendChild(document.createTextNode(label));
    if (!extraClass) {
      b.title = 'Remove filter';
      b.insertAdjacentHTML('beforeend', '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>');
    }
    b.addEventListener('click', onRemove);
    return b;
  }

  function renderChips() {
    if (!chipsWrap) return;
    chipsWrap.innerHTML = '';
    var count = 0;
    if (filters.from || filters.to) {
      var preset = matchingPreset(filters.from, filters.to);
      var label = preset === 'month' ? 'This month'
        : preset === '30' ? 'Last 30 days'
        : (filters.from && filters.to) ? (displayDate(filters.from) + ' – ' + displayDate(filters.to))
        : filters.from ? ('From ' + displayDate(filters.from))
        : ('Until ' + displayDate(filters.to));
      chipsWrap.appendChild(chipButton('Date: ' + label, function () { filters.from = ''; filters.to = ''; applyFilters(); }));
      count++;
    }
    if (filters.status !== 'all') {
      chipsWrap.appendChild(chipButton('Status: ' + optionLabel(statusFilter, filters.status), function () { filters.status = 'all'; applyFilters(); }));
      count++;
    }
    if (filters.journal !== 'all') {
      chipsWrap.appendChild(chipButton('Journal: ' + optionLabel(journalFilter, filters.journal), function () { filters.journal = 'all'; applyFilters(); }));
      count++;
    }
    if (count > 0) {
      chipsWrap.appendChild(chipButton('All data', function () { clearFilters(); }, 'je-filter-chip--clear'));
    }
    chipsWrap.hidden = count === 0;
    if (filterDot) filterDot.hidden = count === 0;
  }

  function clearFilters() {
    filters = { status: 'all', journal: 'all', from: '', to: '' };
    applyFilters();
  }

  function openFilters() {
    if (!modal) return;
    if (statusFilter) statusFilter.value = filters.status;
    if (journalFilter) journalFilter.value = filters.journal;
    if (dateFrom) dateFrom.value = filters.from;
    if (dateTo) dateTo.value = filters.to;
    syncPresetButtons();
    modal.hidden = false;
    if (filterBtn) filterBtn.setAttribute('aria-expanded', 'true');
    if (modalApply) modalApply.focus();
  }

  function closeFilters() {
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    if (filterBtn) {
      filterBtn.setAttribute('aria-expanded', 'false');
      filterBtn.focus();
    }
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

  if (search) search.addEventListener('input', applyFilters);

  if (filterBtn) filterBtn.addEventListener('click', openFilters);
  if (modalClose) modalClose.addEventListener('click', closeFilters);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeFilters();
    });
  }
  presetBtns.forEach(function (b) {
    b.addEventListener('click', function () {
      var r = presetRange(b.getAttribute('data-preset'));
      if (dateFrom) dateFrom.value = r.from;
      if (dateTo) dateTo.value = r.to;
      syncPresetButtons();
    });
  });
  [dateFrom, dateTo].forEach(function (el) {
    if (el) el.addEventListener('change', syncPresetButtons);
  });
  if (modalApply) {
    modalApply.addEventListener('click', function () {
      var from = dateFrom ? dateFrom.value : '';
      var to = dateTo ? dateTo.value : '';
      if (from && to && from > to) {
        var tmp = from; from = to; to = tmp;
      }
      filters = {
        status: statusFilter ? statusFilter.value : 'all',
        journal: journalFilter ? journalFilter.value : 'all',
        from: from,
        to: to
      };
      closeFilters();
      applyFilters();
    });
  }
  if (modalClear) {
    modalClear.addEventListener('click', function () {
      closeFilters();
      clearFilters();
    });
  }

  rows.forEach(function (row) {
    var href = row.getAttribute('data-href');
    if (!href) return;
    row.addEventListener('click', function (e) {
      if (e.target.closest('[data-je-ignore], a, button, .je-actions-menu')) return;
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

  document.addEventListener('click', function (e) {
    if (openMenu && !e.target.closest('.je-actions-menu') && !e.target.closest('.je-actions-btn')) {
      closeOpenMenu();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeOpenMenu();
      closeFilters();
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
