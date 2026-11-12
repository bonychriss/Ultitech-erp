<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

$periodPresets = [
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'this_quarter' => 'This quarter',
    'last_quarter' => 'Last quarter',
    'this_year' => 'This year',
    'last_year' => 'Last year',
];
$period = (string) ($_GET['period'] ?? '');
if (isset($periodPresets[$period])) {
    $today = new DateTimeImmutable('today');
    $quarterStart = $today->setDate((int) $today->format('Y'), (int) (floor(((int) $today->format('n') - 1) / 3) * 3) + 1, 1);
    [$presetFrom, $presetTo] = match ($period) {
        'this_month' => [$today->modify('first day of this month'), $today],
        'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'this_quarter' => [$quarterStart, $today],
        'last_quarter' => [$quarterStart->modify('-3 months'), $quarterStart->modify('-1 day')],
        'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today],
        'last_year' => [$today->setDate((int) $today->format('Y') - 1, 1, 1), $today->setDate((int) $today->format('Y') - 1, 12, 31)],
    };
    $_GET['from_date'] = $presetFrom->format('Y-m-d');
    $_GET['to_date'] = $presetTo->format('Y-m-d');
} else {
    $period = 'custom';
}
[$from, $to] = gl_period_params();

$compareModes = ['none' => 'No comparison', 'previous' => 'Previous period', 'last_year' => 'Same period last year'];
$compare = (string) ($_GET['compare'] ?? 'none');
if (!isset($compareModes[$compare])) {
    $compare = 'none';
}
$cmpFrom = $cmpTo = null;
if ($compare === 'previous') {
    $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    $cmpTo = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
    $cmpFrom = (new DateTimeImmutable($cmpTo))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
} elseif ($compare === 'last_year') {
    $cmpFrom = (new DateTimeImmutable($from))->modify('-1 year')->format('Y-m-d');
    $cmpTo = (new DateTimeImmutable($to))->modify('-1 year')->format('Y-m-d');
}
$hasCompare = $cmpFrom !== null;

$loadPl = isset($plLoader) && is_callable($plLoader)
    ? $plLoader
    : static fn (string $f, string $t): array => gl_profit_and_loss($pdo, $f, $t);
$pl = $loadPl($from, $to);
$plCmp = $hasCompare ? $loadPl($cmpFrom, $cmpTo) : null;

$expenseSection = static function (array $a): string {
    $name = (string) $a['name'];
    if (preg_match('/\b(income|corporate|corporation)\s+tax\b/i', $name)) {
        return 'tax';
    }
    return preg_match('/cost of (goods|sales)|\bcogs\b|cost of sale/i', $name) ? 'cogs' : 'expense';
};
$sections = ['revenue' => [], 'cogs' => [], 'expense' => [], 'tax' => []];
$accounts = [];
foreach ($pl['revenue'] as $a) {
    $accounts[(int) $a['id']] = ['section' => 'revenue'] + $a;
}
foreach ($pl['expense'] as $a) {
    $accounts[(int) $a['id']] = ['section' => $expenseSection($a)] + $a;
}
if ($plCmp) {
    foreach (['revenue' => $plCmp['revenue'], 'expense' => $plCmp['expense']] as $kind => $list) {
        foreach ($list as $a) {
            $id = (int) $a['id'];
            if (!isset($accounts[$id])) {
                $accounts[$id] = [
                    'section' => $kind === 'revenue' ? 'revenue' : $expenseSection($a),
                    'amount' => 0.0, 'opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing' => 0.0,
                ] + $a;
            }
            $accounts[$id]['compare'] = (float) $a['amount'];
        }
    }
}
foreach ($accounts as $a) {
    $a['compare'] = (float) ($a['compare'] ?? 0);
    $sections[$a['section']][] = $a;
}
foreach ($sections as &$list) {
    usort($list, static fn ($x, $y) => strnatcasecmp($x['code'] . ' ' . $x['name'], $y['code'] . ' ' . $y['name']));
}
unset($list);

$taxRate = (float) (function_exists('gl_meta_get') ? (gl_meta_get($pdo, 'income_tax_rate') ?? 30) : 30);
if ($taxRate < 0 || $taxRate > 100) {
    $taxRate = 30.0;
}
$taxRateText = rtrim(rtrim(number_format($taxRate, 2), '0'), '.') . '%';

$sum = static fn (array $rows, string $key): float => round(array_sum(array_map(static fn ($r) => (float) $r[$key], $rows)), 2);
$tot = [];
foreach (['amount', 'compare'] as $k) {
    $t = [
        'revenue' => $sum($sections['revenue'], $k),
        'cogs' => $sum($sections['cogs'], $k),
        'expense' => $sum($sections['expense'], $k),
        'tax_posted' => $sum($sections['tax'], $k),
    ];
    $t['gross'] = round($t['revenue'] - $t['cogs'], 2);
    $t['pbt'] = round($t['gross'] - $t['expense'], 2);
    // With no income tax booked for the period, provide for it at the statutory rate on any profit.
    $t['tax_estimate'] = abs($t['tax_posted']) < 0.005 && $t['pbt'] > 0 ? round($t['pbt'] * $taxRate / 100, 2) : 0.0;
    $t['tax'] = round($t['tax_posted'] + $t['tax_estimate'], 2);
    $t['net'] = round($t['pbt'] - $t['tax'], 2);
    $tot[$k] = $t;
}
$cur = $tot['amount'];
$prev = $tot['compare'];
$showTaxEstimate = $cur['tax_estimate'] > 0 || $prev['tax_estimate'] > 0;
require_once __DIR__ . '/../../includes/gl/gl_bridge.php';
$taxReview = function_exists('gl_bridge_tax_review_items') ? gl_bridge_tax_review_items($pdo) : [];
$taxEstimateLabel = 'Income tax at ' . $taxRateText . ' of profit before tax';
$pctOfRevenue = static fn (float $v): string => abs($cur['revenue']) < 0.005 ? '' : number_format($v / $cur['revenue'] * 100, 1) . '%';
$change = static fn (float $now, float $before): float => round($now - $before, 2);
$changePct = static fn (float $now, float $before): string => abs($before) < 0.005 ? '' : number_format(($now - $before) / abs($before) * 100, 1) . '%';
$module = (string) ($_GET['module'] ?? 'accounting');
$sectionTitles = ['revenue' => 'Income', 'cogs' => 'Cost of Sales', 'expense' => 'Operating Expenses', 'tax' => 'Income Tax'];
$periodText = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
$compareText = $hasCompare ? date('d M Y', strtotime($cmpFrom)) . ' – ' . date('d M Y', strtotime($cmpTo)) : '';

/* Statement lines shared by the CSV and PDF exports. */
$lines = [];
foreach (['revenue', 'cogs', 'expense'] as $key) {
    if ($key === 'cogs' && !$sections['cogs'] && abs($prev['cogs']) < 0.005) {
        continue;
    }
    $lines[] = ['kind' => 'section', 'label' => $sectionTitles[$key]];
    foreach ($sections[$key] as $a) {
        $lines[] = ['kind' => 'account', 'section' => $sectionTitles[$key], 'code' => $a['code'], 'label' => $a['name'], 'amount' => (float) $a['amount'], 'compare' => (float) $a['compare']];
    }
    $lines[] = ['kind' => 'total', 'label' => 'Total ' . strtolower($sectionTitles[$key]), 'amount' => $cur[$key], 'compare' => $prev[$key]];
    if ($key === 'cogs' || ($key === 'revenue' && !$sections['cogs'] && abs($prev['cogs']) < 0.005)) {
        $lines[] = ['kind' => 'result', 'label' => 'Gross profit', 'amount' => $cur['gross'], 'compare' => $prev['gross']];
    }
}
$lines[] = ['kind' => 'result', 'label' => $cur['pbt'] >= 0 ? 'Profit before tax' : 'Loss before tax', 'amount' => $cur['pbt'], 'compare' => $prev['pbt']];
$lines[] = ['kind' => 'section', 'label' => $sectionTitles['tax']];
foreach ($sections['tax'] as $a) {
    $lines[] = ['kind' => 'account', 'section' => $sectionTitles['tax'], 'code' => $a['code'], 'label' => $a['name'], 'amount' => (float) $a['amount'], 'compare' => (float) $a['compare']];
}
if ($showTaxEstimate) {
    $lines[] = ['kind' => 'account', 'section' => $sectionTitles['tax'], 'code' => '', 'label' => $taxEstimateLabel . ' (estimate)', 'amount' => $cur['tax_estimate'], 'compare' => $prev['tax_estimate']];
}
$lines[] = ['kind' => 'total', 'label' => 'Total income tax', 'amount' => $cur['tax'], 'compare' => $prev['tax']];
$lines[] = ['kind' => 'result', 'label' => $cur['net'] >= 0 ? 'Net profit after tax' : 'Net loss after tax', 'amount' => $cur['net'], 'compare' => $prev['net']];

$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv') {
    $header = ['Section', 'Code', 'Account', 'Amount'];
    if ($hasCompare) {
        array_push($header, 'Comparison', 'Change');
    }
    $header[] = '% of revenue';
    $csv = [];
    foreach ($lines as $l) {
        if ($l['kind'] === 'section') {
            continue;
        }
        $row = [$l['section'] ?? '', $l['code'] ?? '', $l['label'], $l['amount']];
        if ($hasCompare) {
            array_push($row, $l['compare'], $change($l['amount'], $l['compare']));
        }
        $row[] = $pctOfRevenue($l['amount']);
        $csv[] = $row;
    }
    gl_send_csv("profit-and-loss-{$from}-to-{$to}.csv", $header, $csv);
}

if ($export === 'pdf') {
    $logo = gl_pdf_logo();
    $pages = [];
    $chunks = array_chunk($lines, 30) ?: [[]];
    foreach ($chunks as $pageIndex => $pageLines) {
        $c = '';
        if ($logo !== null) {
            $lw = 72.0;
            $lh = $lw * $logo['height'] / max(1, $logo['width']);
            $c .= sprintf("q %.2F 0 0 %.2F 32 %.2F cm /Logo Do Q\n", $lw, $lh, 536 + (32 - $lh) / 2);
        }
        $tx = $logo !== null ? 120.0 : 32.0;
        $c .= gl_pdf_text($tx, 558, 15, 'Profit & Loss', false, true);
        $c .= gl_pdf_text($tx, 540, 9, 'Period: ' . $from . ' to ' . $to . ($hasCompare ? '   Compared with: ' . $cmpFrom . ' to ' . $cmpTo : ''));
        $c .= gl_pdf_text(810, 540, 8, 'Generated: ' . date('Y-m-d H:i'), true);
        $c .= "0.75 w 32 528 m 810 528 l S\n";
        $cols = $hasCompare ? ['Amount' => 520, 'Comparison' => 620, 'Change' => 720, '% of revenue' => 810] : ['Amount' => 680, '% of revenue' => 810];
        $c .= gl_pdf_text(32, 512, 7.5, 'Account', false, true);
        foreach ($cols as $title => $x) {
            $c .= gl_pdf_text((float) $x, 512, 7.5, $title, true, true);
        }
        $c .= "0.5 w 32 504 m 810 504 l S\n";
        $y = 489;
        foreach ($pageLines as $l) {
            $bold = $l['kind'] !== 'account';
            $label = $l['kind'] === 'account' ? '    ' . trim(($l['code'] !== '' ? $l['code'] . '  ' : '') . $l['label']) : strtoupper($l['label']);
            $c .= gl_pdf_text(32, $y, 7.5, substr(gl_pdf_ascii($label), 0, 70), false, $bold);
            if ($l['kind'] !== 'section') {
                $values = $hasCompare
                    ? [gl_fmt($l['amount']), gl_fmt($l['compare']), gl_fmt($change($l['amount'], $l['compare'])), $pctOfRevenue($l['amount'])]
                    : [gl_fmt($l['amount']), $pctOfRevenue($l['amount'])];
                foreach (array_values($cols) as $i => $x) {
                    $c .= gl_pdf_text((float) $x, $y, 7.5, $values[$i], true, $bold);
                }
            }
            if ($l['kind'] === 'result') {
                $c .= '0.5 w 32 ' . ($y + 10) . ' m 810 ' . ($y + 10) . " l S\n";
            }
            $c .= '0.25 w 32 ' . ($y - 4) . ' m 810 ' . ($y - 4) . " l S\n";
            $y -= 14;
        }
        $c .= gl_pdf_text(810, 18, 7, 'Page ' . ($pageIndex + 1) . ' of ' . count($chunks), true);
        $pages[] = $c;
    }
    gl_pdf_send("profit-and-loss-{$from}-to-{$to}.pdf", $pages, $logo);
}

$exportUrl = static function (string $format): string {
    $q = $_GET;
    $q['export'] = $format;
    return '?' . http_build_query($q);
};

$page_title = 'Profit & Loss';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
?>
<style>
.main-content.glr.glr-pl{padding-top:8px!important;background:#f1f5f9!important}
html:not([data-theme="dark"]) body:has(.glr-pl),html:not([data-theme="dark"]) body:has(.glr-pl) .layout-main-wrapper{background:#f1f5f9!important}
.glr-pl .glr-wrap{width:100%;max-width:none;padding:0 10px}
.glr-pl-breadcrumb{display:flex;align-items:center;gap:9px;min-height:42px;padding:0 12px;margin-bottom:8px;color:#94a3b8;font-size:13px}
.glr-pl-breadcrumb a{color:#8290a2;text-decoration:none}
.glr-pl-breadcrumb a:hover{color:#2563eb}
.glr-pl-breadcrumb .separator{color:#c3cad3}
.glr-pl-breadcrumb [aria-current="page"]{color:#334155;font-weight:650}
.glr-pl-head{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px;padding:8px 12px;background:#fff;border:1px solid #e6e8eb;border-bottom:0}
.glr-pl-title{display:flex;align-items:center;gap:8px;margin:0;font-size:15px;font-weight:700;color:#334155}
.glr-pl-title i{color:#64748b;font-size:14px}
.glr-pl-date{font-size:11px;color:#7b8490;white-space:nowrap;text-align:right}
.glr-pl-date small{display:block;color:#98a1ac}
.glr-pl-filter{min-height:60px;padding:9px 12px;border:1px solid #e6e8eb;border-bottom:0;background:#fff}
.glr-pl-filter .glr-form{padding:0;gap:9px}
.glr-pl-filter label{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:#64748b}
.glr-pl-filter input,.glr-pl-filter select{height:36px;font-size:12px}
.glr-pl-filter button.glr-btn[type="submit"]{width:38px;height:36px;padding:0;justify-content:center;background:transparent;color:#2563eb;border-color:transparent}
.glr-pl-filter button.glr-btn[type="submit"]:hover{background:#eff6ff}
.glr-pl-filter button.glr-btn[type="submit"]:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.glr-pl-download{position:relative}
.glr-pl-download summary{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 9px;border-radius:4px;color:#2563eb;cursor:pointer;list-style:none;font-size:12px;font-weight:600;white-space:nowrap}
.glr-pl-download summary::-webkit-details-marker{display:none}
.glr-pl-download summary:hover,.glr-pl-download[open] summary{background:#eff6ff}
.glr-pl-download summary:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.glr-pl-download.is-downloading .fa-download{animation:glr-pl-pulse .7s ease-in-out infinite alternate}
@keyframes glr-pl-pulse{from{transform:translateY(-2px);opacity:.55}to{transform:translateY(2px);opacity:1}}
@media(prefers-reduced-motion:reduce){.glr-pl-download.is-downloading .fa-download{animation:none}}
.glr-pl-download-menu{position:absolute;top:calc(100% + 3px);right:0;z-index:5;min-width:145px;padding:4px;background:#fff;border:1px solid #e0e5eb;border-radius:5px;box-shadow:0 5px 16px rgba(15,23,42,.14)}
.glr-pl-download-menu a,.glr-pl-download-menu button{display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:0;border-radius:3px;background:transparent;color:#344054;text-decoration:none;font-size:12px;cursor:pointer;text-align:left}
.glr-pl-download-menu a:hover,.glr-pl-download-menu button:hover{background:#f1f5f9}
.glr-pl-download-menu .fa-file-csv{color:#16803c}
.glr-pl-download-menu .fa-file-pdf{color:#c62828}
.glr-pl-download-menu .fa-print{color:#475569}
.glr-pl-search{height:36px!important;min-width:170px;width:min(308px,30vw);margin-left:auto;border:1px solid #dfe3e8;border-radius:4px;padding:0 12px;font-size:12px}
.glr-pl-table-card{background:#fff;border:1px solid #e6e8eb;overflow:hidden}
.glr-pl-table-wrap{max-height:min(68vh,720px);min-height:320px;overflow:auto}
.glr-pl-table{width:100%;border-collapse:collapse;font-size:11px}
.glr-pl-table th,.glr-pl-table td{height:34px;padding:5px 12px;border-bottom:1px solid #eceef0;white-space:nowrap}
.glr-pl-table thead th{position:sticky;top:0;z-index:2;height:32px;background:#fff;color:#606a76;font-size:10px;font-weight:600;text-align:left}
.glr-pl-table .glr-pl-num{text-align:right;font-variant-numeric:tabular-nums}
.glr-pl-nominal{width:46%;min-width:250px}
.glr-pl-expand{width:22px;height:22px;margin-right:5px;padding:0;border:0;background:transparent;color:#89929d;cursor:pointer}
.glr-pl-expand:hover{color:#2563eb}
.glr-pl-account a{color:#3f4a56;text-decoration:none}
.glr-pl-account a:hover{color:#2563eb;text-decoration:underline}
.glr-pl-account-code{margin-right:5px;color:#697380}
.glr-pl-row:hover td{background:#f8fafc}
.glr-pl-section td{height:30px;background:#f8fafb;color:#334155;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.glr-pl-subtotal td{font-weight:700;color:#334155;border-bottom-color:#dfe3e8}
.glr-pl-result td{font-weight:750;color:#1e3a8a;background:#f5f8ff;border-bottom-color:#dbe4f5}
.glr-pl-muted td{color:#98a1ac;font-style:italic}
.glr-pl-expand-spacer{display:inline-block;width:27px}
.glr-pl-alert{padding:9px 12px;background:#fffbeb;border:1px solid #fde68a;border-bottom:0;color:#78350f;font-size:12px}
.glr-pl-alert summary{cursor:pointer;font-weight:600}
.glr-pl-alert summary i{margin-right:6px;color:#d97706}
.glr-pl-alert p{margin:6px 0 4px}
.glr-pl-alert ul{margin:0;padding-left:18px}
.glr-pl-alert a{color:#1d4ed8}
html[data-theme="dark"] .glr-pl-alert{background:#2a2112;border-color:#5b4515;color:#fcd34d}
@media print{.glr-pl-alert{display:none}}
.glr-pl-estimate td{color:#64748b}
.glr-pl-badge{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:9px;background:#fff7ed;color:#c2410c;font-size:9px;font-weight:600;vertical-align:1px}
.glr-pl-up{color:#15803d}.glr-pl-down{color:#b42318}
.glr-pl-detail td{height:auto;padding:0 14px 9px 48px;background:#fafbfc;white-space:normal}
.glr-pl-detail[hidden]{display:none}
.glr-pl-detail-grid{display:flex;flex-wrap:wrap;gap:8px 26px;padding:9px 0 2px;color:#66717d;font-size:10px}
.glr-pl-detail-grid span{display:inline-flex;gap:5px}
.glr-pl-detail-grid b{color:#334155;font-weight:650}
.glr-pl-table tfoot td{position:sticky;bottom:0;background:#f8fafb;font-weight:700;border-top:1px solid #dfe3e8}
.glr-pl-table tfoot .is-loss{color:#b42318}
.glr-pl-nomatch{padding:24px 12px!important;color:#77818c;text-align:center!important}
.glr-pl-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:6px 0 12px}
.glr-pl-stat{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;background:#fff;border:1px solid #edf0f5;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.03);transition:transform .15s ease,box-shadow .15s ease}
.glr-pl-stat:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(15,23,42,.06)}
.glr-pl-stat-body{min-width:0;display:flex;flex-direction:column}
.glr-pl-stat-label{font-size:11px;font-weight:400;color:#475569;line-height:1.2}
.glr-pl-stat-value{margin:1px 0;font-size:15px;font-weight:600;letter-spacing:-.01em;color:#1f2937;line-height:1.2;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.glr-pl-stat-value.is-loss{color:#b42318}
.glr-pl-stat-note{font-size:10px;font-weight:400;color:#6b7280;line-height:1.2}
.glr-pl-stat-icon{flex-shrink:0;display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;font-size:12px}
.glr-pl-stat.is-revenue .glr-pl-stat-icon{background:#eff6ff;color:#3b82f6}
.glr-pl-stat.is-gross .glr-pl-stat-icon{background:#eef2ff;color:#6366f1}
.glr-pl-stat.is-expense .glr-pl-stat-icon{background:#fff7ed;color:#f59e0b}
.glr-pl-stat.is-net .glr-pl-stat-icon{background:#ecfdf5;color:#10b981}
.glr-pl-stat.is-net-loss .glr-pl-stat-icon{background:#fef2f2;color:#ef4444}
@media(prefers-reduced-motion:reduce){.glr-pl-stat{transition:none}.glr-pl-stat:hover{transform:none}}
html[data-theme="dark"] .main-content.glr.glr-pl{background:#111827}
html[data-theme="dark"] .glr-pl-breadcrumb [aria-current="page"]{color:#dbe4ed}
html[data-theme="dark"] .glr-pl-head,html[data-theme="dark"] .glr-pl-filter,
html[data-theme="dark"] .glr-pl-table-card,html[data-theme="dark"] .glr-pl-stat{background:#17212b;border-color:#334155}
html[data-theme="dark"] .glr-pl-title,html[data-theme="dark"] .glr-pl-table td,
html[data-theme="dark"] .glr-pl-account a{color:#dbe4ed}
html[data-theme="dark"] .glr-pl-table thead th,html[data-theme="dark"] .glr-pl-table tfoot td,
html[data-theme="dark"] .glr-pl-section td{background:#17212b;color:#cbd5e1}
html[data-theme="dark"] .glr-pl-result td{background:#1b2a44;color:#c7d7fe}
html[data-theme="dark"] .glr-pl-detail td,html[data-theme="dark"] .glr-pl-row:hover td{background:#1d2935}
html[data-theme="dark"] .glr-pl-search,html[data-theme="dark"] .glr-pl-filter input,html[data-theme="dark"] .glr-pl-filter select{background:#111a23;color:#e2e8f0;border-color:#334155}
html[data-theme="dark"] .glr-pl-download-menu{background:#17212b;border-color:#334155}
html[data-theme="dark"] .glr-pl-download-menu a,html[data-theme="dark"] .glr-pl-download-menu button{color:#dbe4ed}
html[data-theme="dark"] .glr-pl-download-menu a:hover,html[data-theme="dark"] .glr-pl-download-menu button:hover{background:#1d2935}
html[data-theme="dark"] .glr-pl-detail-grid b,html[data-theme="dark"] .glr-pl-stat-value{color:#e2e8f0}
html[data-theme="dark"] .glr-pl-stat-label,html[data-theme="dark"] .glr-pl-stat-value{color:#f8fafc}
html[data-theme="dark"] .glr-pl-stat-note{color:#94a3b8}
@media(max-width:900px){.glr-pl-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){.glr-pl-summary{grid-template-columns:1fr}}
@media(max-width:680px){
  .glr-pl .glr-wrap{padding:0 6px}
  .glr-pl-breadcrumb{min-height:36px;padding:0 8px;font-size:12px}
  .glr-pl-head{align-items:flex-start;flex-wrap:wrap}
  .glr-pl-filter .glr-form{flex-wrap:wrap}
  .glr-pl-search{width:100%;margin-left:0}
  .glr-pl-table{min-width:640px}
}
@media print{
  .glr-pl-filter,.glr-pl-expand{display:none!important}
  .glr-pl .glr-wrap{padding:0}
  .glr-pl-table-wrap{max-height:none;overflow:visible}
  .glr-pl-table thead th,.glr-pl-table tfoot td{position:static}
}
</style>

<main class="main-content glr glr-pl">
  <div class="glr-wrap">
    <nav class="glr-pl-breadcrumb" aria-label="Breadcrumb">
      <a href="../">Home</a>
      <span class="separator" aria-hidden="true">/</span>
      <a href="../modules/accounting/index.php?module=<?= rawurlencode($module) ?>#financial-statements">Accounting</a>
      <span class="separator" aria-hidden="true">/</span>
      <span aria-current="page">Profit &amp; Loss</span>
    </nav>
    <section class="glr-pl-summary" aria-label="Profit and loss summary">
      <div class="glr-pl-stat is-revenue">
        <div class="glr-pl-stat-body">
          <div class="glr-pl-stat-label">Total Revenue</div>
          <div class="glr-pl-stat-value"><?= gl_fmt($cur['revenue']) ?></div>
          <div class="glr-pl-stat-note"><?= $hasCompare ? gl_h('vs ' . gl_fmt($prev['revenue'])) : 'Income for the period' ?></div>
        </div>
        <span class="glr-pl-stat-icon" aria-hidden="true"><i class="fas fa-sack-dollar"></i></span>
      </div>
      <div class="glr-pl-stat is-gross">
        <div class="glr-pl-stat-body">
          <div class="glr-pl-stat-label">Gross Profit</div>
          <div class="glr-pl-stat-value<?= $cur['gross'] < 0 ? ' is-loss' : '' ?>"><?= gl_fmt($cur['gross']) ?></div>
          <div class="glr-pl-stat-note"><?= $pctOfRevenue($cur['gross']) !== '' ? gl_h($pctOfRevenue($cur['gross']) . ' margin') : '&nbsp;' ?></div>
        </div>
        <span class="glr-pl-stat-icon" aria-hidden="true"><i class="fas fa-chart-pie"></i></span>
      </div>
      <div class="glr-pl-stat is-expense">
        <div class="glr-pl-stat-body">
          <div class="glr-pl-stat-label">Total Expenses</div>
          <div class="glr-pl-stat-value"><?= gl_fmt($cur['cogs'] + $cur['expense']) ?></div>
          <div class="glr-pl-stat-note"><?= $pctOfRevenue($cur['cogs'] + $cur['expense']) !== '' ? gl_h($pctOfRevenue($cur['cogs'] + $cur['expense']) . ' of revenue') : '&nbsp;' ?></div>
        </div>
        <span class="glr-pl-stat-icon" aria-hidden="true"><i class="fas fa-receipt"></i></span>
      </div>
      <div class="glr-pl-stat <?= $cur['net'] >= 0 ? 'is-net' : 'is-net-loss' ?>">
        <div class="glr-pl-stat-body">
          <div class="glr-pl-stat-label"><?= $cur['net'] >= 0 ? 'Net Profit (after tax)' : 'Net Loss (after tax)' ?></div>
          <div class="glr-pl-stat-value<?= $cur['net'] < 0 ? ' is-loss' : '' ?>"><?= gl_fmt($cur['net']) ?></div>
          <div class="glr-pl-stat-note"><?= $pctOfRevenue($cur['net']) !== '' ? gl_h($pctOfRevenue($cur['net']) . ' margin · tax ' . gl_fmt($cur['tax']) . ($cur['tax_estimate'] > 0 ? ' (est.)' : '')) : '&nbsp;' ?></div>
        </div>
        <span class="glr-pl-stat-icon" aria-hidden="true"><i class="fas <?= $cur['net'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' ?>"></i></span>
      </div>
    </section>

    <header class="glr-pl-head">
      <h1 class="glr-pl-title"><i class="fas fa-chart-line" aria-hidden="true"></i>Profit &amp; Loss</h1>
      <div class="glr-pl-date"><?= gl_h($periodText) ?><?php if ($hasCompare): ?><small>Compared with <?= gl_h($compareText) ?></small><?php endif; ?></div>
    </header>

    <section class="glr-pl-filter" aria-label="Profit and loss filters">
      <form method="get" class="glr-form" id="glrPlForm">
        <input type="hidden" name="module" value="<?= gl_h($module) ?>">
        <label>Period
          <select name="period" id="glrPlPeriod">
            <option value="custom"<?= $period === 'custom' ? ' selected' : '' ?>>Custom dates</option>
            <?php foreach ($periodPresets as $value => $text): ?>
              <option value="<?= gl_h($value) ?>"<?= $period === $value ? ' selected' : '' ?>><?= gl_h($text) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>From <input type="date" name="from_date" id="glrPlFrom" value="<?= gl_h($from) ?>"></label>
        <label>To <input type="date" name="to_date" id="glrPlTo" value="<?= gl_h($to) ?>"></label>
        <label>Compare
          <select name="compare" id="glrPlCompare">
            <?php foreach ($compareModes as $value => $text): ?>
              <option value="<?= gl_h($value) ?>"<?= $compare === $value ? ' selected' : '' ?>><?= gl_h($text) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="glr-btn" type="submit" aria-label="View profit and loss" title="View profit and loss"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></button>
        <details class="glr-pl-download">
          <summary aria-label="Download Profit and Loss" title="Download Profit and Loss"><i class="fas fa-download" aria-hidden="true"></i><span>Download</span></summary>
          <div class="glr-pl-download-menu">
            <a href="<?= gl_h($exportUrl('csv')) ?>" data-export-format="csv"><i class="fas fa-file-csv" aria-hidden="true"></i>Download CSV</a>
            <a href="<?= gl_h($exportUrl('pdf')) ?>" data-export-format="pdf"><i class="fas fa-file-pdf" aria-hidden="true"></i>Download PDF</a>
            <button type="button" id="glrPlPrint"><i class="fas fa-print" aria-hidden="true"></i>Print</button>
          </div>
        </details>
        <input class="glr-pl-search" id="glrPlSearch" type="search" placeholder="Search accounts" aria-label="Search accounts">
      </form>
    </section>

    <?php if ($taxReview): ?>
      <details class="glr-pl-alert">
        <summary><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><?= count($taxReview) ?> tax payment<?= count($taxReview) === 1 ? '' : 's' ?> need<?= count($taxReview) === 1 ? 's' : '' ?> a tax type (<?= gl_fmt(array_sum(array_map(static fn ($r) => (float) $r['amount'], $taxReview))) ?> held in Suspense)</summary>
        <p>These Tax &amp; Compliance voucher items have no Tax type. Open each voucher, click Edit, choose its Tax type and save; the books update automatically. <a href="../employee/user-manual.php#paying-taxes">How to fill in tax vouchers</a></p>
        <ul>
          <?php foreach ($taxReview as $r): ?>
            <li><a href="../employee/view-voucher.php?id=<?= (int) $r['id'] ?>"><?= gl_h($r['voucher_no']) ?></a> – <?= gl_h($r['payee_name']) ?><?= trim((string) $r['description']) !== '' ? ' – “' . gl_h(trim((string) $r['description'])) . '”' : '' ?> – <?= gl_fmt((float) $r['amount']) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <section class="glr-pl-table-card" aria-label="Profit and loss statement">
      <div class="glr-pl-table-wrap">
        <table class="glr-pl-table">
          <thead>
            <tr>
              <th class="glr-pl-nominal">Nominal</th>
              <th class="glr-pl-num">Amount</th>
              <?php if ($hasCompare): ?>
                <th class="glr-pl-num">Comparison</th>
                <th class="glr-pl-num">Change</th>
                <th class="glr-pl-num">Change %</th>
              <?php endif; ?>
              <th class="glr-pl-num">% of Revenue</th>
            </tr>
          </thead>
          <tbody id="glrPlRows">
          <?php
            $colspan = $hasCompare ? 6 : 3;
            $valueCells = static function (float $amount, float $compareAmount, bool $higherIsBetter) use ($hasCompare, $change, $changePct, $pctOfRevenue): void {
                echo '<td class="glr-pl-num">' . gl_fmt($amount) . '</td>';
                if ($hasCompare) {
                    $diff = $change($amount, $compareAmount);
                    $cls = abs($diff) < 0.005 ? '' : (($diff > 0) === $higherIsBetter ? ' glr-pl-up' : ' glr-pl-down');
                    echo '<td class="glr-pl-num">' . gl_fmt($compareAmount) . '</td>';
                    echo '<td class="glr-pl-num' . $cls . '">' . gl_fmt($diff) . '</td>';
                    echo '<td class="glr-pl-num' . $cls . '">' . gl_h($changePct($amount, $compareAmount)) . '</td>';
                }
                echo '<td class="glr-pl-num">' . gl_h($pctOfRevenue($amount)) . '</td>';
            };
            $showCogs = $sections['cogs'] || abs($prev['cogs']) >= 0.005;
          ?>
          <?php foreach (['revenue', 'cogs', 'expense', 'tax'] as $key):
              if ($key === 'cogs' && !$showCogs) {
                  continue;
              }
              $higherIsBetter = $key === 'revenue';
          ?>
            <?php if ($key === 'tax'): ?>
              <tr class="glr-pl-result"><td><?= $cur['pbt'] >= 0 ? 'Profit before tax' : 'Loss before tax' ?></td><?php $valueCells($cur['pbt'], $prev['pbt'], true); ?></tr>
            <?php endif; ?>
            <tr class="glr-pl-section"><td colspan="<?= $colspan ?>"><?= gl_h($sectionTitles[$key]) ?></td></tr>
            <?php if (!$sections[$key] && !($key === 'tax' && $showTaxEstimate)): ?>
              <tr class="glr-pl-muted"><td colspan="<?= $colspan ?>">None in this period</td></tr>
            <?php endif; ?>
            <?php foreach ($sections[$key] as $a):
                $accountId = (int) $a['id'];
                $ledgerUrl = 'ledger.php?' . http_build_query([
                    'module' => $module, 'account_id' => $accountId,
                    'from_date' => $from, 'to_date' => $to, 'start_date' => $from, 'end_date' => $to,
                ]);
                $searchText = strtolower(trim($a['code'] . ' ' . $a['name'] . ' ' . $sectionTitles[$key]));
            ?>
              <tr class="glr-pl-row" data-account-row data-search="<?= gl_h($searchText) ?>">
                <td class="glr-pl-account">
                  <button class="glr-pl-expand" type="button" aria-expanded="false" aria-label="Show period details for <?= gl_h($a['code'] . ' ' . $a['name']) ?>" aria-controls="glr-pl-detail-<?= $accountId ?>"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                  <a href="<?= gl_h($ledgerUrl) ?>"><span class="glr-pl-account-code"><?= gl_h($a['code'] !== '' ? $a['code'] : '-') ?></span><?= gl_h($a['name']) ?></a>
                </td>
                <?php $valueCells((float) $a['amount'], (float) $a['compare'], $higherIsBetter); ?>
              </tr>
              <tr class="glr-pl-detail" id="glr-pl-detail-<?= $accountId ?>" data-detail-row hidden>
                <td colspan="<?= $colspan ?>">
                  <div class="glr-pl-detail-grid">
                    <span>Period debits <b><?= number_format((float) $a['debit'], 2) ?></b></span>
                    <span>Period credits <b><?= number_format((float) $a['credit'], 2) ?></b></span>
                    <span>Net for period <b><?= gl_fmt((float) $a['amount']) ?></b></span>
                    <?php if ($hasCompare): ?><span>Comparison period <b><?= gl_fmt((float) $a['compare']) ?></b></span><?php endif; ?>
                    <span>Account type <b><?= gl_h(ucfirst((string) $a['type'])) ?></b></span>
                    <span><a href="<?= gl_h($ledgerUrl) ?>">Open in General Ledger</a></span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($key === 'tax' && $showTaxEstimate): ?>
              <tr class="glr-pl-row glr-pl-estimate" data-account-row data-search="<?= gl_h(strtolower($taxEstimateLabel . ' income tax')) ?>">
                <td class="glr-pl-account" title="No income tax has been booked for this period, so it is provided for at <?= gl_h($taxRateText) ?> of profit before tax. Post income tax to the Income Tax Expense account to replace this estimate.">
                  <span class="glr-pl-expand-spacer" aria-hidden="true"></span><?= gl_h($taxEstimateLabel) ?> <span class="glr-pl-badge">Estimate</span>
                </td>
                <?php $valueCells($cur['tax_estimate'], $prev['tax_estimate'], false); ?>
              </tr>
            <?php endif; ?>
            <tr class="glr-pl-subtotal"><td>Total <?= gl_h(strtolower($sectionTitles[$key])) ?></td><?php $valueCells($cur[$key], $prev[$key], $higherIsBetter); ?></tr>
            <?php if ($key === 'cogs' || ($key === 'revenue' && !$showCogs)): ?>
              <tr class="glr-pl-result"><td>Gross profit</td><?php $valueCells($cur['gross'], $prev['gross'], true); ?></tr>
            <?php endif; ?>
          <?php endforeach; ?>
            <tr class="glr-pl-muted" id="glrPlNoMatch" hidden><td class="glr-pl-nomatch" colspan="<?= $colspan ?>">No accounts match your search.</td></tr>
          </tbody>
          <tfoot>
            <tr>
              <td><?= $cur['net'] >= 0 ? 'Net profit after tax' : 'Net loss after tax' ?></td>
              <td class="glr-pl-num<?= $cur['net'] < 0 ? ' is-loss' : '' ?>"><?= gl_fmt($cur['net']) ?></td>
              <?php if ($hasCompare): $netDiff = $change($cur['net'], $prev['net']); ?>
                <td class="glr-pl-num<?= $prev['net'] < 0 ? ' is-loss' : '' ?>"><?= gl_fmt($prev['net']) ?></td>
                <td class="glr-pl-num <?= $netDiff >= 0 ? 'glr-pl-up' : 'glr-pl-down' ?>"><?= gl_fmt($netDiff) ?></td>
                <td class="glr-pl-num <?= $netDiff >= 0 ? 'glr-pl-up' : 'glr-pl-down' ?>"><?= gl_h($changePct($cur['net'], $prev['net'])) ?></td>
              <?php endif; ?>
              <td class="glr-pl-num"><?= gl_h($pctOfRevenue($cur['net'])) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

  </div>
</main>
<script>
(function () {
  var periodSelect = document.getElementById('glrPlPeriod');
  var fromInput = document.getElementById('glrPlFrom');
  var toInput = document.getElementById('glrPlTo');
  var form = document.getElementById('glrPlForm');
  if (periodSelect && form) {
    periodSelect.addEventListener('change', function () {
      if (periodSelect.value !== 'custom') form.submit();
    });
    [fromInput, toInput].forEach(function (input) {
      if (input) input.addEventListener('change', function () { periodSelect.value = 'custom'; });
    });
    var compareSelect = document.getElementById('glrPlCompare');
    if (compareSelect) compareSelect.addEventListener('change', function () { form.submit(); });
  }

  var downloadMenu = document.querySelector('.glr-pl-download');
  if (downloadMenu) {
    var label = downloadMenu.querySelector('summary span');
    var timer;
    document.addEventListener('click', function (event) {
      if (!downloadMenu.contains(event.target)) downloadMenu.open = false;
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') downloadMenu.open = false;
    });
    downloadMenu.addEventListener('click', function (event) {
      var link = event.target.closest('.glr-pl-download-menu a');
      if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
      window.clearTimeout(timer);
      downloadMenu.classList.add('is-downloading');
      downloadMenu.setAttribute('aria-busy', 'true');
      if (label) label.textContent = 'Preparing…';
      downloadMenu.open = false;
      timer = window.setTimeout(function () {
        downloadMenu.classList.remove('is-downloading');
        downloadMenu.removeAttribute('aria-busy');
        if (label) label.textContent = 'Download';
      }, 2400);
    });
    var printButton = document.getElementById('glrPlPrint');
    if (printButton) printButton.addEventListener('click', function () { downloadMenu.open = false; window.print(); });
  }

  var body = document.getElementById('glrPlRows');
  var search = document.getElementById('glrPlSearch');
  var noMatch = document.getElementById('glrPlNoMatch');
  if (!body || !search) return;
  var rows = Array.prototype.slice.call(body.querySelectorAll('[data-account-row]'));

  body.addEventListener('click', function (event) {
    var button = event.target.closest('.glr-pl-expand');
    if (!button) return;
    var expanded = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    button.innerHTML = '<i class="fas fa-chevron-' + (expanded ? 'down' : 'right') + '" aria-hidden="true"></i>';
    var detail = button.closest('[data-account-row]').nextElementSibling;
    if (detail) detail.hidden = !expanded;
  });

  search.addEventListener('input', function () {
    var query = search.value.trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (row) {
      var match = !query || (row.getAttribute('data-search') || '').indexOf(query) !== -1;
      row.hidden = !match;
      if (match) shown++;
      var detail = row.nextElementSibling;
      var button = row.querySelector('.glr-pl-expand');
      if (detail && detail.hasAttribute('data-detail-row')) detail.hidden = !match || !button || button.getAttribute('aria-expanded') !== 'true';
    });
    if (noMatch) noMatch.hidden = shown > 0 || !query;
  });
})();
</script>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
