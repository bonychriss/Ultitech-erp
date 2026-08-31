<?php
/**
 * Store Management API — warehouse inventory connected to the stock module database.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../stock/config/database.php';

if (!function_exists('requireLogin')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

requireLogin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// JSON bodies are not populated into $_POST by PHP. Merge them even when action
// is already present on the query string (frontend always sends ?action=...).
if ($method === 'POST') {
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false || (empty($_POST) && empty($_FILES))) {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $body = json_decode($raw, true);
            if (is_array($body)) {
                $_POST = array_merge($_POST, $body);
                if ($action === '' && !empty($body['action'])) {
                    $action = (string) $body['action'];
                }
            }
        }
    }
}

if ($action !== 'receipt_document') {
    header('Content-Type: application/json; charset=utf-8');
}

function sms_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function sms_error(string $message, int $code = 400): void
{
    sms_json(['success' => false, 'error' => $message], $code);
}


function sms_can_manage_products(): bool
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($role, ['admin', 'procurement'], true);
}

function sms_product_columns(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function sms_has_column(PDO $pdo, string $column): bool
{
    return in_array($column, sms_product_columns($pdo), true);
}

function sms_category_columns(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = $pdo->query('SHOW COLUMNS FROM categories')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function sms_map_product(array $row, string $currencySymbol): array
{
    $sku = $row['sku'] ?? $row['product_code'] ?? '';
    $cost = (float) ($row['buying_price'] ?? $row['cost_price'] ?? 0);
    $unit = $row['unit_of_measure'] ?? $row['uom'] ?? 'pcs';
    $productId = (int) ($row['id'] ?? 0);
    $imageFile = trim((string) ($row['product_image'] ?? $row['main_image'] ?? ''));
    $imageUrl = '';
    if ($productId > 0 && function_exists('stock_product_list_image_url')) {
        $imageUrl = (string) stock_product_list_image_url($productId, $imageFile, 'thumbnail', '');
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'sku' => (string) $sku,
        'name' => (string) ($row['name'] ?? ''),
        'category' => (string) ($row['category_name'] ?? 'Uncategorized'),
        'categoryId' => (int) ($row['category_id'] ?? 0),
        'price' => (float) ($row['unit_price'] ?? 0),
        'cost' => $cost,
        'stock' => (float) ($row['quantity'] ?? 0),
        'minStock' => (int) ($row['reorder_level'] ?? 10),
        'unit' => (string) $unit,
        'description' => (string) ($row['description'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? date('c')),
        'imageUrl' => $imageUrl,
    ];
}

function sms_resolve_category_id(PDO $pdo, string $categoryName): ?int
{
    $categoryName = trim($categoryName);
    if ($categoryName === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $stmt->execute([$categoryName]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $cols = sms_category_columns($pdo);
    $fields = ['name'];
    $placeholders = ['?'];
    $values = [$categoryName];

    if (in_array('description', $cols, true)) {
        $fields[] = 'description';
        $placeholders[] = '?';
        $values[] = '';
    }

    $sql = 'INSERT INTO categories (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($values);

    return (int) $pdo->lastInsertId();
}

function sms_generate_product_code(PDO $pdo): string
{
    $year = date('Y');
    $prefix = "PRD-$year-";
    $stmt = $pdo->prepare('SELECT MAX(CAST(SUBSTRING_INDEX(product_code, \'-\', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?');
    $stmt->execute([$prefix . '%']);
    $maxNum = (int) $stmt->fetchColumn();
    $nextNum = $maxNum > 0 ? $maxNum + 1 : 1;

    return $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
}

function sms_upsert_stock(PDO $pdo, int $productId, int $warehouseId, int $quantity, ?string $location = null): void
{
    $stmt = $pdo->prepare('SELECT id, quantity FROM stock WHERE product_id = ? AND warehouse_id = ?');
    $stmt->execute([$productId, $warehouseId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare('UPDATE stock SET quantity = ?, last_updated = NOW() WHERE id = ?')
            ->execute([$quantity, $existing['id']]);
        return;
    }

    if ($location === null) {
        $whStmt = $pdo->prepare('SELECT code FROM warehouses WHERE id = ?');
        $whStmt->execute([$warehouseId]);
        $location = $whStmt->fetchColumn() ?: 'WH';
    }

    $pdo->prepare('INSERT INTO stock (product_id, warehouse_id, quantity, location, last_updated) VALUES (?, ?, ?, ?, NOW())')
        ->execute([$productId, $warehouseId, $quantity, $location]);
}

function sms_movement_columns(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

function sms_movement_has_column(PDO $pdo, string $column): bool
{
    return in_array($column, sms_movement_columns($pdo), true);
}

function sms_record_movement(
    PDO $pdo,
    int $productId,
    int $warehouseId,
    string $movementType,
    int $quantity,
    string $notes,
    string $referenceType = 'adjustment',
    string $referenceId = '0'
): void {
    if (!tableExists('stock_movements')) {
        return;
    }

    $allowedRefs = ['purchase', 'sale', 'adjustment'];
    if (!in_array($referenceType, $allowedRefs, true)) {
        $referenceType = 'adjustment';
    }

    $cols = sms_movement_columns($pdo);
    $fields = ['product_id', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_at'];
    $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()'];
    $values = [$productId, $movementType, $quantity, $referenceType, $referenceId, $notes];

    if (in_array('warehouse_id', $cols, true)) {
        array_splice($fields, 1, 0, ['warehouse_id']);
        array_splice($placeholders, 1, 0, ['?']);
        array_splice($values, 1, 0, [$warehouseId]);
    }

    $quoted = implode(', ', array_map(static fn(string $f) => "`$f`", $fields));
    $pdo->prepare("INSERT INTO stock_movements ($quoted) VALUES (" . implode(', ', $placeholders) . ')')
        ->execute($values);
}

function sms_movement_receive_status(string $movementType, string $notes): string
{
    $type = strtolower(trim($movementType));
    if ($type !== 'in') {
        if (stripos($notes, 'shipped') !== false) {
            return 'Shipped';
        }
        return 'Released';
    }

    if (preg_match('/expected\s+([\d.]+)\s*,\s*verified\s+([\d.]+)/i', $notes, $m)) {
        $expected = (float) $m[1];
        $verified = (float) $m[2];
        if (abs($expected - $verified) > 0.0001) {
            return 'Partially received';
        }
        return 'Received';
    }

    if (stripos($notes, 'partial') !== false) {
        return 'Partially received';
    }

    return 'Received';
}

function sms_map_movement(array $row): array
{
    $movementType = (string) ($row['movement_type'] ?? 'out');
    $notes = (string) ($row['notes'] ?? '');
    $productId = (int) ($row['product_id'] ?? 0);
    $imageFile = trim((string) ($row['product_image'] ?? $row['main_image'] ?? ''));
    $imageUrl = '';
    if ($productId > 0 && function_exists('stock_product_list_image_url')) {
        $imageUrl = (string) stock_product_list_image_url($productId, $imageFile, 'thumbnail', '');
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'productId' => (string) ($row['product_id'] ?? ''),
        'productName' => (string) ($row['product_name'] ?? ''),
        'productSku' => (string) ($row['product_code'] ?? ''),
        'categoryName' => (string) ($row['category_name'] ?? 'Uncategorized'),
        'imageUrl' => $imageUrl,
        'movementType' => $movementType,
        'quantity' => (int) ($row['quantity'] ?? 0),
        'referenceType' => (string) ($row['reference_type'] ?? ''),
        'referenceId' => (string) ($row['reference_id'] ?? ''),
        'notes' => $notes,
        'status' => sms_movement_receive_status($movementType, $notes),
        'createdAt' => (string) ($row['created_at'] ?? date('c')),
    ];
}

function sms_fetch_movements(PDO $pdo, int $warehouseId, array $filters = []): array
{
    if (!tableExists('stock_movements')) {
        return [];
    }

    $hasWarehouse = sms_movement_has_column($pdo, 'warehouse_id');
    $imageSql = function_exists('stock_product_main_image_sql')
        ? stock_product_main_image_sql($pdo, 'p')
        : "NULLIF(TRIM(p.main_image), '')";
    $query = "SELECT sm.*, p.name AS product_name, p.product_code, c.name AS category_name,
                     ({$imageSql}) AS product_image
              FROM stock_movements sm
              JOIN products p ON sm.product_id = p.id
              LEFT JOIN categories c ON p.category_id = c.id
              WHERE 1=1";
    $params = [];

    if ($hasWarehouse && $warehouseId > 0) {
        $query .= ' AND sm.warehouse_id = ?';
        $params[] = $warehouseId;
    }

    // Desk list: only movements recorded/confirmed in Store Management
    // (exclude sales shipments and other module stock posts).
    $storeOnly = !array_key_exists('store_only', $filters) || !empty($filters['store_only']);
    if ($storeOnly) {
        $query .= " AND (
            sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
            OR sm.notes LIKE ?
        )";
        $params[] = '%store management%';
        $params[] = 'Verified receipt%';
        $params[] = 'Store verified%';
        $params[] = 'Sample out:%';
        $params[] = 'Dispatched via Invoice:%';
        $params[] = 'Opening stock via store management%';
        $params[] = 'Stock level updated via store management%';
        $params[] = 'Quick stock adjustment via store management%';
        $params[] = '%(Store management manual out)%';
    }

    $productId = (int) ($filters['product_id'] ?? 0);
    if ($productId > 0) {
        $query .= ' AND sm.product_id = ?';
        $params[] = $productId;
    }

    $type = trim((string) ($filters['type'] ?? ''));
    if ($type !== '' && in_array($type, ['in', 'out', 'adjustment'], true)) {
        $query .= ' AND sm.movement_type = ?';
        $params[] = $type;
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $query .= ' AND (p.name LIKE ? OR p.product_code LIKE ? OR sm.notes LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $startDate = trim((string) ($filters['start_date'] ?? ''));
    if ($startDate !== '') {
        $query .= ' AND DATE(sm.created_at) >= ?';
        $params[] = $startDate;
    }

    $endDate = trim((string) ($filters['end_date'] ?? ''));
    if ($endDate !== '') {
        $query .= ' AND DATE(sm.created_at) <= ?';
        $params[] = $endDate;
    }

    $limit = min(200, max(1, (int) ($filters['limit'] ?? 100)));
    $query .= ' ORDER BY sm.created_at DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row) use ($pdo): array {
        $movement = sms_map_movement($row);
        if (($movement['imageUrl'] ?? '') === '' && (int) ($movement['productId'] ?? 0) > 0) {
            $movement['imageUrl'] = sms_product_image_url($pdo, (int) $movement['productId']);
        }

        return $movement;
    }, $rows);
}

function sms_purchase_workflow_loaded(): bool
{
    static $loaded = false;
    if ($loaded) {
        return true;
    }
    $path = __DIR__ . '/../../stock/modules/purchases/purchase_workflow.php';
    if (!is_file($path)) {
        return false;
    }
    require_once $path;
    $loaded = true;

    return true;
}

function sms_active_company_id(): int
{
    if (function_exists('stockPurchaseActiveCompanyId')) {
        return (int) stockPurchaseActiveCompanyId();
    }
    if (function_exists('currentCompanyId')) {
        return (int) (currentCompanyId() ?? 0);
    }

    return 0;
}

function sms_po_can_receive(array $po, bool $hasShipment = true): bool
{
    $status = (string) ($po['status'] ?? '');
    if (in_array($status, ['Received', 'Cancelled'], true)) {
        return false;
    }
    if (sms_purchase_workflow_loaded() && function_exists('purchaseStatusesBlockingReceive')
        && in_array($status, purchaseStatusesBlockingReceive(), true)) {
        return false;
    }
    $purchaseType = (string) ($po['purchase_type'] ?? 'domestic');
    if ($purchaseType === 'import' && !$hasShipment) {
        return false;
    }

    return true;
}

function sms_po_has_shipment(PDO $pdo, int $poId): bool
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn()) {
            $stmt = $pdo->prepare('SELECT 1 FROM shipments WHERE stocks_po_id = ? LIMIT 1');
            $stmt->execute([$poId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        if ($pdo->query("SHOW TABLES LIKE 'shipment_items'")->fetchColumn()) {
            $stmt = $pdo->prepare('SELECT 1 FROM shipment_items WHERE purchase_id = ? LIMIT 1');
            $stmt->execute([$poId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
    } catch (Throwable $e) {
    }

    return false;
}

function sms_map_purchase_order_summary(array $row): array
{
    $ordered = (float) ($row['ordered_qty'] ?? 0);
    $received = (float) ($row['received_qty'] ?? 0);
    $remaining = (float) ($row['remaining_qty'] ?? max(0, $ordered - $received));
    $receiveStatus = 'Pending';
    if ($ordered > 0 && $remaining <= 0.0001) {
        $receiveStatus = 'Received';
    } elseif ($received > 0.0001 && $remaining > 0.0001) {
        $receiveStatus = 'Partially received';
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'poNumber' => (string) ($row['po_number'] ?? $row['purchase_no'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'receiveStatus' => $receiveStatus,
        'purchaseType' => (string) ($row['purchase_type'] ?? 'domestic'),
        'supplierName' => (string) ($row['supplier_name'] ?? ''),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'orderedQty' => $ordered,
        'receivedQty' => $received,
        'remainingQty' => $remaining,
        'lineCount' => (int) ($row['line_count'] ?? 0),
        'source' => (string) ($row['_source'] ?? 'stocks'),
    ];
}

function sms_map_po_line(array $row): array
{
    $ordered = (float) ($row['qty_ordered'] ?? $row['quantity'] ?? 0);
    $received = (float) ($row['qty_received'] ?? 0);
    $remaining = max(0, $ordered - $received);
    $receiveStatus = 'Pending';
    if ($ordered > 0 && $remaining <= 0.0001) {
        $receiveStatus = 'Received';
    } elseif ($received > 0.0001 && $remaining > 0.0001) {
        $receiveStatus = 'Partially received';
    }

    return [
        'lineId' => (string) ($row['line_id'] ?? $row['id'] ?? ''),
        'productId' => (string) ($row['product_id'] ?? $row['item_id'] ?? ''),
        'productName' => (string) ($row['product_name'] ?? $row['item_name'] ?? ''),
        'productSku' => (string) ($row['product_code'] ?? $row['sku'] ?? ''),
        'qtyOrdered' => $ordered,
        'qtyReceived' => $received,
        'qtyRemaining' => $remaining,
        'receiveStatus' => $receiveStatus,
        'unitCost' => (float) ($row['unit_cost'] ?? $row['unit_price'] ?? 0),
        'imageUrl' => (string) ($row['image_url'] ?? $row['imageUrl'] ?? ''),
    ];
}

function sms_product_image_url(PDO $pdo, int $productId): string
{
    if ($productId <= 0 || !function_exists('stock_product_list_image_url')) {
        return '';
    }

    $imageFile = '';
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $imageCol = in_array('product_image', $cols, true)
            ? 'product_image'
            : (in_array('main_image', $cols, true) ? 'main_image' : '');
        if ($imageCol !== '') {
            $stmt = $pdo->prepare("SELECT `{$imageCol}` FROM products WHERE id = ? LIMIT 1");
            $stmt->execute([$productId]);
            $imageFile = trim((string) ($stmt->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) {
        $imageFile = '';
    }

    return (string) stock_product_list_image_url($productId, $imageFile, 'thumbnail', '');
}

function sms_lookup_supplier_name(PDO $pdo, array $po): string
{
    $supplierId = (int) ($po['supplier_id'] ?? 0);
    if ($supplierId <= 0) {
        return trim((string) ($po['supplier_name'] ?? ''));
    }

    try {
        if (tableExists('stocks_suppliers')) {
            $stmt = $pdo->prepare('SELECT name FROM stocks_suppliers WHERE id = ? LIMIT 1');
            $stmt->execute([$supplierId]);
            $name = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($name !== '') {
                return $name;
            }
        }
    } catch (Throwable $e) {
    }

    return trim((string) ($po['supplier_name'] ?? '')) ?: ('Supplier #' . $supplierId);
}

function sms_fetch_receivable_purchase_orders(PDO $pdo): array
{
    $orders = [];
    $companyId = sms_active_company_id();

    if (tableExists('stocks_purchase_orders') && tableExists('stocks_po_items')) {
        $poCols = [];
        try {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $poCols = [];
        }

        $supplierJoin = tableExists('stocks_suppliers')
            ? 'LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id'
            : '';
        $supplierExpr = tableExists('stocks_suppliers')
            ? "COALESCE(ss.name, CONCAT('Supplier #', p.supplier_id))"
            : "CONCAT('Supplier #', p.supplier_id)";

        $sql = "SELECT p.id, p.po_number, p.status, p.purchase_type, p.created_at,
                       {$supplierExpr} AS supplier_name,
                       COALESCE(SUM(COALESCE(pi.qty_ordered, 0)), 0) AS ordered_qty,
                       COALESCE(SUM(COALESCE(pi.qty_received, 0)), 0) AS received_qty,
                       COALESCE(SUM(GREATEST(COALESCE(pi.qty_ordered, 0) - COALESCE(pi.qty_received, 0), 0)), 0) AS remaining_qty,
                       COUNT(pi.id) AS line_count
                FROM stocks_purchase_orders p
                {$supplierJoin}
                INNER JOIN stocks_po_items pi ON pi.po_id = p.id
                WHERE p.status NOT IN ('Received', 'Cancelled')";
        if (in_array('company_id', $poCols, true) && $companyId > 0) {
            $sql .= ' AND p.company_id = ' . (int) $companyId;
        }
        $sql .= ' GROUP BY p.id HAVING remaining_qty > 0 ORDER BY p.created_at DESC LIMIT 50';

        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $row['_source'] = 'stocks';
                $hasShipment = sms_po_has_shipment($pdo, (int) $row['id']);
                if (!sms_po_can_receive($row, $hasShipment)) {
                    continue;
                }
                $orders[] = sms_map_purchase_order_summary($row);
            }
        } catch (Throwable $e) {
        }
    }

    if (tableExists('purchases') && tableExists('purchase_items') && sms_purchase_workflow_loaded()) {
        ensureLegacyPurchaseItemsReceivedColumn($pdo);
        $legacyCols = [];
        try {
            $legacyCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $legacyCols = [];
        }

        $legacySql = "SELECT p.id, p.purchase_no AS po_number, p.status, 'domestic' AS purchase_type, p.created_at,
                             COALESCE(s.name, CONCAT('Supplier #', p.supplier_id)) AS supplier_name,
                             COALESCE(SUM(COALESCE(pi.quantity, 0)), 0) AS ordered_qty,
                             COALESCE(SUM(COALESCE(pi.qty_received, 0)), 0) AS received_qty,
                             COALESCE(SUM(GREATEST(COALESCE(pi.quantity, 0) - COALESCE(pi.qty_received, 0), 0)), 0) AS remaining_qty,
                             COUNT(pi.id) AS line_count
                      FROM purchases p
                      LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id
                      INNER JOIN purchase_items pi ON pi.purchase_id = p.id
                      WHERE p.status NOT IN ('Received', 'Cancelled')";
        if (in_array('company_id', $legacyCols, true) && $companyId > 0) {
            $legacySql .= ' AND p.company_id = ' . (int) $companyId;
        }
        $legacySql .= ' GROUP BY p.id HAVING remaining_qty > 0 ORDER BY p.created_at DESC LIMIT 50';

        try {
            foreach ($pdo->query($legacySql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $row['_source'] = 'legacy';
                if (!sms_po_can_receive($row, true)) {
                    continue;
                }
                $orders[] = sms_map_purchase_order_summary($row);
            }
        } catch (Throwable $e) {
        }
    }

    usort($orders, static function (array $a, array $b): int {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });

    return array_slice($orders, 0, 50);
}

function sms_stock_public_url(string $relativePath): string
{
    $relative = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relative === '') {
        return '';
    }
    if (function_exists('app_url')) {
        return app_url('stock/' . $relative);
    }

    return '/stock/' . $relative;
}

/**
 * Linked documents on a purchase order (stocks_purchase_attachments + invoice_attachment).
 *
 * @return list<array{id:string,name:string,url:string,kind:string}>
 */
function sms_fetch_po_attachments(PDO $pdo, int $poId, string $source = 'stocks'): array
{
    if ($poId <= 0) {
        return [];
    }

    $attachments = [];
    $seenUrls = [];

    try {
        if (tableExists('stocks_purchase_attachments')) {
            $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_attachments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $fk = in_array('purchase_id', $cols, true)
                ? 'purchase_id'
                : (in_array('po_id', $cols, true) ? 'po_id' : '');
            if ($fk !== '') {
                $select = ['id', 'file_name', 'file_path'];
                $stmt = $pdo->prepare(
                    'SELECT ' . implode(', ', $select) . " FROM stocks_purchase_attachments WHERE {$fk} = ? ORDER BY id ASC"
                );
                $stmt->execute([$poId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $path = trim((string) ($row['file_path'] ?? ''));
                    $url = sms_stock_public_url($path);
                    if ($url === '' || isset($seenUrls[$url])) {
                        continue;
                    }
                    $seenUrls[$url] = true;
                    $name = trim((string) ($row['file_name'] ?? ''));
                    if ($name === '') {
                        $name = basename($path) ?: 'Attachment';
                    }
                    $attachments[] = [
                        'id' => 'po-file-' . (int) ($row['id'] ?? 0),
                        'name' => $name,
                        'url' => $url,
                        'kind' => 'purchase_order',
                    ];
                }
            }
        }
    } catch (Throwable $e) {
    }

    $invoicePath = '';
    try {
        if ($source === 'legacy' && tableExists('purchases')) {
            $stmt = $pdo->prepare('SELECT invoice_attachment FROM purchases WHERE id = ? LIMIT 1');
            $stmt->execute([$poId]);
            $invoicePath = trim((string) ($stmt->fetchColumn() ?: ''));
        } elseif (tableExists('stocks_purchase_orders')) {
            $stmt = $pdo->prepare('SELECT invoice_attachment FROM stocks_purchase_orders WHERE id = ? LIMIT 1');
            $stmt->execute([$poId]);
            $invoicePath = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($invoicePath === '' && tableExists('purchases')) {
                $stmt = $pdo->prepare('SELECT invoice_attachment FROM purchases WHERE id = ? LIMIT 1');
                $stmt->execute([$poId]);
                $invoicePath = trim((string) ($stmt->fetchColumn() ?: ''));
            }
        }
    } catch (Throwable $e) {
        $invoicePath = '';
    }

    if ($invoicePath !== '') {
        $invoiceUrl = function_exists('app_url')
            ? app_url('stock/modules/purchases/download_invoice.php?id=' . $poId)
            : '/stock/modules/purchases/download_invoice.php?id=' . $poId;
        if (!isset($seenUrls[$invoiceUrl])) {
            $seenUrls[$invoiceUrl] = true;
            $attachments[] = [
                'id' => 'po-invoice-' . $poId,
                'name' => 'Supplier invoice',
                'url' => $invoiceUrl,
                'kind' => 'invoice',
            ];
        }
    }

    return $attachments;
}

function sms_fetch_purchase_order_detail(PDO $pdo, int $poId, string $source = 'stocks'): ?array
{
    if ($poId <= 0) {
        return null;
    }

    if ($source === 'legacy' && tableExists('purchases') && tableExists('purchase_items')) {
        if (!sms_purchase_workflow_loaded()) {
            return null;
        }
        ensureLegacyPurchaseItemsReceivedColumn($pdo);
        $stmt = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
        $stmt->execute([$poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return null;
        }
        if (!sms_po_can_receive($po, true)) {
            return null;
        }

        $lines = [];
        foreach (stockPurchaseFetchLegacyReceiveLineItems($pdo, $poId) as $row) {
            $productId = (int) ($row['product_id'] ?? $row['item_id'] ?? 0);
            $mapped = sms_map_po_line($row);
            if ($mapped['imageUrl'] === '' && $productId > 0) {
                $mapped['imageUrl'] = sms_product_image_url($pdo, $productId);
            }
            $lines[] = $mapped;
        }

        $remaining = array_sum(array_map(static fn(array $line) => (float) $line['qtyRemaining'], $lines));
        $ordered = array_sum(array_map(static fn(array $line) => (float) $line['qtyOrdered'], $lines));
        $received = array_sum(array_map(static fn(array $line) => (float) $line['qtyReceived'], $lines));
        $supplierName = sms_lookup_supplier_name($pdo, $po);

        return [
            'order' => sms_map_purchase_order_summary(array_merge($po, [
                '_source' => 'legacy',
                'supplier_name' => $supplierName,
                'ordered_qty' => $ordered,
                'received_qty' => $received,
                'remaining_qty' => $remaining,
                'line_count' => count($lines),
            ])),
            'lines' => $lines,
            'attachments' => sms_fetch_po_attachments($pdo, $poId, 'legacy'),
        ];
    }

    if (!tableExists('stocks_purchase_orders') || !tableExists('stocks_po_items')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM stocks_purchase_orders WHERE id = ? LIMIT 1');
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po) {
        return null;
    }

    $hasShipment = sms_po_has_shipment($pdo, $poId);
    if (!sms_po_can_receive($po, $hasShipment)) {
        return null;
    }

    $lines = [];
    $stmtItems = $pdo->prepare("
        SELECT pi.id AS line_id, pi.item_id, pi.qty_ordered, pi.qty_received, pi.unit_cost,
               si.name AS item_name, COALESCE(si.sku, '') AS sku
        FROM stocks_po_items pi
        LEFT JOIN stocks_items si ON pi.item_id = si.id
        WHERE pi.po_id = ?
        ORDER BY pi.id ASC
    ");
    $stmtItems->execute([$poId]);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $productId = (int) ($row['item_id'] ?? 0);
        $productName = (string) ($row['item_name'] ?? '');
        $productSku = (string) ($row['sku'] ?? '');

        if ($productName === '' && $productId > 0) {
            $prodStmt = $pdo->prepare('SELECT name, product_code FROM products WHERE id = ? LIMIT 1');
            $prodStmt->execute([$productId]);
            $prod = $prodStmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $productName = (string) ($prod['name'] ?? $productName);
                $productSku = (string) ($prod['product_code'] ?? $productSku);
            }
        }

        $lines[] = sms_map_po_line([
            'line_id' => $row['line_id'],
            'item_id' => $productId,
            'product_name' => $productName,
            'sku' => $productSku,
            'qty_ordered' => $row['qty_ordered'],
            'qty_received' => $row['qty_received'],
            'unit_cost' => $row['unit_cost'],
            'image_url' => sms_product_image_url(
                $pdo,
                sms_resolve_product_id_for_po_item($pdo, $productId, $productSku)
            ),
        ]);
    }

    $remaining = array_sum(array_map(static fn(array $line) => (float) $line['qtyRemaining'], $lines));
    $ordered = array_sum(array_map(static fn(array $line) => (float) $line['qtyOrdered'], $lines));
    $received = array_sum(array_map(static fn(array $line) => (float) $line['qtyReceived'], $lines));
    $supplierName = sms_lookup_supplier_name($pdo, $po);

    return [
        'order' => sms_map_purchase_order_summary(array_merge($po, [
            '_source' => 'stocks',
            'supplier_name' => $supplierName,
            'ordered_qty' => $ordered,
            'received_qty' => $received,
            'remaining_qty' => $remaining,
            'line_count' => count($lines),
        ])),
        'lines' => $lines,
        'attachments' => sms_fetch_po_attachments($pdo, $poId, 'stocks'),
    ];
}

function sms_resolve_product_id_for_po_item(PDO $pdo, int $itemId, string $sku = ''): int
{
    if ($itemId <= 0) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$itemId]);
        if ($stmt->fetchColumn()) {
            return $itemId;
        }
    } catch (Throwable $e) {
    }

    if ($sku !== '') {
        try {
            $stmt = $pdo->prepare('SELECT id FROM products WHERE product_code = ? LIMIT 1');
            $stmt->execute([$sku]);
            $altId = $stmt->fetchColumn();
            if ($altId) {
                return (int) $altId;
            }
        } catch (Throwable $e) {
        }
    }

    return $itemId;
}

function sms_receive_purchase_order(
    PDO $pdo,
    int $poId,
    int $warehouseId,
    array $receiveQuantities,
    string $notes,
    string $source = 'stocks',
    ?int $userId = null
): array {
    if ($poId <= 0 || $warehouseId <= 0 || $receiveQuantities === []) {
        return ['ok' => false, 'message' => 'Invalid purchase order receive request.'];
    }
    if (!function_exists('storeReceiptCreatePending')) {
        return ['ok' => false, 'message' => 'Store receipt workflow is not available on this server.'];
    }

    /**
     * Procurement records delivery only: update PO line qty_received and create
     * pending warehouse receipts. On-hand stock increases only after store verify.
     */
    if ($source === 'legacy') {
        if (!tableExists('purchases') || !tableExists('purchase_items') || !sms_purchase_workflow_loaded()) {
            return ['ok' => false, 'message' => 'Legacy purchase orders are not available.'];
        }

        ensureLegacyPurchaseItemsReceivedColumn($pdo);
        $stmtPo = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
        $stmtPo->execute([$poId]);
        $po = $stmtPo->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }
        if (!sms_po_can_receive($po, true)) {
            return ['ok' => false, 'message' => 'This purchase order cannot be received.'];
        }

        $reference = trim((string) ($po['purchase_no'] ?? ''));
        if ($reference === '') {
            $reference = 'PO#' . $poId;
        }

        try {
            $pdo->beginTransaction();
            $anyReceived = false;
            $pendingCount = 0;
            $receiptIds = [];

            foreach ($receiveQuantities as $lineId => $qtyRaw) {
                $qty = (float) $qtyRaw;
                if ($qty <= 0) {
                    continue;
                }

                $stmtItem = $pdo->prepare('SELECT * FROM purchase_items WHERE id = ? AND purchase_id = ? LIMIT 1');
                $stmtItem->execute([(int) $lineId, $poId]);
                $line = $stmtItem->fetch(PDO::FETCH_ASSOC);
                if (!$line) {
                    continue;
                }

                $remaining = max(0, (float) ($line['quantity'] ?? 0) - (float) ($line['qty_received'] ?? 0));
                if ($remaining <= 0) {
                    continue;
                }
                if ($qty > $remaining) {
                    $qty = $remaining;
                }

                $productId = (int) ($line['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $pdo->prepare('UPDATE purchase_items SET qty_received = COALESCE(qty_received, 0) + ? WHERE id = ? AND purchase_id = ?')
                    ->execute([$qty, (int) $lineId, $poId]);

                $receiptId = storeReceiptCreatePending(
                    $pdo,
                    $warehouseId,
                    $productId,
                    $qty,
                    $poId,
                    (int) $lineId,
                    $reference,
                    $notes !== '' ? $notes : null,
                    $userId
                );
                if ($receiptId <= 0) {
                    throw new Exception('Failed to create pending store receipt for line #' . (int) $lineId);
                }

                $receiptIds[] = $receiptId;
                $pendingCount++;
                $anyReceived = true;
            }

            if (!$anyReceived) {
                throw new Exception('No valid quantities were processed.');
            }

            $stmtRemain = $pdo->prepare(
                'SELECT COALESCE(SUM(GREATEST(0, COALESCE(quantity, 0) - COALESCE(qty_received, 0))), 0)
                 FROM purchase_items WHERE purchase_id = ?'
            );
            $stmtRemain->execute([$poId]);
            $remainingTotal = (float) $stmtRemain->fetchColumn();

            $poCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($remainingTotal <= 0) {
                $sets = ["status = 'Received'"];
                if (in_array('received_date', $poCols, true)) {
                    $sets[] = 'received_date = NOW()';
                }
                if (in_array('updated_at', $poCols, true)) {
                    $sets[] = 'updated_at = NOW()';
                }
                $pdo->exec('UPDATE purchases SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $poId);
            }

            $pdo->commit();

            return [
                'ok' => true,
                'message' => sprintf(
                    'Delivery recorded for %s (%d line%s). Awaiting store confirmation — stock is not added yet.',
                    $reference,
                    $pendingCount,
                    $pendingCount === 1 ? '' : 's'
                ),
                'pending_count' => $pendingCount,
                'receipt_ids' => $receiptIds,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    $detail = sms_fetch_purchase_order_detail($pdo, $poId, 'stocks');
    if ($detail === null) {
        return ['ok' => false, 'message' => 'Purchase order not found or cannot be received.'];
    }

    $po = $detail['order'];
    $reference = (string) ($po['poNumber'] !== '' ? $po['poNumber'] : ('PO#' . $poId));

    try {
        $pdo->beginTransaction();

        $anyReceived = false;
        $pendingCount = 0;
        $receiptIds = [];
        foreach ($receiveQuantities as $lineId => $qtyRaw) {
            $qty = (float) $qtyRaw;
            if ($qty <= 0) {
                continue;
            }

            $stmtItem = $pdo->prepare('SELECT * FROM stocks_po_items WHERE id = ? AND po_id = ? LIMIT 1');
            $stmtItem->execute([(int) $lineId, $poId]);
            $poItem = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$poItem) {
                continue;
            }

            $remaining = max(0, (float) ($poItem['qty_ordered'] ?? 0) - (float) ($poItem['qty_received'] ?? 0));
            if ($remaining <= 0) {
                continue;
            }
            if ($qty > $remaining) {
                $qty = $remaining;
            }

            $pdo->prepare('UPDATE stocks_po_items SET qty_received = qty_received + ? WHERE id = ?')
                ->execute([$qty, (int) $lineId]);

            $sku = '';
            if (tableExists('stocks_items')) {
                $skuStmt = $pdo->prepare('SELECT sku FROM stocks_items WHERE id = ? LIMIT 1');
                $skuStmt->execute([(int) $poItem['item_id']]);
                $sku = trim((string) ($skuStmt->fetchColumn() ?: ''));
            }

            $productId = sms_resolve_product_id_for_po_item($pdo, (int) $poItem['item_id'], $sku);
            if ($productId <= 0) {
                throw new Exception('Could not resolve product for PO line #' . (int) $lineId);
            }

            $receiptId = storeReceiptCreatePending(
                $pdo,
                $warehouseId,
                $productId,
                $qty,
                $poId,
                (int) $lineId,
                $reference,
                $notes !== '' ? $notes : null,
                $userId
            );
            if ($receiptId <= 0) {
                throw new Exception('Failed to create pending store receipt for line #' . (int) $lineId);
            }

            $receiptIds[] = $receiptId;
            $pendingCount++;
            $anyReceived = true;
        }

        if (!$anyReceived) {
            throw new Exception('No valid quantities were processed.');
        }

        $stmtCheck = $pdo->prepare('SELECT COALESCE(SUM(GREATEST(qty_ordered - qty_received, 0)), 0) FROM stocks_po_items WHERE po_id = ?');
        $stmtCheck->execute([$poId]);
        $remainingTotal = (float) $stmtCheck->fetchColumn();
        if ($remainingTotal <= 0) {
            $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('updated_at', $poCols, true)) {
                $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")->execute([$poId]);
            } else {
                $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received' WHERE id = ?")->execute([$poId]);
            }
        }

        $pdo->commit();

        return [
            'ok' => true,
            'message' => sprintf(
                'Delivery recorded for %s (%d line%s). Awaiting store confirmation — stock is not added yet.',
                $reference,
                $pendingCount,
                $pendingCount === 1 ? '' : 's'
            ),
            'pending_count' => $pendingCount,
            'receipt_ids' => $receiptIds,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => $e->getMessage()];
    }
}


function sms_release_upload_base_dir(): string
{
    $dir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $releases = $dir . DIRECTORY_SEPARATOR . 'releases';
    if (!is_dir($releases)) {
        mkdir($releases, 0755, true);
    }

    return $releases;
}

function sms_delete_release_upload(string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    $relativePath = str_replace('\\', '/', $relativePath);
    $relativePath = ltrim(str_replace('releases/', '', $relativePath), '/');
    $full = sms_release_upload_base_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $base = realpath(sms_release_upload_base_dir());
    $resolved = realpath($full);
    if ($base && $resolved && strpos($resolved, $base) === 0 && is_file($resolved)) {
        @unlink($resolved);
    }
}

function sms_save_release_document(array $file, string $docType, bool $required = false): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            return ['ok' => false, 'message' => 'Supporting document is required (signed note, receipt, or photo).'];
        }

        return ['ok' => true, 'skipped' => true];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'File upload failed. Please try again.'];
    }

    $maxSize = 10 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $maxSize) {
        return ['ok' => false, 'message' => 'Each file must be 10MB or smaller.'];
    }

    $originalName = (string) ($file['name'] ?? 'document');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'message' => 'Allowed file types: PDF, JPG, PNG, WEBP.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if ($mime !== '' && !in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'message' => 'Invalid file content. Use PDF or image files only.'];
        }
    }

    $subdir = date('Y/m');
    $destDir = sms_release_upload_base_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    // Map signature/receipt into supported ENUM values when recording docs.
    $storeDocType = $docType === 'signature' ? 'supporting' : ($docType === 'receipt' ? 'supporting' : $docType);
    if (!in_array($storeDocType, ['supporting', 'invoice'], true)) {
        $storeDocType = 'supporting';
    }

    $safeName = $docType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $destDir . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'Could not save the uploaded file.'];
    }

    return [
        'ok' => true,
        'doc_type' => $storeDocType,
        'logical_type' => $docType,
        'file_path' => 'releases/' . $subdir . '/' . $safeName,
        'original_name' => $originalName,
    ];
}

/**
 * Save a PNG signature from a canvas data URL.
 *
 * @return array{ok:bool,message?:string,doc_type?:string,file_path?:string,original_name?:string,skipped?:bool}
 */
function sms_save_signature_data_url(string $dataUrl, string $docType = 'signature', bool $required = true): array
{
    $dataUrl = trim($dataUrl);
    if ($dataUrl === '') {
        if ($required) {
            return ['ok' => false, 'message' => 'Issuer signature is required.'];
        }
        return ['ok' => true, 'skipped' => true];
    }

    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) {
        return ['ok' => false, 'message' => 'Invalid signature image.'];
    }

    $ext = strtolower($m[1]) === 'jpg' ? 'jpeg' : strtolower($m[1]);
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false || strlen($raw) < 32) {
        return ['ok' => false, 'message' => 'Could not decode signature.'];
    }
    if (strlen($raw) > 3 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Signature image is too large.'];
    }

    $subdir = date('Y/m');
    $destDir = sms_release_upload_base_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $safeName = $docType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = $destDir . DIRECTORY_SEPARATOR . $safeName;
    if (file_put_contents($dest, $raw) === false) {
        return ['ok' => false, 'message' => 'Could not save signature.'];
    }

    return [
        'ok' => true,
        'doc_type' => 'supporting',
        'logical_type' => $docType,
        'file_path' => 'releases/' . $subdir . '/' . $safeName,
        'original_name' => 'issuer-signature.' . ($ext === 'jpeg' ? 'jpg' : $ext),
    ];
}

/**
 * Copy the logged-in user's account signature into the release document store.
 *
 * @return array{ok:bool,message?:string,doc_type?:string,file_path?:string,original_name?:string,logical_type?:string}
 */
function sms_attach_account_signature(string $docType = 'signature'): array
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'You must be signed in to record a sample release.'];
    }

    $sigRel = null;
    if (function_exists('getUserSignaturePathById')) {
        $sigRel = getUserSignaturePathById($userId);
    }
    if (!$sigRel) {
        try {
            global $pdo;
            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare('SELECT signature_path FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $sigRel = $stmt->fetchColumn() ?: null;
            }
        } catch (Throwable $e) {
            $sigRel = null;
        }
    }

    $sigRel = is_string($sigRel) ? trim($sigRel) : '';
    if ($sigRel === '') {
        return ['ok' => false, 'message' => 'No signature on file. Add your signature in My Account first.'];
    }

    $abs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($sigRel, '/\\'));
    if (!is_file($abs) || !is_readable($abs)) {
        return ['ok' => false, 'message' => 'Account signature file was not found on the server.'];
    }

    $bytes = @file_get_contents($abs);
    if ($bytes === false || strlen($bytes) < 32) {
        return ['ok' => false, 'message' => 'Could not read account signature.'];
    }

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        $ext = 'png';
    }
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    $subdir = date('Y/m');
    $destDir = sms_release_upload_base_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $safeName = $docType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $destDir . DIRECTORY_SEPARATOR . $safeName;
    if (file_put_contents($dest, $bytes) === false) {
        return ['ok' => false, 'message' => 'Could not save account signature.'];
    }

    return [
        'ok' => true,
        'doc_type' => 'supporting',
        'logical_type' => $docType,
        'file_path' => 'releases/' . $subdir . '/' . $safeName,
        'original_name' => 'account-signature.' . $ext,
    ];
}

/**
 * Collect optional extra receipt uploads from multipart form fields extra_receipt_*.
 *
 * @return array<int, array{ok:bool,message?:string,doc_type?:string,file_path?:string,original_name?:string}>
 */
function sms_collect_delivery_attachment_uploads(): array
{
    $saved = [];
    $primary = sms_save_release_document($_FILES['delivery_attachment'] ?? [], 'receipt', false);
    if (!($primary['ok'] ?? false)) {
        return [['ok' => false, 'message' => (string) ($primary['message'] ?? 'Invalid attachment')]];
    }
    if (!($primary['skipped'] ?? false)) {
        $saved[] = $primary;
    }

    foreach ($_FILES as $key => $file) {
        if (!is_string($key) || strpos($key, 'extra_receipt_') !== 0) {
            continue;
        }
        if (!is_array($file)) {
            continue;
        }
        $upload = sms_save_release_document($file, 'receipt', false);
        if (!($upload['ok'] ?? false)) {
            return [['ok' => false, 'message' => (string) ($upload['message'] ?? 'Invalid attachment')]];
        }
        if (!($upload['skipped'] ?? false)) {
            $saved[] = $upload;
        }
    }

    return $saved;
}

function sms_collect_extra_receipt_uploads(): array
{
    $saved = [];
    foreach ($_FILES as $key => $file) {
        if (!is_string($key) || strpos($key, 'extra_receipt_') !== 0) {
            continue;
        }
        if (!is_array($file)) {
            continue;
        }
        $upload = sms_save_release_document($file, 'receipt', false);
        if (!($upload['ok'] ?? false)) {
            return [['ok' => false, 'message' => (string) ($upload['message'] ?? 'Invalid extra receipt')]];
        }
        if (!($upload['skipped'] ?? false)) {
            $saved[] = $upload;
        }
    }
    return $saved;
}

function sms_ensure_delivery_notes_table(PDO $pdo): void
{
    if (function_exists('tableExists') && tableExists('erp_delivery_notes', $pdo)) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS erp_delivery_notes (
            id INT(11) NOT NULL AUTO_INCREMENT,
            order_id INT(11) DEFAULT NULL,
            delivery_number VARCHAR(50) NOT NULL DEFAULT 'DN-TMP',
            invoice_id INT(11) DEFAULT NULL,
            customer_id INT(11) DEFAULT NULL,
            date DATE DEFAULT NULL,
            delivery_date DATE DEFAULT NULL,
            status VARCHAR(255) DEFAULT NULL,
            shipping_address VARCHAR(255) DEFAULT NULL,
            driver_name VARCHAR(255) DEFAULT NULL,
            vehicle_number VARCHAR(255) DEFAULT NULL,
            vehicle_reg VARCHAR(50) DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT(11) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_edn_invoice (invoice_id),
            KEY idx_edn_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('sms_ensure_delivery_notes_table: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/invoice_helpers.php';

function sms_fetch_products(PDO $pdo, int $warehouseId, string $currencySymbol): array
{
    $imageSql = function_exists('stock_product_main_image_sql')
        ? stock_product_main_image_sql($pdo, 'p')
        : "NULLIF(TRIM(p.main_image), '')";

    $sql = "SELECT p.*, c.name AS category_name,
                   COALESCE(s.quantity, 0) AS quantity,
                   ({$imageSql}) AS product_image
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stock s ON p.id = s.product_id AND s.warehouse_id = ?
            ORDER BY p.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$warehouseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn(array $row) => sms_map_product($row, $currencySymbol), $rows);
}

try {
    $settings = function_exists('getCompanySettings') ? getCompanySettings($pdo) : ['currency' => 'TZS'];
    $currency = strtoupper((string) ($settings['currency'] ?? 'TZS'));
    $currencySymbol = function_exists('getCurrencySymbol') ? getCurrencySymbol($currency) : 'TSh ';
    $showCost = in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true);
    $companyName = trim((string) ($settings['company_name'] ?? ''));
    if ($companyName === '') {
        $companyName = 'Store';
    }
    $warehouseDisplayName = $companyName . ' Warehouse';

    switch ($action) {
        case 'init':
            $warehouses = $pdo->query('SELECT id, code, name, address, is_active FROM warehouses ORDER BY name ASC')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $categories = $pdo->query('SELECT id, name, description FROM categories ORDER BY name ASC')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];

            sms_json([
                'success' => true,
                'warehouses' => array_map(static function (array $w) use ($warehouseDisplayName): array {
                    return [
                        'id' => (int) $w['id'],
                        'code' => (string) $w['code'],
                        'name' => $warehouseDisplayName,
                        'address' => (string) ($w['address'] ?? ''),
                        'isActive' => (bool) ($w['is_active'] ?? true),
                    ];
                }, $warehouses),
                'categories' => array_map(static fn(array $c) => [
                    'id' => (string) $c['id'],
                    'name' => (string) $c['name'],
                    'description' => (string) ($c['description'] ?? ''),
                ], $categories),
                'config' => [
                    'currency' => $currency,
                    'currencySymbol' => trim($currencySymbol),
                    'showCost' => $showCost,
                    'companyName' => $companyName,
                    'companyLogoUrl' => function_exists('getCompanyLogoUrl')
                        ? (string) getCompanyLogoUrl()
                        : '',
                    'canManageProducts' => sms_can_manage_products(),
                    'manageProductsUrl' => function_exists('app_url')
                        ? app_url('stock/modules/products/index.php')
                        : '../stock/modules/products/index.php',
                    'manageWarehousesUrl' => function_exists('app_url')
                        ? app_url('stock/modules/warehouses/index.php')
                        : '../stock/modules/warehouses/index.php',
                ],
            ]);
            break;

        case 'products':
            $warehouseId = (int) ($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
            if ($warehouseId <= 0) {
                sms_error('warehouse_id is required');
            }

            $whCheck = $pdo->prepare('SELECT id FROM warehouses WHERE id = ?');
            $whCheck->execute([$warehouseId]);
            if (!$whCheck->fetchColumn()) {
                sms_error('Warehouse not found', 404);
            }

            sms_json([
                'success' => true,
                'products' => sms_fetch_products($pdo, $warehouseId, $currencySymbol),
            ]);
            break;

        case 'product_add':
            if (!sms_can_manage_products()) {
                sms_error('Product catalogue is managed by Procurement', 403);
            }

            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $categoryName = trim((string) ($_POST['category'] ?? ''));
            $price = (float) ($_POST['price'] ?? 0);
            $cost = (float) ($_POST['cost'] ?? 0);
            $stock = (int) ($_POST['stock'] ?? 0);
            $minStock = (int) ($_POST['minStock'] ?? 10);
            $unit = trim((string) ($_POST['unit'] ?? 'pcs'));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($warehouseId <= 0) {
                sms_error('warehouse_id is required');
            }
            if ($name === '') {
                sms_error('Product name is required');
            }

            if ($sku === '') {
                $sku = sms_generate_product_code($pdo);
            } else {
                $dup = $pdo->prepare('SELECT COUNT(*) FROM products WHERE product_code = ?');
                $dup->execute([$sku]);
                if ((int) $dup->fetchColumn() > 0) {
                    sms_error('A product with this SKU already exists');
                }
            }

            $categoryId = sms_resolve_category_id($pdo, $categoryName);

            $pdo->beginTransaction();

            $fields = ['product_code', 'name', 'unit_price', 'reorder_level'];
            $values = [$sku, $name, $price, $minStock];

            if ($categoryId !== null) {
                $fields[] = 'category_id';
                $values[] = $categoryId;
            }
            if (sms_has_column($pdo, 'description')) {
                $fields[] = 'description';
                $values[] = $description;
            }
            if (sms_has_column($pdo, 'buying_price')) {
                $fields[] = 'buying_price';
                $values[] = $cost;
            }
            if (sms_has_column($pdo, 'unit_of_measure')) {
                $fields[] = 'unit_of_measure';
                $values[] = $unit;
            } elseif (sms_has_column($pdo, 'uom')) {
                $fields[] = 'uom';
                $values[] = $unit;
            }
            if (sms_has_column($pdo, 'item_type')) {
                $fields[] = 'item_type';
                $values[] = 'general';
            }

            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $quotedFields = implode(', ', array_map(static fn(string $f) => "`$f`", $fields));
            $pdo->prepare("INSERT INTO products ($quotedFields) VALUES ($placeholders)")->execute($values);

            $productId = (int) $pdo->lastInsertId();

            if ($stock > 0) {
                sms_upsert_stock($pdo, $productId, $warehouseId, $stock);
                sms_record_movement($pdo, $productId, $warehouseId, 'in', $stock, 'Opening stock via store management');
            } else {
                sms_upsert_stock($pdo, $productId, $warehouseId, 0);
            }

            $pdo->commit();

            $stmt = $pdo->prepare(
                'SELECT p.*, c.name AS category_name, COALESCE(s.quantity, 0) AS quantity
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 LEFT JOIN stock s ON p.id = s.product_id AND s.warehouse_id = ?
                 WHERE p.id = ?'
            );
            $stmt->execute([$warehouseId, $productId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            sms_json([
                'success' => true,
                'product' => $row ? sms_map_product($row, $currencySymbol) : null,
            ]);
            break;

        case 'product_update':
            if (!sms_can_manage_products()) {
                sms_error('Product catalogue is managed by Procurement', 403);
            }

            $productId = (int) ($_POST['id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            if ($productId <= 0 || $warehouseId <= 0) {
                sms_error('Product id and warehouse_id are required');
            }

            $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $categoryName = trim((string) ($_POST['category'] ?? ''));
            $price = (float) ($_POST['price'] ?? 0);
            $cost = (float) ($_POST['cost'] ?? 0);
            $newStock = isset($_POST['stock']) ? (int) $_POST['stock'] : null;
            $minStock = (int) ($_POST['minStock'] ?? 10);
            $unit = trim((string) ($_POST['unit'] ?? 'pcs'));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($name === '' || $sku === '') {
                sms_error('Name and SKU are required');
            }

            $dup = $pdo->prepare('SELECT COUNT(*) FROM products WHERE product_code = ? AND id != ?');
            $dup->execute([$sku, $productId]);
            if ((int) $dup->fetchColumn() > 0) {
                sms_error('Another product with this SKU already exists');
            }

            $categoryId = sms_resolve_category_id($pdo, $categoryName);

            $pdo->beginTransaction();

            $sets = ['product_code = ?', 'name = ?', 'unit_price = ?', 'reorder_level = ?'];
            $values = [$sku, $name, $price, $minStock];

            if ($categoryId !== null) {
                $sets[] = 'category_id = ?';
                $values[] = $categoryId;
            }
            if (sms_has_column($pdo, 'description')) {
                $sets[] = 'description = ?';
                $values[] = $description;
            }
            if (sms_has_column($pdo, 'buying_price')) {
                $sets[] = 'buying_price = ?';
                $values[] = $cost;
            }
            if (sms_has_column($pdo, 'unit_of_measure')) {
                $sets[] = 'unit_of_measure = ?';
                $values[] = $unit;
            } elseif (sms_has_column($pdo, 'uom')) {
                $sets[] = 'uom = ?';
                $values[] = $unit;
            }

            $values[] = $productId;
            $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);

            if ($newStock !== null) {
                $curStmt = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ? AND warehouse_id = ?');
                $curStmt->execute([$productId, $warehouseId]);
                $currentQty = (int) ($curStmt->fetchColumn() ?: 0);

                sms_upsert_stock($pdo, $productId, $warehouseId, max(0, $newStock));

                $diff = $newStock - $currentQty;
                if ($diff !== 0) {
                    sms_record_movement(
                        $pdo,
                        $productId,
                        $warehouseId,
                        $diff > 0 ? 'in' : 'out',
                        abs($diff),
                        'Stock level updated via store management'
                    );
                }
            }

            $pdo->commit();

            $stmt = $pdo->prepare(
                'SELECT p.*, c.name AS category_name, COALESCE(s.quantity, 0) AS quantity
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 LEFT JOIN stock s ON p.id = s.product_id AND s.warehouse_id = ?
                 WHERE p.id = ?'
            );
            $stmt->execute([$warehouseId, $productId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            sms_json([
                'success' => true,
                'product' => $row ? sms_map_product($row, $currencySymbol) : null,
            ]);
            break;

        case 'product_delete':
            if (!sms_can_manage_products()) {
                sms_error('Product catalogue is managed by Procurement', 403);
            }

            $productId = (int) ($_POST['id'] ?? 0);
            if ($productId <= 0) {
                sms_error('Product id is required');
            }

            $stockCheck = $pdo->prepare('SELECT SUM(quantity) FROM stock WHERE product_id = ?');
            $stockCheck->execute([$productId]);
            $totalQty = (float) $stockCheck->fetchColumn();

            if ($totalQty > 0) {
                sms_error('Cannot delete product with active stock. Adjust stock to zero first.');
            }

            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);

            sms_json(['success' => true]);
            break;

        case 'stock_adjust':
            if (!sms_can_manage_products()) {
                sms_error('Stock adjustments are managed through verify receipts and stock out workflows', 403);
            }

            $productId = (int) ($_POST['product_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $change = (int) ($_POST['change'] ?? 0);

            if ($productId <= 0 || $warehouseId <= 0 || $change === 0) {
                sms_error('product_id, warehouse_id and non-zero change are required');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ? AND warehouse_id = ?');
            $stmt->execute([$productId, $warehouseId]);
            $currentQty = (int) ($stmt->fetchColumn() ?: 0);
            $newQty = max(0, $currentQty + $change);

            sms_upsert_stock($pdo, $productId, $warehouseId, $newQty);
            sms_record_movement(
                $pdo,
                $productId,
                $warehouseId,
                $change > 0 ? 'in' : 'out',
                abs($change),
                'Quick stock adjustment via store management'
            );

            $pdo->commit();

            sms_json(['success' => true, 'stock' => $newQty]);
            break;

        case 'movements':
            $warehouseId = (int) ($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
            if ($warehouseId <= 0) {
                sms_error('warehouse_id is required');
            }

            $whCheck = $pdo->prepare('SELECT id FROM warehouses WHERE id = ?');
            $whCheck->execute([$warehouseId]);
            if (!$whCheck->fetchColumn()) {
                sms_error('Warehouse not found', 404);
            }

            $movements = sms_fetch_movements($pdo, $warehouseId, [
                'product_id' => (int) ($_GET['product_id'] ?? $_POST['product_id'] ?? 0),
                'type' => (string) ($_GET['type'] ?? $_POST['type'] ?? ''),
                'search' => (string) ($_GET['search'] ?? $_POST['search'] ?? ''),
                // Desk shows store history, not only the current calendar month.
                'start_date' => (string) ($_GET['start_date'] ?? $_POST['start_date'] ?? ''),
                'end_date' => (string) ($_GET['end_date'] ?? $_POST['end_date'] ?? ''),
                'limit' => (int) ($_GET['limit'] ?? $_POST['limit'] ?? 100),
                'store_only' => true,
            ]);

            $totalIn = 0;
            $totalOut = 0;
            foreach ($movements as $movement) {
                $qty = abs((int) $movement['quantity']);
                if ($movement['movementType'] === 'in') {
                    $totalIn += $qty;
                } elseif ($movement['movementType'] === 'out') {
                    $totalOut += $qty;
                }
            }

            sms_json([
                'success' => true,
                'movements' => $movements,
                'stats' => [
                    'totalIn' => $totalIn,
                    'totalOut' => $totalOut,
                    'netMovement' => $totalIn - $totalOut,
                ],
            ]);
            break;

        case 'stock_movement':
            $productId = (int) ($_POST['product_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $direction = strtolower(trim((string) ($_POST['direction'] ?? '')));
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($productId <= 0 || $warehouseId <= 0) {
                sms_error('product_id and warehouse_id are required');
            }
            if (!in_array($direction, ['in', 'out'], true)) {
                sms_error('direction must be in or out');
            }
            if ($quantity <= 0) {
                sms_error('quantity must be greater than zero');
            }
            if ($reason === '') {
                sms_error('reason is required');
            }

            if ($direction === 'in') {
                sms_error('Stock in must be verified through procurement receipts.');
            }

            $prodCheck = $pdo->prepare('SELECT id, name FROM products WHERE id = ?');
            $prodCheck->execute([$productId]);
            $product = $prodCheck->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                sms_error('Product not found', 404);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ? AND warehouse_id = ?');
            $stmt->execute([$productId, $warehouseId]);
            $currentQty = (int) ($stmt->fetchColumn() ?: 0);

            if ($direction === 'out' && $quantity > $currentQty) {
                $pdo->rollBack();
                sms_error("Cannot remove {$quantity} units — only {$currentQty} available in this warehouse");
            }

            $newQty = $direction === 'in'
                ? $currentQty + $quantity
                : max(0, $currentQty - $quantity);

            sms_upsert_stock($pdo, $productId, $warehouseId, $newQty);

            $movementNotes = $reason;
            if ($notes !== '') {
                $movementNotes .= ($movementNotes !== '' ? ' — ' : '') . $notes;
            }
            $movementNotes .= ' (Store management manual out)';

            sms_record_movement($pdo, $productId, $warehouseId, $direction, $quantity, $movementNotes);

            $pdo->commit();

            sms_json([
                'success' => true,
                'stock' => $newQty,
                'movement' => [
                    'productId' => (string) $productId,
                    'productName' => (string) $product['name'],
                    'movementType' => $direction,
                    'quantity' => $quantity,
                    'notes' => $movementNotes,
                ],
            ]);
            break;

        case 'sample_stock_out':
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $items = $_POST['items'] ?? [];
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $issuerName = trim((string) ($_POST['issuer_name'] ?? ''));

            if (is_string($items)) {
                $decoded = json_decode($items, true);
                $items = is_array($decoded) ? $decoded : [];
            }

            if ($warehouseId <= 0) {
                sms_error('warehouse_id is required');
            }
            if (!is_array($items) || $items === []) {
                sms_error('items is required');
            }
            if ($reason === '') {
                sms_error('reason is required');
            }
            if ($issuerName === '') {
                sms_error('issuer_name is required');
            }

            $savedDocs = [];
            $sigUpload = sms_attach_account_signature('signature');
            if (!($sigUpload['ok'] ?? false)) {
                sms_error((string) ($sigUpload['message'] ?? 'Account signature is required'));
            }
            $savedDocs[] = $sigUpload;

            $supportUpload = sms_save_release_document($_FILES['supporting_document'] ?? [], 'supporting', false);
            if (!($supportUpload['ok'] ?? false) && !($supportUpload['skipped'] ?? false)) {
                foreach ($savedDocs as $doc) {
                    if (!empty($doc['file_path'])) {
                        sms_delete_release_upload((string) $doc['file_path']);
                    }
                }
                sms_error((string) ($supportUpload['message'] ?? 'Invalid receipt attachment'));
            }
            if (!($supportUpload['skipped'] ?? false)) {
                $savedDocs[] = $supportUpload;
            }

            $extraUploads = sms_collect_extra_receipt_uploads();
            foreach ($extraUploads as $extra) {
                if (!($extra['ok'] ?? false)) {
                    foreach ($savedDocs as $doc) {
                        if (!empty($doc['file_path'])) {
                            sms_delete_release_upload((string) $doc['file_path']);
                        }
                    }
                    sms_error((string) ($extra['message'] ?? 'Invalid receipt attachment'));
                }
                $savedDocs[] = $extra;
            }

            $pdo->beginTransaction();
            try {
                $paths = [];
                foreach ($savedDocs as $doc) {
                    if (!empty($doc['file_path'])) {
                        $paths[] = (string) $doc['file_path'];
                    }
                }
                $stockStmt = $pdo->prepare('SELECT quantity FROM stock WHERE product_id = ? AND warehouse_id = ?');
                $productStmt = $pdo->prepare('SELECT id, name FROM products WHERE id = ?');
                $itemResults = [];

                foreach ($items as $rawProductId => $rawQty) {
                    $productId = (int) $rawProductId;
                    $quantity = (int) $rawQty;
                    if ($productId <= 0 || $quantity <= 0) {
                        throw new RuntimeException('Each sample item must include a valid product and quantity.');
                    }

                    $productStmt->execute([$productId]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$product) {
                        throw new RuntimeException('One of the selected products was not found.');
                    }

                    $stockStmt->execute([$productId, $warehouseId]);
                    $currentQty = (int) ($stockStmt->fetchColumn() ?: 0);
                    if ($quantity > $currentQty) {
                        throw new RuntimeException("Cannot remove {$quantity} units of {$product['name']} — only {$currentQty} available in this warehouse");
                    }

                    $newQty = max(0, $currentQty - $quantity);
                    sms_upsert_stock($pdo, $productId, $warehouseId, $newQty);

                    $movementNotes = 'Sample out: ' . $reason;
                    $movementNotes .= ' | Issuer: ' . $issuerName;
                    if ($notes !== '') {
                        $movementNotes .= ' | ' . $notes;
                    }
                    if ($paths !== []) {
                        $movementNotes .= ' | Docs: ' . implode(', ', $paths);
                    }

                    sms_record_movement($pdo, $productId, $warehouseId, 'out', $quantity, $movementNotes, 'adjustment', '0');
                    $itemResults[] = [
                        'productId' => (string) $productId,
                        'productName' => (string) $product['name'],
                        'quantity' => $quantity,
                        'stock' => $newQty,
                    ];
                }

                if (function_exists('storeReleaseRecordDocuments')) {
                    try {
                        storeReleaseRecordDocuments(
                            $pdo,
                            0,
                            0,
                            $warehouseId,
                            $savedDocs,
                            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
                        );
                    } catch (Throwable $docErr) {
                        error_log('sample_stock_out docs: ' . $docErr->getMessage());
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($savedDocs as $doc) {
                    if (!empty($doc['file_path'])) {
                        sms_delete_release_upload((string) $doc['file_path']);
                    }
                }
                sms_error($e->getMessage());
            }

            sms_json([
                'success' => true,
                'items' => $itemResults,
                'message' => 'Sample outgoing recorded',
            ]);
            break;

        case 'pending_receipts':
            $warehouseId = (int) ($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
            if ($warehouseId <= 0) {
                sms_error('warehouse_id is required');
            }
            if (!function_exists('storeReceiptFetchPending')) {
                sms_json(['success' => true, 'receipts' => []]);
            }
            $rows = storeReceiptFetchPending($pdo, $warehouseId);
            $receiptIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows);
            $docsByReceipt = function_exists('storeReceiptFetchDocuments')
                ? storeReceiptFetchDocuments($pdo, $receiptIds)
                : [];

            $poAttachmentCache = [];

            sms_json([
                'success' => true,
                'receipts' => array_map(static function (array $row) use ($pdo, $docsByReceipt, &$poAttachmentCache): array {
                    $rid = (int) ($row['id'] ?? 0);
                    $docs = $docsByReceipt[$rid] ?? [];
                    $poId = (int) ($row['po_id'] ?? 0);
                    $poAttachments = [];
                    if ($poId > 0) {
                        if (!isset($poAttachmentCache[$poId])) {
                            $poAttachmentCache[$poId] = sms_fetch_po_attachments($pdo, $poId, 'stocks');
                        }
                        $poAttachments = $poAttachmentCache[$poId];
                    }

                    $qtyExpected = (float) ($row['qty_expected'] ?? 0);
                    $qtyOriginal = (float) ($row['qty_original_expected'] ?? 0);
                    $qtyPrior = (float) ($row['qty_prior_received'] ?? 0);
                    $procuredNotes = (string) ($row['procured_notes'] ?? '');

                    // Legacy remainder notes: "Remaining quantity after partial confirm (45 of 46). Shortfall reason: ..."
                    if ($qtyOriginal <= 0 && preg_match(
                        '/Remaining quantity after partial confirm\s*\(([\d.]+)\s+of\s+([\d.]+)\)/i',
                        $procuredNotes,
                        $m
                    )) {
                        $qtyPrior = (float) $m[1];
                        $qtyOriginal = (float) $m[2];
                        if (preg_match('/Shortfall reason:\s*(.+)$/is', $procuredNotes, $rm)) {
                            $procuredNotes = 'Shortfall reason: ' . trim($rm[1]);
                        }
                    }

                    return [
                        'id' => (string) ($row['id'] ?? ''),
                        'warehouseId' => (int) ($row['warehouse_id'] ?? 0),
                        'productId' => (string) ($row['product_id'] ?? ''),
                        'productName' => (string) ($row['product_name'] ?? ''),
                        'productSku' => (string) ($row['product_code'] ?? ''),
                        'poId' => (string) ($row['po_id'] ?? ''),
                        'poReference' => (string) ($row['po_reference'] ?? ''),
                        'qtyExpected' => $qtyExpected,
                        'qtyOriginalExpected' => $qtyOriginal,
                        'qtyPriorReceived' => $qtyPrior,
                        'procuredNotes' => $procuredNotes,
                        'procuredAt' => (string) ($row['procured_at'] ?? ''),
                        'attachments' => array_map(static function (array $doc): array {
                            return [
                                'id' => (string) ($doc['id'] ?? ''),
                                'name' => (string) ($doc['original_name'] ?? ''),
                                'url' => './api/index.php?action=receipt_document&id=' . (int) ($doc['id'] ?? 0),
                                'kind' => 'delivery',
                            ];
                        }, $docs),
                        'poAttachments' => $poAttachments,
                    ];
                }, $rows),
            ]);
            break;

        case 'receipt_document':
            $docId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
            if ($docId <= 0) {
                sms_error('Document id is required');
            }
            if (!function_exists('ensureStoreReceiptDocumentsTable')) {
                sms_error('Receipt documents are not available', 404);
            }
            ensureStoreReceiptDocumentsTable($pdo);
            $stmt = $pdo->prepare('SELECT * FROM store_receipt_documents WHERE id = ? LIMIT 1');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                sms_error('Document not found', 404);
            }

            $relative = str_replace('\\', '/', (string) ($doc['file_path'] ?? ''));
            $relative = ltrim(str_replace('releases/', '', $relative), '/');
            $full = sms_release_upload_base_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $base = realpath(sms_release_upload_base_dir());
            $resolved = realpath($full);
            if (!$base || !$resolved || strpos($resolved, $base) !== 0 || !is_file($resolved)) {
                sms_error('File not found', 404);
            }

            $originalName = trim((string) ($doc['original_name'] ?? ''));
            if ($originalName === '') {
                $originalName = basename($resolved);
            }
            $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
            $mimeMap = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';

            header_remove('Content-Type');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($resolved));
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $originalName) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($resolved);
            exit;

        case 'verify_receipt':
            $receiptId = (int) ($_POST['receipt_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $qtyVerified = isset($_POST['qty_verified']) ? (float) $_POST['qty_verified'] : null;
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($receiptId <= 0 || $warehouseId <= 0 || $qtyVerified === null) {
                sms_error('receipt_id, warehouse_id and qty_verified are required');
            }
            if (!function_exists('storeReceiptVerify')) {
                sms_error('Store verification is not available on this server');
            }

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $result = storeReceiptVerify($pdo, $receiptId, $warehouseId, $qtyVerified, $notes, $userId);
            if (!($result['ok'] ?? false)) {
                sms_error((string) ($result['message'] ?? 'Verification failed'));
            }

            sms_json([
                'success' => true,
                'message' => (string) ($result['message'] ?? 'Verified'),
            ]);
            break;

        case 'update_pending_receipt':
            $receiptId = (int) ($_POST['receipt_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $qtyExpected = isset($_POST['qty_expected']) ? (float) $_POST['qty_expected'] : null;
            $poReference = trim((string) ($_POST['po_reference'] ?? ''));

            if ($receiptId <= 0 || $warehouseId <= 0 || $qtyExpected === null || $qtyExpected < 0) {
                sms_error('receipt_id, warehouse_id and qty_expected are required');
            }
            if (function_exists('ensureStoreWarehouseReceiptsTable')) {
                ensureStoreWarehouseReceiptsTable($pdo);
            }

            $stmt = $pdo->prepare("UPDATE store_warehouse_receipts SET qty_expected = ?, po_reference = ? WHERE id = ? AND warehouse_id = ? AND status = 'pending'");
            $stmt->execute([$qtyExpected, $poReference, $receiptId, $warehouseId]);
            if ($stmt->rowCount() <= 0) {
                sms_error('Pending receipt not found or already processed', 404);
            }

            sms_json(['success' => true, 'message' => 'Receipt updated']);
            break;

        case 'manual_incoming_confirm':
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $qtyExpected = isset($_POST['qty_expected']) ? (float) $_POST['qty_expected'] : 0;
            $qtyVerified = isset($_POST['qty_verified']) ? (float) $_POST['qty_verified'] : 0;
            $poReference = trim((string) ($_POST['po_reference'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($warehouseId <= 0 || $productId <= 0 || $qtyVerified <= 0) {
                sms_error('warehouse_id, product_id and qty_verified are required');
            }
            if ($qtyExpected <= 0) {
                $qtyExpected = $qtyVerified;
            }
            if (!function_exists('storeReceiptCreatePending') || !function_exists('storeReceiptVerify')) {
                sms_error('Store verification is not available on this server');
            }

            $prodCheck = $pdo->prepare('SELECT id FROM products WHERE id = ?');
            $prodCheck->execute([$productId]);
            if (!$prodCheck->fetchColumn()) {
                sms_error('Product not found', 404);
            }

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $receiptId = storeReceiptCreatePending(
                $pdo,
                $warehouseId,
                $productId,
                $qtyExpected,
                null,
                null,
                $poReference !== '' ? $poReference : 'Manual incoming',
                $notes !== '' ? $notes : 'Manual entry from store confirmation sheet',
                $userId
            );
            if ($receiptId <= 0) {
                sms_error('Failed to create incoming receipt row');
            }

            $result = storeReceiptVerify($pdo, $receiptId, $warehouseId, $qtyVerified, $notes, $userId);
            if (!($result['ok'] ?? false)) {
                sms_error((string) ($result['message'] ?? 'Verification failed'));
            }

            sms_json([
                'success' => true,
                'message' => (string) ($result['message'] ?? 'Stock confirmed'),
                'receiptId' => (string) $receiptId,
            ]);
            break;

        case 'purchase_orders':
            sms_json([
                'success' => true,
                'orders' => sms_fetch_receivable_purchase_orders($pdo),
            ]);
            break;

        case 'purchase_order':
            $poId = (int) ($_GET['po_id'] ?? $_POST['po_id'] ?? 0);
            $source = trim((string) ($_GET['source'] ?? $_POST['source'] ?? 'stocks'));
            if ($poId <= 0) {
                sms_error('po_id is required');
            }
            $detail = sms_fetch_purchase_order_detail($pdo, $poId, $source !== '' ? $source : 'stocks');
            if ($detail === null) {
                sms_error('Purchase order not found or not available for receiving', 404);
            }
            sms_json([
                'success' => true,
                'order' => $detail['order'],
                'lines' => $detail['lines'],
                'attachments' => $detail['attachments'] ?? [],
            ]);
            break;

        case 'purchase_order_receive':
            if (!sms_can_manage_products()) {
                sms_error('Purchase orders are received by Procurement. Store manager verifies at the warehouse.', 403);
            }

            $poId = (int) ($_POST['po_id'] ?? 0);
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $source = trim((string) ($_POST['source'] ?? 'stocks'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $receiveQuantities = $_POST['receive_qty'] ?? [];
            if (is_string($receiveQuantities)) {
                $decoded = json_decode($receiveQuantities, true);
                $receiveQuantities = is_array($decoded) ? $decoded : [];
            }

            if ($poId <= 0 || $warehouseId <= 0) {
                sms_error('po_id and warehouse_id are required');
            }
            if (!is_array($receiveQuantities) || $receiveQuantities === []) {
                sms_error('receive_qty is required');
            }

            $whCheck = $pdo->prepare('SELECT id FROM warehouses WHERE id = ?');
            $whCheck->execute([$warehouseId]);
            if (!$whCheck->fetchColumn()) {
                sms_error('Warehouse not found', 404);
            }

            $attachmentUploads = sms_collect_delivery_attachment_uploads();
            $savedAttachments = [];
            foreach ($attachmentUploads as $upload) {
                if (!($upload['ok'] ?? false)) {
                    foreach ($savedAttachments as $doc) {
                        if (!empty($doc['file_path'])) {
                            sms_delete_release_upload((string) $doc['file_path']);
                        }
                    }
                    sms_error((string) ($upload['message'] ?? 'Invalid delivery attachment'));
                }
                $savedAttachments[] = $upload;
            }

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $result = sms_receive_purchase_order($pdo, $poId, $warehouseId, $receiveQuantities, $notes, $source, $userId);
            if (!($result['ok'] ?? false)) {
                foreach ($savedAttachments as $doc) {
                    if (!empty($doc['file_path'])) {
                        sms_delete_release_upload((string) $doc['file_path']);
                    }
                }
                sms_error((string) ($result['message'] ?? 'Failed to receive purchase order'));
            }

            $receiptIds = array_map('intval', $result['receipt_ids'] ?? []);
            if ($savedAttachments !== [] && $receiptIds !== [] && function_exists('storeReceiptRecordDocuments')) {
                try {
                    storeReceiptRecordDocuments($pdo, $receiptIds, $warehouseId, $savedAttachments, $userId);
                } catch (Throwable $e) {
                    foreach ($savedAttachments as $doc) {
                        if (!empty($doc['file_path'])) {
                            sms_delete_release_upload((string) $doc['file_path']);
                        }
                    }
                    sms_error('Delivery was recorded but attachments could not be saved: ' . $e->getMessage());
                }
            }

            sms_json([
                'success' => true,
                'message' => (string) ($result['message'] ?? 'Delivery recorded — awaiting store confirmation'),
                'pending_count' => (int) ($result['pending_count'] ?? 0),
            ]);
            break;

        case 'category_add':
            if (!sms_can_manage_products()) {
                sms_error('Product catalogue is managed by Procurement', 403);
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($name === '') {
                sms_error('Category name is required');
            }

            $dup = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE name = ?');
            $dup->execute([$name]);
            if ((int) $dup->fetchColumn() > 0) {
                sms_error('This category already exists');
            }

            $cols = sms_category_columns($pdo);
            $fields = ['name'];
            $placeholders = ['?'];
            $values = [$name];

            if (in_array('description', $cols, true)) {
                $fields[] = 'description';
                $placeholders[] = '?';
                $values[] = $description;
            }

            $sql = 'INSERT INTO categories (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $pdo->prepare($sql)->execute($values);
            $id = (int) $pdo->lastInsertId();

            sms_json([
                'success' => true,
                'category' => [
                    'id' => (string) $id,
                    'name' => $name,
                    'description' => $description,
                ],
            ]);
            break;

        case 'category_delete':
            if (!sms_can_manage_products()) {
                sms_error('Product catalogue is managed by Procurement', 403);
            }

            $categoryId = (int) ($_POST['id'] ?? 0);
            if ($categoryId <= 0) {
                sms_error('Category id is required');
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
            $countStmt->execute([$categoryId]);
            $count = (int) $countStmt->fetchColumn();

            if ($count > 0) {
                sms_error("Cannot delete category with $count assigned products");
            }

            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);
            sms_json(['success' => true]);
            break;


        case 'pending_invoices':
            $warehouseId = (int) ($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
            if ($warehouseId <= 0) {
                sms_error('warehouse_id is required');
            }
            $filter = strtolower(trim((string) ($_GET['filter'] ?? $_POST['filter'] ?? 'pending')));
            if (!in_array($filter, ['pending', 'released', 'all'], true)) {
                $filter = 'pending';
            }
            sms_json([
                'success' => true,
                'invoices' => sms_fetch_pending_invoices($pdo, $filter),
            ]);
            break;

        case 'invoice_detail':
            $warehouseId = (int) ($_GET['warehouse_id'] ?? $_POST['warehouse_id'] ?? 0);
            $invoiceRef = trim((string) ($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? ''));
            if ($warehouseId <= 0 || $invoiceRef === '') {
                sms_error('warehouse_id and invoice_id are required');
            }
            $detail = sms_fetch_invoice_detail($pdo, $warehouseId, $invoiceRef);
            if ($detail === null) {
                sms_error('Invoice not found', 404);
            }
            sms_json([
                'success' => true,
                'invoice' => $detail['invoice'],
                'lines' => $detail['lines'],
            ]);
            break;

        case 'invoice_dispatch':
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $invoiceRef = trim((string) ($_POST['invoice_id'] ?? ''));
            $items = $_POST['items'] ?? [];
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $issuerName = trim((string) ($_POST['issuer_name'] ?? ''));
            $signatureDataUrl = (string) ($_POST['issuer_signature'] ?? '');

            if (is_string($items)) {
                $decoded = json_decode($items, true);
                $items = is_array($decoded) ? $decoded : [];
            }

            if ($warehouseId <= 0 || $invoiceRef === '') {
                sms_error('warehouse_id and invoice_id are required');
            }
            if (!is_array($items) || $items === []) {
                sms_error('items is required');
            }
            if ($issuerName === '') {
                sms_error('Invoice issuer name is required');
            }

            $savedDocs = [];
            if (trim($signatureDataUrl) !== '') {
                $sigUpload = sms_save_signature_data_url($signatureDataUrl, 'signature', false);
                if (($sigUpload['ok'] ?? false) && !($sigUpload['skipped'] ?? false)) {
                    $savedDocs[] = $sigUpload;
                }
            }

            $supportUpload = sms_save_release_document($_FILES['supporting_document'] ?? [], 'supporting', false);
            if (!($supportUpload['ok'] ?? false) && !($supportUpload['skipped'] ?? false)) {
                foreach ($savedDocs as $doc) {
                    if (!empty($doc['file_path'])) {
                        sms_delete_release_upload((string) $doc['file_path']);
                    }
                }
                sms_error((string) ($supportUpload['message'] ?? 'Invalid supporting document'));
            }
            if (!($supportUpload['skipped'] ?? false)) {
                $savedDocs[] = $supportUpload;
            }

            $invoiceUpload = sms_save_release_document($_FILES['invoice_document'] ?? [], 'invoice', false);
            if (!($invoiceUpload['ok'] ?? false) && !($invoiceUpload['skipped'] ?? false)) {
                foreach ($savedDocs as $doc) {
                    if (!empty($doc['file_path'])) {
                        sms_delete_release_upload((string) $doc['file_path']);
                    }
                }
                sms_error((string) ($invoiceUpload['message'] ?? 'Invalid invoice document'));
            }
            if (!($invoiceUpload['skipped'] ?? false)) {
                $savedDocs[] = $invoiceUpload;
            }

            $extraUploads = sms_collect_extra_receipt_uploads();
            foreach ($extraUploads as $extra) {
                if (!($extra['ok'] ?? false)) {
                    foreach ($savedDocs as $doc) {
                        if (!empty($doc['file_path'])) {
                            sms_delete_release_upload((string) $doc['file_path']);
                        }
                    }
                    sms_error((string) ($extra['message'] ?? 'Invalid receipt attachment'));
                }
                $savedDocs[] = $extra;
            }

            if ($issuerName !== '') {
                $notes = trim($notes . ($notes !== '' ? ' | ' : '') . 'Issuer: ' . $issuerName);
            }

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $result = sms_dispatch_invoice($pdo, $warehouseId, $invoiceRef, $items, $notes, $userId);
            if (!($result['ok'] ?? false)) {
                foreach ($savedDocs as $doc) {
                    if (!empty($doc['file_path'])) {
                        sms_delete_release_upload((string) $doc['file_path']);
                    }
                }
                sms_error((string) ($result['message'] ?? 'Failed to dispatch invoice'));
            }

            $deliveryId = (int) ($result['delivery_id'] ?? 0);
            if ($deliveryId > 0 && function_exists('storeReleaseRecordDocuments')) {
                $invoiceNumericId = (int) (sms_parse_invoice_ref($invoiceRef)['id'] ?? 0);
                storeReleaseRecordDocuments($pdo, $deliveryId, $invoiceNumericId, $warehouseId, $savedDocs, $userId);
            }

            sms_json([
                'success' => true,
                'message' => (string) ($result['message'] ?? 'Invoice dispatched'),
                'deliveryNumber' => (string) ($result['delivery_number'] ?? ''),
            ]);
            break;

        case 'labels_init':
            require_once __DIR__ . '/../lib.php';
            require_once __DIR__ . '/../label-lib.php';
            sms_ensure_label_placements_table($pdo);

            $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

            sms_json([
                'success' => true,
                'categories' => array_map(static fn(array $row) => [
                    'id' => (string) $row['id'],
                    'name' => (string) ($row['name'] ?? ''),
                ], $categories),
                'placedCount' => sms_count_label_placed($pdo),
                'perPageOptions' => [
                    ['value' => 1, 'label' => '1 (wide landscape)'],
                    ['value' => 2, 'label' => '2'],
                    ['value' => 4, 'label' => '4'],
                    ['value' => 6, 'label' => '6'],
                    ['value' => 8, 'label' => '8'],
                ],
                'labelDownloadUrl' => storeManagementUiPublicUrl('label-download.php'),
                'labelStarUrl' => storeManagementUiPublicUrl('label-star.php'),
            ]);
            break;

        case 'labels_products':
            require_once __DIR__ . '/../label-lib.php';

            $products = sms_fetch_label_products($pdo, [
                'search' => (string) ($_GET['search'] ?? $_POST['search'] ?? ''),
                'category_id' => (int) ($_GET['category_id'] ?? $_POST['category_id'] ?? 0),
                'placed' => (string) ($_GET['placed'] ?? $_POST['placed'] ?? 'all'),
            ]);

            sms_json([
                'success' => true,
                'products' => $products,
                'placedCount' => sms_count_label_placed($pdo),
            ]);
            break;

        default:
            sms_error('Unknown action', 404);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sms_error('Server error: ' . $e->getMessage(), 500);
}
