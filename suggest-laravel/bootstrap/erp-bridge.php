<?php

declare(strict_types=1);

/**
 * Boot Laravel for Suggest (page bridge or JSON API).
 * Expects $GLOBALS['ERP_SUGGEST_CONTEXT'] and optional $GLOBALS['ERP_SUGGEST_ROUTE'].
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$laravelRoot = dirname(__DIR__);

require $laravelRoot . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $laravelRoot . '/bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$route = (string) ($GLOBALS['ERP_SUGGEST_ROUTE'] ?? '/suggest');
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
