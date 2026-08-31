<?php
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    // Ensure batches table exists for environments missing schema
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `erp_inventory_batches` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `batch_number` VARCHAR(50) NOT NULL,
            `expiry_date` DATE DEFAULT NULL,
            `quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `cost_price` DECIMAL(15,2) DEFAULT 0.00,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        // Add missing columns defensively (legacy DBs)
        $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_inventory_batches'");
        $colCheck->execute();
        $cols = array_map(fn($r) => $r['COLUMN_NAME'], $colCheck->fetchAll(PDO::FETCH_ASSOC));
        $want = [
            'product_id' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `product_id` INT NOT NULL",
            'batch_number' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `batch_number` VARCHAR(50) NOT NULL",
            'expiry_date' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `expiry_date` DATE DEFAULT NULL",
            'quantity' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'cost_price' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `cost_price` DECIMAL(15,2) DEFAULT 0.00",
            'created_at' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "ALTER TABLE `erp_inventory_batches` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        ];
        foreach ($want as $c => $ddl) { if (!in_array($c, $cols, true)) { $pdo->exec($ddl); } }
    } catch (Throwable $schemaEx) {
        // Continue; the insert will error with a useful message if schema still missing
    }
    
    if ($action === 'create') {
        if (empty($_POST['product_id']) || empty($_POST['batch_number']) || empty($_POST['quantity'])) {
            throw new Exception('Product, batch number, and quantity are required');
        }
        
        // Check if batch already exists
        $stmt = $pdo->prepare("SELECT id FROM erp_inventory_batches WHERE product_id = ? AND batch_number = ?");
        $stmt->execute([$_POST['product_id'], $_POST['batch_number']]);
        
        if ($stmt->fetch()) {
            throw new Exception('Batch number already exists for this product');
        }
        
        $sql = "INSERT INTO erp_inventory_batches (product_id, batch_number, expiry_date, quantity, cost_price) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['product_id'],
            $_POST['batch_number'],
            $_POST['expiry_date'] ?? null,
            floatval($_POST['quantity']),
            floatval($_POST['cost_price'] ?? 0)
        ]);
        
        // Update product stock
        $stmt = $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $stmt->execute([floatval($_POST['quantity']), $_POST['product_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Batch created successfully']);
        
    } elseif ($action === 'get_batches') {
        if (empty($_POST['product_id'])) {
            throw new Exception('Product ID is required');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM erp_inventory_batches WHERE product_id = ? AND quantity > 0 ORDER BY expiry_date ASC, created_at ASC");
        $stmt->execute([$_POST['product_id']]);
        $batches = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'batches' => $batches]);
        
    } elseif ($action === 'get_expiring') {
        $days = intval($_POST['days'] ?? 30);
        $sql = "SELECT b.*, p.name as product_name, p.sku 
                FROM erp_inventory_batches b 
                JOIN erp_products p ON b.product_id = p.id 
                WHERE b.expiry_date IS NOT NULL 
                AND b.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL ? DAY)
                AND b.quantity > 0
                ORDER BY b.expiry_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$days]);
        $batches = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'batches' => $batches]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
