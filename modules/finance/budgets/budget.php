<?php
require_once __DIR__ . '/lib.php';
requireFinanceOrAdmin();

$qs = function (array $extra = []) {
    return '?' . http_build_query(array_merge($_GET ?: [], $extra));
};

$budgetId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($budgetId <= 0) redirect('index.php?module=finance');

$st = $pdo->prepare('SELECT * FROM budgets WHERE id = ?');
$st->execute([$budgetId]);
$budget = $st->fetch(PDO::FETCH_ASSOC);
if (!$budget) redirect('index.php?module=finance');

$periodType = $_GET['period_type'] ?? ($budget['period_type'] ?? 'monthly');
if (!in_array($periodType, ['monthly', 'quarterly', 'yearly'], true)) $periodType = 'monthly';
$periodKey = $_GET['period'] ?? ($periodType === 'monthly' ? date('Y-m') : ($periodType === 'yearly' ? date('Y') : (date('Y') . '-Q' . (int)ceil(((int)date('n')) / 3))));
[$periodStart, $periodEnd] = budget_parse_period($periodType, (string)$periodKey);

$flash = $_GET['success'] ?? '';
$err = $_GET['error'] ?? '';

// Actions: add item, update item, set sources
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        redirect('budget.php' . $qs(['id' => $budgetId, 'error' => 'csrf']));
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_item') {
        $name = trim((string)($_POST['item_name'] ?? ''));
        $cat = trim((string)($_POST['category'] ?? ''));
        $budgeted = (float)($_POST['budgeted_amount'] ?? 0);
        $threshold = (float)($_POST['alert_threshold_percent'] ?? 90);
        $email = trim((string)($_POST['alert_email'] ?? ''));

        if ($name !== '' && $budgeted >= 0) {
            $st = $pdo->prepare('INSERT INTO budget_items (budget_id, item_name, category, budgeted_amount, alert_threshold_percent, alert_email) VALUES (?,?,?,?,?,?)');
            $st->execute([$budgetId, $name, ($cat !== '' ? $cat : null), $budgeted, ($threshold > 0 ? $threshold : 90), ($email !== '' ? $email : null)]);
            $itemId = (int) $pdo->lastInsertId();

            // Sources (checkboxes)
            $sources = $_POST['sources'] ?? [];
            if (is_array($sources)) {
                foreach ($sources as $src) {
                    $src = (string) $src;
                    if (!in_array($src, ['purchase_orders', 'payroll'], true)) continue;
                    $rule = [];
                    if ($src === 'purchase_orders') {
                        $pt = strtolower(trim((string)($_POST['po_purchase_type'] ?? '')));
                        if (in_array($pt, ['domestic', 'import'], true)) $rule['purchase_type'] = $pt;
                    }
                    $st2 = $pdo->prepare('INSERT INTO budget_item_sources (budget_item_id, source_type, rule_json) VALUES (?,?,?)');
                    $st2->execute([$itemId, $src, json_encode($rule)]);
                }
            }

            budget_notify_line_changed('add', $budgetId, (string)($budget['name'] ?? ''), $name, $periodType, $periodKey, (int)($_SESSION['user_id'] ?? 0));
            redirect('budget.php' . $qs(['id' => $budgetId, 'success' => 'item_added']));
        }
    }

    if ($action === 'update_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $name = trim((string)($_POST['item_name'] ?? ''));
        $cat = trim((string)($_POST['category'] ?? ''));
        $budgeted = (float)($_POST['budgeted_amount'] ?? 0);
        $threshold = (float)($_POST['alert_threshold_percent'] ?? 90);
        $email = trim((string)($_POST['alert_email'] ?? ''));

        if ($itemId > 0 && $name !== '') {
            $st = $pdo->prepare('UPDATE budget_items SET item_name=?, category=?, budgeted_amount=?, alert_threshold_percent=?, alert_email=?, updated_at=NOW() WHERE id=? AND budget_id=?');
            $st->execute([$name, ($cat !== '' ? $cat : null), $budgeted, ($threshold > 0 ? $threshold : 90), ($email !== '' ? $email : null), $itemId, $budgetId]);

            // Replace sources
            $pdo->prepare('DELETE FROM budget_item_sources WHERE budget_item_id = ?')->execute([$itemId]);
            $sources = $_POST['sources'] ?? [];
            if (is_array($sources)) {
                foreach ($sources as $src) {
                    $src = (string) $src;
                    if (!in_array($src, ['purchase_orders', 'payroll'], true)) continue;
                    $rule = [];
                    if ($src === 'purchase_orders') {
                        $pt = strtolower(trim((string)($_POST['po_purchase_type'] ?? '')));
                        if (in_array($pt, ['domestic', 'import'], true)) $rule['purchase_type'] = $pt;
                    }
                    $pdo->prepare('INSERT INTO budget_item_sources (budget_item_id, source_type, rule_json) VALUES (?,?,?)')
                        ->execute([$itemId, $src, json_encode($rule)]);
                }
            }

            budget_notify_line_changed('update', $budgetId, (string)($budget['name'] ?? ''), $name, $periodType, $periodKey, (int)($_SESSION['user_id'] ?? 0));
            redirect('budget.php' . $qs(['id' => $budgetId, 'success' => 'item_updated']));
        }
    }
}

// Items
$st = $pdo->prepare('SELECT * FROM budget_items WHERE budget_id = ? AND is_active = 1 ORDER BY id DESC');
$st->execute([$budgetId]);
$items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Compute
$rows = [];
$totalBudgeted = 0.0;
$totalActual = 0.0;
foreach ($items as $it) {
    $iid = (int) $it['id'];
    $budgeted = (float) ($it['budgeted_amount'] ?? 0);
    $actual = budget_compute_item_actual($iid, $periodStart, $periodEnd);
    $spentPct = budget_compute_variance_percent($budgeted, $actual);
    $varianceAmt = $budgeted - $actual;
    $rows[] = [
        'item' => $it,
        'sources' => budget_get_item_sources($iid),
        'actual' => $actual,
        'spent_pct' => $spentPct,
        'variance_amount' => $varianceAmt,
    ];
    $totalBudgeted += $budgeted;
    $totalActual += $actual;
}

$overallSpentPct = budget_compute_variance_percent($totalBudgeted, $totalActual);

$page_title = 'Budget - ' . ($budget['name'] ?? '');
include __DIR__ . '/../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .bud-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
    .dash-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
    .dash-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .dash-table thead th { background:#1c2331 !important; color:#fff !important; font-weight:700 !important; border-bottom:2px solid #151a24 !important; text-transform:uppercase; font-size:.75rem; letter-spacing:.04em; }
    .kpi { display:flex; align-items:center; gap:.6rem; }
    .kpi .badge { font-size:.75rem; }
    .bud-kpi-card { display: flex; align-items: flex-start; gap: 1rem; }
    .bud-kpi-icon {
        width: 48px; height: 48px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; color: #fff; flex-shrink: 0;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
    }
    .bud-kpi-icon--budget { background: linear-gradient(145deg, #059669 0%, #34d399 100%); }
    .bud-kpi-icon--spent { background: linear-gradient(145deg, #1d4ed8 0%, #60a5fa 100%); }
    .bud-kpi-icon--pct { background: linear-gradient(145deg, #6d28d9 0%, #a78bfa 100%); }
    .bud-kpi-body { min-width: 0; flex: 1; }
    .bud-kpi-alert { display: flex; align-items: flex-start; gap: 0.45rem; margin-top: 0.65rem; color: #6b7280; font-size: 0.8125rem; line-height: 1.35; }
    .bud-kpi-alert > i { color: #f59e0b; flex-shrink: 0; margin-top: 2px; }
</style>

<main class="main-content bud-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <a href="index.php<?= htmlspecialchars($qs(['module' => 'finance', 'period_type' => $periodType, 'period' => $periodKey])) ?>" class="btn btn-outline-secondary btn-sm rounded-2">
                    <i class="bi bi-arrow-left me-1"></i> Budgets
                </a>
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-bullseye text-[#2563EB]"></i><span><?= htmlspecialchars($budget['name'] ?? 'Budget') ?></span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
                <a class="btn btn-sm btn-outline-secondary rounded-2" href="dashboard.php<?= htmlspecialchars($qs(['id' => $budgetId, 'period_type' => $periodType, 'period' => $periodKey])) ?>">
                    <i class="bi bi-speedometer2 me-1"></i> Dashboard
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-sm bg-gray-50/80 border-b border-gray-100 text-gray-600">
                <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="module" value="finance">
                    <input type="hidden" name="id" value="<?= (int)$budgetId ?>">
                    <select name="period_type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="monthly" <?= $periodType === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="quarterly" <?= $periodType === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                        <option value="yearly" <?= $periodType === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                    </select>
                    <?php if ($periodType === 'monthly'): ?>
                        <input type="month" name="period" value="<?= htmlspecialchars(substr($periodStart, 0, 7)) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
                    <?php elseif ($periodType === 'yearly'): ?>
                        <input type="number" min="2000" max="2100" name="period" value="<?= htmlspecialchars(substr($periodStart, 0, 4)) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
                    <?php else: ?>
                        <?php
                        $y = (int) substr($periodStart, 0, 4);
                        $m = (int) substr($periodStart, 5, 2);
                        $q = (int) ceil($m / 3);
                        $qKey = $y . '-Q' . $q;
                        ?>
                        <input type="text" name="period" value="<?= htmlspecialchars($qKey) ?>" class="form-control form-control-sm" placeholder="YYYY-Qn" onchange="this.form.submit()">
                    <?php endif; ?>
                    <span class="text-gray-400 small">Period: <?= htmlspecialchars($periodStart) ?> ? <?= htmlspecialchars($periodEnd) ?></span>
                </form>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <?php if ($err === 'csrf'): ?>
                <div class="alert alert-danger border-0 shadow-sm">Session expired. Please try again.</div>
            <?php endif; ?>
            <?php if ($flash === 'item_added'): ?>
                <div class="alert alert-success border-0 shadow-sm">Budget item added.</div>
            <?php elseif ($flash === 'item_updated'): ?>
                <div class="alert alert-success border-0 shadow-sm">Budget item updated.</div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-4">
                    <div class="dash-card p-4 bud-kpi-card">
                        <div class="bud-kpi-icon bud-kpi-icon--budget" aria-hidden="true"><i class="fas fa-coins"></i></div>
                        <div class="bud-kpi-body">
                            <div class="text-muted small fw-semibold">Total budgeted</div>
                            <div class="h3 m-0 text-gray-900"><?= htmlspecialchars($budget['currency'] ?? 'TZS') ?> <?= budget_money($totalBudgeted) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="dash-card p-4 bud-kpi-card">
                        <div class="bud-kpi-icon bud-kpi-icon--spent" aria-hidden="true"><i class="fas fa-receipt"></i></div>
                        <div class="bud-kpi-body">
                            <div class="text-muted small fw-semibold">Actual spent (auto)</div>
                            <div class="h3 m-0 text-gray-900"><?= htmlspecialchars($budget['currency'] ?? 'TZS') ?> <?= budget_money($totalActual) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="dash-card p-4 bud-kpi-card">
                        <div class="bud-kpi-icon bud-kpi-icon--pct" aria-hidden="true"><i class="fas fa-chart-pie"></i></div>
                        <div class="bud-kpi-body">
                            <div class="text-muted small fw-semibold">Spent %</div>
                            <div class="h3 m-0 text-gray-900"><?= number_format($overallSpentPct, 1) ?>%</div>
                            <div class="bud-kpi-alert">
                                <i class="fas fa-bell"></i>
                                <span>Alert triggers per item at its threshold (default 90%).</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-card mb-4">
                <div class="px-4 py-3 border-bottom border-gray-100 fw-bold text-gray-800">
                    <i class="fas fa-plus text-[#2563EB] me-2"></i>Define input fields (Budget Item Name, Budgeted Amount, Actual Spent, Variance %)
                </div>
                <div class="p-4">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_item">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Budget item name</label>
                            <input name="item_name" class="form-control" placeholder="e.g. Transport" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary">Category (for charts)</label>
                            <input name="category" class="form-control" placeholder="e.g. Operations">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary">Budgeted amount</label>
                            <input type="number" step="0.01" min="0" name="budgeted_amount" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold text-secondary">Alert at %</label>
                            <input type="number" step="0.01" min="1" max="999" name="alert_threshold_percent" class="form-control" value="90">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Department head email (for alerts)</label>
                            <input type="email" name="alert_email" class="form-control" placeholder="head@company.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Automation rules (Actuals source)</label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="sources[]" value="purchase_orders" checked>
                                    <span class="form-check-label">Purchase Orders</span>
                                </label>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="sources[]" value="payroll">
                                    <span class="form-check-label">Payroll</span>
                                </label>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="text-muted small">PO type:</span>
                                    <select class="form-select form-select-sm" name="po_purchase_type" style="width:auto;">
                                        <option value="">All</option>
                                        <option value="domestic">Domestic</option>
                                        <option value="import">Import</option>
                                    </select>
                                </div>
                            </div>
                            <div class="small text-muted mt-1">
                                Actuals are pulled automatically from system tables (received/approved POs and approved/paid payroll runs).
                            </div>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary rounded-2 fw-semibold">
                                <i class="bi bi-plus-circle me-1"></i>Add item
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dash-card">
                <div class="px-4 py-3 border-bottom border-gray-100 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-bold text-gray-800"><i class="fas fa-table text-[#2563EB] me-2"></i>Budget items</span>
                    <span class="small text-muted">Actuals are computed for the selected period.</span>
                </div>
                <div class="dash-table-wrap">
                    <table class="table table-hover align-middle mb-0 dash-table">
                        <thead>
                            <tr>
                                <th class="ps-4">Item</th>
                                <th>Category</th>
                                <th class="text-end">Budgeted</th>
                                <th class="text-end">Actual spent</th>
                                <th class="text-end">Spent %</th>
                                <th class="text-end">Variance</th>
                                <th class="text-end pe-4">Edit</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="py-4 text-center text-muted">No budget items yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $it = $r['item'];
                                $iid = (int) $it['id'];
                                $budgeted = (float) ($it['budgeted_amount'] ?? 0);
                                $actual = (float) $r['actual'];
                                $spentPct = (float) $r['spent_pct'];
                                $varianceAmt = (float) $r['variance_amount'];
                                $sources = $r['sources'] ?? [];
                                $srcTypes = array_map(fn($x) => (string)($x['source_type'] ?? ''), is_array($sources) ? $sources : []);
                                $hasPO = in_array('purchase_orders', $srcTypes, true);
                                $hasPay = in_array('payroll', $srcTypes, true);
                                $flag = ($spentPct >= (float)($it['alert_threshold_percent'] ?? 90)) ? 'text-danger' : ($spentPct >= 75 ? 'text-warning' : 'text-success');
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-semibold text-gray-900"><?= htmlspecialchars($it['item_name'] ?? '') ?></div>
                                        <div class="small text-muted">Sources: <?= $hasPO ? 'PO' : '' ?><?= ($hasPO && $hasPay) ? ' + ' : '' ?><?= $hasPay ? 'Payroll' : '' ?></div>
                                    </td>
                                    <td class="text-muted small"><?= htmlspecialchars($it['category'] ?? '') ?></td>
                                    <td class="text-end fw-semibold"><?= budget_money($budgeted) ?></td>
                                    <td class="text-end fw-semibold"><?= budget_money($actual) ?></td>
                                    <td class="text-end fw-bold <?= $flag ?>"><?= number_format($spentPct, 1) ?>%</td>
                                    <td class="text-end <?= $varianceAmt < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= budget_money($varianceAmt) ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-outline-secondary rounded-2"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editItemModal"
                                                data-item='<?= htmlspecialchars(json_encode([
                                                    'id' => $iid,
                                                    'item_name' => $it['item_name'] ?? '',
                                                    'category' => $it['category'] ?? '',
                                                    'budgeted_amount' => $budgeted,
                                                    'alert_threshold_percent' => (float)($it['alert_threshold_percent'] ?? 90),
                                                    'alert_email' => $it['alert_email'] ?? '',
                                                    'sources' => $srcTypes,
                                                ]), ENT_QUOTES, "UTF-8") ?>'>
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</main>

<!-- Edit modal -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit budget item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Budget item name</label>
                            <input name="item_name" id="edit_item_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Category</label>
                            <input name="category" id="edit_category" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Budgeted amount</label>
                            <input type="number" step="0.01" min="0" name="budgeted_amount" id="edit_budgeted_amount" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Alert at %</label>
                            <input type="number" step="0.01" min="1" max="999" name="alert_threshold_percent" id="edit_alert_threshold_percent" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Alert email</label>
                            <input type="email" name="alert_email" id="edit_alert_email" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary">Sources</label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_src_po" name="sources[]" value="purchase_orders">
                                    <span class="form-check-label">Purchase Orders</span>
                                </label>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_src_pay" name="sources[]" value="payroll">
                                    <span class="form-check-label">Payroll</span>
                                </label>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="text-muted small">PO type:</span>
                                    <select class="form-select form-select-sm" name="po_purchase_type" style="width:auto;">
                                        <option value="">All</option>
                                        <option value="domestic">Domestic</option>
                                        <option value="import">Import</option>
                                    </select>
                                </div>
                            </div>
                            <div class="small text-muted mt-1">Saving will replace sources for this item.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary rounded-2" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-2 fw-semibold"><i class="bi bi-save me-1"></i>Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editItemModal')?.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    if (!btn) return;
    let payload = {};
    try { payload = JSON.parse(btn.getAttribute('data-item') || '{}'); } catch (e) { payload = {}; }
    document.getElementById('edit_item_id').value = payload.id || '';
    document.getElementById('edit_item_name').value = payload.item_name || '';
    document.getElementById('edit_category').value = payload.category || '';
    document.getElementById('edit_budgeted_amount').value = payload.budgeted_amount ?? 0;
    document.getElementById('edit_alert_threshold_percent').value = payload.alert_threshold_percent ?? 90;
    document.getElementById('edit_alert_email').value = payload.alert_email || '';
    const srcs = Array.isArray(payload.sources) ? payload.sources : [];
    document.getElementById('edit_src_po').checked = srcs.includes('purchase_orders');
    document.getElementById('edit_src_pay').checked = srcs.includes('payroll');
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

