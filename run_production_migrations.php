<?php
/**
 * Multi-Tenant Migration Runner
 * 
 * This script runs the PHASE-3B-STOCK-PURCHASE-PAYMENT-MIGRATIONS.sql
 * across all active company tenant databases automatically.
 * It uses the 'mysql' CLI client to execute the SQL securely and 
 * handles DELIMITER blocks correctly.
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "========================================================\n";
echo " MULTI-TENANT MIGRATION RUNNER\n";
echo "========================================================\n\n";

require_once __DIR__ . '/includes/config.php';

$meta = null;
if (isset($control_pdo) && $control_pdo instanceof PDO) {
    $meta = $control_pdo;
} elseif (isset($pdo) && $pdo instanceof PDO) {
    $meta = $pdo;
}

if (!$meta) {
    die("Error: Could not establish connection to the control database.\n");
}

$migrationFile = __DIR__ . '/PHASE-3B-STOCK-PURCHASE-PAYMENT-MIGRATIONS.sql';
if (!file_exists($migrationFile)) {
    die("Error: Migration script not found at {$migrationFile}\n");
}

try {
    $stmt = $meta->query("SELECT id, company_name, company_slug, db_name, db_host, db_user, db_pass FROM companies WHERE status = 'active'");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error querying companies table: " . $e->getMessage() . "\n");
}

echo "Found " . count($companies) . " active company tenants.\n\n";

$successCount = 0;
$failCount = 0;
$skipCount = 0;

foreach ($companies as $comp) {
    $cid = (int)$comp['id'];
    $slug = $comp['company_slug'] ?: "ID-$cid";
    $dbName = trim((string)$comp['db_name']);
    $dbHost = trim((string)$comp['db_host']);
    $dbUser = trim((string)$comp['db_user']);
    $dbPass = trim((string)$comp['db_pass']);

    echo "Processing Tenant: {$comp['company_name']} (Slug: {$slug})\n";
    echo " -> Database: {$dbName}\n";

    if ($dbName === '') {
        echo " -> SKIP: No database name configured for this tenant.\n\n";
        $skipCount++;
        continue;
    }

    // Default to control DB credentials if tenant credentials are not specified
    if (empty($dbHost)) { $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost'; }
    if (empty($dbUser)) { $dbUser = defined('DB_USER') ? DB_USER : 'root'; }
    if (empty($dbPass) && defined('DB_PASS')) { $dbPass = DB_PASS; }

    // Build the mysql CLI command
    // Uses escapeshellarg to prevent injection and safely handle special characters
    $cmd = "mysql -h " . escapeshellarg($dbHost) . " -u " . escapeshellarg($dbUser);
    if (!empty($dbPass)) {
        $cmd .= " -p" . escapeshellarg($dbPass);
    }
    $cmd .= " " . escapeshellarg($dbName) . " < " . escapeshellarg($migrationFile) . " 2>&1";

    echo " -> Executing migration script via mysql CLI...\n";
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);

    if ($returnVar === 0) {
        echo " -> SUCCESS: Migration completed without errors.\n";
        $successCount++;
    } else {
        echo " -> FAILED: mysql command returned exit code {$returnVar}\n";
        echo " -> Output log:\n" . implode("\n", $output) . "\n";
        $failCount++;
    }
    echo "\n";
}

echo "========================================================\n";
echo " MIGRATION RUN COMPLETE\n";
echo " Success: {$successCount}\n";
echo " Failed:  {$failCount}\n";
echo " Skipped: {$skipCount}\n";
echo "========================================================\n";
