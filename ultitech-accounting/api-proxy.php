<?php
declare(strict_types=1);

/**
 * Same-origin proxy to UltiTech Accounting API (avoids localhost↔127.0.0.1 browser issues).
 * Usage: /ultitech-accounting/api-proxy.php?ua_api=1&path=/api/v1/...
 *
 * When ULTITECH_ACCOUNTING_API=internal, requests are dispatched in-process
 * (shared hosting; no php artisan serve required).
 *
 * Important: this file is hit directly by the browser, so it must load ERP env
 * itself (env.php sets ULTITECH_ACCOUNTING_API=internal). Without that it falls
 * back to http://127.0.0.1:8000 and every request 502s on shared hosting.
 */
$root = dirname(__DIR__);
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$isLocal = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
$envCandidates = $isLocal
    ? [$root . '/env.local.php', $root . '/env.php', $root . '/includes/env.local.php', $root . '/includes/env.php']
    : [$root . '/env.php', $root . '/includes/env.php'];
foreach ($envCandidates as $envFile) {
    if (is_file($envFile)) {
        require_once $envFile;
        break;
    }
}

$helper = $root . '/includes/ultitech_accounting_bridge.php';
if (is_file($helper)) {
    require_once $helper;
}

$targetBase = function_exists('ultitech_accounting_api_base')
    ? rtrim(ultitech_accounting_api_base(), '/')
    : 'http://127.0.0.1:8000';

$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' || $path === '/') {
    $path = (string) ($_GET['path'] ?? '');
}
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');
if ($path === '/' || ! str_starts_with($path, '/api/')) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Proxy path must start with /api/', 'path' => $path]);
    exit;
}

// Forward non-path query params (if any) to upstream.
$forwardQuery = [];
foreach ($_GET as $k => $v) {
    if (in_array((string) $k, ['path', 'ua_api'], true)) {
        continue;
    }
    $forwardQuery[(string) $k] = $v;
}
$dispatchPath = $path;
if ($forwardQuery !== []) {
    $dispatchPath .= '?' . http_build_query($forwardQuery);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? file_get_contents('php://input') : null;

$headerMap = ['Accept' => 'application/json'];
$hdrAuth = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['HTTP_X_AUTHORIZATION']
    ?? '';
if ($hdrAuth === '' && function_exists('apache_request_headers')) {
    $all = apache_request_headers();
    foreach ($all as $k => $v) {
        if (strtolower((string) $k) === 'authorization') {
            $hdrAuth = (string) $v;
            break;
        }
    }
}
if ($hdrAuth === '' && ! empty($_SERVER['Authorization'])) {
    $hdrAuth = (string) $_SERVER['Authorization'];
}
if ($hdrAuth === '' && ! empty($_SERVER['HTTP_X_AUTHORIZATION'])) {
    $hdrAuth = (string) $_SERVER['HTTP_X_AUTHORIZATION'];
}
if ($hdrAuth !== '') {
    $headerMap['Authorization'] = $hdrAuth;
}
if (! empty($_SERVER['HTTP_X_COMPANY_ID'])) {
    $headerMap['X-Company-Id'] = (string) $_SERVER['HTTP_X_COMPANY_ID'];
}
if (! empty($_SERVER['HTTP_X_BOOK_ID'])) {
    $headerMap['X-Book-Id'] = (string) $_SERVER['HTTP_X_BOOK_ID'];
}
if (! empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
    $headerMap['Idempotency-Key'] = (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'];
}
if ($body !== null && $body !== '') {
    $headerMap['Content-Type'] = 'application/json';
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (function_exists('ultitech_accounting_uses_internal_api') && ultitech_accounting_uses_internal_api()) {
    $dispatched = ultitech_accounting_dispatch_api($method, $dispatchPath, $body, $headerMap);
    if (($dispatched['error'] ?? '') !== '' && ($dispatched['body'] ?? '') === '') {
        http_response_code(502);
        echo json_encode(['message' => (string) $dispatched['error']]);
        exit;
    }
    http_response_code((int) ($dispatched['status'] ?? 502));
    echo (string) ($dispatched['body'] ?? '');
    exit;
}

$url = $targetBase . $path;
if ($forwardQuery !== []) {
    $url .= '?' . http_build_query($forwardQuery);
}

$headers = [];
foreach ($headerMap as $name => $value) {
    $headers[] = $name . ': ' . $value;
}

if (! function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['message' => 'curl extension required for Accounting API proxy']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_POSTFIELDS => $body,
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$err = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($raw === false) {
    http_response_code(502);
    echo json_encode(['message' => 'Accounting API unreachable via proxy: ' . $err, 'curl' => $errno]);
    exit;
}

$responseBody = substr($raw, $headerSize);
http_response_code($status > 0 ? $status : 502);
echo $responseBody;
