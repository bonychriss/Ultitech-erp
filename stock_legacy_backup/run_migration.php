<?php
require_once __DIR__ . '/config/database.php';

function execSQL($pdo, $sql, $desc) {
    try {
        $pdo->exec($sql);
        echo "[OK] $desc\n";
    } catch (PDOException $e) {
        // Ignore "column exists" or "table exists" or "duplicate key" errors roughly
        // 1060: Duplicate column name
        // 1050: Table already exists
        // 1061: Duplicate key name
        // 1005: Can't create table (FK issue) - we want to see this
        if ($e->errorInfo[1] == 1060 || $e->errorInfo[1] == 1050 || $e->errorInfo[1] == 1061) {
             echo "[SKIP] $desc (Already exists)\n";
        } else {
             echo "[ERROR] $desc: " . $e->getMessage() . "\n";
        }
    }
}

// 1. Create shipment_costs
$sql = "CREATE TABLE IF NOT EXISTS shipment_costs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shipment_id INT NOT NULL,
    cost_type ENUM('shipping', 'insurance', 'fuel', 'duty', 'brokerage', 'port', 'transport', 'storage', 'bank', 'other'),
    description VARCHAR(255),
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    amount_local DECIMAL(15,2),
    invoice_number VARCHAR(100),
    paid BOOLEAN DEFAULT FALSE,
    paid_date DATE,
    entered_by INT,
    entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    KEY idx_shipment (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
execSQL($pdo, $sql, "Create shipment_costs table");

// 2. Add FK to shipment_costs (separate step)
$sql = "ALTER TABLE shipment_costs ADD CONSTRAINT fk_shipment_costs_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE";
execSQL($pdo, $sql, "Add FK to shipment_costs");

// 3. Create alerts
$sql = "CREATE TABLE IF NOT EXISTS alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    alert_type ENUM('low_stock', 'shipment_delayed', 'goods_arrived', 'po_approval', 'cost_variance', 'expiry'),
    title VARCHAR(200),
    message TEXT,
    reference_id INT,
    reference_type VARCHAR(50),
    is_read BOOLEAN DEFAULT FALSE,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
execSQL($pdo, $sql, "Create alerts table");

// 4. Update stock
execSQL($pdo, "ALTER TABLE stock ADD COLUMN reserved_quantity INT DEFAULT 0", "Add stock.reserved_quantity");
execSQL($pdo, "ALTER TABLE stock ADD COLUMN stock_value DECIMAL(15,2) DEFAULT 0.00", "Add stock.stock_value");
execSQL($pdo, "ALTER TABLE stock ADD COLUMN last_movement TIMESTAMP NULL", "Add stock.last_movement");

// 5. Update product_batches
execSQL($pdo, "ALTER TABLE product_batches ADD COLUMN manufacturing_date DATE", "Add product_batches.manufacturing_date");
execSQL($pdo, "ALTER TABLE product_batches ADD COLUMN notes TEXT", "Add product_batches.notes");

?>
