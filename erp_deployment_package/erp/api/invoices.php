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
        // Validate
        if (empty($_POST['customer_id']) || empty($_POST['invoice_date'])) {
            throw new Exception('Customer and date are required');
        }
        
        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('At least one item is required');
        }
        
        $pdo->beginTransaction();
        
        // Calculate totals
        $subtotal = 0;
        foreach ($_POST['items'] as $item) {
            $subtotal += floatval($item['total']);
        }
        
        $taxRate = floatval($_POST['tax_rate'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $total = $subtotal + $taxAmount;
        
        // Insert Invoice
        $sql = "INSERT INTO erp_invoices (
            invoice_number, customer_id, invoice_date, due_date, 
            subtotal, tax_rate, tax_amount, total, balance, 
            notes, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['invoice_number'],
            $_POST['customer_id'],
            $_POST['invoice_date'],
            $_POST['due_date'] ?? null,
            $subtotal,
            $taxRate,
            $taxAmount,
            $total,
            $total, // Initial balance = total
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        
        $invoiceId = $pdo->lastInsertId();
        
        // Insert Items
        $sqlItem = "INSERT INTO erp_invoice_items (
            invoice_id, product_id, description, quantity, unit_price, total
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmtItem = $pdo->prepare($sqlItem);
        
        foreach ($_POST['items'] as $item) {
            $stmtItem->execute([
                $invoiceId,
                !empty($item['product_id']) ? $item['product_id'] : null,
                $item['description'],
                floatval($item['quantity']),
                floatval($item['unit_price']),
                floatval($item['total'])
            ]);
            
            // Update stock if product exists
            if (!empty($item['product_id'])) {
                $stmtStock = $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $stmtStock->execute([floatval($item['quantity']), $item['product_id']]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Invoice created successfully', 'id' => $invoiceId]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
