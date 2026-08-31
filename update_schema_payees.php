<?php
require_once __DIR__ . '/includes/functions.php';

try {
    echo "Starting schema update...\n";

    // 1. Create payees table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) DEFAULT 'Other',
            tin VARCHAR(50) NULL,
            contact_details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            UNIQUE KEY unique_name (name)
        )
    ");
    echo "Table 'payees' checked/created.\n";

    // 2. Add payee_id to payment_vouchers if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE 'payee_id'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE payment_vouchers ADD COLUMN payee_id INT NULL AFTER payee_name");
        // Add foreign key constraint (optional but good practice, keeping soft for now to avoid migration issues)
        // $pdo->exec("ALTER TABLE payment_vouchers ADD CONSTRAINT fk_pv_payee FOREIGN KEY (payee_id) REFERENCES payees(id) ON DELETE SET NULL");
        echo "Column 'payee_id' added to 'payment_vouchers'.\n";
    } else {
        echo "Column 'payee_id' already exists.\n";
    }

    // 3. Populate payees from existing vouchers (Backfill)
    echo "Backfilling existing payees...\n";
    $stmt = $pdo->query("SELECT DISTINCT payee_name FROM payment_vouchers WHERE payee_name IS NOT NULL AND payee_name != ''");
    $existingNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    $insertStmt = $pdo->prepare("INSERT IGNORE INTO payees (name) VALUES (?)");
    foreach ($existingNames as $name) {
        $name = trim($name);
        if ($name === '(Draft)')
            continue;

        $insertStmt->execute([$name]);
        if ($insertStmt->rowCount() > 0) {
            $count++;
        }
    }
    echo "Backfilled $count new payees from history.\n";

    echo "Schema update completed successfully.\n";

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage() . "\n");
}
?>