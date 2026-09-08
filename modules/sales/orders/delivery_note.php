<?php
// modules/sales/orders/delivery_note.php
// Bridge from a sales order to the Deliveries module delivery note.
// Default: create/update note then redirect to the view page.
// ?format=json (or Accept: application/json): return note payload for in-place PDF download.

require_once __DIR__ . '/../../../includes/config.php';
requireLogin();

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($order_id <= 0) {
    delivery_note_fail(400, 'Invalid Order ID');
}

$wantsJson = (isset($_GET['format']) && strtolower((string) $_GET['format']) === 'json')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

try {
    $stmtOrder = $pdo->prepare('SELECT * FROM sales_orders WHERE id = ?');
    $stmtOrder->execute([$order_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        delivery_note_fail(404, 'Sales Order not found.');
    }

    $stmtCust = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmtCust->execute([(int) ($order['customer_id'] ?? 0)]);
    $customer = $stmtCust->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtItems = $pdo->prepare(
        'SELECT soi.*, p.product_code, p.name as product_name, COALESCE(p.main_image, p.image) AS main_image,
                p.id as product_id, soi.description as item_description
         FROM sales_order_items soi
         LEFT JOIN products p ON soi.product_id = p.id
         WHERE soi.order_id = ?'
    );
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $deliveryItemsMap = [];

    foreach ($items as $item) {
        $pid = $item['product_id'] ?? '0';

        $rawDesc = !empty($item['item_description']) ? $item['item_description'] : ($item['product_name'] ?? 'Unknown Item');
        $desc = trim(preg_replace('/\s+/', ' ', str_replace('&nbsp;', ' ', $rawDesc)));

        $key = $pid . '_' . md5(strtolower($desc));

        if (isset($deliveryItemsMap[$key])) {
            $deliveryItemsMap[$key]['qty'] += (float) $item['quantity'];
        } else {
            $deliveryItemsMap[$key] = [
                'sku' => $item['product_code'] ?? '',
                'description' => $desc,
                'qty' => (float) $item['quantity'],
                'unit' => $item['unit'] ?? 'pckge',
                'product_id' => $item['product_id'],
                'main_image' => $item['main_image'] ?? '',
            ];
        }
    }

    $deliveryItems = array_values($deliveryItemsMap);
    $itemsJson = json_encode($deliveryItems);
    $customerName = $customer['company_name'] ?? 'Unknown Customer';
    $deliveryAddress = $customer['address'] ?? ($order['address'] ?? '');
    $actorUserId = (int) ($_SESSION['user_id'] ?? 1);

    require_once __DIR__ . '/../../../deliveries/deliveries-ui/delivery-note-invoice.php';
    $seller = deliveries_resolve_sales_order_seller($order_id, 0);
    $sellerUserId = (int) ($seller['user_id'] ?? 0);
    if ($sellerUserId <= 0) {
        $sellerUserId = (int) ($order['created_by'] ?? 0);
    }
    $salespersonName = trim((string) ($seller['name'] ?? ''));
    $sellerSignature = trim((string) ($seller['signature_path'] ?? ''));
    if ($sellerSignature === '' && $sellerUserId > 0 && function_exists('deliveries_resolve_user_signature_path')) {
        $sellerSignature = deliveries_resolve_user_signature_path($sellerUserId);
    }

    if ($salespersonName === '' && $sellerUserId > 0) {
        $stmtName = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $stmtName->execute([$sellerUserId]);
        $salespersonName = trim((string) ($stmtName->fetchColumn() ?: ''));
    }

    // Fall back to current user only if the order has no seller.
    $noteOwnerId = $sellerUserId > 0 ? $sellerUserId : $actorUserId;
    if ($salespersonName === '' && $noteOwnerId > 0 && $noteOwnerId !== $sellerUserId) {
        $stmtName = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $stmtName->execute([$noteOwnerId]);
        $salespersonName = trim((string) ($stmtName->fetchColumn() ?: ''));
    }
    if ($sellerSignature === '' && $noteOwnerId > 0 && $sellerUserId <= 0) {
        $sellerSignature = function_exists('deliveries_resolve_user_signature_path')
            ? deliveries_resolve_user_signature_path($noteOwnerId)
            : '';
    }

    deliveries_ensure_delivery_note_salesperson_column($pdo);

    $stmt = $pdo->prepare('SELECT id FROM delivery_notes WHERE order_id = ? LIMIT 1');
    $stmt->execute([$order_id]);
    $existingNoteId = (int) $stmt->fetchColumn();

    if ($existingNoteId > 0) {
        // Always stamp the seller's signature (or clear a wrong person's stamp).
        $stmtUpdate = $pdo->prepare(
            'UPDATE delivery_notes
             SET items_json = ?, customer_name = ?, delivery_address = ?,
                 salesperson_name = COALESCE(NULLIF(?, \'\'), salesperson_name),
                 authorized_signature_path = ?
             WHERE id = ?'
        );
        $stmtUpdate->execute([
            $itemsJson,
            $customerName,
            $deliveryAddress,
            $salespersonName,
            $sellerSignature !== '' ? $sellerSignature : null,
            $existingNoteId,
        ]);
        $noteId = $existingNoteId;
    } else {
        $note_number = 'DN-' . date('y') . str_pad((string) $order_id, 4, '0', STR_PAD_LEFT);

        $sqlInsert = 'INSERT INTO delivery_notes
                      (note_number, customer_name, customer_phone, delivery_address, delivery_date, items_json, created_by, authorized_signature_path, order_id, salesperson_name)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute([
            $note_number,
            $customerName,
            $customer['phone'] ?? '',
            $deliveryAddress,
            date('Y-m-d'),
            $itemsJson,
            $noteOwnerId,
            $sellerSignature !== '' ? $sellerSignature : null,
            $order_id,
            $salespersonName !== '' ? $salespersonName : null,
        ]);

        $noteId = (int) $pdo->lastInsertId();
    }

    if ($wantsJson) {
        require_once __DIR__ . '/../../../deliveries/deliveries-ui/includes/delivery-note-view-lib.php';
        $payload = deliveryNoteViewLoadContext($noteId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'id' => $noteId,
            'note_number' => (string) ($payload['display_note_number'] ?? ''),
            'document_html' => (string) ($payload['document_html'] ?? ''),
            'font_stylesheets' => (string) ($payload['font_stylesheets'] ?? ''),
            'document_font_family' => (string) ($payload['document_font_family'] ?? ''),
            'view_url' => (string) ($payload['urls']['view'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (function_exists('deliveries_module_url')) {
        // Already loaded via delivery-note-view-lib when JSON; for redirect load light helper.
        require_once __DIR__ . '/../../../deliveries/deliveries-ui/load-data.php';
        $viewPath = deliveries_module_url('deliveries/view_delivery_note.php') . '&id=' . $noteId . '&v=final';
    } elseif (function_exists('app_url')) {
        $viewPath = app_url('/deliveries/view_delivery_note.php?id=' . $noteId . '&v=final');
    } else {
        $viewPath = '../../../deliveries/view_delivery_note.php?id=' . $noteId . '&v=final';
    }
    header('Location: ' . $viewPath);
    exit;
} catch (Throwable $e) {
    delivery_note_fail(500, 'Database Error: ' . $e->getMessage());
}

/**
 * @param int $status
 * @param string $message
 */
function delivery_note_fail(int $status, string $message): void
{
    $wantsJson = (isset($_GET['format']) && strtolower((string) $_GET['format']) === 'json')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    http_response_code($status);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    die($message);
}
