<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

$asOf = gl_date_param('as_of', date('Y-m-d'));
$bs = gl_balance_sheet($pdo, $asOf);
$balanced = abs($bs['difference']) < 0.005;

if (($_GET['export'] ?? '') === 'csv') {
    $csv = [];
    foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $k => $label) {
        foreach ($bs[$k] as $r) {
            $csv[] = [$label, $r['code'], $r['name'], $r['amount']];
        }
    }
    $csv[] = ['Equity', '', 'Current earnings (not yet closed)', $bs['current_earnings']];
    $csv[] = ['Total assets', '', '', $bs['total_assets']];
    $csv[] = ['Total liabilities and equity', '', '', $bs['total_liabilities'] + $bs['total_equity']];
    gl_send_csv("balance-sheet-{$asOf}.csv", ['Section', 'Code', 'Account', 'Amount'], $csv);
}

$page_title = 'Balance Sheet';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
$rowsOut = static function (array $rows) use ($asOf): void {
    foreach ($rows as $r) { ?>
      <tr><td><?= gl_h($r['code'] !== '' ? $r['code'] : '-') ?></td>
          <td><a href="ledger.php?account_id=<?= (int) $r['id'] ?>&to_date=<?= gl_h($asOf) ?>"><?= gl_h($r['name']) ?></a></td>
          <td class="n"><?= gl_fmt($r['amount']) ?></td></tr>
    <?php }
};
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'bs'; include __DIR__ . '/../../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Balance Sheet</h1>
        <p>As at <?= gl_h(date('d M Y', strtotime($asOf))) ?>.
          <span class="glr-pill <?= $balanced ? 'ok' : 'bad' ?>"><?= $balanced ? 'Assets = Liabilities + Equity' : 'Out by ' . gl_fmt($bs['difference']) ?></span></p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="glr-btn secondary" href="<?= gl_h(gl_export_url()) ?>">Export CSV</a>
        <button class="glr-btn secondary" type="button" onclick="window.print()">Print</button>
      </div>
    </div>
    <form method="get" class="glr-sec glr-form">
      <label>As at <input type="date" name="as_of" value="<?= gl_h($asOf) ?>"></label>
      <button class="glr-btn" type="submit">View</button>
    </form>
    <div class="glr-cards">
      <div class="glr-card"><div class="k">Total assets</div><div class="v"><?= gl_fmt($bs['total_assets']) ?></div></div>
      <div class="glr-card"><div class="k">Total liabilities</div><div class="v"><?= gl_fmt($bs['total_liabilities']) ?></div></div>
      <div class="glr-card"><div class="k">Total equity</div><div class="v"><?= gl_fmt($bs['total_equity']) ?></div></div>
    </div>
    <section class="glr-sec">
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Code</th><th>Account</th><th class="n">Amount (TZS)</th></tr></thead>
        <tbody>
          <tr class="sub"><td colspan="3">Assets</td></tr>
          <?php $rowsOut($bs['assets']); ?>
          <tr class="tot"><td colspan="2">Total assets</td><td class="n"><?= gl_fmt($bs['total_assets']) ?></td></tr>
          <tr class="sub"><td colspan="3">Liabilities</td></tr>
          <?php if (!$bs['liabilities']): ?><tr class="muted"><td colspan="3">None</td></tr><?php endif; ?>
          <?php $rowsOut($bs['liabilities']); ?>
          <tr class="tot"><td colspan="2">Total liabilities</td><td class="n"><?= gl_fmt($bs['total_liabilities']) ?></td></tr>
          <tr class="sub"><td colspan="3">Equity</td></tr>
          <?php $rowsOut($bs['equity']); ?>
          <tr><td>-</td><td>Current earnings (profit not yet closed to retained earnings)</td><td class="n"><?= gl_fmt($bs['current_earnings']) ?></td></tr>
          <tr class="tot"><td colspan="2">Total equity</td><td class="n"><?= gl_fmt($bs['total_equity']) ?></td></tr>
          <tr class="tot"><td colspan="2">Total liabilities and equity</td><td class="n"><?= gl_fmt($bs['total_liabilities'] + $bs['total_equity']) ?></td></tr>
        </tbody>
      </table></div>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
