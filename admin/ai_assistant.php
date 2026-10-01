<?php
declare(strict_types=1);

/**
 * AI Assistant chat. Laravel renders the shell; React draws the conversation.
 * URL: /{company}/admin/ai_assistant.php?module=petty_cash
 */
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();

if (function_exists('isAdmin') && !isAdmin()) {
    $mod = isset($_GET['module']) ? '?module=' . urlencode((string) $_GET['module']) : '';
    $employeeAi = company_url('employee/ai_assistant.php') . $mod;
    header('Location: ' . $employeeAi);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$activeModule = trim((string) ($_GET['module'] ?? ''));
if ($activeModule !== '') {
    $_SESSION['active_module'] = $activeModule;
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
$apiUrl = function_exists('company_url')
    ? company_url('modules/ai-agent/api.php')
    : app_url('/modules/ai-agent/api.php');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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

$GLOBALS['ERP_AI_ASSISTANT_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'csrf' => $csrf,
    'api_url' => $apiUrl,
    'module' => $activeModule,
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_AI_ASSISTANT_CONTEXT'];

$laravelRoot = dirname(__DIR__) . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/ai-assistant';
$GLOBALS['ERP_AI_ASSISTANT_ROUTE'] = '/ai-assistant';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
