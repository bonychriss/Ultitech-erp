<?php
require_once 'includes/config.php';

echo "Creating all missing tables for sales reports...\n";

// Check existing tables
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Tables to create
$requiredTables = [
    'erp_invoice_items',
    'erp_products', 
    'erp_customers',
    'erp_quotes',
    'erp_opportunities',
    'erp_leads'
];

foreach ($requiredTables as $table) {
    if (!in_array($table, $tables)) {
        echo "Creating $table table...\n";
        
        switch ($table) {
            case 'erp_invoice_items':
                $sql = "CREATE TABLE `erp_invoice_items` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `invoice_id` int(11) NOT NULL,
                    `product_id` int(11) NOT NULL,
                    `quantity` int(11) NOT NULL DEFAULT 1,
                    `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
                    `total` decimal(15,2) NOT NULL DEFAULT 0.00,
                    `description` text DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `invoice_id` (`invoice_id`),
                    KEY `product_id` (`product_id`),
                    FOREIGN KEY (`invoice_id`) REFERENCES `erp_invoices`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                break;
                
            case 'erp_products':
                $sql = "CREATE TABLE `erp_products` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    `product_code` varchar(100) DEFAULT NULL,
                    `description` text DEFAULT NULL,
                    `unit_price` decimal(15,2) DEFAULT 0.00,
                    `cost_price` decimal(15,2) DEFAULT 0.00,
                    `category` varchar(100) DEFAULT NULL,
                    `status` enum('active','inactive') DEFAULT 'active',
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `product_code` (`product_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                break;
                
            case 'erp_customers':
                $sql = "CREATE TABLE `erp_customers` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    `email` varchar(255) DEFAULT NULL,
                    `phone` varchar(50) DEFAULT NULL,
                    `address` text DEFAULT NULL,
                    `city` varchar(100) DEFAULT NULL,
                    `country` varchar(100) DEFAULT NULL,
                    `status` enum('active','inactive') DEFAULT 'active',
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                break;
                
            case 'erp_quotes':
                $sql = "CREATE TABLE `erp_quotes` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `quote_number` varchar(50) NOT NULL,
                    `customer_id` int(11) NOT NULL,
                    `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                    `status` enum('draft','sent','accepted','rejected','converted') DEFAULT 'draft',
                    `date` date NOT NULL,
                    `valid_until` date DEFAULT NULL,
                    `created_by` int(11) NOT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `quote_number` (`quote_number`),
                    KEY `customer_id` (`customer_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                break;
                
            case 'erp_opportunities':
                $sql = "CREATE TABLE `erp_opportunities` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `title` varchar(255) NOT NULL,
                    `customer_id` int(11) DEFAULT NULL,
                    `amount` decimal(15,2) DEFAULT 0.00,
                    `stage` enum('lead','qualified','proposal','negotiation','closed_won','closed_lost') DEFAULT 'lead',
                    `probability` int(3) DEFAULT 0,
                    `expected_close_date` date DEFAULT NULL,
                    `assigned_to` int(11) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                break;
                
            case 'erp_leads':
                $sql = "CREATE TABLE `erp_leads` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `name` varchar(255) NOT NULL,
                    `email` varchar(255) DEFAULT NULL,
                    `phone` varchar(50) DEFAULT NULL,
                    `company` varchar(255) DEFAULT NULL,
                    `source` varchar(100) DEFAULT NULL,
                    `status` enum('new','contacted','qualified','converted','lost') DEFAULT 'new',
                    `assigned_to` int(11) DEFAULT NULL,
                    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                break;
        }
        
        try {
            $pdo->exec($sql);
            echo "✓ $table table created successfully\n";
        } catch (PDOException $e) {
            echo "✗ Error creating $table table: " . $e->getMessage() . "\n";
        }
    } else {
        echo "✓ $table table already exists\n";
    }
}

// Add sample data if tables are empty
echo "\nAdding sample data...\n";

// Add sample erp_products
$stmt = $pdo->query("SELECT COUNT(*) FROM erp_products");
if ($stmt->fetchColumn() == 0) {
    $products = [
        ['Laptop Pro', 'LP001', 'High-performance laptop', 1299.99, 999.99, 'Electronics'],
        ['Wireless Mouse', 'WM001', 'Ergonomic mouse', 29.99, 15.00, 'Electronics'],
        ['Office Chair', 'OC001', 'Ergonomic chair', 399.99, 250.00, 'Furniture'],
        ['Desk Lamp', 'DL001', 'LED desk lamp', 49.99, 25.00, 'Lighting']
    ];
    
    foreach ($products as $product) {
        $stmt = $pdo->prepare("INSERT INTO erp_products (name, product_code, description, unit_price, cost_price, category) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$product[0], $product[1], $product[2], $product[3], $product[4], $product[5]]);
    }
    echo "✓ Added sample products\n";
}

// Add sample erp_customers
$stmt = $pdo->query("SELECT COUNT(*) FROM erp_customers");
if ($stmt->fetchColumn() == 0) {
    $customers = [
        ['ABC Company', 'contact@abc.com', '+1234567890', '123 Main St', 'New York', 'USA'],
        ['XYZ Corp', 'info@xyz.com', '+0987654321', '456 Oak Ave', 'Los Angeles', 'USA'],
        ['Tech Solutions', 'hello@tech.com', '+1122334455', '789 Pine Rd', 'Chicago', 'USA']
    ];
    
    foreach ($customers as $customer) {
        $stmt = $pdo->prepare("INSERT INTO erp_customers (name, email, phone, address, city, country) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute($customer);
    }
    echo "✓ Added sample customers\n";
}

// Add sample erp_quotes
$stmt = $pdo->query("SELECT COUNT(*) FROM erp_quotes");
if ($stmt->fetchColumn() == 0) {
    $stmt = $pdo->query("SELECT id FROM erp_customers LIMIT 3");
    $customerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("SELECT id FROM users LIMIT 2");
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $quotes = [
        ['QUOTE-2024-001', 2500.00, 'sent'],
        ['QUOTE-2024-002', 1800.00, 'accepted'],
        ['QUOTE-2024-003', 3200.00, 'converted']
    ];
    
    foreach ($quotes as $i => $quote) {
        $customerId = $customerIds[$i] ?? $customerIds[0];
        $userId = $userIds[0];
        $date = date('Y-m-d', strtotime('-' . rand(1, 60) . ' days'));
        
        $stmt = $pdo->prepare("INSERT INTO erp_quotes (quote_number, customer_id, total_amount, status, date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$quote[0], $customerId, $quote[1], $quote[2], $date, $userId]);
    }
    echo "✓ Added sample quotes\n";
}

echo "\nAll sales reports tables setup complete!\n";
?>
