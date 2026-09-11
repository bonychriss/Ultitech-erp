<?php
declare(strict_types=1);

/**
 * Cash Book JSON API bridge ? erp-laravel Domains/CashBook.
 *
 * Examples:
 *   GET  /modules/petty-cash/api/index.php?resource=init
 *   GET  /modules/petty-cash/api/index.php?resource=entries&book_id=1
 *   POST /modules/petty-cash/api/index.php?resource=entries
 *   POST /modules/petty-cash/api/index.php?resource=books&id=3&_method=DELETE
 */
require_once dirname(__DIR__, 3) . '/includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'petty_cash';
}
$_SESSION['active_module'] = 'petty_cash';

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
$dbName = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $dbName = '';
}

$GLOBALS['ERP_CASHBOOK_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => (int) ($_SESSION['company_id'] ?? 0),
    'company_slug' => $slug,
    'back_url' => '',
    'cashbook_url' => '',
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $dbName !== '' ? $dbName : (defined('DB_NAME') ? (string) DB_NAME : ''),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_CASHBOOK_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'petty_cash';

$resource = strtolower(trim((string) ($_GET['resource'] ?? 'init')));
$allowed = ['init', 'books', 'entries', 'categories', 'reports'];
if (!in_array($resource, $allowed, true)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown resource.']);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$route = '/api/cashbook/' . $resource;
if ($id > 0) {
    $route .= '/' . $id;
}

// Honour method override for hosts that block PUT/DELETE
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$override = strtoupper(trim((string) ($_POST['_method'] ?? $_GET['_method'] ?? '')));
if ($override !== '' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
    $_SERVER['REQUEST_METHOD'] = $override;
}

$GLOBALS['ERP_ROUTE'] = $route;
$GLOBALS['ERP_CASHBOOK_ROUTE'] = $route;

$laravelRoot = dirname(__DIR__, 3) . '/erp-laravel';
if (!is_file($laravelRoot . '/vendor/autoload.php')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'erp-laravel vendor is missing.']);
    exit;
}

require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
