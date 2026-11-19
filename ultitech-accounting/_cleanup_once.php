<?php
declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
if (! hash_equals('ua-extract-c323a98f82f3f5f7', $token)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$files = [
    __DIR__ . '/_extract_backend.php',
    __DIR__ . '/_fix_sqlite_path.php',
    __DIR__ . '/_smoke_api.php',
    __DIR__ . '/_smoke_bridge.php',
    __DIR__ . '/backend-deploy.zip',
    __DIR__ . '/_cleanup_once.php',
];

foreach ($files as $file) {
    if (is_file($file)) {
        echo (unlink($file) ? 'deleted ' : 'failed ') . basename($file) . "\n";
    } else {
        echo 'missing ' . basename($file) . "\n";
    }
}
