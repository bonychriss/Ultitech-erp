<?php
require __DIR__ . '/../env.local.php';
$host = $DB_HOST ?? '127.0.0.1';
$name = $DB_NAME ?? '';
try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $DB_USER ?? 'root', $DB_PASS ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "OK connected to {$name} on {$host}\n";
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
