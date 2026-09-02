<?php
// session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/paths.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
$currencyHelpersFile = dirname(__DIR__, 3) . '/modules/expenses/includes/currency_helpers.php';
if (is_file($currencyHelpersFile)) {
    require_once $currencyHelpersFile;
}
$botExchangeFile = dirname(__DIR__, 3) . '/includes/bot_exchange_rates.php';
if (is_file($botExchangeFile)) {
    require_once $botExchangeFile;
}
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
ensureStocksPurchaseOrdersWorkflowColumns($pdo);
ensureVoucherStockPurchaseSchema();

$company_id = (int) (currentCompanyId() ?? 0);
if ($company_id <= 0 && function_exists('defaultCompanyId')) {
    $company_id = (int) (defaultCompanyId() ?? 0);
}

$isPoClassificationEdit = defined('STOCK_PO_CLASSIFICATION_EDIT') && STOCK_PO_CLASSIFICATION_EDIT;
$classificationEditPoId = ($isPoClassificationEdit && !empty($GLOBALS['stockPoClassificationEditPoId']))
    ? (int) $GLOBALS['stockPoClassificationEditPoId']
    : 0;
if ($isPoClassificationEdit && $classificationEditPoId <= 0) {
    redirect('index.php');
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
    if (!tableExists($table, $pdo)) {
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
    if (!tableExists('stocks_suppliers', $pdo) || !tableExists('suppliers', $pdo)) {
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
    if (!tableExists('stocks_suppliers', $pdo) || !tableExists('payees', $pdo)) {
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
    if (!tableExists('stocks_items', $pdo) || !tableExists('products', $pdo)) {
        return;
    }
    if (!function_exists('ensureStockItemForSalesProductId')) {
        return;
    }

    // Ensure every sales product has a stocks_items row so the PO Product picker
    // can search the full catalogue (PO line FKs still use stocks_items.id).
    try {
        $productIds = $pdo->query('SELECT id FROM products ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Exception $e) {
        return;
    }

    foreach ($productIds as $productId) {
        $pid = (int) $productId;
        if ($pid <= 0) {
            continue;
        }
        try {
            ensureStockItemForSalesProductId($pdo, $pid);
        } catch (Throwable $e) {
            continue;
        }
    }
}

function loadAllSystemSuppliers(PDO $pdo, bool $syncProducts = true): array {
    syncLegacySuppliersToStocksRegistry($pdo);
    syncSupplierPayeesToStocksRegistry($pdo);
    if ($syncProducts) {
        syncProductsToStocksRegistry($pdo); // Also ensure products are in stock registry
    }

    if (tableExists('stocks_suppliers', $pdo)) {
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
$replenishmentProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

try {
    // Always sync full product catalogue into stocks_items so Product search covers every product.
    $suppliers = loadAllSystemSuppliers($pdo, true);
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
    try {
        syncProductsToStocksRegistry($pdo);
    } catch (Throwable $syncErr) {
        // Non-fatal; picker will show whatever stocks_items already has.
    }
}

// Fetch approved stock-purchase payment vouchers (for linking to PO)
$classificationLinkedVoucherIdsRaw = '';
$classificationSelectedVoucherId = 0;
$classificationPoNumber = '';
$classificationEditPoRow = null;
if ($isPoClassificationEdit) {
    $classificationEditPoRow = $GLOBALS['stockPoClassificationEditPo'] ?? null;
    if (!$classificationEditPoRow && $classificationEditPoId > 0 && function_exists('fetchStockPurchaseOrderById')) {
        $classificationEditPoRow = fetchStockPurchaseOrderById($pdo, $classificationEditPoId, true);
    }
    if (!$classificationEditPoRow) {
        flash('success', 'Purchase order not found.', 'error');
        redirect('index.php');
    }
    $classificationPoNumber = (string) ($classificationEditPoRow['po_number'] ?? ('PO #' . $classificationEditPoId));
    $linkedIdsEarly = parseStockPurchasePoLinkedVoucherIds($classificationEditPoRow);
    $classificationLinkedVoucherIdsRaw = implode(',', $linkedIdsEarly);
    $classificationSelectedVoucherId = !empty($linkedIdsEarly) ? (int) $linkedIdsEarly[0] : 0;
    $stockPurchaseVouchers = fetchStockPurchasePoVouchersForClassificationEdit($pdo, $company_id, $classificationEditPoId, $linkedIdsEarly);
    if (empty($stockPurchaseVouchers)) {
        $stockPurchaseVoucherPickerHint = 'No approved Stock Purchase payment vouchers were found. On the Finance Payment Desk, vouchers must be Approved with Purpose = Stock Purchase (not General Payment).';
    } else {
        $stockPurchaseVoucherPickerHint = count($stockPurchaseVouchers) . ' approved Stock Purchase voucher(s) available (includes unpaid and any already linked to this PO).';
    }
} else {
    $stockPurchaseVouchers = fetchStockPurchasePoLinkableVouchers($pdo, $company_id);
}
$stockPurchaseVoucherPickerHint = $stockPurchaseVoucherPickerHint ?? '';
$allowPostedVouchersInPicker = function_exists('stockPurchasePoAllowPostedVoucherPicker') && stockPurchasePoAllowPostedVoucherPicker();
if (!$isPoClassificationEdit && empty($stockPurchaseVouchers)) {
    $stockPurchaseVoucherPickerHint = $allowPostedVouchersInPicker
        ? 'No approved Stock Purchase payment vouchers are available. '
        . 'This list shows vouchers with Purpose = Stock Purchase that are approved and not already linked to a PO (includes posted vouchers when limited-edit workflow is enabled). '
        . 'General-purpose vouchers do not appear here.'
        : 'No approved, unpaid Stock Purchase payment vouchers are available. '
        . 'This list only shows vouchers with Purpose = Stock Purchase that are approved, not yet paid, and not already linked to a PO. '
        . 'General-purpose vouchers do not appear here.';
}

// Ensure stocks_items rows exist for products on linked quotations before building the PO product picker.
try {
    $preEnsuredSalesProductIds = [];
    foreach ($stockPurchaseVouchers as $pvPreRow) {
        if (!paymentVoucherHasLinkedQuotation($pvPreRow)) {
            continue;
        }
        $linkedSoIds = parseLinkedSalesOrderIdsFromVoucher($pvPreRow);
        foreach (fetchSalesProductIdsForSalesOrders($pdo, $linkedSoIds, $company_id) as $salesPid) {
            if ($salesPid > 0 && !isset($preEnsuredSalesProductIds[$salesPid])) {
                ensureStockItemForSalesProductId($pdo, $salesPid);
                $preEnsuredSalesProductIds[$salesPid] = true;
            }
        }
    }
} catch (Throwable $e) {
    // Non-fatal; autofill will still attempt per-line mapping.
}

$productCols = [];
try {
    $productCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $productCols = [];
}

$hasImageCol = in_array('image', $productCols, true);
$hasMainImageCol = in_array('main_image', $productCols, true);
$productImageCol = $hasMainImageCol ? 'main_image' : ($hasImageCol ? 'image' : null);
$productBuyingPriceCol = in_array('buying_price', $productCols, true) ? 'buying_price' : (in_array('cost_price', $productCols, true) ? 'cost_price' : 'unit_price');
$productSupplierCol = in_array('supplier_id', $productCols, true) ? 'supplier_id' : null;
$stockItemCols = [];
try {
    $stockItemCols = $pdo->query("SHOW COLUMNS FROM stocks_items")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $stockItemCols = [];
}
$stockItemBuyingPriceCol = in_array('buying_price', $stockItemCols, true) ? 'buying_price' : (in_array('cost_price', $stockItemCols, true) ? 'cost_price' : null);

if ($hasImageCol && $hasMainImageCol) {
    // Prefer products.image, fallback to products.main_image; treat '' as NULL
    $productImageSelect = "COALESCE(NULLIF(p.image,''), NULLIF(p.main_image,'')) AS main_image";
} elseif ($productImageCol) {
    $productImageSelect = "p.`$productImageCol` AS main_image";
} else {
    $productImageSelect = "NULL AS main_image";
}
$productBuyingPriceSelect = "`$productBuyingPriceCol` AS buying_price";
$stockItemBuyingPriceSelect = $stockItemBuyingPriceCol ? "si.`$stockItemBuyingPriceCol`" : "NULL";
$productSupplierSelect = $productSupplierCol ? "`$productSupplierCol` AS supplier_id" : "NULL AS supplier_id";

/**
 * Replenishment opens PO pages with products.id.
 * But PO items are based on stocks_items, so we must ensure a corresponding stocks_items row exists.
 * We create it opportunistically if missing (name + sku=product_code), filling any required columns
 * with safe defaults based on column types.
 */
function ensureStocksItemForProduct(PDO $pdo, int $productId): void
{
    ensureStockItemForSalesProductId($pdo, $productId);
}

// If this PO page was opened from Replenishment, ensure a stocks_items row exists.
if (isset($_GET['product_id'])) {
    ensureStocksItemForProduct($pdo, (int)$_GET['product_id']);
}
if (!empty($_GET['catalogue_product_ids'])) {
    foreach (preg_split('/\s*,\s*/', (string) $_GET['catalogue_product_ids']) as $cataloguePid) {
        $cataloguePidInt = (int) $cataloguePid;
        if ($cataloguePidInt > 0) {
            ensureStocksItemForProduct($pdo, $cataloguePidInt);
        }
    }
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
            $stockItemBuyingPriceSelect,
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

foreach ($products as &$prodRow) {
    $imgProductId = (int) ($prodRow['image_product_id'] ?? $prodRow['linked_product_id'] ?? 0);
    $imgFile = trim((string) ($prodRow['main_image'] ?? ''));
    $prodRow['image_url'] = ($imgProductId > 0 && function_exists('stock_product_list_image_url'))
        ? stock_product_list_image_url($imgProductId, $imgFile, 'medium', (string) ($stockBasePath ?? ''))
        : '';
}
unset($prodRow);

// Build map: voucher_id => quotation line rows for PO autofill (Phase 4G-3).
$voucherSalesOrderItemsMap = [];
$voucherAttachmentCounts = [];
$voucherAttachmentsMap = [];
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

        if (function_exists('getVoucherAttachments')) {
            $pvAttachments = getVoucherAttachments($voucherId);
            $voucherAttachmentCounts[$voucherId] = count($pvAttachments);
            $voucherAttachmentsMap[$voucherId] = [];
            foreach ($pvAttachments as $attRow) {
                $rel = ltrim((string) ($attRow['file_path'] ?? ''), '/');
                $originalName = (string) ($attRow['original_name'] ?? basename($rel));
                $mime = strtolower((string) ($attRow['mime_type'] ?? ''));
                $isImage = (strpos($mime, 'image/') === 0) || preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $rel);
                $ext = strtoupper(pathinfo($originalName, PATHINFO_EXTENSION) ?: ($isImage ? 'IMG' : 'FILE'));
                $proxyPath = 'proxy_pdf.php?file=' . rawurlencode($rel);
                $fileUrl = function_exists('app_url') ? app_url($proxyPath) : ('/' . ltrim($proxyPath, '/'));
                $voucherAttachmentsMap[$voucherId][] = [
                    'id' => (int) ($attRow['id'] ?? 0),
                    'name' => $originalName,
                    'mime_type' => $mime,
                    'size_bytes' => (int) ($attRow['size_bytes'] ?? 0),
                    'view_url' => $fileUrl,
                    'download_url' => $fileUrl,
                    'is_image' => $isImage,
                    'type_label' => $isImage ? 'Image' : ($ext === 'PDF' ? 'PDF' : $ext),
                ];
            }
        }

        if (!paymentVoucherHasLinkedQuotation($pvRow)) {
            continue;
        }

        $lines = fetchStockPurchaseVoucherQuotationLinesForPo(
            $pdo,
            $pvRow,
            $company_id,
            $productByLinkedSalesId
        );
        if ($lines !== []) {
            foreach ($lines as &$lineRow) {
                $salesProductId = (int) ($lineRow['sales_product_id'] ?? 0);
                $salesImageFile = trim((string) ($lineRow['sales_product_image'] ?? ''));
                $lineRow['sales_product_image_url'] = ($salesProductId > 0 && function_exists('stock_product_list_image_url'))
                    ? stock_product_list_image_url($salesProductId, $salesImageFile, 'medium', (string) ($stockBasePath ?? ''))
                    : '';
            }
            unset($lineRow);
            $voucherSalesOrderItemsMap[$voucherId] = $lines;
        }
    }
} catch (Throwable $e) {
    $voucherSalesOrderItemsMap = [];
    $voucherAttachmentCounts = [];
    $voucherAttachmentsMap = [];
}

// Catalogue shortcut (use Sales catalogue)
$returnUrl = $_SERVER['REQUEST_URI'] ?? '/stock/modules/purchases/create.php';
$cataloguePath = 'modules/sales/catalogue.php?doc=purchase&return=' . urlencode($returnUrl);
$catalogueUrl = function_exists('app_url') ? app_url($cataloguePath) : ('/' . ltrim($cataloguePath, '/'));

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

$poCurrencyOptions = [];
if (function_exists('expenses_currency_catalog')) {
    foreach (expenses_currency_catalog() as $currencyOpt) {
        $code = strtoupper((string) ($currencyOpt['iso'] ?? ''));
        if ($code === '' || isset($poCurrencyOptions[$code])) {
            continue;
        }
        $poCurrencyOptions[$code] = [
            'name' => function_exists('expenses_currency_name') ? expenses_currency_name($code) : $code,
            'flag' => function_exists('expenses_currency_flag_country') ? expenses_currency_flag_country($code) : '',
            'flag_url' => (string) ($currencyOpt['flag_url'] ?? ''),
            'symbol' => getCurrencySymbol($code),
        ];
    }
}
if ($poCurrencyOptions === []) {
    foreach (['TZS', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'RWF', 'ZAR', 'CNY', 'INR', 'AED', 'SAR', 'JPY', 'CHF', 'CAD', 'AUD', 'SGD', 'NGN', 'GHS', 'ZMW', 'MZN', 'EGP', 'QAR'] as $fallbackCode) {
        $poCurrencyOptions[$fallbackCode] = [
            'name' => $fallbackCode,
            'flag' => strtolower(substr($fallbackCode, 0, 2)),
            'flag_url' => function_exists('expenses_currency_flag_url') ? expenses_currency_flag_url($fallbackCode) : '',
            'symbol' => getCurrencySymbol($fallbackCode),
        ];
    }
}
$allowedCurrencies = array_keys($poCurrencyOptions);
if (!in_array($selectedCurrencyCode, $allowedCurrencies, true)) {
    $selectedCurrencyCode = in_array($companyCurrency, $allowedCurrencies, true) ? $companyCurrency : (array_key_first($poCurrencyOptions) ?: 'USD');
}
$selectedCurrencyMeta = $poCurrencyOptions[$selectedCurrencyCode] ?? [
    'name' => $selectedCurrencyCode,
    'flag' => function_exists('expenses_currency_flag_country') ? expenses_currency_flag_country($selectedCurrencyCode) : '',
    'flag_url' => function_exists('expenses_currency_flag_url') ? expenses_currency_flag_url($selectedCurrencyCode) : '',
    'symbol' => getCurrencySymbol($selectedCurrencyCode),
];
$poCurrencyFlagUrl = static function (string $countryCode): string {
    $countryCode = strtolower(trim($countryCode));
    if ($countryCode !== '') {
        return 'https://flagcdn.com/w40/' . $countryCode . '.png';
    }
    if (function_exists('expenses_currency_flag_url')) {
        return expenses_currency_flag_url('XXX');
    }

    return 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">'
        . '<circle cx="12" cy="12" r="10" fill="#e2e8f0"/>'
        . '<path d="M8 12h8M12 8v8" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"/>'
        . '</svg>'
    );
};
$usdBotRate = function_exists('stock_po_bot_usd_rate') ? stock_po_bot_usd_rate() : 0.0;
$displayBotPack = function_exists('stock_po_bot_display_rate')
    ? stock_po_bot_display_rate($selectedCurrencyCode)
    : ['rate' => 1.0, 'meta' => null];
$displayBotRate = (float) ($displayBotPack['rate'] ?? 1.0);
$exchangeRateMeta = $displayBotPack['meta'] ?? null;
$rate = function_exists('stock_po_storage_exchange_rate')
    ? stock_po_storage_exchange_rate($selectedCurrencyCode, $displayBotRate, $usdBotRate)
    : ($displayBotRate > 0 ? $displayBotRate : 1.0);
$exchangeRateHint = function_exists('stock_po_exchange_rate_hint')
    ? stock_po_exchange_rate_hint($selectedCurrencyCode, $exchangeRateMeta)
    : 'System exchange rate will be used.';
$exchangeRateApiUrl = function_exists('app_url')
    ? app_url('modules/sales/payments/exchange_rate.php')
    : '/modules/sales/payments/exchange_rate.php';
$currency = getCurrencySymbol($selectedCurrencyCode);

// Clone Logic
$cloned_items = [];
$cloned_po = null;
if (!$isPoClassificationEdit && isset($_GET['clone_from_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM stocks_purchase_orders WHERE id = ?");
    $stmt->execute([$_GET['clone_from_id']]);
    $cloned_po = $stmt->fetch();
    
    if ($cloned_po) {
        $stmtItems = $pdo->prepare("SELECT item_id AS product_id, qty_ordered AS quantity, unit_cost AS unit_price FROM stocks_po_items WHERE po_id = ?");
        $stmtItems->execute([$_GET['clone_from_id']]);
        $cloned_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Classification edit: prefill PO lines and currency (vouchers loaded above)
$classificationPrefillItems = [];
if ($isPoClassificationEdit) {
    $cloned_po = $classificationEditPoRow;
    $classificationPoTable = (string) ($GLOBALS['stockPoClassificationEditPoTable'] ?? ($cloned_po['_po_table'] ?? 'stocks_purchase_orders'));
    $cloned_items = [];
    if ($classificationPoTable === 'stocks_purchase_orders' && function_exists('fetchStockPurchaseOrderDisplayLineItems')) {
        $cloned_items = fetchStockPurchaseOrderDisplayLineItems($pdo, $classificationEditPoId);
    }
    if (empty($cloned_items) && function_exists('fetchStockPurchaseOrderLineItems')) {
        $cloned_items = fetchStockPurchaseOrderLineItems($pdo, $classificationEditPoId, $classificationPoTable);
    }
    foreach ($cloned_items as &$lineRow) {
        $lineRow['unit_price_is_display'] = false;
    }
    unset($lineRow);
    $selectedCurrencyCode = strtoupper((string) ($cloned_po['currency'] ?? $selectedCurrencyCode));
    $storedRate = (float) ($cloned_po['exchange_rate'] ?? 0);
    if ($storedRate <= 0) {
        $storedRate = function_exists('stock_po_storage_exchange_rate')
            ? stock_po_storage_exchange_rate($selectedCurrencyCode, $displayBotRate, $usdBotRate)
            : 1.0;
    }
    $rate = $storedRate;
    $displayBotRate = function_exists('stock_po_display_bot_from_storage_rate')
        ? stock_po_display_bot_from_storage_rate($selectedCurrencyCode, $storedRate, $usdBotRate)
        : $displayBotRate;
    $displayBotPack = function_exists('stock_po_bot_display_rate')
        ? stock_po_bot_display_rate($selectedCurrencyCode)
        : ['rate' => $displayBotRate, 'meta' => null];
    $exchangeRateMeta = $displayBotPack['meta'] ?? null;
    $exchangeRateHint = function_exists('stock_po_exchange_rate_hint')
        ? stock_po_exchange_rate_hint($selectedCurrencyCode, $exchangeRateMeta)
        : $exchangeRateHint;
    $currency = getCurrencySymbol($selectedCurrencyCode);

    $productsByIdForPrefill = [];
    foreach ($products as $prodRow) {
        $productsByIdForPrefill[(int) ($prodRow['id'] ?? 0)] = $prodRow;
    }
    foreach ($cloned_items as $lineRow) {
        $pid = (int) ($lineRow['product_id'] ?? 0);
        $prod = $productsByIdForPrefill[$pid] ?? null;
        $qty = (float) ($lineRow['quantity'] ?? 0);
        $unitUsd = (float) ($lineRow['unit_price'] ?? 0);
        $unitDisplay = $unitUsd * $rate;

        $productName = trim((string) ($lineRow['product_name'] ?? ''));
        if ($productName === '' && $prod) {
            $productName = trim((string) ($prod['name'] ?? ''));
        }
        if ($productName === '' && $pid > 0) {
            try {
                $stmtSi = $pdo->prepare('SELECT name, sku FROM stocks_items WHERE id = ? LIMIT 1');
                $stmtSi->execute([$pid]);
                $siRow = $stmtSi->fetch(PDO::FETCH_ASSOC);
                if ($siRow) {
                    $productName = trim((string) ($siRow['name'] ?? ''));
                    if (empty($lineRow['product_code'])) {
                        $lineRow['product_code'] = trim((string) ($siRow['sku'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
            }
        }
        if ($productName === '') {
            $productName = $pid > 0 ? ('Item #' . $pid) : 'Unknown item';
        }

        $productCode = trim((string) ($lineRow['product_code'] ?? ''));
        if ($productCode === '' && $prod) {
            $productCode = trim((string) ($prod['product_code'] ?? ''));
        }

        $imgProductId = (int) ($lineRow['image_product_id'] ?? 0);
        if ($imgProductId <= 0 && $prod) {
            $imgProductId = (int) ($prod['image_product_id'] ?? $prod['linked_product_id'] ?? $prod['id'] ?? 0);
        }
        if ($imgProductId <= 0 && $pid > 0) {
            $imgProductId = $pid;
        }
        $imgFile = trim((string) ($lineRow['product_image'] ?? $lineRow['main_image'] ?? ''));
        if ($imgFile === '' && $prod) {
            $imgFile = trim((string) ($prod['main_image'] ?? ''));
        }
        $imageUrl = '';
        if ($imgProductId > 0 && function_exists('resolveStockPurchaseLineImageUrl')) {
            $imageUrl = resolveStockPurchaseLineImageUrl($imgProductId, $imgFile);
        }
        if ($imageUrl === '' && $prod) {
            $imageUrl = trim((string) ($prod['image_url'] ?? ''));
        }

        $classificationPrefillItems[] = [
            'product_id' => $pid,
            'product_name' => $productName,
            'product_code' => $productCode,
            'image_url' => $imageUrl,
            'quantity' => $qty,
            'unit_display' => $unitDisplay,
            'line_total' => $qty * $unitDisplay,
        ];
    }
}

$error = '';
if (isset($GLOBALS['classificationEditError']) && (string) $GLOBALS['classificationEditError'] !== '') {
    $error = (string) $GLOBALS['classificationEditError'];
}
if (!$isPoClassificationEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $procurement_journey = PURCHASE_PROC_STANDARD;
    $supplier_link_draft = false;
    $supplier_invoice_no = clean_input($_POST['supplier_invoice_no'] ?? '');
    $selectedCurrencyCode = strtoupper((string) ($_POST['currency'] ?? $selectedCurrencyCode));
    if (!in_array($selectedCurrencyCode, $allowedCurrencies, true)) {
        $selectedCurrencyCode = $companyCurrency;
    }
    $manualDisplayRate = isset($_POST['exchange_rate']) ? (float) $_POST['exchange_rate'] : 0.0;
    $displayBotRate = $manualDisplayRate > 0 ? $manualDisplayRate : $displayBotRate;
    $usdBotRate = function_exists('stock_po_bot_usd_rate') ? stock_po_bot_usd_rate() : $usdBotRate;
    if ($selectedCurrencyCode === 'TZS') {
        $rate = 1.0;
    } else {
        $rate = function_exists('stock_po_storage_exchange_rate')
            ? stock_po_storage_exchange_rate($selectedCurrencyCode, $displayBotRate, $usdBotRate)
            : ($displayBotRate > 0 ? $displayBotRate : 1.0);
    }
    $currency = getCurrencySymbol($selectedCurrencyCode);
    $notes = clean_input($_POST['notes']);
    $terms_conditions = clean_input($_POST['terms_conditions']);
    $tax_percentage = isset($_POST['tax_percentage']) ? floatval($_POST['tax_percentage']) : 0;
    $discount_percentage = isset($_POST['discount_percentage']) ? floatval($_POST['discount_percentage']) : 0;
    if ($discount_percentage < 0) {
        $discount_percentage = 0;
    }
    if ($discount_percentage > 100) {
        $discount_percentage = 100;
    }
    
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
    
    // PO number is assigned inside the transaction (see stock_generate_purchase_order_number).
    
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
    } elseif (empty($payment_voucher_ids) && ($supplier_id === '' || $supplier_id === null)) {
        $error = 'Please select a Supplier.';
    } elseif (count($valid_product_ids) === 0) {
        $error = 'Please add at least one product.';
    } else {
        if (is_array($product_ids)) {
            for ($i = 0; $i < count($product_ids); $i++) {
                if ($product_ids[$i] === '' || $product_ids[$i] === null) {
                    continue;
                }
                $qtyCheck = isset($quantities[$i]) ? floatval($quantities[$i]) : 0;
                $priceCheck = isset($unit_prices[$i]) ? floatval($unit_prices[$i]) : 0;
                if ($qtyCheck <= 0) {
                    $error = 'Each item must have quantity greater than zero.';
                    break;
                }
                if ($priceCheck < 0) {
                    $error = 'Item prices cannot be negative.';
                    break;
                }
            }
        }
        if (!$error) {
        $linkedVouchers = [];
        if (!empty($payment_voucher_ids)) {
            foreach ($payment_voucher_ids as $pvCheckId) {
                $validation = validatePaymentVoucherForStockPoLink($pdo, (int) $pvCheckId, $company_id);
                if (!$validation['ok']) {
                    $error = $validation['message'];
                    break;
                }
                $vrow = $validation['row'];
                if ($vrow) {
                    try {
                        $supplierLookup = $pdo->prepare('
                            SELECT ss.id AS supplier_id
                            FROM stocks_suppliers ss
                            WHERE LOWER(TRIM(ss.name)) = LOWER(TRIM(?))
                            LIMIT 1
                        ');
                        $supplierLookup->execute([trim((string) ($vrow['payee_name'] ?? ''))]);
                        $supplierIdFromPayee = (int) $supplierLookup->fetchColumn();
                        if ($supplierIdFromPayee > 0) {
                            $vrow['supplier_id'] = $supplierIdFromPayee;
                        }
                    } catch (Throwable $e) {
                        // ignore supplier lookup errors
                    }
                    $linkedVouchers[] = $vrow;
                }
            }
            if (!$error) {
                $linkedVoucher = $linkedVouchers[0] ?? null;
                if (!$linkedVoucher) {
                    $error = stockPurchasePoPaymentVoucherLinkErrorMessage();
                } elseif ($supplier_id === '' || $supplier_id === null) {
                    foreach ($linkedVouchers as $voucherRow) {
                        $voucherSupplierId = (int) ($voucherRow['supplier_id'] ?? 0);
                        if ($voucherSupplierId > 0) {
                            $supplier_id = (string) $voucherSupplierId;
                            break;
                        }
                    }
                    if ($supplier_id === '' || $supplier_id === null) {
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
            }
        }

        if (!$error) {
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
                } elseif ($selectedCurrencyCode === 'TZS') {
                    $price_usd = round($price_display, 2);
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
            
            $discount_amount_usd = $subtotal_usd * ($discount_percentage / 100);
            $net_subtotal_usd = max(0, $subtotal_usd - $discount_amount_usd);
            $tax_amount_usd = $net_subtotal_usd * ($tax_percentage / 100);
            $grand_total_usd = $net_subtotal_usd + $tax_amount_usd;
            
            // 2. Insert Header using the live stock PO schema (only insert columns that exist)
            $initialStatus = $supplier_link_draft ? PURCHASE_STATUS_DRAFT : PURCHASE_STATUS_PENDING;
            $poCols = $pdo->query("SHOW COLUMNS FROM stocks_purchase_orders")->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $purchase_id = 0;
            $po_number = '';
            $maxPoNumberAttempts = 5;
            for ($poAttempt = 0; $poAttempt < $maxPoNumberAttempts; $poAttempt++) {
                $po_number = stock_generate_purchase_order_number($pdo);

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
                'discount_percentage' => $discount_percentage,
                'discount_amount' => $discount_amount_usd,
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

                if (in_array('created_at', $poCols, true)) {
                    $insertCols[] = 'created_at';
                    $valueSql[] = 'NOW()';
                }

                $stmt = $pdo->prepare('INSERT INTO stocks_purchase_orders (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $valueSql) . ')');
                try {
                    $stmt->execute($insertVals);
                    $purchase_id = (int) $pdo->lastInsertId();
                    break;
                } catch (PDOException $insertException) {
                    $isDuplicatePo = (string) $insertException->getCode() === '23000'
                        && stripos($insertException->getMessage(), 'po_number') !== false;
                    if ($isDuplicatePo && $poAttempt < $maxPoNumberAttempts - 1) {
                        continue;
                    }
                    throw $insertException;
                }
            }

            if ($purchase_id <= 0) {
                throw new RuntimeException('Could not assign a unique purchase order number. Please try again.');
            }

            if (!empty($payment_voucher_ids)) {
                $pvCols2 = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (in_array('linked_stock_po_id', $pvCols2, true)) {
                    $linkStmt = $pdo->prepare('
                        UPDATE payment_vouchers
                        SET linked_stock_po_id = ?
                        WHERE id = ?
                          AND (linked_stock_po_id IS NULL OR linked_stock_po_id = 0)
                    ');
                    foreach ($payment_voucher_ids as $pvLinkId) {
                        $linkStmt->execute([$purchase_id, $pvLinkId]);
                        if ($linkStmt->rowCount() !== 1) {
                            throw new RuntimeException(stockPurchasePoPaymentVoucherLinkErrorMessage());
                        }
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
                $hasAttachmentsTable = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_attachments'")->fetchColumn();
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
                            if ($hasAttachmentsTable) {
                                $stmtAttach = $pdo->prepare("INSERT INTO stocks_purchase_attachments (purchase_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                                $stmtAttach->execute([$purchase_id, $originalName, 'uploads/purchases/' . $safeName, $fileType, $fileSize]);
                            }
                        }
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['stock_po_create_success'] = [
                'title' => 'Success!',
                'message' => 'Purchase Order created: ' . $po_number,
                'variant' => 'success',
                'po_id' => (int) $purchase_id,
                'po_number' => (string) $po_number,
            ];
            redirect('index.php');
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Error: ' . $e->getMessage();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
        }
    }
    }
}

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$productImagePlaceholder = !empty($stockBasePath)
    ? rtrim((string) $stockBasePath, '/') . '/assets/images/no-image.png'
    : (function_exists('app_url') ? app_url('/stock/assets/images/no-image.png') : '/stock/assets/images/no-image.png');

$base = isset($stockBasePath) && $stockBasePath !== ''
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

$vouchersPayload = [];
foreach (($stockPurchaseVouchers ?? []) as $pv) {
    $pvId = (int) ($pv['id'] ?? 0);
    if ($pvId <= 0) {
        continue;
    }
    $label = function_exists('formatStockPurchasePoVoucherOptionLabel')
        ? formatStockPurchasePoVoucherOptionLabel($pv)
        : trim((string) ($pv['voucher_no'] ?? $pv['pv_number'] ?? ('PV-' . $pvId)));
    $vouchersPayload[] = [
        'id' => $pvId,
        'voucher_no' => (string) ($pv['voucher_no'] ?? $pv['pv_number'] ?? ''),
        'pv_number' => (string) ($pv['pv_number'] ?? $pv['voucher_no'] ?? ''),
        'payee_name' => (string) ($pv['payee_name'] ?? ''),
        'payee_type' => (string) ($pv['payee_type'] ?? ''),
        'supplier_id' => (int) ($pv['supplier_id'] ?? 0),
        'total_amount' => (float) ($pv['total_amount'] ?? 0),
        'currency' => (string) ($pv['currency'] ?? ''),
        'is_paid' => (int) ($pv['is_paid'] ?? 0),
        'date_created' => (string) ($pv['date_created'] ?? ''),
        'prepared_by' => (string) ($pv['prepared_by'] ?? ''),
        'label' => $label,
    ];
}

$voucherLinesPayload = [];
foreach (($voucherSalesOrderItemsMap ?? []) as $vid => $lines) {
    $voucherLinesPayload[(string) (int) $vid] = array_map(static function ($line) {
        return [
            'product_id' => (int) ($line['product_id'] ?? 0),
            'quantity' => (float) ($line['quantity'] ?? 0),
            'unit_price' => (float) ($line['unit_price'] ?? $line['unit_cost'] ?? 0),
            'product_name' => (string) ($line['product_name'] ?? ''),
            'product_code' => (string) ($line['product_code'] ?? ''),
        ];
    }, is_array($lines) ? $lines : []);
}

$clonedItemsPayload = [];
foreach (($cloned_items ?? []) as $it) {
    $clonedItemsPayload[] = [
        'product_id' => (int) ($it['product_id'] ?? $it['item_id'] ?? 0),
        'quantity' => (float) ($it['quantity'] ?? $it['qty_ordered'] ?? 1),
        'unit_price' => (float) ($it['unit_price'] ?? $it['unit_cost'] ?? 0),
    ];
}

$clonedPoPayload = null;
if (!empty($cloned_po) && is_array($cloned_po)) {
    $clonedPoPayload = [
        'id' => (int) ($cloned_po['id'] ?? 0),
        'supplier_id' => (int) ($cloned_po['supplier_id'] ?? 0),
        'notes' => (string) ($cloned_po['notes'] ?? ''),
        'terms_conditions' => (string) ($cloned_po['terms_conditions'] ?? $cloned_po['terms'] ?? ''),
        'tax_percentage' => (float) ($cloned_po['tax_percentage'] ?? 0),
        'discount_percentage' => (float) ($cloned_po['discount_percentage'] ?? 0),
        'currency' => (string) ($cloned_po['currency'] ?? ''),
        'exchange_rate' => (float) ($cloned_po['exchange_rate'] ?? 0),
        'supplier_invoice_no' => (string) ($cloned_po['supplier_invoice_no'] ?? ''),
        'purchase_type' => (string) ($cloned_po['purchase_type'] ?? 'domestic'),
    ];
}

$classPrefillPayload = [];
foreach (($classificationPrefillItems ?? []) as $line) {
    $classPrefillPayload[] = [
        'product_id' => (int) ($line['product_id'] ?? 0),
        'product_name' => (string) ($line['product_name'] ?? ''),
        'product_code' => (string) ($line['product_code'] ?? ''),
        'image_url' => (string) ($line['image_url'] ?? ''),
        'quantity' => (float) ($line['quantity'] ?? 0),
        'unit_price' => (float) ($line['unit_display'] ?? $line['unit_price'] ?? 0),
    ];
}

$productsPayload = [];
foreach (($products ?? []) as $prodRow) {
    $productsPayload[] = [
        'id' => (int) ($prodRow['id'] ?? 0),
        'name' => (string) ($prodRow['name'] ?? ''),
        'product_code' => (string) ($prodRow['product_code'] ?? ''),
        'buying_price' => (float) ($prodRow['buying_price'] ?? $prodRow['unit_price'] ?? 0),
        'unit_price' => (float) ($prodRow['unit_price'] ?? $prodRow['buying_price'] ?? 0),
        'main_image' => (string) ($prodRow['main_image'] ?? ''),
        'image_url' => (string) ($prodRow['image_url'] ?? ''),
        'image_product_id' => (int) ($prodRow['image_product_id'] ?? 0),
        'linked_product_id' => (int) ($prodRow['linked_product_id'] ?? 0),
        'supplier_id' => (int) ($prodRow['supplier_id'] ?? 0),
    ];
}

$purchaseTypeDefault = (isset($_GET['purchase_type']) && (string) $_GET['purchase_type'] === 'import')
    ? 'import'
    : 'domestic';
if (!empty($cloned_po['purchase_type']) && (string) $cloned_po['purchase_type'] === 'import') {
    $purchaseTypeDefault = 'import';
}

$selectedVoucherIds = [];
if ($isPoClassificationEdit) {
    foreach (preg_split('/\s*,\s*/', (string) ($classificationLinkedVoucherIdsRaw ?? '')) as $tok) {
        $n = (int) $tok;
        if ($n > 0) {
            $selectedVoucherIds[] = $n;
        }
    }
}

$formAction = '';
if ($isPoClassificationEdit) {
    $formAction = 'edit_classification.php?id=' . (int) $classificationEditPoId;
}

$indexUrl = 'index.php';
$viewVoucherUrl = function_exists('app_url')
    ? app_url('modules/expenses/payment_voucher_view.php')
    : '/modules/expenses/payment_voucher_view.php';

$page_title = $isPoClassificationEdit
    ? ('Edit Purchase Order - ' . ($classificationPoNumber !== '' ? $classificationPoNumber : ('#' . $classificationEditPoId)))
    : ($purchaseTypeDefault === 'import' ? 'New Abroad Purchase Order' : 'New Internal Purchase Order');
$employeeHeaderTitle = $isPoClassificationEdit ? 'Edit purchase order' : 'New purchase order';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk page-purchase-create-react';

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
    body.page-products-desk .employee-header--products-desk .header-content {
        padding: 0.55rem 0 0.4rem !important;
        gap: 0.4rem;
    }
    body.page-products-desk .employee-header--products-desk .employee-header-page-title {
        max-width: min(100%, 58vw);
        white-space: nowrap;
        font-size: 1.05rem !important;
    }
    main.main-content.products-desk-react-root {
        padding: 0 0.75rem 5.5rem !important;
        overflow-x: hidden !important;
    }
    main.main-content.products-desk-react-root #root {
        min-height: 0;
    }
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
        <div class="alert alert-warning m-3">JavaScript is required to create a purchase order.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'purchases-create',
            'data' => [
                'formAction' => $formAction,
                'indexUrl' => $indexUrl,
                'catalogueUrl' => $catalogueUrl ?? '',
                'baseUrl' => $base,
                'exchangeRateApiUrl' => $exchangeRateApiUrl ?? '',
                'viewVoucherUrl' => $viewVoucherUrl,
                'productImagePlaceholder' => $productImagePlaceholder,
                'suppliers' => $suppliers ?? [],
                'products' => $productsPayload,
                'poCurrencyOptions' => $poCurrencyOptions ?? [],
                'displayBotRate' => isset($displayBotRate) ? (float) $displayBotRate : 1.0,
                'selectedCurrencyCode' => $selectedCurrencyCode ?? 'TZS',
                'currencySymbol' => $currency ?? 'TSh',
                'stockPurchaseVouchers' => $vouchersPayload,
                'voucherSalesOrderItemsMap' => $voucherLinesPayload,
                'voucherPickerHint' => (string) ($stockPurchaseVoucherPickerHint ?? ''),
                'cloned_po' => $clonedPoPayload,
                'cloned_items' => $clonedItemsPayload,
                'classificationPrefillItems' => $classPrefillPayload,
                'isClassificationEdit' => (bool) $isPoClassificationEdit,
                'classificationPoId' => (int) ($classificationEditPoId ?? 0),
                'classificationPoNumber' => (string) ($classificationPoNumber ?? ''),
                'selectedVoucherIds' => $selectedVoucherIds,
                'purchaseTypeDefault' => $purchaseTypeDefault,
                'procurementJourney' => defined('PURCHASE_PROC_STANDARD') ? PURCHASE_PROC_STANDARD : 'standard',
                'suppliersAvailable' => !empty($suppliersAvailable),
                'supplierSetupError' => (string) ($supplierSetupError ?? ''),
                'error' => (string) ($error ?? ''),
                'prefillProductId' => isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0,
                'prefillQty' => isset($_GET['qty']) ? (float) $_GET['qty'] : 0,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"purchases-create","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>