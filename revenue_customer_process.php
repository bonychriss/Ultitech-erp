<?php
require_once 'includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $moduleQs = '';
    if (!empty($_POST['module']) && is_string($_POST['module'])) {
        $moduleQs = '&module=' . rawurlencode(trim($_POST['module']));
    }

    if ($_POST['action'] === 'create_customer') {
        $name = trim($_POST['customer_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);

        if (empty($name)) {
            header('Location: revenue_customer_create.php?error=' . rawurlencode('Name is required') . $moduleQs);
            exit();
        }

        try {
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS `revenue_customers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `customer_name` varchar(255) NOT NULL,
              `phone` varchar(50) DEFAULT NULL,
              `email` varchar(100) DEFAULT NULL,
              `address` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $stmt = $pdo->prepare("INSERT INTO revenue_customers (customer_name, phone, email, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $email, $address]);
            header('Location: revenue_customers.php?success=' . rawurlencode('Customer created') . $moduleQs);
        } catch (PDOException $e) {
            header('Location: revenue_customer_create.php?error=' . rawurlencode($e->getMessage()) . $moduleQs);
        }
        exit();
    }

    if ($_POST['action'] === 'edit_customer') {
        $id = intval($_POST['id']);
        $name = trim($_POST['customer_name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);

        try {
            $stmt = $pdo->prepare("UPDATE revenue_customers SET customer_name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $email, $address, $id]);
            header('Location: revenue_customers.php?success=' . rawurlencode('Customer updated') . $moduleQs);
        } catch (PDOException $e) {
            header('Location: revenue_customer_edit.php?id=' . $id . '&error=' . rawurlencode($e->getMessage()) . $moduleQs);
        }
        exit();
    }

    if ($_POST['action'] === 'delete_customer' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM revenue_customers WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: revenue_customers.php?success=' . rawurlencode('Customer deleted') . $moduleQs);
        } catch (PDOException $e) {
            header('Location: revenue_customers.php?error=' . rawurlencode($e->getMessage()) . $moduleQs);
        }
        exit();
    }
}
?>
