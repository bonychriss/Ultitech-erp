<?php
require_once '../../includes/functions.php';

global $pdo;

echo "<h2>Initializing Accounting Foundation...</h2>";

try {
    $pdo->beginTransaction();

    // 1. Create Chart of Accounts Table
    echo "Creating erp_chart_of_accounts...<br>";
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_chart_of_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        type ENUM('receivable', 'payable', 'bank', 'cash', 'asset', 'liability', 'equity', 'income', 'expense') NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Seed Default Accounts
    echo "Seeding default accounts...<br>";
    $defaults = [
        ['101000', 'Main Cash', 'cash'],
        ['102000', 'Bank Account', 'bank'],
        ['103000', 'Outstanding Receipts', 'asset'], // Suspense
        ['110000', 'Accounts Receivable', 'receivable'],
        ['120000', 'Stock Valuation', 'asset'], // Current Assets
        ['210000', 'Accounts Payable', 'payable'],
        ['220000', 'Tax Payable', 'liability'],
        ['400000', 'Product Sales', 'income'],
        ['500000', 'Cost of Goods Sold', 'expense'],
        ['600000', 'General Expenses', 'expense']
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO erp_chart_of_accounts (code, name, type) VALUES (?, ?, ?)");
    foreach ($defaults as $acc) {
        $stmt->execute($acc);
        echo " - Added/Checked {$acc[0]} - {$acc[1]}<br>";
    }

    // 3. Update Product Categories (Add Account Links)
    echo "Updating erp_categories schema...<br>";

    // Check and Add Columns
    $cols = [
        'income_account_id' => 'INT NULL',
        'expense_account_id' => 'INT NULL',
        'stock_valuation_account_id' => 'INT NULL'
    ];

    foreach ($cols as $col => $def) {
        try {
            $pdo->query("SELECT $col FROM erp_categories LIMIT 1");
        } catch (PDOException $e) {
            echo " - Adding column $col...<br>";
            $pdo->exec("ALTER TABLE erp_categories ADD COLUMN $col $def");
            $pdo->exec("ALTER TABLE erp_categories ADD CONSTRAINT fk_cat_$col FOREIGN KEY ($col) REFERENCES erp_chart_of_accounts(id)");
        }
    }

    // Set Defaults for Categories (if null)
    // Get IDs of default accounts
    $incomeId = $pdo->query("SELECT id FROM erp_chart_of_accounts WHERE code='400000'")->fetchColumn();
    $expenseId = $pdo->query("SELECT id FROM erp_chart_of_accounts WHERE code='500000'")->fetchColumn();
    $stockId = $pdo->query("SELECT id FROM erp_chart_of_accounts WHERE code='120000'")->fetchColumn();

    if ($incomeId && $expenseId && $stockId) {
        echo "Setting default accounts for existing categories...<br>";
        $pdo->exec("UPDATE erp_categories SET income_account_id = $incomeId WHERE income_account_id IS NULL");
        $pdo->exec("UPDATE erp_categories SET expense_account_id = $expenseId WHERE expense_account_id IS NULL");
        $pdo->exec("UPDATE erp_categories SET stock_valuation_account_id = $stockId WHERE stock_valuation_account_id IS NULL");
    }

    // 4. Update Customers & Vendors (Add Account Links)
    echo "Updating erp_customers schema...<br>";
    try {
        $pdo->query("SELECT receivable_account_id FROM erp_customers LIMIT 1");
    } catch (PDOException $e) {
        echo " - Adding receivable_account_id to customers...<br>";
        $pdo->exec("ALTER TABLE erp_customers ADD COLUMN receivable_account_id INT NULL");
        $pdo->exec("ALTER TABLE erp_customers ADD CONSTRAINT fk_cust_rec_acc FOREIGN KEY (receivable_account_id) REFERENCES erp_chart_of_accounts(id)");
    }

    // Set Default Receivable
    $arId = $pdo->query("SELECT id FROM erp_chart_of_accounts WHERE code='110000'")->fetchColumn();
    if ($arId) {
        $pdo->exec("UPDATE erp_customers SET receivable_account_id = $arId WHERE receivable_account_id IS NULL");
    }

    // Checking if erp_vendors exists (it might verify vendors table if implemented, otherwise skip)
    // Assuming erp_vendors or similar table for suppliers?
    // Let's check if table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'erp_vendors'")->rowCount() > 0;
    if ($tableExists) {
        echo "Updating erp_vendors schema...<br>";
        try {
            $pdo->query("SELECT payable_account_id FROM erp_vendors LIMIT 1");
        } catch (PDOException $e) {
            echo " - Adding payable_account_id to vendors...<br>";
            $pdo->exec("ALTER TABLE erp_vendors ADD COLUMN payable_account_id INT NULL");
            $pdo->exec("ALTER TABLE erp_vendors ADD CONSTRAINT fk_vend_pay_acc FOREIGN KEY (payable_account_id) REFERENCES erp_chart_of_accounts(id)");
        }
        $apId = $pdo->query("SELECT id FROM erp_chart_of_accounts WHERE code='210000'")->fetchColumn();
        if ($apId) {
            $pdo->exec("UPDATE erp_vendors SET payable_account_id = $apId WHERE payable_account_id IS NULL");
        }
    }

    $pdo->commit();
    echo "<h3>Success! Accounting Foundation Initialized.</h3>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h3 style='color:red;'>Error: " . $e->getMessage() . "</h3>";
}
