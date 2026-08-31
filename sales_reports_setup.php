<?php
require_once 'includes/config.php';

echo "Checking and creating missing tables for sales reports...\n";

// Check existing tables
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Create erp_invoices table if it doesn't exist
if (!in_array('erp_invoices', $tables)) {
    echo "Creating erp_invoices table...\n";
    
    $sql = "CREATE TABLE `erp_invoices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `invoice_number` varchar(50) NOT NULL,
        `customer_id` int(11) NOT NULL,
        `order_id` int(11) DEFAULT NULL,
        `invoice_date` date NOT NULL,
        `due_date` date DEFAULT NULL,
        `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `tax_amount` decimal(15,2) DEFAULT 0.00,
        `discount_amount` decimal(15,2) DEFAULT 0.00,
        `balance_due` decimal(15,2) NOT NULL DEFAULT 0.00,
        `status` enum('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
        `notes` text DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `invoice_number` (`invoice_number`),
        KEY `customer_id` (`customer_id`),
        KEY `order_id` (`order_id`),
        KEY `status` (`status`),
        KEY `invoice_date` (`invoice_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    try {
        $pdo->exec($sql);
        echo "✓ erp_invoices table created successfully\n";
    } catch (PDOException $e) {
        echo "✗ Error creating erp_invoices table: " . $e->getMessage() . "\n";
    }
} else {
    echo "✓ erp_invoices table already exists\n";
}

// Add sample data to erp_invoices if it's empty
$stmt = $pdo->query("SELECT COUNT(*) FROM erp_invoices");
$count = $stmt->fetchColumn();

if ($count == 0) {
    echo "Adding sample data to erp_invoices...\n";
    
    // Get existing customers and users for sample data
    $stmt = $pdo->query("SELECT id FROM customers LIMIT 5");
    $customerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT id FROM users LIMIT 3");
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($customerIds) && !empty($userIds)) {
        $sampleInvoices = [
            ['invoice_number' => 'INV-2024-001', 'total_amount' => 1299.99, 'status' => 'paid'],
            ['invoice_number' => 'INV-2024-002', 'total_amount' => 899.99, 'status' => 'sent'],
            ['invoice_number' => 'INV-2024-003', 'total_amount' => 2499.99, 'status' => 'paid'],
            ['invoice_number' => 'INV-2024-004', 'total_amount' => 599.99, 'status' => 'overdue'],
            ['invoice_number' => 'INV-2024-005', 'total_amount' => 1899.99, 'status' => 'sent']
        ];
        
        foreach ($sampleInvoices as $invoice) {
            $customerId = $customerIds[array_rand($customerIds)];
            $userId = $userIds[array_rand($userIds)];
            $invoiceDate = date('Y-m-d', strtotime('-' . rand(1, 90) . ' days'));
            $dueDate = date('Y-m-d', strtotime($invoiceDate . ' +30 days'));
            
            try {
                $stmt = $pdo->prepare("INSERT INTO erp_invoices (invoice_number, customer_id, invoice_date, due_date, total_amount, balance_due, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $balance = $invoice['status'] == 'paid' ? 0 : $invoice['total_amount'];
                $stmt->execute([
                    $invoice['invoice_number'],
                    $customerId,
                    $invoiceDate,
                    $dueDate,
                    $invoice['total_amount'],
                    $balance,
                    $invoice['status'],
                    $userId
                ]);
                echo "✓ Added invoice: " . $invoice['invoice_number'] . "\n";
            } catch (PDOException $e) {
                echo "✗ Error adding invoice: " . $e->getMessage() . "\n";
            }
        }
    }
} else {
    echo "✓ erp_invoices already has data\n";
}

echo "\nSales reports setup complete!\n";
?>
