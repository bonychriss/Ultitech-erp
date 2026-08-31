<?php
require_once '../../includes/functions.php';

global $pdo;

echo "<h2>Initializing Financial Engine (Phase 3)...</h2>";

try {
    $pdo->beginTransaction();

    // 1. Create Journal Entries (The Ledger Header)
    echo "Creating erp_journal_entries...<br>";
    $pdo->exec("DROP TABLE IF EXISTS erp_journal_items"); // Child first
    $pdo->exec("DROP TABLE IF EXISTS erp_journal_entries");

    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_journal_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        reference VARCHAR(100) NULL,
        journal_type ENUM('sale', 'purchase', 'cash', 'bank', 'general') NOT NULL,
        state ENUM('draft', 'posted') DEFAULT 'posted',
        source_document VARCHAR(50) NULL, -- e.g. INV/2025/001
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Create Journal Items (The Ledger Lines)
    echo "Creating erp_journal_items...<br>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_journal_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id INT NOT NULL,
        account_id INT NOT NULL,
        partner_id INT NULL,
        description VARCHAR(255) NULL,
        debit DECIMAL(15,2) DEFAULT 0.00,
        credit DECIMAL(15,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (entry_id) REFERENCES erp_journal_entries(id) ON DELETE CASCADE,
        FOREIGN KEY (account_id) REFERENCES erp_chart_of_accounts(id),
        FOREIGN KEY (partner_id) REFERENCES erp_customers(id) -- Simplified for now (could be vendors too)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->commit();
    echo "<h3>Success! Financial Engine Initialized.</h3>";

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo "<h3 style='color:red;'>Error: " . $e->getMessage() . "</h3>";
}
