<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_settings.php';

requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

global $pdo;

$module = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'accounting';
$success = '';
$error = '';

$revenueOptions = accounting_settings_revenue_gl_options($pdo);
$currentGlId = accounting_get_default_sales_revenue_gl_account_id($pdo)
    ?: accounting_settings_discover_default_sales_revenue_gl_id($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = (int) ($_POST['default_sales_revenue_gl_account_id'] ?? 0);
    if ($selected <= 0) {
        $error = 'Please select a default sales revenue account.';
    } elseif (!accounting_set_default_sales_revenue_gl_account_id($pdo, $selected)) {
        $error = 'Could not save setting. The account must be a revenue GL account.';
    } else {
        $success = 'Accounting settings saved.';
        $currentGlId = $selected;
    }
}

$page_title = 'Accounting Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="<?= app_url('/assets/css/style.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background:#f8fafc; font-family:'Segoe UI',system-ui,sans-serif; margin:0; }
        .wrap { max-width:720px; margin:32px auto; padding:0 16px 40px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; box-shadow:0 8px 24px rgba(15,23,42,.05); }
        h1 { margin:0 0 8px; font-size:28px; color:#0f172a; }
        .sub { color:#64748b; margin:0 0 24px; line-height:1.5; }
        label { display:block; font-weight:600; margin-bottom:8px; color:#334155; }
        select { width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:15px; }
        .help { margin-top:8px; color:#64748b; font-size:13px; line-height:1.45; }
        .actions { margin-top:24px; display:flex; gap:12px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border-radius:8px; text-decoration:none; border:none; cursor:pointer; font-size:14px; font-weight:600; }
        .btn-primary { background:#004560; color:#fff; }
        .btn-secondary { background:#fff; color:#334155; border:1px solid #cbd5e1; }
        .alert { padding:12px 14px; border-radius:8px; margin-bottom:16px; }
        .alert-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .back { margin-bottom:16px; display:inline-block; color:#004560; text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
<div class="wrap">
    <a class="back" href="<?= app_url('/modules/accounting/index.php?module=' . urlencode($module)) ?>">&larr; Back to Accounting</a>
    <div class="card">
        <h1>Accounting Settings</h1>
        <p class="sub">Configure default general ledger accounts used when invoices and sales are posted. Change these when your organization uses different revenue accounts (e.g. Domestic Sales, Export Sales).</p>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="default_sales_revenue_gl_account_id">Default Sales Revenue Account</label>
            <select name="default_sales_revenue_gl_account_id" id="default_sales_revenue_gl_account_id" required>
                <?php if ($revenueOptions === []): ?>
                    <option value="">No revenue GL accounts found � create accounts under Balances first</option>
                <?php else: ?>
                    <?php foreach ($revenueOptions as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"<?= (int) $opt['id'] === (int) $currentGlId ? ' selected' : '' ?>>
                            <?= htmlspecialchars($opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <p class="help">
                When an invoice is recognized in the general ledger, the system credits this revenue account (and debits Accounts Receivable).
                Recommended default: <strong>4001 - Sales Revenue</strong> under the <strong>4000 - Revenue</strong> chart group.
            </p>
            <div class="actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save settings</button>
                <a class="btn btn-secondary" href="<?= app_url('/modules/balances/accounts.php?module=balances') ?>">Manage chart of accounts</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
