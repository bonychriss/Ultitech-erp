<?php
require_once __DIR__ . '/includes/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Table: weekly_plans
    // Tracks the overall plan for a user for a specific week
    $sql1 = "CREATE TABLE IF NOT EXISTS weekly_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        week_start_date DATE NOT NULL, -- The Monday of that week
        status ENUM('planned', 'active', 'completed') DEFAULT 'planned',
        manager_rating DECIMAL(5,2) DEFAULT 0.00, -- Auto-calculated score
        manager_comment TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_week (user_id, week_start_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    // Table: weekly_plan_items
    // Individual tasks within a weekly plan
    $sql2 = "CREATE TABLE IF NOT EXISTS weekly_plan_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        task_description TEXT NOT NULL,
        weight INT DEFAULT 1, -- Calculated automatically stats: 1(Low) to 5(Critical)
        is_completed TINYINT(1) DEFAULT 0,
        completed_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (plan_id) REFERENCES weekly_plans(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql1);
    echo "Table 'weekly_plans' created or already exists.<br>";
    
    $pdo->exec($sql2);
    echo "Table 'weekly_plan_items' created or already exists.<br>";
    
    echo "Success! Database schema setup for Weekly Tasks is complete.";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}
