<?php
// erp/api/sales_orders.php
require_once '../../includes/functions.php';
require_once '../accounting/journal_entry_service.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;

    // ACTION: Convert Quote to Sales Order
    if ($action === 'convert_quote') {
        if (empty($_POST['quote_id'])) {
            throw new Exception('Quote ID is required');
        }
        $quoteId = $_POST['quote_id'];

        $pdo->beginTransaction();

        // 1. Fetch Quote Data
        $stmt = $pdo->prepare("SELECT * FROM erp_quotes WHERE id = ?");
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch();
        if (!$quote)
            throw new Exception("Quote not found");

        // 2. Create Sales Order Header
        // Generate SO Number (SO/YYYY/001)
        $soNum = 'SO/' . date('Y') . '/' . str_pad($quoteId, 4, '0', STR_PAD_LEFT);

        $sql = "INSERT INTO erp_sales_orders (
            order_number, customer_id, order_date, total_amount, status, invoice_status, created_by
        ) VALUES (?, ?, CURDATE(), ?, 'confirmed', 'to_invoice', ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$soNum, $quote['customer_id'], $quote['total_amount'], $_SESSION['user_id'] ?? 1]);
        $soId = $pdo->lastInsertId();

        // 3. Migrate Items
        $items = $pdo->prepare("SELECT * FROM erp_quote_items WHERE quote_id = ?");
        $items->execute([$quoteId]);
        $quoteItems = $items->fetchAll();

        $sqlItem = "INSERT INTO erp_sales_order_items (
            order_id, product_id, description, quantity, unit_price, total
        ) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtItem = $pdo->prepare($sqlItem);

        // Prep Delivery Logic
        $deliveryItems = [];

        foreach ($quoteItems as $item) {
            $stmtItem->execute([
                $soId,
                $item['product_id'],
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['total']
            ]);
            $deliveryItems[] = $item;
        }

        // 4. Create Delivery Order (Draft -> Ready)
        $doNum = 'WH/OUT/' . date('Y') . '/' . str_pad($soId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO erp_delivery_orders (delivery_number, sales_order_id, customer_id, date, status) VALUES (?, ?, ?, CURDATE(), 'draft')")
            ->execute([$doNum, $soId, $quote['customer_id']]);
        $doId = $pdo->lastInsertId();

        // 5. Create Stock Moves (Reserved)
        $sqlMove = "INSERT INTO erp_stock_moves (
            product_id, quantity, move_type, reference, origin_document, status, date, delivery_order_id
        ) VALUES (?, ?, 'out', ?, ?, 'reserved', CURDATE(), ?)";
        $stmtMove = $pdo->prepare($sqlMove);

        foreach ($deliveryItems as $item) {
            $stmtMove->execute([
                $item['product_id'],
                $item['quantity'],
                $soNum,
                'SO: ' . $soNum,
                $doId
            ]);

            // Logic: Deduct from "Virtual/Reserved" - for now we just track it in moves
        }

        // 6. Update Quote Status
        $pdo->prepare("UPDATE erp_quotes SET status = 'converted' WHERE id = ?")->execute([$quoteId]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Sales Order Created', 'id' => $soId]);
    }

    // ACTION: Create Invoice from SO (Bill logic)
    elseif ($action === 'create_invoice') {
        // ... Similar to quote but from SO ...
    } else {
        throw new Exception("Invalid action");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
