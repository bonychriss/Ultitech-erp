<?php
declare(strict_types=1);

/**
 * AI Agent page. Laravel renders the shell; React draws receivables and the briefing.
 * URL: /{company}/modules/ai-agent/index.php#ai-receivables
 */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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

$GLOBALS['ERP_AI_AGENT_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => $backUrl,
    'csrf' => $csrf,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_AI_AGENT_CONTEXT'];

$laravelRoot = dirname(__DIR__, 2) . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
if (!is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>erp-laravel required</h1>';
    echo '<p>Run <code>composer install</code> in <code>erp-laravel/</code>.</p>';
    echo '</body></html>';
    exit;
}

$GLOBALS['ERP_ROUTE'] = '/ai-agent';
$GLOBALS['ERP_AI_AGENT_ROUTE'] = '/ai-agent';
require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
