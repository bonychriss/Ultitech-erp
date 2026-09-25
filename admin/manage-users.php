<?php
declare(strict_types=1);

/**
 * ERP entry for User Management — erp-laravel Domains/Admin + React UI.
 * URL: /{company}/admin/manage-users.php?module=voucher
 */
require_once __DIR__ . '/../includes/functions.php';

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/manage-users-ui/lib.php';
    manageUsersUiRequireAdmin();
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>User management error</title></head><body style="font-family:system-ui,sans-serif;padding:2rem;max-width:720px">';
    echo '<h1>User management could not load</h1>';
    echo '<p style="color:#b91c1c;">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

// Handle mutations in legacy PHP, then redirect to GET (avoids Laravel 419 CSRF).
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $state = manageUsersUi_collect_state();
    if (!empty($state['success'])) {
        $_SESSION['manage_users_flash_success'] = (string) $state['success'];
    }
    if (!empty($state['error'])) {
        $_SESSION['manage_users_flash_error'] = (string) $state['error'];
    }
    if (!empty($state['pwFlash']) && is_array($state['pwFlash'])) {
        $_SESSION['manage_users_pw_flash'] = $state['pwFlash'];
    }
    if (!empty($state['bulkPwFlash']) && is_array($state['bulkPwFlash'])) {
        $_SESSION['manage_users_bulk_pw_flash'] = $state['bulkPwFlash'];
    }
    $qs = [];
    if (!empty($_GET['module'])) {
        $qs['module'] = (string) $_GET['module'];
    }
    if (!empty($_GET['company_slug'])) {
        $qs['company_slug'] = (string) $_GET['company_slug'];
    } elseif (function_exists('getRequestedCompanySlug')) {
        $slugRedirect = trim((string) getRequestedCompanySlug());
        if ($slugRedirect !== '') {
            $qs['company_slug'] = $slugRedirect;
        }
    }
    $target = 'manage-users.php';
    if ($qs !== []) {
        $target .= '?' . http_build_query($qs);
    }
    header('Location: ' . $target);
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}

$dbName = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$GLOBALS['ERP_ADMIN_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => '',
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'desk' => 'manage-users',
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_ADMIN_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = (string) ($_GET['module'] ?? 'voucher');

$laravelRoot = dirname(__DIR__) . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';

$laravelEnv = $laravelRoot . '/.env';
$laravelEnvExample = $laravelRoot . '/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/admin/desk/manage-users';
$GLOBALS['ERP_ADMIN_ROUTE'] = $GLOBALS['ERP_ROUTE'];
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
