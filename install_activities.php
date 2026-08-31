<?php
require_once 'includes/functions.php';
global $pdo;

echo "<h1>Installing Activity Log Schema...</h1>";

$sql = "CREATE TABLE IF NOT EXISTS erp_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL, -- e.g., 'quote', 'invoice'
    entity_id INT NOT NULL,
    user_id INT,
    action VARCHAR(50) NOT NULL, -- e.g., 'create', 'update_status', 'email'
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

try {
    $pdo->exec($sql);
    echo "<li>Table 'erp_activities' created or already exists.</li>";
} catch (PDOException $e) {
    echo "<li>Error: " . $e->getMessage() . "</li>";
}
?>
