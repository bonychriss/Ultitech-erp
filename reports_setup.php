<?php
require_once 'includes/config.php';

echo "Checking existing tables...\n";
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Existing tables:\n";
foreach ($tables as $table) {
    echo "- $table\n";
}

if (!in_array('products', $tables)) {
    echo "\nCreating products table...\n";
    
    $sql = "CREATE TABLE `products` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL,
        `product_code` varchar(100) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `category_id` int(11) DEFAULT NULL,
        `unit_price` decimal(15,2) DEFAULT 0.00,
        `cost_price` decimal(15,2) DEFAULT 0.00,
        `reorder_level` int(11) DEFAULT 0,
        `image` varchar(255) DEFAULT NULL,
        `status` enum('active','inactive') DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `product_code` (`product_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    try {
        $pdo->exec($sql);
        echo "✓ Products table created successfully\n";
    } catch (PDOException $e) {
        echo "✗ Error creating products table: " . $e->getMessage() . "\n";
    }
} else {
    echo "\n✓ Products table already exists\n";
}

if (!in_array('stock', $tables)) {
    echo "\nCreating stock table...\n";
    
    $sql = "CREATE TABLE `stock` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `quantity` int(11) DEFAULT 0,
        `min_quantity` int(11) DEFAULT 0,
        `max_quantity` int(11) DEFAULT 0,
        `location` varchar(100) DEFAULT NULL,
        `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `product_id` (`product_id`),
        FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    try {
        $pdo->exec($sql);
        echo "✓ Stock table created successfully\n";
    } catch (PDOException $e) {
        echo "✗ Error creating stock table: " . $e->getMessage() . "\n";
    }
} else {
    echo "\n✓ Stock table already exists\n";
}

echo "\nSetup complete!\n";
?>
