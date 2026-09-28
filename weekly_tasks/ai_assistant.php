<?php
declare(strict_types=1);

/**
 * Weekly tasks dashboard — erp-laravel Domains/WeeklyTasks + React UI.
 * URL: /weekly_tasks/ai_assistant.php or /{company}/weekly_tasks/ai_assistant.php
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'tasks';
}
$_SESSION['active_module'] = 'tasks';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
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

$GLOBALS['ERP_WEEKLY_TASKS_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
    'module' => 'tasks',
    'week' => isset($_GET['week']) ? (int) $_GET['week'] : (isset($_GET['week_offset']) ? (int) $_GET['week_offset'] : 0),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_WEEKLY_TASKS_CONTEXT'];

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

$GLOBALS['ERP_ROUTE'] = '/weekly-tasks/dashboard';
$GLOBALS['ERP_WEEKLY_TASKS_ROUTE'] = '/weekly-tasks/dashboard';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
