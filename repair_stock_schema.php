<?php
// repair_stock_schema.php
// Fixes missing columns, tables, and keys identified by health check
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

echo "<h1>Stock Schema Repair</h1>";

function runSQL($pdo, $sql, $msg) {
    try {
        $pdo->exec($sql);
        echo "<div style='color:green'>[SUCCESS] $msg</div>";
    } catch (PDOException $e) {
        $err = $e->getMessage();
        // Ignore "Duplicate column name" or "Multiple primary key" errors if we are being aggressive
        if (strpos($err, 'Duplicate column') !== false) {
             echo "<div style='color:orange'>[SKIP] $msg (Already exists)</div>";
        } elseif (strpos($err, 'Multiple primary key') !== false) {
             echo "<div style='color:orange'>[SKIP] $msg (PK already exists)</div>";
        } else {
             echo "<div style='color:red'>[FAIL] $msg: $err</div>";
        }
    }
}

echo "<h3>Starting Repair...</h3>";

// 0. Disable Constraints
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");

// 1. Repair Products
echo "<h4>1. Products</h4>";
runSQL($pdo, "ALTER TABLE products ADD COLUMN sku VARCHAR(50) AFTER name", "Added 'sku'");
runSQL($pdo, "ALTER TABLE products ADD COLUMN status ENUM('active','inactive') DEFAULT 'active'", "Added 'status'");
runSQL($pdo, "ALTER TABLE products ADD COLUMN image_path VARCHAR(255) DEFAULT NULL", "Added 'image_path'");
runSQL($pdo, "ALTER TABLE products MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT", "Fixed 'id' AUTO_INCREMENT");

// 2. Repair Suppliers
echo "<h4>2. Suppliers</h4>";
runSQL($pdo, "ALTER TABLE suppliers ADD COLUMN status ENUM('active','inactive') DEFAULT 'active'", "Added 'status'");
// Check if PK exists before adding
try {
    $stmt = $pdo->query("SHOW KEYS FROM suppliers WHERE Key_name = 'PRIMARY'");
    if ($stmt->rowCount() == 0) {
        runSQL($pdo, "ALTER TABLE suppliers ADD PRIMARY KEY (id)", "Added PRIMARY KEY to 'id'");
    }
} catch (Exception $e) {}
runSQL($pdo, "ALTER TABLE suppliers MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT", "Fixed 'id' AUTO_INCREMENT");

// 3. Repair Stock
echo "<h4>3. Stock</h4>";
runSQL($pdo, "ALTER TABLE stock ADD COLUMN batch_no VARCHAR(50) DEFAULT NULL", "Added 'batch_no'");
runSQL($pdo, "ALTER TABLE stock ADD COLUMN expiry_date DATE DEFAULT NULL", "Added 'expiry_date'");
try {
    $stmt = $pdo->query("SHOW KEYS FROM stock WHERE Key_name = 'PRIMARY'");
    if ($stmt->rowCount() == 0) {
        runSQL($pdo, "ALTER TABLE stock ADD PRIMARY KEY (id)", "Added PRIMARY KEY to 'id'");
    }
} catch (Exception $e) {}
runSQL($pdo, "ALTER TABLE stock MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT", "Fixed 'id' AUTO_INCREMENT");

// 4. Create Stock Transactions
echo "<h4>4. Stock Transactions</h4>";
$sql_trans = "CREATE TABLE IF NOT EXISTS stock_transactions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT(11) NOT NULL,
    type ENUM('in','out','adjustment') NOT NULL,
    quantity INT(11) NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    created_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
runSQL($pdo, $sql_trans, "Created 'stock_transactions' table");

// 5. Repair Purchases
echo "<h4>5. Purchases</h4>";
runSQL($pdo, "ALTER TABLE purchases ADD COLUMN purchase_date DATE DEFAULT CURRENT_DATE", "Added 'purchase_date'");
runSQL($pdo, "ALTER TABLE purchases ADD COLUMN created_by INT(11) DEFAULT NULL", "Added 'created_by'");

// 6. Repair Purchase Items
echo "<h4>6. Purchase Items</h4>";
runSQL($pdo, "ALTER TABLE purchase_items ADD COLUMN total_price DECIMAL(10,2) DEFAULT 0.00", "Added 'total_price'");

// 7. Repair Shipments
echo "<h4>7. Shipments</h4>";
runSQL($pdo, "ALTER TABLE shipments ADD COLUMN purchase_id INT(11) DEFAULT NULL", "Added 'purchase_id'");
runSQL($pdo, "ALTER TABLE shipments ADD COLUMN carrier VARCHAR(100) DEFAULT NULL", "Added 'carrier'");
runSQL($pdo, "ALTER TABLE shipments ADD COLUMN shipped_date DATE DEFAULT NULL", "Added 'shipped_date'");
runSQL($pdo, "ALTER TABLE shipments ADD COLUMN expected_delivery DATE DEFAULT NULL", "Added 'expected_delivery'");

// 9. Create Product Images
echo "<h4>9. Product Images</h4>";
$sql_img = "CREATE TABLE IF NOT EXISTS product_images (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT(11) NOT NULL,
    image_name VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    sort_order INT(11) DEFAULT 0,
    uploaded_by INT(11) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
runSQL($pdo, $sql_img, "Created 'product_images' table");

// 10. Enable Constraints
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

echo "<h3>Repair Complete!</h3>";
echo "<p><a href='stock_health_check.php'>Run Health Check Again</a></p>";
?>
