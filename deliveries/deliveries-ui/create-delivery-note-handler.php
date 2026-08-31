<?php

declare(strict_types=1);

require_once __DIR__ . '/load-data.php';

/**
 * Create a delivery note (shared by React API).
 *
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_process_create_delivery_note_request(PDO $pdo, array $input, int $userId): array
{
    if (function_exists('ensureDeliveryNotesSchema')) {
        ensureDeliveryNotesSchema();
    }

    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'You must be logged in to create a delivery note.'];
    }

    $customerName = trim((string) ($input['customer_name'] ?? ''));
    $deliveryAddress = trim((string) ($input['delivery_address'] ?? ''));
    $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
    $deliveryDate = trim((string) ($input['delivery_date'] ?? ''));
    $items = $input['items'] ?? [];

    if ($customerName === '' || $deliveryDate === '') {
        return ['ok' => false, 'error' => 'Customer Name and Date are required.'];
    }

    if (!is_array($items)) {
        $items = [];
    }

    $validItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $description = trim((string) ($item['description'] ?? ''));
        if ($description === '') {
            continue;
        }
        $qtyRaw = $item['qty'] ?? '';
        $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : 0.0;
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Each item must have a quantity greater than zero.'];
        }
        $validItems[] = [
            'sku' => trim((string) ($item['sku'] ?? '')),
            'description' => $description,
            'qty' => $qty,
            'unit' => trim((string) ($item['unit'] ?? '')),
            'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
        ];
    }

    if ($validItems === []) {
        return ['ok' => false, 'error' => 'Please add at least one item.'];
    }

    $noteNumber = 'DN-' . strtoupper(substr(uniqid(), -6));
    $itemsJson = json_encode($validItems, JSON_UNESCAPED_UNICODE);

    try {
        $stmtSig = $pdo->prepare('SELECT signature_path FROM users WHERE id = ?');
        $stmtSig->execute([$userId]);
        $creatorSig = $stmtSig->fetchColumn() ?: null;

        $stmt = $pdo->prepare(
            'INSERT INTO delivery_notes
            (note_number, customer_name, customer_phone, delivery_address, delivery_date, items_json, created_by, authorized_signature_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $noteNumber,
            $customerName,
            $customerPhone,
            $deliveryAddress,
            $deliveryDate,
            $itemsJson,
            $userId,
            $creatorSig,
        ]);

        $newId = (int) $pdo->lastInsertId();
        $seqNumber = 'DN-' . (1000 + $newId);
        $pdo->prepare('UPDATE delivery_notes SET note_number = ? WHERE id = ?')->execute([$seqNumber, $newId]);

        $listUrl = function_exists('deliveries_module_url')
            ? deliveries_module_url('deliveries/delivery_notes.php')
            : 'delivery_notes.php?module=deliveries';

        return [
            'ok' => true,
            'data' => [
                'noteId' => $newId,
                'noteNumber' => $seqNumber,
                'message' => 'Delivery note created successfully.',
                'redirectUrl' => $listUrl . (strpos($listUrl, '?') !== false ? '&' : '?') . 'success=created',
            ],
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
