<?php
require_once '../includes/config.php';

// Define the EXPECTED schema based on the codebase
$expectedSchema = [
    'erp_invoices' => [
        'id' => 'INT',
        'invoice_number' => 'VARCHAR',
        'customer_id' => 'INT',
        'invoice_date' => 'DATE',
        'due_date' => 'DATE',
        'subtotal' => 'DECIMAL',
        'tax_rate' => 'DECIMAL',
        'tax_amount' => 'DECIMAL',
        'total' => 'DECIMAL',
        'balance' => 'DECIMAL',
        'notes' => 'TEXT',
        'status' => 'VARCHAR',
        'created_by' => 'INT',
        'created_at' => 'TIMESTAMP'
    ],
    'erp_invoice_items' => [
        'id' => 'INT',
        'invoice_id' => 'INT',
        'product_id' => 'INT',
        'description' => 'TEXT',
        'quantity' => 'DECIMAL',
        'unit_price' => 'DECIMAL',
        'total' => 'DECIMAL'
    ],
    'erp_notifications' => [
        'id' => 'INT',
        'user_id' => 'INT',
        'title' => 'VARCHAR',
        'message' => 'TEXT',
        'type' => 'VARCHAR',
        'link' => 'VARCHAR',
        'is_read' => 'TINYINT',
        'created_at' => 'TIMESTAMP'
    ],
    'erp_customers' => [
        'id' => 'INT',
        'customer_code' => 'VARCHAR',
        'name' => 'VARCHAR',
        'email' => 'VARCHAR',
        'phone' => 'VARCHAR',
        'address' => 'TEXT',
        'city' => 'VARCHAR',
        'country' => 'VARCHAR',
        'tax_id' => 'VARCHAR',
        'credit_limit' => 'DECIMAL',
        'notes' => 'TEXT',
        'status' => 'VARCHAR',
        'created_by' => 'INT'
    ],
    'erp_products' => [
        'id' => 'INT',
        'sku' => 'VARCHAR',
        'name' => 'VARCHAR',
        'description' => 'TEXT',
        'category_id' => 'INT',
        'unit' => 'VARCHAR',
        'unit_price' => 'DECIMAL',
        'cost_price' => 'DECIMAL',
        'stock_quantity' => 'DECIMAL',
        'reorder_level' => 'DECIMAL',
        'status' => 'VARCHAR'
    ],
    'erp_categories' => [
        'id' => 'INT',
        'name' => 'VARCHAR',
        'description' => 'TEXT',
        'status' => 'VARCHAR'
    ],
    'erp_suppliers' => [
        'id' => 'INT',
        'supplier_code' => 'VARCHAR',
        'name' => 'VARCHAR',
        'email' => 'VARCHAR',
        'phone' => 'VARCHAR',
        'address' => 'TEXT',
        'city' => 'VARCHAR',
        'country' => 'VARCHAR',
        'tax_id' => 'VARCHAR',
        'notes' => 'TEXT',
        'status' => 'VARCHAR'
    ],
    'erp_purchase_orders' => [
        'id' => 'INT',
        'po_number' => 'VARCHAR',
        'supplier_id' => 'INT',
        'order_date' => 'DATE',
        'expected_delivery' => 'DATE',
        'subtotal' => 'DECIMAL',
        'tax_amount' => 'DECIMAL',
        'total' => 'DECIMAL',
        'notes' => 'TEXT',
        'status' => 'VARCHAR'
    ],
    'erp_purchase_order_items' => [
        'id' => 'INT',
        'po_id' => 'INT',
        'product_id' => 'INT',
        'description' => 'TEXT',
        'quantity' => 'DECIMAL',
        'unit_price' => 'DECIMAL',
        'total' => 'DECIMAL',
        'received_quantity' => 'DECIMAL'
    ],
    'erp_employees' => [
        'id' => 'INT',
        'employee_code' => 'VARCHAR',
        'first_name' => 'VARCHAR',
        'last_name' => 'VARCHAR',
        'email' => 'VARCHAR',
        'phone' => 'VARCHAR',
        'department_id' => 'INT',
        'position' => 'VARCHAR',
        'join_date' => 'DATE',
        'basic_salary' => 'DECIMAL',
        'bank_name' => 'VARCHAR',
        'bank_account_number' => 'VARCHAR',
        'status' => 'VARCHAR',
        'user_id' => 'INT'
    ],
    'erp_quotes' => [
        'id' => 'INT',
        'quote_number' => 'VARCHAR',
        'customer_id' => 'INT',
        'date' => 'DATE',
        'expiry_date' => 'DATE',
        'subtotal' => 'DECIMAL',
        'tax_amount' => 'DECIMAL',
        'total_amount' => 'DECIMAL',
        'status' => 'VARCHAR',
        'notes' => 'TEXT',
        'created_by' => 'INT'
    ],
    'erp_quote_items' => [
        'id' => 'INT',
        'quote_id' => 'INT',
        'product_id' => 'INT',
        'description' => 'TEXT',
        'quantity' => 'DECIMAL',
        'unit_price' => 'DECIMAL',
        'tax_rate' => 'DECIMAL',
        'total' => 'DECIMAL'
    ],
    'erp_delivery_notes' => [
        'id' => 'INT',
        'delivery_number' => 'VARCHAR',
        'invoice_id' => 'INT',
        'customer_id' => 'INT',
        'date' => 'DATE',
        'status' => 'VARCHAR',
        'shipping_address' => 'TEXT',
        'driver_name' => 'VARCHAR',
        'vehicle_number' => 'VARCHAR',
        'notes' => 'TEXT',
        'created_by' => 'INT'
    ],
    'erp_delivery_items' => [
        'id' => 'INT',
        'delivery_id' => 'INT',
        'product_id' => 'INT',
        'quantity' => 'DECIMAL',
        'batch_number' => 'VARCHAR'
    ],
    'erp_inventory_batches' => [
        'id' => 'INT',
        'product_id' => 'INT',
        'batch_number' => 'VARCHAR',
        'expiry_date' => 'DATE',
        'quantity' => 'DECIMAL',
        'cost_price' => 'DECIMAL'
    ],
    'erp_stock_movements' => [
        'id' => 'INT',
        'product_id' => 'INT',
        'type' => 'VARCHAR',
        'quantity' => 'DECIMAL',
        'reference_type' => 'VARCHAR',
        'reference_id' => 'INT',
        'notes' => 'TEXT',
        'created_by' => 'INT',
        'created_at' => 'TIMESTAMP'
    ],
    'erp_inventory_adjustments' => [
        'id' => 'INT',
        'adjustment_number' => 'VARCHAR',
        'date' => 'DATE',
        'reason' => 'VARCHAR',
        'notes' => 'TEXT',
        'status' => 'VARCHAR',
        'created_by' => 'INT'
    ],
    'erp_inventory_adjustment_items' => [
        'id' => 'INT',
        'adjustment_id' => 'INT',
        'product_id' => 'INT',
        'quantity_before' => 'DECIMAL',
        'quantity_after' => 'DECIMAL',
        'difference' => 'DECIMAL'
    ],
    'erp_accounts' => [
        'id' => 'INT',
        'code' => 'VARCHAR',
        'name' => 'VARCHAR',
        'type' => 'VARCHAR',
        'description' => 'TEXT',
        'parent_id' => 'INT',
        'is_system' => 'TINYINT',
        'status' => 'VARCHAR'
    ],
    'erp_journal_entries' => [
        'id' => 'INT',
        'entry_number' => 'VARCHAR',
        'date' => 'DATE',
        'description' => 'TEXT',
        'status' => 'VARCHAR',
        'created_by' => 'INT'
    ],
    'erp_journal_items' => [
        'id' => 'INT',
        'journal_id' => 'INT',
        'account_id' => 'INT',
        'debit' => 'DECIMAL',
        'credit' => 'DECIMAL'
    ],
    'erp_expenses' => [
        'id' => 'INT',
        'expense_number' => 'VARCHAR',
        'date' => 'DATE',
        'payee' => 'VARCHAR',
        'account_id' => 'INT',
        'amount' => 'DECIMAL',
        'payment_method' => 'VARCHAR',
        'description' => 'TEXT',
        'status' => 'VARCHAR',
        'created_by' => 'INT'
    ],
    'erp_payroll' => [
        'id' => 'INT',
        'payroll_month' => 'VARCHAR',
        'employee_id' => 'INT',
        'basic_salary' => 'DECIMAL',
        'allowances' => 'DECIMAL',
        'deductions' => 'DECIMAL',
        'net_salary' => 'DECIMAL',
        'status' => 'VARCHAR',
        'created_by' => 'INT'
    ],
    'erp_leave_requests' => [
        'id' => 'INT',
        'employee_id' => 'INT',
        'leave_type' => 'VARCHAR',
        'start_date' => 'DATE',
        'end_date' => 'DATE',
        'reason' => 'TEXT',
        'status' => 'VARCHAR',
        'approved_by' => 'INT'
    ],
    'erp_leads' => [
        'id' => 'INT',
        'first_name' => 'VARCHAR',
        'last_name' => 'VARCHAR',
        'email' => 'VARCHAR',
        'phone' => 'VARCHAR',
        'company' => 'VARCHAR',
        'source' => 'VARCHAR',
        'status' => 'VARCHAR',
        'assigned_to' => 'INT',
        'notes' => 'TEXT'
    ],
    'erp_opportunities' => [
        'id' => 'INT',
        'name' => 'VARCHAR',
        'customer_id' => 'INT',
        'lead_id' => 'INT',
        'amount' => 'DECIMAL',
        'stage' => 'VARCHAR',
        'probability' => 'INT',
        'expected_close_date' => 'DATE',
        'assigned_to' => 'INT',
        'notes' => 'TEXT'
    ],
    'erp_crm_activities' => [
        'id' => 'INT',
        'type' => 'VARCHAR',
        'subject' => 'VARCHAR',
        'description' => 'TEXT',
        'lead_id' => 'INT',
        'opportunity_id' => 'INT',
        'customer_id' => 'INT',
        'due_date' => 'DATETIME',
        'status' => 'VARCHAR',
        'created_by' => 'INT'
    ],
    'erp_bank_accounts' => [
        'id' => 'INT',
        'account_name' => 'VARCHAR',
        'account_number' => 'VARCHAR',
        'bank_name' => 'VARCHAR',
        'branch' => 'VARCHAR',
        'currency' => 'VARCHAR',
        'opening_balance' => 'DECIMAL',
        'current_balance' => 'DECIMAL',
        'gl_account_id' => 'INT',
        'status' => 'VARCHAR'
    ],
    'erp_bank_transactions' => [
        'id' => 'INT',
        'bank_account_id' => 'INT',
        'transaction_date' => 'DATE',
        'description' => 'VARCHAR',
        'reference' => 'VARCHAR',
        'debit' => 'DECIMAL',
        'credit' => 'DECIMAL',
        'balance' => 'DECIMAL',
        'reconciled' => 'TINYINT',
        'created_by' => 'INT'
    ],
    'erp_settings' => [
        'id' => 'INT',
        'setting_key' => 'VARCHAR',
        'setting_value' => 'TEXT'
    ],
    'erp_tax_rates' => [
        'id' => 'INT',
        'name' => 'VARCHAR',
        'rate' => 'DECIMAL',
        'type' => 'VARCHAR',
        'is_default' => 'TINYINT'
    ],
    'erp_user_roles' => [
        'id' => 'INT',
        'role_name' => 'VARCHAR',
        'permissions' => 'TEXT',
        'status' => 'VARCHAR'
    ]
];

echo "<!DOCTYPE html><html><head><title>DB Schema Check</title>";
echo "<style>body{font-family:sans-serif;padding:20px;} .missing{color:red;font-weight:bold;} .ok{color:green;} pre{background:#f4f4f4;padding:10px;border:1px solid #ddd;}</style>";
echo "</head><body>";
echo "<h1>Database Schema Health Check</h1>";

$missingSQL = [];

foreach ($expectedSchema as $table => $columns) {
    echo "<h3>Checking Table: $table</h3>";
    
    // Check if table exists
    try {
        $stmt = $pdo->query("SELECT 1 FROM $table LIMIT 1");
        echo "<div class='ok'>✔ Table exists</div>";
    } catch (Exception $e) {
        echo "<div class='missing'>✘ Table MISSING</div>";
        // Generate CREATE TABLE SQL (simplified)
        $createSQL = "CREATE TABLE $table ( id INT AUTO_INCREMENT PRIMARY KEY, ";
        foreach ($columns as $col => $type) {
            if ($col == 'id') continue;
            $createSQL .= "$col " . ($type == 'INT' ? 'INT' : ($type == 'DECIMAL' ? 'DECIMAL(10,2)' : ($type == 'DATE' ? 'DATE' : 'VARCHAR(255)'))) . ", ";
        }
        $createSQL = rtrim($createSQL, ", ") . " );";
        $missingSQL[] = $createSQL;
        continue; // Skip column check if table is missing
    }

    // Check columns
    echo "<ul>";
    $dbCols = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($columns as $col => $type) {
        if (in_array($col, $dbCols)) {
            echo "<li class='ok'>✔ Column '$col' exists</li>";
        } else {
            echo "<li class='missing'>✘ Column '$col' MISSING</li>";
            // Generate ALTER TABLE SQL
            $def = "VARCHAR(255) NULL";
            if ($type == 'INT') $def = "INT NULL";
            if ($type == 'DECIMAL') $def = "DECIMAL(10,2) DEFAULT 0.00";
            if ($type == 'DATE') $def = "DATE NULL";
            if ($type == 'TEXT') $def = "TEXT NULL";
            if ($type == 'TIMESTAMP') $def = "TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
            
            $missingSQL[] = "ALTER TABLE $table ADD COLUMN $col $def;";
        }
    }
    echo "</ul>";
}

if (!empty($missingSQL)) {
    echo "<h2>Recommended Fixes (Run these in SQL):</h2>";
    echo "<pre>";
    foreach ($missingSQL as $sql) {
        echo htmlspecialchars($sql) . "\n\n";
    }
    echo "</pre>";
} else {
    echo "<h2>All checks passed! Database looks good.</h2>";
}

echo "</body></html>";
?>
