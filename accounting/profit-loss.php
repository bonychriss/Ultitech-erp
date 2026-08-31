<?php
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/setup_schema.php')) {
    require_once __DIR__ . '/setup_schema.php';
    if (function_exists('ensureAccountingSchema')) {
        ensureAccountingSchema();
    }
}
requireLogin();

global $pdo;

$startDate = (string) ($_GET['start_date'] ?? date('Y-m-01'));
$endDate = (string) ($_GET['end_date'] ?? date('Y-m-t'));
$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';

$startTs = strtotime($startDate) ?: strtotime(date('Y-m-01'));
$endTs = strtotime($endDate) ?: strtotime(date('Y-m-t'));
if ($endTs < $startTs) {
    [$startTs, $endTs] = [$endTs, $startTs];
    $startDate = date('Y-m-d', $startTs);
    $endDate = date('Y-m-d', $endTs);
}
$days = max(1, (int) floor(($endTs - $startTs) / 86400) + 1);
$prevEndTs = strtotime('-1 day', $startTs);
$prevStartTs = strtotime('-' . ($days - 1) . ' days', $prevEndTs);
$prevStartDate = date('Y-m-d', $prevStartTs);
$prevEndDate = date('Y-m-d', $prevEndTs);

function plFetchByType(PDO $pdo, string $type, string $from, string $to): array
{
    $sql = "SELECT a.name,
                   COALESCE(SUM(ji.credit), 0) AS c,
                   COALESCE(SUM(ji.debit), 0) AS d
            FROM erp_accounts a
            LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
            LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id AND je.date BETWEEN ? AND ?
            WHERE a.type = ?
            GROUP BY a.id, a.name
            ORDER BY a.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to, $type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $name = trim((string) ($r['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $credit = (float) ($r['c'] ?? 0);
        $debit = (float) ($r['d'] ?? 0);
        $amt = $type === 'revenue' ? ($credit - $debit) : ($debit - $credit);
        $out[$name] = $amt;
    }
    return $out;
}

function plMergeNames(array ...$maps): array
{
    $set = [];
    foreach ($maps as $m) {
        foreach (array_keys($m) as $k) {
            $set[$k] = true;
        }
    }
    $names = array_keys($set);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

function plRows(array $curr, array $prev, array $notes = []): array
{
    $rows = [];
    foreach (plMergeNames($curr, $prev) as $name) {
        $rows[] = [
            'name' => $name,
            'note' => $notes[$name] ?? '',
            'current' => (float) ($curr[$name] ?? 0),
            'previous' => (float) ($prev[$name] ?? 0),
        ];
    }
    return $rows;
}

$revCurrAll = plFetchByType($pdo, 'revenue', $startDate, $endDate);
$revPrevAll = plFetchByType($pdo, 'revenue', $prevStartDate, $prevEndDate);
$expCurrAll = plFetchByType($pdo, 'expense', $startDate, $endDate);
$expPrevAll = plFetchByType($pdo, 'expense', $prevStartDate, $prevEndDate);

$revCurr = $revCurrAll;
$revPrev = $revPrevAll;
$cogsCurr = [];
$cogsPrev = [];
$opExpCurr = [];
$opExpPrev = [];
$otherCurr = [];
$otherPrev = [];

$bucketExpense = function (string $name): string {
    $n = strtolower($name);
    if (strpos($n, 'cost of goods') !== false || strpos($n, 'cogs') !== false || strpos($n, 'cost of sale') !== false) {
        return 'cogs';
    }
    if (strpos($n, 'finance') !== false || strpos($n, 'interest') !== false || strpos($n, 'bank charge') !== false) {
        return 'other';
    }
    return 'op';
};

foreach ($expCurrAll as $name => $amount) {
    $bucket = $bucketExpense($name);
    if ($bucket === 'cogs') $cogsCurr[$name] = $amount;
    elseif ($bucket === 'other') $otherCurr[$name] = -abs($amount);
    else $opExpCurr[$name] = $amount;
}
foreach ($expPrevAll as $name => $amount) {
    $bucket = $bucketExpense($name);
    if ($bucket === 'cogs') $cogsPrev[$name] = $amount;
    elseif ($bucket === 'other') $otherPrev[$name] = -abs($amount);
    else $opExpPrev[$name] = $amount;
}
foreach ($revCurrAll as $name => $amount) {
    $n = strtolower($name);
    if (strpos($n, 'finance') !== false || strpos($n, 'interest') !== false) {
        $otherCurr[$name] = abs($amount);
        unset($revCurr[$name]);
    }
}
foreach ($revPrevAll as $name => $amount) {
    $n = strtolower($name);
    if (strpos($n, 'finance') !== false || strpos($n, 'interest') !== false) {
        $otherPrev[$name] = abs($amount);
        unset($revPrev[$name]);
    }
}

$noteMapRevenue = ['Sales Revenue' => '4.1', 'Other Income' => '4.2'];
$noteMapCogs = ['Cost of Goods Sold' => '5.1'];
$noteMapOp = ['Selling & Distribution Expenses' => '6.1', 'Administrative Expenses' => '6.2', 'Other Operating Expenses' => '6.3'];
$noteMapOther = ['Finance Income' => '7.1', 'Finance Costs' => '7.2'];

$rowsRevenue = plRows($revCurr, $revPrev, $noteMapRevenue);
$rowsCogs = plRows($cogsCurr, $cogsPrev, $noteMapCogs);
$rowsOp = plRows($opExpCurr, $opExpPrev, $noteMapOp);
$rowsOther = plRows($otherCurr, $otherPrev, $noteMapOther);

$sum = function (array $rows, string $key): float {
    $t = 0.0;
    foreach ($rows as $r) $t += (float) $r[$key];
    return $t;
};
$revCurrTotal = $sum($rowsRevenue, 'current');
$revPrevTotal = $sum($rowsRevenue, 'previous');
$cogsCurrTotal = $sum($rowsCogs, 'current');
$cogsPrevTotal = $sum($rowsCogs, 'previous');
$grossCurr = $revCurrTotal - $cogsCurrTotal;
$grossPrev = $revPrevTotal - $cogsPrevTotal;
$opCurrTotal = $sum($rowsOp, 'current');
$opPrevTotal = $sum($rowsOp, 'previous');
$operatingCurr = $grossCurr - $opCurrTotal;
$operatingPrev = $grossPrev - $opPrevTotal;
$otherCurrTotal = $sum($rowsOther, 'current');
$otherPrevTotal = $sum($rowsOther, 'previous');
$netCurr = $operatingCurr + $otherCurrTotal;
$netPrev = $operatingPrev + $otherPrevTotal;

$fmt = static function (float $n): string {
    $abs = number_format(abs($n), 2);
    return $n < 0 ? '(' . $abs . ')' : $abs;
};
$varPct = static function (float $curr, float $prev): float {
    if (abs($prev) < 0.00001) return 0.0;
    return (($curr - $prev) / abs($prev)) * 100;
};
$varText = static function (float $curr, float $prev) use ($fmt): string {
    return $fmt($curr - $prev);
};
$varClass = static function (float $curr, float $prev): string {
    $d = $curr - $prev;
    if (abs($d) < 0.00001) {
        return 'flat';
    }
    return $d > 0 ? 'up' : 'down';
};

$periodLabel = date('d M Y', $startTs) . ' to ' . date('d M Y', $endTs);

include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
.main-content.pl2-shell{margin-top:0!important;padding:14px 0 24px!important;background:#f9fafb;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;color:#0f172a}
.pl2-wrap{padding:0 16px}
.pl2-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.pl2-title{margin:0;font-size:34px;font-weight:800;color:#0b1f5d;line-height:1.1}
.pl2-sub{margin:6px 0 0;font-size:14px;color:#64748b}
.pl2-bc{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px;color:#64748b}
.pl2-bc a{color:#2563eb;text-decoration:none;font-weight:700}
.pl2-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.pl2-pill{height:32px;border:1px solid #dbe2ea;border-radius:8px;background:#fff;padding:0 10px;font-size:12px;font-weight:700;color:#475569;cursor:pointer}
.pl2-pill.active{background:#2563eb;border-color:#2563eb;color:#fff}
.pl2-btn{height:34px;border:1px solid #dbe2ea;border-radius:8px;background:#fff;padding:0 12px;font-size:12px;font-weight:700;color:#0f172a;display:inline-flex;align-items:center;gap:7px;text-decoration:none;cursor:pointer}
.pl2-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:12px;align-items:start}
.pl2-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);overflow:hidden}
.pl2-main-h{padding:14px 16px;border-bottom:1px solid #eef2f7}
.pl2-main-h h2{margin:0;font-size:34px;letter-spacing:.02em;font-weight:800;text-align:center;color:#0f172a}
.pl2-main-h .p{margin:8px 0 0;font-size:22px;text-align:center;color:#334155;font-weight:600}
.pl2-head-info{display:grid;grid-template-columns:1fr auto;gap:10px;margin-top:8px;font-size:12px;color:#475569}
.pl2-table-wrap{overflow:auto}
.pl2-table{width:100%;min-width:980px;border-collapse:collapse;font-size:13px}
.pl2-table th,.pl2-table td{padding:9px 10px;border-bottom:1px solid #eef2f7;vertical-align:middle}
.pl2-table th{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:800;background:#fafafa;text-align:left;white-space:nowrap}
.pl2-table th.num-h{text-align:right}
.pl2-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.pl2-sec{font-weight:800;color:#16a34a;background:#f8fafc}
.pl2-subtotal td{font-weight:800}
.pl2-subtotal .num{color:#2563eb}
.pl2-highlight td{background:#ecfdf5;font-weight:900;color:#166534}
.pl2-final td{background:#dcfce7;font-weight:900;color:#166534}
.pl2-var.up{color:#16a34a;font-weight:800}
.pl2-var.down{color:#ef4444;font-weight:800}
.pl2-var.flat{color:#2563eb;font-weight:800}
.pl2-var.up::after{content:" \2191";font-weight:900;margin-left:3px}
.pl2-var.down::after{content:" \2193";font-weight:900;margin-left:3px}
.pl2-var.flat::after{content:" \2192";font-weight:900;margin-left:3px}
.pl2-side-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);margin-bottom:12px;overflow:hidden}
.pl2-side-h{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:14px;font-weight:800;color:#0f172a}
.pl2-side-b{padding:12px 14px}
.pl2-fg{margin-bottom:10px}.pl2-fg:last-child{margin-bottom:0}
.pl2-fg label{display:block;font-size:11px;color:#64748b;font-weight:700;margin-bottom:5px}
.pl2-ctl{width:100%;height:36px;border:1px solid #dbe2ea;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;color:#0f172a}
.pl2-apply{width:100%;height:36px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:8px;font-size:13px;font-weight:800}
.pl2-kv{display:flex;justify-content:space-between;gap:8px;margin-bottom:9px;font-size:12px;color:#64748b}
.pl2-kv span:last-child{font-weight:800;color:#0f172a;text-align:right}
.pl2-note{display:flex;gap:8px;font-size:11px;color:#64748b;line-height:1.45;margin-bottom:8px}
.pl2-note:last-child{margin-bottom:0}
.pl2-note b{color:#334155}
@media(max-width:1200px){.pl2-grid{grid-template-columns:1fr}}
</style>

<main class="main-content pl2-shell">
  <div class="pl2-wrap">
    <div class="pl2-top">
      <div>
        <h1 class="pl2-title">Profit &amp; Loss Statement</h1>
        <p class="pl2-sub">View company profitability for the selected period</p>
        <nav class="pl2-bc">
          <a href="../index.php">Home</a><i class="fas fa-chevron-right"></i>
          <a href="#">Finance &amp; Accounting</a><i class="fas fa-chevron-right"></i>
          <a href="#">Financial Reports</a><i class="fas fa-chevron-right"></i>
          <span>Profit &amp; Loss Statement</span>
        </nav>
      </div>
      <div class="pl2-actions">
        <a class="pl2-btn" href="?module=<?= $jeModule ?>&start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-t') ?>">This Month</a>
        <a class="pl2-btn" href="?module=<?= $jeModule ?>&start_date=<?= date('Y-m-01', strtotime('first day of last month')) ?>&end_date=<?= date('Y-m-t', strtotime('last day of last month')) ?>">Last Month</a>
        <button class="pl2-btn"><i class="fas fa-download"></i> Export</button>
        <button class="pl2-btn"><i class="fas fa-print"></i> Print</button>
      </div>
    </div>

    <div class="pl2-grid">
      <div class="pl2-card">
        <div class="pl2-main-h">
          <h2>PROFIT &amp; LOSS STATEMENT</h2>
          <p class="p">For the Period <?= htmlspecialchars($periodLabel) ?></p>
          <div class="pl2-head-info">
            <div>
              <strong><?= htmlspecialchars((string) (COMPANY_NAME ?? 'Ultimate General Trading Ltd')) ?></strong><br>
              Tanzania
            </div>
            <div style="text-align:right;">
              <strong>Report Date:</strong> <?= htmlspecialchars(date('d/m/Y h:i A')) ?><br>
              <strong>Prepared By:</strong> <?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'System Admin')) ?><br>
              <strong>Currency:</strong> TZS
            </div>
          </div>
        </div>
        <div class="pl2-table-wrap">
          <table class="pl2-table">
            <colgroup>
              <col style="width:34%">
              <col style="width:8%">
              <col style="width:17%">
              <col style="width:17%">
              <col style="width:16%">
              <col style="width:8%">
            </colgroup>
            <thead>
              <tr><th>Description</th><th>Note</th><th class="num-h">Current Period (TZS)</th><th class="num-h">Previous Period (TZS)</th><th class="num-h">Variance (TZS)</th><th class="num-h">Variance (%)</th></tr>
            </thead>
            <tbody>
              <tr class="pl2-sec"><td colspan="6">REVENUE</td></tr>
              <?php foreach ($rowsRevenue as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['note']) ?></td>
                  <td class="num"><?= $fmt($r['current']) ?></td><td class="num"><?= $fmt($r['previous']) ?></td>
                  <td class="num pl2-var <?= $varClass($r['current'],$r['previous']) ?>"><?= $varText($r['current'],$r['previous']) ?></td>
                  <td class="num pl2-var <?= $varClass($r['current'],$r['previous']) ?>"><?= number_format($varPct($r['current'],$r['previous']),2) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr class="pl2-subtotal"><td>Total Revenue</td><td></td><td class="num"><?= $fmt($revCurrTotal) ?></td><td class="num"><?= $fmt($revPrevTotal) ?></td><td class="num pl2-var <?= $varClass($revCurrTotal,$revPrevTotal) ?>"><?= $varText($revCurrTotal,$revPrevTotal) ?></td><td class="num pl2-var <?= $varClass($revCurrTotal,$revPrevTotal) ?>"><?= number_format($varPct($revCurrTotal,$revPrevTotal),2) ?>%</td></tr>

              <tr class="pl2-sec"><td colspan="6" style="color:#ef4444;">COST OF SALES</td></tr>
              <?php foreach ($rowsCogs as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['note']) ?></td>
                  <td class="num" style="color:#ef4444;"><?= $fmt(-abs($r['current'])) ?></td><td class="num" style="color:#ef4444;"><?= $fmt(-abs($r['previous'])) ?></td>
                  <td class="num pl2-var <?= $varClass(-abs($r['current']),-abs($r['previous'])) ?>"><?= $varText(-abs($r['current']),-abs($r['previous'])) ?></td>
                  <td class="num pl2-var <?= $varClass(-abs($r['current']),-abs($r['previous'])) ?>"><?= number_format($varPct(-abs($r['current']),-abs($r['previous'])),2) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr class="pl2-subtotal"><td>Total Cost of Sales</td><td></td><td class="num" style="color:#ef4444;"><?= $fmt(-abs($cogsCurrTotal)) ?></td><td class="num" style="color:#ef4444;"><?= $fmt(-abs($cogsPrevTotal)) ?></td><td class="num pl2-var <?= $varClass(-abs($cogsCurrTotal),-abs($cogsPrevTotal)) ?>"><?= $varText(-abs($cogsCurrTotal),-abs($cogsPrevTotal)) ?></td><td class="num pl2-var <?= $varClass(-abs($cogsCurrTotal),-abs($cogsPrevTotal)) ?>"><?= number_format($varPct(-abs($cogsCurrTotal),-abs($cogsPrevTotal)),2) ?>%</td></tr>

              <tr class="pl2-highlight"><td>GROSS PROFIT</td><td></td><td class="num"><?= $fmt($grossCurr) ?></td><td class="num"><?= $fmt($grossPrev) ?></td><td class="num"><?= $varText($grossCurr,$grossPrev) ?></td><td class="num"><?= number_format($varPct($grossCurr,$grossPrev),2) ?>%</td></tr>

              <tr class="pl2-sec"><td colspan="6" style="color:#2563eb;">OPERATING EXPENSES</td></tr>
              <?php foreach ($rowsOp as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['note']) ?></td>
                  <td class="num" style="color:#ef4444;"><?= $fmt(-abs($r['current'])) ?></td><td class="num" style="color:#ef4444;"><?= $fmt(-abs($r['previous'])) ?></td>
                  <td class="num pl2-var <?= $varClass(-abs($r['current']),-abs($r['previous'])) ?>"><?= $varText(-abs($r['current']),-abs($r['previous'])) ?></td>
                  <td class="num pl2-var <?= $varClass(-abs($r['current']),-abs($r['previous'])) ?>"><?= number_format($varPct(-abs($r['current']),-abs($r['previous'])),2) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr class="pl2-subtotal"><td>Total Operating Expenses</td><td></td><td class="num" style="color:#2563eb;"><?= $fmt(-abs($opCurrTotal)) ?></td><td class="num" style="color:#2563eb;"><?= $fmt(-abs($opPrevTotal)) ?></td><td class="num pl2-var <?= $varClass(-abs($opCurrTotal),-abs($opPrevTotal)) ?>"><?= $varText(-abs($opCurrTotal),-abs($opPrevTotal)) ?></td><td class="num pl2-var <?= $varClass(-abs($opCurrTotal),-abs($opPrevTotal)) ?>"><?= number_format($varPct(-abs($opCurrTotal),-abs($opPrevTotal)),2) ?>%</td></tr>

              <tr class="pl2-highlight"><td>OPERATING PROFIT</td><td></td><td class="num"><?= $fmt($operatingCurr) ?></td><td class="num"><?= $fmt($operatingPrev) ?></td><td class="num"><?= $varText($operatingCurr,$operatingPrev) ?></td><td class="num"><?= number_format($varPct($operatingCurr,$operatingPrev),2) ?>%</td></tr>

              <tr class="pl2-sec"><td colspan="6" style="color:#2563eb;">OTHER INCOME / (EXPENSES)</td></tr>
              <?php foreach ($rowsOther as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['note']) ?></td>
                  <td class="num"><?= $fmt($r['current']) ?></td><td class="num"><?= $fmt($r['previous']) ?></td>
                  <td class="num pl2-var <?= $varClass($r['current'],$r['previous']) ?>"><?= $varText($r['current'],$r['previous']) ?></td>
                  <td class="num pl2-var <?= $varClass($r['current'],$r['previous']) ?>"><?= number_format($varPct($r['current'],$r['previous']),2) ?>%</td>
                </tr>
              <?php endforeach; ?>
              <tr class="pl2-subtotal"><td>Net Other Income / (Expenses)</td><td></td><td class="num"><?= $fmt($otherCurrTotal) ?></td><td class="num"><?= $fmt($otherPrevTotal) ?></td><td class="num pl2-var <?= $varClass($otherCurrTotal,$otherPrevTotal) ?>"><?= $varText($otherCurrTotal,$otherPrevTotal) ?></td><td class="num pl2-var <?= $varClass($otherCurrTotal,$otherPrevTotal) ?>"><?= number_format($varPct($otherCurrTotal,$otherPrevTotal),2) ?>%</td></tr>

              <tr class="pl2-final"><td>NET PROFIT FOR THE PERIOD</td><td></td><td class="num"><?= $fmt($netCurr) ?></td><td class="num"><?= $fmt($netPrev) ?></td><td class="num"><?= $varText($netCurr,$netPrev) ?></td><td class="num"><?= number_format($varPct($netCurr,$netPrev),2) ?>%</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <aside>
        <section class="pl2-side-card">
          <div class="pl2-side-h"><i class="fas fa-filter"></i> Report Filters</div>
          <div class="pl2-side-b">
            <form method="get">
              <input type="hidden" name="module" value="<?= $jeModule ?>">
              <div class="pl2-fg"><label>Date Range</label><input class="pl2-ctl" type="text" value="<?= htmlspecialchars($startDate) ?> - <?= htmlspecialchars($endDate) ?>" readonly></div>
              <div class="pl2-fg"><label>Compare With</label><select class="pl2-ctl"><option>Previous Period</option></select></div>
              <div class="pl2-fg"><label>Accounting Basis</label><select class="pl2-ctl"><option>Accrual</option></select></div>
              <div class="pl2-fg"><label>Report Currency</label><select class="pl2-ctl"><option>TZS - Tanzanian Shilling</option></select></div>
              <button class="pl2-apply" type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
            </form>
          </div>
        </section>

        <section class="pl2-side-card">
          <div class="pl2-side-h">Summary</div>
          <div class="pl2-side-b">
            <div class="pl2-kv"><span>Total Revenue</span><span><?= $fmt($revCurrTotal) ?></span></div>
            <div class="pl2-kv"><span>Gross Profit</span><span><?= $fmt($grossCurr) ?></span></div>
            <div class="pl2-kv"><span>Operating Expenses</span><span><?= $fmt(-abs($opCurrTotal)) ?></span></div>
            <div class="pl2-kv"><span>Operating Profit</span><span><?= $fmt($operatingCurr) ?></span></div>
            <div class="pl2-kv"><span>Net Profit</span><span><?= $fmt($netCurr) ?></span></div>
            <div class="pl2-kv"><span>Net Profit Margin</span><span><?= number_format($revCurrTotal != 0 ? ($netCurr / $revCurrTotal) * 100 : 0, 2) ?> %</span></div>
            <div class="pl2-kv" style="margin-bottom:0;"><span>Gross Profit Margin</span><span><?= number_format($revCurrTotal != 0 ? ($grossCurr / $revCurrTotal) * 100 : 0, 2) ?> %</span></div>
          </div>
        </section>

        <section class="pl2-side-card">
          <div class="pl2-side-h">Notes</div>
          <div class="pl2-side-b">
            <div class="pl2-note"><b>4.1</b><span>Revenue from sales of goods and services</span></div>
            <div class="pl2-note"><b>5.1</b><span>Direct costs related to goods sold</span></div>
            <div class="pl2-note"><b>6.x</b><span>Operating expenses for running the business</span></div>
            <div class="pl2-note"><b>7.x</b><span>Finance income and costs</span></div>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
