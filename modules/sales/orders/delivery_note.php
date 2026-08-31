<?php
// modules/sales/orders/delivery_note.php
// Acts as a bridge to the main Deliveries Module

require_once '../../../includes/config.php';
// require_once '../../../includes/auth.php';
// checkAuthentication('sales'); // Ensure user has permission

$order_id = $_GET['id'] ?? 0;

if (!$order_id) {
    die("Invalid Order ID");
}

try {
    // 1. Fetch Order Data REQUIRED for Sync
    $stmtOrder = $pdo->prepare("SELECT * FROM sales_orders WHERE id = ?");
    $stmtOrder->execute([$order_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("Sales Order not found.");
    }
    
    // Fetch Customer Data
    $stmtCust = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmtCust->execute([$order['customer_id']]);
    $customer = $stmtCust->fetch(PDO::FETCH_ASSOC);

    // Fetch Items & Generate JSON (Now includes Images)
    $stmtItems = $pdo->prepare("SELECT soi.*, p.product_code, p.name as product_name, COALESCE(p.main_image, p.image) AS main_image, p.id as product_id, soi.description as item_description 
                                FROM sales_order_items soi 
                                LEFT JOIN products p ON soi.product_id = p.id 
                                WHERE soi.order_id = ?");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $deliveryItemsMap = []; // Keyed by unique signature

    foreach ($items as $item) {
        $pid = $item['product_id'] ?? '0';
        
        // Normalize description for comparison
        $rawDesc = !empty($item['item_description']) ? $item['item_description'] : ($item['product_name'] ?? 'Unknown Item');
        $desc = trim(preg_replace('/\s+/', ' ', str_replace('&nbsp;', ' ', $rawDesc)));
        
        // Create unique key for deduplication (Product ID + Normalized Description)
        $key = $pid . '_' . md5(strtolower($desc)); 
        
        if (isset($deliveryItemsMap[$key])) {
            // Merge quantity
            $deliveryItemsMap[$key]['qty'] += (float)$item['quantity'];
        } else {
            $deliveryItemsMap[$key] = [
                'sku' => $item['product_code'] ?? '',
                'description' => $desc, 
                'qty' => (float)$item['quantity'],
                'unit' => $item['unit'] ?? 'pckge',
                'product_id' => $item['product_id'],
                'main_image' => $item['main_image'] ?? ''
            ];
        }
    }

    // Re-index array
    $deliveryItems = array_values($deliveryItemsMap);
    $itemsJson = json_encode($deliveryItems);

    // 2. Refresh logic: Check if Note Exists
    $stmt = $pdo->prepare("SELECT id FROM delivery_notes WHERE order_id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $existingNoteId = $stmt->fetchColumn();

    if ($existingNoteId) {
        // UPDATE existing note with latest items/address
        $stmtUpdate = $pdo->prepare("UPDATE delivery_notes SET items_json = ?, customer_name = ?, delivery_address = ?, created_by = ? WHERE id = ?");
        $stmtUpdate->execute([
            $itemsJson,
            $customer['company_name'] ?? 'Unknown Customer',
            $customer['address'] ?? ($order['address'] ?? ''),
            $_SESSION['user_id'] ?? 1,
            $existingNoteId
        ]);
        
        header("Location: ../../../deliveries/view_delivery_note.php?id=" . $existingNoteId . "&v=final");
        exit;
    }

    // 3. Create NEW Note logic (If not exists)
    $note_number = 'DN-' . date('y') . str_pad($order_id, 4, '0', STR_PAD_LEFT);
    $userId = $_SESSION['user_id'] ?? 1;
    $stmtSig = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
    $stmtSig->execute([$userId]);
    $signature = $stmtSig->fetchColumn();

    $sqlInsert = "INSERT INTO delivery_notes 
                  (note_number, customer_name, customer_phone, delivery_address, delivery_date, items_json, created_by, authorized_signature_path, order_id) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtInsert->execute([
        $note_number,
        $customer['company_name'] ?? 'Unknown Customer',
        $customer['phone'] ?? '',
        $customer['address'] ?? ($order['address'] ?? ''),
        date('Y-m-d'),
        $itemsJson,
        $userId,
        $signature,
        $order_id
    ]);

    $newId = $pdo->lastInsertId();
    header("Location: ../../../deliveries/view_delivery_note.php?id=" . $newId . "&v=final");
    exit;

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
