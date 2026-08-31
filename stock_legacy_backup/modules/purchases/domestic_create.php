<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();

// Ensure stocks_purchase_orders schema supports domestic/import split.
try {
    $cols = $pdo->query("SHOW COLUMNS FROM stocks_purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_type', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN purchase_type ENUM('domestic','import') NOT NULL DEFAULT 'domestic' AFTER supplier_id");
    }
    if (!in_array('supplier_invoice_no', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN supplier_invoice_no VARCHAR(50) NULL AFTER purchase_type");
    }
    if (!in_array('currency', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'USD' AFTER supplier_invoice_no");
    }
    if (!in_array('exchange_rate', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER currency");
    }
    if (!in_array('payment_voucher_ids', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN payment_voucher_ids TEXT NULL AFTER payment_voucher_id");
    }
} catch (Exception $e) {
    // ignore schema enforcement errors
}

ensurePurchaseWorkflowSchema($pdo);
ensureVoucherStockPurchaseSchema();

function tableExists(PDO $pdo, string $table): bool {
    try {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array {
    try {
        return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function pickExistingColumn(array $available, array $preferred): ?string {
    foreach ($preferred as $column) {
        if (in_array($column, $available, true)) {
            return $column;
        }
    }
    return null;
}

function fetchSuppliersFromTable(PDO $pdo, string $table): array {
    if (!tableExists($pdo, $table)) {
        return [];
    }

    $columns = getTableColumns($pdo, $table);
    if (!in_array('name', $columns, true)) {
        return [];
    }

    $contactColumn = pickExistingColumn($columns, ['contact_person', 'contact_name', 'contact_details']);
    $phoneColumn = pickExistingColumn($columns, ['phone', 'phone_number', 'mobile']);
    $emailColumn = pickExistingColumn($columns, ['email', 'email_address']);
    $addressColumn = pickExistingColumn($columns, ['address', 'location']);

    $selects = [
        "id",
        "name",
        $contactColumn ? "`$contactColumn` AS contact_person" : "NULL AS contact_person",
        $phoneColumn ? "`$phoneColumn` AS phone" : "NULL AS phone",
        $emailColumn ? "`$emailColumn` AS email" : "NULL AS email",
        $addressColumn ? "`$addressColumn` AS address" : "NULL AS address",
        "'" . $table . "' AS source_table"
    ];

    try {
        return $pdo->query("SELECT " . implode(', ', $selects) . " FROM `$table` ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function normalizeSupplierKey(array $row): string {
    $name = strtolower(trim((string)($row['name'] ?? '')));
    $email = strtolower(trim((string)($row['email'] ?? '')));
    $phone = strtolower(trim((string)($row['phone'] ?? '')));
    $key = $name . '|' . $email . '|' . $phone;
    return $key === '||' ? '' : $key;
}

function syncLegacySuppliersToStocksRegistry(PDO $pdo): void {
    if (!tableExists($pdo, 'stocks_suppliers') || !tableExists($pdo, 'suppliers')) {
        return;
    }

    $stockRows = fetchSuppliersFromTable($pdo, 'stocks_suppliers');
    $legacyRows = fetchSuppliersFromTable($pdo, 'suppliers');
    if (empty($legacyRows)) {
        return;
    }

    $existing = [];
    foreach ($stockRows as $row) {
        $key = normalizeSupplierKey($row);
        if ($key !== '') {
            $existing[$key] = true;
        }
        $nameOnly = strtolower(trim((string)($row['name'] ?? '')));
        if ($nameOnly !== '') {
            $existing['name:' . $nameOnly] = true;
        }
    }

    $stockColumns = getTableColumns($pdo, 'stocks_suppliers');
    $insertCols = ['name'];
    foreach (['contact_person', 'phone', 'email', 'address'] as $col) {
        if (in_array($col, $stockColumns, true)) {
            $insertCols[] = $col;
        }
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $insertSql = "INSERT INTO `stocks_suppliers` (" . implode(', ', $insertCols) . ") VALUES ($placeholders)";
    $stmt = $pdo->prepare($insertSql);

    foreach ($legacyRows as $row) {
        $key = normalizeSupplierKey($row);
        $nameOnly = strtolower(trim((string)($row['name'] ?? '')));
        if (($key && isset($existing[$key])) || ($nameOnly && isset($existing['name:' . $nameOnly]))) {
            continue;
        }

        $values = [];
        foreach ($insertCols as $col) {
            $values[] = $row[$col] ?? null;
        }
        try {
            $stmt->execute($values);
            if ($key) {
                $existing[$key] = true;
            }
            if ($nameOnly) {
                $existing['name:' . $nameOnly] = true;
            }
        } catch (Exception $e) {
            continue;
        }
    }
}

function syncSupplierPayeesToStocksRegistry(PDO $pdo): void {
    if (!tableExists($pdo, 'stocks_suppliers') || !tableExists($pdo, 'payees')) {
        return;
    }

    $payeeCols = getTableColumns($pdo, 'payees');
    if (!in_array('name', $payeeCols, true) || !in_array('type', $payeeCols, true)) {
        return;
    }

    $stockRows = fetchSuppliersFromTable($pdo, 'stocks_suppliers');
    $existingNames = [];
    foreach ($stockRows as $row) {
        $nameOnly = strtolower(trim((string)($row['name'] ?? '')));
        if ($nameOnly !== '') {
            $existingNames[$nameOnly] = true;
        }
    }

    $stockColumns = getTableColumns($pdo, 'stocks_suppliers');
    $insertCols = ['name'];
    if (in_array('contact_details', $payeeCols, true)) {
        if (in_array('contact_person', $stockColumns, true)) $insertCols[] = 'contact_person';
        if (in_array('phone', $stockColumns, true)) $insertCols[] = 'phone';
        if (in_array('email', $stockColumns, true)) $insertCols[] = 'email';
        if (in_array('address', $stockColumns, true)) $insertCols[] = 'address';
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $insertSql = "INSERT INTO `stocks_suppliers` (" . implode(', ', $insertCols) . ") VALUES ($placeholders)";
    $stmtInsert = $pdo->prepare($insertSql);

    $stmtPayees = $pdo->query("SELECT name, type, " . (in_array('contact_details', $payeeCols, true) ? "contact_details" : "NULL AS contact_details") . " FROM payees WHERE LOWER(TRIM(type)) = 'supplier' ORDER BY name ASC");
    $payeeRows = $stmtPayees ? ($stmtPayees->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($payeeRows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        $nameKey = strtolower($name);
        if (isset($existingNames[$nameKey])) continue;

        $contact = trim((string)($row['contact_details'] ?? ''));
        $values = [];
        foreach ($insertCols as $col) {
            if ($col === 'name') $values[] = $name;
            else $values[] = ($contact !== '' ? $contact : null);
        }
        try {
            $stmtInsert->execute($values);
            $existingNames[$nameKey] = true;
        } catch (Exception $e) {
            continue;
        }
    }
}

function syncProductsToStocksRegistry(PDO $pdo): void {
    if (!tableExists($pdo, 'stocks_items') || !tableExists($pdo, 'products')) {
        return;
    }

    // Fetch existing in stocks_items
    $existingItems = $pdo->query("SELECT LOWER(TRIM(name)) as name, LOWER(TRIM(sku)) as sku FROM stocks_items")->fetchAll(PDO::FETCH_ASSOC);
    $existingNames = [];
    $existingSkus = [];
    foreach ($existingItems as $it) {
        if ($it['name']) $existingNames[$it['name']] = true;
        if ($it['sku']) $existingSkus[$it['sku']] = true;
    }

    // Fetch all products
    $products = $pdo->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($products)) return;

    $insertStmt = $pdo->prepare("INSERT INTO stocks_items (name, sku, description, category_id, created_at) VALUES (?, ?, ?, ?, NOW())");

    foreach ($products as $p) {
        $name = strtolower(trim((string)$p['name']));
        $sku = strtolower(trim((string)$p['product_code']));
        
        // Skip if already exists by name or SKU
        if (isset($existingNames[$name]) || ($sku && isset($existingSkus[$sku]))) {
            continue;
        }

        try {
            $insertStmt->execute([
                $p['name'],
                $p['product_code'] ?: null,
                $p['description'] ?? null,
                $p['category_id'] ?? null
            ]);
            // Add to tracked to avoid duplicates in same run
            $existingNames[$name] = true;
            if ($sku) $existingSkus[$sku] = true;
        } catch (Exception $e) {
            continue;
        }
    }
}

function loadAllSystemSuppliers(PDO $pdo): array {
    syncLegacySuppliersToStocksRegistry($pdo);
    syncSupplierPayeesToStocksRegistry($pdo);
    syncProductsToStocksRegistry($pdo); // Also ensure products are in stock registry

    if (tableExists($pdo, 'stocks_suppliers')) {
        return fetchSuppliersFromTable($pdo, 'stocks_suppliers');
    }

    return fetchSuppliersFromTable($pdo, 'suppliers');
}

if (isset($_GET['action']) && $_GET['action'] === 'get_suppliers_catalogue') {
    header('Content-Type: application/json');

    try {
        echo json_encode([
            'success' => true,
            'suppliers' => loadAllSystemSuppliers($pdo)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load suppliers'
        ]);
    }
    exit;
}

// Fetch Suppliers and Products for Dropdowns
$suppliers = [];
$suppliersAvailable = false;
$supplierSetupError = '';

try {
    $suppliers = loadAllSystemSuppliers($pdo);
    if (!empty($suppliers)) {
        $suppliersAvailable = true;
    } else {
        $hasStocksSuppliersTable = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_suppliers'")->fetchColumn();
        $hasSuppliersTable = (bool) $pdo->query("SHOW TABLES LIKE 'suppliers'")->fetchColumn();

        if ($hasStocksSuppliersTable || $hasSuppliersTable) {
            $supplierSetupError = 'No suppliers found yet. Add at least one supplier before creating a purchase order.';
        } else {
            $supplierSetupError = 'Suppliers table is missing in this database. Set up suppliers first before creating purchase orders.';
        }
    }
} catch (Exception $e) {
    $supplierSetupError = 'Unable to load suppliers right now.';
}

// Fetch approved stock-purchase payment vouchers (for linking to PO)
$stockPurchaseVouchers = [];
try {
    $pvCols = $pdo->query("SHOW COLUMNS FROM payment_vouchers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasLinkedCol = in_array('linked_stock_po_id', $pvCols, true);
    $linkedSalesOrderIdSelect = in_array('linked_sales_order_id', $pvCols, true)
        ? "pv.linked_sales_order_id AS linked_sales_order_id"
        : "NULL AS linked_sales_order_id";
    $linkedSalesOrderIdsSelect = in_array('linked_sales_order_ids', $pvCols, true)
        ? "pv.linked_sales_order_ids AS linked_sales_order_ids"
        : "NULL AS linked_sales_order_ids";

    $where = "LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'";
    if ($hasLinkedCol) {
        $where .= " AND (pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0)";
    }

    $stockPurchaseVouchers = $pdo->query("
        SELECT
            pv.id,
            pv.voucher_no,
            pv.payee_name,
            COALESCE(py.type, '') AS payee_type,
            pv.currency,
            pv.total_amount,
            pv.status,
            pv.is_paid,
            COALESCE(pv.purpose, 'general') AS purpose,
            COALESCE(pv.linked_stock_po_id, 0) AS linked_stock_po_id,
            $linkedSalesOrderIdSelect,
            $linkedSalesOrderIdsSelect,
            ss.id AS supplier_id
        FROM payment_vouchers pv
        LEFT JOIN payees py ON LOWER(TRIM(py.name)) = LOWER(TRIM(pv.payee_name))
        LEFT JOIN stocks_suppliers ss ON LOWER(TRIM(ss.name)) = LOWER(TRIM(pv.payee_name))
        WHERE $where
        ORDER BY pv.date_created DESC, pv.id DESC
        LIMIT 250
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $stockPurchaseVouchers = [];
}

$productCols = [];
try {
    $productCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $productCols = [];
}

$hasImageCol = in_array('image', $productCols, true);
$hasMainImageCol = in_array('main_image', $productCols, true);
$productImageCol = $hasImageCol ? 'image' : ($hasMainImageCol ? 'main_image' : null);
$productBuyingPriceCol = in_array('buying_price', $productCols, true) ? 'buying_price' : (in_array('cost_price', $productCols, true) ? 'cost_price' : 'unit_price');
$productSupplierCol = in_array('supplier_id', $productCols, true) ? 'supplier_id' : null;

if ($hasImageCol && $hasMainImageCol) {
    // Prefer products.image, fallback to products.main_image; treat '' as NULL
    $productImageSelect = "COALESCE(NULLIF(p.image,''), NULLIF(p.main_image,'')) AS main_image";
} elseif ($productImageCol) {
    $productImageSelect = "p.`$productImageCol` AS main_image";
} else {
    $productImageSelect = "NULL AS main_image";
}
$productBuyingPriceSelect = "`$productBuyingPriceCol` AS buying_price";
$productSupplierSelect = $productSupplierCol ? "`$productSupplierCol` AS supplier_id" : "NULL AS supplier_id";

/**
 * Replenishment opens PO pages with products.id.
 * But PO items are based on stocks_items, so we must ensure a corresponding stocks_items row exists.
 * We create it opportunistically if missing (name + sku=product_code), filling any required columns
 * with safe defaults based on column types.
 */
function ensureStocksItemForProduct(PDO $pdo, int $productId): void
{
    if ($productId <= 0) return;

    try {
        $productStmt = $pdo->prepare("SELECT id, name, product_code FROM products WHERE id = ? LIMIT 1");
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return;

        $pName = trim((string)($product['name'] ?? ''));
        $pCode = trim((string)($product['product_code'] ?? ''));
        if ($pName === '' && $pCode === '') return;

        // If a matching stocks_items exists by name or sku, we're done.
        $existsStmt = $pdo->prepare("
            SELECT id FROM stocks_items
            WHERE (LOWER(TRIM(name)) = LOWER(TRIM(?)) AND ? <> '')
               OR (LOWER(TRIM(sku)) = LOWER(TRIM(?)) AND ? <> '')
            LIMIT 1
        ");
        $existsStmt->execute([$pName, $pName, $pCode, $pCode]);
        if ($existsStmt->fetchColumn()) return;

        // Inspect schema to build a compatible insert.
        $cols = $pdo->query("SHOW COLUMNS FROM stocks_items")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($cols)) return;

        $insert = [];
        foreach ($cols as $c) {
            $field = (string)($c['Field'] ?? '');
            $null = (string)($c['Null'] ?? '');
            $default = $c['Default'] ?? null;
            $extra = (string)($c['Extra'] ?? '');
            $type = (string)($c['Type'] ?? '');

            if ($field === '' || stripos($extra, 'auto_increment') !== false) continue;

            // Always set these if present.
            if ($field === 'name') {
                $insert[$field] = $pName;
                continue;
            }
            if ($field === 'sku') {
                $insert[$field] = $pCode !== '' ? $pCode : null;
                continue;
            }

            // For required fields without defaults, provide a safe value by type.
            if ($null === 'NO' && $default === null) {
                $lt = strtolower($type);
                if (preg_match('/^(int|tinyint|smallint|mediumint|bigint|decimal|float|double)/', $lt)) {
                    $insert[$field] = 0;
                } elseif (str_contains($lt, 'datetime') || str_contains($lt, 'timestamp')) {
                    $insert[$field] = date('Y-m-d H:i:s');
                } elseif (str_contains($lt, 'date')) {
                    $insert[$field] = date('Y-m-d');
                } else {
                    // varchar/text/enum/etc
                    $insert[$field] = '';
                }
            }
        }

        // Must have at least name to insert.
        if (!array_key_exists('name', $insert) || trim((string)$insert['name']) === '') return;

        $fields = array_keys($insert);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = "INSERT INTO stocks_items (`" . implode('`, `', $fields) . "`) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($insert));
    } catch (Throwable $e) {
        // Fail silently; prefill will simply not find a match.
        return;
    }
}

// If this PO page was opened from Replenishment, ensure a stocks_items row exists.
if (isset($_GET['product_id'])) {
    ensureStocksItemForProduct($pdo, (int)$_GET['product_id']);
}

// PO item foreign keys point to stocks_items.id, so the picker must be based on stocks_items.
// We still enrich each stock item with any matching product data (price, image, supplier)
// so replenishment-driven flows can stay familiar to users.
$products = $pdo->query("
    SELECT
        si.id,
        si.name,
        COALESCE(
            (
                SELECT p.product_code
                FROM products p
                WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
                LIMIT 1
            ),
            si.sku
        ) AS product_code,
        COALESCE(
            (
                SELECT p.id
                FROM products p
                WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
                LIMIT 1
            ),
            NULL
        ) AS linked_product_id,
        COALESCE(
            (
                SELECT p.id
                FROM products p
                WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
                LIMIT 1
            ),
            NULL
        ) AS image_product_id,
        COALESCE(
            (
                SELECT $productBuyingPriceSelect
                FROM products p
                WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
                LIMIT 1
            ),
            0
        ) AS buying_price,
        COALESCE(
            (
                SELECT $productImageSelect
                FROM products p
                WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
                LIMIT 1
            ),
            NULL
        ) AS main_image,
        COALESCE(
            (
                SELECT $productSupplierSelect
                FROM products p
                WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                   OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
                LIMIT 1
            ),
            NULL
        ) AS supplier_id
    FROM stocks_items si
    ORDER BY si.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Build map: voucher_id => purchase rows prefilled from linked sales orders.
$voucherSalesOrderItemsMap = [];
try {
    $productByLinkedSalesId = [];
    foreach ($products as $prodRow) {
        $linkedSalesId = isset($prodRow['linked_product_id']) ? (int) $prodRow['linked_product_id'] : 0;
        if ($linkedSalesId > 0 && !isset($productByLinkedSalesId[$linkedSalesId])) {
            $productByLinkedSalesId[$linkedSalesId] = $prodRow;
        }
    }

    foreach ($stockPurchaseVouchers as $pvRow) {
        $voucherId = (int) ($pvRow['id'] ?? 0);
        if ($voucherId <= 0) {
            continue;
        }

        $linkedOrderIds = [];
        $idsJson = trim((string) ($pvRow['linked_sales_order_ids'] ?? ''));
        if ($idsJson !== '') {
            $decoded = json_decode($idsJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $idVal) {
                    $oid = (int) $idVal;
                    if ($oid > 0) {
                        $linkedOrderIds[$oid] = $oid;
                    }
                }
            } else {
                foreach (preg_split('/\s*,\s*/', $idsJson) as $idVal) {
                    $oid = (int) $idVal;
                    if ($oid > 0) {
                        $linkedOrderIds[$oid] = $oid;
                    }
                }
            }
        }
        $singleLinkedOrderId = (int) ($pvRow['linked_sales_order_id'] ?? 0);
        if ($singleLinkedOrderId > 0) {
            $linkedOrderIds[$singleLinkedOrderId] = $singleLinkedOrderId;
        }
        $linkedOrderIds = array_values($linkedOrderIds);
        if (empty($linkedOrderIds)) {
            continue;
        }

        $ph = implode(',', array_fill(0, count($linkedOrderIds), '?'));
        $stmtSoItems = $pdo->prepare("
            SELECT
                soi.product_id AS sales_product_id,
                SUM(COALESCE(soi.quantity, 0)) AS qty_total,
                MAX(COALESCE(soi.unit_price, 0)) AS unit_price
            FROM sales_order_items soi
            WHERE soi.order_id IN ($ph)
            GROUP BY soi.product_id
        ");
        $stmtSoItems->execute($linkedOrderIds);
        $rows = $stmtSoItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            continue;
        }

        $mapped = [];
        foreach ($rows as $r) {
            $salesProductId = (int) ($r['sales_product_id'] ?? 0);
            if ($salesProductId <= 0 || !isset($productByLinkedSalesId[$salesProductId])) {
                continue;
            }
            $matchedProduct = $productByLinkedSalesId[$salesProductId];
            $qty = (float) ($r['qty_total'] ?? 0);
            if ($qty <= 0) {
                $qty = 1;
            }
            $unit = (float) ($matchedProduct['buying_price'] ?? 0);
            if ($unit <= 0) {
                $unit = (float) ($r['unit_price'] ?? 0);
            }
            $mapped[] = [
                'product_id' => (int) ($matchedProduct['id'] ?? 0),
                'quantity' => $qty,
                'unit_price' => $unit
            ];
        }
        if (!empty($mapped)) {
            $voucherSalesOrderItemsMap[$voucherId] = $mapped;
        }
    }
} catch (Throwable $e) {
    $voucherSalesOrderItemsMap = [];
}

// Catalogue shortcut (use Sales catalogue)
$returnUrl = $_SERVER['REQUEST_URI'] ?? '/stock/modules/purchases/create.php';
$catalogueUrl = '/modules/sales/catalogue.php?doc=purchase&return=' . urlencode($returnUrl);

// Fetch Settings Global
$settings = [];
try {
    $settings = getCompanySettings($pdo);
} catch (Exception $e) {
    $settings = [
        'exchange_rate' => 1,
        'currency' => 'USD'
    ];
}
$rate = $settings['exchange_rate'] ?? 1;
$currency = getCurrencySymbol($settings['currency'] ?? 'USD');

$companyCurrency = strtoupper((string) ($settings['currency'] ?? 'USD'));
$selectedCurrencyCode = strtoupper((string) ($_POST['currency'] ?? $_GET['currency'] ?? $companyCurrency));
$allowedCurrencies = ['USD', 'TZS', 'EUR', 'GBP', 'KES'];
if (!in_array($selectedCurrencyCode, $allowedCurrencies, true)) {
    $selectedCurrencyCode = $companyCurrency;
}
$selectedRate = 0.0;
$rate = $selectedRate;
$currency = getCurrencySymbol($selectedCurrencyCode);

// Clone Logic
$cloned_items = [];
$cloned_po = null;
if (isset($_GET['clone_from_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM stocks_purchase_orders WHERE id = ?");
    $stmt->execute([$_GET['clone_from_id']]);
    $cloned_po = $stmt->fetch();
    
    if ($cloned_po) {
        $stmtItems = $pdo->prepare("SELECT item_id AS product_id, qty_ordered AS quantity, unit_cost AS unit_price FROM stocks_po_items WHERE po_id = ?");
        $stmtItems->execute([$_GET['clone_from_id']]);
        $cloned_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $supplier_id = $_POST['supplier_id'] ?? '';
    $payment_voucher_id = isset($_POST['payment_voucher_id']) ? (int) $_POST['payment_voucher_id'] : 0;
    $payment_voucher_ids_raw = trim((string) ($_POST['payment_voucher_ids'] ?? ''));
    $payment_voucher_ids = [];
    if ($payment_voucher_ids_raw !== '') {
        foreach (preg_split('/\s*,\s*/', $payment_voucher_ids_raw) as $pvIdToken) {
            $pvInt = (int) $pvIdToken;
            if ($pvInt > 0) {
                $payment_voucher_ids[$pvInt] = $pvInt;
            }
        }
    }
    if (empty($payment_voucher_ids) && $payment_voucher_id > 0) {
        $payment_voucher_ids[$payment_voucher_id] = $payment_voucher_id;
    }
    $payment_voucher_ids = array_values($payment_voucher_ids);
    $payment_voucher_id = !empty($payment_voucher_ids) ? (int) $payment_voucher_ids[0] : 0;
    $linkedVoucher = null;
    $purchase_type = $_POST['purchase_type'] ?? 'domestic';
    if (!in_array($purchase_type, ['domestic', 'import'], true)) {
        $purchase_type = 'domestic';
    }
    $procurement_journey = $_POST['procurement_journey'] ?? PURCHASE_PROC_STANDARD;
    if (!in_array($procurement_journey, [PURCHASE_PROC_STANDARD, PURCHASE_PROC_SUPPLIER_LINK], true)) {
        $procurement_journey = PURCHASE_PROC_STANDARD;
    }
    $supplier_link_draft = ($procurement_journey === PURCHASE_PROC_SUPPLIER_LINK);
    $supplier_invoice_no = clean_input($_POST['supplier_invoice_no'] ?? '');
    $selectedCurrencyCode = strtoupper((string) ($_POST['currency'] ?? $selectedCurrencyCode));
    if (!in_array($selectedCurrencyCode, $allowedCurrencies, true)) {
        $selectedCurrencyCode = $companyCurrency;
    }
    $manualRate = isset($_POST['exchange_rate']) ? (float) $_POST['exchange_rate'] : 0.0;
    $selectedRate = ($manualRate >= 0) ? $manualRate : 0.0;
    $rate = $selectedRate;
    $currency = getCurrencySymbol($selectedCurrencyCode);
    $notes = clean_input($_POST['notes']);
    $terms_conditions = clean_input($_POST['terms_conditions']);
    $tax_percentage = isset($_POST['tax_percentage']) ? floatval($_POST['tax_percentage']) : 0;
    
    // Items Arrays

    // Items Arrays
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? []; // These are in DISPLAY currency

    // Resolve supplier from a free-form payee label when picker set a temporary value.
    $resolveSupplierIdFromName = function ($name) use ($pdo) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        try {
            $find = $pdo->prepare("SELECT id FROM stocks_suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            $find->execute([$name]);
            $existingId = (int) $find->fetchColumn();
            if ($existingId > 0) {
                return (string) $existingId;
            }

            $ins = $pdo->prepare("INSERT INTO stocks_suppliers (name, created_at) VALUES (?, NOW())");
            $ins->execute([$name]);
            $newId = (int) $pdo->lastInsertId();
            return $newId > 0 ? (string) $newId : '';
        } catch (Throwable $e) {
            return '';
        }
    };

    if (is_string($supplier_id) && strpos($supplier_id, '__pv_payee__') === 0) {
        $payeeNameFromSupplier = substr($supplier_id, strlen('__pv_payee__'));
        $resolvedSupplierId = $resolveSupplierIdFromName($payeeNameFromSupplier);
        if ($resolvedSupplierId !== '') {
            $supplier_id = $resolvedSupplierId;
        }
    }
    
    // Generate PO Number
    $count = $pdo->query("SELECT count(*) FROM stocks_purchase_orders")->fetchColumn() + 1;
    $po_number = sprintf("PUR-%s-%03d", date('Ymd'), $count);
    
    // Filter out empty product selections
    $valid_product_ids = [];
    if (is_array($product_ids)) {
        foreach ($product_ids as $pid) {
            if ($pid !== '' && $pid !== null) {
                $valid_product_ids[] = $pid;
            }
        }
    }
    if (!$suppliersAvailable || empty($suppliers)) {
        $error = $supplierSetupError ?: 'Suppliers are not available. Please set up suppliers first.';
    } elseif (!empty($payment_voucher_ids)) {
        try {
            $stmtVoucher = $pdo->prepare("
                SELECT
                    pv.id,
                    pv.voucher_no,
                    pv.status,
                    pv.payee_name,
                    COALESCE(pv.purpose, 'general') AS purpose,
                    COALESCE(pv.linked_stock_po_id, 0) AS linked_stock_po_id,
                    ss.id AS supplier_id
                FROM payment_vouchers pv
                LEFT JOIN stocks_suppliers ss ON LOWER(TRIM(ss.name)) = LOWER(TRIM(pv.payee_name))
                WHERE pv.id = ?
                LIMIT 1
            ");
            $linkedVouchers = [];
            foreach ($payment_voucher_ids as $pvCheckId) {
                $stmtVoucher->execute([$pvCheckId]);
                $vrow = $stmtVoucher->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$vrow) {
                    $error = 'Selected payment voucher was not found.';
                    break;
                }
                if (strtolower((string) ($vrow['status'] ?? '')) !== 'approved') {
                    $error = 'Only approved payment vouchers can be linked to a purchase order.';
                    break;
                }
                if ((int) ($vrow['linked_stock_po_id'] ?? 0) > 0) {
                    $error = 'One of selected vouchers is already linked to another purchase order.';
                    break;
                }
                $linkedVouchers[] = $vrow;
            }
            if (empty($error)) {
                $linkedVoucher = $linkedVouchers[0] ?? null;
            }
        } catch (Throwable $e) {
            $linkedVoucher = null;
        }

        if (!$error && !$linkedVoucher) {
            $error = 'Selected payment voucher was not found.';
        } elseif (!$error) {
            if ($supplier_id === '' || $supplier_id === null) {
                foreach ($linkedVouchers as $voucherRow) {
                    $voucherSupplierId = (int) ($voucherRow['supplier_id'] ?? 0);
                    if ($voucherSupplierId > 0) {
                        $supplier_id = (string) $voucherSupplierId;
                        break;
                    }
                }
            }
            if (($supplier_id === '' || $supplier_id === null)) {
                foreach ($linkedVouchers as $voucherRow) {
                    $payeeName = trim((string) ($voucherRow['payee_name'] ?? ''));
                    if ($payeeName === '') {
                        continue;
                    }
                    $resolvedSupplierId = $resolveSupplierIdFromName($payeeName);
                    if ($resolvedSupplierId !== '') {
                        $supplier_id = $resolvedSupplierId;
                        break;
                    }
                }
            }
            if ($supplier_id === '' || $supplier_id === null) {
                $error = 'Unable to determine Supplier from selected voucher(s). Please choose Supplier manually.';
            }
        }
    } elseif ($supplier_id === '') {
        $error = "Please select a Supplier.";
    } elseif (count($valid_product_ids) == 0) {
        $error = "Please add at least one product.";
    } else {
        // Proceed...
        try {
            $pdo->beginTransaction();
            
            // 1. Calculate Totals (In USD for DB)
            $subtotal_usd = 0;
            $items_data = [];
            
            for ($i = 0; $i < count($product_ids); $i++) {
                if ($product_ids[$i] === '' || $product_ids[$i] === null) continue;
                
                $qty = isset($quantities[$i]) ? floatval($quantities[$i]) : 1;
                $price_display = isset($unit_prices[$i]) ? floatval($unit_prices[$i]) : 0;
                
                if ($supplier_link_draft) {
                    $price_usd = ($price_display > 0 && $rate > 0) ? ($price_display / $rate) : 0.0;
                } else {
                    $price_usd = ($rate > 0) ? ($price_display / $rate) : $price_display;
                }
                
                $total_usd = $qty * $price_usd;
                $subtotal_usd += $total_usd;
                
                $items_data[] = [
                    'product_id' => $product_ids[$i],
                    'quantity' => $qty,
                    'unit_price' => $price_usd,
                    'total_amount' => $total_usd
                ];
            }
            
            $tax_amount_usd = $subtotal_usd * ($tax_percentage / 100);
            $grand_total_usd = $subtotal_usd + $tax_amount_usd;
            
            // 2. Insert Header using the live stock PO schema (only insert columns that exist)
            $initialStatus = $supplier_link_draft ? PURCHASE_STATUS_DRAFT : PURCHASE_STATUS_PENDING;
            $poCols = $pdo->query("SHOW COLUMNS FROM stocks_purchase_orders")->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $candidateValues = [
                'po_number' => $po_number,
                'supplier_id' => $supplier_id,
                'purchase_type' => $purchase_type,
                'supplier_invoice_no' => ($supplier_invoice_no !== '' ? $supplier_invoice_no : null),
                'status' => $initialStatus,
                'procurement_workflow' => $procurement_journey,
                'created_by' => $_SESSION['user_id'] ?? null,
                'currency' => $selectedCurrencyCode,
                'exchange_rate' => $rate,
                'payment_voucher_id' => ($payment_voucher_id > 0 ? $payment_voucher_id : null),
                'payment_voucher_ids' => !empty($payment_voucher_ids) ? json_encode($payment_voucher_ids) : null,
                // Persist tax so View PO shows correct totals.
                'tax_percentage' => $tax_percentage,
                'tax_amount' => $tax_amount_usd,
                // Some schemas store header totals too.
                'subtotal' => $subtotal_usd,
                'total_amount' => $grand_total_usd,
            ];

            $insertCols = [];
            $valueSql = [];
            $insertVals = [];

            foreach ($candidateValues as $col => $val) {
                if (!in_array($col, $poCols, true)) {
                    continue;
                }
                $insertCols[] = $col;
                $valueSql[] = '?';
                $insertVals[] = $val;
            }

            // created_at: prefer DB-generated timestamp if column exists
            if (in_array('created_at', $poCols, true)) {
                $insertCols[] = 'created_at';
                $valueSql[] = 'NOW()';
            }

            $stmt = $pdo->prepare('INSERT INTO stocks_purchase_orders (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $valueSql) . ')');
            $stmt->execute($insertVals);
            $purchase_id = $pdo->lastInsertId();

            if (!empty($payment_voucher_ids)) {
                $pvCols2 = $pdo->query("SHOW COLUMNS FROM payment_vouchers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (in_array('linked_stock_po_id', $pvCols2, true)) {
                    $linkStmt = $pdo->prepare("UPDATE payment_vouchers SET linked_stock_po_id = ? WHERE id = ?");
                    foreach ($payment_voucher_ids as $pvLinkId) {
                        $linkStmt->execute([$purchase_id, $pvLinkId]);
                    }
                }
            }
            
            // 3. Insert Items using the live stock PO items schema
            $stmtItem = $pdo->prepare("INSERT INTO stocks_po_items (po_id, item_id, qty_ordered, qty_received, unit_cost, landed_cost) VALUES (?, ?, ?, 0, ?, ?)");
            foreach ($items_data as $item) {
                $stmtItem->execute([$purchase_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['unit_price']]);
            }

            // 4. Handle Attachments
            if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
                $uploadDir = '../../uploads/purchases/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                        $originalName = $_FILES['attachments']['name'][$key];
                        $fileSize = $_FILES['attachments']['size'][$key];
                        $fileType = $_FILES['attachments']['type'][$key];
                        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                        
                        // Security check for allowed extensions
                        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls', 'csv', 'txt'];
                        if (!in_array(strtolower($extension), $allowed)) continue;

                        $safeName = $po_number . '_' . time() . '_' . $key . '.' . $extension;
                        $destPath = $uploadDir . $safeName;

                        if (move_uploaded_file($tmpName, $destPath)) {
                            // Save to database
                            $stmtAttach = $pdo->prepare("INSERT INTO stocks_purchase_attachments (purchase_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                            $stmtAttach->execute([$purchase_id, $originalName, 'uploads/purchases/' . $safeName, $fileType, $fileSize]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['stock_po_create_success'] = [
                'title' => 'Success!',
                'message' => 'Purchase Order created: ' . $po_number,
                'variant' => 'success',
            ];
            redirect('index.php');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

$page_title = 'New Domestic Purchase Order';
include '../../includes/header.php';
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
/* Enerpize-style Enterprise ERP Design */
:root {
    --enerpize-bg: #f8f9fa;
    --enerpize-white: #ffffff;
    --enerpize-border: #e0e0e0;
    --enerpize-text-primary: #212529;
    --enerpize-text-secondary: #6c757d;
    --enerpize-primary: #0d6efd;
    --enerpize-primary-hover: #0b5ed7;
    --enerpize-success: #198754;
    --enerpize-shadow: 0 2px 4px rgba(0,0,0,0.08);
    --enerpize-shadow-hover: 0 4px 12px rgba(0,0,0,0.12);
}

.po-create-page {
    background: var(--enerpize-bg);
    min-height: 100vh;
    padding: 2rem 0;
}

.stock-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1rem;
}

/* Page Header - Clean & Professional */
.po-header {
    background: var(--enerpize-white);
    padding: 1.75rem 2rem;
    border-radius: 8px;
    margin-bottom: 2rem;
    box-shadow: var(--enerpize-shadow);
    border: none;
}

.po-header h2 {
    color: var(--enerpize-text-primary);
    font-weight: 600;
    font-size: 1.5rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.po-header h2 i {
    color: var(--enerpize-primary);
    font-size: 1.4rem;
}

.po-header .btn-back {
    background: transparent;
    border: 1px solid var(--enerpize-border);
    color: var(--enerpize-text-secondary);
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.2s;
}

.po-header .btn-back:hover {
    background: var(--enerpize-bg);
    border-color: var(--enerpize-primary);
    color: var(--enerpize-primary);
}

/* Cards - Clean Enterprise Style */
.po-card {
    background: var(--enerpize-white);
    border-radius: 8px;
    border: 1px solid var(--enerpize-border);
    box-shadow: var(--enerpize-shadow);
    margin-bottom: 2rem;
    overflow: hidden;
    transition: box-shadow 0.2s;
}

.po-card:hover {
    box-shadow: var(--enerpize-shadow-hover);
}

.po-card:last-child {
    margin-bottom: 0;
}

.supplier-catalogue-item {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    background: #fff;
    transition: all 0.2s ease;
}

.supplier-catalogue-item:hover {
    border-color: #0d6efd;
    box-shadow: 0 6px 18px rgba(13, 110, 253, 0.08);
}

.po-card-header {
    padding: 1.25rem 1.75rem;
    font-weight: 600;
    font-size: 0.95rem;
    color: var(--enerpize-text-primary);
    border-bottom: 1px solid var(--enerpize-border);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: var(--enerpize-white);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 0.8rem;
}

.po-card-header-icon {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    background: var(--enerpize-bg);
    color: var(--enerpize-text-secondary);
}

.po-card-body {
    padding: 1.75rem;
}

.po-card-body .row {
    margin-left: -0.75rem;
    margin-right: -0.75rem;
}

.po-card-body .row > [class*="col-"] {
    padding-left: 0.75rem;
    padding-right: 0.75rem;
}

/* Form Elements - Clean & Modern */
.form-label {
    font-weight: 600;
    color: var(--enerpize-text-primary);
    font-size: 0.875rem;
    margin-bottom: 0.625rem;
    display: block;
    letter-spacing: 0.01em;
}

.form-label .text-muted {
    font-weight: 400;
    color: var(--enerpize-text-secondary);
    font-size: 0.8rem;
}

.form-control, .form-select {
    border: 1px solid var(--enerpize-border);
    border-radius: 6px;
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    transition: all 0.2s;
    background: var(--enerpize-white);
    color: var(--enerpize-text-primary);
    width: 100%;
}

.form-control::placeholder {
    color: #adb5bd;
    opacity: 0.7;
}

.form-control:focus, .form-select:focus {
    border-color: var(--enerpize-primary);
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
    outline: none;
}

.form-control:read-only {
    background: #f8f9fa;
    cursor: not-allowed;
    color: var(--enerpize-text-secondary);
}

/* Supplier Info Box */
.supplier-info-box {
    background: #f8f9fa;
    border: 1px solid var(--enerpize-border);
    border-radius: 6px;
    padding: 1.25rem;
    margin-top: 1.25rem;
    color: var(--enerpize-text-secondary);
    font-size: 0.875rem;
    line-height: 1.7;
    border-left: 3px solid var(--enerpize-primary);
}

.supplier-info-box strong {
    color: var(--enerpize-text-primary);
    font-size: 0.95rem;
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
}

/* Items Table - Enterprise Style */
.po-items-table {
    width: 100%;
    border-collapse: collapse;
}

.po-items-table thead th {
    background: #f8f9fa;
    color: var(--enerpize-text-secondary);
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 1rem 1rem;
    border-bottom: 2px solid var(--enerpize-border);
    text-align: left;
}

.po-items-table tbody td {
    padding: 1.25rem 1rem;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    font-size: 0.9rem;
}

.po-items-table tbody tr:first-child td {
    padding-top: 1.5rem;
}

.po-items-table tbody tr:last-child td {
    padding-bottom: 1.5rem;
}

.po-items-table tbody tr:hover {
    background: #f8f9fa;
}

.po-items-table tbody tr:last-child td {
    border-bottom: none;
}

.product-img-cell {
    text-align: center;
    width: 80px;
}

.product-img-cell img {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid var(--enerpize-border);
}

.product-img-cell i {
    color: #d0d0d0;
    font-size: 1.5rem;
}

/* Buttons - Enterprise Style */
.btn-add-item {
    background: var(--enerpize-primary);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 0.625rem 1.25rem;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.btn-add-item:hover {
    background: var(--enerpize-primary-hover);
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(13, 110, 253, 0.3);
}

.catalogue-link {
    color: var(--enerpize-primary);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    margin-top: 0.75rem;
    transition: color 0.2s;
}

.catalogue-link:hover {
    color: var(--enerpize-primary-hover);
    text-decoration: none;
}

.catalogue-link i {
    font-size: 0.8rem;
}

/* Totals Card - Sticky Sidebar */
.po-totals-card {
    background: var(--enerpize-white);
    border: 1px solid var(--enerpize-border);
    border-radius: 8px;
    box-shadow: var(--enerpize-shadow);
    position: sticky;
    top: 1rem;
}

.po-totals-header {
    background: #f8f9fa;
    color: var(--enerpize-text-primary);
    padding: 1rem 1.5rem;
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--enerpize-border);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.po-totals-header i {
    color: var(--enerpize-text-secondary);
    font-size: 0.9rem;
}

.po-totals-body {
    padding: 1.5rem;
    background: var(--enerpize-white);
}

.totals-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.9rem;
    color: var(--enerpize-text-secondary);
}

.totals-row:first-child {
    padding-top: 0;
}

.totals-row:last-of-type {
    border-bottom: none;
}

.totals-row.tax-input {
    border-bottom: none;
    padding-bottom: 0.5rem;
}

.totals-row.grand-total {
    margin-top: 1rem;
    padding-top: 1.25rem;
    padding-bottom: 1.25rem;
    border-top: 2px solid var(--enerpize-border);
    border-bottom: none;
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--enerpize-text-primary);
    background: linear-gradient(to right, #f8f9fa, #ffffff);
    margin-left: -1.5rem;
    margin-right: -1.5rem;
    padding-left: 1.5rem;
    padding-right: 1.5rem;
    border-radius: 0 0 8px 8px;
}

.totals-value {
    font-weight: 600;
    color: var(--enerpize-text-primary);
}

.grand-total .totals-value {
    font-size: 1.4rem;
    color: var(--enerpize-primary);
}

.tax-input-wrapper {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.tax-input-wrapper input {
    width: 90px;
    text-align: right;
    border: 1px solid var(--enerpize-border);
    border-radius: 6px;
    padding: 0.375rem 0.5rem;
    font-size: 0.875rem;
    font-weight: 500;
}

.tax-input-wrapper input:focus {
    border-color: var(--enerpize-primary);
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
}

.tax-input-wrapper .text-muted {
    font-size: 0.75rem;
    color: var(--enerpize-text-secondary);
}

/* Submit Button - Enterprise Style */
.btn-submit-po {
    background: var(--enerpize-primary);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 0.875rem 1.5rem;
    font-weight: 600;
    font-size: 0.95rem;
    width: 100%;
    margin-top: 1.5rem;
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
    transition: all 0.2s;
}

.btn-submit-po:hover {
    background: var(--enerpize-primary-hover);
    color: white;
    box-shadow: 0 4px 8px rgba(13, 110, 253, 0.3);
    transform: translateY(-1px);
}

.btn-submit-po:active {
    transform: translateY(0);
}

.btn-remove-row {
    color: #dc3545;
    background: transparent;
    border: none;
    padding: 0.5rem;
    border-radius: 6px;
    transition: all 0.2s;
    cursor: pointer;
    opacity: 0.7;
}

.btn-remove-row:hover {
    background: #fff5f5;
    color: #dc3545;
    opacity: 1;
}

.alert-danger {
    border-radius: 6px;
    border-left: 4px solid #dc3545;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    background: #fff5f5;
    border-color: #dc3545;
}

.po-items-table .form-control-sm,
.po-items-table .form-select-sm {
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--enerpize-border);
    border-radius: 6px;
}

.po-items-table .form-control-sm:focus,
.po-items-table .form-select-sm:focus {
    border-color: var(--enerpize-primary);
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
}

.po-items-table input[readonly] {
    background: #f8f9fa;
    cursor: default;
    font-weight: 600;
}

/* Section Spacing */
.po-card + .po-card {
    margin-top: 1.5rem;
}

/* Form Group Spacing */
.mb-3, .mb-4 {
    margin-bottom: 1.5rem !important;
}

.mb-4 {
    margin-bottom: 2rem !important;
}

/* Table Actions Area */
.po-items-table + .border-top {
    background: #f8f9fa;
    border-top: 2px solid var(--enerpize-border) !important;
}

/* Clear Visual Hierarchy */
.po-card-header {
    font-size: 0.85rem;
}

.po-card-header-icon {
    flex-shrink: 0;
}

/* Better Input Grouping */
.form-group {
    margin-bottom: 1.5rem;
}

/* Responsive */
@media (max-width: 992px) {
    .po-create-page {
        padding: 1rem 0;
    }
    
    .stock-container {
        padding: 0 0.75rem;
    }
    
    .po-header {
        padding: 1.25rem 1.5rem;
    }
    
    .po-header h2 {
        font-size: 1.25rem;
    }
    
    .po-card-body {
        padding: 1.25rem;
    }
    
    .po-totals-card {
        position: static;
        margin-top: 2rem;
    }
    
    .row {
        margin-left: -0.5rem;
        margin-right: -0.5rem;
    }
    
    .row > [class*="col-"] {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
}

@media (max-width: 768px) {
    .po-header {
        padding: 1rem;
    }
    
    .po-header h2 {
        font-size: 1.15rem;
    }
    
    .po-card-body {
        padding: 1rem;
    }
    
    .po-items-table {
        font-size: 0.8rem;
    }
    
    .po-items-table th,
    .po-items-table td {
        padding: 0.75rem 0.5rem;
    }
    
    .po-items-table thead th {
        font-size: 0.7rem;
    }
}
    .purchase-pill-group {
        display: inline-flex;
        border: 1px solid #e5e7eb;
        border-radius: 50px;
        padding: 4px;
        background: #fff;
        gap: 0;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .purchase-pill {
        border: 0;
        background: transparent;
        padding: 8px 24px;
        border-radius: 50px;
        font-weight: 700;
        font-size: 0.9rem;
        line-height: 1;
        color: #4b5563;
        text-decoration: none !important;
        cursor: pointer;
        transition: all 0.2s ease;
        user-select: none;
        white-space: nowrap;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .purchase-pill:hover:not(.active) {
        background: #f9fafb;
        color: #111827;
    }
    .purchase-pill.active {
        background: #0d6efd; /* Brand blue */
        color: #ffffff !important;
    }
    
    /* Attachment Upload Styles */
    .attachment-upload-zone {
        border: 2px dashed var(--enerpize-border) !important;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    .attachment-upload-zone:hover {
        border-color: var(--enerpize-primary) !important;
        background: #f1f8ff !important;
    }
    .attachment-upload-zone .cursor-pointer {
        cursor: pointer;
    }
    .file-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 4px 0;
    }
    .file-item i {
        font-size: 0.8rem;
    }

    /* ================================
       Final ERP redesign (match create.php import layout)
       ================================ */
    main.main-content.mov-shell {
        background: #f8fafc !important;
        padding: 28px 32px !important;
    }
    main.main-content.mov-shell .max-w-full {
        max-width: none !important;
        width: 100% !important;
        margin: 0 !important;
    }
    .po-page-head {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 16px !important;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04) !important;
        margin-bottom: 18px !important;
        overflow: hidden !important;
    }
    .po-page-head .po-toolbar-main {
        padding: 14px 16px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        align-items: center !important;
    }
    .po-page-head .po-toolbar-main > a {
        height: 42px !important;
        border-radius: 10px !important;
        font-size: 14px !important;
        color: #334155 !important;
    }
    .po-page-head .po-toolbar-main h1 {
        font-size: 30px !important;
        color: #0f172a !important;
    }
    .po-page-head .po-toolbar-note {
        margin: 10px 16px 14px !important;
        padding: 12px 16px !important;
        border: 1px solid #bfdbfe !important;
        border-radius: 10px !important;
        background: #eff6ff !important;
        color: #334155 !important;
    }
    main.main-content.mov-shell > .max-w-full > .px-4.pt-4 {
        padding: 0 !important;
    }
    .po-voucher-layout {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) 360px !important;
        gap: 24px !important;
        margin: 0 !important;
    }
    .po-voucher-layout > [class*="col-"] {
        width: auto !important;
        max-width: none !important;
        flex: none !important;
        padding: 0 !important;
    }
    .po-main-column .po-card {
        margin-bottom: 24px !important;
    }
    .po-card,
    .po-totals-card {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 16px !important;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04) !important;
    }
    .po-card-header {
        background: #ffffff !important;
        border-bottom: 1px solid #e2e8f0 !important;
        border-radius: 16px 16px 0 0 !important;
        padding: 16px 20px 14px !important;
        font-size: 16px !important;
        font-weight: 700 !important;
        color: #0f172a !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
    }
    .section-ico {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 14px;
    }
    .section-ico.ico-supplier { background: #dbeafe; color: #1d4ed8; }
    .section-ico.ico-items { background: #dcfce7; color: #16a34a; }
    .section-ico.ico-info { background: #ffedd5; color: #c2410c; }
    .section-ico.ico-attach { background: #e2e8f0; color: #334155; }
    .section-ico.ico-summary { background: #ede9fe; color: #7c3aed; }

    .po-card-header-icon { display: none !important; }
    .po-card-body { padding: 20px !important; }
    .po-card-body .row.g-3 { --bs-gutter-x: 16px !important; --bs-gutter-y: 16px !important; }
    .workflow-panel {
        border: 1px solid #e2e8f0 !important;
        background: #f8fafc !important;
        border-radius: 10px !important;
        padding: 14px !important;
    }
    .form-label {
        margin-bottom: 8px !important;
        font-size: 14px !important;
        color: #334155 !important;
        font-weight: 600 !important;
        text-transform: none !important;
    }
    .form-control, .form-select {
        min-height: 46px !important;
        height: 46px !important;
        border-radius: 10px !important;
        border: 1px solid #cbd5e1 !important;
        font-size: 14px !important;
        color: #0f172a !important;
        padding: 0 14px !important;
        box-shadow: none !important;
    }
    textarea.form-control {
        min-height: 110px !important;
        height: auto !important;
        padding: 10px 14px !important;
    }
    .form-control:focus, .form-select:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12) !important;
    }
    small.text-muted, .text-muted.small {
        margin-top: 6px !important;
        color: #64748b !important;
        font-size: 12px !important;
    }
    .po-items-table thead th {
        background: #f8fafc !important;
        color: #475569 !important;
        border-bottom: 1px solid #e2e8f0 !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        padding: 14px 16px !important;
        text-transform: none !important;
    }
    .po-items-table tbody td {
        padding: 14px 16px !important;
        border-bottom: 1px solid #f1f5f9 !important;
        vertical-align: middle !important;
        font-size: 14px !important;
    }
    .po-items-table tbody tr { height: 64px !important; }
    .btn-remove-row { color: #dc2626 !important; }
    .btn-add-item {
        min-height: 44px !important;
        border-radius: 10px !important;
        background: #2563eb !important;
        border: 1px solid #2563eb !important;
        color: #fff !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        padding: 0 14px !important;
    }
    .btn-add-item:hover { background: #1d4ed8 !important; border-color: #1d4ed8 !important; }
    .catalogue-link {
        color: #2563eb !important;
        font-weight: 600 !important;
        font-size: 14px !important;
        text-decoration: none !important;
        margin-top: 0 !important;
    }
    #supplierInfo.supplier-info-box {
        display: none !important;
    }
    .pv-picker {
        position: relative;
        font-family: 'Outfit', system-ui, -apple-system, sans-serif !important;
    }
    .pv-topbar {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    .pv-search-wrap {
        position: relative;
        flex: 1 1 auto;
    }
    .pv-search-wrap i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        font-size: 14px;
    }
    .pv-search-input {
        width: 100%;
        min-height: 46px;
        height: 46px;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        padding: 0 40px 0 38px;
        font-size: 14px;
        color: #0f172a;
        background: #fff;
    }
    .pv-search-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        outline: none;
    }
    .pv-caret {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #334155;
        padding: 0;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        z-index: 2;
        line-height: 1;
    }
    .pv-filter-toggle {
        height: 46px;
        min-width: 98px;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #f8fbff;
        color: #2563eb;
        font-size: 14px;
        font-weight: 700;
        padding: 0 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
    .pv-dropdown {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        z-index: 60;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
        display: none;
        overflow: hidden;
        max-height: min(88vh, 820px);
    }
    .pv-dropdown.is-open {
        display: block;
    }
    .pv-dropdown.open-up {
        top: auto;
        bottom: calc(100% + 6px);
    }
    .pv-dropdown-head {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        padding: 10px 12px 8px;
        border-bottom: 1px solid #eef2f7;
    }
    .pv-filters {
        padding: 10px 12px;
        border-bottom: 1px solid #eef2f7;
    }
    .pv-filter-toolbar {
        display: grid;
        grid-template-columns: 1fr auto auto;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    .pv-mini-search-wrap {
        position: relative;
    }
    .pv-mini-search-wrap i {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 12px;
    }
    .pv-mini-search {
        width: 100%;
        height: 32px;
        border: 1px solid #dbe3ef;
        border-radius: 7px;
        padding: 0 10px 0 30px;
        font-size: 12px;
    }
    .pv-count-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
        border: 1px solid #dbe3ef;
        border-radius: 7px;
        padding: 0 10px;
        font-size: 12px;
        color: #334155;
        background: #fff;
        font-weight: 600;
    }
    .pv-filters.is-collapsed {
        display: none;
    }
    .pv-filter-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1.4fr 1fr auto;
        gap: 8px;
    }
    .pv-filter-grid .form-select,
    .pv-filter-grid .form-control {
        min-height: 34px !important;
        height: 34px !important;
        border-radius: 8px !important;
        font-size: 14px !important;
        padding: 0 10px !important;
    }
    .pv-date-range {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 6px;
        align-items: center;
    }
    .pv-date-range i {
        color: #94a3b8;
        font-size: 12px;
        text-align: center;
    }
    .pv-quick-filters {
        margin-top: 8px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    .pv-quick-filters {
        display: none !important;
    }
    .pv-chip {
        border: 1px solid #dbe3ef;
        border-radius: 7px;
        background: #fff;
        color: #475569;
        font-size: 13px;
        font-weight: 600;
        padding: 4px 10px;
    }
    .pv-chip.active {
        background: #eff6ff;
        border-color: #93c5fd;
        color: #2563eb;
    }
    .pv-results {
        max-height: 560px;
        overflow-y: auto;
        overscroll-behavior: contain;
    }
    .pv-table {
        width: 100%;
        border-collapse: collapse;
    }
    .pv-table th,
    .pv-table td {
        font-size: 13px;
        padding: 7px 10px;
        border-bottom: 1px solid #f1f5f9;
        text-align: left;
        white-space: nowrap;
    }
    .pv-col-select {
        width: 32px;
        min-width: 32px;
        max-width: 32px;
        text-align: center !important;
        padding-left: 8px !important;
        padding-right: 8px !important;
    }
    .pv-select-cell {
        text-align: center !important;
    }
    .pv-select-dot {
        width: 12px;
        height: 12px;
        border-radius: 999px;
        border: 1.5px solid #cbd5e1;
        display: inline-block;
        background: #fff;
    }
    .pv-row.is-selected .pv-select-dot {
        border-color: #2563eb;
        background: #2563eb;
        box-shadow: inset 0 0 0 2px #fff;
    }
    .pv-table th {
        background: #f8fafc;
        color: #334155;
        font-weight: 700;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .pv-row {
        cursor: pointer;
    }
    .pv-row:hover {
        background: #eff6ff;
    }
    .pv-row.is-selected {
        background: #eaf2ff;
    }
    .pv-status {
        display: inline-block;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 12px;
        font-weight: 700;
    }
    .pv-status.paid { background: #dcfce7; color: #15803d; }
    .pv-status.unpaid { background: #fee2e2; color: #b91c1c; }
    .pv-dropdown-foot {
        border-top: 1px solid #eef2f7;
        padding: 8px 12px;
        font-size: 13px;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .pv-foot-left {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }
    .pv-foot-right {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 14px;
        white-space: nowrap;
    }
    .pv-page-size-wrap {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
    }
    .pv-page-size {
        min-height: 28px !important;
        height: 28px !important;
        border-radius: 6px !important;
        border: 1px solid #dbe3ef !important;
        font-size: 12px !important;
        padding: 0 8px !important;
        color: #334155 !important;
        background: #fff !important;
    }
    .pv-footer-btn {
        border: 1px solid #dbe3ef;
        background: #fff;
        color: #2563eb;
        border-radius: 7px;
        font-size: 13px;
        font-weight: 700;
        padding: 4px 10px;
    }
    @media (max-width: 992px) {
        .pv-filter-grid {
            grid-template-columns: 1fr 1fr;
        }
        .pv-topbar {
            flex-wrap: wrap;
        }
    }
    .po-summary-column .po-totals-card {
        position: sticky !important;
        top: 90px !important;
    }
    .po-totals-card { overflow: hidden !important; }
    .po-totals-header {
        padding: 20px 22px 14px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        background: #fff !important;
        border-radius: 16px 16px 0 0 !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        font-size: 20px !important;
        color: #0f172a !important;
        text-transform: none !important;
        letter-spacing: 0 !important;
    }
    .po-totals-body { padding: 0 22px 22px !important; }
    .totals-row {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 14px 0 !important;
        border-bottom: 1px solid #eef2f7 !important;
        font-size: 14px !important;
        color: #475569 !important;
    }
    .totals-row:last-of-type { border-bottom: 0 !important; }
    .totals-row .totals-value { color: #0f172a !important; font-weight: 700 !important; }
    .tax-input-wrapper { display: inline-flex !important; align-items: center !important; gap: 8px !important; }
    .tax-input-wrapper .form-control-sm {
        width: 70px !important;
        min-height: 36px !important;
        height: 36px !important;
        border-radius: 8px !important;
        border: 1px solid #cbd5e1 !important;
        text-align: center !important;
        padding: 0 8px !important;
    }
    .totals-row.grand-total {
        margin-top: 10px !important;
        border-top: 1px solid #e2e8f0 !important;
        border-bottom: 0 !important;
        background: transparent !important;
        padding: 18px 12px 12px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 12px !important;
        flex-wrap: nowrap !important;
        max-width: 100% !important;
    }
    .grand-total span:first-child {
        font-size: 17px !important;
        color: #0f172a !important;
        font-weight: 700 !important;
        min-width: 0 !important;
        flex: 0 1 45% !important;
        max-width: 45% !important;
        display: block !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    .grand-total .totals-value {
        font-size: clamp(20px, 3.6vw, 26px) !important;
        color: #2563eb !important;
        font-weight: 800 !important;
        line-height: 1 !important;
        text-align: right !important;
        white-space: nowrap !important;
        min-width: 0 !important;
        flex: 0 1 55% !important;
        max-width: 55% !important;
        display: block !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    .btn-submit-po {
        width: 100% !important;
        min-height: 52px !important;
        height: 52px !important;
        border-radius: 10px !important;
        border: 1px solid #2563eb !important;
        background: #2563eb !important;
        color: #fff !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        margin-top: 12px !important;
        box-shadow: none !important;
        transform: none !important;
    }
    .btn-submit-po:hover { background: #1d4ed8 !important; border-color: #1d4ed8 !important; }

    @media (max-width: 992px) {
        main.main-content.mov-shell { padding: 16px !important; }
        .po-voucher-layout { grid-template-columns: 1fr !important; }
        .po-summary-column .po-totals-card { position: static !important; top: auto !important; }
    }
    @media (max-width: 420px) {
        .totals-row.grand-total { flex-wrap: wrap !important; align-items: flex-start !important; }
        .grand-total span:first-child {
            flex: 1 1 100% !important;
            max-width: 100% !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }
        .grand-total .totals-value {
            flex: 1 1 100% !important;
            max-width: 100% !important;
            text-align: left !important;
            margin-top: 6px !important;
        }
    }
</style>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 po-page-head">
            <div class="po-toolbar-main px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Purchase orders
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <span>New domestic purchase</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="domestic_create.php" class="btn px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0 no-underline" style="background-color:#2563EB;color:#fff;">
                    <i class="fas fa-truck-loading text-sm"></i> Domestic
                </a>
                <a href="create.php?type=import" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-shipping-fast text-sm"></i> Outdoor
                </a>
            </div>
            <div class="po-toolbar-note px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-info-circle text-gray-400 me-1"></i>Create a domestic PO and receive directly.</span>
            </div>
        </div>
        <div class="px-4 pt-4">
            
        <?php if($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="poForm" enctype="multipart/form-data">
            <div class="row po-voucher-layout">
                <div class="col-lg-8 po-main-column">
                    <!-- Supplier Details Card -->
                    <div class="po-card mb-4">
                        <div class="po-card-header">
                            <span class="section-ico ico-supplier"><i class="fas fa-address-book"></i></span>
                            <span>Supplier Details</span>
                        </div>
                        <div class="po-card-body">
                            <input type="hidden" id="purchase_type" name="purchase_type" value="domestic">

                            <div class="row g-3 align-items-end">
                                <div class="col-12">
                                    <label class="form-label">Procurement workflow</label>
                                    <div class="workflow-panel">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio" name="procurement_journey" id="pj_standard_dom" value="<?php echo htmlspecialchars(PURCHASE_PROC_STANDARD); ?>" checked>
                                            <label class="form-check-label" for="pj_standard_dom"><strong>Standard</strong> â€” PO is pending immediately.</label>
                                        </div>
                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="radio" name="procurement_journey" id="pj_supplier_link_dom" value="<?php echo htmlspecialchars(PURCHASE_PROC_SUPPLIER_LINK); ?>">
                                            <label class="form-check-label" for="pj_supplier_link_dom"><strong>Supplier link</strong> â€” Save as Draft; release the secure link from View PO when ready.</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="payment_voucher_id" class="form-label">Link Approved Payment Voucher (Stock Purchase)</label>
                                    <?php $selectedVoucherId = isset($_POST['payment_voucher_id']) ? (int) $_POST['payment_voucher_id'] : 0; ?>
                                    <?php $selectedVoucherIdsRaw = trim((string) ($_POST['payment_voucher_ids'] ?? ($selectedVoucherId > 0 ? (string) $selectedVoucherId : ''))); ?>
                                    <div class="pv-picker" id="pvPickerDomestic">
                                        <div class="pv-topbar">
                                            <div class="pv-search-wrap">
                                                <i class="fas fa-search"></i>
                                                <input type="text" class="pv-search-input" id="payment_voucher_search" placeholder="Search by PV No., supplier, amount, reference...">
                                                <button type="button" class="pv-caret" id="pvPickerToggleDomestic"><i class="fas fa-chevron-down"></i></button>
                                            </div>
                                            <button type="button" class="pv-filter-toggle" id="pvFiltersToggleDomestic"><i class="fas fa-filter"></i> Filters <i class="fas fa-chevron-up" style="font-size:11px;"></i></button>
                                        </div>
                                        <div class="pv-dropdown" id="payment_voucher_dropdown">
                                            <div class="pv-dropdown-head">Recent Payment Vouchers</div>
                                            <div class="pv-filters" id="pv_filter_panel">
                                                <div class="pv-filter-toolbar">
                                                    <div class="pv-mini-search-wrap">
                                                        <i class="fas fa-search"></i>
                                                        <input type="text" id="pv_filter_search_inline" class="pv-mini-search" placeholder="Search vouchers...">
                                                    </div>
                                                    <button type="button" class="pv-footer-btn" id="pvFiltersToggleInlineDomestic"><i class="fas fa-filter"></i> Filters <i class="fas fa-chevron-up" style="font-size:11px;"></i></button>
                                                    <span class="pv-count-chip" id="pv_total_count">0 vouchers</span>
                                                </div>
                                                <div class="pv-filter-grid">
                                                    <select id="pv_filter_created_by" class="form-select"><option value="">All Users</option></select>
                                                    <select id="pv_filter_supplier" class="form-select"><option value="">Select Supplier</option></select>
                                                    <div class="pv-date-range">
                                                        <input type="text" id="pv_filter_date_from" class="form-control" placeholder="From">
                                                        <i class="fas fa-arrow-right"></i>
                                                        <input type="text" id="pv_filter_date_to" class="form-control" placeholder="To">
                                                    </div>
                                                    <select id="pv_filter_status" class="form-select">
                                                        <option value="">All Statuses</option>
                                                        <option value="paid">Paid</option>
                                                        <option value="unpaid">Unpaid</option>
                                                        <option value="partial">Partial</option>
                                                        <option value="overdue">Overdue</option>
                                                    </select>
                                                    <button type="button" id="pv_clear_filters" class="pv-footer-btn">Clear Filters</button>
                                                </div>
                                                <div class="pv-quick-filters">
                                                    <button type="button" class="pv-chip active" data-pv-chip="all">All</button>
                                                    <button type="button" class="pv-chip" data-pv-chip="paid">Paid</button>
                                                    <button type="button" class="pv-chip" data-pv-chip="unpaid">Unpaid</button>
                                                    <button type="button" class="pv-chip" data-pv-chip="partial">Partial</button>
                                                    <button type="button" class="pv-chip" data-pv-chip="overdue">Overdue</button>
                                                    <button type="button" class="pv-chip" data-pv-chip="my_vouchers">My Vouchers</button>
                                                </div>
                                            </div>
                                            <div class="pv-results" id="payment_voucher_results"></div>
                                            <div class="pv-dropdown-foot">
                                                <span class="pv-foot-left"><i class="fas fa-search"></i> Type to search more results...</span>
                                                <span class="pv-foot-right">
                                                    <span class="pv-page-size-wrap">Show
                                                        <select id="pv_page_size" class="pv-page-size">
                                                            <option value="15" selected>15</option>
                                                            <option value="25">25</option>
                                                            <option value="50">50</option>
                                                            <option value="100">100</option>
                                                        </select>
                                                    </span>
                                                    <span id="pv_result_count">Showing 0 results</span>
                                                    <button type="button" class="pv-footer-btn" id="pv_load_more">Load more</button>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="payment_voucher_ids" name="payment_voucher_ids" value="<?php echo htmlspecialchars($selectedVoucherIdsRaw); ?>">
                                    <select class="form-select d-none" id="payment_voucher_id" name="payment_voucher_id">
                                        <option value="">-- None (manual PO) --</option>
                                        <?php foreach ($stockPurchaseVouchers as $pv): ?>
                                            <?php
                                                $pvId = (int) ($pv['id'] ?? 0);
                                                $isSel = $selectedVoucherId === $pvId;
                                                $pvAmount = number_format((float) ($pv['total_amount'] ?? 0), 2);
                                                $pvStatusText = ((int) ($pv['is_paid'] ?? 0) === 1) ? 'Paid' : 'Unpaid';
                                            ?>
                                            <option
                                                value="<?php echo $pvId; ?>"
                                                data-supplier-id="<?php echo (int) ($pv['supplier_id'] ?? 0); ?>"
                                                data-payee="<?php echo htmlspecialchars((string) ($pv['payee_name'] ?? 'Unknown Supplier')); ?>"
                                                data-payee-type="<?php echo htmlspecialchars(strtolower(trim((string) ($pv['payee_type'] ?? '')))); ?>"
                                                data-status="<?php echo strtolower($pvStatusText); ?>"
                                                data-currency="<?php echo htmlspecialchars((string) ($pv['currency'] ?? '')); ?>"
                                                data-amount="<?php echo $pvAmount; ?>"
                                                data-date="<?php echo !empty($pv['date_created']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $pv['date_created']))) : ''; ?>"
                                                data-created-by="System Admin"
                                                <?php echo $isSel ? 'selected' : ''; ?>
                                            >
                                                <?php echo htmlspecialchars((string) ($pv['voucher_no'] ?? 'PV-' . $pvId)); ?> - <?php echo htmlspecialchars((string) ($pv['payee_name'] ?? 'Unknown Payee')); ?> (<?php echo htmlspecialchars((string) ($pv['currency'] ?? '')); ?> <?php echo $pvAmount; ?>, <?php echo $pvStatusText; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted d-block mt-1">
                                        Procurement can create a PO from an admin-approved stock-purchase voucher.
                                    </small>
                                    <small id="pvPayeeTypeIndicator" class="d-block mt-1 text-muted">Payee type: Not selected</small>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <label for="supplier_id" class="form-label">
                                        Supplier <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" <?php echo ($suppliersAvailable && !empty($suppliers)) ? 'required' : 'disabled'; ?> onchange="updateSupplierDetails()">
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach($suppliers as $sup): ?>
                                            <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small id="pvSupplierNamesIndicator" class="d-block mt-1 text-muted">Suppliers from selected vouchers: None</small>
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-1">
                                        <?php if ($suppliersAvailable && !empty($suppliers)): ?>
                                            <button type="button" class="catalogue-link py-1 px-2 border-0" onclick="openSupplierCatalogue()">
                                                <i class="fas fa-search-plus me-1"></i> Browse Supplier Catalogue
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Purchase Order Date</label>
                                    <input type="date" class="form-control" name="purchase_order_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" id="currency_code" name="currency">
                                        <?php
                                        $currOptions = [
                                            'USD' => 'USD (' . getCurrencySymbol('USD') . ')',
                                            'TZS' => 'TZS (' . getCurrencySymbol('TZS') . ')',
                                            'EUR' => 'EUR (' . getCurrencySymbol('EUR') . ')',
                                            'GBP' => 'GBP (' . getCurrencySymbol('GBP') . ')',
                                            'KES' => 'KES (' . getCurrencySymbol('KES') . ')',
                                        ];
                                        foreach ($currOptions as $code => $label):
                                            $sel = ($selectedCurrencyCode === $code) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-lg-4 col-md-6">
                                    <label class="form-label">Exchange Rate</label>
                                    <input type="number" step="0.0001" min="0" class="form-control" name="exchange_rate" value="<?php echo number_format((float) $rate, 4, '.', ''); ?>">
                                    <small class="text-muted d-block mt-1">System exchange rate will be used.</small>
                                </div>
                                <div class="col-12" id="domesticFormWrap">
                                    <div class="workflow-panel">
                                        <div class="fw-bold mb-2">Purchase Details</div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="supplier_invoice_no" class="form-label">Supplier Invoice / Delivery Note No</label>
                                                <input type="text" class="form-control" id="supplier_invoice_no" name="supplier_invoice_no" placeholder="e.g. INV-12345 / DN-7788">
                                                <small class="text-muted d-block mt-1">Used for local receiving reference.</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Receiving Status</label>
                                                <div class="form-control bg-white" style="min-height: 38px;">
                                                    <span class="text-success fw-bold"><i class="fas fa-check-circle me-1"></i> Direct Receiving</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div id="supplierInfo" class="supplier-info-box d-none">
                                        <!-- JS populated -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Order Items Card -->
                    <div class="po-card mb-4">
                        <div class="po-card-header">
                            <span class="section-ico ico-items"><i class="fas fa-boxes"></i></span>
                            <span>Order Items</span>
                        </div>
                        <div class="po-card-body p-0">
                            <div class="table-responsive">
                                <table class="po-items-table" id="itemsTable">
                                    <thead>
                                        <tr>
                                            <th class="product-img-cell">Image</th>
                                            <th>Product</th>
                                            <th style="width: 120px;">Quantity</th>
                                            <th style="width: 150px;">Unit Price</th>
                                            <th style="width: 150px;">Total</th>
                                            <th style="width: 50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsBody">
                                        <!-- Rows will be added here -->
                                    </tbody>
                                </table>
                            </div>
                            <div class="p-3 border-top bg-light">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <button type="button" class="btn-add-item" onclick="addItemRow()">
                                        <i class="fas fa-plus me-2"></i> Add Item
                                    </button>
                                    <a href="<?php echo $catalogueUrl; ?>" class="catalogue-link">
                                        <i class="fas fa-shopping-cart me-1"></i> Browse Catalogue
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Additional Information Card -->
                    <div class="po-card mb-4">
                        <div class="po-card-header">
                            <span class="section-ico ico-info"><i class="fas fa-file-alt"></i></span>
                            <span>Additional Information</span>
                        </div>
                        <div class="po-card-body">
                            <div class="row g-3">
                            <div class="col-md-6">
                                <label for="notes" class="form-label">
                                    <i class="fas fa-sticky-note me-2 text-muted" style="font-size: 0.8rem;"></i>Notes
                                </label>
                                <textarea class="form-control" id="notes" name="notes" rows="4" placeholder="Add any internal notes or instructions for this purchase order..."><?php echo htmlspecialchars($cloned_po['notes'] ?? ''); ?></textarea>
                                <small class="text-muted d-block mt-1">Internal notes visible only to your team</small>
                            </div>
                            <div class="col-md-6">
                                <label for="terms_conditions" class="form-label">
                                    <i class="fas fa-file-contract me-2 text-muted" style="font-size: 0.8rem;"></i>Terms & Conditions
                                </label>
                                <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="5" placeholder="Enter custom terms or leave blank to use company defaults..."><?php echo htmlspecialchars($cloned_po['terms_conditions'] ?? ''); ?></textarea>
                                <small class="text-muted d-block mt-1">These terms will appear on the purchase order document</small>
                            </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 po-summary-column">
                    <!-- Attachments Card -->
                    <div class="po-card mb-4">
                        <div class="po-card-header">
                            <span class="section-ico ico-attach"><i class="fas fa-paperclip"></i></span>
                            <span>Documents & Attachments</span>
                        </div>
                        <div class="po-card-body">
                            <div class="mb-0">
                                <label for="attachments" class="form-label">
                                    Upload Documents <span class="text-muted small ms-2">(PDF, JPG, PNG, DOCX, XLSX)</span>
                                </label>
                                <div class="attachment-upload-zone border border-dashed rounded-3 p-4 text-center bg-light position-relative">
                                    <input type="file" name="attachments[]" id="attachments" class="position-absolute w-100 h-100 top-0 start-0 opacity-0 cursor-pointer" multiple onchange="updateFileCount(this)">
                                    <div class="upload-zone-content">
                                        <i class="fas fa-cloud-upload-alt fa-2x text-primary mb-2"></i>
                                        <p class="mb-1 fw-bold">Click to upload or drag and drop</p>
                                        <p class="text-secondary small mb-0">Max file size: 5MB per file</p>
                                    </div>
                                    <div id="file-list" class="mt-3 text-start small d-none">
                                        <hr>
                                        <div class="fw-bold mb-1">Selected Files:</div>
                                        <ul id="selected-files" class="list-unstyled mb-0"></ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary Card -->
                    <div class="po-totals-card">
                        <div class="po-totals-header">
                            <span class="section-ico ico-summary"><i class="fas fa-calculator"></i></span>
                            <span>Order Summary</span>
                        </div>
                        <div class="po-totals-body">
                            <div class="totals-row">
                                <span class="fw-medium">Subtotal:</span>
                                <span class="totals-value" id="displaySubtotal"><?php echo $currency; ?>0.00</span>
                            </div>
                            <div class="totals-row tax-input">
                                <span class="fw-medium">Tax Rate:</span>
                                <div class="tax-input-wrapper">
                                    <input type="number" step="0.01" min="0" name="tax_percentage" id="taxPercentage" value="<?php echo isset($cloned_po['tax_percentage']) ? floatval($cloned_po['tax_percentage']) : '0'; ?>" oninput="calculateGrandTotal()" class="form-control-sm">
                                    <span class="text-muted small">%</span>
                                </div>
                            </div>
                            <div class="totals-row">
                                <span class="fw-medium">Tax Amount:</span>
                                <span class="totals-value" id="displayTax"><?php echo $currency; ?>0.00</span>
                            </div>
                            <div class="totals-row grand-total">
                                <span>Grand Total:</span>
                                <span class="totals-value" id="displayGrandTotal"><?php echo $currency; ?>0.00</span>
                            </div>
                            
                            <button type="submit" class="btn-submit-po">
                                <i class="fas fa-save me-2"></i> Create Purchase Order
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</main>

<div class="modal fade" id="supplierCatalogueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-truck me-2 text-primary"></i>Supplier Catalogue</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" id="supplierCatalogueSearch" class="form-control" placeholder="Search by supplier, contact, phone, email..." oninput="renderSupplierCatalogue(this.value)">
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <small class="text-muted" id="supplierCatalogueCount">Showing 0 suppliers</small>
                    <small class="text-muted">All suppliers from the stock supplier register</small>
                </div>
                <div id="supplierCatalogueList" class="d-grid gap-2"></div>
            </div>
        </div>
    </div>
</div>

<script>
const productsData = <?php echo json_encode($products); ?>;
const suppliersData = <?php echo json_encode($suppliers); ?>;
const clonedItems = <?php echo json_encode($cloned_items); ?>;
const stockPurchaseVouchersData = <?php echo json_encode($stockPurchaseVouchers); ?>;
const voucherSalesOrderItemsMap = <?php echo json_encode($voucherSalesOrderItemsMap); ?>;
// Exchange Rate + Currency
let EXCHANGE_RATE = <?php echo (float) $rate; ?>;
let CURRENCY_SYMBOL = <?php echo json_encode((string) $currency); ?>;
const CURRENCY_CODE_SELECT = document.getElementById('currency_code');
const CURRENCY_RATES = {
    USD: 1,
    EUR: 1,
    GBP: 1,
    KES: 1,
    TZS: <?php echo (float) ($settings['exchange_rate'] ?? 1); ?>
};
const CURRENCY_SYMBOLS = {
    USD: <?php echo json_encode(getCurrencySymbol('USD')); ?>,
    EUR: <?php echo json_encode(getCurrencySymbol('EUR')); ?>,
    GBP: <?php echo json_encode(getCurrencySymbol('GBP')); ?>,
    KES: <?php echo json_encode(getCurrencySymbol('KES')); ?>,
    TZS: <?php echo json_encode(getCurrencySymbol('TZS')); ?>
};
let liveSuppliersData = Array.isArray(suppliersData) ? [...suppliersData] : [];

function getSelectedVoucherIds() {
    const hidden = document.getElementById('payment_voucher_ids');
    const select = document.getElementById('payment_voucher_id');
    const ids = [];
    const seen = {};
    const raw = hidden ? String(hidden.value || '').trim() : '';
    if (raw !== '') {
        raw.split(',').forEach(token => {
            const id = parseInt(token, 10) || 0;
            if (id > 0 && !seen[id]) {
                seen[id] = true;
                ids.push(id);
            }
        });
    }
    if (ids.length === 0 && select && select.value) {
        const fallback = parseInt(select.value, 10) || 0;
        if (fallback > 0) ids.push(fallback);
    }
    return ids;
}

function normalizeVoucherText(value) {
    return String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

function getSelectedVoucherOptions() {
    const voucherSelect = document.getElementById('payment_voucher_id');
    if (!voucherSelect) return [];
    const idSet = new Set(getSelectedVoucherIds().map(id => String(id)));
    return Array.from(voucherSelect.options || []).filter(opt => idSet.has(String(opt.value || '')));
}

function getSelectedVoucherTypeMeta() {
    const selectedOptions = getSelectedVoucherOptions();
    const supplierNames = [];
    const nonSupplierNames = [];
    const seenSuppliers = new Set();
    const seenNonSuppliers = new Set();

    selectedOptions.forEach(opt => {
        const payee = (opt.getAttribute('data-payee') || '').trim() || (opt.textContent || '').trim();
        const payeeType = normalizeVoucherText(opt.getAttribute('data-payee-type') || '');
        const payeeKey = normalizeVoucherText(payee);
        if (!payeeKey) return;
        if (payeeType === 'supplier') {
            if (!seenSuppliers.has(payeeKey)) {
                seenSuppliers.add(payeeKey);
                supplierNames.push(payee);
            }
        } else {
            if (!seenNonSuppliers.has(payeeKey)) {
                seenNonSuppliers.add(payeeKey);
                nonSupplierNames.push(payee);
            }
        }
    });

    return { selectedOptions, supplierNames, nonSupplierNames };
}

function updateSupplierNamesIndicator() {
    const supplierIndicator = document.getElementById('pvSupplierNamesIndicator');
    if (!supplierIndicator) return;
    const meta = getSelectedVoucherTypeMeta();
    if (!meta.selectedOptions.length) {
        supplierIndicator.className = 'd-block mt-1 text-muted';
        supplierIndicator.textContent = 'Suppliers from selected vouchers: None';
        return;
    }
    if (meta.supplierNames.length > 0) {
        supplierIndicator.className = 'd-block mt-1 text-success fw-semibold';
        supplierIndicator.textContent = 'Suppliers from selected vouchers: ' + meta.supplierNames.join(', ');
    } else {
        supplierIndicator.className = 'd-block mt-1 text-warning fw-semibold';
        supplierIndicator.textContent = 'Suppliers from selected vouchers: None (no supplier-type payee selected)';
    }
}

function syncSupplierFromVoucher() {
    const voucherSelect = document.getElementById('payment_voucher_id');
    const supplierSelect = document.getElementById('supplier_id');
    if (!voucherSelect || !supplierSelect) return;
    const meta = getSelectedVoucherTypeMeta();
    const selectedOptions = meta.selectedOptions;
    updateSupplierNamesIndicator();
    if (!selectedOptions.length) return;

    // Prefer first supplier-type voucher that has explicit supplier_id.
    let supplierFromId = null;
    for (const opt of selectedOptions) {
        const payeeType = normalizeVoucherText(opt.getAttribute('data-payee-type') || '');
        if (payeeType !== 'supplier') continue;
        const sid = parseInt(opt.getAttribute('data-supplier-id') || '0', 10) || 0;
        if (sid > 0) {
            supplierFromId = String(sid);
            break;
        }
    }
    if (supplierFromId) {
        supplierSelect.value = supplierFromId;
        updateSupplierDetails();
        return;
    }

    // Fallback to first supplier-type payee name matched by supplier label.
    const matchByName = meta.supplierNames.find(name => {
        const key = normalizeVoucherText(name);
        const match = Array.from(supplierSelect.options || []).find(opt => normalizeVoucherText(opt.textContent) === key);
        if (match && match.value) {
            supplierSelect.value = match.value;
            return true;
        }
        return false;
    });

    // Last fallback: keep payee visible as a temporary supplier option for submit-time resolution.
    if (!matchByName && meta.supplierNames.length > 0) {
        const payeeRaw = meta.supplierNames[0];
        const tempValue = '__pv_payee__' + payeeRaw;
        let match = Array.from(supplierSelect.options || []).find(opt => opt.value === tempValue);
        if (!match) {
            match = document.createElement('option');
            match.value = tempValue;
            match.textContent = payeeRaw;
            match.setAttribute('data-temp-payee', '1');
            supplierSelect.appendChild(match);
        }
        supplierSelect.value = match.value;
    } else if (meta.supplierNames.length === 0) {
        supplierSelect.value = '';
    }
    updateSupplierDetails();
}

function updatePayeeTypeIndicator() {
    const voucherSelect = document.getElementById('payment_voucher_id');
    const indicator = document.getElementById('pvPayeeTypeIndicator');
    if (!voucherSelect || !indicator) return;
    const meta = getSelectedVoucherTypeMeta();
    const selectedOptions = meta.selectedOptions;
    if (!selectedOptions.length) {
        indicator.className = 'd-block mt-1 text-muted';
        indicator.textContent = 'Payee type: Not selected';
        return;
    }

    if (selectedOptions.length > 1) {
        if (meta.nonSupplierNames.length > 0) {
            indicator.className = 'd-block mt-1 text-warning fw-semibold';
            indicator.textContent = 'Payee type check: Non-supplier payees selected - ' + meta.nonSupplierNames.join(', ');
        } else {
            indicator.className = 'd-block mt-1 text-success fw-semibold';
            indicator.textContent = 'Payee type check: All selected payees are Supplier type (' + selectedOptions.length + ')';
        }
        return;
    }

    const selectedOption = selectedOptions[0];
    voucherSelect.value = String(selectedOption.value || '');
    const payeeType = String(selectedOption.getAttribute('data-payee-type') || '').trim().toLowerCase();
    if (payeeType === 'supplier') {
        indicator.className = 'd-block mt-1 text-success fw-semibold';
        indicator.textContent = 'Payee type: Supplier';
    } else if (payeeType !== '') {
        indicator.className = 'd-block mt-1 text-warning fw-semibold';
        indicator.textContent = 'Payee type: Not supplier (' + payeeType + ')';
    } else {
        indicator.className = 'd-block mt-1 text-warning fw-semibold';
        indicator.textContent = 'Payee type: Not supplier';
    }
}

function applyVoucherLinkedSalesOrderItems() {
    const tbody = document.getElementById('itemsBody');
    if (!tbody || typeof addItemRow !== 'function') return;
    const selectedIds = getSelectedVoucherIds();
    if (!selectedIds.length) return;
    const merged = {};
    selectedIds.forEach(voucherId => {
        const mapped = (voucherSalesOrderItemsMap && voucherSalesOrderItemsMap[String(voucherId)])
            ? voucherSalesOrderItemsMap[String(voucherId)]
            : (voucherSalesOrderItemsMap ? voucherSalesOrderItemsMap[voucherId] : null);
        if (!Array.isArray(mapped) || mapped.length === 0) return;
        mapped.forEach(item => {
            const pid = parseInt(item.product_id, 10) || 0;
            if (!pid) return;
            const qty = parseFloat(item.quantity) || 0;
            const unit = parseFloat(item.unit_price) || 0;
            if (!merged[pid]) {
                merged[pid] = { product_id: pid, quantity: 0, unit_price: unit };
            }
            merged[pid].quantity += qty > 0 ? qty : 1;
            if (!merged[pid].unit_price && unit > 0) merged[pid].unit_price = unit;
        });
    });
    const mergedItems = Object.values(merged);
    if (!mergedItems.length) return;
    tbody.innerHTML = '';
    mergedItems.forEach(item => {
        addItemRow({
            product_id: item.product_id,
            quantity: item.quantity,
            unit_price: item.unit_price
        });
    });
    calculateGrandTotal();
}

function initPaymentVoucherPicker() {
    const select = document.getElementById('payment_voucher_id');
    const hiddenIds = document.getElementById('payment_voucher_ids');
    const input = document.getElementById('payment_voucher_search');
    const dropdown = document.getElementById('payment_voucher_dropdown');
    const results = document.getElementById('payment_voucher_results');
    const countEl = document.getElementById('pv_result_count');
    const createdByFilter = document.getElementById('pv_filter_created_by');
    const supplierFilter = document.getElementById('pv_filter_supplier');
    const dateFromFilter = document.getElementById('pv_filter_date_from');
    const dateToFilter = document.getElementById('pv_filter_date_to');
    const statusFilter = document.getElementById('pv_filter_status');
    const inlineSearch = document.getElementById('pv_filter_search_inline');
    const totalCountEl = document.getElementById('pv_total_count');
    const clearBtn = document.getElementById('pv_clear_filters');
    const filtersPanel = document.getElementById('pv_filter_panel');
    const filtersToggle = document.getElementById('pvFiltersToggleDomestic');
    const filtersToggleInline = document.getElementById('pvFiltersToggleInlineDomestic');
    const loadMoreBtn = document.getElementById('pv_load_more');
    const pageSizeSelect = document.getElementById('pv_page_size');
    const chips = Array.from(document.querySelectorAll('[data-pv-chip]'));
    const toggle = document.getElementById('pvPickerToggleDomestic');
    if (!select || !input || !dropdown || !results) return;

    const options = Array.from(select.options)
        .filter(opt => (opt.value || '').trim() !== '')
        .map(opt => ({
            value: opt.value,
            label: (opt.textContent || '').trim(),
            supplierId: opt.getAttribute('data-supplier-id') || '',
            payee: opt.getAttribute('data-payee') || '',
            status: (opt.getAttribute('data-status') || '').toLowerCase(),
            currency: opt.getAttribute('data-currency') || '',
            amount: opt.getAttribute('data-amount') || '0.00',
            date: opt.getAttribute('data-date') || '',
            createdBy: opt.getAttribute('data-created-by') || 'System Admin'
        }));
    const selectedValues = new Set(getSelectedVoucherIds().map(v => String(v)));
    if (selectedValues.size === 0 && select.value) {
        selectedValues.add(String(select.value));
    }
    let activeChip = 'all';
    const DEFAULT_PAGE_SIZE = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : '15', 10) || 15;
    let displayLimit = DEFAULT_PAGE_SIZE;
    let lastFilteredCount = 0;
    let currentQuery = '';

    const getSelected = () => {
        const first = Array.from(selectedValues)[0] || '';
        return options.find(o => o.value === first);
    };

    const refreshSelectionState = () => {
        const values = Array.from(selectedValues);
        if (hiddenIds) hiddenIds.value = values.join(',');
        select.value = values[0] || '';
        const selected = getSelected();
        if (values.length > 1) {
            input.value = values.length + ' vouchers selected';
        } else if (selected) {
            input.value = selected.label;
        } else {
            input.value = '';
        }
        updatePayeeTypeIndicator();
    };

    const parseDMY = (str) => {
        const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec((str || '').trim());
        if (!m) return null;
        return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
    };

    const render = (query = '') => {
        const q = query.trim().toLowerCase();
        const rows = options.filter(o => {
            if (q && !(`${o.label} ${o.payee} ${o.amount} ${o.currency}`.toLowerCase().includes(q))) return false;
            if (supplierFilter && supplierFilter.value && o.payee !== supplierFilter.value) return false;
            if (createdByFilter && createdByFilter.value && o.createdBy !== createdByFilter.value) return false;
            const d = parseDMY(o.date);
            const from = dateFromFilter ? parseDMY(dateFromFilter.value) : null;
            const to = dateToFilter ? parseDMY(dateToFilter.value) : null;
            if (from && d && d < from) return false;
            if (to && d && d > to) return false;
            const statusWanted = (statusFilter && statusFilter.value) ? statusFilter.value : (activeChip !== 'all' ? activeChip : '');
            if (statusWanted === 'my_vouchers') {
                // Placeholder "my vouchers" mode until user identity mapping is added.
            } else if (statusWanted && o.status !== statusWanted) {
                return false;
            }
            return true;
        });
        if (totalCountEl) {
            totalCountEl.textContent = rows.length + (rows.length === 1 ? ' voucher' : ' vouchers');
        }
        lastFilteredCount = rows.length;
        const visibleRows = rows.slice(0, displayLimit);
        if (visibleRows.length === 0) {
            results.innerHTML = '<div class="p-2 text-muted small">No matching approved payment vouchers.</div>';
            if (countEl) countEl.textContent = 'Showing 0 results';
            if (loadMoreBtn) loadMoreBtn.style.display = 'none';
            return;
        }
        const start = 1;
        const end = visibleRows.length;
        if (countEl) countEl.textContent = `Showing ${start} to ${end} of ${rows.length} results`;
        if (loadMoreBtn) loadMoreBtn.style.display = rows.length > visibleRows.length ? 'inline-flex' : 'none';
        results.innerHTML = `
            <table class="pv-table">
                <thead>
                    <tr>
                        <th class="pv-col-select"></th>
                        <th>PV No.</th>
                        <th>Supplier</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    ${visibleRows.map(o => `
                        <tr class="pv-row ${selectedValues.has(o.value) ? 'is-selected' : ''}" data-value="${o.value}">
                            <td class="pv-col-select pv-select-cell"><span class="pv-select-dot"></span></td>
                            <td>${(o.label.split(' - ')[0] || '').trim()}</td>
                            <td>${o.payee}</td>
                            <td>${o.date || '-'}</td>
                            <td>${o.currency} ${o.amount}</td>
                            <td><span class="pv-status ${o.status === 'paid' ? 'paid' : 'unpaid'}">${o.status === 'paid' ? 'Paid' : 'Unpaid'}</span></td>
                            <td>${o.createdBy}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    };

    const applyDropdownPlacement = () => {
        if (!dropdown.classList.contains('is-open')) return;
        const rect = input.getBoundingClientRect();
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
        const spaceBelow = Math.max(0, viewportHeight - rect.bottom - 10);
        const spaceAbove = Math.max(0, rect.top - 10);
        const shouldOpenUp = spaceBelow < 320 && spaceAbove > spaceBelow;
        dropdown.classList.toggle('open-up', shouldOpenUp);

        const available = shouldOpenUp ? spaceAbove : spaceBelow;
        const targetMax = Math.max(320, Math.min(760, Math.floor(available)));
        dropdown.style.maxHeight = `${targetMax}px`;

        const headerApprox = 290; // header + filters + footer spacing
        const resultsMax = Math.max(200, targetMax - headerApprox);
        results.style.maxHeight = `${resultsMax}px`;
    };

    const open = (keepQuery = false) => {
        dropdown.classList.add('is-open');
        if (!keepQuery) currentQuery = '';
        render(currentQuery);
        applyDropdownPlacement();
    };
    const close = () => {
        dropdown.classList.remove('is-open');
        dropdown.classList.remove('open-up');
    };

    refreshSelectionState();
    if (createdByFilter) {
        const users = Array.from(new Set(options.map(o => o.createdBy).filter(Boolean))).sort((a, b) => a.localeCompare(b));
        createdByFilter.innerHTML = '<option value="">All Users</option>' + users.map(u => `<option value="${u}">${u}</option>`).join('');
    }
    if (supplierFilter) {
        const names = Array.from(new Set(options.map(o => o.payee).filter(Boolean))).sort((a, b) => a.localeCompare(b));
        supplierFilter.innerHTML = '<option value="">Select Supplier</option>' + names.map(n => `<option value="${n}">${n}</option>`).join('');
    }

    input.addEventListener('focus', () => open(false));
    input.addEventListener('input', () => {
        currentQuery = input.value || '';
        displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE;
        open(true);
        render(currentQuery);
    });
    if (toggle) {
        toggle.addEventListener('click', () => {
            if (dropdown.classList.contains('is-open')) {
                close();
            } else {
                open(false);
                input.focus();
            }
        });
    }

    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', () => {
            filtersPanel.classList.toggle('is-collapsed');
            applyDropdownPlacement();
        });
    }
    if (filtersToggleInline && filtersPanel) {
        filtersToggleInline.addEventListener('click', () => {
            filtersPanel.classList.toggle('is-collapsed');
            applyDropdownPlacement();
        });
    }
    if (createdByFilter) createdByFilter.addEventListener('change', () => { displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE; render(currentQuery); });
    if (supplierFilter) supplierFilter.addEventListener('change', () => { displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE; render(currentQuery); });
    if (dateFromFilter) dateFromFilter.addEventListener('input', () => { displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE; render(currentQuery); });
    if (dateToFilter) dateToFilter.addEventListener('input', () => { displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE; render(currentQuery); });
    if (statusFilter) statusFilter.addEventListener('change', () => render(currentQuery));
    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', () => {
            displayLimit = parseInt(pageSizeSelect.value || String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE;
            render(currentQuery);
        });
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (createdByFilter) createdByFilter.value = '';
            if (supplierFilter) supplierFilter.value = '';
            if (dateFromFilter) dateFromFilter.value = '';
            if (dateToFilter) dateToFilter.value = '';
            if (statusFilter) statusFilter.value = '';
            if (inlineSearch) inlineSearch.value = '';
            currentQuery = '';
            activeChip = 'all';
            displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE;
            chips.forEach(c => c.classList.toggle('active', c.getAttribute('data-pv-chip') === 'all'));
            render(currentQuery);
        });
    }
    if (inlineSearch) {
        inlineSearch.addEventListener('input', () => {
            currentQuery = inlineSearch.value || '';
            input.value = currentQuery;
            displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE;
            render(currentQuery);
        });
    }
    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            activeChip = chip.getAttribute('data-pv-chip') || 'all';
            chips.forEach(c => c.classList.toggle('active', c === chip));
            if (statusFilter) statusFilter.value = '';
            displayLimit = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : String(DEFAULT_PAGE_SIZE), 10) || DEFAULT_PAGE_SIZE;
            render(currentQuery);
        });
    });
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', () => {
            const step = parseInt((pageSizeSelect && pageSizeSelect.value) ? pageSizeSelect.value : '10', 10) || 10;
            displayLimit = Math.min(displayLimit + step, lastFilteredCount || displayLimit + step);
            render(currentQuery);
        });
    }

    results.addEventListener('click', (e) => {
        const item = e.target.closest('.pv-row[data-value]');
        if (!item) return;
        e.preventDefault();
        e.stopPropagation();
        const value = item.getAttribute('data-value') || '';
        if (!value) return;
        if (selectedValues.has(value)) selectedValues.delete(value);
        else selectedValues.add(value);
        refreshSelectionState();
        currentQuery = '';
        render(currentQuery);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#pvPickerDomestic')) close();
    });
    window.addEventListener('resize', applyDropdownPlacement);
    window.addEventListener('scroll', applyDropdownPlacement, true);
}

// NOTE: addItemRow is now defined below to support arguments, see bottom of file
// We will replace this block with nothing or just the constants to keep file clean
// Actually, let's just remove the OLD addItemRow and let the NEW one at the bottom take over? 
// No, I should update this block to include the NEW logic and remove the bottom block in the next step.
// Let's do it cleanly: Update THIS block with the new function.

function addItemRow(data = null) {
    const tbody = document.getElementById('itemsBody');
    const rowId = 'row_' + Date.now() + Math.random().toString(36).substr(2, 5);
    
    let productOptions = '<option value="">Select Product</option>';
    productsData.forEach(p => {
        let selected = (data && data.product_id == p.id) ? 'selected' : '';
        const imgPid = (p.image_product_id || p.linked_product_id || '');
        productOptions += `<option value="${p.id}" data-price="${p.buying_price}" data-image="${p.main_image || ''}" data-image-product-id="${imgPid}" ${selected}>${p.name} (${p.product_code || 'N/A'})</option>`;
    });

    // Pre-render image if row is pre-filled (e.g. from Catalogue)
    let imgHtml = `<i class="fas fa-image text-muted opacity-25 fa-2x"></i>`;
    if (data && data.product_id) {
        const p = productsData.find(x => x.id == data.product_id);
        const imgProductId = p ? (p.image_product_id || p.linked_product_id) : null;
        if (p && p.main_image && imgProductId) {
            const src = `/stock/uploads/products/${imgProductId}/medium/${p.main_image}`;
            imgHtml = `<img src="${src}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 1px solid #e5e7eb;" onerror="this.src='/assets/images/placeholder.png'; this.onerror=null;">`;
        }
    }
    
    // Calculate display values if data exists
    let qty = 1;
    let unitPriceVal = "0.00";
    let totalVal = "0.00";

    if (data) {
        qty = data.quantity;
        // Convert stored USD price to Display Rate
        let priceUSD = parseFloat(data.unit_price);
        unitPriceVal = (priceUSD * EXCHANGE_RATE).toFixed(2);
        totalVal = (qty * unitPriceVal).toFixed(2);
    }

    const tr = document.createElement('tr');
    tr.id = rowId;
    tr.innerHTML = `
        <td class="product-img-cell">
            ${imgHtml}
        </td>
        <td>
            <select class="form-select form-select-sm" name="product_id[]" onchange="updateRowPrice(this)" required>
                ${productOptions}
            </select>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm" name="quantity[]" min="1" value="${qty}" oninput="updateRowTotal(this)" required>
        </td>
        <td>
            <input type="number" step="0.01" class="form-control form-control-sm text-end" name="unit_price[]" value="${unitPriceVal}" oninput="updateRowTotal(this)" required>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm text-end bg-light fw-semibold" name="total[]" value="${totalVal}" readonly>
        </td>
        <td class="text-center">
            <button type="button" class="btn-remove-row" onclick="removeRow('${rowId}')" title="Remove item">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
}

function removeRow(rowId) {
    const row = document.getElementById(rowId);
    row.remove();
    calculateGrandTotal();
}

function updateRowPrice(select) {
    const row = select.closest('tr');
    const option = select.options[select.selectedIndex];
    const basePrice = parseFloat(option.getAttribute('data-price')) || 0;
    const imgPath = option.getAttribute('data-image');
    const imageProductId = option.getAttribute('data-image-product-id');
    
    // Update Image
    const imgCell = row.querySelector('.product-img-cell');
    if (imgPath && imageProductId) {
        const src = `/stock/uploads/products/${imageProductId}/medium/${imgPath}`;
        imgCell.innerHTML = `<img src="${src}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 1px solid #e5e7eb;" onerror="this.src='/assets/images/placeholder.png'; this.onerror=null;">`;
    } else {
        imgCell.innerHTML = `<i class="fas fa-image text-muted opacity-25 fa-2x"></i>`;
    }
    
    // Apply Exchange Rate for Display
    const displayPrice = basePrice * EXCHANGE_RATE;
    
    row.querySelector('input[name="unit_price[]"]').value = displayPrice.toFixed(2);
    updateRowTotal(select);
}

function updateRowTotal(element) {
    const row = element.closest('tr');
    const qty = parseFloat(row.querySelector('input[name="quantity[]"]').value) || 0;
    const price = parseFloat(row.querySelector('input[name="unit_price[]"]').value) || 0;
    const total = qty * price;
    
    row.querySelector('input[name="total[]"]').value = total.toFixed(2);
    
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let subtotal = 0;
    const totalInputs = document.querySelectorAll('input[name="total[]"]');
    
    totalInputs.forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    
    const taxRate = parseFloat(document.getElementById('taxPercentage').value) || 0;
    const taxAmount = subtotal * (taxRate / 100);
    const grandTotal = subtotal + taxAmount;
    
    // CURRENCY_SYMBOL is already defined globally
    // const CURRENCY_SYMBOL = "<?php echo $currency; ?>";

    document.getElementById('displaySubtotal').innerText = CURRENCY_SYMBOL + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('displayTax').innerText = CURRENCY_SYMBOL + taxAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('displayGrandTotal').innerText = CURRENCY_SYMBOL + grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Init
document.addEventListener('DOMContentLoaded', function() {
    initPaymentVoucherPicker();
    const voucherSelect = document.getElementById('payment_voucher_id');
    if (voucherSelect) {
        voucherSelect.addEventListener('change', () => {
            syncSupplierFromVoucher();
            updatePayeeTypeIndicator();
            applyVoucherLinkedSalesOrderItems();
        });
    }

    // Currency selector behavior (updates display prices using current base USD prices).
    if (CURRENCY_CODE_SELECT) {
        const applyCurrency = () => {
            const code = (CURRENCY_CODE_SELECT.value || 'USD').toUpperCase();
            EXCHANGE_RATE = (typeof CURRENCY_RATES[code] !== 'undefined') ? (parseFloat(CURRENCY_RATES[code]) || 1) : 1;
            CURRENCY_SYMBOL = (typeof CURRENCY_SYMBOLS[code] !== 'undefined') ? CURRENCY_SYMBOLS[code] : '$';

            // Update all existing rows unit prices based on selected product base price.
            document.querySelectorAll('#itemsBody tr').forEach(tr => {
                const sel = tr.querySelector('select[name=\"product_id[]\"]');
                const opt = sel ? sel.options[sel.selectedIndex] : null;
                const basePrice = opt ? (parseFloat(opt.getAttribute('data-price')) || 0) : 0;
                const unitInput = tr.querySelector('input[name=\"unit_price[]\"]');
                if (unitInput) {
                    unitInput.value = (basePrice * EXCHANGE_RATE).toFixed(2);
                }
                updateRowTotal(sel || tr);
            });

            calculateGrandTotal();
        };
        CURRENCY_CODE_SELECT.addEventListener('change', applyCurrency);
    }

    renderSupplierCatalogue('');
    toggleDomesticFields();

    // Check URL Params for Replenishment
    const urlParams = new URLSearchParams(window.location.search);
    const preProdId = urlParams.get('product_id');
    const preQty = urlParams.get('qty');
    const preProdName = urlParams.get('product_name');
    const preProdCode = urlParams.get('product_code');

    // Catalogue -> Purchase Order (from Sales Catalogue)
    try {
        const raw = localStorage.getItem('purchase_catalogue_items');
        if (raw) {
            const picked = JSON.parse(raw);
            if (Array.isArray(picked) && picked.length > 0) {
                // Try set supplier based on first picked product
                const first = picked[0];
                const firstProd = productsData.find(p => p.linked_product_id == first.product_id);
                if (firstProd && firstProd.supplier_id) {
                    const supSelect = document.getElementById('supplier_id');
                    if (supSelect) {
                        supSelect.value = firstProd.supplier_id;
                        updateSupplierDetails();
                    }
                }

                // Replace rows
                document.getElementById('itemsBody').innerHTML = '';
                picked.forEach(item => {
                    const prod = productsData.find(p => p.linked_product_id == item.product_id);
                    if (prod) {
                        addItemRow({
                            product_id: prod.id,
                            quantity: item.quantity || 1,
                            unit_price: prod.buying_price || 0
                        });
                    }
                });
                calculateGrandTotal();
            }
            localStorage.removeItem('purchase_catalogue_items');
        }
    } catch (e) {
        // ignore malformed localStorage
    }

    if (preProdId) {
        // Replenishment sends products.id, so map it to the linked stock item.
        let preProd = productsData.find(p => p.linked_product_id == preProdId);

        // Fallbacks: sometimes there is no linked_product_id match (name/SKU mismatch).
        if (!preProd) {
            preProd = productsData.find(p => p.id == preProdId);
        }
        if (!preProd && preProdCode) {
            const code = String(preProdCode).trim().toLowerCase();
            if (code) {
                preProd = productsData.find(p => String(p.product_code || '').trim().toLowerCase() === code);
            }
        }
        if (!preProd && preProdName) {
            const nm = String(preProdName).trim().toLowerCase();
            if (nm) {
                preProd = productsData.find(p => String(p.name || '').trim().toLowerCase() === nm);
            }
        }
        if (preProd && preProd.supplier_id) {
            const supSelect = document.getElementById('supplier_id');
            if (supSelect) {
                supSelect.value = preProd.supplier_id;
                updateSupplierDetails();
            }
        }

        if (preProd) {
            addItemRow({
                product_id: preProd.id,
                quantity: preQty || 1,
                unit_price: preProd.buying_price || 0
            });
            calculateGrandTotal();
        } else {
            addItemRow();
        }
    } else if (typeof clonedItems !== 'undefined' && clonedItems.length > 0) {
        clonedItems.forEach(item => {
            addItemRow(item);
        });
        calculateGrandTotal();
        
        // Trigger supplier update if cloned
        const supSelect = document.getElementById('supplier_id');
        if(supSelect.value) updateSupplierDetails();
    } else {
        addItemRow();
    }
    
    // Update supplier details on fresh load if something selected (e.g. browser back)
    const supSelect = document.getElementById('supplier_id');
    if(supSelect && supSelect.value && !document.getElementById('supplierInfo').innerHTML) updateSupplierDetails();
    if (voucherSelect && voucherSelect.value) {
        syncSupplierFromVoucher();
        updatePayeeTypeIndicator();
        applyVoucherLinkedSalesOrderItems();
    } else {
        updatePayeeTypeIndicator();
    }
});

function toggleDomesticFields() {
    const typeEl = document.getElementById('purchase_type');
    const domWrap = document.getElementById('domesticFormWrap');
    const outWrap = document.getElementById('outdoorFormWrap');
    const hint = document.getElementById('purchaseTypeHint');
    const btnDom = document.getElementById('btnDomestic');
    const btnOut = document.getElementById('btnOutdoor');

    if (!typeEl) return;
    const isDomestic = (typeEl.value || 'domestic') === 'domestic';
    if (domWrap) domWrap.classList.toggle('d-none', !isDomestic);
    if (outWrap) outWrap.classList.toggle('d-none', isDomestic);
    if (hint) hint.textContent = isDomestic
        ? 'Domestic purchase = direct receiving. Outdoor = track in Shipments, receive stock from Purchases.'
        : 'Outdoor: use Shipments for tracking; receive inventory from the PO list (Receive stock).';

    if (btnDom && btnOut) {
        btnDom.classList.toggle('active', isDomestic);
        btnOut.classList.toggle('active', !isDomestic);
        btnDom.setAttribute('aria-pressed', isDomestic ? 'true' : 'false');
        btnOut.setAttribute('aria-pressed', !isDomestic ? 'true' : 'false');
    }
}

    // Simplified for domestic_create.php
    function setType(type) {
        // No-op or just force domestic
        const typeEl = document.getElementById('purchase_type');
        if (typeEl) typeEl.value = 'domestic';
    }


function openSupplierCatalogue() {
    const search = document.getElementById('supplierCatalogueSearch');
    if (search) search.value = '';

    const modalEl = document.getElementById('supplierCatalogueModal');
    if (window.bootstrap && modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    loadSupplierCatalogue();
}

function loadSupplierCatalogue() {
    const list = document.getElementById('supplierCatalogueList');
    const countEl = document.getElementById('supplierCatalogueCount');

    if (list) {
        list.innerHTML = '<div class="text-center text-muted py-4 small">Loading suppliers...</div>';
    }
    if (countEl) {
        countEl.textContent = 'Loading suppliers...';
    }

    fetch('create.php?action=get_suppliers_catalogue')
        .then(response => response.json())
        .then(data => {
            if (data && data.success && Array.isArray(data.suppliers)) {
                liveSuppliersData = data.suppliers;
            }
            renderSupplierCatalogue('');
        })
        .catch(() => {
            renderSupplierCatalogue('');
        });
}

function renderSupplierCatalogue(term = '') {
    const list = document.getElementById('supplierCatalogueList');
    const countEl = document.getElementById('supplierCatalogueCount');
    if (!list) return;

    const q = (term || '').toLowerCase().trim();
    const sourceSuppliers = (Array.isArray(liveSuppliersData) && liveSuppliersData.length > 0) ? liveSuppliersData : suppliersData;
    const filtered = !q ? sourceSuppliers : sourceSuppliers.filter(s =>
        (s.name || '').toLowerCase().includes(q) ||
        (s.contact_person || '').toLowerCase().includes(q) ||
        (s.phone || '').toLowerCase().includes(q) ||
        (s.email || '').toLowerCase().includes(q) ||
        (s.address || '').toLowerCase().includes(q)
    );

    if (filtered.length === 0) {
        if (countEl) countEl.textContent = 'Showing 0 suppliers';
        list.innerHTML = '<div class="text-center text-muted py-4 small">No matching suppliers found.</div>';
        return;
    }

    if (countEl) {
        countEl.textContent = `Showing ${filtered.length} supplier${filtered.length === 1 ? '' : 's'}`;
    }

    list.innerHTML = filtered.map(supplier => `
        <div class="supplier-catalogue-item">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="fw-bold text-dark">${supplier.name || 'Unknown Supplier'}</div>
                    <div class="text-muted small">${supplier.contact_person ? supplier.contact_person : 'No contact person'}</div>
                    <div class="text-muted small mt-1">
                        ${supplier.phone ? `<span class="me-2"><i class="fas fa-phone me-1"></i>${supplier.phone}</span>` : ''}
                        ${supplier.email ? `<span><i class="fas fa-envelope me-1"></i>${supplier.email}</span>` : ''}
                    </div>
                    ${supplier.address ? `<div class="text-muted small mt-1"><i class="fas fa-map-marker-alt me-1"></i>${supplier.address}</div>` : ''}
                </div>
                <button type="button" class="btn btn-sm btn-primary" onclick="selectSupplierFromCatalogue('${supplier.id}')">Select</button>
            </div>
        </div>
    `).join('');
}

function selectSupplierFromCatalogue(supplierId) {
    const supplierSelect = document.getElementById('supplier_id');
    if (supplierSelect) {
        supplierSelect.value = supplierId;
        updateSupplierDetails();
    }

    const modalEl = document.getElementById('supplierCatalogueModal');
    if (window.bootstrap && modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
}

function updateSupplierDetails() {
    const id = document.getElementById('supplier_id').value;
    const infoBox = document.getElementById('supplierInfo');
    
    if (!id) {
        infoBox.classList.add('d-none');
        infoBox.innerHTML = '';
        return;
    }
    
    const supplier = suppliersData.find(s => s.id == id);
    if (supplier) {
        infoBox.classList.remove('d-none');
        
        let html = `<strong>${supplier.name}</strong>`;
        if(supplier.address) html += `<br>${supplier.address.replace(/\n/g, '<br>')}`;
        if(supplier.contact_person) html += `<br><i class="fas fa-user me-1"></i> ${supplier.contact_person}`;
        if(supplier.phone) html += `<br><i class="fas fa-phone me-1"></i> ${supplier.phone}`;
        if(supplier.email) html += `<br><i class="fas fa-envelope me-1"></i> ${supplier.email}`;
        
        infoBox.innerHTML = html;
    }
}

function updateFileCount(input) {
    const list = document.getElementById('selected-files');
    const wrap = document.getElementById('file-list');
    
    if (input.files.length > 0) {
        wrap.classList.remove('d-none');
        list.innerHTML = '';
        
        Array.from(input.files).forEach(file => {
            const li = document.createElement('li');
            li.className = 'file-item text-primary fw-medium';
            
            // Icon based on type
            let icon = 'fa-file';
            if (file.type.includes('image')) icon = 'fa-file-image';
            if (file.type.includes('pdf')) icon = 'fa-file-pdf';
            if (file.type.includes('excel') || file.name.endsWith('.xlsx')) icon = 'fa-file-excel';
            if (file.type.includes('word') || file.name.endsWith('.docx')) icon = 'fa-file-word';
            
            li.innerHTML = `<i class="fas ${icon} me-1"></i> ${file.name} (${formatBytes(file.size)})`;
            list.appendChild(li);
        });
    } else {
        wrap.classList.add('d-none');
        list.innerHTML = '';
    }
}

function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}
</script>

<?php include '../../includes/footer.php'; ?>
