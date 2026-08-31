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
        if (empty($_POST['customer_id']) || empty($_POST['date']) || empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Customer, date, and items are required');
        }
        
        $pdo->beginTransaction();
        
        // Generate delivery note number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(delivery_number, 4) AS UNSIGNED)) FROM erp_delivery_notes");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $dnNumber = 'DN-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        
        // Insert delivery note header
        $sql = "INSERT INTO erp_delivery_notes (delivery_number, invoice_id, customer_id, date, status, shipping_address, driver_name, vehicle_number, notes, created_by) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $dnNumber,
            $_POST['invoice_id'] ?? null,
            $_POST['customer_id'],
            $_POST['date'],
            $_POST['shipping_address'] ?? null,
            $_POST['driver_name'] ?? null,
            $_POST['vehicle_number'] ?? null,
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        $dnId = $pdo->lastInsertId();
        
        // Insert delivery items
        $stmt = $pdo->prepare("INSERT INTO erp_delivery_items (delivery_id, product_id, quantity, batch_number) VALUES (?, ?, ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            $stmt->execute([
                $dnId,
                $item['product_id'],
                $item['quantity'],
                $item['batch_number'] ?? null
            ]);
            
            // Update inventory if batch tracking is enabled
            if (!empty($item['batch_number'])) {
                $updateStmt = $pdo->prepare("UPDATE erp_inventory_batches SET quantity = quantity - ? WHERE product_id = ? AND batch_number = ?");
                $updateStmt->execute([
                    $item['quantity'],
                    $item['product_id'],
                    $item['batch_number']
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Delivery note created successfully', 'id' => $dnId]);
        
    } elseif ($action === 'update_status') {
        if (empty($_POST['id']) || empty($_POST['status'])) {
            throw new Exception('ID and status are required');
        }
        
        $sql = "UPDATE erp_delivery_notes SET status = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$_POST['status'], $_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Delivery status updated successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
