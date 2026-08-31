<?php
require_once 'includes/functions.php';
require_once 'includes/revenue_account_helpers.php';
requireLogin();

if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}

$module = isset($_GET['module']) ? trim((string) $_GET['module']) : 'revenue';
$moduleQs = '?module=' . rawurlencode($module);

revenue_ensure_account_schema($pdo);
revenue_ensure_default_accounts($pdo);

$success = isset($_GET['success']) ? trim((string) $_GET['success']) : '';
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';

$categories = revenue_fetch_categories($pdo);
$categoryRows = [];
foreach ($categories as $cat) {
    $children = revenue_fetch_account_options($pdo, (int) $cat['id']);
    $categoryRows[] = [
        'id' => (int) $cat['id'],
        'code' => (string) ($cat['code'] ?? ''),
        'name' => (string) ($cat['name'] ?? ''),
        'child_count' => count($children),
        'posting_account' => !empty($children[0]['name']) ? (string) $children[0]['name'] : '�',
    ];
}

$backUrl = 'revenue_entries.php' . $moduleQs;
$createUrl = 'revenue_category_create.php' . $moduleQs;
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Sub Accounts | <?= $esc(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
        .main-content-wrapper { padding: 2rem; }
        .page-shell { padding-left: 4rem; }
        .editor-shell { max-width: 1140px; margin: 0 auto; }
        .editor-topbar {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
        }
        .btn-new {
            background: #7c3aed; color: #fff; padding: 0.65rem 1.25rem; border-radius: 10px;
            font-weight: 600; font-size: 14px; text-decoration: none; display: inline-flex;
            align-items: center; gap: 0.5rem;
        }
        .btn-new:hover { background: #6d28d9; color: #fff; }
        .cat-table {
            width: 100%; border-collapse: collapse; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;
        }
        .cat-table th, .cat-table td {
            padding: 0.85rem 1rem; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 14px;
        }
        .cat-table th {
            background: #f8fafc; font-size: 12px; text-transform: uppercase;
            letter-spacing: 0.04em; color: #64748b; font-weight: 600;
        }
        .cat-table tr:last-child td { border-bottom: none; }
        .code-pill {
            font-family: monospace; font-size: 12px; font-weight: 700;
            color: #2563eb; background: #eff6ff; padding: 2px 8px; border-radius: 6px;
        }
        @media (max-width: 992px) {
            .page-shell { padding-left: 0; }
            .cat-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>
<?php require_once 'includes/header_employee.php'; ?>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">Revenue Sub Accounts</h1>
                <p class="text-sm text-slate-400 mt-1 mb-0">Manage revenue sub accounts for entries</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="<?= $esc($createUrl) ?>" class="btn-new">
                    <i class="fas fa-plus"></i> New Sub Account
                </a>
                <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-arrow-left text-xs"></i> Back to Revenue
                </a>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700">
                <?= $esc($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                <?= $esc($error) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
            <table class="cat-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Category</th>
                        <th>Posting Account</th>
                        <th>Accounts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categoryRows)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-slate-500 py-8">
                                No categories yet.
                                <a href="<?= $esc($createUrl) ?>" class="text-purple-600 font-semibold ml-1">Create one</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categoryRows as $row): ?>
                            <tr>
                                <td><span class="code-pill"><?= $esc($row['code'] ?: '�') ?></span></td>
                                <td class="font-semibold text-slate-800"><?= $esc($row['name']) ?></td>
                                <td><?= $esc($row['posting_account']) ?></td>
                                <td><?= (int) $row['child_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
