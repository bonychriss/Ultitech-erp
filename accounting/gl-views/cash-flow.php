<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

[$from, $to] = gl_period_params();
$cf = gl_cash_flow($pdo, $from, $to);
$ties = abs($cf['opening'] + $cf['net'] - $cf['closing']) < 0.005;
$sectionNames = ['operating' => 'Operating activities', 'financing' => 'Financing and opening balances', 'internal' => 'Internal movements'];

if (($_GET['export'] ?? '') === 'csv') {
    $csv = [['Cash at start', '', '', $cf['opening']]];
    foreach ($cf['sections'] as $key => $items) {
        foreach ($items as $i) {
            $csv[] = [$sectionNames[$key] ?? $key, $i['label'], $i['in'], $i['out']];
        }
    }
    $csv[] = ['Net change', '', '', $cf['net']];
    $csv[] = ['Cash at end', '', '', $cf['closing']];
    gl_send_csv("cash-flow-{$from}-to-{$to}.csv", ['Section', 'Line', 'Money in', 'Money out'], $csv);
}

$page_title = 'Cash Flow';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'cf'; include __DIR__ . '/../../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Cash Flow</h1>
        <p><?= gl_h(date('d M Y', strtotime($from))) ?> to <?= gl_h(date('d M Y', strtotime($to))) ?>. Direct method: every movement on bank, cash and mobile money accounts.
          <span class="glr-pill <?= $ties ? 'ok' : 'bad' ?>"><?= $ties ? 'Reconciles to closing cash' : 'Does not reconcile' ?></span></p>
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
        <thead><tr><th>Line</th><th class="n">Money in</th><th class="n">Money out</th><th class="n">Net</th></tr></thead>
        <tbody>
          <tr class="tot"><td>Cash at <?= gl_h(date('d M Y', strtotime($from))) ?></td><td></td><td></td><td class="n"><?= gl_fmt($cf['opening']) ?></td></tr>
          <?php foreach ($sectionNames as $key => $label): ?>
            <?php if (empty($cf['sections'][$key])) { continue; } ?>
            <tr class="sub"><td colspan="4"><?= gl_h($label) ?></td></tr>
            <?php foreach ($cf['sections'][$key] as $i): ?>
              <tr><td><?= gl_h($i['label']) ?></td><td class="n"><?= number_format($i['in'], 2) ?></td><td class="n"><?= gl_fmt($i['out']) ?></td><td class="n"><?= gl_fmt($i['net']) ?></td></tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <tr class="tot"><td>Net change in cash</td><td></td><td></td><td class="n"><?= gl_fmt($cf['net']) ?></td></tr>
          <tr class="tot"><td>Cash at <?= gl_h(date('d M Y', strtotime($to))) ?></td><td></td><td></td><td class="n"><?= gl_fmt($cf['closing']) ?></td></tr>
        </tbody>
      </table></div>
    </section>
    <section class="glr-sec">
      <h2>By account</h2>
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Account</th><th class="n">Start</th><th class="n">End</th><th class="n">Change</th></tr></thead>
        <tbody>
        <?php foreach ($cf['accounts'] as $a): ?>
          <tr><td><?= gl_h($a['name']) ?></td><td class="n"><?= gl_fmt($a['opening']) ?></td><td class="n"><?= gl_fmt($a['closing']) ?></td><td class="n"><?= gl_fmt($a['closing'] - $a['opening']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
