<?php

declare(strict_types=1);

require_once __DIR__ . '/load-data.php';
require_once __DIR__ . '/client-signature-handler.php';
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

    return [
        'ok' => true,
        'data' => [
            'order' => [
                'id' => (int) ($row['id'] ?? 0),
                'invoice_ref' => (string) ($row['invoice_ref'] ?? ''),
                'client_name' => (string) ($row['client_name'] ?? ''),
                'client_phone' => (string) ($row['client_phone'] ?? ''),
                'package_description' => (string) ($row['package_description'] ?? ''),
                'package_weight' => (string) ($row['package_weight'] ?? ''),
                'pickup_location' => (string) ($row['pickup_location'] ?? ''),
                'delivery_address' => (string) ($row['delivery_address'] ?? ''),
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
            'documents' => $documents,
            'evidence' => $evidence,
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'urls' => [
                'dashboard' => deliveries_module_url('deliveries/index'),
                'myDeliveries' => deliveries_module_url('deliveries/my_deliveries.php'),
                'viewTrip' => deliveries_module_url('deliveries/view_trip.php'),
                'orderDetails' => deliveries_module_url('deliveries/order_details.php'),
            ],
            'flash' => $flash,
        ],
    ];
}
