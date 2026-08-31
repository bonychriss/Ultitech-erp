<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
// IMPORTANT: Do NOT require login here.
// Image requests from <img> tags can be redirected to the login page (HTML),
// which makes every image appear broken. Product images are not sensitive,
// so we serve them publicly from disk with strict path validation below.

// Optional role restriction: uncomment if you want only logged-in staff (already required).
// if (function_exists('hasRole') && !hasRole('admin')) { http_response_code(403); exit('Forbidden'); }

$productId = (int) ($_GET['product_id'] ?? 0);
$size = (string) ($_GET['size'] ?? 'medium');
$file = (string) ($_GET['file'] ?? '');

// Validate inputs
if ($productId <= 0) {
    http_response_code(400);
    exit('Invalid product_id');
}

$size = strtolower(trim($size));
$allowedSizes = ['medium', 'thumbnail', 'original', 'large'];
if (!in_array($size, $allowedSizes, true)) {
    $size = 'medium';
}

$file = trim(str_replace('\\', '/', $file));
$file = basename($file); // prevent path traversal

$base = realpath(__DIR__ . '/uploads/products');
if (!$base) {
    $base = __DIR__ . '/uploads/products';
}
if (!is_dir($base)) {
    http_response_code(404);
    exit('Uploads folder missing');
}

$path = $base . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . $size . DIRECTORY_SEPARATOR . $file;

// If file not provided, auto-pick first image from disk (fixes DB rows with NULL image).
if ($file === '' || $file === '.' || $file === '..') {
    $picked = null;
    foreach ([$size, 'medium', 'thumbnail', 'original', 'large'] as $sz) {
        if (!in_array($sz, $allowedSizes, true)) continue;
        $dir = $base . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . $sz;
        if (!is_dir($dir)) continue;
        $candidates = glob($dir . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,gif,webp,bmp}', GLOB_BRACE) ?: [];
        if (!empty($candidates)) {
            // Pick the newest file (by mtime).
            usort($candidates, static fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
            $picked = $candidates[0];
            break;
        }
    }
    if ($picked && is_file($picked)) {
        $path = $picked;
    } else {
        http_response_code(404);
        exit('Not found');
    }
} elseif (!is_file($path)) {
    // Try a few fallbacks if the chosen size doesn't exist.
    foreach (['medium', 'thumbnail', 'original', 'large'] as $fallback) {
        $p2 = $base . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . $fallback . DIRECTORY_SEPARATOR . $file;
        if (is_file($p2)) {
            $path = $p2;
            break;
        }
    }
}

if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

// Send content type
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'bmp' => 'image/bmp',
    'svg' => 'image/svg+xml',
];
$ctype = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $ctype);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=86400'); // 1 day
header('X-Content-Type-Options: nosniff');

// Clear buffer just in case anything leaked during processing
if (ob_get_level()) { ob_clean(); }

readfile($path);
exit;

