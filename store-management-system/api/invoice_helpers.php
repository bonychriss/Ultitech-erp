<?php
/**
 * Store Management  invoice source helpers (modern `invoices` + legacy `erp_invoices`).
 */

declare(strict_types=1);

/**
 * True when this connection has modern sales invoices and/or legacy erp_invoices.
 */
function sms_pdo_has_invoice_tables(PDO $conn): bool
{
    if (!function_exists('tableExists')) {
        return false;
    }

    return tableExists('invoices', $conn) || tableExists('erp_invoices', $conn);
}

/**
 * Resolve a PDO that contains sales invoices for the current company.
 */
function sms_invoices_pdo(PDO $stockPdo): ?PDO
{
    if (sms_pdo_has_invoice_tables($stockPdo)) {
        return $stockPdo;
    }

    $candidates = [];

    if (function_exists('erp_data_pdo')) {
        try {
            $erp = erp_data_pdo();
            if ($erp instanceof PDO) {
                $candidates[] = $erp;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $meta = $stockPdo;
    global $control_pdo;
    if ($control_pdo instanceof PDO) {
        $meta = $control_pdo;
    }

    $cid = 0;
    if (function_exists('currentCompanyId')) {
        $cid = (int) (currentCompanyId() ?? 0);
    }
    if ($cid <= 0 && !empty($_SESSION['company_id'])) {
        $cid = (int) $_SESSION['company_id'];
    }

    if ($cid > 0 && function_exists('tableExists') && tableExists('companies', $meta) && function_exists('connectToTenantDatabase')) {
        try {
            $st = $meta->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $dbName = trim((string) ($st->fetchColumn() ?: ''));
            if ($dbName !== '') {
                $tenant = connectToTenantDatabase($dbName);
                if ($tenant instanceof PDO) {
                    $candidates[] = $tenant;
                }
            }
        } catch (Throwable $e) {
            error_log('sms_invoices_pdo company lookup: ' . $e->getMessage());
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate instanceof PDO && sms_pdo_has_invoice_tables($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array{source:string,id:int}
 */
function sms_parse_invoice_ref(string $ref): array
{
    $ref = trim($ref);
    if (preg_match('/^(sales|erp)[:\-](\d+)$/i', $ref, $m)) {
        return [
            'source' => strtolower($m[1]),
            'id' => (int) $m[2],
        ];
    }

    return [
        'source' => '',
        'id' => (int) $ref,
    ];
}

function sms_invoice_ref(string $source, int $id): string
{
    return strtolower($source) . ':' . $id;
}

function sms_ensure_delivery_note_source_column(PDO $pdo): void
{
    if (!function_exists('tableExists') || !tableExists('erp_delivery_notes', $pdo)) {
        return;
    }
    if (function_exists('columnExists') && columnExists('erp_delivery_notes', 'invoice_source', $pdo)) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE erp_delivery_notes ADD COLUMN invoice_source VARCHAR(20) NULL DEFAULT NULL AFTER invoice_id");
    } catch (Throwable $e) {
        // Column may already exist.
    }
}

/**
 * @return array<string, true>
 */
function sms_released_invoice_ref_map(PDO ...$connections): array
{
    $map = [];
    foreach ($connections as $conn) {
        if (!($conn instanceof PDO)) {
            continue;
        }
        if (!function_exists('tableExists') || !tableExists('erp_delivery_notes', $conn)) {
            continue;
        }
        sms_ensure_delivery_note_source_column($conn);
        try {
            $hasSource = function_exists('columnExists') && columnExists('erp_delivery_notes', 'invoice_source', $conn);
            $sql = $hasSource
                ? "SELECT invoice_id, invoice_source FROM erp_delivery_notes
                   WHERE invoice_id IS NOT NULL AND LOWER(COALESCE(status, '')) = 'delivered'"
                : "SELECT invoice_id, NULL AS invoice_source FROM erp_delivery_notes
                   WHERE invoice_id IS NOT NULL AND LOWER(COALESCE(status, '')) = 'delivered'";
            $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $id = (int) ($row['invoice_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $source = strtolower(trim((string) ($row['invoice_source'] ?? '')));
                if ($source === 'sales' || $source === 'erp') {
                    $map[sms_invoice_ref($source, $id)] = true;
                } else {
                    $map[sms_invoice_ref('sales', $id)] = true;
                    $map[sms_invoice_ref('erp', $id)] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('sms_released_invoice_ref_map: ' . $e->getMessage());
        }
    }

    return $map;
}

/**
 * @param array<string, true> $releasedMap
 */
function sms_lookup_delivery_number(array $releasedMap, string $ref, int $invoiceId, string $source, PDO ...$connections): string
{
    if (!isset($releasedMap[$ref])) {
        return '';
    }
    foreach ($connections as $conn) {
        if (!($conn instanceof PDO) || !function_exists('tableExists') || !tableExists('erp_delivery_notes', $conn)) {
            continue;
        }
        try {
            $hasSource = function_exists('columnExists') && columnExists('erp_delivery_notes', 'invoice_source', $conn);
            if ($hasSource) {
                $st = $conn->prepare(
                    "SELECT delivery_number FROM erp_delivery_notes
                     WHERE invoice_id = ?
                       AND LOWER(COALESCE(status, '')) = 'delivered'
                       AND (invoice_source IS NULL OR invoice_source = '' OR LOWER(invoice_source) = ?)
                     ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$invoiceId, $source]);
            } else {
                $st = $conn->prepare(
                    "SELECT delivery_number FROM erp_delivery_notes
                     WHERE invoice_id = ? AND LOWER(COALESCE(status, '')) = 'delivered'
                     ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$invoiceId]);
            }
            $deliveryNumber = trim((string) ($st->fetchColumn() ?: ''));
            if ($deliveryNumber !== '') {
                return $deliveryNumber;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return '';
}

function sms_fetch_pending_invoices(PDO $pdo, string $filter = 'pending'): array
{
    $invPdo = sms_invoices_pdo($pdo);
    if (!($invPdo instanceof PDO)) {
        return [];
    }

    if (function_exists('sms_ensure_delivery_notes_table')) {
        sms_ensure_delivery_notes_table($pdo);
        sms_ensure_delivery_note_source_column($pdo);
        if ($invPdo !== $pdo) {
            sms_ensure_delivery_notes_table($invPdo);
            sms_ensure_delivery_note_source_column($invPdo);
        }
    }

    $releasedMap = sms_released_invoice_ref_map($pdo, $invPdo);
    $out = [];

    // Modern sales invoices (modules/sales)  where new invoices are created.
    if (function_exists('tableExists') && tableExists('invoices', $invPdo)) {
        $hasOrderItems = tableExists('sales_order_items', $invPdo);
        $lineCountSql = $hasOrderItems
            ? '(SELECT COUNT(*) FROM sales_order_items soi WHERE soi.order_id = i.order_id)'
            : '0';
        $hasCustomers = tableExists('customers', $invPdo);

        $sql = $hasCustomers
            ? "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status,
                      COALESCE(c.company_name, c.contact_person, 'Unknown customer') AS customer_name,
                      COALESCE(c.phone, '') AS customer_phone,
                      {$lineCountSql} AS line_count
               FROM invoices i
               LEFT JOIN customers c ON i.customer_id = c.id
               WHERE (i.status IS NULL OR LOWER(TRIM(i.status)) NOT IN ('cancelled', 'canceled', 'void'))
               ORDER BY i.id DESC
               LIMIT 500"
            : "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status,
                      'Unknown customer' AS customer_name,
                      '' AS customer_phone,
                      {$lineCountSql} AS line_count
               FROM invoices i
               WHERE (i.status IS NULL OR LOWER(TRIM(i.status)) NOT IN ('cancelled', 'canceled', 'void'))
               ORDER BY i.id DESC
               LIMIT 500";

        try {
            $rows = $invPdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $ref = sms_invoice_ref('sales', $id);
                $released = isset($releasedMap[$ref]);
                if ($filter === 'pending' && $released) {
                    continue;
                }
                if ($filter === 'released' && !$released) {
                    continue;
                }
                $out[] = [
                    'id' => $ref,
                    'invoiceNumber' => (string) ($row['invoice_number'] ?? ''),
                    'customerName' => (string) ($row['customer_name'] ?? ''),
                    'customerPhone' => (string) ($row['customer_phone'] ?? ''),
                    'invoiceDate' => (string) ($row['invoice_date'] ?? ''),
                    'totalAmount' => (float) ($row['total_amount'] ?? $row['total'] ?? 0),
                    'invoiceStatus' => (string) ($row['status'] ?? ''),
                    'dispatchStatus' => $released ? 'released' : 'awaiting_release',
                    'lineCount' => (int) ($row['line_count'] ?? 0),
                    'deliveryNumber' => sms_lookup_delivery_number($releasedMap, $ref, $id, 'sales', $pdo, $invPdo),
                    'source' => 'sales',
                ];
            }
        } catch (Throwable $e) {
            error_log('sms_fetch_pending_invoices sales: ' . $e->getMessage());
        }
    }

    // Legacy erp_invoices.
    if (function_exists('tableExists') && tableExists('erp_invoices', $invPdo)) {
        $hasErpItems = tableExists('erp_invoice_items', $invPdo);
        $lineCountSql = $hasErpItems
            ? '(SELECT COUNT(*) FROM erp_invoice_items ii WHERE ii.invoice_id = i.id)'
            : '0';
        $hasErpCustomers = tableExists('erp_customers', $invPdo);
        $sql = $hasErpCustomers
            ? "SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.status,
                      COALESCE(c.name, 'Unknown customer') AS customer_name,
                      COALESCE(c.phone, '') AS customer_phone,
                      {$lineCountSql} AS line_count
               FROM erp_invoices i
               LEFT JOIN erp_customers c ON i.customer_id = c.id
               WHERE (i.status IS NULL OR LOWER(TRIM(i.status)) NOT IN ('void', 'cancelled', 'canceled'))
               ORDER BY i.id DESC
               LIMIT 500"
            : "SELECT i.id, i.invoice_number, i.invoice_date, i.total, i.status,
                      'Unknown customer' AS customer_name,
                      '' AS customer_phone,
                      {$lineCountSql} AS line_count
               FROM erp_invoices i
               WHERE (i.status IS NULL OR LOWER(TRIM(i.status)) NOT IN ('void', 'cancelled', 'canceled'))
               ORDER BY i.id DESC
               LIMIT 500";

        try {
            $rows = $invPdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $ref = sms_invoice_ref('erp', $id);
                $released = isset($releasedMap[$ref]);
                if ($filter === 'pending' && $released) {
                    continue;
                }
                if ($filter === 'released' && !$released) {
                    continue;
                }
                $out[] = [
                    'id' => $ref,
                    'invoiceNumber' => (string) ($row['invoice_number'] ?? ''),
                    'customerName' => (string) ($row['customer_name'] ?? ''),
                    'customerPhone' => (string) ($row['customer_phone'] ?? ''),
                    'invoiceDate' => (string) ($row['invoice_date'] ?? ''),
                    'totalAmount' => (float) ($row['total'] ?? $row['total_amount'] ?? 0),
                    'invoiceStatus' => (string) ($row['status'] ?? ''),
                    'dispatchStatus' => $released ? 'released' : 'awaiting_release',
                    'lineCount' => (int) ($row['line_count'] ?? 0),
                    'deliveryNumber' => sms_lookup_delivery_number($releasedMap, $ref, $id, 'erp', $pdo, $invPdo),
                    'source' => 'erp',
                ];
            }
        } catch (Throwable $e) {
            error_log('sms_fetch_pending_invoices erp: ' . $e->getMessage());
        }
    }

    return $out;
}

function sms_salesperson_expr(PDO $invPdo): string
{
    try {
        $userCols = $invPdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('full_name', $userCols, true)) {
            return "COALESCE(u.full_name, '')";
        }
        if (in_array('name', $userCols, true)) {
            return "COALESCE(u.name, '')";
        }
        if (in_array('username', $userCols, true)) {
            return "COALESCE(u.username, '')";
        }
    } catch (Throwable $e) {
        // ignore
    }

    return "''";
}

/**
 * Enrich invoice lines with stock product metadata from the stock PDO.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function sms_enrich_invoice_lines_with_stock(PDO $stockPdo, int $warehouseId, array $items): array
{
    foreach ($items as &$item) {
        $pid = (int) ($item['product_id'] ?? $item['stock_product_id'] ?? 0);
        $item['stock_product_id'] = $pid;
        if ($pid <= 0) {
            $item['current_stock'] = (float) ($item['current_stock'] ?? 0);
            continue;
        }
        try {
            $pStmt = $stockPdo->prepare('SELECT id, name, product_code, unit_of_measure FROM products WHERE id = ? LIMIT 1');
            $pStmt->execute([$pid]);
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $item['stock_product_id'] = (int) $prod['id'];
                if (empty($item['product_name'])) {
                    $item['product_name'] = (string) ($prod['name'] ?? '');
                } else {
                    $item['product_name'] = (string) ($prod['name'] ?: $item['product_name']);
                }
                $item['product_code'] = (string) ($prod['product_code'] ?? ($item['product_code'] ?? ''));
                $item['unit_of_measure'] = (string) ($prod['unit_of_measure'] ?? ($item['unit_of_measure'] ?? 'pcs'));
            }
            $sStmt = $stockPdo->prepare('SELECT quantity FROM stock WHERE product_id = ? AND warehouse_id = ? LIMIT 1');
            $sStmt->execute([$pid, $warehouseId]);
            $item['current_stock'] = (float) ($sStmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $item['current_stock'] = (float) ($item['current_stock'] ?? 0);
        }
    }
    unset($item);

    return $items;
}

/**
 * @return array{source:string,id:int}|null
 */
function sms_resolve_invoice_source(PDO $invPdo, string $invoiceRef): ?array
{
    $parsed = sms_parse_invoice_ref($invoiceRef);
    $id = (int) ($parsed['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $source = (string) ($parsed['source'] ?? '');

    if ($source === 'sales' || $source === 'erp') {
        return ['source' => $source, 'id' => $id];
    }

    // Bare numeric id: prefer modern sales invoices, then legacy erp.
    if (function_exists('tableExists') && tableExists('invoices', $invPdo)) {
        $st = $invPdo->prepare('SELECT id FROM invoices WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            return ['source' => 'sales', 'id' => $id];
        }
    }
    if (function_exists('tableExists') && tableExists('erp_invoices', $invPdo)) {
        $st = $invPdo->prepare('SELECT id FROM erp_invoices WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            return ['source' => 'erp', 'id' => $id];
        }
    }

    return null;
}

function sms_fetch_invoice_detail(PDO $pdo, int $warehouseId, string $invoiceRef): ?array
{
    $invPdo = sms_invoices_pdo($pdo);
    if (!($invPdo instanceof PDO)) {
        return null;
    }

    $resolved = sms_resolve_invoice_source($invPdo, $invoiceRef);
    if ($resolved === null) {
        return null;
    }
    $source = $resolved['source'];
    $invoiceId = $resolved['id'];
    $ref = sms_invoice_ref($source, $invoiceId);
    $salespersonExpr = sms_salesperson_expr($invPdo);
    $invoice = null;
    $items = [];

    if ($source === 'sales') {
        if (!function_exists('tableExists') || !tableExists('invoices', $invPdo)) {
            return null;
        }
        $hasCustomers = tableExists('customers', $invPdo);
        $sql = $hasCustomers
            ? "SELECT i.*,
                      COALESCE(c.company_name, c.contact_person, 'Unknown customer') AS customer_name,
                      COALESCE(c.phone, '') AS customer_phone,
                      {$salespersonExpr} AS salesperson_name
               FROM invoices i
               LEFT JOIN customers c ON i.customer_id = c.id
               LEFT JOIN users u ON i.created_by = u.id
               WHERE i.id = ?"
            : "SELECT i.*,
                      'Unknown customer' AS customer_name,
                      '' AS customer_phone,
                      {$salespersonExpr} AS salesperson_name
               FROM invoices i
               LEFT JOIN users u ON i.created_by = u.id
               WHERE i.id = ?";
        $invStmt = $invPdo->prepare($sql);
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$invoice) {
            return null;
        }

        $orderId = (int) ($invoice['order_id'] ?? 0);
        if ($orderId > 0 && tableExists('sales_order_items', $invPdo)) {
            $itemsStmt = $invPdo->prepare(
                'SELECT soi.*,
                        soi.product_id AS stock_product_id,
                        COALESCE(soi.description, \'\') AS product_name,
                        \'\' AS product_code,
                        0 AS current_stock,
                        \'pcs\' AS unit_of_measure
                 FROM sales_order_items soi
                 WHERE soi.order_id = ?'
            );
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $items = sms_enrich_invoice_lines_with_stock($pdo, $warehouseId, $items);
        $viewUrl = function_exists('app_url')
            ? app_url('modules/sales/invoices/view.php?id=' . $invoiceId)
            : '../modules/sales/invoices/view.php?id=' . $invoiceId;
    } else {
        if (!function_exists('tableExists') || !tableExists('erp_invoices', $invPdo)) {
            return null;
        }
        $hasErpCustomers = tableExists('erp_customers', $invPdo);
        $sql = $hasErpCustomers
            ? "SELECT i.*,
                      COALESCE(c.name, 'Unknown customer') AS customer_name,
                      COALESCE(c.phone, '') AS customer_phone,
                      {$salespersonExpr} AS salesperson_name
               FROM erp_invoices i
               LEFT JOIN erp_customers c ON i.customer_id = c.id
               LEFT JOIN users u ON i.created_by = u.id
               WHERE i.id = ?"
            : "SELECT i.*,
                      'Unknown customer' AS customer_name,
                      '' AS customer_phone,
                      {$salespersonExpr} AS salesperson_name
               FROM erp_invoices i
               LEFT JOIN users u ON i.created_by = u.id
               WHERE i.id = ?";
        $invStmt = $invPdo->prepare($sql);
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$invoice) {
            return null;
        }

        if ($invPdo === $pdo && tableExists('erp_invoice_items', $pdo)) {
            $itemsStmt = $pdo->prepare(
                'SELECT ii.*,
                        COALESCE(p.id, p2.id) AS stock_product_id,
                        COALESCE(p.name, ep.name, ii.description) AS product_name,
                        COALESCE(p.product_code, ep.sku, \'\') AS product_code,
                        COALESCE(s.quantity, 0) AS current_stock,
                        COALESCE(p.unit_of_measure, ep.unit, \'pcs\') AS unit_of_measure
                 FROM erp_invoice_items ii
                 LEFT JOIN products p ON ii.product_id = p.id
                 LEFT JOIN erp_products ep ON ii.product_id = ep.id AND p.id IS NULL
                 LEFT JOIN products p2 ON p.id IS NULL AND ep.sku IS NOT NULL AND p2.product_code = ep.sku
                 LEFT JOIN stock s ON s.product_id = COALESCE(p.id, p2.id) AND s.warehouse_id = ?
                 WHERE ii.invoice_id = ?'
            );
            $itemsStmt->execute([$warehouseId, $invoiceId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } elseif (tableExists('erp_invoice_items', $invPdo)) {
            $itemsStmt = $invPdo->prepare(
                'SELECT ii.*,
                        ii.product_id AS stock_product_id,
                        COALESCE(ii.description, \'\') AS product_name,
                        \'\' AS product_code,
                        0 AS current_stock,
                        \'pcs\' AS unit_of_measure
                 FROM erp_invoice_items ii
                 WHERE ii.invoice_id = ?'
            );
            $itemsStmt->execute([$invoiceId]);
            $items = sms_enrich_invoice_lines_with_stock($pdo, $warehouseId, $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        $viewUrl = function_exists('app_url')
            ? app_url('sales/view-invoice.php?id=' . $invoiceId)
            : '../sales/view-invoice.php?id=' . $invoiceId;
    }

    return [
        'invoice' => [
            'id' => $ref,
            'invoiceNumber' => (string) ($invoice['invoice_number'] ?? ''),
            'customerName' => (string) ($invoice['customer_name'] ?? 'Unknown customer'),
            'customerPhone' => (string) ($invoice['customer_phone'] ?? ''),
            'invoiceDate' => (string) ($invoice['invoice_date'] ?? ''),
            'totalAmount' => (float) ($invoice['total'] ?? $invoice['total_amount'] ?? 0),
            'salespersonName' => trim((string) ($invoice['salesperson_name'] ?? '')),
            'viewInvoiceUrl' => $viewUrl,
            'source' => $source,
        ],
        'lines' => array_map(static function (array $row) use ($pdo): array {
            $stockProductId = (int) ($row['stock_product_id'] ?? $row['product_id'] ?? 0);
            $imageUrl = ($stockProductId > 0 && function_exists('sms_product_image_url'))
                ? sms_product_image_url($pdo, $stockProductId)
                : '';

            return [
                'productId' => (string) ($stockProductId > 0 ? $stockProductId : ($row['product_id'] ?? '')),
                'productName' => (string) ($row['product_name'] ?? ''),
                'productSku' => (string) ($row['product_code'] ?? ''),
                'qtyInvoiced' => (float) ($row['quantity'] ?? 0),
                'currentStock' => (float) ($row['current_stock'] ?? 0),
                'unit' => (string) ($row['unit_of_measure'] ?? 'pcs'),
                'imageUrl' => $imageUrl,
            ];
        }, $items),
    ];
}

function sms_dispatch_invoice(PDO $pdo, int $warehouseId, string $invoiceRef, array $items, string $notes, ?int $userId): array
{
    $invPdo = sms_invoices_pdo($pdo);
    if (!($invPdo instanceof PDO)) {
        return ['ok' => false, 'message' => 'Invoice module is not available'];
    }

    $resolved = sms_resolve_invoice_source($invPdo, $invoiceRef);
    if ($resolved === null) {
        return ['ok' => false, 'message' => 'Invoice not found.'];
    }
    $source = $resolved['source'];
    $invoiceId = $resolved['id'];
    $ref = sms_invoice_ref($source, $invoiceId);

    if (function_exists('sms_ensure_delivery_notes_table')) {
        sms_ensure_delivery_notes_table($pdo);
        sms_ensure_delivery_note_source_column($pdo);
        if ($invPdo !== $pdo) {
            sms_ensure_delivery_notes_table($invPdo);
            sms_ensure_delivery_note_source_column($invPdo);
        }
    }

    $releasedMap = sms_released_invoice_ref_map($pdo, $invPdo);
    if (isset($releasedMap[$ref])) {
        return ['ok' => false, 'message' => 'This invoice has already been dispatched.'];
    }

    try {
        $pdo->beginTransaction();
        if ($invPdo !== $pdo) {
            $invPdo->beginTransaction();
        }

        if ($source === 'sales') {
            $invStmt = $invPdo->prepare('SELECT * FROM invoices WHERE id = ?');
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }
            $orderId = (int) ($invoice['order_id'] ?? 0);
            $invoiceItems = [];
            if ($orderId > 0 && tableExists('sales_order_items', $invPdo)) {
                $invoiceItemsStmt = $invPdo->prepare('SELECT * FROM sales_order_items WHERE order_id = ?');
                $invoiceItemsStmt->execute([$orderId]);
                $invoiceItems = $invoiceItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $invoiceItems = sms_enrich_invoice_lines_with_stock($pdo, $warehouseId, $invoiceItems);
        } else {
            $invStmt = $invPdo->prepare('SELECT * FROM erp_invoices WHERE id = ?');
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }
            if ($invPdo === $pdo) {
                $invoiceItemsStmt = $pdo->prepare(
                    'SELECT ii.*,
                            COALESCE(p.id, p2.id) AS stock_product_id,
                            COALESCE(p.name, ep.name, ii.description) AS product_name,
                            COALESCE(s.quantity, 0) AS current_stock
                     FROM erp_invoice_items ii
                     LEFT JOIN products p ON ii.product_id = p.id
                     LEFT JOIN erp_products ep ON ii.product_id = ep.id AND p.id IS NULL
                     LEFT JOIN products p2 ON p.id IS NULL AND ep.sku IS NOT NULL AND p2.product_code = ep.sku
                     LEFT JOIN stock s ON s.product_id = COALESCE(p.id, p2.id) AND s.warehouse_id = ?
                     WHERE ii.invoice_id = ?'
                );
                $invoiceItemsStmt->execute([$warehouseId, $invoiceId]);
                $invoiceItems = $invoiceItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $invoiceItemsStmt = $invPdo->prepare('SELECT * FROM erp_invoice_items WHERE invoice_id = ?');
                $invoiceItemsStmt->execute([$invoiceId]);
                $invoiceItems = sms_enrich_invoice_lines_with_stock($pdo, $warehouseId, $invoiceItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }
        }

        foreach ($invoiceItems as $item) {
            $pid = (int) ($item['stock_product_id'] ?? $item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $reqQty = (float) ($items[(string) $pid] ?? $items[$pid] ?? 0);
            if ($reqQty <= 0) {
                continue;
            }
            if ($reqQty > (float) ($item['quantity'] ?? 0)) {
                throw new RuntimeException('Cannot release more than invoiced quantity for: ' . ($item['product_name'] ?? 'product'));
            }
            if ($reqQty > (float) ($item['current_stock'] ?? 0)) {
                throw new RuntimeException(
                    'Insufficient stock for product: ' . ($item['product_name'] ?? 'product')
                    . '. Available: ' . (int) ($item['current_stock'] ?? 0)
                    . ', Requested: ' . (int) $reqQty
                );
            }
        }

        $hasRelease = false;
        foreach ($invoiceItems as $item) {
            $pid = (int) ($item['stock_product_id'] ?? $item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if ((float) ($items[(string) $pid] ?? $items[$pid] ?? 0) > 0) {
                $hasRelease = true;
                break;
            }
        }
        if (!$hasRelease) {
            throw new RuntimeException('Enter at least one quantity to release.');
        }

        $deliveryNumber = 'DN-' . date('Ymd') . '-' . strtoupper(substr(uniqid('', true), -4));
        $dnPdo = function_exists('tableExists') && tableExists('erp_delivery_notes', $invPdo) ? $invPdo : $pdo;
        sms_ensure_delivery_note_source_column($dnPdo);
        $hasSourceCol = function_exists('columnExists') && columnExists('erp_delivery_notes', 'invoice_source', $dnPdo);

        if ($hasSourceCol) {
            $dnStmt = $dnPdo->prepare(
                'INSERT INTO erp_delivery_notes (order_id, delivery_number, invoice_id, invoice_source, customer_id, date, delivery_date, status, shipping_address, driver_name, vehicle_reg, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), CURDATE(), ?, ?, ?, ?, ?, ?)'
            );
            $dnStmt->execute([
                (int) ($invoice['order_id'] ?? 0),
                $deliveryNumber,
                $invoiceId,
                $source,
                (int) ($invoice['customer_id'] ?? 0),
                'delivered',
                '',
                '',
                '',
                $notes,
                $userId ?? 1,
            ]);
        } else {
            $dnStmt = $dnPdo->prepare(
                'INSERT INTO erp_delivery_notes (order_id, delivery_number, invoice_id, customer_id, date, delivery_date, status, shipping_address, driver_name, vehicle_reg, notes, created_by)
                 VALUES (?, ?, ?, ?, CURDATE(), CURDATE(), ?, ?, ?, ?, ?, ?)'
            );
            $dnStmt->execute([
                (int) ($invoice['order_id'] ?? 0),
                $deliveryNumber,
                $invoiceId,
                (int) ($invoice['customer_id'] ?? 0),
                'delivered',
                '',
                '',
                '',
                $notes,
                $userId ?? 1,
            ]);
        }
        $deliveryId = (int) $dnPdo->lastInsertId();

        if ($dnPdo !== $pdo && function_exists('tableExists') && tableExists('erp_delivery_notes', $pdo)) {
            try {
                sms_ensure_delivery_note_source_column($pdo);
                $mirrorHasSource = function_exists('columnExists') && columnExists('erp_delivery_notes', 'invoice_source', $pdo);
                if ($mirrorHasSource) {
                    $mirror = $pdo->prepare(
                        'INSERT INTO erp_delivery_notes (order_id, delivery_number, invoice_id, invoice_source, customer_id, date, delivery_date, status, shipping_address, driver_name, vehicle_reg, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, CURDATE(), CURDATE(), ?, ?, ?, ?, ?, ?)'
                    );
                    $mirror->execute([
                        (int) ($invoice['order_id'] ?? 0),
                        $deliveryNumber,
                        $invoiceId,
                        $source,
                        (int) ($invoice['customer_id'] ?? 0),
                        'delivered',
                        '',
                        '',
                        '',
                        $notes,
                        $userId ?? 1,
                    ]);
                }
            } catch (Throwable $mirrorErr) {
                error_log('sms_dispatch_invoice mirror DN: ' . $mirrorErr->getMessage());
            }
        }

        foreach ($invoiceItems as $item) {
            $pid = (int) ($item['stock_product_id'] ?? $item['product_id'] ?? 0);
            $reqQty = (int) round((float) ($items[(string) $pid] ?? $items[$pid] ?? 0));
            if ($reqQty <= 0 || $pid <= 0) {
                continue;
            }

            $deductStmt = $pdo->prepare('UPDATE stock SET quantity = quantity - ? WHERE product_id = ? AND warehouse_id = ?');
            $deductStmt->execute([$reqQty, $pid, $warehouseId]);

            $movementNotes = 'Dispatched via Invoice: ' . ($invoice['invoice_number'] ?? '')
                . ' / DN: ' . $deliveryNumber;
            if ($notes !== '') {
                $movementNotes .= ' - ' . $notes;
            }

            if (function_exists('sms_record_movement')) {
                sms_record_movement(
                    $pdo,
                    $pid,
                    $warehouseId,
                    'out',
                    $reqQty,
                    $movementNotes,
                    'sale',
                    (string) ($invoice['invoice_number'] ?? $invoiceId)
                );
            }

            if (function_exists('tableExists') && tableExists('erp_delivery_items', $dnPdo)) {
                $diStmt = $dnPdo->prepare('INSERT INTO erp_delivery_items (delivery_id, product_id, quantity, batch_number) VALUES (?, ?, ?, ?)');
                $diStmt->execute([$deliveryId, $pid, $reqQty, '']);
            }
        }

        $pdo->commit();
        if ($invPdo !== $pdo && $invPdo->inTransaction()) {
            $invPdo->commit();
        }

        return [
            'ok' => true,
            'message' => 'Invoice products released successfully. Delivery Note ' . $deliveryNumber . ' generated.',
            'delivery_id' => $deliveryId,
            'delivery_number' => $deliveryNumber,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($invPdo instanceof PDO && $invPdo !== $pdo && $invPdo->inTransaction()) {
            $invPdo->rollBack();
        }

        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
