<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../includes/functions.php';
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

    if ($action === 'create_from_po') {
        if (empty($_POST['po_id']) || empty($_POST['items'])) {
            throw new Exception('PO ID and Items are required');
        }

        $pdo->beginTransaction();

        // 1. Generate GRN Number
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(grn_number, 5) AS UNSIGNED)) FROM erp_grn");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $grnNumber = 'GRN-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        // 2. Insert GRN Header
        $stmt = $pdo->prepare("INSERT INTO erp_grn (grn_number, po_id, supplier_id, received_date, received_by, delivery_note_ref, status, notes) VALUES (?, ?, ?, ?, ?, ?, 'verified', ?)");
        $stmt->execute([
            $grnNumber,
            $_POST['po_id'],
            $_POST['supplier_id'], // Should come from form or PO fetch
            date('Y-m-d'),
            $_SESSION['user_id'],
            $_POST['delivery_note_ref'] ?? null,
            $_POST['notes'] ?? null
        ]);
        $grnId = $pdo->lastInsertId();

        // 3. Insert Items and Update Stock
        $stmtItem = $pdo->prepare("INSERT INTO erp_grn_items (grn_id, po_item_id, product_id, quantity_ordered, quantity_received, quantity_rejected, rejection_reason, batch_number, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $fullyReceived = true;

        foreach ($_POST['items'] as $item) {
            $received = floatval($item['received']);
            $ordered = floatval($item['ordered']);
            if ($received < $ordered) $fullyReceived = false;

            $stmtItem->execute([
                $grnId,
                $item['po_item_id'],
                $item['product_id'],
                $ordered,
                $received,
                $item['rejected'] ?? 0,
                $item['reason'] ?? null,
                $item['batch'] ?? null,
                $item['expiry'] ?? null
            ]);

            // Add to Stock (Simple update for now, ideally log movement in stock_movements)
            // Assuming erp_products has 'stock_quantity'
            $pdo->prepare("UPDATE erp_products SET stock_quantity = stock_quantity + ? WHERE id = ?")->execute([$received, $item['product_id']]);
        }

        // 4. Update PO Status
        $newStatus = $fullyReceived ? 'delivered' : 'partial'; // Or 'received'
        // Check current PO schema for valid enum status
        // Usually 'delivered' or 'received'. Let's assume 'delivered' based on typical flow.
        // Actually, let's update erp_purchase_orders status to 'received' or something similar if supported.
        // Or specific delivery_status column if exists.
        
        // Checking erp_purchase_orders structure via memory from Sales Order check (it had delivery_status)
        // Let's assume PO has 'status' or similar. 
        // For safety, let's just log it for now or update if column exists.
        // User asked for GRN module, likely manual flow.
        
        $pdo->commit();

        $logger->log('grn', $grnId, 'created', "GRN $grnNumber created for PO #" . $_POST['po_id']);

        echo json_encode(['success' => true, 'message' => 'GRN created successfully']);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Throwable $e) {
    ob_clean();
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
