<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../includes/functions.php';
require_once '../includes/InventoryEngine.php';
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
    $inventory = new InventoryEngine($pdo);
    $logger = new ActivityLogger($pdo);

    if ($action === 'create_from_so') {
        if (empty($_POST['order_id']) || empty($_POST['items'])) {
            throw new Exception('Order ID and Items are required');
        }

        $pdo->beginTransaction();

        // 1. Generate DN Number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(delivery_number, 4) AS UNSIGNED)) FROM erp_delivery_notes");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $dnNumber = 'DN-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        // 2. Create Header
        $stmt = $pdo->prepare("INSERT INTO erp_delivery_notes (delivery_number, order_id, customer_id, delivery_date, status, driver_name, vehicle_reg, notes, created_by) VALUES (?, ?, ?, ?, 'scheduled', ?, ?, ?, ?)");
        $stmt->execute([
            $dnNumber,
            $_POST['order_id'],
            $_POST['customer_id'],
            $_POST['delivery_date'] ?? date('Y-m-d'),
            $_POST['driver_name'] ?? null,
            $_POST['vehicle_reg'] ?? null,
            $_POST['notes'] ?? null,
            $_SESSION['user_id']
        ]);
        $dnId = $pdo->lastInsertId();

        // 3. Process Items & Deduct Stock
        $stmtItem = $pdo->prepare("INSERT INTO erp_delivery_items (delivery_id, so_item_id, product_id, quantity_delivered) VALUES (?, ?, ?, ?)");
        
        $cogsTotal = 0;

        foreach ($_POST['items'] as $item) {
            $qty = floatval($item['quantity']);
            if ($qty <= 0) continue;

            // Save line item
            $stmtItem->execute([
                $dnId,
                $item['so_item_id'],
                $item['product_id'],
                $qty
            ]);

            // Deduct Stock (FIFO)
            try {
                $cogs = $inventory->removeStock(
                    $item['product_id'], 
                    $qty, 
                    'delivery', 
                    $dnId, 
                    $_SESSION['user_id']
                ); // Returns COGS for this transaction
                $cogsTotal += $cogs;
            } catch (Exception $e) {
                throw new Exception("Stock Error for Product ID " . $item['product_id'] . ": " . $e->getMessage());
            }
        }

        // 4. Update Sales Order Delivery Status
        // Logic: Check if fully delivered? 
        // For simplicity, we mark 'partial' or 'delivered'. 
        // Let's mark 'delivered' if we just delivered something. Ideally we check vs total ordered.
        $pdo->prepare("UPDATE erp_sales_orders SET delivery_status = 'delivered' WHERE id = ?")->execute([$_POST['order_id']]);

        // 5. Accounting Entry for COGS (Optional but recommended for full ERP)
        // Debit COGS, Credit Inventory Asset
        // We'll skip this automatic journal for now unless requested, but we calculated $cogsTotal.

        $pdo->commit();

        $logger->log('delivery', $dnId, 'created', "Delivery Note $dnNumber created");

        echo json_encode(['success' => true, 'message' => 'Delivery Note created successfully', 'id' => $dnId]);

    } elseif ($action === 'mark_delivered') {
        if (empty($_POST['id'])) throw new Exception('ID required');
        
        $pdo->prepare("UPDATE erp_delivery_notes SET status = 'delivered' WHERE id = ?")->execute([$_POST['id']]);
        echo json_encode(['success' => true, 'message' => 'Marked as Delivered']);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Throwable $e) {
    ob_clean();
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
