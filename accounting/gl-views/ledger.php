<?php
if (!defined('GL_VIEW')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../../includes/gl/gl_view_helpers.php';

[$from, $to] = gl_period_params();
$accountId = (int) ($_GET['account_id'] ?? 0);
$faId = (int) ($_GET['fa_id'] ?? 0);

$accounts = $pdo->query("SELECT a.id, a.code, a.name, LOWER(a.type) AS type, COUNT(l.id) AS n
                         FROM erp_accounts a LEFT JOIN gl_journal_lines l ON l.account_id = a.id
                         GROUP BY a.id, a.code, a.name, a.type
                         HAVING n > 0 OR COALESCE(MAX(a.status), 'active') <> 'inactive'
                         ORDER BY a.code + 0, a.code, a.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$subAccounts = [];
if ($accountId > 0) {
    $st = $pdo->prepare('SELECT DISTINCT l.financial_account_id AS id, f.name FROM gl_journal_lines l JOIN financial_accounts f ON f.id = l.financial_account_id WHERE l.account_id = ? ORDER BY f.name');
    $st->execute([$accountId]);
    $subAccounts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$current = null;
foreach ($accounts as $a) {
    if ((int) $a['id'] === $accountId) {
        $current = $a;
    }
}
$stmt = $current ? gl_account_statement($pdo, $accountId, $from, $to, $faId ?: null) : null;

if ($stmt && ($_GET['export'] ?? '') === 'csv') {
    $csv = [['', '', 'Opening balance', '', '', '', $stmt['opening']]];
    foreach ($stmt['lines'] as $l) {
        $csv[] = [$l['entry_date'], $l['entry_number'], $l['description'], $l['memo'], (float) $l['debit'], (float) $l['credit'], $l['running']];
    }
    gl_send_csv('ledger-' . ($current['code'] ?: $accountId) . "-{$from}-to-{$to}.csv", ['Date', 'Entry', 'Description', 'Details', 'Debit', 'Credit', 'Balance'], $csv);
}

$page_title = 'General Ledger';
include __DIR__ . '/../../modules/balances/includes/header.php';
include __DIR__ . '/../../includes/gl/gl_page_style.php';
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'ledger'; include __DIR__ . '/../../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>General Ledger</h1>
        <p>Every posting to an account, with a running balance (debit positive, credit in brackets).</p>
      </div>
      <?php if ($stmt): ?>
      <div style="display:flex;gap:8px">
        <a class="glr-btn secondary" href="<?= gl_h(gl_export_url()) ?>">Export CSV</a>
        <button class="glr-btn secondary" type="button" onclick="window.print()">Print</button>
      </div>
      <?php endif; ?>
    </div>
    <form method="get" class="glr-sec glr-form">
      <select name="account_id" onchange="this.form.fa_id && (this.form.fa_id.value='');this.form.submit()" style="min-width:320px">
        <option value="0">Choose an account</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $accountId ? 'selected' : '' ?>><?= gl_h(gl_account_label($a)) ?><?= (int) $a['n'] === 0 ? ' (no postings)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (count($subAccounts) > 1): ?>
        <select name="fa_id">
          <option value="0">All money accounts</option>
          <?php foreach ($subAccounts as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $faId ? 'selected' : '' ?>><?= gl_h($s['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <label>From <input type="date" name="from_date" value="<?= gl_h($from) ?>"></label>
      <label>To <input type="date" name="to_date" value="<?= gl_h($to) ?>"></label>
      <button class="glr-btn" type="submit">View</button>
    </form>

    <?php if ($stmt): ?>
    <div class="glr-cards">
      <div class="glr-card"><div class="k">Opening balance</div><div class="v"><?= gl_fmt($stmt['opening']) ?></div></div>
      <div class="glr-card"><div class="k">Postings</div><div class="v"><?= count($stmt['lines']) ?></div></div>
      <div class="glr-card"><div class="k">Closing balance</div><div class="v"><?= gl_fmt($stmt['closing']) ?></div></div>
    </div>
    <section class="glr-sec">
      <h2><?= gl_h(gl_account_label($current)) ?></h2>
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="n">Debit</th><th class="n">Credit</th><th class="n">Balance</th></tr></thead>
        <tbody>
          <tr class="muted"><td><?= gl_h(date('d M Y', strtotime($from))) ?></td><td></td><td>Opening balance</td><td></td><td></td><td class="n"><?= gl_fmt($stmt['opening']) ?></td></tr>
          <?php foreach ($stmt['lines'] as $l): ?>
            <tr>
              <td><?= gl_h(date('d M Y', strtotime($l['entry_date']))) ?></td>
              <td><a href="gl-journal.php?id=<?= (int) $l['entry_id'] ?>"><?= gl_h($l['entry_number']) ?></a><?= $l['entry_kind'] === 'reversal' ? ' <span class="glr-pill warn">Reversal</span>' : '' ?></td>
              <td class="wrap"><?= gl_h($l['description']) ?><?= ($l['memo'] && $l['memo'] !== $l['description']) ? '<br><small style="color:#64748b">' . gl_h($l['memo']) . '</small>' : '' ?></td>
              <td class="n"><?= (float) $l['debit'] > 0 ? number_format((float) $l['debit'], 2) : '' ?></td>
              <td class="n"><?= (float) $l['credit'] > 0 ? number_format((float) $l['credit'], 2) : '' ?></td>
              <td class="n"><?= gl_fmt($l['running']) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="tot"><td colspan="5">Closing balance</td><td class="n"><?= gl_fmt($stmt['closing']) ?></td></tr>
        </tbody>
      </table></div>
    </section>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/../../modules/balances/includes/footer.php'; ?>
