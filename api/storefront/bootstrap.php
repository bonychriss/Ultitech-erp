<?php

declare(strict_types=1);

/**
 * Storefront sync API bootstrap — Roadmaster tenant PDO + bearer auth.
 */

$publicHtmlRoot = dirname(__DIR__, 2);
if (!is_file($publicHtmlRoot . '/includes/config.php')) {
    $publicHtmlRoot = dirname(__DIR__, 1);
}

$_GET['company_slug'] = $_GET['company_slug'] ?? 'roadmaster';
if (empty($_SESSION['company_slug'])) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION['company_slug'] = 'roadmaster';
    $_SESSION['company_id'] = 2;
}

require_once $publicHtmlRoot . '/includes/config.php';
require_once $publicHtmlRoot . '/includes/functions.php';

// Stock image helpers (same resolver as /roadmaster/stock/products)
$stockFn = $publicHtmlRoot . '/stock/config/functions.php';
if (is_file($stockFn)) {
    require_once $stockFn;
}
$salesFn = $publicHtmlRoot . '/modules/sales/functions.php';
if (is_file($salesFn)) {
    require_once $salesFn;
}

if (!defined('STOREFRONT_API_ROOT')) {
    define('STOREFRONT_API_ROOT', __DIR__);
}

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key, X-Idempotency-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Discard HTML/debug noise printed while bootstrapping UltiTech config.
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
register_shutdown_function(static function (): void {
    $buffer = ob_get_contents();
    if ($buffer === false) {
        return;
    }
    // Keep only the last JSON object/array if debug text leaked before it.
    $trim = trim($buffer);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        return;
    }
    if (preg_match('/(\{.*\}|\[.*\])\s*$/s', $buffer, $m)) {
        ob_clean();
        echo $m[1];
    }
});

storefrontApiRequireBearer();
// Clear any boot chatter before endpoint body runs.
ob_clean();
