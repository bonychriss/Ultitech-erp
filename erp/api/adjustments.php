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
        if (empty($_POST['date']) || empty($_POST['reason'])) {
            throw new Exception('Date and reason are required');
        }
        
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('At least one item is required');
        }
        
        $pdo->beginTransaction();
        
        // Insert Adjustment
        $sql = "INSERT INTO erp_inventory_adjustments (
            adjustment_number, date, reason, notes, created_by
        ) VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['adjustment_number'],
            $_POST['date'],
            $_POST['reason'],
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        
        $adjId = $pdo->lastInsertId();
        
        // Insert Items and Update Stock
        $sqlItem = "INSERT INTO erp_inventory_adjustment_items (
            adjustment_id, product_id, quantity_change
        ) VALUES (?, ?, ?)";
        
        $stmtItem = $pdo->prepare($sqlItem);
        $stmtStock = $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $stmtLog = $pdo->prepare("INSERT INTO erp_stock_movements (product_id, type, quantity, reference_type, reference_id, created_by) VALUES (?, 'adjustment', ?, 'adjustment', ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            $change = floatval($item['quantity_change']);
            
            // Insert item
            $stmtItem->execute([
                $adjId,
                $item['product_id'],
                $change
            ]);
            
            // Update stock
            $stmtStock->execute([$change, $item['product_id']]);
            
            // Log movement
            $stmtLog->execute([
                $item['product_id'],
                abs($change), // Quantity is always positive in movement log, type handles direction? 
                              // Wait, schema says type enum('in','out','adjustment'). 
                              // Usually adjustment can be + or -. 
                              // Let's store the absolute quantity and rely on the adjustment record for direction if needed, 
                              // or maybe I should have 'in'/'out' based on sign.
                              // Let's stick to 'adjustment' type and maybe store signed quantity? 
                              // The schema for stock_movements has `quantity decimal(10,2)`. Usually unsigned.
                              // Let's use 'adjustment' and store the absolute value, but maybe I should have added a sign column or used in/out.
                              // For now, I'll just store absolute value. The adjustment record has the signed change.
                $adjId,
                $_SESSION['user_id']
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Adjustment saved successfully']);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
