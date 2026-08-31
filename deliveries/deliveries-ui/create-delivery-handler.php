<?php

declare(strict_types=1);

require_once __DIR__ . '/delivery-note-invoice.php';

$dispatchHelpers = dirname(__DIR__, 2) . '/dispatch/dispatch-helpers.php';
if (is_file($dispatchHelpers)) {
    require_once $dispatchHelpers;
}

/**
 * Create a delivery request (shared by legacy form and React API).
 *
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_process_create_request(PDO $pdo, array $input, array $files, int $userId): array
{
    // DDL auto-commits in MySQL — run all schema ensures before beginTransaction.
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }
    if (function_exists('ensureDeliveryNotesSchema')) {
        ensureDeliveryNotesSchema();
    }
    if (function_exists('ensure_dispatch_notes_schema')) {
        ensure_dispatch_notes_schema($pdo);
    }
    if (function_exists('ensure_dispatch_routes_price_currency')) {
        ensure_dispatch_routes_price_currency($pdo);
    }

    $clientName = trim((string) ($input['client_name'] ?? ''));
    $clientPhone = trim((string) ($input['client_phone'] ?? ''));
    $destination = trim((string) ($input['destination'] ?? ''));
    $pickup = trim((string) ($input['pickup'] ?? ''));
    $deadline = trim((string) ($input['deadline'] ?? ''));
    $routeCostRaw = trim((string) ($input['route_cost'] ?? ''));
    $estimatedRouteCostRaw = trim((string) ($input['estimated_route_cost'] ?? ''));
    $invoiceRef = trim((string) ($input['invoice_ref'] ?? ''));
    $salesInvoiceId = !empty($input['invoice_id']) ? (int) $input['invoice_id'] : 0;
    $packageWeight = trim((string) ($input['package_weight'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $deliveryNoteId = !empty($input['delivery_note_id']) ? (int) $input['delivery_note_id'] : null;
    $driverId = $userId;
    if ($driverId <= 0) {
        return ['ok' => false, 'error' => 'You must be logged in to create a delivery.'];
    }

    if ($clientName === '' || $destination === '') {
        return ['ok' => false, 'error' => 'Please fill in all required fields (Client, Destination).'];
    }

    $routeCost = null;
    if ($routeCostRaw !== '') {
        if (!is_numeric($routeCostRaw) || (float) $routeCostRaw < 0) {
            return ['ok' => false, 'error' => 'Route cost must be a valid amount.'];
        }
        $routeCost = round((float) $routeCostRaw, 2);
    }

    $estimatedRouteCost = null;
    if ($estimatedRouteCostRaw !== '') {
        if (!is_numeric($estimatedRouteCostRaw) || (float) $estimatedRouteCostRaw < 0) {
            return ['ok' => false, 'error' => 'Estimated route price must be a valid amount.'];
        }
        $estimatedRouteCost = round((float) $estimatedRouteCostRaw, 2);
    }

    $baseDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deliveries';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }

    $receiptFilePath = null;
    $invFilePath = null;
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

    if (!empty($files['receipt_file']['name'])) {
        $ext = strtolower(pathinfo((string) $files['receipt_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $uniqueName = 'rcp_' . uniqid('', true) . '.' . $ext;
            if (move_uploaded_file($files['receipt_file']['tmp_name'], $baseDir . DIRECTORY_SEPARATOR . $uniqueName)) {
                $receiptFilePath = 'assets/uploads/deliveries/' . $uniqueName;
            }
        }
    }

    if (!empty($files['invoice_file']['name'])) {
        $ext = strtolower(pathinfo((string) $files['invoice_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $uniqueName = 'inv_' . uniqid('', true) . '.' . $ext;
            if (move_uploaded_file($files['invoice_file']['tmp_name'], $baseDir . DIRECTORY_SEPARATOR . $uniqueName)) {
                $invFilePath = 'assets/uploads/deliveries/' . $uniqueName;
            }
        }
    }

    deliveries_ensure_sales_invoice_column($pdo);
    if (function_exists('deliveries_ensure_delivery_note_salesperson_column')) {
        deliveries_ensure_delivery_note_salesperson_column($pdo);
    }

    $pdo->beginTransaction();
    try {
        $tripRef = 'TRIP-' . date('Ymd') . '-' . rand(100, 999);
        $stmtTrip = $pdo->prepare(
            "INSERT INTO delivery_trips (trip_ref, driver_id, status, created_at) VALUES (?, ?, 'planned', NOW())"
        );
        $stmtTrip->execute([$tripRef, $driverId]);
        $newTripId = (int) $pdo->lastInsertId();

        if ($deliveryNoteId) {
            $stmtSig = $pdo->prepare('SELECT signature_path FROM users WHERE id = ?');
            $stmtSig->execute([$userId]);
            $creatorSig = $stmtSig->fetchColumn();
            if ($creatorSig) {
                $pdo->prepare(
                    'UPDATE delivery_notes SET authorized_signature_path = ? WHERE id = ? AND authorized_signature_path IS NULL'
                )->execute([$creatorSig, $deliveryNoteId]);
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO delivery_orders
            (requested_driver_id, trip_id, delivery_note_id, client_name, client_phone, delivery_address, pickup_location, delivery_deadline, route_cost, estimated_route_cost,
             invoice_ref, sales_invoice_id, package_weight, package_description, receipt_file, invoice_file, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'request_pending', ?, NOW())
        ");
        $stmt->execute([
            $driverId,
            $newTripId,
            $deliveryNoteId,
            $clientName,
            $clientPhone,
            $destination,
            $pickup,
            $deadline !== '' ? $deadline : null,
            $routeCost,
            $estimatedRouteCost,
            $invoiceRef,
            $salesInvoiceId > 0 ? $salesInvoiceId : null,
            $packageWeight,
            $description,
            $receiptFilePath,
            $invFilePath,
            $userId,
        ]);

        $orderId = (int) $pdo->lastInsertId();

        if ($salesInvoiceId > 0 || $invoiceRef !== '') {
            $orderRow = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
            $orderRow->execute([$orderId]);
            $freshOrder = $orderRow->fetch(PDO::FETCH_ASSOC);
            if ($freshOrder) {
                deliveries_ensure_order_delivery_note($pdo, $orderId, $userId);
            }
        }

        $dispatchResult = null;
        $shouldCreateDispatch = $salesInvoiceId <= 0 && $invoiceRef === '';
        if ($shouldCreateDispatch && function_exists('dispatch_create_note_from_delivery')) {
            $dispatchResult = dispatch_create_note_from_delivery($pdo, [
                'pickup' => $pickup,
                'destination' => $destination,
                'route_cost' => $routeCost,
                'client_name' => $clientName,
                'description' => $description,
            ], $userId);
            if (!$dispatchResult['ok']) {
                throw new RuntimeException((string) ($dispatchResult['error'] ?? 'Could not create dispatch note.'));
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $whatsappUrl = '';
    $stmtD = $pdo->prepare('SELECT full_name, whatsapp_number FROM users WHERE id = ?');
    $stmtD->execute([$driverId]);
    $driver = $stmtD->fetch(PDO::FETCH_ASSOC);

    if ($driver && !empty($driver['whatsapp_number']) && $driverId !== $userId) {
        $phone = preg_replace('/[^0-9]/', '', (string) $driver['whatsapp_number']);
        $driverViewUrl = function_exists('deliveries_module_url')
            ? deliveries_module_url('deliveries/Driver.php') . '&id=' . $orderId
            : ('deliveries/Driver.php?id=' . $orderId);
        if (function_exists('app_url') && strpos($driverViewUrl, 'http') !== 0) {
            $driverViewUrl = app_url('/' . ltrim(str_replace('\\', '/', $driverViewUrl), '/'));
        }
        $msg = "New Delivery Order #$orderId\nFrom: " . ($pickup !== '' ? $pickup : 'Not specified') . "\nTo: $clientName ($destination)\nDeadline: $deadline\n\nPlease accept or reject here: $driverViewUrl";
        $whatsappUrl = 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
    }

    return [
        'ok' => true,
        'data' => [
            'orderId' => $orderId,
            'tripRef' => $tripRef,
            'message' => $dispatchResult
                ? ('Delivery #' . $orderId . ' and dispatch ' . ($dispatchResult['dispatchNumber'] ?? '') . ' created successfully!')
                : "Delivery request #$orderId sent successfully!",
            'whatsappUrl' => $whatsappUrl,
            'dispatchId' => $dispatchResult['dispatchId'] ?? null,
            'dispatchNumber' => $dispatchResult['dispatchNumber'] ?? null,
        ],
    ];
}
