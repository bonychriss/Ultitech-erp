<?php
$base_path = 'c:/xampp/htdocs/stock';
require_once $base_path . '/config/database.php';

try {
    $pdo->beginTransaction();

    // 1. Core shipments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        shipment_number VARCHAR(50) UNIQUE NOT NULL,
        supplier_id INT NOT NULL,
        contact_person VARCHAR(100),
        contact_number VARCHAR(50),
        invoice_number VARCHAR(100),
        tracking_number VARCHAR(100) DEFAULT 'NA',
        packages_count INT DEFAULT 1,
        cbm DECIMAL(10,3) DEFAULT 0.000,
        total_value DECIMAL(15,2) DEFAULT 0.00,
        total_value_currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        description TEXT,
        shipment_date DATE,
        shipper VARCHAR(100),
        ecc_number VARCHAR(100),
        estimated_clearance_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        etd DATE,
        eta DATE,
        actual_arrival_date DATE,
        status ENUM('pending', 'confirmed', 'shipped', 'in_transit', 'arrived_at_port', 'in_customs', 'delivered', 'delayed', 'cancelled') DEFAULT 'pending',
        notes TEXT,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES stocks_suppliers(id)
    )");

    // 2. Shippers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shippers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        contact_person VARCHAR(100),
        email VARCHAR(100),
        phone VARCHAR(20),
        website VARCHAR(200),
        service_type ENUM('air', 'sea', 'road', 'courier', 'freight') DEFAULT 'freight',
        average_delivery_days INT DEFAULT 0,
        reliability_score DECIMAL(3,2) DEFAULT 5.00,
        total_shipments INT DEFAULT 0,
        on_time_rate DECIMAL(5,2) DEFAULT 0.00,
        cost_per_kg DECIMAL(10,2) DEFAULT 0.00,
        cost_per_cbm DECIMAL(10,2) DEFAULT 0.00,
        is_active BOOLEAN DEFAULT TRUE,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Enhancement: Link Shipments to Shippers
    $cols = $pdo->query("SHOW COLUMNS FROM shipments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('shipper_id', $cols)) {
        $pdo->exec("ALTER TABLE shipments ADD COLUMN shipper_id INT NULL AFTER shipper");
        $pdo->exec("ALTER TABLE shipments ADD CONSTRAINT fk_shipments_shipper FOREIGN KEY (shipper_id) REFERENCES shippers(id) ON DELETE SET NULL");
    }
    if (!in_array('shipper_name', $cols)) {
        $pdo->exec("ALTER TABLE shipments ADD COLUMN shipper_name VARCHAR(100) DEFAULT '' AFTER shipper_id");
    }

    // 4. Shipment items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        shipment_id INT NOT NULL,
        product_id INT,
        purchase_id INT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2),
        received_quantity INT DEFAULT 0,
        quality_status ENUM('pending', 'passed', 'failed', 'partial') DEFAULT 'pending',
        notes TEXT,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    )");

    // 5. Shipment packages table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_packages (
        id INT PRIMARY KEY AUTO_INCREMENT,
        shipment_id INT NOT NULL,
        package_number VARCHAR(50),
        tracking_number VARCHAR(100),
        weight_kg DECIMAL(10,3) DEFAULT 0.000,
        dimensions VARCHAR(50),
        cbm DECIMAL(10,3) DEFAULT 0.000,
        status ENUM('pending', 'in_transit', 'arrived', 'received', 'damaged', 'lost') DEFAULT 'pending',
        received_at TIMESTAMP NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )");

    // 6. ECC Documents table
    $pdo->exec("CREATE TABLE IF NOT EXISTS ecc_documents (
        id INT PRIMARY KEY AUTO_INCREMENT,
        shipment_id INT NOT NULL,
        ecc_number VARCHAR(100),
        document_type ENUM('certificate', 'license', 'permit', 'declaration') DEFAULT 'certificate',
        issue_date DATE,
        expiry_date DATE,
        issuing_authority VARCHAR(200),
        file_path VARCHAR(500),
        verified BOOLEAN DEFAULT FALSE,
        verified_by INT,
        verified_at TIMESTAMP NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )");

    // 7. Shipment documents table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_documents (
        id INT PRIMARY KEY AUTO_INCREMENT,
        shipment_id INT NOT NULL,
        document_type ENUM('invoice', 'packing_list', 'bill_of_lading', 'certificate', 'photo', 'other'),
        document_name VARCHAR(255),
        file_path VARCHAR(500),
        uploaded_by INT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )");

    // 8. Shipment status history
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_status_history (
        id INT PRIMARY KEY AUTO_INCREMENT,
        shipment_id INT NOT NULL,
        old_status VARCHAR(50),
        new_status VARCHAR(50),
        changed_by INT NULL,
        notes TEXT,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
    )");

    $pdo->commit();
    echo "Shipment tables created successfully!";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error creating shipment tables: " . $e->getMessage();
}
