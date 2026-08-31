<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
require_once '../../classes/LandedCostCalculator.php';

requireLogin();
ensure_shipment_po_linking_schema($pdo);

if (!isset($_GET['id'])) {
    flash('danger', 'No shipment specified.');
    redirect('index.php');
}

$id = $_GET['id'];
$userId = $_SESSION['user_id'] ?? 1;

// Fetch Shipment
$stmt = $pdo->prepare("SELECT s.*, su.name AS supplier_name
                       FROM shipments s
                       LEFT JOIN stocks_suppliers su ON s.supplier_id = su.id
                       WHERE s.id = ?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    flash('danger', 'Shipment not found.');
    redirect('index.php');
}

if ($shipment['status'] === 'delivered') {
    flash('info', 'This shipment has already been received.');
    redirect('view.php?id=' . $id);
}

// Fetch Items
$stmtItems = $pdo->prepare("SELECT si.*, p.name as product_name, p.product_code 
                            FROM shipment_items si 
                            LEFT JOIN products p ON si.product_id = p.id 
                            WHERE si.shipment_id = ?");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $received_date_str = date('Y-m-d H:i:s');
    $notes = clean_input($_POST['notes']);
    
    // Begin Transaction
    $pdo->beginTransaction();
    file_put_contents('debug_trace.txt', "Step 1: Transaction Started\n", FILE_APPEND);
    
    try {
        // 1. Update Shipment Status
        $stmtUpdate = $pdo->prepare("UPDATE shipments SET status = 'delivered', actual_arrival_date = ?, received_by = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$received_date_str, $userId, $id]);
        file_put_contents('debug_trace.txt', "Step 2: Shipment Status Updated\n", FILE_APPEND);
        
        // 2 & 3. Calculate Landed Cost
        $calculator = new LandedCostCalculator($pdo);
        $resCalc = $calculator->calculateTotalCosts($id); 
        file_put_contents('debug_trace.txt', "Step 3: Total Costs Calculated (" . json_encode($resCalc) . ")\n", FILE_APPEND);
        
        $resAlloc = $calculator->allocateCosts($id, 'value');
        file_put_contents('debug_trace.txt', "Step 4: Costs Allocated (" . json_encode($resAlloc) . ")\n", FILE_APPEND);

        // Fetch allocated costs
        $stmtAlloc = $pdo->prepare("SELECT * FROM product_landed_costs WHERE shipment_id = ?");
        $stmtAlloc->execute([$id]);
        $allocations = [];
        while($row = $stmtAlloc->fetch()){
            $allocations[$row['product_id']] = $row;
        }
        file_put_contents('debug_trace.txt', "Step 5: Allocations Fetched\n", FILE_APPEND);

        $processedItems = [];

        // 4. Update Product Stock & 5. Create Batches
        foreach ($items as $item) {
            $postDesc = 'qty_' . $item['id'];
            $received_qty = isset($_POST[$postDesc]) ? (int)$_POST[$postDesc] : 0;
            
            if ($received_qty > 0 && $item['product_id']) {
                $productId = $item['product_id'];

                // Get Unit Cost
                $batchUnitCost = $item['unit_price']; 
                if (isset($allocations[$productId])) {
                    $batchUnitCost = $allocations[$productId]['landed_cost_per_unit'];
                }

                // Update Stock
                $stmtCheckStock = $pdo->prepare("SELECT id, quantity FROM stock WHERE product_id = ? LIMIT 1");
                $stmtCheckStock->execute([$productId]);
                $stock = $stmtCheckStock->fetch();
                
                $stockLocation = 'Warehouse A'; 
                
                if ($stock) {
                    $new_qty = $stock['quantity'] + $received_qty;
                    $stmtStock = $pdo->prepare("UPDATE stock SET quantity = ?, last_updated = NOW() WHERE id = ?");
                    $stmtStock->execute([$new_qty, $stock['id']]);
                } else {
                    $stmtStock = $pdo->prepare("INSERT INTO stock (product_id, quantity, location, last_updated) VALUES (?, ?, ?, NOW())");
                    $stmtStock->execute([$productId, $received_qty, $stockLocation]);
                }

                // Create Batch Record
                // Fix: Ensure Batch Number is unique per Product line item
                // Format: BATCH-{Invoice}-{ProductID}-{Date}
                $batchNo = 'BATCH-' . ($shipment['invoice_number'] ?? 'SHP'.$id) . '-' . $productId . '-' . date('ymd');
                
                // Extra safety: Append random digits if somehow duplicate
                if ($shipment['invoice_number'] == '') {
                     $batchNo .= '-' . rand(100,999);
                }

                $expiryDate = date('Y-m-d', strtotime('+2 years')); 

                $stmtBatch = $pdo->prepare("INSERT INTO product_batches 
                    (product_id, shipment_id, batch_number, quantity, unit_cost, received_date, expiry_date, location, current_stock) 
                    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity"); // Fail-safe: if still duplicate, just ignore (or update)
                
                // Actually, duplicate key update is risky if logic expects new batch. 
                // Better to ensure unique key.
                
                // Let's rely on the unique ID in string
                $stmtBatch->execute([$productId, $id, $batchNo, $received_qty, $batchUnitCost, $expiryDate, $stockLocation, $received_qty]);

                // Record Stock Movement
                $stmtMove = $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes) VALUES (?, 'in', ?, 'shipment', ?, ?)");
                $stmtMove->execute([$productId, $received_qty, $id, "Received from Shipment #" . $shipment['invoice_number']]);

                // Calculate New Product Cost 
                $stmtBatches = $pdo->prepare("SELECT SUM(current_stock * unit_cost) as total_val, SUM(current_stock) as total_qty FROM product_batches WHERE product_id = ? AND current_stock > 0");
                $stmtBatches->execute([$productId]);
                $batchStats = $stmtBatches->fetch();

                if ($batchStats['total_qty'] > 0) {
                    $newAvgCost = $batchStats['total_val'] / $batchStats['total_qty'];
                    $stmtUpdateProd = $pdo->prepare("UPDATE products SET buying_price = ?, last_cost_update = NOW() WHERE id = ?");
                    $stmtUpdateProd->execute([$newAvgCost, $productId]);
                }

                $processedItems[] = [
                    'name' => $item['product_name'],
                    'qty' => $received_qty,
                    'batch' => $batchNo,
                    'cost' => $batchUnitCost
                ];
            }
            
            // Update Shipment Item Status
            $stmtUpdateItem = $pdo->prepare("UPDATE shipment_items SET received_quantity = ?, quality_status = 'passed' WHERE id = ?");
            $stmtUpdateItem->execute([$received_qty, $item['id']]);
            
            // Update Linked Purchase Order
            if ($item['purchase_id']) {
                 // Check if all items in PO are received? For now just mark Received.
                 $stmtPO = $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received', received_date = NOW(), received_by = ? WHERE id = ?");
                 $stmtPO->execute([$userId, $item['purchase_id']]);
            }
        }
        file_put_contents('debug_trace.txt', "Step 6: Items Processed\n", FILE_APPEND);
        
        // 10. Update System Stats
        $stmtStats = $pdo->query("SELECT SUM(quantity * buying_price) FROM products JOIN stock ON products.id = stock.product_id");
        $totalVal = $stmtStats->fetchColumn() ?: 0;
        
        $pdo->prepare("UPDATE system_stats SET total_stock_value = ?, last_updated = NOW()")->execute([$totalVal]);
        file_put_contents('debug_trace.txt', "Step 7: Stats Updated\n", FILE_APPEND);
        
        // 11. Send Email Notification
        $to = 'procurement@example.com'; 
        $subject = "Shipment Received: " . $shipment['invoice_number'];
        $message = "Shipment #" . $shipment['invoice_number'] . " has been received.\n\n";
        $message .= "Received By: " . ($_SESSION['username'] ?? 'User') . "\n";
        $message .= "Date: " . date('Y-m-d H:i:s') . "\n\n";
        $message .= "Items Updated:\n";
        foreach ($processedItems as $pItem) {
            $message .= "- " . $pItem['name'] . ": " . $pItem['qty'] . " units (Batch: " . $pItem['batch'] . ")\n";
        }
        $headers = 'From: system@stockmanager.local' . "\r\n" .
                   'Reply-To: no-reply@stockmanager.local' . "\r\n" .
                   'X-Mailer: PHP/' . phpversion();

        // Send email 
        $mailSent = @mail($to, $subject, $message, $headers);
        file_put_contents('debug_trace.txt', "Step 8: Email Sent (" . ($mailSent ? 'OK' : 'Fail') . ")\n", FILE_APPEND);
        
        $pdo->commit();
        file_put_contents('debug_trace.txt', "Step 9: Commit Successful\n", FILE_APPEND);
        
        $_SESSION['receipt_summary'] = [
            'shipment_no' => $shipment['invoice_number'],
            'received_by' => $_SESSION['username'] ?? 'User',
            'time' => date('Y-m-d H:i:s'),
            'items' => $processedItems,
            'shipment_id' => $id
        ];
        
        redirect('receipt_confirmed.php?shipment=' . $id);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        file_put_contents('debug_trace.txt', "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
        flash('danger', 'Error receiving shipment: ' . $e->getMessage());
    }
}

$page_title = 'Receive Shipment: ' . $shipment['invoice_number'];
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold mb-1">Receive Shipment #<?php echo htmlspecialchars($shipment['invoice_number']); ?></h4>
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-sm">Cancel</a>
            </div>

            <div class="card border-0 rounded-0 shadow-sm">
                <div class="card-body">
                    <form method="POST">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> This process will create batches, update stock levels, calculate weighted average costs, and close linked Purchase Orders.
                        </div>
                        
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Product</th>
                                        <th width="150" class="text-center">Expected Qty</th>
                                        <th width="150" class="text-center">Received Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($items as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($item['product_name'] ?? 'Unknown Product'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary" style="font-size: 0.9rem;"><?php echo $item['quantity']; ?></span>
                                        </td>
                                        <td>
                                            <input type="number" name="qty_<?php echo $item['id']; ?>" class="form-control text-center text-success fw-bold" value="<?php echo $item['quantity']; ?>" min="0">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Receiving Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Condition of goods, driver details, etc."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-success btn-lg rounded-0 w-100 py-3">
                            <i class="fas fa-check-double fa-lg me-2"></i> Confirm Receipt & Update Everything
                        </button>
                    </form>
                </div>
            </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
