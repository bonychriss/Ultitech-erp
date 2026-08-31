<?php
/**
 * Bootstrap for modules/balances (staff/modules/balances/config/).
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../functions.php';
} catch (Throwable $e) {
    if (function_exists('balances_render_error_page')) {
        balances_render_error_page(
            'Could not start the Balances module. ' . $e->getMessage(),
            [
                'title' => 'Page unavailable',
                'headline' => 'Oops! Page unavailable',
                'home_url' => 'accounts.php',
                'retry_url' => '#back',
                'error_code' => '500',
                'log_context' => 'balances bootstrap',
            ]
        );
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<h1>Balances bootstrap failed</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

$balancesPdo = function_exists('balancesSyncGlobalPdo')
    ? balancesSyncGlobalPdo()
    : (function_exists('balances_resolve_pdo') ? balances_resolve_pdo() : null);
if (!($balancesPdo instanceof PDO)) {
    global $pdo, $control_pdo;
    $balancesPdo = ($pdo instanceof PDO) ? $pdo : $control_pdo;
}
if (!($balancesPdo instanceof PDO)) {
    if (function_exists('balances_render_error_page')) {
        balances_render_error_page(
            'Could not connect to the database for the Balances module.',
            [
                'title' => 'Page unavailable',
                'headline' => 'Oops! Page unavailable',
                'home_url' => 'accounts.php',
                'retry_url' => '#back',
                'error_code' => '500',
                'log_context' => 'balances database',
            ]
        );
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<h1>Database connection failed</h1><p>Could not connect for the Balances module.</p>';
    exit;
}

$pdo = $balancesPdo;
$GLOBALS['pdo'] = $balancesPdo;

try {
    ensureBalancesSchema();
    if (function_exists('ensureCoaReferenceSchema')) {
        ensureCoaReferenceSchema($balancesPdo);
    }
    if (function_exists('ensureFinancialAccountCategoriesSchema')) {
        ensureFinancialAccountCategoriesSchema($balancesPdo);
    }
} catch (Throwable $e) {
    error_log('balances database bootstrap schema: ' . $e->getMessage());
}
