<?php

declare(strict_types=1);

require_once __DIR__ . '/delivery-note-invoice.php';

/**
 * Save client signature for a delivery order (QR page or driver handoff).
 *
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_process_client_signature(PDO $pdo, int $orderId, string $signatureData, string $recipientName): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }

    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'Missing order ID.'];
    }

    $recipientName = trim($recipientName);
    if ($recipientName === '') {
        return ['ok' => false, 'error' => 'Recipient name is required.'];
    }

    if (trim($signatureData) === '') {
        return ['ok' => false, 'error' => 'Signature is required.'];
    }

    try {
        $stmt = $pdo->prepare('SELECT id, delivery_note_id, signature_path FROM delivery_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    if (function_exists('deliveries_ensure_order_delivery_note')) {
        deliveries_ensure_order_delivery_note($pdo, $orderId, 0);
        $stmt = $pdo->prepare('SELECT id, delivery_note_id, signature_path FROM delivery_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: $order;
    }

    $existing = (string) ($order['signature_path'] ?? '');
    if (strpos($existing, 'client_') !== false) {
        return ['ok' => true, 'data' => ['alreadySigned' => true]];
    }

    $data = $signatureData;
    if (strpos($data, ',') !== false) {
        $parts = explode(',', $data, 2);
        $data = $parts[1] ?? '';
    }
    $binary = base64_decode($data, true);
    if ($binary === false || $binary === '') {
        return ['ok' => false, 'error' => 'Invalid signature data.'];
    }

    if (!function_exists('ensureSignatureDir')) {
        return ['ok' => false, 'error' => 'Signature storage is not available.'];
    }

    $filename = 'client_' . $orderId . '_' . time() . '.png';
    $dir = ensureSignatureDir();
    $fullPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($fullPath, $binary) === false) {
        return ['ok' => false, 'error' => 'Could not save signature file.'];
    }

    $signaturePath = 'assets/signatures/' . $filename;
    $deliveryNoteId = !empty($order['delivery_note_id']) ? (int) $order['delivery_note_id'] : 0;

    try {
        $pdo->beginTransaction();
        if ($deliveryNoteId > 0) {
            $upDn = $pdo->prepare('UPDATE delivery_notes SET receiver_signature_path = ? WHERE id = ?');
            $upDn->execute([$signaturePath, $deliveryNoteId]);
        }
        $upOrder = $pdo->prepare("
            UPDATE delivery_orders SET
                status = 'delivered',
                signature_path = ?,
                recipient_name = ?,
                completion_time = NOW()
            WHERE id = ?
        ");
        $upOrder->execute([$signaturePath, $recipientName, $orderId]);
        deliveries_sync_order_note_signatures($pdo, $orderId);
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
            'signaturePath' => $signaturePath,
            'signatureUrl' => deliveries_resolve_public_path($signaturePath),
            'recipientName' => $recipientName,
        ],
    ];
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_check_client_signature(PDO $pdo, int $orderId): array
{
    try {
        $stmt = $pdo->prepare('SELECT signature_path, recipient_name FROM delivery_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (!$row) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $path = (string) ($row['signature_path'] ?? '');
    $signed = strpos($path, 'client_') !== false;

    return [
        'ok' => true,
        'data' => [
            'signed' => $signed,
            'signatureUrl' => $signed ? deliveries_resolve_public_path($path) : '',
            'recipientName' => (string) ($row['recipient_name'] ?? ''),
        ],
    ];
}

function deliveries_build_verify_url(int $orderId): string
{
    if (!function_exists('generateOrderVerificationHash')) {
        return '';
    }
    $hash = generateOrderVerificationHash($orderId);
    $path = 'deliveries/verify_delivery.php?hash=' . urlencode($hash);
    if (function_exists('deliveries_resolve_absolute_url')) {
        return deliveries_resolve_absolute_url($path);
    }
    return $path;
}

function deliveries_user_can_access_order(PDO $pdo, int $orderId, int $userId): bool
{
    if ($orderId <= 0 || $userId <= 0) {
        return false;
    }
    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM delivery_orders WHERE id = ? AND (created_by = ? OR requested_driver_id = ?)');
        $stmt->execute([$orderId, $userId, $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
