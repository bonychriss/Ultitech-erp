<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

global $pdo;
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$can_manage = pettyCashCanManage();

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$category = trim((string) ($_GET['category'] ?? ''));
$custodian_id = isset($_GET['custodian_id']) && $_GET['custodian_id'] !== '' ? (int) $_GET['custodian_id'] : null;

if (!$can_manage) {
    $custodian_id = $user_id;
}

$filters = [
    'date_from' => $date_from,
    'date_to' => $date_to,
    'exclude_cancelled' => true,
];
if ($category !== '') {
    $filters['category'] = $category;
}
if ($custodian_id) {
    $filters['custodian_id'] = $custodian_id;
}

$vouchers = getAllPettyCashVouchers($filters);

$total_amount = array_sum(array_column($vouchers, 'amount'));
$approved_vouchers = array_filter($vouchers, fn ($v) => strtolower((string) $v['status']) === 'approved');
$approved_amount = array_sum(array_column($approved_vouchers, 'amount'));

$by_category = [];
$by_custodian = [];
foreach ($vouchers as $v) {
    $cat = (string) $v['category'];
    if (!isset($by_category[$cat])) {
        $by_category[$cat] = ['count' => 0, 'amount' => 0];
    }
    $by_category[$cat]['count']++;
    $by_category[$cat]['amount'] += (float) $v['amount'];

    $custName = (string) ($v['custodian_name'] ?? 'Unknown');
    if (!isset($by_custodian[$custName])) {
        $by_custodian[$custName] = ['count' => 0, 'amount' => 0];
    }
    $by_custodian[$custName]['count']++;
    $by_custodian[$custName]['amount'] += (float) $v['amount'];
}

$categories = getPettyCashCategories();
$custodians = [];
if ($can_manage) {
    try {
        $custodians = $pdo->query(
            "SELECT DISTINCT u.id, u.full_name
             FROM petty_cash_vouchers v
             JOIN users u ON v.custodian_id = u.id
             ORDER BY u.full_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $custodians = [];
    }
}

$page_title = 'Petty Cash Reports';
include __DIR__ . '/includes/header.php';
?>
<style>
    .pc-reports { padding: 1.5rem; max-width: 1400px; }
    .pc-section { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.25rem; margin-bottom:1rem; }
    .pc-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; margin-bottom:1rem; }
    .pc-stat { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem; }
    .pc-stat-label { font-size:12px; color:#64748b; text-transform:uppercase; }
    .pc-stat-value { font-size:1.5rem; font-weight:700; }
    .filter-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; }
    .filter-form label { display:block; font-size:13px; font-weight:500; margin-bottom:.35rem; }
    .filter-form input, .filter-form select { width:100%; padding:.5rem .75rem; border:1px solid #d1d5db; border-radius:8px; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { padding:.75rem; border-bottom:1px solid #eef2f7; text-align:left; }
    th { background:#f8fafc; font-size:11px; text-transform:uppercase; color:#64748b; }
    .chart-bar { height:20px; background:#7c3aed; border-radius:4px; }
</style>

<main class="main-content pc-reports">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Petty Cash Reports</h1>
            <p class="text-secondary small mb-0">Filter by date, category, and custodian.</p>
        </div>
        <div class="ms-auto"><a href="index.php?module=petty_cash" class="btn btn-outline-secondary btn-sm">Back to dashboard</a></div>
    </div>

    <div class="pc-section">
        <form method="GET" class="filter-form">
            <input type="hidden" name="module" value="petty_cash">
            <div><label>Date from</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
            <div><label>Date to</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
            <div>
                <label>Category</label>
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($can_manage): ?>
            <div>
                <label>Custodian</label>
                <select name="custodian_id">
                    <option value="">All custodians</option>
                    <?php foreach ($custodians as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $custodian_id === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Apply filters</button></div>
        </form>
    </div>

    <div class="pc-grid">
        <div class="pc-stat"><div class="pc-stat-label">Total vouchers</div><div class="pc-stat-value"><?= count($vouchers) ?></div></div>
        <div class="pc-stat"><div class="pc-stat-label">Total amount</div><div class="pc-stat-value">TSh <?= number_format($total_amount, 2) ?></div></div>
        <div class="pc-stat"><div class="pc-stat-label">Approved vouchers</div><div class="pc-stat-value"><?= count($approved_vouchers) ?></div></div>
        <div class="pc-stat"><div class="pc-stat-label">Approved amount</div><div class="pc-stat-value">TSh <?= number_format($approved_amount, 2) ?></div></div>
    </div>

    <div class="pc-section">
        <h2 class="h6 mb-3">By category</h2>
        <table>
            <thead><tr><th>Category</th><th>Vouchers</th><th>Amount</th><th style="width:35%">Distribution</th></tr></thead>
            <tbody>
                <?php if (empty($by_category)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No data for selected period.</td></tr>
                <?php else: ?>
                    <?php $max_amount = max(array_column($by_category, 'amount')); ?>
                    <?php foreach ($by_category as $cat => $data): ?>
                        <?php $percentage = $max_amount > 0 ? ($data['amount'] / $max_amount) * 100 : 0; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($cat) ?></strong></td>
                            <td><?= (int) $data['count'] ?></td>
                            <td>TSh <?= number_format($data['amount'], 2) ?></td>
                            <td><div class="chart-bar" style="width: <?= $percentage ?>%;"></div></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($can_manage): ?>
    <div class="pc-section">
        <h2 class="h6 mb-3">By custodian</h2>
        <table>
            <thead><tr><th>Custodian</th><th>Vouchers</th><th>Amount</th></tr></thead>
            <tbody>
                <?php if (empty($by_custodian)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No data for selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($by_custodian as $name => $data): ?>
                        <tr>
                            <td><?= htmlspecialchars($name) ?></td>
                            <td><?= (int) $data['count'] ?></td>
                            <td>TSh <?= number_format($data['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
