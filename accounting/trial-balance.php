<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

$jeModule = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'balances';
$fromDate = (string) ($_GET['from_date'] ?? date('Y-m-01'));
$toDate = (string) ($_GET['to_date'] ?? date('Y-m-t'));
$fiscalYear = (string) ($_GET['fiscal_year'] ?? date('Y'));

$fromTs = strtotime($fromDate) ?: strtotime(date('Y-m-01'));
$toTs = strtotime($toDate) ?: strtotime(date('Y-m-t'));
if ($toTs < $fromTs) {
    [$fromTs, $toTs] = [$toTs, $fromTs];
    $fromDate = date('Y-m-d', $fromTs);
    $toDate = date('Y-m-d', $toTs);
}

$sql = "SELECT
            a.code,
            a.name,
            a.type,
            COALESCE(SUM(CASE WHEN je.date BETWEEN ? AND ? THEN ji.debit ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN je.date BETWEEN ? AND ? THEN ji.credit ELSE 0 END), 0) AS total_credit
        FROM erp_accounts a
        LEFT JOIN erp_journal_items ji ON a.id = ji.account_id
        LEFT JOIN erp_journal_entries je ON ji.journal_id = je.id
        GROUP BY a.id, a.code, a.name, a.type
        ORDER BY CAST(a.code AS UNSIGNED) ASC, a.code ASC, a.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$fromDate, $toDate, $fromDate, $toDate]);
$rawAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groups = [
    'asset' => ['idx' => '1', 'label' => 'ASSETS'],
    'liability' => ['idx' => '2', 'label' => 'LIABILITIES'],
    'equity' => ['idx' => '3', 'label' => 'EQUITY'],
    'revenue' => ['idx' => '4', 'label' => 'REVENUE'],
    'expense' => ['idx' => '5', 'label' => 'EXPENSES'],
];
$byType = [
    'asset' => [],
    'liability' => [],
    'equity' => [],
    'revenue' => [],
    'expense' => [],
];

$totalDebit = 0.0;
$totalCredit = 0.0;
$totalAccounts = 0;

foreach ($rawAccounts as $acc) {
    $type = strtolower(trim((string) ($acc['type'] ?? '')));
    if (!isset($byType[$type])) {
        continue;
    }
    $debit = (float) ($acc['total_debit'] ?? 0);
    $credit = (float) ($acc['total_credit'] ?? 0);
    if (abs($debit) < 0.00001 && abs($credit) < 0.00001) {
        continue;
    }
    $totalDebit += $debit;
    $totalCredit += $credit;
    $totalAccounts++;
    $byType[$type][] = [
        'code' => (string) ($acc['code'] ?? ''),
        'name' => (string) ($acc['name'] ?? ''),
        'type' => ucfirst($type),
        'debit' => $debit,
        'credit' => $credit,
        'net' => $debit - $credit,
    ];
}

// Temporary demo data to visualize layout when the selected period has no transactions.
if ($totalAccounts === 0) {
    $byType = [
        'asset' => [
            ['code' => '1100', 'name' => 'Current Assets', 'type' => 'Asset', 'debit' => 2100750000, 'credit' => 895250000, 'net' => 1205500000],
            ['code' => '1110', 'name' => 'Cash and Bank', 'type' => 'Asset', 'debit' => 1250750000, 'credit' => 450250000, 'net' => 800500000],
            ['code' => '1111', 'name' => 'Cash on Hand', 'type' => 'Asset', 'debit' => 85750000, 'credit' => 35250000, 'net' => 50500000],
            ['code' => '1112', 'name' => 'Bank - CRDB', 'type' => 'Asset', 'debit' => 1165000000, 'credit' => 415000000, 'net' => 750000000],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'Asset', 'debit' => 850000000, 'credit' => 445000000, 'net' => 405000000],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'Asset', 'debit' => 60000000, 'credit' => 0, 'net' => 60000000],
            ['code' => '1400', 'name' => 'Prepayments', 'type' => 'Asset', 'debit' => 100000000, 'credit' => 0, 'net' => 100000000],
        ],
        'liability' => [
            ['code' => '2100', 'name' => 'Current Liabilities', 'type' => 'Liability', 'debit' => 220000000, 'credit' => 1150000000, 'net' => -930000000],
            ['code' => '2110', 'name' => 'Accounts Payable', 'type' => 'Liability', 'debit' => 0, 'credit' => 850000000, 'net' => -850000000],
            ['code' => '2120', 'name' => 'Accrued Expenses', 'type' => 'Liability', 'debit' => 0, 'credit' => 80000000, 'net' => -80000000],
            ['code' => '2130', 'name' => 'Taxes Payable', 'type' => 'Liability', 'debit' => 220000000, 'credit' => 220000000, 'net' => 0],
        ],
        'equity' => [
            ['code' => '3100', 'name' => "Owner's Equity", 'type' => 'Equity', 'debit' => 0, 'credit' => 1000000000, 'net' => -1000000000],
            ['code' => '3110', 'name' => 'Capital', 'type' => 'Equity', 'debit' => 0, 'credit' => 1000000000, 'net' => -1000000000],
        ],
        'revenue' => [
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'Revenue', 'debit' => 0, 'credit' => 2250000000, 'net' => -2250000000],
        ],
        'expense' => [
            ['code' => '5100', 'name' => 'Operating Expenses', 'type' => 'Expense', 'debit' => 2500000000, 'credit' => 0, 'net' => 2500000000],
        ],
    ];
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $totalAccounts = 0;
    foreach ($byType as $rows) {
        foreach ($rows as $r) {
            $totalDebit += (float) $r['debit'];
            $totalCredit += (float) $r['credit'];
            $totalAccounts++;
        }
    }
}

$page_title = 'Trial Balance';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
.employee-header{display:none!important}
.main-content.tb-shell{margin-top:0!important;padding:14px 0 24px!important;background:#f9fafb;font-family:"Inter","Segoe UI",Roboto,Arial,sans-serif;color:#0f172a}
.tb-wrap{padding:0 16px}
.tb-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.tb-title{margin:0;font-size:34px;font-weight:800;color:#0b1f5d;line-height:1.1}
.tb-sub{margin:6px 0 0;font-size:14px;color:#64748b}
.tb-bc{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12px;color:#64748b}
.tb-bc a{color:#2563eb;text-decoration:none;font-weight:700}
.tb-actions{display:flex;gap:10px;flex-wrap:wrap}
.tb-btn{height:34px;border:1px solid #dbe2ea;border-radius:8px;background:#fff;padding:0 12px;font-size:12px;font-weight:700;color:#0f172a;display:inline-flex;align-items:center;gap:7px;text-decoration:none;cursor:pointer}
.tb-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:12px;align-items:start}
.tb-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);overflow:hidden}
.tb-head{padding:12px 14px;border-bottom:1px solid #eef2f7;display:grid;grid-template-columns:minmax(0,1fr) repeat(4,minmax(120px,1fr));gap:10px;align-items:center}
.tb-main{display:flex;gap:10px;align-items:center}
.tb-ico{width:38px;height:38px;border-radius:50%;background:#dcfce7;color:#16a34a;display:inline-flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.tb-main h2{margin:0;font-size:30px;font-weight:800;color:#0f172a;line-height:1.05}
.tb-main p{margin:2px 0 0;font-size:22px;color:#334155;font-weight:600}
.tb-meta .k{font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase}
.tb-meta .v{font-size:13px;color:#0f172a;font-weight:700;margin-top:3px}
.tb-table-wrap{overflow:auto}
.tb-table{width:100%;min-width:980px;border-collapse:collapse;font-size:12px}
.tb-table th,.tb-table td{padding:8px 10px;border-bottom:1px solid #eef2f7;vertical-align:middle;white-space:nowrap}
.tb-table th{font-size:10px;text-transform:uppercase;color:#64748b;font-weight:800;background:#fafafa;text-align:left}
.tb-table th.num-h,.tb-table td.num{text-align:right;font-variant-numeric:tabular-nums}
.tb-sec td{font-weight:900;color:#2563eb;background:#fcfdff}
.tb-total td{font-weight:900;color:#2563eb;background:#f8fafc}
.tb-net-red{color:#ef4444;font-weight:800}
.tb-net-blue{color:#2563eb;font-weight:800}
.tb-foot-note{padding:10px 14px;font-size:11px;color:#64748b}
.tb-side{background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.05);margin-bottom:12px;overflow:hidden}
.tb-side-h{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:14px;font-weight:800;color:#0f172a}
.tb-side-b{padding:12px 14px}
.tb-fg{margin-bottom:10px}.tb-fg:last-child{margin-bottom:0}
.tb-fg label{display:block;font-size:11px;color:#64748b;font-weight:700;margin-bottom:5px}
.tb-ctl{width:100%;height:36px;border:1px solid #dbe2ea;border-radius:8px;padding:0 10px;font-size:13px;background:#fff;color:#0f172a}
.tb-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.tb-act{width:100%;height:36px;border-radius:8px;border:1px solid #2563eb;background:#2563eb;color:#fff;font-size:13px;font-weight:800}
.tb-act.secondary{margin-top:8px;background:#fff;color:#0f172a;border-color:#dbe2ea}
.tb-kv{display:flex;justify-content:space-between;gap:8px;margin-bottom:9px;font-size:12px;color:#64748b}
.tb-kv span:last-child{font-weight:800;color:#0f172a;text-align:right}
@media(max-width:1200px){.tb-grid{grid-template-columns:1fr}.tb-head{grid-template-columns:1fr 1fr}}
@media(max-width:760px){.tb-head{grid-template-columns:1fr}.tb-row2{grid-template-columns:1fr}}
</style>

<main class="main-content tb-shell">
  <div class="tb-wrap">
    <div class="tb-top">
      <div>
        <h1 class="tb-title">Trial Balance</h1>
        <p class="tb-sub">View trial balance for the selected period</p>
        <nav class="tb-bc">
          <a href="../index.php">Home</a><i class="fas fa-chevron-right"></i>
          <a href="#">Finance &amp; Accounting</a><i class="fas fa-chevron-right"></i>
          <a href="#">Financial Reports</a><i class="fas fa-chevron-right"></i>
          <span>Trial Balance</span>
        </nav>
      </div>
      <div class="tb-actions">
        <button class="tb-btn"><i class="fas fa-download"></i> Export</button>
        <button class="tb-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <button class="tb-btn"><i class="far fa-calendar-check"></i> Schedule Report</button>
      </div>
    </div>

    <div class="tb-grid">
      <div class="tb-card">
        <div class="tb-head">
          <div class="tb-main"><span class="tb-ico"><i class="far fa-file-lines"></i></span><div><h2>Trial Balance</h2><p>As at <?= htmlspecialchars(date('d M Y', $toTs)) ?></p></div></div>
          <div class="tb-meta"><div class="k">Report Date</div><div class="v"><?= htmlspecialchars(date('d/m/Y h:i A')) ?></div></div>
          <div class="tb-meta"><div class="k">Fiscal Year</div><div class="v"><?= htmlspecialchars($fiscalYear) ?></div></div>
          <div class="tb-meta"><div class="k">From Date</div><div class="v"><?= htmlspecialchars(date('d/m/Y', $fromTs)) ?></div></div>
          <div class="tb-meta"><div class="k">To Date</div><div class="v"><?= htmlspecialchars(date('d/m/Y', $toTs)) ?></div></div>
        </div>

        <div class="tb-table-wrap">
          <table class="tb-table">
            <thead>
              <tr>
                <th>Account Code</th>
                <th>Account Name</th>
                <th>Account Type</th>
                <th class="num-h">Debit (TZS)</th>
                <th class="num-h">Credit (TZS)</th>
                <th class="num-h">Net Balance (TZS)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($groups as $typeKey => $meta): ?>
                <?php if (empty($byType[$typeKey])) continue; ?>
                <?php
                  $secDebit = 0.0;
                  $secCredit = 0.0;
                  foreach ($byType[$typeKey] as $a) { $secDebit += $a['debit']; $secCredit += $a['credit']; }
                  $secNet = $secDebit - $secCredit;
                ?>
                <tr class="tb-sec">
                  <td><?= htmlspecialchars($meta['idx']) ?> <?= htmlspecialchars($meta['label']) ?></td>
                  <td></td><td></td>
                  <td class="num"><?= number_format($secDebit, 2) ?></td>
                  <td class="num"><?= number_format($secCredit, 2) ?></td>
                  <td class="num <?= $secNet < 0 ? 'tb-net-red' : 'tb-net-blue' ?>"><?= $secNet < 0 ? '(' . number_format(abs($secNet),2) . ')' : number_format($secNet,2) ?></td>
                </tr>
                <?php foreach ($byType[$typeKey] as $a): ?>
                  <tr>
                    <td><?= htmlspecialchars($a['code'] !== '' ? $a['code'] : '-') ?></td>
                    <td><?= htmlspecialchars($a['name']) ?></td>
                    <td><?= htmlspecialchars($a['type']) ?></td>
                    <td class="num"><?= number_format($a['debit'], 2) ?></td>
                    <td class="num"><?= number_format($a['credit'], 2) ?></td>
                    <td class="num <?= $a['net'] < 0 ? 'tb-net-red' : 'tb-net-blue' ?>"><?= $a['net'] < 0 ? '(' . number_format(abs($a['net']),2) . ')' : number_format($a['net'],2) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
              <?php $diff = $totalDebit - $totalCredit; ?>
              <tr class="tb-total">
                <td>TOTAL</td><td></td><td></td>
                <td class="num"><?= number_format($totalDebit, 2) ?></td>
                <td class="num"><?= number_format($totalCredit, 2) ?></td>
                <td class="num <?= $diff < 0 ? 'tb-net-red' : 'tb-net-blue' ?>"><?= $diff < 0 ? '(' . number_format(abs($diff),2) . ')' : number_format($diff,2) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="tb-foot-note">Note: Trial Balance includes all posted entries up to <?= htmlspecialchars(date('d/m/Y', $toTs)) ?>.</div>
      </div>

      <aside>
        <section class="tb-side">
          <div class="tb-side-h"><i class="fas fa-filter"></i> Report Filters</div>
          <div class="tb-side-b">
            <form method="get">
              <input type="hidden" name="module" value="<?= $jeModule ?>">
              <div class="tb-fg"><label>Date Range</label><select class="tb-ctl"><option>Custom Range</option></select></div>
              <div class="tb-row2">
                <div class="tb-fg"><label>From Date <span style="color:#ef4444">*</span></label><input class="tb-ctl" type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>"></div>
                <div class="tb-fg"><label>To Date <span style="color:#ef4444">*</span></label><input class="tb-ctl" type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>"></div>
              </div>
              <div class="tb-fg"><label>Fiscal Year</label><input class="tb-ctl" name="fiscal_year" value="<?= htmlspecialchars($fiscalYear) ?>"></div>
              <div class="tb-fg"><label>Accounting Basis</label><select class="tb-ctl"><option>Accrual</option></select></div>
              <div class="tb-fg"><label>Show Accounts</label><select class="tb-ctl"><option>All Accounts</option></select></div>
              <div class="tb-fg"><label>Compare With (Optional)</label><select class="tb-ctl"><option>-- Select --</option></select></div>
              <button class="tb-act" type="submit"><i class="far fa-eye"></i> View Report</button>
              <button class="tb-act secondary" type="button" onclick="window.location.href='?module=<?= $jeModule ?>'"><i class="fas fa-rotate-left"></i> Reset Filters</button>
            </form>
          </div>
        </section>

        <section class="tb-side">
          <div class="tb-side-h">Report Summary</div>
          <div class="tb-side-b">
            <div class="tb-kv"><span>Total Accounts</span><span><?= number_format($totalAccounts) ?></span></div>
            <div class="tb-kv"><span>Total Debit (TZS)</span><span><?= number_format($totalDebit, 2) ?></span></div>
            <div class="tb-kv"><span>Total Credit (TZS)</span><span><?= number_format($totalCredit, 2) ?></span></div>
            <div class="tb-kv"><span>Difference (TZS)</span><span style="color:<?= $diff < 0 ? '#ef4444' : '#2563eb' ?>;"><?= $diff < 0 ? '(' . number_format(abs($diff),2) . ')' : number_format($diff,2) ?></span></div>
            <div class="tb-kv"><span>Report Currency</span><span>TZS</span></div>
            <div class="tb-kv"><span>Generated By</span><span><?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'System Admin')) ?></span></div>
            <div class="tb-kv" style="margin-bottom:0;"><span>Generated On</span><span><?= htmlspecialchars(date('d/m/Y h:i A')) ?></span></div>
          </div>
        </section>
      </aside>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
