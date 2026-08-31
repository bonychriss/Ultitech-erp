<?php
/**
 * Budget module schema bootstrap (idempotent).
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireFinanceOrAdmin();

function budget_table_exists(string $table): bool
{
    global $pdo;
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_budget_schema(): void
{
    global $pdo;

    // budgets (master)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS budgets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            period_type ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'TZS',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_budgets_active (is_active),
            INDEX idx_budgets_period (period_type, start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // budget items (lines)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS budget_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_id INT NOT NULL,
            item_name VARCHAR(190) NOT NULL,
            category VARCHAR(120) NULL,
            budgeted_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            alert_threshold_percent DECIMAL(5,2) NOT NULL DEFAULT 90.00,
            alert_email VARCHAR(190) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_budget_items_budget (budget_id),
            INDEX idx_budget_items_active (is_active),
            CONSTRAINT fk_budget_items_budget
                FOREIGN KEY (budget_id) REFERENCES budgets(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // item sources (automation rules)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS budget_item_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_item_id INT NOT NULL,
            source_type ENUM('purchase_orders','payroll') NOT NULL,
            rule_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_budget_item_sources_item (budget_item_id),
            INDEX idx_budget_item_sources_type (source_type),
            CONSTRAINT fk_budget_item_sources_item
                FOREIGN KEY (budget_item_id) REFERENCES budget_items(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // alerts log (prevent duplicates); alert_kind separates threshold % vs pacing (ahead-of-schedule) alerts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS budget_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_item_id INT NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            spent_percent DECIMAL(8,2) NOT NULL,
            sent_to VARCHAR(190) NOT NULL,
            alert_kind VARCHAR(32) NOT NULL DEFAULT 'threshold',
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_budget_alert_once (budget_item_id, period_start, period_end, sent_to, alert_kind),
            INDEX idx_budget_alerts_item (budget_item_id),
            CONSTRAINT fk_budget_alerts_item
                FOREIGN KEY (budget_item_id) REFERENCES budget_items(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    budget_migrate_budget_alerts_kind_column($pdo);
}

/**
 * Older installs: add alert_kind and widen unique key so pacing + threshold can both log per period.
 */
function budget_migrate_budget_alerts_kind_column(PDO $pdo): void
{
    if (!budget_table_exists('budget_alerts')) {
        return;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM budget_alerts')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return;
        }
        if (!in_array('alert_kind', $cols, true)) {
            $pdo->exec("ALTER TABLE budget_alerts ADD COLUMN alert_kind VARCHAR(32) NOT NULL DEFAULT 'threshold' AFTER sent_to");
        }
        $idxRows = $pdo->query("SHOW INDEX FROM budget_alerts WHERE Key_name = 'uq_budget_alert_once'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seq = [];
        foreach ($idxRows as $ir) {
            $seq[(int) ($ir['Seq_in_index'] ?? 0)] = true;
        }
        $colCount = count($seq);
        if ($colCount === 4) {
            $pdo->exec('ALTER TABLE budget_alerts DROP INDEX uq_budget_alert_once');
            $pdo->exec('ALTER TABLE budget_alerts ADD UNIQUE KEY uq_budget_alert_once (budget_item_id, period_start, period_end, sent_to, alert_kind)');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

ensure_budget_schema();

