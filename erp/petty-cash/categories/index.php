<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

global $pdo;
$can_manage = pettyCashCanManage();
$success = '';
$error = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $success = 'Category created successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $result = updatePettyCashCategory($id, $name);
            if ($result === true) {
                $success = 'Category updated successfully.';
            } else {
                throw new Exception(is_string($result) ? $result : 'Update failed.');
            }
        } elseif ($action === 'deactivate') {
            $result = setPettyCashCategoryStatus((int) ($_POST['id'] ?? 0), 'inactive');
            if ($result === true) {
                $success = 'Category deactivated.';
            } else {
                throw new Exception(is_string($result) ? $result : 'Deactivate failed.');
            }
        } elseif ($action === 'activate') {
            $result = setPettyCashCategoryStatus((int) ($_POST['id'] ?? 0), 'active');
            if ($result === true) {
                $success = 'Category activated.';
            } else {
                throw new Exception(is_string($result) ? $result : 'Activate failed.');
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$can_manage) {
    $error = 'Only Admin or Finance can manage categories.';
}

$search = trim((string) ($_GET['search'] ?? ''));
$showInactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';
$categories = getAllPettyCashCategories([
    'search' => $search,
    'show_inactive' => $showInactive,
]);

$createUrl = 'create.php?module=petty_cash';
$backUrl = '../index.php?module=petty_cash';
$toggleInactiveUrl = $showInactive
    ? '?module=petty_cash' . ($search !== '' ? '&search=' . urlencode($search) : '')
    : '?module=petty_cash&show_inactive=1' . ($search !== '' ? '&search=' . urlencode($search) : '');
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$statusBadge = static function (string $status): string {
    return strtolower($status) === 'active' ? 'badge-pc-approved' : 'badge-pc-cancelled';
};

$page_title = 'Petty Cash Categories';
include __DIR__ . '/../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    .pc-shell { font-family: 'Outfit', system-ui, sans-serif; padding: 1rem; }
    .dash-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; overflow: hidden; }
    .dash-table thead tr th { background: #1c2331; color: #fff; font-size: .75rem; text-transform: uppercase; }
    .badge-pc-approved { background: #dcfce7; color: #15803d; padding: .25rem .5rem; border-radius: 999px; font-size: 11px; }
    .badge-pc-cancelled { background: #f3f4f6; color: #6b7280; padding: .25rem .5rem; border-radius: 999px; font-size: 11px; }
</style>

<main class="main-content pc-shell">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="fas fa-folder text-primary me-2"></i>Petty Cash Categories</h1>
        <div class="ms-auto d-flex flex-wrap gap-2">
            <a href="<?= $esc($backUrl) ?>" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            <?php if ($can_manage): ?>
                <a href="<?= $esc($createUrl) ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>New Category</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?= $esc($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $esc($error) ?></div><?php endif; ?>

    <div class="dash-card p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="module" value="petty_cash">
            <?php if ($showInactive): ?><input type="hidden" name="show_inactive" value="1"><?php endif; ?>
            <div class="col-md-4">
                <label class="form-label small mb-1">Search</label>
                <input type="search" name="search" value="<?= $esc($search) ?>" class="form-control form-control-sm" placeholder="Name or code">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?= $esc($toggleInactiveUrl) ?>" class="btn btn-outline-secondary btn-sm"><?= $showInactive ? 'Active only' : 'Show inactive' ?></a>
            </div>
        </form>
    </div>

    <div class="dash-card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 dash-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th class="text-end">Vouchers</th>
                        <?php if ($can_manage): ?><th class="text-end">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="<?= $can_manage ? 5 : 4 ?>" class="text-center text-muted py-4">No categories found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <?php $st = strtolower((string) $cat['status']); ?>
                            <tr>
                                <td>
                                    <?php if ($can_manage): ?>
                                        <form method="POST" class="d-flex gap-2">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                            <input type="text" name="name" value="<?= $esc($cat['name']) ?>" class="form-control form-control-sm" required>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>
                                    <?php else: ?>
                                        <?= $esc($cat['name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $esc($cat['code'] ?: '�') ?></td>
                                <td><span class="<?= $statusBadge($st) ?>"><?= ucfirst($st) ?></span></td>
                                <td class="text-end"><?= (int) ($cat['voucher_count'] ?? 0) ?></td>
                                <?php if ($can_manage): ?>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $st === 'active' ? 'deactivate' : 'activate' ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <?= $st === 'active' ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
