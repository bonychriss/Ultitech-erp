<?php
// Simple health check: DB connectivity, sessions, and env
header('Content-Type: application/json');
$ok = true; $errors = [];

try {
    require_once __DIR__ . '/includes/config.php';
} catch (Throwable $e) {
    $ok = false;
    $errors[] = 'Config load failed: ' . $e->getMessage();
}

// Test session
if (session_status() !== PHP_SESSION_ACTIVE) {
    $ok = false; $errors[] = 'Session not active';
}

// Test DB
try {
    if (isset($pdo)) {
        $stmt = $pdo->query('SELECT 1 AS ok');
        $row = $stmt->fetch();
        if ((int)($row['ok'] ?? 0) !== 1) { $ok = false; $errors[] = 'DB ping failed'; }
    } else {
        $ok = false; $errors[] = 'PDO not initialized';
    }
} catch (Throwable $e) {
    $ok = false; $errors[] = 'DB error: ' . $e->getMessage();
}

echo json_encode([
    'status' => $ok ? 'OK' : 'FAIL',
    'host' => $_SERVER['HTTP_HOST'] ?? null,
    'app_base' => defined('APP_BASE_PATH') ? APP_BASE_PATH : null,
    'time' => date('c'),
    'errors' => $errors,
], JSON_PRETTY_PRINT);
