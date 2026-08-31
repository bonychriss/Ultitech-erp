<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/includes/email_bootstrap.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Email Module Migration</h2>";
echo "<pre>";

if (!isAdmin()) {
    die("ERROR: You must be logged in as an Administrator to run this migration.");
}

try {
    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        $emailDb = $pdo;
    }
    $dbName = (string) $emailDb->query('SELECT DATABASE()')->fetchColumn();
    echo "Database: " . $dbName . "\n";
    if (ensure_email_module_schema($emailDb)) {
        echo "module_emails: OK\n";
        echo "module_email_attachments: OK\n";
        echo "\nMigration completed successfully!";
    } else {
        echo "\nERROR: Could not create email tables.";
    }
} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage();
}

echo "</pre>";
echo "<p><a href='index.php'>Go to Email Module</a></p>";
?>
