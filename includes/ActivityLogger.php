<?php

class ActivityLogger {
    protected $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Log an activity
     */
    public function log($entityType, $entityId, $action, $description = null) {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO erp_activities (entity_type, entity_id, user_id, action, description) VALUES (?, ?, ?, ?, ?)");
            $userId = $_SESSION['user_id'] ?? null;
            $stmt->execute([$entityType, $entityId, $userId, $action, $description]);
        } catch (PDOException $e) {
            // Auto-create table if it doesn't exist
            if ($e->getCode() == '42S02') { // Table not found
                $this->install();
                // Retry
                $this->log($entityType, $entityId, $action, $description);
            }
        }
    }

    /**
     * Get activities for an entity
     */
    public function get($entityType, $entityId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT a.*, u.full_name as user_name 
                FROM erp_activities a 
                LEFT JOIN erp_users u ON a.user_id = u.id 
                WHERE a.entity_type = ? AND a.entity_id = ? 
                ORDER BY a.created_at DESC
            ");
            $stmt->execute([$entityType, $entityId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
             return [];
        }
    }

    private function install() {
        $sql = "CREATE TABLE IF NOT EXISTS erp_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            user_id INT,
            action VARCHAR(50) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $this->pdo->exec($sql);
    }
}
