<?php
/**
 * One-shot extract of backend.zip into ultitech-accounting/backend.
 * Delete this file after a successful run.
 */
declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
$expected = 'ua-extract-c323a98f82f3f5f7';
if (! hash_equals($expected, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$root = __DIR__;
$zipPath = $root . DIRECTORY_SEPARATOR . 'backend-deploy.zip';
$target = $root . DIRECTORY_SEPARATOR . 'backend';

header('Content-Type: text/plain; charset=utf-8');

if (! is_file($zipPath)) {
    http_response_code(404);
    echo "Missing backend-deploy.zip\n";
    exit;
}

if (! class_exists('ZipArchive')) {
    http_response_code(500);
    echo "ZipArchive extension missing\n";
    exit;
}

if (! is_dir($target)) {
    mkdir($target, 0755, true);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    http_response_code(500);
    echo "Failed to open zip\n";
    exit;
}

$ok = $zip->extractTo($target);
$count = $zip->numFiles;
$zip->close();

if (! $ok) {
    http_response_code(500);
    echo "Extract failed\n";
    exit;
}

// Ensure runtime dirs exist and are writable.
foreach ([
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
] as $rel) {
    $dir = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

@unlink($zipPath);
echo "OK extracted {$count} entries into backend/\n";
echo "Delete _extract_backend.php now.\n";
