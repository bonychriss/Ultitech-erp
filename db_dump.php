<?php
require_once __DIR__ . '/includes/config.php';
try {
    $tablesToDescribe = ['attendance', 'attendance_records', 'tasks', 'user_tasks'];
    foreach ($tablesToDescribe as $table) {
        $stmt = $pdo->query("DESCRIBE `$table`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nTable: $table\n";
        foreach ($columns as $column) {
            echo "- {$column['Field']} ({$column['Type']})\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
