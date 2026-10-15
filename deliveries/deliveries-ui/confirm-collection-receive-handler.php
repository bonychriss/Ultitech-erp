<?php

declare(strict_types=1);

/**
 * Storekeeper confirms actual received quantity for a goods collection task.
 * Does NOT post stock — stock/procurement receiving remains the source of truth.
 *
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_process_collection_receive(PDO $pdo, int $orderId, float $receivedQty, int $userId): array
{
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'Order ID is required.'];
    }
    if ($receivedQty < 0) {
        return ['ok' => false, 'error' => 'Received quantity cannot be negative.'];
    }
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'You must be logged in.'];
    }

    if (!deliveries_user_can_confirm_collection_receive()) {
        return ['ok' => false, 'error' => 'Only a storekeeper, warehouse user, or admin can confirm receipt.'];
    }

    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }

    try {
        $stmt = $pdo->prepare('SELECT id, request_kind, status, package_description FROM delivery_orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $kind = strtolower(trim((string) ($order['request_kind'] ?? '')));
    if ($kind !== 'goods_collection') {
        return ['ok' => false, 'error' => 'This order is not a goods collection task.'];
    }

    $status = strtolower(trim((string) ($order['status'] ?? '')));
    if (in_array($status, ['rejected', 'failed', 'returned'], true)) {
        return ['ok' => false, 'error' => 'This collection task cannot be received in its current status.'];
    }

    $qtyInt = (int) max(0, (int) round($receivedQty));
    $itemName = trim((string) ($order['package_description'] ?? ''));
    if ($itemName === '') {
        $itemName = 'Goods collection';
    }
    $itemName = substr($itemName, 0, 255);

    $pdo->beginTransaction();
    try {
        $stmtItem = $pdo->prepare('SELECT id, quantity_ordered FROM delivery_items WHERE delivery_order_id = ? ORDER BY id ASC LIMIT 1');
        $stmtItem->execute([$orderId]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $pdo->prepare(
                "UPDATE delivery_items
                 SET quantity_delivered = ?, status = 'verified'
                 WHERE id = ?"
            )->execute([$qtyInt, (int) $item['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO delivery_items (delivery_order_id, item_name, quantity_ordered, quantity_delivered, status)
                 VALUES (?, ?, ?, ?, 'verified')"
            )->execute([$orderId, $itemName, $qtyInt > 0 ? $qtyInt : 1, $qtyInt]);
        }

        $pdo->prepare(
            "UPDATE delivery_orders
             SET status = 'delivered',
                 recipient_name = ?,
                 completion_time = NOW(),
                 package_weight = ?
             WHERE id = ?"
        )->execute([
            trim((string) ($_SESSION['full_name'] ?? 'Storekeeper')),
            rtrim(rtrim(number_format($receivedQty, 3, '.', ''), '0'), '.'),
            $orderId,
        ]);

        $tripIdStmt = $pdo->prepare('SELECT trip_id FROM delivery_orders WHERE id = ?');
        $tripIdStmt->execute([$orderId]);
        $tripId = (int) $tripIdStmt->fetchColumn();
        if ($tripId > 0) {
            $pdo->prepare(
                "UPDATE delivery_trips SET status = 'completed', end_time = NOW() WHERE id = ? AND status <> 'completed'"
            )->execute([$tripId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'data' => [
            'orderId' => $orderId,
            'receivedQuantity' => $receivedQty,
            'message' => 'Received quantity confirmed. Post stock in procurement/stock receiving to avoid duplicate inventory entries.',
        ],
    ];
}

function deliveries_user_can_confirm_collection_receive(): bool
{
    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }
    if (function_exists('userHasAccessRole')) {
        return userHasAccessRole('warehouse') || userHasAccessRole('store') || userHasAccessRole('procurement');
    }
    $dept = strtolower(trim((string) ($_SESSION['department'] ?? '')));
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    $hay = $dept . ' ' . $role;
    foreach (['warehouse', 'store', 'storekeeper', 'inventory', 'procurement', 'purchasing'] as $needle) {
        if ($hay !== '' && str_contains($hay, $needle)) {
            return true;
        }
    }
    return false;
}
