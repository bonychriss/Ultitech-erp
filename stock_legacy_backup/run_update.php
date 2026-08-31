<?php
require_once __DIR__ . '/config/database.php';

$sql = file_get_contents(__DIR__ . '/spec_update.sql');

try {
    $pdo->exec($sql);
    echo "Schema update completed successfully.";
} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage();
    exit(1);
}
?>
