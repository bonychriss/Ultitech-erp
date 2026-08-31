<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../includes/functions.php';
require_once '../../config_mail.php';
require_once '../includes/WorkflowEngine.php';
require_once '../includes/ActivityLogger.php';

ob_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    $logger = new ActivityLogger($pdo);

    if ($action === 'create') {
        if (empty($_POST['customer_id']) || empty($_POST['order_date']) || empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Customer, date, and items are required');
        }

        $pdo->beginTransaction();

        // Generate SO number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(order_number, 4) AS UNSIGNED)) FROM erp_sales_orders");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $orderNumber = 'SO-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        // Calculate totals
        $subtotal = 0;
        $taxAmount = 0;

        foreach ($_POST['items'] as $item) {
            $qty = floatval($item['quantity']);
            $price = floatval($item['unit_price']);
            $rate = floatval($item['tax_rate'] ?? 0);

            $lineSub = $qty * $price;
            $lineTax = $lineSub * ($rate / 100);

            $subtotal += $lineSub;
            $taxAmount += $lineTax;
        }

        $total = $subtotal + $taxAmount;

        // Insert Header
        $sql = "INSERT INTO erp_sales_orders (order_number, customer_id, order_date, delivery_date, subtotal, tax_amount, total_amount, status, delivery_status, invoice_status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 'pending', 'not_invoiced', ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $orderNumber,
            $_POST['customer_id'],
            $_POST['order_date'],
            $_POST['delivery_date'] ?? null,
            $subtotal,
            $taxAmount,
            $total,
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        $orderId = $pdo->lastInsertId();

        // Insert Items
        $stmt = $pdo->prepare("INSERT INTO erp_sales_order_items (order_id, product_id, quantity, unit_price, tax_amount, total) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($_POST['items'] as $item) {
            $qty = floatval($item['quantity']);
            $price = floatval($item['unit_price']);
            $rate = floatval($item['tax_rate'] ?? 0);
            $lineSub = $qty * $price;
            $lineTax = $lineSub * ($rate / 100);

            $stmt->execute([
                $orderId,
                $item['product_id'],
                $qty,
                $price,
                $lineTax,
                $lineSub // Total is subtotal in this schema context mainly
            ]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Sales Order created successfully', 'id' => $orderId]);

    } elseif ($action === 'update_status') {
        if (empty($_POST['id']) || empty($_POST['status'])) {
            throw new Exception('ID and Status are required');
        }
        $pdo->prepare("UPDATE erp_sales_orders SET status = ? WHERE id = ?")->execute([$_POST['status'], $_POST['id']]);
        echo json_encode(['success' => true, 'message' => 'Status updated']);

    } elseif ($action === 'convert_to_invoice') {
        if (empty($_POST['id'])) {
            throw new Exception('Order ID is required');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM erp_sales_orders WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $order = $stmt->fetch();

        if (!$order) throw new Exception('Order not found');

        // Check if already invoiced
        if ($order['invoice_status'] === 'invoiced') throw new Exception('Order already invoiced');

        // Generate Invoice Number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) FROM erp_invoices");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $invoiceNumber = 'INV-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        // Create Invoice
        $sql = "INSERT INTO erp_invoices (invoice_number, customer_id, invoice_date, subtotal, tax_rate, tax_amount, total, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)";
        $stmt = $pdo->prepare($sql);
        // Calculate avg tax rate just for the field (approx)
        $avgTaxRate = ($order['subtotal'] > 0) ? ($order['tax_amount'] / $order['subtotal'] * 100) : 0;

        $stmt->execute([
            $invoiceNumber,
            $order['customer_id'],
            date('Y-m-d'),
            $order['subtotal'],
            $avgTaxRate,
            $order['tax_amount'],
            $order['total_amount'],
            $order['notes'],
            $_SESSION['user_id']
        ]);
        $invoiceId = $pdo->lastInsertId();

        // Copy Items
        $items = $pdo->prepare("SELECT * FROM erp_sales_order_items WHERE order_id = ?");
        $items->execute([$order['id']]);
        
        $stmtItem = $pdo->prepare("INSERT INTO erp_invoice_items (invoice_id, product_id, quantity, unit_price, tax_rate, total) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($items->fetchAll() as $item) {
            // Calculate tax rate per item since SO items table stores tax_amount, not rate
            $taxRate = ($item['total'] > 0) ? ($item['tax_amount'] / $item['total'] * 100) : 0;
            
            $stmtItem->execute([
                $invoiceId,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $taxRate,
                $item['total']
            ]);
        }

        // Update Order Status
        $pdo->prepare("UPDATE erp_sales_orders SET invoice_status = 'invoiced' WHERE id = ?")->execute([$order['id']]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Invoice created successfully', 'invoice_id' => $invoiceId]);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Throwable $e) {
    ob_clean();
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
