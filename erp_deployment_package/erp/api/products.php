<?php
require_once '../../includes/functions.php';
requireLogin();

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
        if (empty($_POST['name']) || empty($_POST['unit_price'])) {
            throw new Exception('Product name and price are required');
        }
        
        // Check for duplicate SKU
        $stmt = $pdo->prepare("SELECT id FROM erp_products WHERE sku = ?");
        $stmt->execute([$_POST['sku']]);
        if ($stmt->fetch()) {
            throw new Exception('Product SKU already exists');
        }
        
        // Insert product
        $sql = "INSERT INTO erp_products (
            sku, name, description, category_id, unit, 
            unit_price, cost_price, stock_quantity, reorder_level, barcode, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['sku'],
            $_POST['name'],
            $_POST['description'] ?? null,
            !empty($_POST['category_id']) ? $_POST['category_id'] : null,
            $_POST['unit'] ?? 'pcs',
            floatval($_POST['unit_price']),
            floatval($_POST['cost_price'] ?? 0),
            floatval($_POST['stock_quantity'] ?? 0),
            floatval($_POST['reorder_level'] ?? 0),
            $_POST['barcode'] ?? null,
            $_SESSION['user_id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Product created successfully', 'id' => $pdo->lastInsertId()]);
        
    } elseif ($action === 'update') {
        // Validate required fields
        if (empty($_POST['id']) || empty($_POST['name']) || empty($_POST['unit_price'])) {
            throw new Exception('Product ID, name and price are required');
        }
        
        $sql = "UPDATE erp_products SET 
            name = ?, description = ?, category_id = ?, unit = ?, 
            unit_price = ?, cost_price = ?, stock_quantity = ?, reorder_level = ?, barcode = ?, status = ?
            WHERE id = ?";
            
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['name'],
            $_POST['description'] ?? null,
            !empty($_POST['category_id']) ? $_POST['category_id'] : null,
            $_POST['unit'] ?? 'pcs',
            floatval($_POST['unit_price']),
            floatval($_POST['cost_price'] ?? 0),
            floatval($_POST['stock_quantity'] ?? 0),
            floatval($_POST['reorder_level'] ?? 0),
            $_POST['barcode'] ?? null,
            $_POST['status'] ?? 'active',
            $_POST['id']
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
        
    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('Product ID is required');
        }
        
        // Check if product is used in invoices
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_invoice_items WHERE product_id = ?");
        $stmt->execute([$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete product that has been sold. Mark as inactive instead.');
        }
        
        $stmt = $pdo->prepare("DELETE FROM erp_products WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
