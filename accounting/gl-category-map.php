<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gl/gl_reports.php';
requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

global $pdo;
gl_ensure_schema($pdo);

$flash = null;
$flashOk = true;
$typeLabels = ['expense' => 'Expenses', 'asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Your session expired. Reload the page and try again.';
        $flashOk = false;
    } elseif (($_POST['action'] ?? '') === 'save_map') {
        $valid = [];
        foreach ($pdo->query('SELECT id FROM erp_accounts WHERE is_postable = 1')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $valid[(int) $id] = true;
        }
        $set = $pdo->prepare('INSERT INTO gl_category_map (category, account_id, updated_by) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), updated_by = VALUES(updated_by)');
        $del = $pdo->prepare('DELETE FROM gl_category_map WHERE category = ?');
        foreach ((array) ($_POST['map'] ?? []) as $category => $accountId) {
            $category = mb_substr(trim((string) $category), 0, 100);
            $accountId = (int) $accountId;
            if ($category === '') {
                continue;
            }
            if ($accountId > 0 && isset($valid[$accountId])) {
                $set->execute([$category, $accountId, $_SESSION['user_id'] ?? null]);
            } elseif ($accountId === 0) {
                $del->execute([$category]);
            }
        }
        $res = gl_bridge_sync_all($pdo);
        $flashOk = empty($res['errors']);
        $reclass = (int) ($res['by_type']['reclassification'] ?? 0);
        $flash = $flashOk
            ? "Mapping saved. {$reclass} reclassification entr" . ($reclass === 1 ? 'y' : 'ies') . ' posted.'
            : 'Mapping saved, but the ledger update reported errors: ' . implode(' | ', array_slice($res['errors'], 0, 3));
    } elseif (($_POST['action'] ?? '') === 'add_account') {
        $code = preg_replace('/[^0-9A-Za-z\-.]/', '', (string) ($_POST['code'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = strtolower((string) ($_POST['type'] ?? 'expense'));
        $exists = $pdo->prepare('SELECT 1 FROM erp_accounts WHERE code = ? LIMIT 1');
        $exists->execute([$code]);
        if ($code === '' || $name === '' || !isset($typeLabels[$type])) {
            $flash = 'Enter a code, a name and a type for the new account.';
            $flashOk = false;
        } elseif ($exists->fetchColumn()) {
            $flash = "Code {$code} is already used. Each account needs a unique code.";
            $flashOk = false;
        } else {
            $pdo->prepare("INSERT INTO erp_accounts (code, name, type, description, is_system, status, is_postable) VALUES (?, ?, ?, ?, '0', 'active', 1)")
                ->execute([$code, mb_substr($name, 0, 255), $type, 'Created from voucher category mapping']);
            $flash = "Account {$code} - {$name} created.";
        }
    }
}

$categories = $pdo->query("SELECT vi.budget_type AS category,
                                  COUNT(DISTINCT vi.voucher_id) AS vouchers,
                                  SUM(vi.amount) AS amount,
                                  COUNT(DISTINCT IF(pv.is_paid = 1, vi.voucher_id, NULL)) AS paid_vouchers
                           FROM voucher_items vi
                           JOIN payment_vouchers pv ON pv.id = vi.voucher_id
                           WHERE TRIM(COALESCE(vi.budget_type, '')) <> ''
                             AND LOWER(COALESCE(pv.purpose, 'general')) NOT IN ('stock_purchase', 'stock_purchases')
                           GROUP BY vi.budget_type
                           ORDER BY amount DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$mapped = [];
foreach ($pdo->query('SELECT category, account_id FROM gl_category_map')->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $mapped[(string) $m['category']] = (int) $m['account_id'];
}
$accounts = $pdo->query("SELECT id, code, name, LOWER(type) AS type FROM erp_accounts
                         WHERE is_postable = 1 AND COALESCE(status, 'active') <> 'inactive'
                           AND (system_key IS NULL OR system_key NOT IN ('suspense', 'opening_balance_equity', 'transfer_clearing'))
                         ORDER BY FIELD(LOWER(type), 'expense', 'liability', 'asset', 'equity', 'revenue'), code + 0, code")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byType = [];
foreach ($accounts as $a) {
    $byType[$a['type']][] = $a;
}
$suspense = gl_report_suspense_lines($pdo);
$suspenseTotal = array_sum(array_map(static fn ($s) => (float) $s['amount'], $suspense));

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$page_title = 'Voucher Categories';
include __DIR__ . '/../modules/balances/includes/header.php';
include __DIR__ . '/../includes/gl/gl_page_style.php';
?>
<main class="main-content glr">
  <div class="glr-wrap">
    <?php $glNavActive = 'categories'; include __DIR__ . '/../includes/gl/gl_page_nav.php'; ?>
    <div class="glr-top">
      <div>
        <h1>Voucher Categories</h1>
        <p>Choose the ledger account for each payment voucher category. Payments in unmapped categories wait in Suspense.
           Saving a mapping posts a dated reclassification entry; earlier entries are never changed.
           Stock purchase vouchers always go to Inventory.</p>
      </div>
    </div>

    <?php if ($flash !== null): ?>
      <div class="glr-flash <?= $flashOk ? 'ok' : 'err' ?>"><?= $h($flash) ?></div>
    <?php endif; ?>

    <div class="glr-cards">
      <div class="glr-card"><div class="k">Categories</div><div class="v"><?= count($categories) ?></div><div class="s"><?= count(array_intersect_key($mapped, array_flip(array_column($categories, 'category')))) ?> mapped</div></div>
      <div class="glr-card"><div class="k">Still in Suspense</div><div class="v <?= abs($suspenseTotal) < 0.005 ? 'ok-t' : 'warn-t' ?>"><?= number_format($suspenseTotal, 2) ?></div><div class="s"><?= count($suspense) ?> item(s)</div></div>
    </div>

    <section class="glr-sec">
      <h2>Category mapping</h2>
      <div class="note">Tax payments (for example VAT or PAYE paid to TRA) usually settle a liability such as Tax Payable rather than being an expense.</div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_map">
        <div class="glr-tw"><table class="glr-t">
          <thead><tr><th>Voucher category</th><th class="n">Vouchers</th><th class="n">Paid</th><th class="n">Total on vouchers</th><th>Ledger account</th></tr></thead>
          <tbody>
          <?php if (!$categories): ?><tr><td colspan="5">No voucher categories found.</td></tr><?php endif; ?>
          <?php foreach ($categories as $c): ?>
            <?php $cur = $mapped[(string) $c['category']] ?? 0; $auto = $cur ? 0 : gl_bridge_category_account($pdo, (string) $c['category']); ?>
            <tr>
              <td><b><?= $h($c['category']) ?></b></td>
              <td class="n"><?= number_format((int) $c['vouchers']) ?></td>
              <td class="n"><?= number_format((int) $c['paid_vouchers']) ?></td>
              <td class="n"><?= number_format((float) $c['amount'], 2) ?></td>
              <td>
                <select name="map[<?= $h($c['category']) ?>]">
                  <option value="0"><?= $auto ? 'Automatic (same-name expense account)' : 'Not mapped - keep in Suspense' ?></option>
                  <?php foreach ($byType as $type => $list): ?>
                    <optgroup label="<?= $h($typeLabels[$type] ?? ucfirst($type)) ?>">
                      <?php foreach ($list as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $cur === (int) $a['id'] ? 'selected' : '' ?>><?= $h(trim(($a['code'] ? $a['code'] . ' - ' : '') . $a['name'])) ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="glr-form"><button class="glr-btn" type="submit">Save mapping and reclassify</button></div>
      </form>
    </section>

    <section class="glr-sec">
      <h2>Add a ledger account</h2>
      <form method="post" class="glr-form">
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_account">
        <input name="code" placeholder="Code, e.g. 5011" required maxlength="20" style="width:140px">
        <input name="name" placeholder="Account name, e.g. Staff Costs" required maxlength="255" style="width:280px">
        <select name="type">
          <?php foreach ($typeLabels as $k => $label): ?><option value="<?= $h($k) ?>"><?= $h($label) ?></option><?php endforeach; ?>
        </select>
        <button class="glr-btn secondary" type="submit">Create account</button>
      </form>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
