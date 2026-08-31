<?php
require_once 'config/database.php';

$sql = file_get_contents(__DIR__ . '/migrations/003_enhance_shipments.sql');

// Split by semicolon, but be careful with delimiters if any (none in this simple script)
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        try {
            $pdo->exec($stmt);
            echo "Executed: " . substr($stmt, 0, 50) . "...\n";
        } catch (PDOException $e) {
            // Ignore "Duplicate column name" errors
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "Skipped (Column exists): " . substr($stmt, 0, 50) . "...\n";
            } elseif (strpos($e->getMessage(), 'Table') !== false && strpos($e->getMessage(), 'already exists') !== false) {
                 echo "Skipped (Table exists): " . substr($stmt, 0, 50) . "...\n";
            } else {
                echo "Error executing: " . substr($stmt, 0, 50) . "...\n";
                echo "Message: " . $e->getMessage() . "\n";
            }
        }
    }
}
echo "Migration completed.\n";
?>
