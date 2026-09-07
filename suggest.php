<?php
declare(strict_types=1);

/**
 * ERP entry for Suggest — Laravel API + React UI (ERP sidebar).
 * URL: /{company}/suggest → suggest.php
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
$backUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : app_url('/select-module.php');

$dbName = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $pdo->query('SELECT 1 FROM developer_suggestions LIMIT 1');
    }
} catch (Throwable $e) {
    try {
        global $pdo;
        if ($pdo instanceof PDO) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS developer_suggestions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                suggestion TEXT NOT NULL,
                status ENUM('pending', 'accomplished', 'impossible') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $createEx) {
        error_log('suggest table ensure: ' . $createEx->getMessage());
    }
}

$suggestPublicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . (string) ($_SERVER['REQUEST_URI'] ?? '/suggest.php');
$suggestPublicUrl = strtok($suggestPublicUrl, '?') ?: $suggestPublicUrl;

$GLOBALS['ERP_SUGGEST_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'suggest_url' => $suggestPublicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : 'new_trading_voucher-35313030c7e2',
];

// JSON API → Laravel
$api = strtolower(trim((string) ($_GET['api'] ?? '')));
if ($api === 'suggestions' || $api === '1') {
    $GLOBALS['ERP_SUGGEST_ROUTE'] = '/api/suggestions';
    require __DIR__ . '/suggest-laravel/bootstrap/erp-bridge.php';
    exit;
}

// Optional Blade fallback: ?view=blade
if (strtolower(trim((string) ($_GET['view'] ?? ''))) === 'blade') {
    $GLOBALS['ERP_SUGGEST_ROUTE'] = '/suggest';
    require __DIR__ . '/suggest-laravel/bootstrap/erp-bridge.php';
    exit;
}

$_SESSION['active_module'] = 'suggestions';

$laravelEnv = __DIR__ . '/suggest-laravel/.env';
$laravelEnvExample = __DIR__ . '/suggest-laravel/.env.example';
if (!is_file($laravelEnv) && is_file($laravelEnvExample)) {
    @copy($laravelEnvExample, $laravelEnv);
}

require_once __DIR__ . '/suggest-laravel/ui-lib.php';

$apiUrl = $suggestPublicUrl . (str_contains($suggestPublicUrl, '?') ? '&' : '?') . 'api=suggestions';

suggestUiRenderReactShell([
    'apiUrl' => $apiUrl,
    'backUrl' => $backUrl,
    'userName' => (string) ($_SESSION['full_name'] ?? ''),
    'companySlug' => $slug,
]);
