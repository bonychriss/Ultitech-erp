<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gl/gl_reports.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

global $pdo;

$schemaReady = gl_ensure_schema($pdo);
$base = gl_base_currency($pdo);
$flash = null;
$flashOk = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Your session expired. Reload the page and try again.';
        $flashOk = false;
    } elseif ($action === 'add') {
        $currency = gl_normalize_currency($_POST['currency'] ?? '');
        $date = (string) ($_POST['rate_date'] ?? '');
        $rate = (float) str_replace([',', ' '], '', (string) ($_POST['rate'] ?? ''));
        if ($currency === '' || $currency === $base) {
            $flashOk = false;
            $flash = 'Choose a currency other than ' . $base . '.';
        } elseif (!strtotime($date)) {
            $flashOk = false;
            $flash = 'Enter a valid date.';
        } elseif ($rate <= 0) {
            $flashOk = false;
            $flash = 'The rate must be greater than zero.';
        } else {
            $pdo->prepare('INSERT INTO gl_exchange_rates (currency, rate_date, rate, note, created_by) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE rate = VALUES(rate), note = VALUES(note), created_by = VALUES(created_by)')
                ->execute([$currency, date('Y-m-d', strtotime($date)), $rate, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null, $_SESSION['user_id'] ?? null]);
            $res = gl_meta_get($pdo, 'autosync') === '1' ? gl_bridge_sync_all($pdo) : null;
            $flash = "Saved: 1 {$currency} = " . number_format($rate, 2) . " {$base} from " . date('d M Y', strtotime($date)) . '.';
            if ($res !== null) {
                $flash .= " Ledger synced: {$res['posted']} entr" . ($res['posted'] === 1 ? 'y' : 'ies') . ' posted.';
                if (!empty($res['errors'])) {
                    $flashOk = false;
                    $flash .= ' Still waiting: ' . implode(' | ', array_slice($res['errors'], 0, 2));
                }
            }
        }
    } elseif ($action === 'delete' && isAdmin()) {
        $pdo->prepare('DELETE FROM gl_exchange_rates WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        $flash = 'Rate removed. Entries already posted keep the rate they were posted with.';
    }
}

$rates = $schemaReady ? $pdo->query('SELECT r.*, u.full_name FROM gl_exchange_rates r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.currency, r.rate_date DESC')->fetchAll(PDO::FETCH_ASSOC) : [];
$latest = $schemaReady ? gl_latest_exchange_rates($pdo) : [];

$inUse = [];
foreach ($pdo->query("SELECT UPPER(currency) AS c, COUNT(*) AS n FROM financial_accounts WHERE currency IS NOT NULL AND currency <> '' GROUP BY UPPER(currency)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($r['c'] !== $base) {
        $inUse[$r['c']] = (int) $r['n'];
    }
}
$catalog = function_exists('balances_currency_catalog') ? balances_currency_catalog() : [];
if (!$catalog && is_file(__DIR__ . '/../modules/balances/functions.php')) {
    require_once __DIR__ . '/../modules/balances/functions.php';
    $catalog = function_exists('balances_currency_catalog') ? balances_currency_catalog() : [];
}
$choices = array_unique(array_merge(array_keys($inUse), array_keys($catalog), array_keys($latest)));
$choices = array_values(array_filter($choices, static fn ($c) => $c !== $base));
sort($choices);

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$page_title = 'Exchange Rates';
include __DIR__ . '/../modules/balances/includes/header.php';
?>
<?php include __DIR__ . '/../includes/gl/gl_page_style.php'; ?>

<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'fx'; include __DIR__ . '/../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Exchange Rates</h1>
        <p>The ledger and every report are kept in <b><?= $h($base) ?></b>. Money in other currencies is converted with the latest rate dated on or before
           each transaction. Posted entries keep the rate they used; a new rate only applies to transactions on or after its date that have not been posted yet.</p>
      </div>
    </div>

    <?php if ($flash !== null): ?>
      <div class="glr-flash <?= $flashOk ? 'ok' : 'err' ?>"><?= $h($flash) ?></div>
    <?php endif; ?>

    <?php if ($inUse): ?>
    <div class="glr-cards">
      <?php foreach ($inUse as $cur => $n): $l = $latest[$cur] ?? null; ?>
        <div class="glr-card"><div class="k"><?= $h($cur) ?> (<?= $n ?> account<?= $n === 1 ? '' : 's' ?>)</div>
          <div class="v <?= $l ? '' : 'bad-t' ?>"><?= $l ? number_format($l['rate'], 2) . ' ' . $h($base) : 'No rate' ?></div>
          <div class="s"><?= $l ? 'Since ' . $h(date('d M Y', strtotime($l['rate_date']))) : 'Transactions in ' . $h($cur) . ' are waiting to post' ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <section class="glr-sec">
      <h2>Add a rate</h2>
      <form method="post" class="glr-lock">
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <label>1
          <select name="currency" required>
            <?php
            $missing = array_keys(array_diff_key($inUse, $latest));
            $defaultChoice = $missing[0] ?? (in_array('USD', $choices, true) ? 'USD' : ($choices[0] ?? ''));
            foreach ($choices as $c): ?>
              <option value="<?= $h($c) ?>"<?= $c === $defaultChoice ? ' selected' : '' ?>><?= $h($c) ?><?= isset($catalog[$c]['name']) ? ' - ' . $h($catalog[$c]['name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>= <input type="text" name="rate" inputmode="decimal" placeholder="e.g. 2,650" required style="width:120px"> <?= $h($base) ?></label>
        <label>from <input type="date" name="rate_date" value="<?= date('Y-m-d') ?>" required></label>
        <input type="text" name="note" placeholder="Source, e.g. Bank of Tanzania (optional)" maxlength="255">
        <button class="glr-btn" type="submit">Save rate</button>
      </form>
    </section>

    <section class="glr-sec">
      <h2>Rate history</h2>
      <div class="glr-tw"><table class="glr-t">
        <thead><tr><th>Currency</th><th>From</th><th class="n">Rate (<?= $h($base) ?>)</th><th>Note</th><th>Added by</th><?php if (isAdmin()): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$rates): ?><tr><td colspan="6">No rates yet.</td></tr><?php endif; ?>
        <?php foreach ($rates as $r): ?>
          <tr>
            <td><b><?= $h($r['currency']) ?></b></td>
            <td><?= $h(date('d M Y', strtotime($r['rate_date']))) ?></td>
            <td class="n"><?= number_format((float) $r['rate'], 4) ?></td>
            <td><?= $h($r['note'] ?? '') ?></td>
            <td><?= $h($r['full_name'] ?? '') ?></td>
            <?php if (isAdmin()): ?>
            <td>
              <form method="post" onsubmit="return confirm('Remove this rate?');" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="glr-btn secondary" type="submit">Remove</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
