<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gl/gl_reports.php';
require_once __DIR__ . '/../includes/gl/gl_view_helpers.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

global $pdo;
gl_ensure_schema($pdo);

$moneyGl = [];
foreach (gl_money_financial_account_ids($pdo) as $faId) {
    $moneyGl[gl_bridge_fa_gl_account($pdo, (int) $faId)] = true;
}
unset($moneyGl[gl_system_account($pdo, 'suspense')]);

$kindLabels = ['system' => 'Automatic', 'manual' => 'Manual', 'opening' => 'Opening balance', 'adjustment' => 'Reclassification', 'reversal' => 'Reversal'];
$flash = null;
$flashOk = true;
$formErrors = [];
$old = ['date' => date('Y-m-d'), 'description' => '', 'reference' => '', 'lines' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Your session expired. Reload the page and try again.';
        $flashOk = false;
    } elseif ($action === 'create') {
        $old['date'] = (string) ($_POST['date'] ?? '');
        $old['description'] = trim((string) ($_POST['description'] ?? ''));
        $old['reference'] = trim((string) ($_POST['reference'] ?? ''));
        $lines = [];
        foreach ((array) ($_POST['lines'] ?? []) as $l) {
            $acc = (int) ($l['account_id'] ?? 0);
            $dr = round((float) str_replace(',', '', (string) ($l['debit'] ?? '0')), 2);
            $cr = round((float) str_replace(',', '', (string) ($l['credit'] ?? '0')), 2);
            $memo = trim((string) ($l['memo'] ?? ''));
            $old['lines'][] = ['account_id' => $acc, 'debit' => $dr ?: '', 'credit' => $cr ?: '', 'memo' => $memo];
            if ($acc <= 0 && $dr == 0 && $cr == 0) {
                continue;
            }
            if ($dr > 0 && $cr > 0) {
                $formErrors[] = 'A line cannot have both a debit and a credit.';
            }
            if ($dr < 0 || $cr < 0) {
                $formErrors[] = 'Amounts cannot be negative.';
            }
            if (isset($moneyGl[$acc])) {
                $formErrors[] = 'Bank, cash and mobile money accounts change only through Balances (payments, receipts, transfers), so their statements stay in step with the ledger.';
            }
            $lines[] = ['account_id' => $acc, 'debit' => $dr, 'credit' => $cr, 'memo' => $memo !== '' ? $memo : $old['description']];
        }
        if ($old['description'] === '') {
            $formErrors[] = 'Enter a description explaining the entry.';
        }
        $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['form_token'] ?? ''));
        if (strlen($token) !== 32) {
            $formErrors[] = 'Form token missing; reload the page.';
        }
        if (!$formErrors) {
            $res = gl_post_entry($pdo, [
                'date' => $old['date'],
                'description' => $old['description'],
                'source_type' => 'manual',
                'source_key' => 'manual:' . $token,
                'source_ref' => $old['reference'] !== '' ? $old['reference'] : null,
                'entry_kind' => 'manual',
            ], $lines);
            if ($res['ok']) {
                header('Location: gl-journal.php?id=' . $res['entry_id'] . '&posted=1');
                exit;
            }
            $formErrors[] = (string) $res['error'];
        }
        $_GET['new'] = '1';
    } elseif ($action === 'reverse') {
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $st = $pdo->prepare('SELECT entry_kind FROM gl_journal_entries WHERE id = ?');
        $st->execute([$id]);
        $kind = (string) ($st->fetchColumn() ?: '');
        if ($kind !== 'manual') {
            $flash = 'Only manual entries can be reversed here. Automatic entries follow their source document.';
            $flashOk = false;
        } elseif ($reason === '') {
            $flash = 'Enter a reason for the reversal.';
            $flashOk = false;
        } else {
            $res = gl_reverse_entry($pdo, $id, $reason, (string) ($_POST['date'] ?? '') ?: null);
            if ($res['ok']) {
                header('Location: gl-journal.php?id=' . $res['entry_id'] . '&reversed=1');
                exit;
            }
            $flash = (string) $res['error'];
            $flashOk = false;
        }
        $_GET['id'] = (string) $id;
    }
}
if (isset($_GET['posted'])) {
    $flash = 'Journal entry posted.';
}
if (isset($_GET['reversed'])) {
    $flash = 'Reversing entry posted. The original entry remains on the books.';
}

$accounts = $pdo->query("SELECT id, code, name, LOWER(type) AS type FROM erp_accounts
                         WHERE is_postable = 1 AND COALESCE(status, 'active') <> 'inactive'
                         ORDER BY FIELD(LOWER(type), 'asset', 'liability', 'equity', 'revenue', 'expense'), code + 0, code")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$accounts = array_values(array_filter($accounts, static fn ($a) => !isset($moneyGl[(int) $a['id']])));
$typeLabels = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expenses'];

$view = isset($_GET['new']) ? 'new' : (isset($_GET['id']) ? 'show' : 'list');
$page_title = 'Journal';

if ($view === 'show') {
    $st = $pdo->prepare('SELECT * FROM gl_journal_entries WHERE id = ?');
    $st->execute([(int) $_GET['id']]);
    $entry = $st->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        $view = 'list';
        $flash = 'Entry not found.';
        $flashOk = false;
    } else {
        $st = $pdo->prepare('SELECT l.*, a.code, a.name AS account_name, f.name AS fa_name FROM gl_journal_lines l
                             JOIN erp_accounts a ON a.id = l.account_id LEFT JOIN financial_accounts f ON f.id = l.financial_account_id
                             WHERE l.entry_id = ? ORDER BY l.line_no');
        $st->execute([(int) $entry['id']]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT id, entry_number FROM gl_journal_entries WHERE reversal_of_id = ? LIMIT 1');
        $st->execute([(int) $entry['id']]);
        $reversedBy = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $reverses = null;
        if (!empty($entry['reversal_of_id'])) {
            $st = $pdo->prepare('SELECT id, entry_number FROM gl_journal_entries WHERE id = ?');
            $st->execute([(int) $entry['reversal_of_id']]);
            $reverses = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $page_title = 'Journal ' . $entry['entry_number'];
    }
}

if ($view === 'list') {
    [$from, $to] = gl_period_params();
    $q = trim((string) ($_GET['q'] ?? ''));
    $kind = (string) ($_GET['kind'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = 50;
    $where = ['e.entry_date BETWEEN ? AND ?'];
    $params = [$from, $to];
    if ($q !== '') {
        $where[] = '(e.entry_number LIKE ? OR e.description LIKE ? OR e.source_ref LIKE ?)';
        array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
    }
    if (isset($kindLabels[$kind])) {
        $where[] = 'e.entry_kind = ?';
        $params[] = $kind;
    }
    $w = implode(' AND ', $where);
    $st = $pdo->prepare("SELECT COUNT(*) FROM gl_journal_entries e WHERE $w");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare("SELECT e.*, (SELECT r.entry_number FROM gl_journal_entries r WHERE r.reversal_of_id = e.id LIMIT 1) AS reversed_by
                         FROM gl_journal_entries e WHERE $w ORDER BY e.entry_date DESC, e.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $entries = $st->fetchAll(PDO::FETCH_ASSOC);
    $pages = max(1, (int) ceil($total / $per));
}

include __DIR__ . '/../modules/balances/includes/header.php';
include __DIR__ . '/../includes/gl/gl_page_style.php';
$accountOptions = static function (int $selected) use ($accounts, $typeLabels): string {
    $html = '<option value="0">Choose account</option>';
    $group = null;
    foreach ($accounts as $a) {
        if ($a['type'] !== $group) {
            $html .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . gl_h($typeLabels[$a['type']] ?? ucfirst($a['type'])) . '">';
            $group = $a['type'];
        }
        $html .= '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === $selected ? ' selected' : '') . '>' . gl_h(gl_account_label($a)) . '</option>';
    }
    return $html . ($group !== null ? '</optgroup>' : '');
};
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'journal'; include __DIR__ . '/../includes/gl/gl_page_nav.php'; ?>

    <?php if ($flash !== null): ?><div class="glr-flash <?= $flashOk ? 'ok' : 'err' ?>"><?= gl_h($flash) ?></div><?php endif; ?>

    <?php if ($view === 'list'): ?>
      <div class="glr-top">
        <div><h1>Journal</h1><p>All ledger entries. Entries are permanent; corrections are made with reversing entries.</p></div>
        <a class="glr-btn" href="gl-journal.php?new=1">New journal entry</a>
      </div>
      <form method="get" class="glr-sec glr-form">
        <input type="search" name="q" value="<?= gl_h($q) ?>" placeholder="Entry no., description or reference" style="width:260px">
        <select name="kind"><option value="">All kinds</option><?php foreach ($kindLabels as $k => $l): ?><option value="<?= gl_h($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= gl_h($l) ?></option><?php endforeach; ?></select>
        <label>From <input type="date" name="from_date" value="<?= gl_h($from) ?>"></label>
        <label>To <input type="date" name="to_date" value="<?= gl_h($to) ?>"></label>
        <button class="glr-btn" type="submit">Filter</button>
      </form>
      <section class="glr-sec">
        <div class="glr-tw"><table class="glr-t">
          <thead><tr><th>Entry</th><th>Date</th><th>Description</th><th>Reference</th><th>Kind</th><th class="n">Amount</th></tr></thead>
          <tbody>
          <?php if (!$entries): ?><tr><td colspan="6">No entries match.</td></tr><?php endif; ?>
          <?php foreach ($entries as $e): ?>
            <tr>
              <td><a href="gl-journal.php?id=<?= (int) $e['id'] ?>"><b><?= gl_h($e['entry_number']) ?></b></a></td>
              <td><?= gl_h(date('d M Y', strtotime($e['entry_date']))) ?></td>
              <td class="wrap"><?= gl_h($e['description']) ?></td>
              <td><?= gl_h($e['source_ref']) ?></td>
              <td><span class="glr-pill <?= $e['entry_kind'] === 'reversal' ? 'warn' : ($e['entry_kind'] === 'manual' ? 'info' : 'ok') ?>"><?= gl_h($kindLabels[$e['entry_kind']] ?? $e['entry_kind']) ?></span>
                  <?php if ($e['reversed_by']): ?><span class="glr-pill bad">Reversed</span><?php endif; ?></td>
              <td class="n"><?= number_format((float) $e['total_amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="glr-form">
          <span><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?></span>
          <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
            <a class="glr-btn sm <?= $p === $page ? '' : 'secondary' ?>" href="?<?= gl_h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
      </section>

    <?php elseif ($view === 'show'): ?>
      <div class="glr-top">
        <div>
          <h1><?= gl_h($entry['entry_number']) ?></h1>
          <p><?= gl_h($entry['description']) ?></p>
        </div>
        <div style="display:flex;gap:8px"><a class="glr-btn secondary" href="gl-journal.php">Back to journal</a><button class="glr-btn secondary" onclick="window.print()">Print</button></div>
      </div>
      <div class="glr-cards">
        <div class="glr-card"><div class="k">Date</div><div class="v" style="font-size:16px"><?= gl_h(date('d M Y', strtotime($entry['entry_date']))) ?></div>
          <?php if ($entry['original_date']): ?><div class="s">Originally <?= gl_h(date('d M Y', strtotime($entry['original_date']))) ?> (period was locked)</div><?php endif; ?></div>
        <div class="glr-card"><div class="k">Kind</div><div class="v" style="font-size:16px"><?= gl_h($kindLabels[$entry['entry_kind']] ?? $entry['entry_kind']) ?></div><div class="s"><?= gl_h($entry['source_type']) ?></div></div>
        <div class="glr-card"><div class="k">Reference</div><div class="v" style="font-size:16px"><?= gl_h($entry['source_ref'] ?: '-') ?></div></div>
        <div class="glr-card"><div class="k">Amount</div><div class="v" style="font-size:16px"><?= number_format((float) $entry['total_amount'], 2) ?> <?= gl_h($entry['currency']) ?></div><div class="s">Posted <?= gl_h(date('d M Y H:i', strtotime($entry['created_at']))) ?></div></div>
      </div>
      <?php if ($reversedBy): ?><div class="glr-flash err">This entry was reversed by <a href="gl-journal.php?id=<?= (int) $reversedBy['id'] ?>"><?= gl_h($reversedBy['entry_number']) ?></a>.</div><?php endif; ?>
      <?php if ($reverses): ?><div class="glr-flash ok">This entry reverses <a href="gl-journal.php?id=<?= (int) $reverses['id'] ?>"><?= gl_h($reverses['entry_number']) ?></a>.</div><?php endif; ?>
      <section class="glr-sec">
        <div class="glr-tw"><table class="glr-t">
          <thead><tr><th>#</th><th>Account</th><th>Money account</th><th>Details</th><th class="n">Debit</th><th class="n">Credit</th></tr></thead>
          <tbody>
          <?php $sd = 0; $sc = 0; foreach ($lines as $l): $sd += (float) $l['debit']; $sc += (float) $l['credit']; ?>
            <tr>
              <td><?= (int) $l['line_no'] ?></td>
              <td><a href="ledger.php?account_id=<?= (int) $l['account_id'] ?>&from_date=<?= gl_h(date('Y-01-01', strtotime($entry['entry_date']))) ?>&to_date=<?= gl_h($entry['entry_date']) ?>"><?= gl_h(gl_account_label(['code' => $l['code'], 'name' => $l['account_name']])) ?></a></td>
              <td><?= gl_h($l['fa_name'] ?? '') ?></td>
              <td class="wrap"><?= gl_h($l['memo']) ?></td>
              <td class="n"><?= (float) $l['debit'] > 0 ? number_format((float) $l['debit'], 2) : '' ?></td>
              <td class="n"><?= (float) $l['credit'] > 0 ? number_format((float) $l['credit'], 2) : '' ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="tot"><td colspan="4">Total</td><td class="n"><?= number_format($sd, 2) ?></td><td class="n"><?= number_format($sc, 2) ?></td></tr>
          </tbody>
        </table></div>
      </section>
      <?php if ($entry['entry_kind'] === 'manual' && !$reversedBy): ?>
        <section class="glr-sec">
          <h2>Reverse this entry</h2>
          <div class="note">Posts the exact opposite entry. The original stays on the books so the history is complete.</div>
          <form method="post" class="glr-form" onsubmit="return confirm('Post a reversing entry?');">
            <input type="hidden" name="csrf_token" value="<?= gl_h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reverse">
            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
            <label>Date <input type="date" name="date" value="<?= gl_h(date('Y-m-d')) ?>"></label>
            <input name="reason" placeholder="Reason (required)" required maxlength="200" style="width:320px">
            <button class="glr-btn danger" type="submit">Post reversal</button>
          </form>
        </section>
      <?php elseif ($entry['entry_kind'] !== 'manual' && $entry['entry_kind'] !== 'reversal' && !$reversedBy): ?>
        <div class="glr-flash ok" style="background:#f8fafc;color:#334155;border-color:#e2e8f0">This entry was created automatically from <?= gl_h($entry['source_ref'] ?: $entry['source_type']) ?>. To correct it, fix the source document; the ledger reverses and re-posts it automatically.</div>
      <?php endif; ?>

    <?php else: ?>
      <div class="glr-top">
        <div><h1>New journal entry</h1><p>Use for adjustments that have no source document (accruals, depreciation, corrections). Debits must equal credits.</p></div>
        <a class="glr-btn secondary" href="gl-journal.php">Cancel</a>
      </div>
      <?php if ($formErrors): ?><div class="glr-flash err"><?= gl_h(implode(' ', array_unique($formErrors))) ?></div><?php endif; ?>
      <?php $lockedThrough = gl_locked_through($pdo); if ($lockedThrough): ?>
        <div class="glr-flash ok" style="background:#fffbeb;color:#92400e;border-color:#fde68a">Periods up to <?= gl_h(date('d M Y', strtotime($lockedThrough))) ?> are locked; earlier dates will be posted on the next open day.</div>
      <?php endif; ?>
      <form method="post" id="je-form">
        <input type="hidden" name="csrf_token" value="<?= gl_h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="form_token" value="<?= gl_h(preg_match('/^[a-f0-9]{32}$/', (string) ($_POST['form_token'] ?? '')) ? $_POST['form_token'] : bin2hex(random_bytes(16))) ?>">
        <section class="glr-sec glr-form">
          <label>Date <input type="date" name="date" value="<?= gl_h($old['date']) ?>" required></label>
          <input name="description" value="<?= gl_h($old['description']) ?>" placeholder="Description (required)" required maxlength="500" style="flex:1;min-width:280px">
          <input name="reference" value="<?= gl_h($old['reference']) ?>" placeholder="Reference (optional)" maxlength="120" style="width:180px">
        </section>
        <section class="glr-sec">
          <div class="glr-tw"><table class="glr-t" id="je-lines">
            <thead><tr><th>Account</th><th>Details</th><th class="n">Debit</th><th class="n">Credit</th><th></th></tr></thead>
            <tbody>
            <?php $rows = $old['lines'] ?: array_fill(0, 2, ['account_id' => 0, 'debit' => '', 'credit' => '', 'memo' => '']); ?>
            <?php foreach ($rows as $i => $r): ?>
              <tr>
                <td><select name="lines[<?= $i ?>][account_id]"><?= $accountOptions((int) $r['account_id']) ?></select></td>
                <td><input name="lines[<?= $i ?>][memo]" value="<?= gl_h($r['memo']) ?>" style="width:100%;min-width:200px"></td>
                <td class="n"><input name="lines[<?= $i ?>][debit]" value="<?= gl_h($r['debit']) ?>" inputmode="decimal" class="je-dr" style="width:140px;text-align:right"></td>
                <td class="n"><input name="lines[<?= $i ?>][credit]" value="<?= gl_h($r['credit']) ?>" inputmode="decimal" class="je-cr" style="width:140px;text-align:right"></td>
                <td><button type="button" class="glr-btn sm secondary je-del">Remove</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="tot"><td><button type="button" class="glr-btn sm secondary" id="je-add">Add line</button></td><td>Totals <span id="je-diff"></span></td>
              <td class="n" id="je-tdr">0.00</td><td class="n" id="je-tcr">0.00</td><td></td></tr></tfoot>
          </table></div>
          <div class="glr-form"><button class="glr-btn" type="submit" id="je-submit">Post entry</button><span style="font-size:12px;color:#64748b">Posted entries cannot be edited or deleted.</span></div>
        </section>
      </form>
      <script>
      (function () {
        var body = document.querySelector('#je-lines tbody');
        var n = body.rows.length;
        function num(v) { v = parseFloat(String(v || '').replace(/,/g, '')); return isNaN(v) ? 0 : Math.round(v * 100); }
        function recalc() {
          var dr = 0, cr = 0;
          body.querySelectorAll('.je-dr').forEach(function (i) { dr += num(i.value); });
          body.querySelectorAll('.je-cr').forEach(function (i) { cr += num(i.value); });
          var f = function (c) { return (c / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
          document.getElementById('je-tdr').textContent = f(dr);
          document.getElementById('je-tcr').textContent = f(cr);
          var ok = dr > 0 && dr === cr;
          var d = document.getElementById('je-diff');
          d.textContent = ok ? '(balanced)' : '(difference ' + f(Math.abs(dr - cr)) + ')';
          d.style.color = ok ? '#16a34a' : '#dc2626';
          document.getElementById('je-submit').disabled = !ok;
        }
        body.addEventListener('input', function (e) {
          var row = e.target.closest('tr');
          if (e.target.classList.contains('je-dr') && num(e.target.value)) { row.querySelector('.je-cr').value = ''; }
          if (e.target.classList.contains('je-cr') && num(e.target.value)) { row.querySelector('.je-dr').value = ''; }
          recalc();
        });
        body.addEventListener('click', function (e) {
          if (e.target.classList.contains('je-del') && body.rows.length > 2) { e.target.closest('tr').remove(); recalc(); }
        });
        document.getElementById('je-add').addEventListener('click', function () {
          var tr = body.rows[0].cloneNode(true);
          tr.querySelectorAll('input,select').forEach(function (el) {
            el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + n + ']');
            if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
          });
          n++;
          body.appendChild(tr);
        });
        recalc();
      })();
      </script>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
