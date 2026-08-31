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
        if (empty($_POST['customer_id']) || empty($_POST['date']) || empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Customer, date, and items are required');
        }
        
        $pdo->beginTransaction();
        
        // Generate quote number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(quote_number, 4) AS UNSIGNED)) FROM erp_quotes");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $quoteNumber = 'QT-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        
        // Calculate totals
        $subtotal = 0;
        foreach ($_POST['items'] as $item) {
            $subtotal += floatval($item['quantity']) * floatval($item['unit_price']);
        }
        
        $taxRate = floatval($_POST['tax_rate'] ?? 0);
        $taxAmount = $subtotal * ($taxRate / 100);
        $total = $subtotal + $taxAmount;
        
        // Insert quote header
        $sql = "INSERT INTO erp_quotes (quote_number, customer_id, date, expiry_date, subtotal, tax_amount, total_amount, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $quoteNumber,
            $_POST['customer_id'],
            $_POST['date'],
            $_POST['expiry_date'] ?? null,
            $subtotal,
            $taxAmount,
            $total,
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        $quoteId = $pdo->lastInsertId();
        
        // Insert quote items
        $stmt = $pdo->prepare("INSERT INTO erp_quote_items (quote_id, product_id, quantity, unit_price, tax_rate, total) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($_POST['items'] as $item) {
            $qty = floatval($item['quantity']);
            $price = floatval($item['unit_price']);
            $itemTotal = $qty * $price;
            
            $stmt->execute([
                $quoteId,
                $item['product_id'],
                $qty,
                $price,
                $taxRate,
                $itemTotal
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Quote created successfully', 'id' => $quoteId]);
        
    } elseif ($action === 'convert_to_invoice') {
        if (empty($_POST['id'])) {
            throw new Exception('Quote ID is required');
        }
        
        $pdo->beginTransaction();
        
        // Get quote details
        $stmt = $pdo->prepare("SELECT * FROM erp_quotes WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $quote = $stmt->fetch();
        
        if (!$quote) {
            throw new Exception('Quote not found');
        }
        
        if ($quote['status'] === 'converted') {
            throw new Exception('Quote already converted to invoice');
        }
        
        // Generate invoice number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) FROM erp_invoices");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $invoiceNumber = 'INV-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        
        // Create invoice
        $sql = "INSERT INTO erp_invoices (invoice_number, customer_id, invoice_date, subtotal, tax_rate, tax_amount, total, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $invoiceNumber,
            $quote['customer_id'],
            date('Y-m-d'),
            $quote['subtotal'],
            ($quote['tax_amount'] / $quote['subtotal']) * 100,
            $quote['tax_amount'],
            $quote['total_amount'],
            $quote['notes'],
            $_SESSION['user_id']
        ]);
        $invoiceId = $pdo->lastInsertId();
        
        // Copy quote items to invoice items
        $quoteItems = $pdo->prepare("SELECT * FROM erp_quote_items WHERE quote_id = ?");
        $quoteItems->execute([$_POST['id']]);
        
        $stmt = $pdo->prepare("INSERT INTO erp_invoice_items (invoice_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($quoteItems->fetchAll() as $item) {
            $stmt->execute([
                $invoiceId,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['total']
            ]);
        }
        
        // Update quote status
        $stmt = $pdo->prepare("UPDATE erp_quotes SET status = 'converted' WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Quote converted to invoice successfully', 'invoice_id' => $invoiceId]);
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
