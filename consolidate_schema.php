<?php
require_once 'includes/config.php';

/**
 * Migration script to consolidate schemas for multi-tenant support.
 * Adds company_id to all major tables and includes industry-specific customizations.
 */

function localTableExists($tableName) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tableName]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) { return false; }
}

function localColumnExists($tableName, $columnName) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tableName, $columnName]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) { return false; }
}

$tablesToScope = [
    'payment_vouchers',
    'voucher_items',
    'approval_logs',
    'products',
    'categories',
    'stocks_items',
    'stocks_categories',
    'stocks_suppliers',
    'stocks_purchase_orders',
    'stocks_po_items',
    'stocks_transactions',
    'customers',
    'sales_orders',
    'sales_order_items',
    'invoices',
    'delivery_trips',
    'delivery_orders',
    'delivery_items',
    'delivery_evidence',
    'financial_accounts',
    'revenue_entries',
    'revenue_payments',
    'notifications',
    'messages',
    'attendance',
    'tasks',
    'meetings',
    'voucher_attachments'
];

echo "Starting Schema Consolidation (Phase 2 - Detailed Specs)...\n";

// 1. Add company_id to tables
foreach ($tablesToScope as $table) {
    if (localTableExists($table) && !localColumnExists($table, 'company_id')) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN company_id INT NULL AFTER id");
            $pdo->exec("ALTER TABLE `$table` ADD INDEX (company_id)");
            echo "[OK] Added company_id to $table\n";
        } catch (Exception $e) {
            echo "[ERROR] Adding company_id to $table: " . $e->getMessage() . "\n";
        }
    }
}

// 2. Add Detailed Industry Specs to products
$industryCols = [
    // Core Multi-Tenant
    'item_type' => "ENUM('general', 'spare_part', 'vehicle') NOT NULL DEFAULT 'general'",
    
    // Truck & Fleet Specs
    'truck_type' => "VARCHAR(255) DEFAULT NULL",
    'model_number' => "VARCHAR(100) DEFAULT NULL",
    'engine_model' => "VARCHAR(120) DEFAULT NULL",
    'curb_weight_kg' => "INT DEFAULT NULL",
    'axle_load_front_kg' => "INT DEFAULT NULL",
    'axle_load_rear_kg' => "INT DEFAULT NULL",
    'max_drawing_capacity_kg' => "INT DEFAULT NULL",
    'length_mm' => "INT DEFAULT NULL",
    'width_mm' => "INT DEFAULT NULL",
    'height_mm' => "INT DEFAULT NULL",
    'wheel_base_mm' => "VARCHAR(50) DEFAULT NULL",
    'max_speed_kmh' => "INT DEFAULT NULL",
    'fuel_tank_capacity_l' => "VARCHAR(50) DEFAULT NULL",
    'cab_details' => "TEXT DEFAULT NULL",
    'frame_rail_section_mm' => "VARCHAR(50) DEFAULT NULL",
    'suspension_type' => "VARCHAR(80) DEFAULT NULL",
    'engine_displacement_l' => "DECIMAL(6,3) DEFAULT NULL",
    'engine_horsepower_hp' => "INT DEFAULT NULL",
    'emission_level' => "VARCHAR(40) DEFAULT NULL",
    'fifth_wheel_type' => "VARCHAR(50) DEFAULT NULL",
    'transmission_model' => "VARCHAR(120) DEFAULT NULL",
    'rear_axle_type' => "VARCHAR(80) DEFAULT NULL",
    'rear_axle_ratio' => "VARCHAR(20) DEFAULT NULL",
    'tire_model' => "VARCHAR(80) DEFAULT NULL",
    'tire_type' => "VARCHAR(120) DEFAULT NULL",
    'other_features' => "TEXT DEFAULT NULL",
    
    // Spare Parts Specs
    'brand' => "VARCHAR(100) DEFAULT NULL",
    'compatibility' => "VARCHAR(255) DEFAULT NULL",
    'part_condition' => "VARCHAR(50) DEFAULT 'new'",
    'vin' => "VARCHAR(80) DEFAULT NULL",
    'engine_number' => "VARCHAR(80) DEFAULT NULL",
    'chassis_number' => "VARCHAR(80) DEFAULT NULL",
    'model_year' => "INT DEFAULT NULL",
    'mileage' => "DECIMAL(12,2) DEFAULT NULL",
    'color' => "VARCHAR(50) DEFAULT NULL",
    'wholesale_price' => "DECIMAL(12,2) DEFAULT NULL",
    'oem_number' => "VARCHAR(100) DEFAULT NULL",
    'unit_of_measure' => "VARCHAR(20) DEFAULT 'pcs'"
];

foreach ($industryCols as $col => $def) {
    if (localTableExists('products') && !localColumnExists('products', $col)) {
        try {
            $pdo->exec("ALTER TABLE products ADD COLUMN `$col` $def");
            echo "[OK] Added $col to products\n";
        } catch (Exception $e) {
            echo "[ERROR] Adding $col to products: " . $e->getMessage() . "\n";
        }
    }
}

// 3. Update Companies and Categories
if (localTableExists('companies') && !localColumnExists('companies', 'industry_type')) {
    $pdo->exec("ALTER TABLE companies ADD COLUMN industry_type ENUM('general', 'trading', 'logistics', 'trucks') NOT NULL DEFAULT 'general' AFTER company_slug");
    echo "[OK] Added industry_type to companies\n";
}

if (localTableExists('categories') && !localColumnExists('categories', 'status')) {
    $pdo->exec("ALTER TABLE categories ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
    echo "[OK] Added status to categories\n";
}

// 4. Backfill company_id for existing data (Associate with the first active company)
try {
    $stmt = $pdo->query("SELECT id FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $firstCompanyId = $stmt->fetchColumn();
    
    if ($firstCompanyId) {
        echo "Backfilling existing data with Company ID: $firstCompanyId\n";
        foreach ($tablesToScope as $table) {
            if (localTableExists($table) && localColumnExists($table, 'company_id')) {
                $count = $pdo->exec("UPDATE `$table` SET company_id = $firstCompanyId WHERE company_id IS NULL");
                if ($count > 0) {
                    echo "[OK] Updated $count rows in $table\n";
                }
            }
        }
    }
} catch (Exception $e) {
    echo "[ERROR] During backfill: " . $e->getMessage() . "\n";
}

echo "\nSchema Consolidation Complete.\n";
