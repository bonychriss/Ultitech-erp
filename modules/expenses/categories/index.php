<?php
require_once '../../../includes/functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'expenses';
}
$_SESSION['active_module'] = 'expenses';

global $pdo;
$success = '';
$error = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $success = 'Category created successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        die('CSRF token validation failed.');
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                throw new Exception('Category name is required.');
            }
            $dup = $pdo->prepare("SELECT id FROM erp_accounts WHERE type = 'expense' AND LOWER(name) = LOWER(?) AND id != ? LIMIT 1");
            $dup->execute([$name, $id]);
            if ($dup->fetchColumn()) {
                throw new Exception('A category with this name already exists.');
            }
            $stmt = $pdo->prepare("UPDATE erp_accounts SET name = ? WHERE id = ? AND type = 'expense'");
            $stmt->execute([$name, $id]);
            $success = 'Category updated successfully.';
        } elseif ($action === 'deactivate') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Invalid category.');
            }
            $stmt = $pdo->prepare("UPDATE erp_accounts SET status = 'inactive' WHERE id = ? AND type = 'expense'");
            $stmt->execute([$id]);
            $success = 'Category deactivated.';
        } elseif ($action === 'activate') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Invalid category.');
            }
            $stmt = $pdo->prepare("UPDATE erp_accounts SET status = 'active' WHERE id = ? AND type = 'expense'");
            $stmt->execute([$id]);
            $success = 'Category activated.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$showInactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';

$sql = "SELECT a.id, a.name, a.code, COALESCE(a.status, 'active') AS status,
               COUNT(e.id) AS expense_count
        FROM erp_accounts a
        LEFT JOIN erp_expenses e ON e.account_id = a.id
        WHERE a.type = 'expense'";
$params = [];

if (!$showInactive) {
    $sql .= " AND (a.status IS NULL OR a.status = 'active')";
}
if ($search !== '') {
    $sql .= " AND (a.name LIKE ? OR a.code LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$sql .= " GROUP BY a.id, a.name, a.code, a.status ORDER BY a.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCategories = count($categories);
$activeCount = 0;
foreach ($categories as $cat) {
    if (($cat['status'] ?? 'active') === 'active') {
        $activeCount++;
    }
}

$createUrl = 'create.php?module=expenses';
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$toggleInactiveUrl = $showInactive
    ? '?module=expenses' . ($search !== '' ? '&search=' . urlencode($search) : '')
    : '?module=expenses&show_inactive=1' . ($search !== '' ? '&search=' . urlencode($search) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Categories | Expenses Module</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; font-weight: 300; }
        .main-content { background: #f8fafc; color: #000; }
        .table-container { border-radius: 12px; overflow: hidden; background: #fff; }
        .category-row:hover { background-color: #f1f5f9; }
        .category-row { color: #000; }
        .font-light { font-weight: 300; }
        .font-normal { font-weight: 400; }
        .category-name-input {
            width: 100%;
            border: 1px solid transparent;
            background: transparent;
            font-size: 12px;
            font-weight: 400;
            color: #1d4ed8;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            padding: 2px 4px;
            border-radius: 6px;
            outline: none;
        }
        .category-name-input:hover { border-color: #e2e8f0; background: #fff; }
        .category-name-input:focus { border-color: #93c5fd; background: #fff; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15); }

        @media (max-width: 992px) {
            .px-8 { padding-left: 1rem !important; padding-right: 1rem !important; }
            .table-container { overflow-x: auto !important; border-radius: 8px; -webkit-overflow-scrolling: touch; }
            #categoriesTable { display: table !important; width: 100% !important; min-width: 720px !important; }
            #categoriesTable thead { display: table-header-group !important; }
            #categoriesTable tbody { display: table-row-group !important; }
            #categoriesTable tr { display: table-row !important; }
            #categoriesTable th, #categoriesTable td { display: table-cell !important; padding: 8px 10px !important; font-size: 11px !important; }
            .sticky { position: static !important; }
            html body .main-content,
            html body .content-wrapper,
            html body main,
            html body.dashboard .main-content,
            html body .header,
            html body .admin-header,
            html body .employee-header {
                margin-left: 0 !important;
                width: 100% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
        }
    </style>
</head>
<body>

<?php include '../../../includes/header_employee.php'; ?>

<main class="main-content">
    <div class="bg-gray-50 min-h-screen pb-12">

        <!-- Header -->
        <div class="px-8 py-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-light text-black">All categories</h1>
                <p class="text-xs text-gray-500 font-light mt-1">Manage ledger categories used when recording and posting expenses.</p>
            </div>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                <a href="<?= $esc($toggleInactiveUrl) ?>"
                   class="bg-white border border-gray-200 text-gray-700 px-6 py-2.5 rounded-full hover:bg-gray-50 transition-all font-normal shadow-sm flex items-center justify-center gap-2 text-sm">
                    <i class="fas fa-filter text-xs"></i>
                    <?= $showInactive ? 'Active only' : 'Show inactive' ?>
                </a>
                <a href="<?= $esc($createUrl) ?>"
                   class="bg-purple-500 text-white px-6 py-2.5 rounded-full hover:bg-purple-600 transition-all font-normal shadow-sm flex items-center justify-center gap-2">
                    <i class="fas fa-plus text-xs"></i> New Category
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="px-8 mb-6">
                <div class="bg-green-50 border border-green-200 rounded-2xl p-4 flex items-center gap-3 shadow-sm">
                    <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center text-green-600 shrink-0">
                        <i class="fas fa-check text-sm"></i>
                    </div>
                    <p class="text-sm text-green-800 font-normal"><?= $esc($success) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="px-8 mb-6">
                <div class="bg-red-50 border border-red-200 rounded-2xl p-4 flex items-center gap-3 shadow-sm">
                    <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center text-red-600 shrink-0">
                        <i class="fas fa-exclamation-triangle text-sm"></i>
                    </div>
                    <p class="text-sm text-red-800 font-normal"><?= $esc($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="bg-white border-y px-8 py-4 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 sticky top-0 z-50 shadow-sm">
            <div class="flex items-center gap-6">
                <span class="text-lg font-light text-black">All Category</span>
                <span class="text-xs text-gray-400 font-light"><?= (int) $totalCategories ?> total &middot; <?= (int) $activeCount ?> active</span>
            </div>

            <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                <div class="relative flex items-center w-full lg:w-auto">
                    <form method="GET" action="" class="flex w-full">
                        <input type="hidden" name="module" value="expenses">
                        <?php if ($showInactive): ?><input type="hidden" name="show_inactive" value="1"><?php endif; ?>
                        <input type="text"
                               name="search"
                               placeholder="Type & Enter"
                               value="<?= $esc($search) ?>"
                               class="w-full lg:w-64 px-4 py-2 border border-gray-200 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500 text-sm font-light">
                    </form>
                </div>
            </div>
        </div>

        <!-- Table Section -->
        <div class="px-8 mt-8">
            <div class="table-container shadow-sm border border-gray-100">
                <table class="w-full text-left border-collapse" id="categoriesTable">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-200">
                            <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider w-2/5">Category Details</th>
                            <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider text-center">Expenses</th>
                            <th class="px-6 py-3 text-[11px] font-normal text-black uppercase tracking-wider text-right pr-8">Options</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-gray-400 font-light text-sm">
                                    No categories found.
                                    <a href="<?= $esc($createUrl) ?>" class="text-blue-600 hover:underline ml-1">Create the first category</a>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <?php $isActive = ($cat['status'] ?? 'active') === 'active'; ?>
                                <tr class="category-row transition-colors group text-black font-light">
                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-4">
                                            <div class="w-12 h-12 rounded-lg border border-gray-200 flex-shrink-0 overflow-hidden bg-purple-50 flex items-center justify-center">
                                                <i class="fas fa-folder-open text-purple-400 text-sm"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <form method="POST" class="flex items-center gap-2" id="form-cat-<?= (int) $cat['id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                                    <input type="text"
                                                           name="name"
                                                           value="<?= $esc($cat['name']) ?>"
                                                           required
                                                           class="category-name-input"
                                                           title="Edit category name and click save">
                                                </form>
                                                <div class="text-[10px] text-black font-light mt-1">
                                                    CODE: <?= $esc($cat['code'] ?: '�') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <?php if ($isActive): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-medium uppercase tracking-wider bg-green-50 text-green-600 border border-green-100">Active</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-medium uppercase tracking-wider bg-gray-100 text-gray-500 border border-gray-200">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3 text-center">
                                        <span class="text-xl font-light text-black leading-none"><?= (int) $cat['expense_count'] ?></span>
                                    </td>
                                    <td class="px-6 py-3 text-right pr-8">
                                        <div class="flex items-center justify-end gap-3">
                                            <button type="submit"
                                                    form="form-cat-<?= (int) $cat['id'] ?>"
                                                    class="w-10 h-10 flex items-center justify-center rounded-full border border-blue-100 bg-blue-50 text-blue-500 hover:bg-blue-100 transition-all shadow-sm"
                                                    title="Save name">
                                                <i class="fas fa-save text-sm"></i>
                                            </button>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                                <?php if ($isActive): ?>
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit"
                                                            onclick="return confirm('Deactivate this category?')"
                                                            class="w-10 h-10 flex items-center justify-center rounded-full border border-red-100 bg-red-50 text-red-500 hover:bg-red-100 transition-all shadow-sm"
                                                            title="Deactivate">
                                                        <i class="fas fa-ban text-sm"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit"
                                                            class="w-10 h-10 flex items-center justify-center rounded-full border border-green-100 bg-green-50 text-green-500 hover:bg-green-100 transition-all shadow-sm"
                                                            title="Activate">
                                                        <i class="fas fa-check text-sm"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->

</body>
</html>
