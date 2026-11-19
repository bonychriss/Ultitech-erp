<?php
declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');
if (! hash_equals('ua-extract-c323a98f82f3f5f7', $token)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$envPath = __DIR__ . '/backend/.env';
$dbPath = __DIR__ . '/backend/database/database.sqlite';
$abs = str_replace('\\', '/', $dbPath);

if (! is_file($envPath)) {
    http_response_code(404);
    echo "Missing backend/.env\n";
    exit;
}
if (! is_file($dbPath)) {
    http_response_code(404);
    echo "Missing database.sqlite\n";
    exit;
}

$env = file_get_contents($envPath);
if ($env === false) {
    http_response_code(500);
    echo "Cannot read .env\n";
    exit;
}

if (preg_match('/^DB_DATABASE=.*$/m', $env)) {
    $env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . $abs, $env, 1);
} else {
    $env .= "\nDB_DATABASE={$abs}\n";
}

if (file_put_contents($envPath, $env) === false) {
    http_response_code(500);
    echo "Cannot write .env\n";
    exit;
}

@chmod($dbPath, 0664);
foreach ([
    __DIR__ . '/backend/storage',
    __DIR__ . '/backend/storage/framework',
    __DIR__ . '/backend/storage/framework/cache',
    __DIR__ . '/backend/storage/framework/sessions',
    __DIR__ . '/backend/storage/framework/views',
    __DIR__ . '/backend/storage/logs',
    __DIR__ . '/backend/bootstrap/cache',
] as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @chmod($dir, 0755);
}

echo "OK DB_DATABASE={$abs}\n";
