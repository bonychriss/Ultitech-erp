<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

[$from, $to] = gl_period_params();
$rows = gl_account_movements($pdo, $from, $to);
$typeOrder = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expenses'];
$byType = [];
foreach ($rows as $r) {
    $byType[isset($typeOrder[$r['type']]) ? $r['type'] : 'other'][] = $r;
}
$tot = ['opening' => 0.0, 'debit' => 0.0, 'credit' => 0.0, 'closing_dr' => 0.0, 'closing_cr' => 0.0];
foreach ($rows as $r) {
    $tot['opening'] += $r['opening'];
    $tot['debit'] += $r['debit'];
    $tot['credit'] += $r['credit'];
    if ($r['closing'] >= 0) {
        $tot['closing_dr'] += $r['closing'];
    } else {
        $tot['closing_cr'] += -$r['closing'];
    }
}
$balanced = abs($tot['closing_dr'] - $tot['closing_cr']) < 0.005 && abs($tot['debit'] - $tot['credit']) < 0.005;

if (($_GET['export'] ?? '') === 'csv') {
    $csv = [];
    foreach ($rows as $r) {
        $csv[] = [$r['code'], $r['name'], ucfirst($r['type']), $r['opening'], $r['debit'], $r['credit'], $r['closing'] >= 0 ? $r['closing'] : 0, $r['closing'] < 0 ? -$r['closing'] : 0];
    }
    gl_send_csv("trial-balance-{$to}.csv", ['Code', 'Account', 'Type', 'Opening', 'Debit', 'Credit', 'Closing debit', 'Closing credit'], $csv);
}

$page_title = 'Trial Balance';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'trial'; include __DIR__ . '/../../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Trial Balance</h1>
        <p>As at <?= gl_h(date('d M Y', strtotime($to))) ?>, with movements from <?= gl_h(date('d M Y', strtotime($from))) ?>.
          <span class="glr-pill <?= $balanced ? 'ok' : 'bad' ?>"><?= $balanced ? 'Balanced' : 'Not balanced' ?></span></p>
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
    <section class="glr-sec">
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Code</th><th>Account</th><th class="n">Opening</th><th class="n">Debit</th><th class="n">Credit</th><th class="n">Closing debit</th><th class="n">Closing credit</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7">No posted entries up to this date.</td></tr><?php endif; ?>
        <?php foreach (array_merge($typeOrder, ['other' => 'Other']) as $type => $label): ?>
          <?php if (empty($byType[$type])) { continue; } ?>
          <tr class="sub"><td colspan="7"><?= gl_h($label) ?></td></tr>
          <?php foreach ($byType[$type] as $r): ?>
            <tr>
              <td><?= gl_h($r['code'] !== '' ? $r['code'] : '-') ?></td>
              <td><a href="ledger.php?account_id=<?= (int) $r['id'] ?>&from_date=<?= gl_h($from) ?>&to_date=<?= gl_h($to) ?>"><?= gl_h($r['name']) ?></a></td>
              <td class="n"><?= gl_fmt($r['opening']) ?></td>
              <td class="n"><?= number_format($r['debit'], 2) ?></td>
              <td class="n"><?= number_format($r['credit'], 2) ?></td>
              <td class="n"><?= $r['closing'] >= 0.005 ? number_format($r['closing'], 2) : '' ?></td>
              <td class="n"><?= $r['closing'] <= -0.005 ? number_format(-$r['closing'], 2) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <tr class="tot"><td colspan="2">Total</td><td class="n"><?= gl_fmt($tot['opening']) ?></td><td class="n"><?= number_format($tot['debit'], 2) ?></td><td class="n"><?= number_format($tot['credit'], 2) ?></td>
          <td class="n"><?= number_format($tot['closing_dr'], 2) ?></td><td class="n"><?= number_format($tot['closing_cr'], 2) ?></td></tr>
        </tbody>
      </table></div>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
