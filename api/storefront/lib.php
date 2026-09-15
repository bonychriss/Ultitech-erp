<?php

declare(strict_types=1);

/**
 * Roadmaster storefront sync helpers.
 */

function storefrontApiJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function storefrontApiExtractBearer(): string
{
    $auth = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim((string) $_SERVER['HTTP_X_API_KEY']);
    }
    if (isset($_GET['api_key'])) {
        return trim((string) $_GET['api_key']);
    }
    return '';
}

function storefrontApiExpectedToken(): string
{
    if (defined('STOREFRONT_SYNC_TOKEN') && (string) STOREFRONT_SYNC_TOKEN !== '') {
        return (string) STOREFRONT_SYNC_TOKEN;
    }
    $env = getenv('STOREFRONT_SYNC_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    global $pdo;
    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'storefront_sync_token' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    // Dev default — override in env.local.php / system_settings for production
    return 'roadmaster-storefront-dev-token-change-me';
}

function storefrontApiRequireBearer(): void
{
    $expected = storefrontApiExpectedToken();
    $provided = storefrontApiExtractBearer();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        storefrontApiJson([
            'success' => false,
            'error' => 'Unauthorized. Provide Authorization: Bearer <STOREFRONT_SYNC_TOKEN>.',
        ], 401);
    }
}

function storefrontApiPdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection unavailable.');
    }
    return $pdo;
}

/**
 * @return list<array<string,mixed>>
 */
function storefrontApiFetchProducts(PDO $pdo, ?int $id = null): array
{
    $prodCols = [];
    try {
        $prodCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $prodCols = [];
    }

    $imgSelect = 'NULL AS main_image';
    if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
        $imgSelect = 'COALESCE(p.main_image, p.image) AS main_image';
    } elseif (in_array('main_image', $prodCols, true)) {
        $imgSelect = 'p.main_image AS main_image';
    } elseif (in_array('image', $prodCols, true)) {
        $imgSelect = 'p.image AS main_image';
    }

    $itemTypeSelect = in_array('item_type', $prodCols, true) ? 'p.item_type' : "'' AS item_type";
    $brandSelect = in_array('brand', $prodCols, true) ? 'p.brand' : "'' AS brand";
    $activeWhere = '';
    if (in_array('is_active', $prodCols, true)) {
        $activeWhere = ' AND COALESCE(p.is_active, 1) = 1';
    } elseif (in_array('status', $prodCols, true)) {
        $activeWhere = " AND LOWER(TRIM(COALESCE(p.status, 'active'))) IN ('active', '1', '')";
    }

    $hasCat = function_exists('columnExists')
        && columnExists('products', 'category_id', $pdo)
        && function_exists('tableExists')
        && tableExists('categories', $pdo);

    $sql = "
        SELECT p.id, p.product_code, p.name, p.description, p.unit_price AS selling_price,
               $imgSelect, $itemTypeSelect, $brandSelect,
               COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) AS stock_quantity";
    $sql .= $hasCat ? ", COALESCE(MAX(c.name), '') AS category_name" : ", '' AS category_name";
    $sql .= ' FROM products p';
    if ($hasCat) {
        $sql .= ' LEFT JOIN categories c ON c.id = p.category_id';
    }
    $sql .= ' WHERE 1=1' . $activeWhere;
    $params = [];
    if ($id !== null && $id > 0) {
        $sql .= ' AND p.id = ?';
        $params[] = $id;
    }
    $sql .= ' GROUP BY p.id ORDER BY p.name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $price = (float) ($row['selling_price'] ?? 0);
        $image = '';
        if (function_exists('sales_product_image_url')) {
            $image = (string) sales_product_image_url(
                (int) ($row['id'] ?? 0),
                (string) ($row['main_image'] ?? ''),
                'medium'
            );
        } elseif (!empty($row['main_image']) && function_exists('app_url')) {
            $image = app_url('/uploads/' . ltrim((string) $row['main_image'], '/'));
        }
        $image = storefrontApiPublicImageUrl($image);
        $itemType = strtolower(trim((string) ($row['item_type'] ?? '')));
        $kind = in_array($itemType, ['vehicle', 'truck'], true) ? 'truck' : 'spare';
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'sku' => (string) ($row['product_code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'price' => $price,
            'stock_qty' => (float) ($row['stock_quantity'] ?? 0),
            'category' => (string) ($row['category_name'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'kind' => $kind,
            'image_url' => $image,
            'updated_at' => date('c'),
        ];
    }
    return $out;
}

/**
 * Storefront clients load images from UltiTech — always return an absolute public URL.
 */
function storefrontApiPublicImageUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $publicBase = rtrim((string) (getenv('STOREFRONT_PUBLIC_ASSET_BASE') ?: 'https://ultitech.io'), '/');
    if (defined('STOREFRONT_PUBLIC_ASSET_BASE') && (string) STOREFRONT_PUBLIC_ASSET_BASE !== '') {
        $publicBase = rtrim((string) STOREFRONT_PUBLIC_ASSET_BASE, '/');
    }

    // Already absolute
    if (preg_match('#^https?://#i', $url)) {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        // Drop local XAMPP base path (/public_html) from absolute URLs too
        if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '' && str_starts_with($path, (string) APP_BASE_PATH)) {
            $path = substr($path, strlen((string) APP_BASE_PATH)) ?: '/';
        }
        $path = preg_replace('#^/public_html(?=/|$)#', '', $path) ?: $path;
        return $publicBase . $path . $query;
    }

    $path = $url;
    if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '' && str_starts_with($path, (string) APP_BASE_PATH)) {
        $path = substr($path, strlen((string) APP_BASE_PATH)) ?: '/';
    }
    $path = preg_replace('#^/public_html(?=/|$)#', '', $path) ?: $path;
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    return $publicBase . $path;
}

/**
 * @param array<string,mixed> $input
 * @return array{success:bool,order_id?:int,order_number?:string,message:string,error?:string}
 */
function storefrontApiCreateOrder(PDO $pdo, array $input): array
{
    $idempotency = trim((string) ($input['idempotency_key'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    $customerName = trim((string) ($input['customer_name'] ?? 'Web customer'));
    $customerEmail = trim((string) ($input['customer_email'] ?? ''));
    $customerPhone = trim((string) ($input['customer_phone'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? 'Storefront order from roadmasterspares.com'));
    $items = $input['items'] ?? [];
    if (!is_array($items) || $items === []) {
        return ['success' => false, 'message' => 'No line items.', 'error' => 'items_required'];
    }

    if ($idempotency !== '') {
        try {
            if (function_exists('tableExists') && tableExists('sales_orders', $pdo)) {
                $cols = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                if (in_array('notes', $cols, true)) {
                    $chk = $pdo->prepare("SELECT id, order_number FROM sales_orders WHERE notes LIKE ? ORDER BY id DESC LIMIT 1");
                    $chk->execute(['%idem:' . $idempotency . '%']);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        return [
                            'success' => true,
                            'order_id' => (int) $existing['id'],
                            'order_number' => (string) ($existing['order_number'] ?? ''),
                            'message' => 'Order already created (idempotent).',
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    $lineRows = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        $qty = (float) ($item['quantity'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $stmt = $pdo->prepare('
            SELECT p.id, p.name, p.unit_price,
                   COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) AS stock_qty
            FROM products p WHERE p.id = ? LIMIT 1
        ');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            return ['success' => false, 'message' => "Product #$productId not found.", 'error' => 'product_missing'];
        }
        $stockQty = (float) ($product['stock_qty'] ?? 0);
        if ($stockQty < $qty) {
            return [
                'success' => false,
                'message' => 'Insufficient stock for ' . (string) $product['name'] . " (available $stockQty).",
                'error' => 'insufficient_stock',
            ];
        }
        $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) $product['unit_price'];
        $lineTotal = round($qty * $unitPrice, 2);
        $subtotal += $lineTotal;
        $lineRows[] = [
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'description' => (string) ($product['name'] ?? ''),
        ];
    }
    if ($lineRows === []) {
        return ['success' => false, 'message' => 'No valid line items.', 'error' => 'items_invalid'];
    }

    $orderCols = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    $orderNumber = 'SF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $noteText = $notes;
    if ($idempotency !== '') {
        $noteText .= ' | idem:' . $idempotency;
    }
    if ($customerEmail !== '') {
        $noteText .= ' | email:' . $customerEmail;
    }
    if ($customerPhone !== '') {
        $noteText .= ' | phone:' . $customerPhone;
    }

    $fields = [];
    $values = [];
    $map = [
        'order_number' => $orderNumber,
        'customer_name' => $customerName,
        'status' => 'confirmed',
        'total_amount' => $subtotal,
        'subtotal' => $subtotal,
        'notes' => $noteText,
        'order_date' => date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    if (in_array('order_type', $orderCols, true)) {
        $map['order_type'] = 'spare';
    }
    if (in_array('company_id', $orderCols, true) && function_exists('currentCompanyId')) {
        $map['company_id'] = (int) currentCompanyId();
    }
    if (in_array('source', $orderCols, true)) {
        $map['source'] = 'storefront';
    }
    if (in_array('created_by', $orderCols, true)) {
        $map['created_by'] = 0;
    }

    foreach ($map as $col => $val) {
        if (in_array($col, $orderCols, true)) {
            $fields[] = $col;
            $values[] = $val;
        }
    }
    if ($fields === []) {
        return ['success' => false, 'message' => 'sales_orders table missing expected columns.', 'error' => 'schema'];
    }

    $pdo->beginTransaction();
    try {
        $sql = 'INSERT INTO sales_orders (' . implode(', ', $fields) . ') VALUES ('
            . implode(', ', array_fill(0, count($fields), '?')) . ')';
        $pdo->prepare($sql)->execute($values);
        $orderId = (int) $pdo->lastInsertId();

        $itemCols = $pdo->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        foreach ($lineRows as $line) {
            $iFields = ['order_id', 'product_id', 'quantity', 'unit_price'];
            $iValues = [$orderId, $line['product_id'], $line['quantity'], $line['unit_price']];
            if (in_array('line_total', $itemCols, true)) {
                $iFields[] = 'line_total';
                $iValues[] = $line['line_total'];
            }
            if (in_array('discount_percentage', $itemCols, true)) {
                $iFields[] = 'discount_percentage';
                $iValues[] = 0;
            }
            if (in_array('description', $itemCols, true)) {
                $iFields[] = 'description';
                $iValues[] = $line['description'];
            }
            if (in_array('company_id', $itemCols, true) && function_exists('currentCompanyId')) {
                array_splice($iFields, 1, 0, ['company_id']);
                array_splice($iValues, 1, 0, [(int) currentCompanyId()]);
            }
            $iSql = 'INSERT INTO sales_order_items (' . implode(', ', $iFields) . ') VALUES ('
                . implode(', ', array_fill(0, count($iFields), '?')) . ')';
            $pdo->prepare($iSql)->execute($iValues);
        }

        $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
        if (is_file($salesFunctions)) {
            require_once $salesFunctions;
        }
        if (function_exists('deductStockForOrder')) {
            deductStockForOrder($orderId);
        } else {
            foreach ($lineRows as $line) {
                $upd = $pdo->prepare('UPDATE stock SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?');
                $upd->execute([$line['quantity'], $line['product_id'], $line['quantity']]);
                if ($upd->rowCount() === 0) {
                    throw new RuntimeException('Stock update failed for product ' . $line['product_id']);
                }
            }
        }

        $pdo->commit();
        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'message' => 'Order created and stock deducted.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage(), 'error' => 'order_failed'];
    }
}
