<?php
require_once 'includes/config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $stmt = $pdo->query("SHOW TABLES LIKE 'tasks'");
    if ($stmt->rowCount() > 0) {
        echo "Table 'tasks' EXISTS.";
    } else {
        echo "Table 'tasks' DOES NOT EXIST.";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
