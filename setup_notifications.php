<?php
require_once 'includes/config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS erp_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT,
        type VARCHAR(50) DEFAULT 'info',
        link VARCHAR(255) NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql);
    echo "Table 'erp_notifications' created successfully.";
    
    // Seed a test notification
    $stmt = $pdo->prepare("INSERT INTO erp_notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'] ?? 1, 'Welcome!', 'Welcome to the new notification system.', 'success']);
    echo " Test notification added.";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
