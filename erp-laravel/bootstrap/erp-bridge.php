<?php

declare(strict_types=1);

/**
 * Boot Laravel for ERP (HTML page or JSON API).
 * Expects $GLOBALS['ERP_CONTEXT'] / ERP_SALES_CONTEXT / ERP_SUGGEST_CONTEXT / ERP_STOCK_CONTEXT
 * and optional $GLOBALS['ERP_ROUTE'] (or ERP_SALES_ROUTE / ERP_SUGGEST_ROUTE / ERP_STOCK_ROUTE).
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$laravelRoot = dirname(__DIR__);

$autoload = $laravelRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'erp-laravel vendor is missing. Run composer install in erp-laravel/.',
    ]);
    exit;
}

require $autoload;

/** @var \Illuminate\Foundation\Application $app */
$app = require $laravelRoot . '/bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$route = (string) (
    $GLOBALS['ERP_ROUTE']
    ?? $GLOBALS['ERP_HOME_ROUTE']
    ?? $GLOBALS['ERP_TRIAL_ROUTE']
    ?? $GLOBALS['ERP_SALES_ROUTE']
    ?? $GLOBALS['ERP_SUGGEST_ROUTE']
    ?? $GLOBALS['ERP_STOCK_ROUTE']
    ?? '/api/dashboard'
);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$query = [];
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $query);

$input = $method === 'POST' ? $_POST : $query;
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }
}

$request = Request::create(
    $route,
    $method,
    $input,
    $_COOKIE,
    $_FILES,
    $_SERVER
);

$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
