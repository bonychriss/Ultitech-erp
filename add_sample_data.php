<?php
require_once 'includes/config.php';

echo "Adding sample data for products and stock...\n";

// Insert sample products
$products = [
    ['name' => 'Laptop Computer', 'product_code' => 'LAP001', 'description' => 'High-performance laptop', 'unit_price' => 899.99, 'cost_price' => 650.00, 'reorder_level' => 5],
    ['name' => 'Wireless Mouse', 'product_code' => 'MOU001', 'description' => 'Ergonomic wireless mouse', 'unit_price' => 29.99, 'cost_price' => 15.00, 'reorder_level' => 20],
    ['name' => 'USB Keyboard', 'product_code' => 'KEY001', 'description' => 'Mechanical keyboard', 'unit_price' => 79.99, 'cost_price' => 45.00, 'reorder_level' => 15],
    ['name' => 'Monitor 24"', 'product_code' => 'MON001', 'description' => '24-inch LED monitor', 'unit_price' => 199.99, 'cost_price' => 120.00, 'reorder_level' => 8],
    ['name' => 'Office Chair', 'product_code' => 'CHA001', 'description' => 'Ergonomic office chair', 'unit_price' => 249.99, 'cost_price' => 150.00, 'reorder_level' => 3],
    ['name' => 'Desk Lamp', 'product_code' => 'LAM001', 'description' => 'LED desk lamp', 'unit_price' => 39.99, 'cost_price' => 20.00, 'reorder_level' => 25],
    ['name' => 'Printer Paper', 'product_code' => 'PAP001', 'description' => 'A4 printer paper pack', 'unit_price' => 12.99, 'cost_price' => 8.00, 'reorder_level' => 50],
    ['name' => 'Pen Set', 'product_code' => 'PEN001', 'description' => 'Assorted pen set', 'unit_price' => 9.99, 'cost_price' => 4.00, 'reorder_level' => 30]
];

foreach ($products as $product) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO products (name, product_code, description, unit_price, cost_price, reorder_level) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$product['name'], $product['product_code'], $product['description'], $product['unit_price'], $product['cost_price'], $product['reorder_level']]);
        echo "âœ“ Added product: " . $product['name'] . "\n";
    } catch (PDOException $e) {
        echo "âœ— Error adding product " . $product['name'] . ": " . $e->getMessage() . "\n";
    }
}

// Insert corresponding stock records
$stmt = $pdo->query("SELECT id FROM products");
$productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($productIds as $productId) {
    $quantity = rand(1, 100); // Random stock quantity
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO stock (product_id, quantity, min_quantity, max_quantity) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productId, $quantity, 5, 200]);
        echo "âœ“ Added stock for product ID: $productId (Quantity: $quantity)\n";
    } catch (PDOException $e) {
        echo "âœ— Error adding stock for product ID $productId: " . $e->getMessage() . "\n";
    }
}

echo "\nSample data setup complete!\n";
echo "You can now access the reports page at: http://localhost/reports/index.php\n";
?>
