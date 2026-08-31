<?php
// CLI helper to apply a SQL file using project's PDO (deploy_temp/config.php)
// Usage: php tools/apply_sql.php sql/20260110_add_approvals_table.sql

$arg = $argv[1] ?? null;
if (!$arg) {
    echo "Usage: php tools/apply_sql.php <sql-file-path>\n";
    exit(1);
}

$path = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $arg;
$path = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $path);
if (!file_exists($path)) {
    echo "SQL file not found: $path\n";
    exit(2);
}

// Load PDO from deploy_temp config
$configPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'deploy_temp' . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    echo "Config not found: $configPath\n";
    exit(3);
}

require $configPath; // provides $pdo
if (!isset($pdo) || !$pdo) {
    echo "PDO not available from config.\n";
    exit(4);
}

$sql = file_get_contents($path);
if ($sql === false) {
    echo "Failed to read SQL file.\n";
    exit(5);
}

try {
    // Execute SQL - use exec for DDL
    $pdo->exec($sql);
    echo "Migration applied successfully.\n";
    exit(0);
} catch (PDOException $e) {
    echo "SQL execution error: " . $e->getMessage() . "\n";
    exit(6);
}
