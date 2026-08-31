<?php
/**
 * One-time company admin password reset (Roadmaster / Ultimate tenants).
 * DELETE this file immediately after use.
 *
 * https://ultitech.io/reset_company_admin.php?key=ultitech-debug&company_slug=roadmaster
 */
declare(strict_types=1);

@ini_set('display_errors', '1');
error_reporting(E_ALL);

const RCA_KEY = 'ultitech-debug';
const RCA_VERSION = '1.1';

$rcaKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$rcaExpected = RCA_KEY;
foreach ([__DIR__ . '/env.php', __DIR__ . '/includes/env.php'] as $ep) {
    if (!is_file($ep)) {
        continue;
    }
    $DEBUG_KEY = $DEBUG_KEY ?? null;
    include $ep;
    if (isset($DEBUG_KEY) && trim((string) $DEBUG_KEY) !== '') {
        $rcaExpected = trim((string) $DEBUG_KEY);
        break;
    }
}

if (PHP_SAPI !== 'cli' && ($rcaKey === '' || !hash_equals($rcaExpected, $rcaKey))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden. Use ?key=" . RCA_KEY . "\n";
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$companySlug = isset($_REQUEST['company_slug'])
    ? strtolower(trim((string) $_REQUEST['company_slug']))
    : 'roadmaster';
if ($companySlug === '') {
    $companySlug = 'roadmaster';
}

function rca_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function rca_generate_password(int $length = 18): string
{
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%&*+-';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

$message = '';
$error = '';
$result = null;

$company = function_exists('findCompanyBySlug') ? findCompanyBySlug($companySlug) : null;
if (!$company) {
    $error = 'Company not found for slug: ' . $companySlug;
}

$tenantPdo = null;
$adminUser = null;
if (!$error && $company) {
    $tenantDb = trim((string) ($company['db_name'] ?? ''));
    $tenantHost = trim((string) ($company['db_host'] ?? ''));
    $tenantUser = trim((string) ($company['db_user'] ?? ''));
    $tenantPass = null;
    if (array_key_exists('db_pass', (array) $company)) {
        $raw = (string) ($company['db_pass'] ?? '');
        $tenantPass = trim($raw) !== '' ? $raw : null;
    }
    if ($tenantDb === '' && $companySlug === 'roadmaster' && defined('ROADMASTER_DB_NAME')) {
        $tenantDb = trim((string) ROADMASTER_DB_NAME);
    }
    if ($tenantHost === '' && $companySlug === 'roadmaster' && defined('ROADMASTER_DB_HOST')) {
        $tenantHost = trim((string) ROADMASTER_DB_HOST);
    }
    if ($tenantDb !== '' && function_exists('resolveEffectiveTenantDbConnection')) {
        $eff = resolveEffectiveTenantDbConnection($tenantDb, $tenantHost, $tenantUser, $tenantPass);
        $tenantDb = $eff['db_name'];
        $tenantHost = $eff['host'];
        $tenantUser = $eff['user'];
        $tenantPass = $eff['pass'];
    }
    if ($tenantDb !== '' && function_exists('connectToTenantDatabase')) {
        $tenantPdo = connectToTenantDatabase($tenantDb, $tenantHost, $tenantUser, $tenantPass);
    }
    if (!($tenantPdo instanceof PDO)) {
        $error = 'Could not connect to tenant database: ' . $tenantDb;
    } else {
        try {
            $st = $tenantPdo->query("
                SELECT id, username, email, full_name, role, is_active
                FROM users
                WHERE LOWER(TRIM(role)) IN ('admin', 'company_admin', 'superadmin', 'owner')
                ORDER BY id ASC
                LIMIT 1
            ");
            $adminUser = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$adminUser) {
                $st2 = $tenantPdo->prepare("SELECT id, username, email, full_name, role, is_active FROM users WHERE username = 'admin' LIMIT 1");
                $st2->execute();
                $adminUser = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$adminUser) {
                $error = 'No admin user found in tenant DB.';
            }
        } catch (Throwable $e) {
            $error = 'Tenant user lookup failed: ' . $e->getMessage();
        }
    }
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === 'RESET') {
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    if ($newPassword === '') {
        $newPassword = rca_generate_password(18);
    } elseif (strlen($newPassword) < 10) {
        $error = 'Password must be at least 10 characters (or leave blank to auto-generate).';
    }

    if (!$error && $tenantPdo instanceof PDO && is_array($adminUser)) {
        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $uid = (int) ($adminUser['id'] ?? 0);
            $setParts = array('password = ?');
            $params = array($hash);
            if (function_exists('columnExists') && columnExists('users', 'is_active', $tenantPdo)) {
                $setParts[] = 'is_active = 1';
            }
            if (function_exists('columnExists') && columnExists('users', 'status', $tenantPdo)) {
                $setParts[] = "status = 'active'";
            }
            if (function_exists('columnExists') && columnExists('users', 'approval_status', $tenantPdo)) {
                $setParts[] = "approval_status = 'approved'";
            }
            if (function_exists('columnExists') && columnExists('users', 'updated_at', $tenantPdo)) {
                $setParts[] = 'updated_at = NOW()';
            }
            $params[] = $uid;
            $tenantPdo->prepare('UPDATE users SET ' . implode(', ', $setParts) . ' WHERE id = ?')
                ->execute($params);

            $loginEmail = trim((string) ($adminUser['email'] ?? ''));
            if ($loginEmail === '' || stripos($loginEmail, '@system.local') !== false) {
                $loginEmail = trim((string) ($_POST['login_email'] ?? ''));
            }
            if ($loginEmail !== '' && filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
                $tenantPdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$loginEmail, $uid]);
                $adminUser['email'] = $loginEmail;
            }

            $indexSynced = false;
            if (function_exists('syncUserCompanyIndex')) {
                $indexSynced = syncUserCompanyIndex((int) ($company['id'] ?? 0), $uid);
            }

            $verifyOk = false;
            if (function_exists('authenticate')) {
                $tryUser = trim((string) ($adminUser['email'] ?? ''));
                if ($tryUser === '') {
                    $tryUser = trim((string) ($adminUser['username'] ?? 'admin'));
                }
                $verifyOk = authenticate($tryUser, $newPassword, $companySlug);
            }

            $result = array(
                'company' => (string) ($company['company_name'] ?? $companySlug),
                'slug' => $companySlug,
                'user_id' => $uid,
                'username' => (string) ($adminUser['username'] ?? ''),
                'email' => (string) ($adminUser['email'] ?? ''),
                'full_name' => (string) ($adminUser['full_name'] ?? ''),
                'password' => $newPassword,
                'index_synced' => $indexSynced,
                'auth_test' => $verifyOk,
                'login_url' => function_exists('company_login_url') ? company_login_url($companySlug) : ('/' . $companySlug . '/login'),
            );
            $message = 'Password updated successfully.';
        } catch (Throwable $e) {
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset company admin password</title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; margin: 24px; background: #f8fafc; color: #0f172a; }
        .card { max-width: 640px; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .ok { background: #dcfce7; color: #166534; padding: 12px; border-radius: 8px; margin: 12px 0; }
        .err { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin: 12px 0; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; padding: 12px; border-radius: 8px; margin: 12px 0; }
        label { display: block; margin: 10px 0 4px; font-weight: 600; }
        input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; }
        button { margin-top: 14px; background: #2563eb; color: #fff; border: 0; padding: 10px 16px; border-radius: 6px; cursor: pointer; }
        code, pre { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; }
        pre { padding: 12px; overflow: auto; }
    </style>
</head>
<body>
<div class="card">
    <h1>Reset company admin password</h1>
    <p>Version <?= rca_h(RCA_VERSION) ?> ù <strong>delete <code>reset_company_admin.php</code> after use.</strong></p>

    <?php if ($error): ?>
        <div class="err"><?= rca_h($error) ?></div>
    <?php endif; ?>

    <?php if ($message && is_array($result)): ?>
        <div class="ok"><?= rca_h($message) ?></div>
        <div class="warn">
            <p><strong>Save these credentials now</strong> (shown once):</p>
            <pre><?= rca_h(
                "Company: {$result['company']}\n"
                . "Login URL: {$result['login_url']}\n"
                . "Sign in with (email field): {$result['email']}\n"
                . "Username: {$result['username']}\n"
                . "New password: {$result['password']}\n"
                . "Auth test: " . ($result['auth_test'] ? 'OK' : 'FAILED ù try username instead of email')
                . "\nIndex synced: " . ($result['index_synced'] ? 'yes' : 'no')
            ) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (!$error && is_array($adminUser) && !is_array($result)): ?>
        <div class="warn">
            Target: <strong><?= rca_h((string) ($company['company_name'] ?? $companySlug)) ?></strong>
            (slug <code><?= rca_h($companySlug) ?></code>)
        </div>
        <p>Current admin user:</p>
        <ul>
            <li>ID: <?= (int) ($adminUser['id'] ?? 0) ?></li>
            <li>Username: <code><?= rca_h((string) ($adminUser['username'] ?? '')) ?></code></li>
            <li>Email: <code><?= rca_h((string) ($adminUser['email'] ?? '')) ?></code></li>
            <li>Role: <?= rca_h((string) ($adminUser['role'] ?? '')) ?></li>
        </ul>
        <form method="post">
            <input type="hidden" name="company_slug" value="<?= rca_h($companySlug) ?>">
            <input type="hidden" name="confirm" value="RESET">
            <label for="login_email">Login email (optional ù use if current is @system.local)</label>
            <input type="email" id="login_email" name="login_email" placeholder="e.g. admin@roadmaster.co.tz" value="">
            <label for="new_password">New password (leave blank to auto-generate a unique 18-char password)</label>
            <input type="text" id="new_password" name="new_password" autocomplete="new-password" value="">
            <button type="submit">Generate / set unique admin password</button>
        </form>
    <?php endif; ?>

    <p style="margin-top:16px;font-size:13px;color:#64748b">
        Other company: add <code>&amp;company_slug=ultimate</code> to the URL.
    </p>
</div>
</body>
</html>
