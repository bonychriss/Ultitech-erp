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

    if ($action === 'create') {
        // Validate required fields
        if (empty($_POST['name'])) {
            throw new Exception('Customer name is required');
        }

        // Check for duplicate code
        $stmt = $pdo->prepare("SELECT id FROM erp_customers WHERE customer_code = ?");
        $stmt->execute([$_POST['customer_code']]);
        if ($stmt->fetch()) {
            throw new Exception('Customer code already exists');
        }

        // Insert customer with explicit status
        $sql = "INSERT INTO erp_customers (
            customer_code, name, email, phone, address, city, country, 
            tax_id, credit_limit, notes, created_by, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";

        $userId = $_SESSION['user_id'] ?? null;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['customer_code'],
            $_POST['name'],
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['address'] ?? null,
            $_POST['city'] ?? null,
            $_POST['country'] ?? 'Tanzania',
            $_POST['tax_id'] ?? null,
            floatval($_POST['credit_limit'] ?? 0),
            $_POST['notes'] ?? null,
            $userId
        ]);

        echo json_encode(['success' => true, 'message' => 'Customer created successfully', 'id' => $pdo->lastInsertId()]);

    } elseif ($action === 'update') {
        // Validate required fields
        if (empty($_POST['id']) || empty($_POST['name'])) {
            throw new Exception('Customer ID and name are required');
        }

        $sql = "UPDATE erp_customers SET 
            name = ?, email = ?, phone = ?, address = ?, city = ?, 
            country = ?, tax_id = ?, credit_limit = ?, status = ?, notes = ?
            WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['name'],
            $_POST['email'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['address'] ?? null,
            $_POST['city'] ?? null,
            $_POST['country'] ?? 'Tanzania',
            $_POST['tax_id'] ?? null,
            floatval($_POST['credit_limit'] ?? 0),
            $_POST['status'] ?? 'active',
            $_POST['notes'] ?? null,
            $_POST['id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);

    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('Customer ID is required');
        }

        // Check if customer has invoices
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_invoices WHERE customer_id = ?");
        $stmt->execute([$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete customer with existing invoices');
        }

        $stmt = $pdo->prepare("DELETE FROM erp_customers WHERE id = ?");
        $stmt->execute([$_POST['id']]);

        echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
