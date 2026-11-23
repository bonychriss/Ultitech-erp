<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

[$from, $to] = gl_period_params();
$pl = gl_profit_and_loss($pdo, $from, $to);

if (($_GET['export'] ?? '') === 'csv') {
    $csv = [];
    foreach ($pl['revenue'] as $r) {
        $csv[] = ['Revenue', $r['code'], $r['name'], $r['amount']];
    }
    $csv[] = ['Total revenue', '', '', $pl['total_revenue']];
    foreach ($pl['expense'] as $r) {
        $csv[] = ['Expenses', $r['code'], $r['name'], $r['amount']];
    }
    $csv[] = ['Total expenses', '', '', $pl['total_expense']];
    $csv[] = ['Net profit', '', '', $pl['net']];
    gl_send_csv("profit-and-loss-{$from}-to-{$to}.csv", ['Section', 'Code', 'Account', 'Amount'], $csv);
}

$page_title = 'Profit & Loss';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
$section = static function (string $title, array $rows, float $total, string $totalLabel) use ($from, $to): void { ?>
  <tr class="sub"><td colspan="3"><?= gl_h($title) ?></td></tr>
  <?php if (!$rows): ?><tr class="muted"><td colspan="3">None in this period</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= gl_h($r['code'] !== '' ? $r['code'] : '-') ?></td>
        <td><a href="ledger.php?account_id=<?= (int) $r['id'] ?>&from_date=<?= gl_h($from) ?>&to_date=<?= gl_h($to) ?>"><?= gl_h($r['name']) ?></a></td>
        <td class="n"><?= gl_fmt($r['amount']) ?></td></tr>
  <?php endforeach; ?>
  <tr class="tot"><td colspan="2"><?= gl_h($totalLabel) ?></td><td class="n"><?= gl_fmt($total) ?></td></tr>
<?php };
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'pl'; include __DIR__ . '/../../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Profit &amp; Loss</h1>
        <p><?= gl_h(date('d M Y', strtotime($from))) ?> to <?= gl_h(date('d M Y', strtotime($to))) ?> (accrual basis: revenue when invoiced, costs when paid or classified).</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="glr-btn secondary" href="<?= gl_h(gl_export_url()) ?>">Export CSV</a>
        <button class="glr-btn secondary" type="button" onclick="window.print()">Print</button>
      </div>
    </div>
    <form method="get" class="glr-sec glr-form">
      <label>From <input type="date" name="from_date" value="<?= gl_h($from) ?>"></label>
      <label>To <input type="date" name="to_date" value="<?= gl_h($to) ?>"></label>
      <button class="glr-btn" type="submit">View</button>
    </form>
    <div class="glr-cards">
      <div class="glr-card"><div class="k">Revenue</div><div class="v"><?= gl_fmt($pl['total_revenue']) ?></div></div>
      <div class="glr-card"><div class="k">Expenses</div><div class="v"><?= gl_fmt($pl['total_expense']) ?></div></div>
      <div class="glr-card"><div class="k"><?= $pl['net'] >= 0 ? 'Net profit' : 'Net loss' ?></div><div class="v <?= $pl['net'] >= 0 ? 'ok-t' : 'bad-t' ?>"><?= gl_fmt($pl['net']) ?></div></div>
    </div>
    <section class="glr-sec">
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Code</th><th>Account</th><th class="n">Amount (TZS)</th></tr></thead>
        <tbody>
          <?php $section('Revenue', $pl['revenue'], $pl['total_revenue'], 'Total revenue'); ?>
          <?php $section('Expenses', $pl['expense'], $pl['total_expense'], 'Total expenses'); ?>
          <tr class="tot"><td colspan="2"><?= $pl['net'] >= 0 ? 'Net profit' : 'Net loss' ?></td><td class="n"><?= gl_fmt($pl['net']) ?></td></tr>
        </tbody>
      </table></div>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
