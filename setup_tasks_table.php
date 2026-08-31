<?php
/**
 * Setup script for tasks table
 * Run this once on the production server to create the tasks table
 */

require_once 'includes/config.php';

try {
    echo "Creating tasks table...<br>";
    
    $sql = "CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('daily', 'weekly', 'monthly') NOT NULL,
        description TEXT NOT NULL,
        status ENUM('pending', 'approved', 'implemented', 'verified', 'rejected') DEFAULT 'pending',
        admin_feedback TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_type_date (user_id, type, created_at),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "✓ Tasks table created successfully!<br>";
    
    // Verify table exists
    $check = $pdo->query("SHOW TABLES LIKE 'tasks'");
    if ($check->rowCount() > 0) {
        echo "✓ Table verification passed<br>";
        
        // Show table structure
        $structure = $pdo->query("DESCRIBE tasks");
        echo "<br><strong>Table Structure:</strong><br>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = $structure->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "✗ Table verification failed<br>";
    }
    
    echo "<br><strong>Setup complete!</strong><br>";
    echo "<a href='index.php'>Go to Home Page</a>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
    echo "Please check your database connection and try again.";
}
?>
