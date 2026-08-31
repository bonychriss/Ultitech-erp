<?php
require_once '../../includes/functions.php';
global $pdo;

$sql = "
-- Purchase Requests (Internal requests from staff)
CREATE TABLE IF NOT EXISTS erp_purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(50) NOT NULL UNIQUE,
    request_date DATE NOT NULL,
    requested_by INT NOT NULL,
    department VARCHAR(100) NULL,
    status ENUM('draft', 'pending_approval', 'approved', 'rejected', 'po_created') DEFAULT 'draft',
    approval_level INT DEFAULT 0,
    notes TEXT,
    total_estimated_cost DECIMAL(15,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS erp_pr_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pr_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL, -- Flexible, might not be in product DB yet
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) DEFAULT 'pcs',
    estimated_unit_cost DECIMAL(15,2) DEFAULT 0.00,
    total_cost DECIMAL(15,2) DEFAULT 0.00,
    product_id INT NULL, -- Optional link if exists
    FOREIGN KEY (pr_id) REFERENCES erp_purchase_requests(id) ON DELETE CASCADE
);

-- Goods Received Notes (GRN)
CREATE TABLE IF NOT EXISTS erp_grn (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grn_number VARCHAR(50) NOT NULL UNIQUE,
    po_id INT NOT NULL,
    supplier_id INT NOT NULL,
    received_date DATE NOT NULL,
    received_by INT NOT NULL,
    delivery_note_ref VARCHAR(100) NULL,
    status ENUM('draft', 'verified', 'cancelled') DEFAULT 'draft',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES erp_purchase_orders(id),
    FOREIGN KEY (supplier_id) REFERENCES erp_suppliers(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS erp_grn_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grn_id INT NOT NULL,
    po_item_id INT NOT NULL, -- Link back to PO line
    product_id INT NOT NULL,
    quantity_ordered DECIMAL(10,2) NOT NULL,
    quantity_received DECIMAL(10,2) NOT NULL,
    quantity_rejected DECIMAL(10,2) DEFAULT 0.00,
    rejection_reason TEXT,
    batch_number VARCHAR(100) NULL,
    expiry_date DATE NULL,
    FOREIGN KEY (grn_id) REFERENCES erp_grn(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES erp_products(id)
);
";

try {
    $pdo->exec($sql);
    echo "Purchase Flow (PR & GRN) tables created successfully.";
} catch (PDOException $e) {
    die("Error creating tables: " . $e->getMessage());
}
