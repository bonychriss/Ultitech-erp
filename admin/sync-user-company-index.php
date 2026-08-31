<?php
/**
 * Super-admin tool: rebuild user_company_index (auto-runs when index is empty).
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$syncPageError = '';
$syncPageTrace = '';

set_exception_handler(function (Throwable $e) use (&$syncPageError, &$syncPageTrace) {
    $syncPageError = $e->getMessage();
    $syncPageTrace = $e->getFile() . ':' . $e->getLine();
    http_response_code(500);
});

try {
    require_once __DIR__ . '/../includes/functions.php';
} catch (Throwable $e) {
    $syncPageError = 'Bootstrap failed: ' . $e->getMessage();
    $syncPageTrace = $e->getFile() . ':' . $e->getLine();
}

if ($syncPageError === '' && !function_exists('userCompanyIndexTableReady')) {
    $syncPageError = 'Login index functions are missing. Upload the latest includes/functions.php to the server.';
}

if ($syncPageError === '') {
    try {
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            // Not logged in: still try automatic bootstrap sync (no auth required for empty index).
            if (function_exists('maybeAutoSyncUserCompanyIndex') && userCompanyIndexTableReady() && userCompanyIndexIsEmpty()) {
                maybeAutoSyncUserCompanyIndex(false);
                if (!userCompanyIndexIsEmpty()) {
                    header('Location: ' . (function_exists('app_url') ? app_url('/login.php') : '/login.php'));
                    exit;
                }
            }
            $loginUrl = function_exists('app_url') ? app_url('/login.php?next=admin/sync-user-company-index.php') : '/login.php';
            header('Location: ' . $loginUrl);
            exit;
        }
        if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
            http_response_code(403);
            die('Access denied. Log in as super admin (e.g. admin@ultimatetrading.com) then open this page again.');
        }
    } catch (Throwable $e) {
        $syncPageError = $e->getMessage();
        $syncPageTrace = $e->getFile() . ':' . $e->getLine();
    }
}

$summary = null;
$ran = false;
$autoRan = false;
$indexCount = 0;
$schemaOk = false;
$forceSync = isset($_GET['force']) && (string) $_GET['force'] === '1';

if ($syncPageError === '') {
    try {
        if (function_exists('ensureMultiCompanyControlSchema')) {
            $schemaOk = (bool) ensureMultiCompanyControlSchema();
        }

        if (function_exists('userCompanyIndexTableReady') && userCompanyIndexTableReady()) {
            global $control_pdo, $pdo;
            $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
            if ($usePdo instanceof PDO) {
                $indexCount = (int) $usePdo->query('SELECT COUNT(*) FROM user_company_index')->fetchColumn();
            }
        }

        $isPostForce = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_sync']));
        $shouldRunSync = $isPostForce || $forceSync || $indexCount === 0;

        if ($shouldRunSync && function_exists('syncAllTenantUsersToIndex')) {
            $ran = true;
            $autoRan = !$isPostForce && !$forceSync && $indexCount === 0;
            if ($isPostForce || $forceSync) {
                $summary = function_exists('maybeAutoSyncUserCompanyIndex')
                    ? maybeAutoSyncUserCompanyIndex(true)
                    : syncAllTenantUsersToIndex();
                if ($summary === null) {
                    $summary = syncAllTenantUsersToIndex();
                }
            } else {
                $summary = function_exists('maybeAutoSyncUserCompanyIndex')
                    ? maybeAutoSyncUserCompanyIndex(false)
                    : syncAllTenantUsersToIndex();
            }
            if (userCompanyIndexTableReady()) {
                global $control_pdo, $pdo;
                $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
                if ($usePdo instanceof PDO) {
                    $indexCount = (int) $usePdo->query('SELECT COUNT(*) FROM user_company_index')->fetchColumn();
                }
            }
        }
    } catch (Throwable $e) {
        $syncPageError = $e->getMessage();
        $syncPageTrace = $e->getFile() . ':' . $e->getLine();
    }
}

$esc = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

header('Content-Type: text/html; charset=UTF-8');
http_response_code($syncPageError !== '' ? 500 : 200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sync User Company Index</title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; margin: 24px; background: #f8fafc; color: #111827; }
        .card { max-width: 720px; background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { border: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
        th { background: #f3f4f6; }
        .btn { display: inline-block; padding: 10px 18px; background: #6366f1; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; }
        .muted { color: #6b7280; font-size: 14px; }
        .ok { color: #166534; }
        .info { background: #eff6ff; color: #1e40af; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .err { color: #991b1b; background: #fee2e2; padding: 12px; border-radius: 8px; }
        pre { font-size: 12px; overflow: auto; background: #f3f4f6; padding: 10px; border-radius: 6px; }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Sync User Company Index</h1>

    <?php if ($syncPageError !== ''): ?>
        <div class="err">
            <strong>Error</strong><br><?= $esc($syncPageError) ?>
            <?php if ($syncPageTrace !== ''): ?>
                <pre><?= $esc($syncPageTrace) ?></pre>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="info">
            <strong>Automatic sync is enabled.</strong> The index rebuilds itself when empty (on login, app load, or this page).
            You do not need to click the button unless forcing a full refresh.
        </p>
        <p class="muted">Schema: <strong><?= $schemaOk ? 'OK' : 'check logs' ?></strong> &nbsp;|&nbsp; Index rows: <strong><?= (int) $indexCount ?></strong></p>

        <?php if ($ran && is_array($summary)): ?>
            <h2 class="ok"><?= $autoRan ? 'Auto-sync complete' : 'Sync complete' ?></h2>
            <p>Total synced: <strong><?= (int) ($summary['total_synced'] ?? 0) ?></strong> &nbsp;|&nbsp; Errors: <strong><?= (int) ($summary['total_errors'] ?? 0) ?></strong></p>
            <table>
                <thead><tr><th>Company</th><th>Synced</th><th>Errors</th></tr></thead>
                <tbody>
                <?php foreach (($summary['companies'] ?? []) as $slug => $row): ?>
                    <tr>
                        <td><?= $esc((string) ($row['label'] ?? $slug)) ?></td>
                        <td><?= (int) ($row['synced'] ?? 0) ?></td>
                        <td><?= (int) ($row['errors'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($summary['errors'])): ?>
                <h3 class="err">Error details</h3>
                <ul>
                    <?php foreach ($summary['errors'] as $err): ?>
                        <li><?= $esc((string) $err) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php elseif ($indexCount > 0): ?>
            <p class="ok">Index already populated (<?= (int) $indexCount ?> users). No sync needed.</p>
        <?php endif; ?>

        <?php if (!userCompanyIndexTableReady()): ?>
            <p class="err">Table <code>user_company_index</code> is missing on the control database. Create it in phpMyAdmin first.</p>
        <?php else: ?>
            <form method="post" style="margin-top:16px;" onsubmit="return confirm('Force full rebuild of user_company_index?');">
                <input type="hidden" name="confirm_sync" value="1">
                <button type="submit" class="btn">Force full sync</button>
            </form>
            <a href="?force=1" class="btn" style="margin-left:8px;background:#0f766e;">Force sync (GET)</a>
        <?php endif; ?>

        <p class="muted" style="margin-top:20px;">
            <a href="<?= $esc(function_exists('app_url') ? app_url('/admin/list-company-users.php') : '/admin/list-company-users.php') ?>">View company users</a>
            &nbsp;|&nbsp;
            <a href="<?= $esc(function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php') ?>">Modules</a>
        </p>
    <?php endif; ?>
</div>
</body>
</html>
