<?php
require_once 'includes/functions.php';

echo "<h1>Local Database Setup</h1>";

try {
    global $pdo;
    
    // Create erp_quotes table
    echo "<p>Creating erp_quotes table...</p>";
    $pdo->exec("DROP TABLE IF EXISTS `erp_quotes`");
    
    $sql = "CREATE TABLE `erp_quotes` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `quote_number` varchar(50) NOT NULL UNIQUE,
      `customer_id` int(11) NOT NULL,
      `quote_date` date NOT NULL,
      `expiry_date` date DEFAULT NULL,
      `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
      `status` enum('draft','sent','accepted','rejected','converted') DEFAULT 'draft',
      `notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `customer_id` (`customer_id`),
      CONSTRAINT `fk_quote_customer` FOREIGN KEY (`customer_id`) REFERENCES `erp_customers` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "<p style='color:green'>✓ Table 'erp_quotes' created successfully.</p>";
    
    echo "<h3 style='color:green'>✓ Done!</h3>";
    echo "<p><a href='erp/sales/quotes.php' style='background:#1a73e8;color:white;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;'>Go to Quotations</a></p>";

} catch (PDOException $e) {
    echo "<p style='color:red'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>
