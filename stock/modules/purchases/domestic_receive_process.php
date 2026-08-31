<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$poId = (int)($_POST['po_id'] ?? 0);
$poTable = trim((string) ($_POST['po_table'] ?? 'stocks_purchase_orders'));
$notes = clean_input($_POST['notes'] ?? '');
$receiveQuantities = $_POST['receive_qty'] ?? [];
$userId = $_SESSION['user_id'] ?? null;
$warehouseId = (int)($_POST['warehouse_id'] ?? 1);

if ($poId <= 0 || empty($receiveQuantities)) {
    flash('success_type', 'error');
    flash('success', 'Invalid submission data.');
    redirect('index.php');
}

if ($poTable === 'purchases') {
    if (!function_exists('stockPurchaseProcessLegacyReceive')) {
        flash('success_type', 'error');
        flash('success', 'Legacy receive processing is not available on this server yet. Please deploy the latest purchase module update.');
        redirect('view_po.php?id=' . $poId);
    }
    $result = stockPurchaseProcessLegacyReceive($pdo, $poId, $receiveQuantities, $notes, $userId ? (int) $userId : null);
    if ($result['ok'] ?? false) {
        flash('success', (string) ($result['message'] ?? 'Stock received successfully.'));
        redirect('view_po.php?id=' . $poId);
    }
    flash('success_type', 'error');
    flash('success', (string) ($result['message'] ?? 'Error processing receipt.'));
    redirect('domestic_receive.php?id=' . $poId);
}

try {
    $txnCols = $pdo->query('SHOW COLUMNS FROM stocks_transactions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('notes', $txnCols, true)) {
        $pdo->exec('ALTER TABLE stocks_transactions ADD COLUMN notes TEXT NULL');
        $txnCols[] = 'notes';
    }
} catch (Throwable $e) {
    $txnCols = [];
}

try {
    // 1) Fetch/validate the PO outside the transaction.
    // IMPORTANT: do not run schema ALTER statements inside a transaction in MySQL
    // (they can cause implicit commits, leaving no active transaction).
    $stmtPo = $pdo->prepare("SELECT * FROM stocks_purchase_orders WHERE id = ? LIMIT 1");
    $stmtPo->execute([$poId]);
    $po = $stmtPo->fetch();

    if (!$po) {
        throw new Exception("Purchase Order not found.");
    }

    if (($po['status'] ?? '') === 'Cancelled') {
        throw new Exception("Cannot receive a cancelled order.");
    }

    $poPurchaseType = $po['purchase_type'] ?? 'domestic';
    if (!in_array($poPurchaseType, ['domestic', 'import'], true)) {
        throw new Exception("This order type cannot be received from this screen.");
    }

    // Ensure schema needed for import checks BEFORE starting the transaction.
    $shipmentFunctions = dirname(__DIR__, 2) . '/includes/shipment-functions.php';
    if (is_file($shipmentFunctions)) {
        require_once $shipmentFunctions;
    }
    if (function_exists('ensure_shipment_po_linking_schema')) {
        ensure_shipment_po_linking_schema($pdo);
    }
    if ($poPurchaseType === 'import' && function_exists('stocks_po_has_linked_shipment') && !stocks_po_has_linked_shipment($pdo, $poId)) {
        throw new Exception('Create and link a shipment to this outdoor PO before receiving stock.');
    }

    $reference = trim((string)($po['po_number'] ?? ''));
    if ($reference === '') {
        $reference = 'PO#' . $poId;
    }

    // 2) Start transaction ONLY after all schema checks are done.
    $pdo->beginTransaction();

    // 2. Process each item
    $anyReceived = false;
    foreach ($receiveQuantities as $itemId => $qty) {
        $qty = (float)$qty;
        if ($qty <= 0) continue;

        // Fetch PO Item
        $stmtItem = $pdo->prepare("SELECT * FROM stocks_po_items WHERE id = ? AND po_id = ?");
        $stmtItem->execute([$itemId, $poId]);
        $poItem = $stmtItem->fetch();

        if (!$poItem) continue;

        // Safety check: Remaining quantity
        $remaining = (float)$poItem['qty_ordered'] - (float)$poItem['qty_received'];
        if ($qty > $remaining) {
            $qty = $remaining; // Cap it at remaining
        }

        if ($qty <= 0) continue;

        // A) Update PO item received quantity
        $stmtUpdPoItem = $pdo->prepare("UPDATE stocks_po_items SET qty_received = qty_received + ? WHERE id = ?");
        $stmtUpdPoItem->execute([$qty, $itemId]);

        // B) Update stock in stocks_items
        $stmtUpdStock = $pdo->prepare("UPDATE stocks_items SET stock_quantity = COALESCE(stock_quantity, 0) + ? WHERE id = ?");
        $stmtUpdStock->execute([$qty, $poItem['item_id']]);

        // ALSO update the main inventory stock table (products/stock),
        // because inventory screens read from `stock.quantity`.
        //
        // IMPORTANT: In this install, `stocks_transactions.item_id` matches the product screen id,
        // so we must prioritize updating `stock` by the PO's `item_id` directly.
        $productId = (int) $poItem['item_id'];
        try {
            $stmtProdExists = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
            $stmtProdExists->execute([$productId]);
            $prodOk = (bool) $stmtProdExists->fetchColumn();
        } catch (Throwable $e) {
            $prodOk = true; // best effort; keep direct mapping
        }

        // Fallback only if that product id doesn't exist.
        if (!$prodOk) {
            try {
                $stmtSku = $pdo->prepare("SELECT sku FROM stocks_items WHERE id = ? LIMIT 1");
                $stmtSku->execute([(int) $poItem['item_id']]);
                $sku = trim((string) ($stmtSku->fetchColumn() ?: ''));
                if ($sku !== '') {
                    $stmtProd = $pdo->prepare("SELECT id FROM products WHERE product_code = ? LIMIT 1");
                    $stmtProd->execute([$sku]);
                    $altId = $stmtProd->fetchColumn();
                    if ($altId) {
                        $productId = (int) $altId;
                    }
                }
            } catch (Throwable $e) {
                // ignore; keep direct mapping
            }
        }

        // Ensure stock row exists, then increment quantity (schema-aware; tolerates missing warehouses table).
        try {
            if (function_exists('stockIncrementProductStock')) {
                stockIncrementProductStock($pdo, (int) $productId, $qty, $warehouseId);
            }
        } catch (Throwable $e) {
            throw new Exception('Failed to update main inventory stock table: ' . $e->getMessage());
        }

        // C) Insert Transaction Log (schema-safe: unit_cost / external_reference optional)
        if (!empty($txnCols)) {
            $txnFields = ['item_id', 'type', 'quantity'];
            $txnParams = [(int) $poItem['item_id'], 'in', $qty];
            $txnSqlParts = ['?', '?', '?'];

            if (in_array('unit_cost', $txnCols, true)) {
                $txnFields[] = 'unit_cost';
                $txnParams[] = (float) ($poItem['unit_cost'] ?? 0);
                $txnSqlParts[] = '?';
            }
            if (in_array('tax_amount', $txnCols, true)) {
                $txnFields[] = 'tax_amount';
                $txnParams[] = 0;
                $txnSqlParts[] = '?';
            }
            if (in_array('company_id', $txnCols, true)) {
                $txnCompanyId = (int) (function_exists('stockPurchaseActiveCompanyId') ? stockPurchaseActiveCompanyId() : (currentCompanyId() ?? 0));
                if ($txnCompanyId > 0) {
                    $txnFields[] = 'company_id';
                    $txnParams[] = $txnCompanyId;
                    $txnSqlParts[] = '?';
                }
            }
            $txnFields[] = 'reference_type';
            $txnParams[] = 'purchase_order';
            $txnSqlParts[] = '?';
            $txnFields[] = 'reference_id';
            $txnParams[] = $poId;
            $txnSqlParts[] = '?';
            if (in_array('external_reference', $txnCols, true)) {
                $txnFields[] = 'external_reference';
                $txnParams[] = $reference !== '' ? $reference : null;
                $txnSqlParts[] = '?';
            }
            if (in_array('notes', $txnCols, true)) {
                $txnNote = trim($notes);
                if ($txnNote === '' && $reference !== '' && !in_array('external_reference', $txnCols, true)) {
                    $txnNote = 'Ref: ' . $reference;
                }
                $txnFields[] = 'notes';
                $txnParams[] = $txnNote !== '' ? $txnNote : null;
                $txnSqlParts[] = '?';
            }
            if (in_array('user_id', $txnCols, true)) {
                $txnFields[] = 'user_id';
                $txnParams[] = (int) ($userId ?? 0);
                $txnSqlParts[] = '?';
            }
            if (in_array('transaction_date', $txnCols, true)) {
                $txnFields[] = 'transaction_date';
                $txnSqlParts[] = 'NOW()';
            }

            $stmtTxn = $pdo->prepare(
                'INSERT INTO stocks_transactions (' . implode(', ', $txnFields) . ') VALUES (' . implode(', ', $txnSqlParts) . ')'
            );
            $stmtTxn->execute($txnParams);
        }

        $anyReceived = true;
    }

    if (!$anyReceived) {
        throw new Exception("No valid quantities were processed.");
    }

    // 3. Check if PO is now fully received
    $stmtCheck = $pdo->prepare("SELECT SUM(qty_ordered - qty_received) as remaining FROM stocks_po_items WHERE po_id = ?");
    $stmtCheck->execute([$poId]);
    $remainingTotal = (float)$stmtCheck->fetchColumn();

    if ($remainingTotal <= 0) {
        // Fully Received
        $poCols = [];
        try {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $poCols = [];
        }

        if (in_array('updated_at', $poCols, true)) {
            $stmtStatus = $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?");
            $stmtStatus->execute([$poId]);
        } else {
            $stmtStatus = $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received' WHERE id = ?");
            $stmtStatus->execute([$poId]);
        }
    } else {
        // Partial (Stay Pending or mark Partially Received if there was a status for it)
        // For now let's keep it Pending or update to a new status if it existed.
        // Looking at index.php, it uses 'Received', 'Pending', 'Approved', etc.
        // We might want to mark it 'Partially Received' if we want to distinguish.
        // Let's stick with the existing logic for now.
    }

    if (!$pdo->inTransaction()) {
        throw new Exception('Internal error: transaction was not active. Nothing was posted.');
    }
    $pdo->commit();

    flash('success', 'Stock received successfully for Order ' . ($po['po_number'] ?: '#'.$poId));
    redirect('index.php');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('success_type', 'error');
    flash('success', 'Error processing receipt: ' . $e->getMessage());
    redirect('domestic_receive.php?id=' . $poId);
}
