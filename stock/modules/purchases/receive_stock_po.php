<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

// Domestic receiving for stocks_purchase_orders / stocks_po_items.
// This does not delete or migrate any legacy purchase data.

$poId = (int)($_GET['id'] ?? 0);
$ref = clean_input($_GET['ref'] ?? '');

if ($poId <= 0) {
    flash('success_type', 'error');
    flash('success', 'Invalid purchase order id.');
    redirect('index.php');
}

try {
    $stmtPo = $pdo->prepare("
        SELECT p.*, s.name AS supplier_name
        FROM stocks_purchase_orders p
        LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id
        WHERE p.id = ? AND p.company_id = ?
        LIMIT 1
    ");
    $stmtPo->execute([$poId, $company_id]);
    $po = $stmtPo->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        throw new Exception('Purchase order not found.');
    }

    $stmtItems = $pdo->prepare("
        SELECT pi.*, si.name AS item_name, si.sku, si.stock_quantity
        FROM stocks_po_items pi
        JOIN stocks_items si ON pi.item_id = si.id
        WHERE pi.po_id = ? AND pi.company_id = ? AND si.company_id = ?
    ");
    $stmtItems->execute([$poId, $company_id, $company_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('No items found for this purchase order.');
    }

    $pdo->beginTransaction();

    $receivedAny = false;

    foreach ($items as $it) {
        $ordered = (float)($it['qty_ordered'] ?? 0);
        $received = (float)($it['qty_received'] ?? 0);
        $toReceive = max(0, $ordered - $received);
        if ($toReceive <= 0) {
            continue;
        }

        // 1) Update PO item received qty
        $upd = $pdo->prepare("UPDATE stocks_po_items SET qty_received = qty_received + ? WHERE id = ? AND company_id = ?");
        $upd->execute([$toReceive, $it['id'], $company_id]);

        // 2) Update on-hand in stocks_items
        $updItem = $pdo->prepare("UPDATE stocks_items SET stock_quantity = COALESCE(stock_quantity, 0) + ? WHERE id = ? AND company_id = ?");
        $updItem->execute([$toReceive, $it['item_id'], $company_id]);

        // ALSO update the main inventory stock table (products/stock),
        // because inventory screens read from `stock.quantity`.
        //
        // IMPORTANT: prioritize direct id mapping (stocks_items.id == products.id).
        $productId = (int) ($it['item_id'] ?? 0);
        $prodOk = true;
        try {
            $stmtProdExists = $pdo->prepare("SELECT id FROM products WHERE id = ? AND company_id = ? LIMIT 1");
            $stmtProdExists->execute([$productId, $company_id]);
            $prodOk = (bool) $stmtProdExists->fetchColumn();
        } catch (Throwable $e) {
            $prodOk = true;
        }
        if (!$prodOk) {
            try {
                $sku = trim((string) ($it['sku'] ?? ''));
                if ($sku !== '') {
                    $stmtProd = $pdo->prepare("SELECT id FROM products WHERE product_code = ? AND company_id = ? LIMIT 1");
                    $stmtProd->execute([$sku, $company_id]);
                    $altId = $stmtProd->fetchColumn();
                    if ($altId) {
                        $productId = (int) $altId;
                    }
                }
            } catch (Throwable $e) {
                // ignore; keep direct mapping
            }
        }
        $stmtCheckStock = $pdo->prepare("SELECT COUNT(*) FROM stock WHERE product_id = ? AND company_id = ?");
        $stmtCheckStock->execute([(int) $productId, $company_id]);
        if ((int) $stmtCheckStock->fetchColumn() === 0) {
            $loc = '';
            try {
                $stmtLoc = $pdo->prepare("SELECT location FROM stocks_items WHERE id = ? AND company_id = ? LIMIT 1");
                $stmtLoc->execute([(int) $it['item_id'], $company_id]);
                $loc = (string) ($stmtLoc->fetchColumn() ?: '');
            } catch (Throwable $ext) {}
            
            $pdo->prepare("INSERT INTO stock (company_id, product_id, quantity, location) VALUES (?, ?, 0, ?)")->execute([$company_id, (int) $productId, $loc]);
        }
        $pdo->prepare("UPDATE stock SET quantity = COALESCE(quantity, 0) + ?, last_updated = NOW() WHERE product_id = ? AND company_id = ?")->execute([$toReceive, (int) $productId, $company_id]);

        // 3) Log transaction
        $insTxn = $pdo->prepare("
            INSERT INTO stocks_transactions
            (company_id, item_id, type, quantity, unit_cost, tax_amount, reference_type, reference_id, external_reference, transaction_date, user_id)
            VALUES (?, ?, 'in', ?, ?, 0, 'purchase_order', ?, ?, NOW(), ?)
        ");
        $insTxn->execute([
            $company_id,
            $it['item_id'],
            $toReceive,
            (float)($it['unit_cost'] ?? 0),
            $poId,
            ($ref !== '' ? $ref : null),
            $_SESSION['user_id'] ?? null
        ]);

        $receivedAny = true;
    }

    // Mark received if fully received
    $stmtRemaining = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(qty_ordered - qty_received, 0)), 0) FROM stocks_po_items WHERE po_id = ? AND company_id = ?");
    $stmtRemaining->execute([(int)$poId, $company_id]);
    $remaining = (float) $stmtRemaining->fetchColumn();
    if ($remaining <= 0) {
        $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received' WHERE id = ? AND company_id = ?")->execute([$poId, $company_id]);
    }

    $pdo->commit();

    if ($receivedAny) {
        flash('success', 'Received stock for PO: ' . ($po['po_number'] ?? ('#' . $poId)));
    } else {
        flash('success', 'Nothing to receive for PO: ' . ($po['po_number'] ?? ('#' . $poId)));
    }
    redirect('index.php');
} catch (Exception $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Exception $x) {}
    flash('success_type', 'error');
    flash('success', 'Error receiving PO: ' . $e->getMessage());
    redirect('index.php');
}

