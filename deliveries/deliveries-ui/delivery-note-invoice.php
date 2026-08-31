<?php

declare(strict_types=1);

require_once __DIR__ . '/load-create-data.php';

/**
 * Ensure delivery_orders.sales_invoice_id exists.
 */
function deliveries_ensure_sales_invoice_column(PDO $pdo): void
{
    // DDL auto-commits in MySQL — never run ALTER mid-transaction.
    if ($pdo->inTransaction()) {
        return;
    }
    try {
        $cols = $pdo->query('DESCRIBE delivery_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('sales_invoice_id', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_orders ADD COLUMN sales_invoice_id INT NULL AFTER invoice_ref');
        }
    } catch (Throwable $e) {
        // non-fatal
    }
}

/**
 * Resolve linked sales invoice id from order row.
 */
function deliveries_resolve_sales_invoice_id(PDO $pdo, array $orderRow): int
{
    deliveries_ensure_sales_invoice_column($pdo);

    $stored = (int) ($orderRow['sales_invoice_id'] ?? 0);
    if ($stored > 0) {
        return $stored;
    }

    $ref = trim((string) ($orderRow['invoice_ref'] ?? ''));
    if ($ref === '') {
        return 0;
    }

    $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!is_file($salesFunctions)) {
        return 0;
    }
    require_once $salesFunctions;

    try {
        $salesDb = sales_pdo();
        if (!sales_connection_has_table($salesDb, 'invoices')) {
            return 0;
        }
        $sql = 'SELECT id FROM invoices i WHERE i.invoice_number = ? AND i.status != \'cancelled\' LIMIT 1';
        $params = [$ref];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, 'invoices', 'i');
        }
        $stmt = $salesDb->prepare($sql);
        $stmt->execute($params);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0 && !empty($orderRow['id'])) {
            $pdo->prepare('UPDATE delivery_orders SET sales_invoice_id = ? WHERE id = ? AND (sales_invoice_id IS NULL OR sales_invoice_id = 0)')
                ->execute([$id, (int) $orderRow['id']]);
        }
        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Ensure delivery_notes.salesperson_name exists.
 */
function deliveries_ensure_delivery_note_salesperson_column(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        return;
    }
    try {
        if (function_exists('ensureDeliveryNotesSchema')) {
            ensureDeliveryNotesSchema();
        }
        $cols = $pdo->query('DESCRIBE delivery_notes')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('salesperson_name', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN salesperson_name VARCHAR(255) NULL AFTER order_id');
        }
    } catch (Throwable $e) {
        // non-fatal
    }
}

/**
 * Salesperson full name from invoice (same logic as modules/sales/invoices/view.php).
 */
function deliveries_fetch_invoice_salesperson(int $invoiceId): string
{
    if ($invoiceId <= 0) {
        return '';
    }

    $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!is_file($salesFunctions)) {
        return '';
    }
    require_once $salesFunctions;

    try {
        $salesDb = sales_pdo();
        if (!sales_connection_has_table($salesDb, 'invoices')) {
            return '';
        }

        $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasOrderJoin = sales_connection_has_table($salesDb, 'sales_orders') && in_array('order_id', $invCols, true);
        $soCols = $hasOrderJoin
            ? ($salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [])
            : [];

        $soJoinSql = $hasOrderJoin ? ' LEFT JOIN sales_orders so ON i.order_id = so.id' : '';
        $hasUsers = sales_connection_has_table($salesDb, 'users');

        $userJoinSql = '';
        $salespersonSelect = "'' AS salesperson";
        if ($hasUsers) {
            $userRef = null;
            if (in_array('created_by', $invCols, true) && in_array('created_by', $soCols, true) && $hasOrderJoin) {
                $userRef = 'COALESCE(i.created_by, so.created_by)';
            } elseif (in_array('created_by', $invCols, true)) {
                $userRef = 'i.created_by';
            } elseif (in_array('created_by', $soCols, true) && $hasOrderJoin) {
                $userRef = 'so.created_by';
            }
            if ($userRef !== null) {
                $userJoinSql = " LEFT JOIN users u ON {$userRef} = u.id";
                $salespersonSelect = 'u.full_name AS salesperson';
            }
        }

        $sql = "SELECT {$salespersonSelect} FROM invoices i{$soJoinSql}{$userJoinSql} WHERE i.id = ? LIMIT 1";
        $params = [$invoiceId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, 'invoices', 'i');
        }
        $stmt = $salesDb->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return trim((string) ($row['salesperson'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * company_id from a linked sales invoice (when present).
 */
function deliveries_fetch_invoice_company_id(int $invoiceId): int
{
    if ($invoiceId <= 0) {
        return 0;
    }

    $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!is_file($salesFunctions)) {
        return 0;
    }
    require_once $salesFunctions;

    try {
        $salesDb = sales_pdo();
        if (!sales_connection_has_table($salesDb, 'invoices')) {
            return 0;
        }
        $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('company_id', $invCols, true)) {
            return 0;
        }
        $sql = 'SELECT company_id FROM invoices i WHERE i.id = ? LIMIT 1';
        $params = [$invoiceId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, 'invoices', 'i');
        }
        $stmt = $salesDb->prepare($sql);
        $stmt->execute($params);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Resolve tenant company for a delivery order (public pages � no session).
 */
function deliveries_resolve_order_company_id(PDO $pdo, array $orderRow): int
{
    if (!empty($orderRow['company_id'])) {
        return (int) $orderRow['company_id'];
    }

    $invoiceId = deliveries_resolve_sales_invoice_id($pdo, $orderRow);
    if ($invoiceId > 0) {
        $fromInvoice = deliveries_fetch_invoice_company_id($invoiceId);
        if ($fromInvoice > 0) {
            return $fromInvoice;
        }
    }

    foreach (['created_by', 'requested_driver_id'] as $userField) {
        $uid = (int) ($orderRow[$userField] ?? 0);
        if ($uid > 0 && function_exists('resolveUserCompanyId')) {
            $cid = (int) resolveUserCompanyId($uid);
            if ($cid > 0) {
                return $cid;
            }
        }
    }

    if (function_exists('defaultCompanyId')) {
        $fallback = (int) (defaultCompanyId() ?? 0);
        if ($fallback > 0) {
            return $fallback;
        }
    }

    return 0;
}

/**
 * Company branding for public delivery pages (logo, name, contact).
 *
 * @return array{companyId:int,name:string,address:string,phone:string,website:string,logoUrl:string}
 */
function deliveries_load_public_company_branding(PDO $pdo, int $companyId): array
{
    $brand = [
        'companyId' => $companyId,
        'name' => '',
        'address' => '',
        'phone' => '',
        'website' => '',
        'logoUrl' => '',
    ];

    try {
        if ($companyId > 0 && function_exists('tableExists') && tableExists('sales_settings', $pdo)) {
            if (function_exists('columnExists') && columnExists('sales_settings', 'company_id', $pdo)) {
                $settingsStmt = $pdo->prepare(
                    'SELECT company_name, company_address, company_phone FROM sales_settings WHERE company_id = ? LIMIT 1'
                );
                $settingsStmt->execute([$companyId]);
            } else {
                $settingsStmt = $pdo->query('SELECT company_name, company_address, company_phone FROM sales_settings LIMIT 1');
            }
            $salesSettings = $settingsStmt ? $settingsStmt->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($salesSettings)) {
                $brand['name'] = trim((string) ($salesSettings['company_name'] ?? ''));
                $brand['address'] = trim((string) ($salesSettings['company_address'] ?? ''));
                $brand['phone'] = trim((string) ($salesSettings['company_phone'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        // non-fatal
    }

    global $control_pdo;
    foreach ([$pdo, $control_pdo ?? null] as $settingsPdo) {
        if (!($settingsPdo instanceof PDO) || $companyId <= 0 || !function_exists('fetchCompanySettingsMap')) {
            continue;
        }
        $settings = fetchCompanySettingsMap($settingsPdo, $companyId);
        if (!empty($settings['company_name'])) {
            $brand['name'] = trim((string) $settings['company_name']);
        }
        if (!empty($settings['company_address'])) {
            $brand['address'] = trim((string) $settings['company_address']);
        }
        if (!empty($settings['company_phone'])) {
            $brand['phone'] = trim((string) $settings['company_phone']);
        }
        if (!empty($settings['company_website'])) {
            $brand['website'] = trim((string) $settings['company_website']);
        }
    }

    if (function_exists('getCompanyById')) {
        $profile = getCompanyById($companyId);
        if (is_array($profile)) {
            if (!empty($profile['company_name'])) {
                $brand['name'] = trim((string) $profile['company_name']);
            }
            if ($brand['website'] === '' && !empty($profile['domain'])) {
                $domain = trim((string) $profile['domain']);
                if ($domain !== '') {
                    $brand['website'] = (strpos($domain, '://') !== false) ? $domain : 'https://' . $domain;
                }
            }
        }
    }

    if ($brand['name'] === '' && defined('COMPANY_NAME') && trim(COMPANY_NAME) !== '') {
        $brand['name'] = trim(COMPANY_NAME);
    }
    if ($brand['address'] === '' && defined('COMPANY_ADDRESS') && trim(COMPANY_ADDRESS) !== '') {
        $brand['address'] = trim(COMPANY_ADDRESS);
    }
    if ($brand['phone'] === '' && defined('COMPANY_PHONE') && trim(COMPANY_PHONE) !== '') {
        $brand['phone'] = trim(COMPANY_PHONE);
    }
    if ($brand['name'] === '') {
        $brand['name'] = 'Company';
    }

    $brand['logoUrl'] = function_exists('getCompanyLogoUrl')
        ? getCompanyLogoUrl($companyId > 0 ? $companyId : null)
        : '';
    if ($brand['logoUrl'] === '' && function_exists('app_url')) {
        $brand['logoUrl'] = app_url('/assets/images/logo.svg');
    }

    return $brand;
}

/**
 * Display name for delivery note Salesperson field (stored value, then invoice, then creator).
 */
function deliveries_delivery_note_salesperson(PDO $pdo, array $note): string
{
    $name = trim((string) ($note['salesperson_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $invoiceId = 0;
    if (!empty($note['order_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
        $stmt->execute([(int) $note['order_id']]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $invoiceId = deliveries_resolve_sales_invoice_id($pdo, $order);
        }
    }

    if ($invoiceId > 0) {
        $name = deliveries_fetch_invoice_salesperson($invoiceId);
        if ($name !== '') {
            deliveries_ensure_delivery_note_salesperson_column($pdo);
            $pdo->prepare(
                'UPDATE delivery_notes SET salesperson_name = ? WHERE id = ? AND (salesperson_name IS NULL OR salesperson_name = \'\')'
            )->execute([$name, (int) ($note['id'] ?? 0)]);
            return $name;
        }
    }

    return trim((string) ($note['creator_name'] ?? ''));
}

/**
 * Backfill salesperson_name on an existing delivery note from its linked invoice.
 */
function deliveries_backfill_delivery_note_salesperson(PDO $pdo, int $dnId, int $invoiceId): void
{
    if ($dnId <= 0 || $invoiceId <= 0) {
        return;
    }
    deliveries_ensure_delivery_note_salesperson_column($pdo);
    $stmt = $pdo->prepare('SELECT salesperson_name FROM delivery_notes WHERE id = ?');
    $stmt->execute([$dnId]);
    if (trim((string) ($stmt->fetchColumn() ?: '')) !== '') {
        return;
    }
    $name = deliveries_fetch_invoice_salesperson($invoiceId);
    if ($name !== '') {
        $pdo->prepare('UPDATE delivery_notes SET salesperson_name = ? WHERE id = ?')->execute([$name, $dnId]);
    }
}

/**
 * @return list<array{sku:string,description:string,qty:float,unit:string,product_id:?int,main_image:string}>
 */
function deliveries_fetch_invoice_line_items(int $invoiceId): array
{
    if ($invoiceId <= 0) {
        return [];
    }

    $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!is_file($salesFunctions)) {
        return [];
    }
    require_once $salesFunctions;

    try {
        $salesDb = sales_pdo();
        if (!sales_connection_has_table($salesDb, 'invoices')) {
            return [];
        }

        $sql = 'SELECT id, order_id FROM invoices i WHERE i.id = ? LIMIT 1';
        $params = [$invoiceId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, 'invoices', 'i');
        }
        $stmt = $salesDb->prepare($sql);
        $stmt->execute($params);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return [];
        }

        $orderId = (int) ($invoice['order_id'] ?? 0);
        if ($orderId <= 0 || !sales_connection_has_table($salesDb, 'sales_order_items')) {
            return [];
        }

        $productImageCol = null;
        try {
            $productCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('main_image', $productCols, true)) {
                $productImageCol = 'main_image';
            } elseif (in_array('image', $productCols, true)) {
                $productImageCol = 'image';
            }
        } catch (Throwable $e) {
            $productImageCol = null;
        }
        $imgSelect = $productImageCol ? "p.`{$productImageCol}` AS main_image" : 'NULL AS main_image';

        $sqlItems = "SELECT soi.*, p.name AS product_name, p.product_code, p.description AS product_description, {$imgSelect}
            FROM sales_order_items soi
            LEFT JOIN products p ON soi.product_id = p.id
            WHERE soi.order_id = ?";
        $stmtItems = $salesDb->prepare($sqlItems);
        $stmtItems->execute([$orderId]);
        $rows = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (function_exists('sales_enrich_order_items_images')) {
            $rows = sales_enrich_order_items_images($rows, $salesDb);
        }

        $items = [];
        foreach ($rows as $row) {
            $desc = trim((string) ($row['product_name'] ?? $row['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $items[] = [
                'sku' => (string) ($row['product_code'] ?? ''),
                'description' => $desc,
                'qty' => (float) ($row['quantity'] ?? 0),
                'unit' => (string) ($row['unit'] ?? 'pcs'),
                'product_id' => !empty($row['product_id']) ? (int) $row['product_id'] : null,
                'main_image' => (string) ($row['main_image'] ?? ''),
            ];
        }
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Create delivery note from order + invoice and link to order.
 *
 * @return array{id:int,note_number:string}|null
 */
function deliveries_create_delivery_note_for_order(PDO $pdo, array $orderRow, int $invoiceId, int $userId): ?array
{
    if (!$pdo->inTransaction() && function_exists('ensureDeliveryNotesSchema')) {
        ensureDeliveryNotesSchema();
    }

    $orderId = (int) ($orderRow['id'] ?? 0);
    if ($orderId <= 0) {
        return null;
    }

    $items = deliveries_fetch_invoice_line_items($invoiceId);
    if ($items === []) {
        $desc = trim((string) ($orderRow['package_description'] ?? ''));
        if ($desc !== '') {
            $items[] = [
                'sku' => '',
                'description' => $desc,
                'qty' => 1,
                'unit' => 'lot',
                'product_id' => null,
                'main_image' => '',
            ];
        }
    }
    if ($items === []) {
        return null;
    }

    $creatorId = $userId > 0 ? $userId : (int) ($orderRow['created_by'] ?? 0);
    if ($creatorId <= 0) {
        $creatorId = (int) ($orderRow['requested_driver_id'] ?? 0);
    }

    $creatorSig = null;
    if ($creatorId > 0) {
        $stmtSig = $pdo->prepare('SELECT signature_path FROM users WHERE id = ?');
        $stmtSig->execute([$creatorId]);
        $creatorSig = $stmtSig->fetchColumn() ?: null;
    }

    $noteNumber = 'DN-' . strtoupper(substr(uniqid(), -6));
    $deliveryDate = date('Y-m-d');
    $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
    deliveries_ensure_delivery_note_salesperson_column($pdo);
    $salespersonName = deliveries_fetch_invoice_salesperson($invoiceId);

    $stmt = $pdo->prepare("
        INSERT INTO delivery_notes
        (note_number, customer_name, customer_phone, delivery_address, delivery_date, items_json, created_by, authorized_signature_path, order_id, salesperson_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $noteNumber,
        (string) ($orderRow['client_name'] ?? ''),
        (string) ($orderRow['client_phone'] ?? ''),
        (string) ($orderRow['delivery_address'] ?? ''),
        $deliveryDate,
        $itemsJson,
        $creatorId > 0 ? $creatorId : 1,
        $creatorSig,
        $orderId,
        $salespersonName !== '' ? $salespersonName : null,
    ]);

    $newId = (int) $pdo->lastInsertId();
    $seqNumber = 'DN-' . (1000 + $newId);
    $pdo->prepare('UPDATE delivery_notes SET note_number = ? WHERE id = ?')->execute([$seqNumber, $newId]);
    $pdo->prepare('UPDATE delivery_orders SET delivery_note_id = ? WHERE id = ?')->execute([$newId, $orderId]);

    $sigPath = (string) ($orderRow['signature_path'] ?? '');
    if (strpos($sigPath, 'client_') !== false) {
        $pdo->prepare('UPDATE delivery_notes SET receiver_signature_path = ? WHERE id = ?')
            ->execute([$sigPath, $newId]);
    }

    return ['id' => $newId, 'note_number' => $seqNumber];
}

/**
 * Ensure order has a delivery note when a sales invoice is linked.
 *
 * @return array{id:int,note_number:string,authorized_signature_path:string,receiver_signature_path:string}|null
 */
function deliveries_ensure_order_delivery_note(PDO $pdo, int $orderId, int $userId = 0): ?array
{
    // DDL auto-commits in MySQL — never run schema ensures mid-transaction.
    if (!$pdo->inTransaction()) {
        if (function_exists('ensureDeliveriesSchema')) {
            ensureDeliveriesSchema();
        }
        if (function_exists('ensureDeliveryNotesSchema')) {
            ensureDeliveryNotesSchema();
        }
        deliveries_ensure_sales_invoice_column($pdo);
    }

    $stmt = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }

    $invoiceId = deliveries_resolve_sales_invoice_id($pdo, $order);
    if ($invoiceId <= 0) {
        return null;
    }

    if (!empty($order['sales_invoice_id']) && (int) $order['sales_invoice_id'] !== $invoiceId) {
        // already stored
    } elseif (empty($order['sales_invoice_id'])) {
        $pdo->prepare('UPDATE delivery_orders SET sales_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $orderId]);
    }

    $dnId = (int) ($order['delivery_note_id'] ?? 0);
    if ($dnId > 0) {
        deliveries_backfill_delivery_note_salesperson($pdo, $dnId, $invoiceId);
        deliveries_sync_order_note_signatures($pdo, $orderId);
        $stmtDn = $pdo->prepare('SELECT id, note_number, authorized_signature_path, receiver_signature_path FROM delivery_notes WHERE id = ?');
        $stmtDn->execute([$dnId]);
        $dn = $stmtDn->fetch(PDO::FETCH_ASSOC);
        return $dn ?: null;
    }

    $created = deliveries_create_delivery_note_for_order($pdo, $order, $invoiceId, $userId);
    if (!$created) {
        return null;
    }

    $stmtDn = $pdo->prepare('SELECT id, note_number, authorized_signature_path, receiver_signature_path FROM delivery_notes WHERE id = ?');
    $stmtDn->execute([$created['id']]);
    return $stmtDn->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Keep delivery note signatures in sync with order signature state.
 */
function deliveries_sync_order_note_signatures(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare('
        SELECT o.signature_path, o.delivery_note_id, o.created_by, o.requested_driver_id,
               dn.authorized_signature_path, dn.receiver_signature_path
        FROM delivery_orders o
        LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
        WHERE o.id = ?
    ');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['delivery_note_id'])) {
        return;
    }

    $dnId = (int) $row['delivery_note_id'];
    $orderSig = (string) ($row['signature_path'] ?? '');

    if (strpos($orderSig, 'client_') !== false && empty($row['receiver_signature_path'])) {
        $pdo->prepare('UPDATE delivery_notes SET receiver_signature_path = ? WHERE id = ?')
            ->execute([$orderSig, $dnId]);
    }

    if (empty($row['authorized_signature_path'])) {
        $uid = (int) ($row['created_by'] ?? 0);
        if ($uid <= 0) {
            $uid = (int) ($row['requested_driver_id'] ?? 0);
        }
        if ($uid > 0) {
            $stmtSig = $pdo->prepare('SELECT signature_path FROM users WHERE id = ?');
            $stmtSig->execute([$uid]);
            $sig = $stmtSig->fetchColumn();
            if ($sig) {
                $pdo->prepare('UPDATE delivery_notes SET authorized_signature_path = ? WHERE id = ?')
                    ->execute([$sig, $dnId]);
            }
        }
    }
}

/**
 * @return array{deliveryNote:?array,invoice:?array,hasDocuments:bool,canDownload:bool}
 */
function deliveries_build_order_documents(PDO $pdo, array $orderRow, bool $isClientSigned, ?string $verifyHash = null): array
{
    $invoiceId = deliveries_resolve_sales_invoice_id($pdo, $orderRow);
    $dnId = (int) ($orderRow['delivery_note_id'] ?? 0);
    $invoiceRef = trim((string) ($orderRow['invoice_ref'] ?? ''));

    $deliveryNote = null;
    if ($dnId > 0) {
        $stmt = $pdo->prepare('SELECT id, note_number, receiver_signature_path FROM delivery_notes WHERE id = ?');
        $stmt->execute([$dnId]);
        $dn = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dn) {
            $viewPath = 'deliveries/view_delivery_note.php?id=' . (int) $dn['id'];
            if ($verifyHash) {
                $viewPath .= '&hash=' . urlencode($verifyHash);
            }
            $deliveryNote = [
                'id' => (int) $dn['id'],
                'number' => (string) $dn['note_number'],
                'viewUrl' => deliveries_resolve_public_path($viewPath),
                'downloadUrl' => deliveries_resolve_public_path($viewPath),
                'hasReceiverSignature' => !empty($dn['receiver_signature_path']),
            ];
        }
    }

    $invoice = null;
    if ($invoiceId > 0) {
        $viewPath = 'modules/sales/invoices/view.php?id=' . $invoiceId;
        $publicPath = 'deliveries/public_invoice.php?id=' . $invoiceId;
        if ($verifyHash) {
            $publicPath .= '&hash=' . urlencode($verifyHash);
        }
        $invoice = [
            'id' => $invoiceId,
            'number' => $invoiceRef !== '' ? $invoiceRef : ('INV-' . $invoiceId),
            'viewUrl' => deliveries_resolve_public_path($viewPath),
            'publicUrl' => deliveries_resolve_public_path($publicPath),
            'downloadUrl' => deliveries_resolve_public_path($publicPath),
        ];
    } elseif ($invoiceRef !== '' && !empty($orderRow['invoice_file'])) {
        $fileUrl = deliveries_resolve_public_path((string) $orderRow['invoice_file']);
        $invoice = [
            'id' => 0,
            'number' => $invoiceRef,
            'viewUrl' => $fileUrl,
            'publicUrl' => $fileUrl,
            'downloadUrl' => $fileUrl,
        ];
    }

    $receipt = null;
    if (!empty($orderRow['receipt_file'])) {
        $receiptUrl = deliveries_resolve_public_path((string) $orderRow['receipt_file']);
        $receipt = [
            'url' => $receiptUrl,
            'viewUrl' => $receiptUrl,
            'downloadUrl' => $receiptUrl,
            'label' => 'Receipt',
        ];
    }

    $hasDocuments = $deliveryNote !== null || $invoice !== null || $receipt !== null;
    $canDownload = $isClientSigned
        || (!empty($deliveryNote['hasReceiverSignature']))
        || $invoice !== null
        || $receipt !== null;

    $shareUrl = '';
    if ($verifyHash) {
        $sharePath = 'deliveries/verify_delivery.php?hash=' . urlencode($verifyHash);
        $shareUrl = function_exists('deliveries_resolve_absolute_url')
            ? deliveries_resolve_absolute_url($sharePath)
            : deliveries_resolve_public_path($sharePath);
    }

    return [
        'deliveryNote' => $deliveryNote,
        'invoice' => $invoice,
        'receipt' => $receipt,
        'hasDocuments' => $hasDocuments,
        'canDownload' => $canDownload,
        'shareUrl' => $shareUrl,
    ];
}

function deliveries_order_verification_hash(PDO $pdo, int $orderId): string
{
    if (!function_exists('generateOrderVerificationHash')) {
        return '';
    }
    return (string) generateOrderVerificationHash($orderId);
}
