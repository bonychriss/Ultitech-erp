<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$poId = (int)($_POST['po_id'] ?? 0);
$notes = clean_input($_POST['notes'] ?? '');
$receiveQuantities = $_POST['receive_qty'] ?? [];
$issuedInvoiceIds = $_POST['issued_invoice_ids'] ?? [];
$userId = $_SESSION['user_id'] ?? null;

if ($poId <= 0 || empty($receiveQuantities)) {
    flash('success_type', 'error');
    flash('success', 'Invalid submission data.');
    redirect('index.php');
}

try {
    $txnCols = $pdo->query('SHOW COLUMNS FROM stocks_transactions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('notes', $txnCols, true)) {
        $pdo->exec('ALTER TABLE stocks_transactions ADD COLUMN notes TEXT NULL');
    }
} catch (Throwable $e) {
    // If ALTER fails, INSERT below may still error — surface in main try
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
    ensure_shipment_po_linking_schema($pdo);
    if ($poPurchaseType === 'import' && !stocks_po_has_linked_shipment($pdo, $poId)) {
        throw new Exception('Create and link a shipment to this outdoor PO before receiving stock.');
    }

    $reference = $poPurchaseType === 'import' ? '' : clean_input($_POST['reference'] ?? '');
    if ($poPurchaseType !== 'import') {
        if (!is_array($issuedInvoiceIds)) {
            $issuedInvoiceIds = [$issuedInvoiceIds];
        }
        $issuedInvoiceIds = array_values(array_filter(array_map('intval', $issuedInvoiceIds), fn($v) => $v > 0));
        if (!empty($issuedInvoiceIds)) {
            try {
                $hasInvoices = (bool) $pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
            } catch (Throwable $e) {
                $hasInvoices = false;
            }
            if ($hasInvoices) {
                try {
                    $in = '(' . implode(',', array_fill(0, count($issuedInvoiceIds), '?')) . ')';
                    $stmtInv = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id IN $in");
                    $stmtInv->execute($issuedInvoiceIds);
                    $invNos = $stmtInv->fetchAll(PDO::FETCH_COLUMN) ?: [];
                    $invNos = array_values(array_filter(array_map('trim', array_map('strval', $invNos))));
                    if (!empty($invNos)) {
                        $suffix = ' | Invoices: ' . implode(', ', $invNos);
                        if (stripos($reference, '| Invoices:') === false) {
                            $reference = trim($reference . $suffix);
                        }
                    }
                } catch (Throwable $e) {
                    // ignore invoice lookup issues
                }
            }
        }
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

        // Ensure stock row exists, then increment quantity
        try {
            $stmtCheckStock = $pdo->prepare("SELECT COUNT(*) FROM stock WHERE product_id = ?");
            $stmtCheckStock->execute([(int) $productId]);
            if ((int) $stmtCheckStock->fetchColumn() === 0) {
                // Determine if we have a location from the item
                $loc = '';
                try {
                    $stmtLoc = $pdo->prepare("SELECT location FROM stocks_items WHERE id = ? LIMIT 1");
                    $stmtLoc->execute([(int) $poItem['item_id']]);
                    $loc = (string) ($stmtLoc->fetchColumn() ?: '');
                } catch (Throwable $ext) {}
                
                $insStock = $pdo->prepare("INSERT INTO stock (product_id, quantity, location) VALUES (?, 0, ?)");
                $insStock->execute([(int) $productId, $loc]);
            }
            
            // Perform the update with timestamp
            $updStockMain = $pdo->prepare("UPDATE stock SET quantity = COALESCE(quantity, 0) + ?, last_updated = NOW() WHERE product_id = ?");
            $updStockMain->execute([$qty, (int) $productId]);
        } catch (Throwable $e) {
            throw new Exception("Failed to update main inventory stock table: " . $e->getMessage());
        }

        // C) Insert Transaction Log
        $stmtTxn = $pdo->prepare("
            INSERT INTO stocks_transactions 
            (item_id, type, quantity, unit_cost, reference_type, reference_id, external_reference, notes, user_id, transaction_date)
            VALUES (?, 'in', ?, ?, 'purchase_order', ?, ?, ?, ?, NOW())
        ");
        $stmtTxn->execute([
            $poItem['item_id'],
            $qty,
            $poItem['unit_cost'],
            $poId,
            $reference,
            $notes,
            $userId
        ]);

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
