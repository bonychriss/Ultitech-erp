<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
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
$company_id = stockPurchaseActiveCompanyId();

ensurePurchaseWorkflowSchema($pdo);
ensureStocksPurchaseOrdersWorkflowColumns($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

try {
    $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_type', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN purchase_type ENUM('domestic','import') NOT NULL DEFAULT 'domestic' AFTER supplier_id");
    }
    if (!in_array('supplier_invoice_no', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN supplier_invoice_no VARCHAR(50) NULL AFTER purchase_type");
    }
} catch (Exception $e) {
}

$poCols = [];
$poItemCols = [];
$supplierCols = [];
try {
    $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $poCols = [];
}
try {
    $poItemCols = $pdo->query('SHOW COLUMNS FROM stocks_po_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $poItemCols = [];
}
try {
    $supplierCols = $pdo->query('SHOW COLUMNS FROM stocks_suppliers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $supplierCols = [];
}
$hasPoCompanyId = in_array('company_id', $poCols, true);
$hasPoItemsCompanyId = in_array('company_id', $poItemCols, true);
$hasSupplierCompanyId = in_array('company_id', $supplierCols, true);
$scopePoItemsByCompany = $hasPoItemsCompanyId && $company_id > 0;

$po = loadStockPurchaseOrderForAccess($pdo, $id, $company_id, false);

if (!$po) {
    flash('success', 'Purchase order not found.', 'error');
    redirect('index.php');
}

enrichPurchaseOrderSupplierDisplay($po, $pdo, $company_id);

if (($po['_po_table'] ?? 'stocks_purchase_orders') === 'purchases') {
    require __DIR__ . '/edit_legacy_supplier.inc.php';
}

$poWhere = 'id = ?';
$poWhereParams = [$id];

$rowWf = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
$editableStatuses = purchaseOrderAllEditAccessStatuses($rowWf);
$pricesLocked = arePurchaseOrderPricesLocked($po['status'] ?? '');

if (!in_array($po['status'] ?? '', $editableStatuses, true)) {
    flash('success', 'This order can no longer be edited from this screen.', 'error');
    redirect('index.php');
}

$stmtItemsSql = '
    SELECT pi.id AS line_id, pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price, pi.qty_received
    FROM stocks_po_items pi
    WHERE pi.po_id = ?';
$stmtItemsParams = [$id];
$stmtItemsSql .= stockPurchaseCompanyScopeSql('pi.company_id', $scopePoItemsByCompany, $company_id, $stmtItemsParams);
$stmtItemsSql .= '
    ORDER BY pi.id ASC
';
$stmtItems = $pdo->prepare($stmtItemsSql);
$stmtItems->execute($stmtItemsParams);
$existing_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$suppliers = [];
try {
    $supSql = 'SELECT * FROM stocks_suppliers';
    $supParams = [];
    if ($hasSupplierCompanyId) {
        $supSql .= ' WHERE company_id = ?';
        $supParams[] = $company_id;
    }
    $supSql .= ' ORDER BY name ASC';
    $stmtSuppliers = $pdo->prepare($supSql);
    $stmtSuppliers->execute($supParams);
    $suppliers = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($suppliers !== [] && function_exists('stockPurchaseEnrichSupplierRecord')) {
        $suppliers = array_map('stockPurchaseEnrichSupplierRecord', $suppliers);
    }
} catch (Exception $e) {
    $suppliers = [];
}

$productCols = [];
try {
    $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $productCols = [];
}

$productImageCol = in_array('main_image', $productCols, true) ? 'main_image' : (in_array('image', $productCols, true) ? 'image' : null);
$productBuyingPriceCol = in_array('buying_price', $productCols, true) ? 'buying_price' : (in_array('cost_price', $productCols, true) ? 'cost_price' : 'unit_price');
$productSupplierCol = in_array('supplier_id', $productCols, true) ? 'supplier_id' : null;
$stocksItemCols = [];
try {
    $stocksItemCols = $pdo->query('SHOW COLUMNS FROM stocks_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $stocksItemCols = [];
}
$hasStocksItemCompanyId = in_array('company_id', $stocksItemCols, true);

$productImageSelect = $productImageCol ? "`$productImageCol` AS main_image" : 'NULL AS main_image';
$productBuyingPriceSelect = "`$productBuyingPriceCol` AS buying_price";
$productSupplierSelect = $productSupplierCol ? "`$productSupplierCol` AS supplier_id" : 'NULL AS supplier_id';

$products = $pdo->query("
    SELECT
        si.id,
        si.name,
        COALESCE(
            (SELECT p.product_code FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
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
            (SELECT $productBuyingPriceSelect FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
            0
        ) AS unit_price,
        COALESCE(
            (SELECT $productSupplierSelect FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
            NULL
        ) AS supplier_id,
        COALESCE(
            (SELECT $productImageSelect FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
            ''
        ) AS main_image
    FROM stocks_items si
    " . ($hasStocksItemCompanyId ? ("WHERE si.company_id = " . (int) $company_id) : "") . "
    ORDER BY si.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as &$prodRow) {
    $imgProductId = (int) ($prodRow['image_product_id'] ?? $prodRow['linked_product_id'] ?? 0);
    $imgFile = trim((string) ($prodRow['main_image'] ?? ''));
    $prodRow['buying_price'] = (float) ($prodRow['unit_price'] ?? 0);
    $prodRow['image_url'] = ($imgProductId > 0 && function_exists('stock_product_list_image_url'))
        ? stock_product_list_image_url($imgProductId, $imgFile, 'medium', (string) ($stockBasePath ?? ''))
        : '';
}
unset($prodRow);
$productImagePlaceholder = function_exists('app_url')
    ? app_url('/stock/assets/images/no-image.png')
    : '/stock/assets/images/no-image.png';

$settings = getCompanySettings($pdo);
$defaultCurrencyCode = strtoupper((string) ($settings['currency'] ?? 'USD'));
$poCurrencyCode = strtoupper((string) ($po['currency'] ?? $defaultCurrencyCode));
$selectedCurrencyCode = $poCurrencyCode !== '' ? $poCurrencyCode : $defaultCurrencyCode;

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
    $selectedCurrencyCode = in_array($defaultCurrencyCode, $allowedCurrencies, true) ? $defaultCurrencyCode : (array_key_first($poCurrencyOptions) ?: 'USD');
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

$poStorageRate = isset($po['exchange_rate']) ? (float) $po['exchange_rate'] : 0.0;
if ($poStorageRate > 0 && function_exists('stock_po_display_bot_from_storage_rate')) {
    $displayBotRate = stock_po_display_bot_from_storage_rate($selectedCurrencyCode, $poStorageRate, $usdBotRate);
}

$storageRate = $poStorageRate > 0
    ? $poStorageRate
    : (function_exists('stock_po_storage_exchange_rate')
        ? stock_po_storage_exchange_rate($selectedCurrencyCode, $displayBotRate, $usdBotRate)
        : ($displayBotRate > 0 ? $displayBotRate : 1.0));
$exchangeRateHint = function_exists('stock_po_exchange_rate_hint')
    ? stock_po_exchange_rate_hint($selectedCurrencyCode, $exchangeRateMeta)
    : 'Bank of Tanzania (BOT) mean rate per 1 unit vs TZS. Updates when you change currency.';
$exchangeRateApiUrl = function_exists('app_url')
    ? app_url('modules/sales/payments/exchange_rate.php')
    : '/modules/sales/payments/exchange_rate.php';
$currency = getCurrencySymbol($selectedCurrencyCode);

$companyProfile = resolveStockPurchaseCompanyProfile($pdo, $company_id);
$defaultTermsConditions = trim((string) ($companyProfile['terms_and_conditions'] ?? ''));
$poTermsConditions = trim((string) ($po['terms_conditions'] ?? ''));

$poDateColumn = null;
foreach (['purchase_date', 'order_date', 'po_date'] as $col) {
    if (in_array($col, $poCols, true)) {
        $poDateColumn = $col;
        break;
    }
}
if ($poDateColumn === null && in_array('created_at', $poCols, true)) {
    $poDateColumn = 'created_at';
}
$poDateVal = date('Y-m-d');
if ($poDateColumn !== null && !empty($po[$poDateColumn])) {
    $poDateVal = date('Y-m-d', strtotime((string) $po[$poDateColumn]));
} elseif (!empty($po['created_at'])) {
    $poDateVal = date('Y-m-d', strtotime((string) $po['created_at']));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'] ?? '';
    $supplier_invoice_no = clean_input($_POST['supplier_invoice_no'] ?? '');
    $purchase_order_date_raw = trim((string) ($_POST['purchase_order_date'] ?? ''));
    $purchase_order_date = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_order_date_raw) === 1) ? $purchase_order_date_raw : '';
    $terms_conditions = trim((string) ($_POST['terms_conditions'] ?? ''));
    $tax_percentage = isset($_POST['tax_percentage']) ? (float) $_POST['tax_percentage'] : 0.0;
    $postedCurrencyCode = strtoupper(trim((string) ($_POST['currency_code'] ?? $selectedCurrencyCode)));
    if (!in_array($postedCurrencyCode, $allowedCurrencies, true)) {
        $postedCurrencyCode = $selectedCurrencyCode;
    }
    $manualDisplayRate = isset($_POST['exchange_rate']) ? (float) $_POST['exchange_rate'] : 0.0;
    $displayBotRatePost = $manualDisplayRate > 0 ? $manualDisplayRate : $displayBotRate;
    $usdBotRatePost = function_exists('stock_po_bot_usd_rate') ? stock_po_bot_usd_rate() : $usdBotRate;
    if ($postedCurrencyCode === 'TZS') {
        $postedRate = 1.0;
    } else {
        $postedRate = function_exists('stock_po_storage_exchange_rate')
            ? stock_po_storage_exchange_rate($postedCurrencyCode, $displayBotRatePost, $usdBotRatePost)
            : ($displayBotRatePost > 0 ? $displayBotRatePost : 1.0);
    }
    if ($postedRate <= 0) {
        $postedRate = 1.0;
    }
    if ($tax_percentage < 0) $tax_percentage = 0.0;
    if ($tax_percentage > 100) $tax_percentage = 100.0;
    $line_ids = $_POST['line_id'] ?? [];
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];

    $validRows = [];
    if (is_array($product_ids)) {
        for ($i = 0; $i < count($product_ids); $i++) {
            if ($product_ids[$i] === '' || $product_ids[$i] === null) {
                continue;
            }
            $validRows[] = [
                'line_id' => isset($line_ids[$i]) && $line_ids[$i] !== '' ? (int) $line_ids[$i] : null,
                'item_id' => (int) $product_ids[$i],
                'qty' => isset($quantities[$i]) ? (float) $quantities[$i] : 0,
                'price_display' => isset($unit_prices[$i]) ? (float) $unit_prices[$i] : 0,
            ];
        }
    }

    if ($supplier_id === '' || $supplier_id === null) {
        $error = 'Please select a supplier.';
    } elseif ($purchase_order_date === '') {
        $error = 'Please enter a valid PO date.';
    } elseif (count($validRows) === 0) {
        $error = 'Please add at least one line item.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtPo = $pdo->prepare('SELECT * FROM stocks_purchase_orders WHERE ' . $poWhere . ' FOR UPDATE');
            $stmtPo->execute($poWhereParams);
            $poRow = $stmtPo->fetch();
            if (!$poRow || !in_array($poRow['status'] ?? '', purchaseOrderAllEditAccessStatuses($poRow['procurement_workflow'] ?? PURCHASE_PROC_STANDARD), true)) {
                throw new Exception('This order is no longer editable.');
            }
            $pricesLockedPost = arePurchaseOrderPricesLocked($poRow['status'] ?? '');
            // Allow currency and exchange rate changes even when unit prices are locked.
            $postedRate = $postedCurrencyCode === 'TZS' ? 1.0 : $postedRate;

            $stmtExSql = 'SELECT * FROM stocks_po_items WHERE po_id = ?';
            $stmtExParams = [$id];
            $stmtExSql .= stockPurchaseCompanyScopeSql('company_id', $scopePoItemsByCompany, $company_id, $stmtExParams);
            $stmtEx = $pdo->prepare($stmtExSql);
            $stmtEx->execute($stmtExParams);
            $existingDb = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
            $existingById = [];
            foreach ($existingDb as $row) {
                $existingById[(int) $row['id']] = $row;
            }

            $postedLineIds = [];
            foreach ($validRows as $r) {
                if ($r['line_id']) {
                    $postedLineIds[$r['line_id']] = true;
                }
            }

            foreach ($existingDb as $ex) {
                $lid = (int) $ex['id'];
                if (isset($postedLineIds[$lid])) {
                    continue;
                }
                if ((float) ($ex['qty_received'] ?? 0) > 0) {
                    throw new Exception('Cannot remove a line that already has received quantity.');
                }
                $delSql = 'DELETE FROM stocks_po_items WHERE id = ? AND po_id = ?';
                $delParams = [$lid, $id];
                $delSql .= stockPurchaseCompanyScopeSql('company_id', $scopePoItemsByCompany, $company_id, $delParams);
                $pdo->prepare($delSql)->execute($delParams);
                unset($existingById[$lid]);
            }

            foreach ($validRows as $r) {
                if ($r['qty'] <= 0) {
                    throw new Exception('Quantities must be greater than zero.');
                }
                if ($pricesLockedPost && $r['line_id'] && isset($existingById[$r['line_id']])) {
                    $unitStored = (float) ($existingById[$r['line_id']]['unit_cost'] ?? 0);
                } elseif ($postedCurrencyCode === 'TZS') {
                    $unitStored = round((float) $r['price_display'], 2);
                } else {
                    $unitStored = round($r['price_display'] / $postedRate, 8);
                }

                if ($r['line_id'] && isset($existingById[$r['line_id']])) {
                    $ex = $existingById[$r['line_id']];
                    $received = (float) ($ex['qty_received'] ?? 0);
                    if ($r['qty'] < $received) {
                        throw new Exception('Ordered quantity cannot be less than quantity already received.');
                    }
                    if ($received > 0 && (int) $ex['item_id'] !== $r['item_id']) {
                        throw new Exception('Cannot change the product on a line that already has receipts.');
                    }
                    $updSql = 'UPDATE stocks_po_items SET item_id = ?, qty_ordered = ?, unit_cost = ?, landed_cost = ? WHERE id = ? AND po_id = ?';
                    $updParams = [$r['item_id'], $r['qty'], $unitStored, $unitStored, $r['line_id'], $id];
                    $updSql .= stockPurchaseCompanyScopeSql('company_id', $scopePoItemsByCompany, $company_id, $updParams);
                    $pdo->prepare($updSql)->execute($updParams);
                } elseif (!$r['line_id']) {
                    $lineCompanyId = $company_id > 0 ? $company_id : (int) ($poRow['company_id'] ?? 0);
                    if ($hasPoItemsCompanyId) {
                        $pdo->prepare('INSERT INTO stocks_po_items (company_id, po_id, item_id, qty_ordered, qty_received, unit_cost, landed_cost) VALUES (?, ?, ?, ?, 0, ?, ?)')
                            ->execute([$lineCompanyId > 0 ? $lineCompanyId : null, $id, $r['item_id'], $r['qty'], $unitStored, $unitStored]);
                    } else {
                        $pdo->prepare('INSERT INTO stocks_po_items (po_id, item_id, qty_ordered, qty_received, unit_cost, landed_cost) VALUES (?, ?, ?, 0, ?, ?)')
                            ->execute([$id, $r['item_id'], $r['qty'], $unitStored, $unitStored]);
                    }
                } else {
                    throw new Exception('Invalid line reference.');
                }
            }

            // Recalculate totals in base currency (USD) from the posted rows.
            $subtotalUsd = 0.0;
            foreach ($validRows as $r) {
                $qty = (float) ($r['qty'] ?? 0);
                if ($pricesLockedPost && $r['line_id'] && isset($existingById[$r['line_id']])) {
                    $lineUnitStored = (float) ($existingById[$r['line_id']]['unit_cost'] ?? 0);
                } elseif ($postedCurrencyCode === 'TZS') {
                    $lineUnitStored = round((float) ($r['price_display'] ?? 0), 2);
                } else {
                    $lineUnitStored = round(((float) ($r['price_display'] ?? 0)) / $postedRate, 8);
                }
                $subtotalUsd += $qty * $lineUnitStored;
            }
            $taxAmountUsd = $subtotalUsd * ($tax_percentage / 100.0);
            $grandTotalUsd = $subtotalUsd + $taxAmountUsd;

            $sets = ['supplier_id = ?', 'supplier_invoice_no = ?'];
            $vals = [$supplier_id, ($supplier_invoice_no !== '' ? $supplier_invoice_no : null)];

            if (in_array('tax_percentage', $poCols, true)) { $sets[] = 'tax_percentage = ?'; $vals[] = $tax_percentage; }
            if (in_array('tax_amount', $poCols, true)) { $sets[] = 'tax_amount = ?'; $vals[] = $taxAmountUsd; }
            if (in_array('subtotal', $poCols, true)) { $sets[] = 'subtotal = ?'; $vals[] = $subtotalUsd; }
            if (in_array('total_amount', $poCols, true)) { $sets[] = 'total_amount = ?'; $vals[] = $grandTotalUsd; }
            if (in_array('currency', $poCols, true)) { $sets[] = 'currency = ?'; $vals[] = $postedCurrencyCode; }
            if (in_array('exchange_rate', $poCols, true)) { $sets[] = 'exchange_rate = ?'; $vals[] = $postedRate; }
            if (in_array('terms_conditions', $poCols, true)) {
                $sets[] = 'terms_conditions = ?';
                $vals[] = $terms_conditions !== '' ? $terms_conditions : null;
            }
            if ($poDateColumn !== null && $purchase_order_date !== '') {
                if ($poDateColumn === 'created_at') {
                    $existingTime = date('H:i:s', strtotime((string) ($poRow['created_at'] ?? 'now')));
                    $sets[] = 'created_at = ?';
                    $vals[] = $purchase_order_date . ' ' . $existingTime;
                } else {
                    $sets[] = $poDateColumn . ' = ?';
                    $vals[] = $purchase_order_date;
                }
            }
            if (in_array('updated_at', $poCols, true)) { $sets[] = 'updated_at = NOW()'; }

            $vals[] = $id;
            $poUpdateWhere = 'id = ?';
            $pdo->prepare('UPDATE stocks_purchase_orders SET ' . implode(', ', $sets) . ' WHERE ' . $poUpdateWhere)->execute($vals);

            $pdo->commit();
            flash('success', 'Purchase order updated successfully.');
            redirect('view_po.php?id=' . $id);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
    if ($error !== '') {
        $poTermsConditions = trim((string) ($_POST['terms_conditions'] ?? $poTermsConditions));
        if ($purchase_order_date !== '') {
            $poDateVal = $purchase_order_date;
        }
    }
}

$page_title = 'Edit Purchase Order';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
$bodyExtraClass = 'page-purchase-edit-react';
include __DIR__ . '/../../includes/header.php';

$success = $_GET['success'] ?? '';
if ($error === '' && isset($_GET['error'])) {
    $error = (string) $_GET['error'];
}

$poPayload = $po;
$poPayload['purchase_order_date'] = $poDateVal;
$poPayload['terms_conditions'] = $poTermsConditions !== '' ? $poTermsConditions : $defaultTermsConditions;
$assetVersion = @filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: time();

$editPostUrl = $stockBasePath . 'modules/purchases/edit.php?id=' . $id;
if (!empty($_SERVER['QUERY_STRING'])) {
    $editPostUrl = $stockBasePath . 'modules/purchases/edit.php?' . $_SERVER['QUERY_STRING'];
}
?>
<style>
    :root { --bg-body: #f8fafc; }
    body.page-purchase-edit-react.dashboard,
    body.page-purchase-edit-react.dashboard .layout-main-wrapper,
    body.page-purchase-edit-react.dashboard .layout-main-wrapper > .flex-grow-1 {
        background-color: #f8fafc !important;
    }
    body.page-purchase-edit-react.dashboard .header,
    body.page-purchase-edit-react.dashboard .employee-header {
        background: #f8fafc !important;
        border: none !important;
        box-shadow: none !important;
    }
    .main-content.purchase-edit-react-root {
        width: 100% !important;
        max-width: none !important;
        padding: 0.5rem 1.25rem 2.5rem !important;
        box-sizing: border-box;
        background: #f8fafc !important;
    }
    .main-content.purchase-edit-react-root #root { width: 100%; max-width: none; margin: 0; }
    @media (max-width: 1024px) {
        .main-content.purchase-edit-react-root { padding: 1rem 0.875rem 1.5rem !important; }
    }
    @media (max-width: 767.98px) {
        .main-content.purchase-edit-react-root { padding: 0.875rem 0.75rem 1.5rem !important; }
    }
</style>
<main class="main-content purchase-edit-react-root">
    <noscript>
        <div class="alert alert-warning">JavaScript is required to edit this purchase order.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'purchases-edit',
            'data' => [
                'po' => $poPayload,
                'existing_items' => $existing_items,
                'suppliers' => $suppliers,
                'products' => $products,
                'poCurrencyOptions' => $poCurrencyOptions,
                'pricesLocked' => $pricesLocked,
                'displayBotRate' => $displayBotRate,
                'storageRate' => $storageRate,
                'productImagePlaceholder' => $productImagePlaceholder,
                'baseUrl' => $stockBasePath,
                'apiUrl' => $editPostUrl,
                'exchangeRateApiUrl' => $exchangeRateApiUrl,
                'indexUrl' => $stockBasePath . 'modules/purchases/index.php',
                'success' => $success,
                'error' => $error,
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"purchases-edit","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($stockBasePath) ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($stockBasePath) ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
