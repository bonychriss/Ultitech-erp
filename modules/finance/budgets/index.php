<?php
require_once __DIR__ . '/lib.php';
requireFinanceOrAdmin();

$qs = function (array $extra = []) {
    return '?' . http_build_query(array_merge($_GET ?: [], $extra));
};

$periodType = $_GET['period_type'] ?? 'monthly';
if (!in_array($periodType, ['monthly', 'quarterly', 'yearly'], true)) $periodType = 'monthly';

$periodKey = $_GET['period'] ?? ($periodType === 'monthly' ? date('Y-m') : ($periodType === 'yearly' ? date('Y') : (date('Y') . '-Q' . (int)ceil(((int)date('n')) / 3))));
[$periodStart, $periodEnd] = budget_parse_period($periodType, (string)$periodKey);

$flash = $_GET['success'] ?? '';

// Create budget
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_budget') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        redirect('index.php' . $qs(['error' => 'csrf']));
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $currency = budget_normalize_currency((string)($_POST['currency'] ?? 'TZS'));
    $pt = (string)($_POST['period_type'] ?? 'monthly');
    $sd = (string)($_POST['start_date'] ?? date('Y-m-01'));
    $ed = (string)($_POST['end_date'] ?? date('Y-m-d'));

    if ($name !== '' && in_array($pt, ['monthly', 'quarterly', 'yearly'], true)) {
        $st = $pdo->prepare('INSERT INTO budgets (name, period_type, start_date, end_date, currency, created_by) VALUES (?,?,?,?,?,?)');
        $st->execute([$name, $pt, $sd, $ed, $currency, (int)($_SESSION['user_id'] ?? 0) ?: null]);
        $newId = (int) $pdo->lastInsertId();
        if ($newId > 0) {
            $pk = budget_default_period_key($pt, $sd);
            budget_notify_new_budget_created($newId, $name, $currency, $pt, $pk, (int)($_SESSION['user_id'] ?? 0));
        }
        redirect('index.php' . $qs(['success' => 'created']));
    }
}

// Fetch budgets
$budgets = [];
$st = $pdo->query('SELECT * FROM budgets ORDER BY is_active DESC, start_date DESC, id DESC');
$budgets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$budget_currency_list = function_exists('budget_currency_options') ? budget_currency_options() : [];
if ($budget_currency_list === []) {
    $budget_currency_list = ['TZS' => 'TZS - Tanzanian Shilling'];
}

$page_title = 'Budgets';
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
    /* Ensure native dropdown options stay readable (some global styles hide option text) */
    select[name="currency"] option { color: #111827; background: #fff; }
</style>

<main class="main-content bud-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-bullseye text-[#2563EB]"></i><span>Budgets</span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="<?= htmlspecialchars(app_url('/select-module.php')) ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-sm bg-gray-50/80 border-b border-gray-100 text-gray-600">
                <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="module" value="finance">
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
            <?php if ($flash === 'created'): ?>
                <div class="alert alert-success border-0 shadow-sm">Budget created.</div>
            <?php endif; ?>

            <div class="dash-card mb-4">
                <div class="px-4 py-3 border-bottom border-gray-100 fw-bold text-gray-800">
                    <i class="fas fa-plus-circle text-[#2563EB] me-2"></i>Create budget structure
                </div>
                <div class="p-4">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="create_budget">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-secondary">Budget name</label>
                            <input name="name" class="form-control" placeholder="e.g. 2026 Operating Budget" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary">Period type</label>
                            <select name="period_type" class="form-select" required>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary">Currency</label>
                            <select name="currency" class="form-select" required>
                                <?php foreach ($budget_currency_list as $code => $label): ?>
                                    <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === 'TZS' ? 'selected' : '' ?>><?= htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1"></div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary">Start date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-01')) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold text-secondary">End date</label>
                            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary rounded-2 fw-semibold">
                                <i class="bi bi-save me-1"></i>Create budget
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dash-card">
                <div class="px-4 py-3 border-bottom border-gray-100 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-bold text-gray-800"><i class="fas fa-list text-[#2563EB] me-2"></i>Budgets</span>
                </div>
                <div class="dash-table-wrap">
                    <table class="table table-hover align-middle mb-0 dash-table">
                        <thead>
                            <tr>
                                <th class="ps-4">Name</th>
                                <th>Period type</th>
                                <th>Date range</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($budgets)): ?>
                            <tr><td colspan="5" class="py-4 text-center text-muted">No budgets yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($budgets as $b): ?>
                                <?php
                                $bid = (int) $b['id'];
                                $isActive = (int)($b['is_active'] ?? 1) === 1;
                                ?>
                                <tr>
                                    <td class="ps-4 fw-semibold"><?= htmlspecialchars($b['name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($b['period_type'] ?? '') ?></td>
                                    <td class="text-muted small"><?= htmlspecialchars($b['start_date'] ?? '') ?> ? <?= htmlspecialchars($b['end_date'] ?? '') ?></td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a class="btn btn-sm btn-outline-primary rounded-2" href="budget.php<?= htmlspecialchars($qs(['id' => $bid, 'period_type' => $periodType, 'period' => $periodKey])) ?>">
                                            <i class="bi bi-eye me-1"></i>Open
                                        </a>
                                        <a class="btn btn-sm btn-outline-secondary rounded-2" href="dashboard.php<?= htmlspecialchars($qs(['id' => $bid, 'period_type' => $periodType, 'period' => $periodKey])) ?>">
                                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                                        </a>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>

