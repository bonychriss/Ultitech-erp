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

storefrontApiRequireBearer();
