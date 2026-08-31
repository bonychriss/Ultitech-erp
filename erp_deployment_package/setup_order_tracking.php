<?php
require_once 'includes/config.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS `order_tracking` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `client` varchar(255) NOT NULL,
      `mobile` varchar(50) DEFAULT NULL,
      `shipment_no` varchar(100) NOT NULL,
      `shipment_status` varchar(50) DEFAULT 'Pending',
      `tracking_status` varchar(50) DEFAULT 'NA',
      `packages` int(11) DEFAULT 0,
      `cbm` decimal(10,3) DEFAULT 0.000,
      `total_value` decimal(15,2) DEFAULT 0.00,
      `description` text DEFAULT NULL,
      `shipment_date` date DEFAULT NULL,
      `etd` date DEFAULT NULL,
      `eta` date DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "Table 'order_tracking' created successfully.";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>
