<?php
// erp/api/inventory.php
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

    if ($action === 'validate_delivery') {
        if (empty($_POST['id']))
            throw new Exception("ID required");
        $id = $_POST['id'];

        $pdo->beginTransaction();

        // 1. Fetch Delivery
        $stmt = $pdo->prepare("SELECT * FROM erp_delivery_orders WHERE id = ?");
        $stmt->execute([$id]);
        $del = $stmt->fetch();

        if ($del['status'] !== 'draft')
            throw new Exception("Delivery already processed");

        // 2. Fetch Moves
        $stmtMoves = $pdo->prepare("SELECT * FROM erp_stock_moves WHERE delivery_order_id = ?");
        $stmtMoves->execute([$id]);
        $moves = $stmtMoves->fetchAll();

        // 3. Process each move
        foreach ($moves as $move) {
            // A. Deduct Physical Stock
            $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity - ? WHERE id = ?")
                ->execute([$move['quantity'], $move['product_id']]);

            // B. Update Move Status
            $pdo->prepare("UPDATE erp_stock_moves SET status = 'done' WHERE id = ?")
                ->execute([$move['id']]);
        }

        // 4. Update Delivery Status
        $pdo->prepare("UPDATE erp_delivery_orders SET status = 'done' WHERE id = ?")->execute([$id]);

        // 5. Trigger Accounting (COGS)
        $jeService = new JournalEntryService($pdo);
        $jeService->postDelivery($id);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } else {
        throw new Exception("Invalid action");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
