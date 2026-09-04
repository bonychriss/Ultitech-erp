<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
// IMPORTANT: Do NOT require login here.
// Image requests from <img> tags can be redirected to the login page (HTML),
// which makes every image appear broken. Product images are not sensitive,
// so we serve them publicly from disk with strict path validation below.

$productId = (int) ($_GET['product_id'] ?? 0);
$size = (string) ($_GET['size'] ?? 'medium');
$file = (string) ($_GET['file'] ?? '');

if ($productId <= 0) {
    http_response_code(400);
    exit('Invalid product_id');
}

$size = strtolower(trim($size));
$allowedSizes = array('medium', 'thumbnail', 'original', 'large');
if (!in_array($size, $allowedSizes, true)) {
    $size = 'medium';
}

$file = trim(str_replace('\\', '/', $file));
$file = basename($file);

$ctx = function_exists('stock_image_company_context')
    ? stock_image_company_context()
    : array('slug' => '', 'company_id' => 1);

$path = null;
if (function_exists('stock_resolve_product_image_file')) {
    $sizeFallbacks = array($size);
    foreach (array('thumbnail', 'medium', 'large', 'original') as $candidate) {
        if (!in_array($candidate, $sizeFallbacks, true)) {
            $sizeFallbacks[] = $candidate;
        }
    }

    foreach ($sizeFallbacks as $trySize) {
        $path = stock_resolve_product_image_file($productId, $trySize, $file, $ctx['slug'], $ctx['company_id']);
        if (($path === null || !is_file($path)) && $file !== '') {
            $path = stock_resolve_product_image_file($productId, $trySize, '', $ctx['slug'], $ctx['company_id']);
        }
        if ($path !== null && is_file($path)) {
            break;
        }
        $path = null;
    }
}

if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = array(
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'bmp' => 'image/bmp',
    'svg' => 'image/svg+xml',
);
$ctype = isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';

header('Content-Type: ' . $ctype);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

if (ob_get_level()) {
    ob_clean();
}

readfile($path);
exit;
