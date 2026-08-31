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
        // Validate
        if (empty($_POST['supplier_id']) || empty($_POST['order_date'])) {
            throw new Exception('Supplier and date are required');
        }
        
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('At least one item is required');
        }
        
        $pdo->beginTransaction();
        
        // Calculate total
        $total = 0;
        foreach ($_POST['items'] as $item) {
            $total += floatval($item['total']);
        }
        
        // Insert PO
        $sql = "INSERT INTO erp_purchase_orders (
            po_number, supplier_id, order_date, expected_date, 
            total_amount, notes, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['po_number'],
            $_POST['supplier_id'],
            $_POST['order_date'],
            $_POST['expected_date'] ?? null,
            $total,
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        
        $poId = $pdo->lastInsertId();
        
        // Insert Items
        $sqlItem = "INSERT INTO erp_purchase_order_items (
            po_id, product_id, quantity, unit_price, total
        ) VALUES (?, ?, ?, ?, ?)";
        
        $stmtItem = $pdo->prepare($sqlItem);
        
        foreach ($_POST['items'] as $item) {
            $stmtItem->execute([
                $poId,
                $item['product_id'],
                floatval($item['quantity']),
                floatval($item['unit_price']),
                floatval($item['total'])
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Purchase Order created successfully', 'id' => $poId]);
        
    } elseif ($action === 'receive') {
        // Logic to receive goods and update stock
        if (empty($_POST['id'])) {
            throw new Exception('PO ID is required');
        }
        
        $pdo->beginTransaction();
        
        // Update PO status
        $stmt = $pdo->prepare("UPDATE erp_purchase_orders SET status = 'received' WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        // Get items to update stock
        $stmt = $pdo->prepare("SELECT product_id, quantity FROM erp_purchase_order_items WHERE po_id = ?");
        $stmt->execute([$_POST['id']]);
        $items = $stmt->fetchAll();
        
        // Update stock and log movement
        $stmtStock = $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $stmtLog = $pdo->prepare("INSERT INTO erp_stock_movements (product_id, type, quantity, reference_type, reference_id, created_by) VALUES (?, 'in', ?, 'purchase_order', ?, ?)");
        
        foreach ($items as $item) {
            // Update stock
            $stmtStock->execute([$item['quantity'], $item['product_id']]);
            
            // Log movement
            $stmtLog->execute([
                $item['product_id'],
                $item['quantity'],
                $_POST['id'],
                $_SESSION['user_id']
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Goods received and stock updated']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
