<?php
require_once 'includes/config.php';

try {
    echo "<h2>Updating Schema for Weekly Tasks Priority...</h2>";
    
    // Add 'priority' column if it doesn't exist
    $sql = "SHOW COLUMNS FROM weekly_plan_items LIKE 'priority'";
    $stmt = $pdo->query($sql);
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE weekly_plan_items ADD COLUMN priority ENUM('low', 'medium', 'high') DEFAULT 'medium' AFTER task_description");
        echo "✅ Added 'priority' column to weekly_plan_items.<br>";
    } else {
        echo "ℹ️ 'priority' column already exists.<br>";
    }

    echo "<h3>Schema update complete.</h3>";
    echo "<a href='weekly_tasks/index.php'>Go to Dashboard</a>";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
