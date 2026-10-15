<?php

declare(strict_types=1);

require_once __DIR__ . '/load-data.php';
require_once __DIR__ . '/client-signature-handler.php';
require_once __DIR__ . '/confirm-collection-receive-handler.php';
require_once __DIR__ . '/delivery-note-invoice.php';

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_order_details_payload(PDO $pdo, array $query): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }

    $orderId = (int) ($query['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'Order ID is required.'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT o.*, t.trip_ref, t.status AS trip_status, t.id AS trip_id,
                   dn.receiver_signature_path AS dn_sig,
                   u.full_name AS driver_name
            FROM delivery_orders o
            LEFT JOIN delivery_trips t ON o.trip_id = t.id
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            LEFT JOIN users u ON t.driver_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (!$row) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (deliveries_resolve_sales_invoice_id($pdo, $row) > 0) {
        deliveries_ensure_order_delivery_note($pdo, $orderId, $userId);
        $stmt = $pdo->prepare("
            SELECT o.*, t.trip_ref, t.status AS trip_status, t.id AS trip_id,
                   dn.receiver_signature_path AS dn_sig,
                   u.full_name AS driver_name
            FROM delivery_orders o
            LEFT JOIN delivery_trips t ON o.trip_id = t.id
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            LEFT JOIN users u ON t.driver_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
    }

    $evidence = [];
    try {
        $stmtEv = $pdo->prepare('SELECT * FROM delivery_evidence WHERE delivery_order_id = ? ORDER BY created_at DESC');
        $stmtEv->execute([$orderId]);
        $rows = $stmtEv->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $ev) {
            $type = (string) ($ev['type'] ?? '');
            $evidence[] = [
                'id' => (int) ($ev['id'] ?? 0),
                'type' => $type,
                'typeLabel' => $type === 'photo_drop' ? 'Driver proof' : 'Extra proof',
                'fileUrl' => deliveries_resolve_public_path((string) ($ev['file_path'] ?? '')),
                'created_at' => (string) ($ev['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $evidence = [];
    }

    $signaturePath = (string) ($row['signature_path'] ?? '');
    $dnSig = (string) ($row['dn_sig'] ?? '');
    $isClientSigned = (strpos($signaturePath, 'client_') !== false) || $dnSig !== '';
    $status = (string) ($row['status'] ?? '');
    $isDriverAccomplished = in_array($status, ['delivered', 'completed', 'failed', 'returned'], true);

    $packageImageUrl = deliveries_resolve_public_path((string) ($row['package_image'] ?? ''));

    $signaturePath = (string) ($row['signature_path'] ?? '');
    $signatureUrl = deliveries_resolve_public_path($signaturePath);
    $verifyUrl = deliveries_build_verify_url($orderId);
    $verifyHash = deliveries_order_verification_hash($pdo, $orderId);
    $documents = deliveries_build_order_documents($pdo, $row, $isClientSigned, $verifyHash !== '' ? $verifyHash : null);
    if ($verifyUrl !== '') {
        $documents['shareUrl'] = $verifyUrl;
    }

    $flash = null;
    if (!empty($query['uploaded'])) {
        $flash = ['type' => 'success', 'message' => 'Evidence photos uploaded successfully.'];
    }

    $requestKind = strtolower(trim((string) ($row['request_kind'] ?? 'delivery')));
    $isGoodsCollection = $requestKind === 'goods_collection';

    $expectedQty = null;
    $receivedQty = null;
    $collectionItemId = 0;
    $collectionReceived = false;
    if ($isGoodsCollection) {
        try {
            $stmtItem = $pdo->prepare(
                'SELECT id, quantity_ordered, quantity_delivered, status
                 FROM delivery_items WHERE delivery_order_id = ? ORDER BY id ASC LIMIT 1'
            );
            $stmtItem->execute([$orderId]);
            $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $collectionItemId = (int) ($item['id'] ?? 0);
                $expectedQty = (float) ($item['quantity_ordered'] ?? 0);
                $receivedQty = (float) ($item['quantity_delivered'] ?? 0);
                $collectionReceived = strtolower((string) ($item['status'] ?? '')) === 'verified'
                    || in_array($status, ['delivered', 'completed'], true);
            } elseif (($row['package_weight'] ?? '') !== '' && is_numeric((string) $row['package_weight'])) {
                $expectedQty = (float) $row['package_weight'];
            }
        } catch (Throwable $e) {
            // optional line items
        }
        if ($expectedQty === null && ($row['package_weight'] ?? '') !== '' && is_numeric((string) $row['package_weight'])) {
            $expectedQty = (float) $row['package_weight'];
        }
        if (in_array($status, ['delivered', 'completed'], true)) {
            $collectionReceived = true;
            if ($receivedQty === null || $receivedQty <= 0) {
                $receivedQty = $expectedQty;
            }
        }
    }

    $canConfirmReceive = $isGoodsCollection && !$collectionReceived && deliveries_user_can_confirm_collection_receive();

    $stockReceiveUrl = '';
    if (function_exists('deliveries_module_url')) {
        // Prefer purchasing receive desk when available; otherwise stock purchases.
        $stockReceiveUrl = function_exists('app_url')
            ? (string) app_url('/stock/modules/purchases/index.php')
            : '/stock/modules/purchases/index.php';
    }

    return [
        'ok' => true,
        'data' => [
            'order' => [
                'id' => (int) ($row['id'] ?? 0),
                'invoice_ref' => (string) ($row['invoice_ref'] ?? ''),
                'request_kind' => $requestKind,
                'delivery_type' => function_exists('deliveries_order_delivery_type')
                    ? deliveries_order_delivery_type($row)
                    : ($isGoodsCollection ? 'Goods Collection' : 'Dispatch'),
                'client_name' => (string) ($row['client_name'] ?? ''),
                'client_phone' => (string) ($row['client_phone'] ?? ''),
                'package_description' => (string) ($row['package_description'] ?? ''),
                'package_weight' => (string) ($row['package_weight'] ?? ''),
                'pickup_location' => (string) ($row['pickup_location'] ?? ''),
                'delivery_address' => (string) ($row['delivery_address'] ?? ''),
                'delivery_deadline' => (string) ($row['delivery_deadline'] ?? ''),
                'route_cost' => $row['route_cost'] !== null && $row['route_cost'] !== ''
                    ? (float) $row['route_cost']
                    : null,
                'estimated_route_cost' => $row['estimated_route_cost'] !== null && $row['estimated_route_cost'] !== ''
                    ? (float) $row['estimated_route_cost']
                    : null,
                'status' => $status,
                'trip_ref' => (string) ($row['trip_ref'] ?? ''),
                'trip_id' => (int) ($row['trip_id'] ?? 0),
                'driver_name' => (string) ($row['driver_name'] ?? ''),
                'customer_rating' => $row['customer_rating'] !== null ? (int) $row['customer_rating'] : null,
                'customer_feedback' => (string) ($row['customer_feedback'] ?? ''),
                'package_image_url' => $packageImageUrl,
                'isClientSigned' => $isClientSigned,
                'isDriverAccomplished' => $isDriverAccomplished,
                'recipient_name' => (string) ($row['recipient_name'] ?? ''),
                'signatureUrl' => $isClientSigned ? $signatureUrl : '',
                'verifyUrl' => $verifyUrl,
                'delivery_note_id' => (int) ($row['delivery_note_id'] ?? 0),
                'sales_invoice_id' => (int) ($row['sales_invoice_id'] ?? 0),
            ],
            'collection' => $isGoodsCollection ? [
                'expectedQuantity' => $expectedQty,
                'receivedQuantity' => $receivedQty,
                'itemId' => $collectionItemId,
                'received' => $collectionReceived,
                'canConfirmReceive' => $canConfirmReceive,
                'stockReceiveUrl' => $stockReceiveUrl,
                'progress' => deliveries_collection_progress_steps($status, $collectionReceived),
            ] : null,
            'documents' => $documents,
            'evidence' => $evidence,
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'urls' => [
                'dashboard' => deliveries_module_url('deliveries/index'),
                'myDeliveries' => deliveries_module_url('deliveries/my_deliveries.php'),
                'orderDetails' => deliveries_module_url('deliveries/order_details.php'),
            ],
            'flash' => $flash,
        ],
    ];
}

/**
 * @return list<array{key:string,label:string,done:bool,current:bool}>
 */
function deliveries_collection_progress_steps(string $status, bool $received): array
{
    $s = strtolower(trim($status));
    $stage = 0; // 0 pending assignment, 1 assigned, 2 collecting, 3 arrived/received
    if (in_array($s, ['accepted', 'pending'], true)) {
        $stage = 1;
    } elseif (in_array($s, ['loading', 'in_transit', 'partial'], true)) {
        $stage = 2;
    } elseif (in_array($s, ['delivered', 'completed'], true) || $received) {
        $stage = 3;
    }

    $labels = [
        ['key' => 'pending', 'label' => 'Pending assignment'],
        ['key' => 'assigned', 'label' => 'Assigned'],
        ['key' => 'collecting', 'label' => 'Collecting'],
        ['key' => 'arrived', 'label' => 'Received at office'],
    ];

    $out = [];
    foreach ($labels as $i => $step) {
        $out[] = [
            'key' => $step['key'],
            'label' => $step['label'],
            'done' => $i < $stage || ($i === $stage && $stage === 3),
            'current' => $i === $stage && $stage < 3,
        ];
    }
    return $out;
}
