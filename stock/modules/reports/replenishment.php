<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

$salesFunctionsFile = dirname(__DIR__, 4) . '/modules/sales/functions.php';
if (is_file($salesFunctionsFile)) {
    require_once $salesFunctionsFile;
}

/**
 * @return array{0:string,1:bool}
 */
function replenishment_schema_hints(PDO $db): array
{
    static $cache = [];
    $key = spl_object_id($db);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $lineQtyExpr = 'soi.quantity';
    $hasShippedQty = false;
    try {
        $soiCols = $db->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('shipped_quantity', $soiCols, true)) {
            $hasShippedQty = true;
            $lineQtyExpr = 'GREATEST(soi.quantity - COALESCE(soi.shipped_quantity, 0), 0)';
        }
    } catch (Throwable $e) {
    }

    $cache[$key] = [$lineQtyExpr, $hasShippedQty];
    return $cache[$key];
}

function replenishment_open_order_filter(array $soCols, bool $hasShippedQty): string
{
    $filter = "so.status NOT IN ('cancelled', 'canceled', 'shipped', 'delivered')";
    if (!$hasShippedQty && in_array('shipped_at', $soCols, true)) {
        $filter .= " AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00' OR so.shipped_at = '')";
    }

    return $filter;
}

function replenishment_committed_status_filter(): string
{
    return "so.status IN ('confirmed', 'invoiced', 'paid', 'processing', 'on_hold')";
}

/**
 * @return array<int, PDO>
 */
function replenishment_resolve_sales_pdos(): array
{
    global $pdo, $control_pdo;

    $connections = [];
    $seen = [];

    $add = static function ($conn) use (&$connections, &$seen): void {
        if (!$conn instanceof PDO) {
            return;
        }
        $key = spl_object_id($conn);
        if (isset($seen[$key])) {
            return;
        }
        try {
            $conn->query('SELECT 1 FROM sales_order_items LIMIT 1');
            $seen[$key] = true;
            $connections[] = $conn;
        } catch (Throwable $e) {
        }
    };

    $add($pdo ?? null);
    if (function_exists('sales_pdo')) {
        try {
            $add(sales_pdo());
        } catch (Throwable $e) {
        }
    }
    $add($control_pdo ?? null);

    return $connections;
}

/**
 * @return array<int, array<string, mixed>>
 */
function replenishment_query_product_sources(PDO $db, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    try {
        $db->query('SELECT 1 FROM sales_order_items LIMIT 1');
    } catch (Throwable $e) {
        return [];
    }

    $soCols = [];
    $soiCols = [];
    $invCols = [];
    $hasCustomers = false;
    $hasInvoices = false;
    $hasInvoiceItems = false;
    $hasProducts = false;

    try {
        $soCols = $db->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $soiCols = $db->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    try {
        $hasCustomers = (bool) $db->query("SHOW TABLES LIKE 'customers'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $hasProducts = (bool) $db->query("SHOW TABLES LIKE 'products'")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $hasInvoices = (bool) $db->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
        if ($hasInvoices) {
            $invCols = $db->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (Throwable $e) {
    }
    try {
        $hasInvoiceItems = (bool) $db->query("SHOW TABLES LIKE 'invoice_items'")->fetchColumn();
    } catch (Throwable $e) {
    }

    if (in_array('order_number', $soCols, true)) {
        $orderNumberExpr = 'so.order_number';
    } elseif (in_array('formatted_number', $soCols, true)) {
        $orderNumberExpr = 'so.formatted_number';
    } else {
        $orderNumberExpr = "CONCAT('SO-', so.id)";
    }

    [$lineQtyExpr, $hasShippedQty] = replenishment_schema_hints($db);

    $customerSelect = $hasCustomers ? 'c.company_name AS customer_name' : "'' AS customer_name";
    $customerJoin = $hasCustomers ? 'LEFT JOIN customers c ON so.customer_id = c.id' : '';

    $invoiceNumberExpr = "CONCAT('INV-', i.id)";
    $invoiceDateExpr = 'NULL';
    $invoiceStatusExpr = "''";
    if ($hasInvoices) {
        $invoiceNumberExpr = in_array('invoice_number', $invCols, true)
            ? 'i.invoice_number'
            : "CONCAT('INV-', i.id)";
        $invoiceDateExpr = in_array('invoice_date', $invCols, true)
            ? 'i.invoice_date'
            : (in_array('created_at', $invCols, true) ? 'i.created_at' : 'NULL');
        $invoiceStatusExpr = in_array('status', $invCols, true) ? 'i.status' : "''";
    }

    $productMatch = 'soi.product_id = ?';
    $params = [$productId];
    if ($hasProducts) {
        $productMatch = '(soi.product_id = ? OR soi.product_id IN (SELECT id FROM products WHERE id = ? OR product_code = (SELECT product_code FROM products WHERE id = ? LIMIT 1)))';
        $params = [$productId, $productId, $productId];
    }

    $openOrderFilter = replenishment_open_order_filter($soCols, $hasShippedQty);
    $detailStatusFilter = replenishment_committed_status_filter();

    $queries = [];

    if ($hasInvoices) {
        $invoiceCancelFilter = in_array('status', $invCols, true)
            ? " AND (i.status IS NULL OR i.status NOT IN ('cancelled', 'canceled'))"
            : '';
        $queries[] = [
            'sql' => "
                SELECT
                    so.id AS order_id,
                    {$orderNumberExpr} AS order_number,
                    so.status AS order_status,
                    COALESCE(so.created_at, so.quote_date) AS order_date,
                    {$lineQtyExpr} AS line_qty,
                    {$customerSelect},
                    i.id AS invoice_id,
                    {$invoiceNumberExpr} AS invoice_number,
                    {$invoiceDateExpr} AS invoice_date,
                    {$invoiceStatusExpr} AS invoice_status
                FROM sales_order_items soi
                INNER JOIN sales_orders so ON soi.order_id = so.id
                INNER JOIN invoices i ON i.order_id = so.id
                {$customerJoin}
                WHERE {$productMatch}
                  AND {$detailStatusFilter}
                  AND {$openOrderFilter}
                  {$invoiceCancelFilter}
                  AND {$lineQtyExpr} > 0
            ",
            'params' => $params,
        ];
    }

    $invoiceSelect = $hasInvoices
        ? "i.id AS invoice_id, {$invoiceNumberExpr} AS invoice_number, {$invoiceDateExpr} AS invoice_date, {$invoiceStatusExpr} AS invoice_status"
        : 'NULL AS invoice_id, NULL AS invoice_number, NULL AS invoice_date, NULL AS invoice_status';
    $invoiceJoin = $hasInvoices ? 'LEFT JOIN invoices i ON i.order_id = so.id' : '';
    if ($hasInvoices && in_array('status', $invCols, true)) {
        $invoiceJoin = "LEFT JOIN invoices i ON i.order_id = so.id AND (i.status IS NULL OR i.status NOT IN ('cancelled', 'canceled'))";
    }

    $queries[] = [
        'sql' => "
            SELECT
                so.id AS order_id,
                {$orderNumberExpr} AS order_number,
                so.status AS order_status,
                COALESCE(so.created_at, so.quote_date) AS order_date,
                {$lineQtyExpr} AS line_qty,
                {$customerSelect},
                {$invoiceSelect}
            FROM sales_order_items soi
            INNER JOIN sales_orders so ON soi.order_id = so.id
            {$customerJoin}
            {$invoiceJoin}
            WHERE {$productMatch}
              AND {$detailStatusFilter}
              AND {$openOrderFilter}
              AND {$lineQtyExpr} > 0
        ",
        'params' => $params,
    ];

    if ($hasInvoiceItems && $hasInvoices) {
        $iiCols = [];
        try {
            $iiCols = $db->query('SHOW COLUMNS FROM invoice_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $iiCols = [];
        }
        $iiQtyExpr = in_array('quantity', $iiCols, true) ? 'ii.quantity' : '1';
        $iiProductMatch = in_array('product_id', $iiCols, true)
            ? ( $hasProducts
                ? '(ii.product_id = ? OR ii.product_id IN (SELECT id FROM products WHERE id = ? OR product_code = (SELECT product_code FROM products WHERE id = ? LIMIT 1)))'
                : 'ii.product_id = ?' )
            : '1 = 0';
        $iiParams = $hasProducts ? [$productId, $productId, $productId] : [$productId];
        $invoiceCancelFilter = in_array('status', $invCols, true)
            ? " AND (i.status IS NULL OR i.status NOT IN ('cancelled', 'canceled'))"
            : '';
        $orderJoin = in_array('order_id', $invCols, true) ? 'LEFT JOIN sales_orders so ON so.id = i.order_id' : 'LEFT JOIN sales_orders so ON 1 = 0';
        $invCustomerJoin = ($hasCustomers && in_array('customer_id', $invCols, true))
            ? 'LEFT JOIN customers c ON c.id = COALESCE(so.customer_id, i.customer_id)'
            : $customerJoin;
        $queries[] = [
            'sql' => "
                SELECT
                    so.id AS order_id,
                    {$orderNumberExpr} AS order_number,
                    so.status AS order_status,
                    COALESCE(i.invoice_date, i.created_at, so.created_at) AS order_date,
                    {$iiQtyExpr} AS line_qty,
                    {$customerSelect},
                    i.id AS invoice_id,
                    {$invoiceNumberExpr} AS invoice_number,
                    {$invoiceDateExpr} AS invoice_date,
                    {$invoiceStatusExpr} AS invoice_status
                FROM invoice_items ii
                INNER JOIN invoices i ON ii.invoice_id = i.id
                {$orderJoin}
                {$invCustomerJoin}
                WHERE {$iiProductMatch}
                  {$invoiceCancelFilter}
                  AND (so.id IS NULL OR ({$detailStatusFilter} AND {$openOrderFilter}))
            ",
            'params' => $iiParams,
        ];
    }

    $merged = [];
    $seenKeys = [];

    foreach ($queries as $query) {
        $sql = $query['sql'] . ' ORDER BY order_date DESC, order_id DESC';
        $qParams = $query['params'];
        if (function_exists('salesCompanyScopeSql')) {
            $scope = salesCompanyScopeSql('sales_orders', 'so');
            if (!empty($scope[0])) {
                $sql = str_replace('WHERE ', 'WHERE 1=1' . $scope[0] . ' AND ', $sql);
                $qParams = array_merge($qParams, $scope[1] ?? []);
            }
        }

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($qParams);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $orderId = (int) ($row['order_id'] ?? 0);
                $invoiceId = (int) ($row['invoice_id'] ?? 0);
                $dedupeKey = $orderId . ':' . $invoiceId . ':' . ($row['invoice_number'] ?? '');
                if (isset($seenKeys[$dedupeKey])) {
                    continue;
                }
                $seenKeys[$dedupeKey] = true;
                $merged[] = $row;
            }
        } catch (Throwable $e) {
            error_log('replenishment_query_product_sources: ' . $e->getMessage());
        }
    }

    return $merged;
}

/**
 * @return array<int, array<string, mixed>>
 */
function replenishment_fetch_product_demand_sources(int $productId): array
{
    $merged = [];
    $seenKeys = [];

    foreach (replenishment_resolve_sales_pdos() as $db) {
        foreach (replenishment_query_product_sources($db, $productId) as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);
            $invoiceId = (int) ($row['invoice_id'] ?? 0);
            $dedupeKey = $orderId . ':' . $invoiceId . ':' . ($row['invoice_number'] ?? '');
            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }
            $seenKeys[$dedupeKey] = true;
            $merged[] = $row;
        }
    }

    usort($merged, static function ($a, $b) {
        return strcmp((string) ($b['order_date'] ?? ''), (string) ($a['order_date'] ?? ''));
    });

    return $merged;
}

/**
 * Payment voucher attached to our sales invoice for this product.
 * The supplier invoice stays on the voucher; this only links the voucher.
 *
 * @return array<int, array<string, mixed>>
 */
function replenishment_fetch_supplier_invoices(int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    $salesRows = replenishment_fetch_product_demand_sources($productId);
    $needleMeta = [];
    foreach ($salesRows as $row) {
        $pairs = [
            [trim((string) ($row['invoice_number'] ?? '')), 'invoice'],
            [trim((string) ($row['order_number'] ?? '')), 'order'],
        ];
        foreach ($pairs as [$raw, $kind]) {
            if (strlen($raw) < 6 || !preg_match('/[A-Za-z]/', $raw)) {
                continue;
            }
            $canonicalInvoice = $kind === 'invoice' ? $raw : '';
            $canonicalOrder = $kind === 'order' ? $raw : '';
            foreach ([$raw, str_replace('-', '/', $raw)] as $form) {
                if (!isset($needleMeta[$form])) {
                    $needleMeta[$form] = ['invoice' => '', 'order' => ''];
                }
                if ($canonicalInvoice !== '' && $needleMeta[$form]['invoice'] === '') {
                    $needleMeta[$form]['invoice'] = $canonicalInvoice;
                }
                if ($canonicalOrder !== '' && $needleMeta[$form]['order'] === '') {
                    $needleMeta[$form]['order'] = $canonicalOrder;
                }
            }
        }
    }
    if (!$needleMeta) {
        return [];
    }

    $db = null;
    if (function_exists('voucher_operational_pdo')) {
        try {
            $candidate = voucher_operational_pdo();
            if ($candidate instanceof PDO) {
                $db = $candidate;
            }
        } catch (Throwable $e) {
            $db = null;
        }
    }
    if (!$db instanceof PDO) {
        global $pdo;
        $db = $pdo instanceof PDO ? $pdo : null;
    }
    if (!$db instanceof PDO) {
        return [];
    }

    try {
        $hasVouchers = (bool) $db->query("SHOW TABLES LIKE 'payment_vouchers'")->fetchColumn();
    } catch (Throwable $e) {
        return [];
    }
    if (!$hasVouchers) {
        return [];
    }

    $pvCols = [];
    try {
        $pvCols = $db->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $select = ['pv.id'];
    foreach (['voucher_no', 'payee_name', 'status', 'date_created', 'created_at', 'is_reference'] as $col) {
        if (in_array($col, $pvCols, true)) {
            $select[] = 'pv.' . $col;
        }
    }

    $hasAttachments = false;
    try {
        $hasAttachments = (bool) $db->query("SHOW TABLES LIKE 'voucher_attachments'")->fetchColumn();
    } catch (Throwable $e) {
        $hasAttachments = false;
    }

    $needles = array_keys($needleMeta);
    $matched = [];
    foreach (array_chunk($needles, 12) as $chunk) {
        $params = [];
        foreach ($chunk as $needle) {
            $params[] = '%' . $needle . '%';
        }

        $queries = [];
        if ($hasAttachments) {
            $queries[] = [
                'sql' => 'SELECT ' . implode(', ', $select) . ', va.original_name AS hit_text
                    FROM voucher_attachments va
                    INNER JOIN payment_vouchers pv ON pv.id = va.voucher_id
                    WHERE ' . implode(' OR ', array_fill(0, count($chunk), 'va.original_name LIKE ?')),
                'params' => $params,
            ];
        }
        if (in_array('description', $pvCols, true)) {
            $queries[] = [
                'sql' => 'SELECT ' . implode(', ', $select) . ', pv.description AS hit_text
                    FROM payment_vouchers pv
                    WHERE ' . implode(' OR ', array_fill(0, count($chunk), 'pv.description LIKE ?')),
                'params' => $params,
            ];
        }

        foreach ($queries as $query) {
            try {
                $stmt = $db->prepare($query['sql']);
                $stmt->execute($query['params']);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $voucherId = (int) ($row['id'] ?? 0);
                    $text = (string) ($row['hit_text'] ?? '');
                    if ($voucherId <= 0 || $text === '') {
                        continue;
                    }
                    foreach ($chunk as $needle) {
                        if (!preg_match('/(?<![A-Za-z0-9])' . preg_quote($needle, '/') . '(?![0-9])/i', $text)) {
                            continue;
                        }
                        if (!isset($matched[$voucherId])) {
                            $matched[$voucherId] = $row;
                            $matched[$voucherId]['invoices'] = [];
                            $matched[$voucherId]['orders'] = [];
                        }
                        $meta = $needleMeta[$needle] ?? ['invoice' => '', 'order' => ''];
                        if ($meta['invoice'] !== '') {
                            $matched[$voucherId]['invoices'][$meta['invoice']] = $meta['invoice'];
                        }
                        if ($meta['order'] !== '') {
                            $matched[$voucherId]['orders'][$meta['order']] = $meta['order'];
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('replenishment_fetch_supplier_invoices: ' . $e->getMessage());
            }
        }
    }

    $voucherUrl = function_exists('app_url')
        ? app_url('view-voucher.php?id=')
        : '/view-voucher.php?id=';

    $out = [];
    foreach ($matched as $voucherId => $voucher) {
        $voucherNo = trim((string) ($voucher['voucher_no'] ?? ''));
        if ($voucherNo === '') {
            $voucherNo = 'PV-' . $voucherId;
        }
        $ourDocs = array_values($voucher['invoices'] ?? []);
        if (!$ourDocs) {
            $ourDocs = array_values($voucher['orders'] ?? []);
        }
        $placeUrl = $voucherUrl . $voucherId;

        $out[] = [
            'order_id' => $voucherId,
            'order_number' => implode(', ', $ourDocs),
            'order_status' => (string) ($voucher['status'] ?? ''),
            'order_date' => (string) ($voucher['date_created'] ?? $voucher['created_at'] ?? ''),
            'line_qty' => '',
            'customer_name' => trim((string) ($voucher['payee_name'] ?? '')),
            'invoice_id' => $voucherId,
            'invoice_number' => $voucherNo,
            'invoice_date' => (string) ($voucher['date_created'] ?? $voucher['created_at'] ?? ''),
            'invoice_status' => (string) ($voucher['status'] ?? ''),
            'invoice_url' => $placeUrl,
            'invoice_print_url' => '',
            'order_url' => '',
            'our_invoice' => implode(', ', $ourDocs),
            'is_supplier_invoice' => true,
            'is_payment_voucher' => true,
            'is_reference' => (int) ($voucher['is_reference'] ?? 0) === 1,
        ];
    }

    usort($out, static function ($a, $b) {
        return strcmp((string) ($b['invoice_date'] ?? ''), (string) ($a['invoice_date'] ?? ''));
    });

    return $out;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function replenishment_map_sales_invoice_rows(array $rows): array
{
    $invoiceViewBase = function_exists('app_url')
        ? app_url('modules/sales/invoices/view.php?id=')
        : '/modules/sales/invoices/view.php?id=';
    $invoicePrintBase = function_exists('app_url')
        ? app_url('modules/sales/invoices/print.php?id=')
        : '/modules/sales/invoices/print.php?id=';
    $orderViewBase = function_exists('app_url')
        ? app_url('modules/sales/orders/view.php?id=')
        : '/modules/sales/orders/view.php?id=';

    $out = [];
    foreach ($rows as $row) {
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        $out[] = [
            'order_id' => $orderId,
            'order_number' => (string) ($row['order_number'] ?? ($orderId > 0 ? 'SO-' . $orderId : '')),
            'order_status' => (string) ($row['order_status'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? ''),
            'line_qty' => (float) ($row['line_qty'] ?? 0),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceId > 0 ? (string) ($row['invoice_number'] ?? ('INV-' . $invoiceId)) : '',
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'invoice_status' => (string) ($row['invoice_status'] ?? ''),
            'invoice_url' => $invoiceId > 0 ? $invoiceViewBase . $invoiceId : '',
            'invoice_print_url' => $invoiceId > 0 ? $invoicePrintBase . $invoiceId : '',
            'order_url' => $orderId > 0 ? $orderViewBase . $orderId : '',
        ];
    }

    return $out;
}

$replenishmentApiUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'replenishment.php'), '?') . '?action=invoices';
$investigateApiUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'replenishment.php'), '?') . '?action=investigate';

/**
 * Resolve stocks_po_items.item_id candidates for a sales products.id.
 * PO lines store stocks_items.id (not products.id); ids often differ.
 *
 * @return list<int>
 */
function replenishment_resolve_po_item_ids(PDO $pdo, int $productId): array
{
    $ids = [];
    if ($productId <= 0) {
        return [];
    }

    // Keep products.id in case some older rows stored it directly.
    $ids[$productId] = true;

    if (function_exists('ensureStockItemForSalesProductId')) {
        try {
            $stockItemId = (int) ensureStockItemForSalesProductId($pdo, $productId);
            if ($stockItemId > 0) {
                $ids[$stockItemId] = true;
            }
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->query('SELECT 1 FROM stocks_items LIMIT 1');
        $st = $pdo->prepare('SELECT name, product_code FROM products WHERE id = ? LIMIT 1');
        $st->execute([$productId]);
        $product = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($product) {
            $name = trim((string) ($product['name'] ?? ''));
            $code = trim((string) ($product['product_code'] ?? ''));
            if ($name !== '' || $code !== '') {
                $find = $pdo->prepare(
                    'SELECT id FROM stocks_items
                     WHERE (LOWER(TRIM(name)) = LOWER(TRIM(?)) AND ? <> \'\')
                        OR (sku IS NOT NULL AND sku <> \'\' AND LOWER(TRIM(sku)) = LOWER(TRIM(?)) AND ? <> \'\')
                        OR (? <> \'\' AND LOWER(TRIM(name)) LIKE CONCAT(\'%\', LOWER(TRIM(?)), \'%\'))
                        OR (? <> \'\' AND sku IS NOT NULL AND LOWER(TRIM(sku)) LIKE CONCAT(\'%\', LOWER(TRIM(?)), \'%\'))'
                );
                $find->execute([$name, $name, $code, $code, $name, $name, $code, $code]);
                foreach ($find->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
                    $sid = (int) $sid;
                    if ($sid > 0) {
                        $ids[$sid] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }

    return array_values(array_map('intval', array_keys($ids)));
}

/**
 * Purchase orders that include this product (stocks + legacy purchases).
 *
 * @return list<array<string,mixed>>
 */
function replenishment_fetch_product_purchase_orders(PDO $pdo, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    $out = [];
    $seen = [];
    $itemIds = replenishment_resolve_po_item_ids($pdo, $productId);
    if ($itemIds === []) {
        $itemIds = [$productId];
    }

    $supplierTable = null;
    foreach (['stocks_suppliers', 'suppliers'] as $candidate) {
        try {
            if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($candidate))->fetchColumn()) {
                $supplierTable = $candidate;
                break;
            }
        } catch (Throwable $e) {
        }
    }

    $supplierNameExpr = "CONCAT('Supplier #', po.supplier_id)";
    $supplierJoin = '';
    if ($supplierTable !== null) {
        try {
            $sCols = $pdo->query("SHOW COLUMNS FROM `{$supplierTable}`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $nameCol = in_array('name', $sCols, true)
                ? 'name'
                : (in_array('supplier_name', $sCols, true)
                    ? 'supplier_name'
                    : (in_array('company_name', $sCols, true) ? 'company_name' : ''));
            if ($nameCol !== '') {
                $supplierNameExpr = "COALESCE(NULLIF(TRIM(sup.`{$nameCol}`), ''), {$supplierNameExpr})";
                $supplierJoin = "LEFT JOIN `{$supplierTable}` sup ON sup.id = po.supplier_id";
            }
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->query('SELECT 1 FROM stocks_po_items LIMIT 1');
        $pdo->query('SELECT 1 FROM stocks_purchase_orders LIMIT 1');
        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $piCols = $pdo->query('SHOW COLUMNS FROM stocks_po_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $selectParts = [
            'po.id AS po_id',
            in_array('po_number', $poCols, true) ? 'po.po_number' : "CONCAT('PO-', po.id) AS po_number",
            in_array('status', $poCols, true) ? 'po.status' : "'' AS status",
            in_array('purchase_type', $poCols, true) ? 'po.purchase_type' : "'domestic' AS purchase_type",
            in_array('currency', $poCols, true) ? 'po.currency' : "'TZS' AS currency",
            in_array('total_amount', $poCols, true) ? 'po.total_amount' : 'NULL AS total_amount',
            in_array('created_at', $poCols, true) ? 'po.created_at' : 'NULL AS created_at',
            in_array('expected_delivery_date', $poCols, true)
                ? 'po.expected_delivery_date'
                : 'NULL AS expected_delivery_date',
            in_array('qty_ordered', $piCols, true) ? 'pi.qty_ordered' : '0 AS qty_ordered',
            in_array('qty_received', $piCols, true) ? 'pi.qty_received' : '0 AS qty_received',
            in_array('unit_cost', $piCols, true) ? 'pi.unit_cost' : 'NULL AS unit_cost',
            "{$supplierNameExpr} AS supplier_name",
        ];

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = $itemIds;
        $where = "pi.item_id IN ({$placeholders})";

        try {
            $stProd = $pdo->prepare('SELECT name, product_code FROM products WHERE id = ? LIMIT 1');
            $stProd->execute([$productId]);
            $prodRow = $stProd->fetch(PDO::FETCH_ASSOC) ?: null;
            $pName = trim((string) ($prodRow['name'] ?? ''));
            $pCode = trim((string) ($prodRow['product_code'] ?? ''));
            if ($pName !== '' || $pCode !== '') {
                $where .= " OR pi.item_id IN (
                    SELECT si.id FROM stocks_items si
                    WHERE (LOWER(TRIM(si.name)) = LOWER(TRIM(?)) AND ? <> '')
                       OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(si.sku)) = LOWER(TRIM(?)) AND ? <> '')
                       OR (? <> '' AND LOWER(TRIM(si.name)) LIKE CONCAT('%', LOWER(TRIM(?)), '%'))
                       OR (? <> '' AND si.sku IS NOT NULL AND LOWER(TRIM(si.sku)) LIKE CONCAT('%', LOWER(TRIM(?)), '%'))
                )";
                array_push($params, $pName, $pName, $pCode, $pCode, $pName, $pName, $pCode, $pCode);
            }
        } catch (Throwable $e) {
        }

        $orderBy = in_array('created_at', $poCols, true) ? 'po.created_at DESC, po.id DESC' : 'po.id DESC';
        $sql = 'SELECT ' . implode(', ', $selectParts) . "
            FROM stocks_po_items pi
            INNER JOIN stocks_purchase_orders po ON po.id = pi.po_id
            {$supplierJoin}
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT 50
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $poId = (int) ($row['po_id'] ?? 0);
            if ($poId <= 0 || isset($seen['stocks:' . $poId])) {
                continue;
            }
            $seen['stocks:' . $poId] = true;
            $out[] = [
                'id' => $poId,
                'source' => 'stocks',
                'po_number' => (string) ($row['po_number'] ?? ('PO-' . $poId)),
                'status' => (string) ($row['status'] ?? ''),
                'purchase_type' => (string) ($row['purchase_type'] ?? 'domestic'),
                'currency' => (string) ($row['currency'] ?? 'TZS'),
                'total_amount' => $row['total_amount'] !== null && $row['total_amount'] !== ''
                    ? (float) $row['total_amount']
                    : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'expected_delivery_date' => (string) ($row['expected_delivery_date'] ?? ''),
                'qty_ordered' => (float) ($row['qty_ordered'] ?? 0),
                'qty_received' => (float) ($row['qty_received'] ?? 0),
                'unit_cost' => $row['unit_cost'] !== null && $row['unit_cost'] !== ''
                    ? (float) $row['unit_cost']
                    : null,
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'view_url' => '../purchases/view_po.php?id=' . $poId,
                'preview_url' => '../purchases/view_po.php?id=' . $poId . '&embed=1',
            ];
        }
    } catch (Throwable $e) {
        error_log('replenishment_fetch_product_purchase_orders stocks: ' . $e->getMessage());
    }

    try {
        $pdo->query('SELECT 1 FROM erp_purchase_order_items LIMIT 1');
        $pdo->query('SELECT 1 FROM erp_purchase_orders LIMIT 1');
        $erpPoCols = $pdo->query('SHOW COLUMNS FROM erp_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $dateExpr = in_array('created_at', $erpPoCols, true)
            ? 'po.created_at'
            : (in_array('order_date', $erpPoCols, true) ? 'po.order_date' : 'NULL');
        $totalExpr = in_array('total_amount', $erpPoCols, true) ? 'po.total_amount' : 'NULL';
        $sql = "
            SELECT po.id AS po_id, po.po_number, po.status, {$totalExpr} AS total_amount,
                   {$dateExpr} AS created_at, poi.quantity AS qty_ordered
            FROM erp_purchase_order_items poi
            INNER JOIN erp_purchase_orders po ON po.id = poi.po_id
            WHERE poi.product_id = ?
            ORDER BY po.id DESC
            LIMIT 50
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$productId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $poId = (int) ($row['po_id'] ?? 0);
            if ($poId <= 0 || isset($seen['erp:' . $poId]) || isset($seen['stocks:' . $poId])) {
                continue;
            }
            $seen['erp:' . $poId] = true;
            $out[] = [
                'id' => $poId,
                'source' => 'erp',
                'po_number' => (string) ($row['po_number'] ?? ('PO-' . $poId)),
                'status' => (string) ($row['status'] ?? ''),
                'purchase_type' => 'erp',
                'currency' => 'TZS',
                'total_amount' => $row['total_amount'] !== null && $row['total_amount'] !== ''
                    ? (float) $row['total_amount']
                    : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'expected_delivery_date' => '',
                'qty_ordered' => (float) ($row['qty_ordered'] ?? 0),
                'qty_received' => 0.0,
                'unit_cost' => null,
                'supplier_name' => '',
                'view_url' => '../../erp/purchasing/view-po.php?id=' . $poId,
                'preview_url' => '../../erp/purchasing/view-po.php?id=' . $poId,
            ];
        }
    } catch (Throwable $e) {
        error_log('replenishment_fetch_product_purchase_orders erp: ' . $e->getMessage());
    }

    try {
        $pdo->query('SELECT 1 FROM purchases LIMIT 1');
        $pCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('product_id', $pCols, true)) {
            $numExpr = in_array('purchase_no', $pCols, true)
                ? 'p.purchase_no'
                : "CONCAT('PO-', p.id)";
            $statusExpr = in_array('status', $pCols, true) ? 'p.status' : "''";
            $totalExpr = in_array('total_amount', $pCols, true) ? 'p.total_amount' : 'NULL';
            $qtyExpr = in_array('quantity', $pCols, true) ? 'p.quantity' : '0';
            $recvExpr = in_array('qty_received', $pCols, true) ? 'p.qty_received' : '0';
            $dateExpr = in_array('created_at', $pCols, true) ? 'p.created_at' : 'NULL';
            $currencyExpr = in_array('currency', $pCols, true) ? 'p.currency' : "'TZS'";

            $sql = "
                SELECT p.id, {$numExpr} AS po_number, {$statusExpr} AS status,
                       {$totalExpr} AS total_amount, {$qtyExpr} AS qty_ordered,
                       {$recvExpr} AS qty_received, {$dateExpr} AS created_at,
                       {$currencyExpr} AS currency, p.supplier_id
                FROM purchases p
                WHERE p.product_id = ?
                ORDER BY p.id DESC
                LIMIT 50
            ";
            $st = $pdo->prepare($sql);
            $st->execute([$productId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $poId = (int) ($row['id'] ?? 0);
                if ($poId <= 0 || isset($seen['legacy:' . $poId])) {
                    continue;
                }
                $seen['legacy:' . $poId] = true;
                $out[] = [
                    'id' => $poId,
                    'source' => 'legacy',
                    'po_number' => (string) ($row['po_number'] ?? ('PO-' . $poId)),
                    'status' => (string) ($row['status'] ?? ''),
                    'purchase_type' => 'legacy',
                    'currency' => (string) ($row['currency'] ?? 'TZS'),
                    'total_amount' => $row['total_amount'] !== null && $row['total_amount'] !== ''
                        ? (float) $row['total_amount']
                        : null,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'expected_delivery_date' => '',
                    'qty_ordered' => (float) ($row['qty_ordered'] ?? 0),
                    'qty_received' => (float) ($row['qty_received'] ?? 0),
                    'unit_cost' => null,
                    'supplier_name' => '',
                    'view_url' => '../purchases/view_po.php?id=' . $poId,
                    'preview_url' => '../purchases/view_po.php?id=' . $poId . '&embed=1',
                ];
            }
        }

        // Also line items on purchases via purchase_items when present.
        try {
            $pdo->query('SELECT 1 FROM purchase_items LIMIT 1');
            $sql = "
                SELECT p.id, " . (in_array('purchase_no', $pCols, true) ? 'p.purchase_no' : "CONCAT('PO-', p.id)") . " AS po_number,
                       " . (in_array('status', $pCols, true) ? 'p.status' : "''") . " AS status,
                       " . (in_array('total_amount', $pCols, true) ? 'p.total_amount' : 'NULL') . " AS total_amount,
                       pi.quantity AS qty_ordered,
                       COALESCE(pi.qty_received, 0) AS qty_received,
                       " . (in_array('created_at', $pCols, true) ? 'p.created_at' : 'NULL') . " AS created_at,
                       " . (in_array('currency', $pCols, true) ? 'p.currency' : "'TZS'") . " AS currency
                FROM purchase_items pi
                INNER JOIN purchases p ON p.id = pi.purchase_id
                WHERE pi.product_id = ?
                ORDER BY p.id DESC
                LIMIT 50
            ";
            $st = $pdo->prepare($sql);
            $st->execute([$productId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $poId = (int) ($row['id'] ?? 0);
                if ($poId <= 0 || isset($seen['legacy:' . $poId]) || isset($seen['stocks:' . $poId])) {
                    continue;
                }
                $seen['legacy:' . $poId] = true;
                $out[] = [
                    'id' => $poId,
                    'source' => 'legacy',
                    'po_number' => (string) ($row['po_number'] ?? ('PO-' . $poId)),
                    'status' => (string) ($row['status'] ?? ''),
                    'purchase_type' => 'legacy',
                    'currency' => (string) ($row['currency'] ?? 'TZS'),
                    'total_amount' => $row['total_amount'] !== null && $row['total_amount'] !== ''
                        ? (float) $row['total_amount']
                        : null,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'expected_delivery_date' => '',
                    'qty_ordered' => (float) ($row['qty_ordered'] ?? 0),
                    'qty_received' => (float) ($row['qty_received'] ?? 0),
                    'unit_cost' => null,
                    'supplier_name' => '',
                    'view_url' => '../purchases/view_po.php?id=' . $poId,
                    'preview_url' => '../purchases/view_po.php?id=' . $poId . '&embed=1',
                ];
            }
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
        error_log('replenishment_fetch_product_purchase_orders legacy: ' . $e->getMessage());
    }

    usort($out, static function ($a, $b) {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $out;
}

/**
 * Full investigation payload for the Product Sales Trace modal.
 *
 * @return array{ok:bool,error?:string,data?:array<string,mixed>}
 */
function replenishment_investigate_product(PDO $pdo, int $productId): array
{
    if ($productId <= 0) {
        return ['ok' => false, 'error' => 'Invalid product id.'];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.product_code, p.name, p.main_image, p.reorder_level, p.hide_replenishment,
                    COALESCE(s.quantity, 0) AS current_stock
             FROM products p
             LEFT JOIN stock s ON s.product_id = p.id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (!$product) {
        return ['ok' => false, 'error' => 'Product not found.'];
    }

    $stockQty = (float) ($product['current_stock'] ?? 0);
    $threshold = (float) ($product['reorder_level'] ?? 0);
    $needsReplenishment = $stockQty < 0
        || ($threshold > 0 && $stockQty <= $threshold);

    $img = '';
    if (function_exists('stock_product_list_image_url')) {
        global $stockBasePath;
        $img = (string) stock_product_list_image_url(
            $productId,
            (string) ($product['main_image'] ?? ''),
            'medium',
            (string) ($stockBasePath ?? '')
        );
    }

    $salesRaw = replenishment_map_sales_invoice_rows(replenishment_fetch_product_demand_sources($productId));
    $salesHistory = [];
    $totalSold = 0.0;
    $totalInvoices = 0;
    $invoiceIds = [];
    foreach ($salesRaw as $row) {
        $qty = (float) ($row['line_qty'] ?? 0);
        $totalSold += $qty;
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        if ($invoiceId > 0 && !isset($invoiceIds[$invoiceId])) {
            $invoiceIds[$invoiceId] = true;
            $totalInvoices++;
        }
        $status = trim((string) ($row['order_status'] ?? $row['invoice_status'] ?? ''));
        if ($status === '') {
            $status = 'open';
        }
        $salesHistory[] = [
            'date' => (string) ($row['invoice_date'] ?? $row['order_date'] ?? ''),
            'invoice_id' => $invoiceId,
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'invoice_url' => (string) ($row['invoice_url'] ?? ''),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'order_url' => (string) ($row['order_url'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'quantity' => $qty,
            'status' => $status,
            'recorded_by' => '',
            'line_value' => null,
        ];
    }

    $movements = [];
    $goodsReceived = 0.0;
    $salesDeductions = 0.0;
    $returnsIn = 0.0;
    $adjustments = 0.0;
    $transfersNet = 0.0;
    $customers = [];
    $warehouses = [];
    $types = [];

    try {
        $hasWh = false;
        $hasUser = false;
        $cols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasWh = in_array('warehouse_id', $cols, true);
        $hasUser = in_array('user_id', $cols, true) || in_array('created_by', $cols, true);
        $userCol = in_array('user_id', $cols, true) ? 'user_id' : (in_array('created_by', $cols, true) ? 'created_by' : '');

        $userSelect = "'' AS user_name";
        $userJoin = '';
        if ($hasUser && $userCol !== '') {
            $userSelect = 'u.full_name AS user_name';
            $userJoin = "LEFT JOIN users u ON u.id = sm.{$userCol}";
        }
        $whSelect = $hasWh ? 'sm.warehouse_id' : 'NULL AS warehouse_id';

        $sql = "SELECT sm.id, sm.movement_type, sm.quantity, sm.reference_type, sm.reference_id,
                       sm.notes, sm.created_at, {$whSelect}, {$userSelect}
                FROM stock_movements sm
                {$userJoin}
                WHERE sm.product_id = ?
                ORDER BY sm.created_at ASC, sm.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute([$productId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $running = 0.0;
        // Infer opening so that after all movements we land on current stock.
        $signedTotal = 0.0;
        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['movement_type'] ?? '')));
            $qty = abs((float) ($row['quantity'] ?? 0));
            $signed = in_array($type, ['out', 'sale', 'transfer_out'], true) ? -$qty : $qty;
            if ($type === 'transfer_in') {
                $signed = $qty;
            }
            $signedTotal += $signed;
        }
        $opening = $stockQty - $signedTotal;
        $running = $opening;

        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['movement_type'] ?? '')));
            $qty = abs((float) ($row['quantity'] ?? 0));
            $isOut = in_array($type, ['out', 'sale', 'transfer_out'], true);
            $signed = $isOut ? -$qty : $qty;
            if ($type === 'in' || $type === 'receive' || $type === 'purchase' || $type === 'grn') {
                $goodsReceived += $qty;
                $typeLabel = 'Goods Received';
            } elseif ($type === 'out' || $type === 'sale') {
                $salesDeductions -= $qty;
                $refType = strtolower(trim((string) ($row['reference_type'] ?? '')));
                $typeLabel = $refType === 'sales_order' || $refType === 'sale' || $refType === 'invoice'
                    ? 'Sales (Invoice)'
                    : 'Stock Out';
            } elseif ($type === 'return' || $type === 'return_in') {
                $returnsIn += $qty;
                $typeLabel = 'Returns In';
            } elseif ($type === 'adjustment' || $type === 'adjust') {
                $adjustments += $signed;
                $typeLabel = 'Adjustment';
            } elseif ($type === 'transfer_in') {
                $transfersNet += $qty;
                $typeLabel = 'Transfer In';
            } elseif ($type === 'transfer_out') {
                $transfersNet -= $qty;
                $typeLabel = 'Transfer Out';
            } else {
                if ($isOut) {
                    $salesDeductions -= $qty;
                } else {
                    $goodsReceived += $qty;
                }
                $typeLabel = $type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Movement';
            }

            $running += $signed;
            $refId = trim((string) ($row['reference_id'] ?? ''));
            $refType = trim((string) ($row['reference_type'] ?? ''));
            $reference = trim((string) ($row['notes'] ?? ''));
            if ($reference === '' && $refType !== '') {
                $reference = ucwords(str_replace('_', ' ', $refType)) . ($refId !== '' ? ' #' . $refId : '');
            }
            if ($reference === '') {
                $reference = 'Movement #' . (int) ($row['id'] ?? 0);
            }

            $whId = $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null;
            if ($whId) {
                $warehouses[(string) $whId] = 'Warehouse ' . $whId;
            }
            $types[$typeLabel] = $typeLabel;

            $movements[] = [
                'id' => (int) ($row['id'] ?? 0),
                'date' => (string) ($row['created_at'] ?? ''),
                'reference' => $reference,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'type' => $typeLabel,
                'movement_type' => $type,
                'quantity' => $signed,
                'balance' => $running,
                'user_name' => trim((string) ($row['user_name'] ?? '')),
                'warehouse_id' => $whId,
            ];
        }

        // Newest first for the table (balances already computed chronologically).
        $movements = array_reverse($movements);
    } catch (Throwable $e) {
        $opening = $stockQty;
        $movements = [];
    }

    if (!isset($opening)) {
        $opening = $stockQty;
    }

    $expected = $opening + $goodsReceived + $salesDeductions + $returnsIn + $adjustments + $transfersNet;
    $discrepancy = abs($expected - $stockQty) > 0.0001;

    foreach ($salesHistory as $row) {
        $cust = trim((string) ($row['customer_name'] ?? ''));
        if ($cust !== '') {
            $customers[$cust] = $cust;
        }
    }

    $why = '';
    if ($stockQty < 0) {
        $why = 'The current stock is below the set threshold'
            . ($threshold > 0 ? ' (' . rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . ')' : '')
            . '. This product has a negative stock balance of '
            . rtrim(rtrim(number_format($stockQty, 2, '.', ''), '0'), '.') . '.';
    } elseif ($threshold > 0 && $stockQty <= $threshold) {
        $why = 'Current stock (' . rtrim(rtrim(number_format($stockQty, 2, '.', ''), '0'), '.')
            . ') is at or below the replenishment threshold ('
            . rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.') . ').';
    } else {
        $why = 'This product is not currently flagged by the negative-stock replenishment rule.';
    }

    return [
        'ok' => true,
        'data' => [
            'product' => [
                'id' => $productId,
                'name' => (string) ($product['name'] ?? ''),
                'product_code' => (string) ($product['product_code'] ?? ''),
                'image_url' => $img,
                'current_stock' => $stockQty,
                'reorder_level' => $threshold,
                'needs_replenishment' => $needsReplenishment,
            ],
            'sales_summary' => [
                'total_qty_sold' => $totalSold,
                'total_invoices' => $totalInvoices > 0 ? $totalInvoices : count($salesHistory),
                'total_bought' => $goodsReceived,
                'currency' => 'TZS',
            ],
            'sales_history' => $salesHistory,
            'stock_movements' => $movements,
            'reconciliation' => [
                'opening_stock' => $opening,
                'goods_received' => $goodsReceived,
                'sales_deductions' => $salesDeductions,
                'returns_in' => $returnsIn,
                'adjustments' => $adjustments,
                'transfers_net' => $transfersNet,
                'expected_stock' => $expected,
                'current_stock' => $stockQty,
                'has_discrepancy' => $discrepancy,
            ],
            'why_on_list' => $why,
            'filters' => [
                'customers' => array_values($customers),
                'types' => array_values($types),
                'warehouses' => array_map(
                    static fn ($id, $label) => ['id' => (int) $id, 'label' => $label],
                    array_keys($warehouses),
                    array_values($warehouses)
                ),
            ],
            'supplier_invoices' => replenishment_fetch_supplier_invoices($productId),
            'purchase_orders' => (static function () use ($pdo, $productId, $product): array {
                $rows = replenishment_fetch_product_purchase_orders($pdo, $productId);
                // Temporary diagnostic for empty-PO cases (e.g. RUNGU). Safe to remove later.
                try {
                    $code = trim((string) ($product['product_code'] ?? ''));
                    $trace = [
                        'at' => date('c'),
                        'db' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
                        'product_id' => $productId,
                        'product_code' => $code,
                        'product_name' => (string) ($product['name'] ?? ''),
                        'item_ids' => replenishment_resolve_po_item_ids($pdo, $productId),
                        'po_count' => count($rows),
                        'po_numbers' => array_values(array_map(
                            static fn ($r) => (string) ($r['po_number'] ?? ''),
                            $rows
                        )),
                    ];
                    @file_put_contents(
                        __DIR__ . '/_po_trace_last.json',
                        json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                } catch (Throwable $e) {
                }
                return $rows;
            })(),
        ],
    ];
}

if (isset($_GET['action']) && $_GET['action'] === 'invoices') {
    header('Content-Type: application/json; charset=utf-8');

    $productId = (int) ($_GET['product_id'] ?? 0);
    if ($productId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid product id.', 'items' => []]);
        exit;
    }

    $sales = replenishment_map_sales_invoice_rows(replenishment_fetch_product_demand_sources($productId));
    $supplier = replenishment_fetch_supplier_invoices($productId);

    echo json_encode([
        'ok' => true,
        'product_id' => $productId,
        'count' => count($sales),
        'items' => $sales,
        'supplier_items' => $supplier,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'investigate') {
    header('Content-Type: application/json; charset=utf-8');
    $productId = (int) ($_GET['product_id'] ?? 0);
    $result = replenishment_investigate_product($pdo, $productId);
    if (!($result['ok'] ?? false)) {
        http_response_code(422);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Ensure schema
try {
    $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hide_replenishment', $cols, true)) {
        $pdo->exec('ALTER TABLE products ADD COLUMN hide_replenishment TINYINT(1) DEFAULT 0');
    }
} catch (Exception $e) {
}

// Logic: products with negative warehouse stock only
$items = [];
$sales_tables_exist = false;
try {
    $pdo->query('SELECT 1 FROM sales_order_items LIMIT 1');
    $sales_tables_exist = true;
} catch (Throwable $e) {
}

if ($sales_tables_exist) {
    [$pendingQtyExpr, $hasShippedQtyMain] = replenishment_schema_hints($pdo);
    $pendingShippedFilter = $hasShippedQtyMain ? " AND {$pendingQtyExpr} > 0" : '';
    $soColsMain = [];
    try {
        $soColsMain = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }
    $pendingStatusFilter = replenishment_committed_status_filter();
    $pendingOpenFilter = replenishment_open_order_filter($soColsMain, $hasShippedQtyMain);

    $sql = "
        SELECT 
            p.id, 
            p.product_code, 
            p.name, 
            p.main_image,
            p.reorder_level,
            COALESCE(s.quantity, 0) as current_stock,
            (
                SELECT COALESCE(SUM({$pendingQtyExpr}), 0)
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.order_id = so.id
                WHERE soi.product_id = p.id
                AND {$pendingStatusFilter}
                AND {$pendingOpenFilter}
                {$pendingShippedFilter}
            ) as pending_demand,
            p.hide_replenishment
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        WHERE (p.hide_replenishment IS NULL OR p.hide_replenishment = 0)
        AND COALESCE(s.quantity, 0) < 0
        ORDER BY current_stock ASC
    ";
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "
        SELECT 
            p.id, 
            p.product_code, 
            p.name, 
            p.main_image,
            p.reorder_level,
            COALESCE(s.quantity, 0) as current_stock,
            0 as pending_demand,
            p.hide_replenishment
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id
        WHERE (p.hide_replenishment IS NULL OR p.hide_replenishment = 0)
        AND COALESCE(s.quantity, 0) < 0
        ORDER BY COALESCE(s.quantity, 0) ASC
    ";
    try {
        $stmt = $pdo->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $items = [];
    }
}

$search = trim((string) ($_GET['search'] ?? ''));

$shortageCount = 0;
foreach ($items as $row) {
    if ((int) ($row['current_stock'] ?? 0) < 0) {
        $shortageCount++;
    }
}

$purchasesHref = '../purchases/index.php';
$stockReportHref = 'stock.php';

require_once __DIR__ . '/../../config/paths.php';

$itemsPayload = [];
foreach ($items as $item) {
    $productId = (int) ($item['id'] ?? 0);
    $filename = (string) ($item['main_image'] ?? '');
    $img = function_exists('stock_product_list_image_url')
        ? stock_product_list_image_url($productId, $filename, 'medium', (string) ($stockBasePath ?? ''))
        : '';

    $demandSources = $productId > 0
        ? replenishment_map_sales_invoice_rows(replenishment_fetch_product_demand_sources($productId))
        : [];
    $supplierInvoices = $productId > 0 ? replenishment_fetch_supplier_invoices($productId) : [];
    $references = [];
    $seenRefs = [];

    foreach ($demandSources as $row) {
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($invoiceId > 0) {
            $refKey = 'inv:' . $invoiceId;
            if (!isset($seenRefs[$refKey])) {
                $seenRefs[$refKey] = true;
                $references[] = [
                    'type' => 'invoice',
                    'id' => $invoiceId,
                    'label' => (string) ($row['invoice_number'] ?? ('INV-' . $invoiceId)),
                    'url' => (string) ($row['invoice_url'] ?? ''),
                    'print_url' => (string) ($row['invoice_print_url'] ?? ''),
                ];
            }
        } elseif ($orderId > 0) {
            $refKey = 'so:' . $orderId;
            if (!isset($seenRefs[$refKey])) {
                $seenRefs[$refKey] = true;
                $references[] = [
                    'type' => 'order',
                    'id' => $orderId,
                    'label' => (string) ($row['order_number'] ?? ('SO-' . $orderId)),
                    'url' => (string) ($row['order_url'] ?? ''),
                ];
            }
        }
    }

    $itemsPayload[] = [
        'id' => $productId,
        'product_code' => (string) ($item['product_code'] ?? ''),
        'name' => (string) ($item['name'] ?? ''),
        'main_image' => $filename,
        'image_url' => (string) $img,
        'current_stock' => (int) ($item['current_stock'] ?? 0),
        'reorder_level' => (int) ($item['reorder_level'] ?? 0),
        'pending_demand' => (int) ($item['pending_demand'] ?? 0),
        'references' => $references,
        'demand_sources' => $demandSources,
        'supplier_invoices' => $supplierInvoices,
    ];
}

$flashMessage = '';
$flashType = '';

$base = !empty($stockBasePath)
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
if (function_exists('app_url')) {
    $assetBase = rtrim(app_url('/stock'), '/') . '/';
} else {
    $assetBase = preg_replace('#/([A-Za-z0-9-]+)/stock/#', '/stock/', $base) ?: $base;
}
if (strpos($assetBase, '/stock/') === false) {
    $assetBase = $base;
}

$page_title = 'Replenishment Report';
$employeeHeaderTitle = 'Replenishment';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

include __DIR__ . '/../../includes/header.php';
?>
<style>
body.page-products-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-products-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-products-desk,
body.page-products-desk.dashboard,
body.page-products-desk .layout-main-wrapper,
body.page-products-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-products-desk .employee-header.employee-header--products-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-products-desk .employee-header--products-desk::after { display: none !important; }
body.page-products-desk .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    font-size: clamp(1.05rem, 2vw, 1.35rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: min(42rem, 70vw);
}
body.page-products-desk .employee-header--products-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.products-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.products-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
    main.main-content.products-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-products-desk,
html[data-theme="dark"] body.page-products-desk.dashboard,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-products-desk main.main-content.products-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to view the replenishment report.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'reports-replenishment',
            'data' => [
                'items' => $itemsPayload,
                'initialSearch' => (string) $search,
                'shortageCount' => (int) $shortageCount,
                'invoicesApiUrl' => $replenishmentApiUrl,
                'investigateApiUrl' => $investigateApiUrl,
                'flashMessage' => $flashMessage,
                'flashType' => $flashType,
                'urls' => [
                    'stockReport' => $stockReportHref,
                    'purchases' => $purchasesHref,
                    'createDomestic' => '../purchases/domestic_create.php',
                    'createImport' => '../purchases/domestic_create.php?purchase_type=import',
                    'referenceToggle' => function_exists('paymentVoucherReferenceToggleUrl')
                        ? paymentVoucherReferenceToggleUrl()
                        : (function_exists('app_url') ? app_url('/toggle-voucher-reference.php') : '/toggle-voucher-reference.php'),
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"reports-replenishment","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
