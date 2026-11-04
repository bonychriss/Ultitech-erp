<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gl/gl_reports.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

global $pdo;

$flash = null;
$flashOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Your session expired. Reload the page and try again.';
        $flashOk = false;
    } elseif (in_array($_POST['action'] ?? '', ['sync', 'preview'], true)) {
        $dryRun = $_POST['action'] === 'preview';
        $res = gl_bridge_sync_all($pdo, ['dry_run' => $dryRun]);
        $flashOk = empty($res['errors']);
        $byType = [];
        foreach ($res['by_type'] as $type => $n) {
            $byType[] = $type . ' ' . $n;
        }
        $counts = "{$res['posted']} entr" . ($res['posted'] === 1 ? 'y' : 'ies') . " posted, {$res['reversed']} reversed"
            . ($byType ? ' (' . implode(', ', $byType) . ')' : '');
        if (!$flashOk) {
            $flash = ($dryRun ? 'Preview found errors: ' : 'Sync finished with errors: ') . implode(' | ', array_slice($res['errors'], 0, 3));
        } elseif ($dryRun) {
            $flash = "Preview only, nothing saved: a sync would have {$counts}.";
        } else {
            gl_meta_set($pdo, 'autosync', '1');
            $flash = "Ledger updated: {$counts}. New money movements now post automatically.";
        }
    } elseif (($_POST['action'] ?? '') === 'report_source' && isAdmin()) {
        $src = ($_POST['source'] ?? '') === 'legacy' ? 'legacy' : 'ledger';
        if ($src === 'ledger' && gl_meta_get($pdo, 'autosync') !== '1') {
            $flashOk = false;
            $flash = 'Run "Sync ledger now" first so the ledger contains the full history.';
        } else {
            gl_meta_set($pdo, 'report_source', $src);
            $flash = $src === 'ledger'
                ? 'Financial reports now read from the double-entry ledger.'
                : 'Financial reports switched back to the old journal.';
        }
    } elseif (($_POST['action'] ?? '') === 'lock' && isAdmin()) {
        $date = (string) ($_POST['locked_through'] ?? '');
        $flashOk = gl_lock_period_through($pdo, $date, trim((string) ($_POST['note'] ?? '')));
        $flash = $flashOk
            ? 'Period locked through ' . date('d M Y', strtotime($date)) . '. Later corrections will be dated in the next open period.'
            : 'Lock date must be a valid date after the current lock.';
    }
}

$schemaReady = gl_ensure_schema($pdo);
$moneyAccounts = $schemaReady ? gl_report_money_accounts($pdo) : [];
$tb = $schemaReady ? gl_trial_balance($pdo) : [];
$suspense = $schemaReady ? gl_report_suspense_lines($pdo) : [];
$arCheck = $schemaReady ? gl_report_receivables_check($pdo) : ['ledger' => 0, 'outstanding' => 0, 'unrecorded_receipts' => 0, 'entries' => 0];
$dupCodes = $schemaReady ? gl_report_duplicate_codes($pdo) : [];
$legacy = $schemaReady ? gl_report_legacy_ledger($pdo) : ['entries' => 0, 'unbalanced' => 0];
$pending = $schemaReady ? gl_report_pending_sources($pdo) : 0;
$lockedThrough = $schemaReady ? gl_locked_through($pdo) : null;
$entryCount = $schemaReady ? (int) $pdo->query('SELECT COUNT(*) FROM gl_journal_entries')->fetchColumn() : 0;

$tbDr = array_sum(array_column($tb, 'debit'));
$tbCr = array_sum(array_column($tb, 'credit'));
$tbBalanced = abs($tbDr - $tbCr) < 0.005;
$suspenseTotal = 0.0;
foreach ($suspense as $s) {
    $suspenseTotal += (float) $s['amount'];
}

$gapAccounts = array_filter($moneyAccounts, static fn ($a) => abs($a['stored_gap']) >= 0.005);
$headingAccounts = array_filter($moneyAccounts, static fn ($a) => $a['is_heading'] && $a['tx_count'] > 0);
$mislabelledTotal = array_sum(array_column($moneyAccounts, 'mislabelled_outflows'));

$fmt = static function (float $v): string {
    return $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2);
};
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$page_title = 'Ledger Reconciliation';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<?php include __DIR__ . '/../includes/gl/gl_page_style.php'; ?>

<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'recon'; include __DIR__ . '/../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Ledger Reconciliation</h1>
        <p>The double-entry ledger is built from opening balances, every money movement and every revenue entry.
           Posted entries are never edited or deleted: when a source record changes, its entry is reversed and re-posted.</p>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
        <button class="glr-btn secondary" type="submit" name="action" value="preview">Preview sync</button>
        <button class="glr-btn" type="submit" name="action" value="sync">Sync ledger now</button>
      </form>
    </div>

    <?php if ($flash !== null): ?>
      <div class="glr-flash <?= $flashOk ? 'ok' : 'err' ?>"><?= $h($flash) ?></div>
    <?php endif; ?>
    <?php if (!$schemaReady): ?>
      <div class="glr-flash err">Ledger tables could not be created. Check database permissions.</div>
    <?php endif; ?>

    <div class="glr-cards">
      <div class="glr-card"><div class="k">Trial balance</div>
        <div class="v <?= $tbBalanced ? 'ok-t' : 'bad-t' ?>"><?= $tbBalanced ? 'Balanced' : 'Out by ' . $fmt($tbDr - $tbCr) ?></div>
        <div class="s"><?= number_format($tbDr, 2) ?> TZS each side</div></div>
      <div class="glr-card"><div class="k">Journal entries</div>
        <div class="v"><?= number_format($entryCount) ?></div>
        <div class="s"><?= $pending > 0 ? number_format($pending) . ' transaction(s) waiting to post' : 'All transactions posted' ?></div></div>
      <div class="glr-card"><div class="k">Suspense (to classify)</div>
        <div class="v <?= abs($suspenseTotal) < 0.005 ? 'ok-t' : 'warn-t' ?>"><?= $fmt($suspenseTotal) ?></div>
        <div class="s"><?= count($suspense) ?> item(s)</div></div>
      <div class="glr-card"><div class="k">Stored balance mismatches</div>
        <div class="v <?= $gapAccounts ? 'warn-t' : 'ok-t' ?>"><?= count($gapAccounts) ?></div>
        <div class="s">account(s) where the saved balance differs</div></div>
      <div class="glr-card"><div class="k">Period lock</div>
        <div class="v"><?= $lockedThrough ? $h(date('d M Y', strtotime($lockedThrough))) : 'None' ?></div>
        <div class="s"><?= $lockedThrough ? 'No postings on or before this date' : 'All periods open' ?></div></div>
    </div>

    <section class="glr-sec">
      <h2>Money accounts</h2>
      <div class="note"><b>Saved balance</b> is the number stored on the account. <b>From transactions</b> is opening balance plus all recorded movements.
        <b>Ledger</b> is the double-entry balance (supplier payments that were saved as money in are treated as money out).</div>
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Account</th><th>Type</th><th class="n">Transactions</th><th class="n">Saved balance</th><th class="n">From transactions</th><th class="n">Ledger</th><th class="n">Saved minus ledger</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($moneyAccounts as $a): ?>
          <?php
            $issues = [];
            if (abs($a['stored_gap']) >= 0.005) { $issues[] = ['warn', 'Saved balance out of date']; }
            if ($a['mislabelled_outflows'] > 0) { $issues[] = ['warn', 'Supplier payments saved as money in']; }
            if ($a['is_heading'] && $a['tx_count'] > 0) { $issues[] = ['bad', 'Heading account used for transactions']; }
            if (!$issues) { $issues[] = ['ok', 'Matches']; }
          ?>
          <tr>
            <td><?= $h($a['name']) ?><?= $a['status'] === 'inactive' ? ' <small>(inactive)</small>' : '' ?></td>
            <td><?= $h(ucfirst($a['type'])) ?></td>
            <td class="n"><?= number_format($a['tx_count']) ?></td>
            <td class="n"><?= $fmt($a['stored']) ?></td>
            <td class="n"><?= $fmt($a['computed']) ?></td>
            <td class="n"><b><?= $fmt($a['ledger']) ?></b></td>
            <td class="n <?= abs($a['stored_gap']) >= 0.005 ? 'warn-t' : '' ?>"><?= $fmt($a['stored_gap']) ?></td>
            <td><?php foreach ($issues as [$cls, $txt]): ?><span class="glr-pill <?= $cls ?>"><?= $h($txt) ?></span> <?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <section class="glr-sec">
      <h2>Findings</h2>
      <div class="glr-find">
        <b class="<?= abs($arCheck['unrecorded_receipts']) < 0.005 ? 'ok-t' : 'warn-t' ?>">Customer receipts not recorded in any money account: <?= $fmt($arCheck['unrecorded_receipts']) ?> TZS</b>
        <span>The ledger says customers owe <?= $fmt($arCheck['ledger']) ?>, but the <?= number_format($arCheck['entries']) ?> revenue entries say only <?= $fmt($arCheck['outstanding']) ?> is outstanding.
          The difference is revenue marked as paid where the money was never recorded in a bank, cash or mobile account. Record those receipts against the account that received them.</span>
      </div>
      <div class="glr-find">
        <b class="<?= $mislabelledTotal > 0 ? 'warn-t' : 'ok-t' ?>">Supplier payments saved as money in: <?= $fmt($mislabelledTotal) ?> TZS</b>
        <span>Older supplier and purchase-order payments were stored as inflows while the saved balance was reduced. New payments are now stored correctly; the ledger already treats the old ones as outflows.</span>
      </div>
      <div class="glr-find">
        <b class="<?= $headingAccounts ? 'bad-t' : 'ok-t' ?>">Heading accounts used for transactions: <?= count($headingAccounts) ?></b>
        <span><?= $headingAccounts ? $h(implode(', ', array_column($headingAccounts, 'name'))) . '. Headings group sub-accounts and should not hold transactions; move these to a sub-account.' : 'None.' ?></span>
      </div>
      <div class="glr-find">
        <b class="<?= $dupCodes ? 'bad-t' : 'ok-t' ?>">Account codes used more than once: <?= count($dupCodes) ?></b>
        <span><?php if ($dupCodes): foreach ($dupCodes as $code => $names): ?><?= $h($code) ?>: <?= $h(implode(' / ', $names)) ?>. <?php endforeach; else: ?>None.<?php endif; ?></span>
      </div>
      <div class="glr-find">
        <b class="<?= $legacy['unbalanced'] ? 'bad-t' : 'ok-t' ?>">Old journal (current reports): <?= number_format($legacy['entries']) ?> entries, <?= number_format($legacy['unbalanced']) ?> unbalanced</b>
        <span>The old journal only covers part of the activity (mainly invoices), so the current financial reports are incomplete. It is kept unchanged for reference.</span>
      </div>
    </section>

    <section class="glr-sec">
      <h2>Suspense items awaiting classification</h2>
      <div class="note">Payments whose expense category could not be matched to a ledger account. They stay in suspense until classified, so nothing is guessed.
        <a href="gl-category-map.php">Classify voucher categories</a>.</div>
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Entry</th><th>Date</th><th>Source</th><th>Details</th><th class="n">Amount</th></tr></thead>
        <tbody>
        <?php if (!$suspense): ?><tr><td colspan="5">No items in suspense.</td></tr><?php endif; ?>
        <?php foreach ($suspense as $s): ?>
          <tr><td><?= $h($s['entry_number']) ?></td><td><?= $h(date('d M Y', strtotime($s['entry_date']))) ?></td><td><?= $h($s['source_ref']) ?></td>
              <td><?= $h($s['memo']) ?></td><td class="n"><?= $fmt((float) $s['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <section class="glr-sec">
      <h2>Trial balance (double-entry ledger)</h2>
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="n">Debit</th><th class="n">Credit</th><th class="n">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($tb as $r): ?>
          <tr><td><?= $h($r['code'] !== '' ? $r['code'] : '-') ?></td><td><?= $h($r['name']) ?></td><td><?= $h(ucfirst($r['type'])) ?></td>
              <td class="n"><?= number_format($r['debit'], 2) ?></td><td class="n"><?= number_format($r['credit'], 2) ?></td><td class="n"><?= $fmt($r['debit'] - $r['credit']) ?></td></tr>
        <?php endforeach; ?>
          <tr class="tot"><td colspan="3">Total</td><td class="n"><?= number_format($tbDr, 2) ?></td><td class="n"><?= number_format($tbCr, 2) ?></td><td class="n"><?= $fmt($tbDr - $tbCr) ?></td></tr>
        </tbody>
      </table></div>
    </section>

    <?php if (isAdmin()): ?>
    <?php $reportSource = gl_report_source($pdo); ?>
    <section class="glr-sec">
      <h2>Report source</h2>
      <div class="note">Trial Balance, Profit &amp; Loss, Balance Sheet, Cash Flow and General Ledger currently read from
        <b><?= $reportSource === 'ledger' ? 'the double-entry ledger' : 'the old journal' ?></b>. Switching does not change any data.</div>
      <form method="post" class="glr-lock">
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
        <input type="hidden" name="action" value="report_source">
        <input type="hidden" name="source" value="<?= $reportSource === 'ledger' ? 'legacy' : 'ledger' ?>">
        <button class="glr-btn secondary" type="submit"><?= $reportSource === 'ledger' ? 'Switch reports back to the old journal' : 'Use the double-entry ledger for reports' ?></button>
      </form>
    </section>
    <section class="glr-sec">
      <h2>Close a period</h2>
      <div class="note">Locking stops any posting dated on or before the chosen date. Corrections that arrive later are dated on the next open day, keeping closed figures unchanged.</div>
      <form method="post" class="glr-lock" onsubmit="return confirm('Lock the ledger through this date? This cannot be undone.');">
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
        <input type="hidden" name="action" value="lock">
        <label>Lock through <input type="date" name="locked_through" required></label>
        <input type="text" name="note" placeholder="Note (optional)" maxlength="255">
        <button class="glr-btn secondary" type="submit">Lock period</button>
      </form>
    </section>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
