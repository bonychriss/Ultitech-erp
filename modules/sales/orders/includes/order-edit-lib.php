<?php

declare(strict_types=1);

require_once __DIR__ . '/../../invoices/includes/invoices-lib.php';

/**
 * @return array{order:array<string,mixed>,items:list<array<string,mixed>>}
 */
function sales_order_edit_load(int $orderId, bool $allowAnyStatus = false): array
{
    invoicesDeskBootstrap();

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;

    $orderSql = 'SELECT * FROM sales_orders WHERE id = ?';
    $orderParams = [$orderId];
    $orderScope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('sales_orders') : ['', []];
    $orderSql .= $orderScope[0];
    $orderParams = array_merge($orderParams, $orderScope[1]);

    $stmt = $salesDb->prepare($orderSql);
    $stmt->execute($orderParams);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    if (!$allowAnyStatus && !in_array((string) ($order['status'] ?? ''), ['draft', 'quotation'], true)) {
        throw new RuntimeException('This order cannot be edited as it is already ' . ($order['status'] ?? 'processed') . '.');
    }

    $prodCols = [];
    try {
        $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
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

    $stmtItems = $salesDb->prepare("
        SELECT soi.*, p.name AS product_name, p.product_code, $imgSelect
        FROM sales_order_items soi
        LEFT JOIN products p ON soi.product_id = p.id
        WHERE soi.order_id = ?
        ORDER BY soi.id ASC
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stockUploadsBase = function_exists('app_url') ? app_url('/stock/uploads/products') : '/stock/uploads/products';
    foreach ($items as $idx => $itemRow) {
        $pId = (int) ($itemRow['product_id'] ?? 0);
        $mainImage = (string) ($itemRow['main_image'] ?? '');
        if ($pId > 0 && function_exists('sales_product_image_url')) {
            $line = ['product_id' => $pId, 'main_image' => $mainImage];
            if (function_exists('sales_order_item_image_name')) {
                $line['main_image'] = sales_order_item_image_name($line, $salesDb);
            }
            $items[$idx]['image_url'] = sales_product_image_url($pId, (string) ($line['main_image'] ?? ''), 'thumbnail');
        } elseif ($pId > 0 && $mainImage !== '') {
            $items[$idx]['image_url'] = $stockUploadsBase . '/' . $pId . '/thumbnail/' . $mainImage;
        } else {
            $items[$idx]['image_url'] = '';
        }
    }

    return ['order' => $order, 'items' => $items];
}

/**
 * @return array<string, mixed>
 */
function sales_quote_edit_init_data(int $orderId, bool $allowAnyStatus = false): array
{
    $loaded = sales_order_edit_load($orderId, $allowAnyStatus);
    $order = $loaded['order'];
    $items = $loaded['items'];

    $_GET['document'] = 'quote';
    $orderType = strtolower(trim((string) ($order['order_type'] ?? 'spare')));
    if (in_array($orderType, ['truck', 'spare'], true)) {
        $_GET['type'] = $orderType;
    }

    $base = sales_invoice_create_init_data();
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';

    $subtotal = (float) ($order['subtotal'] ?? 0);
    $discountAmount = (float) ($order['discount_amount'] ?? 0);
    $taxAmount = (float) ($order['tax_amount'] ?? 0);
    $taxBase = max(0.0, $subtotal - $discountAmount);
    $taxPercentage = $taxBase > 0 ? round(($taxAmount / $taxBase) * 100, 2) : 18.0;

    $displayCurrencies = [];
    if (!empty($order['display_currencies'])) {
        $decoded = json_decode((string) $order['display_currencies'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $code) {
                $code = strtoupper(trim((string) $code));
                if ($code !== '') {
                    $displayCurrencies[] = $code;
                }
            }
        }
    }
    $primaryCurrency = strtoupper(trim((string) ($order['currency'] ?? '')));
    if ($primaryCurrency === '') {
        $primaryCurrency = $displayCurrencies[0] ?? (string) ($base['default_currency'] ?? 'TZS');
    }
    if ($displayCurrencies === []) {
        $displayCurrencies = [$primaryCurrency];
    }
    if (!in_array($primaryCurrency, $displayCurrencies, true)) {
        array_unshift($displayCurrencies, $primaryCurrency);
    }

    $currencyRates = ['TZS' => '1.0000'];
    if (!empty($order['currency_rates'])) {
        $decodedRates = json_decode((string) $order['currency_rates'], true);
        if (is_array($decodedRates)) {
            foreach ($decodedRates as $code => $rate) {
                $code = strtoupper(trim((string) $code));
                if ($code !== '') {
                    $currencyRates[$code] = number_format(max(0.0, (float) $rate), 4, '.', '');
                }
            }
        }
    } elseif ($primaryCurrency !== 'TZS' && (float) ($order['exchange_rate'] ?? 0) > 0) {
        $currencyRates[$primaryCurrency] = number_format((float) $order['exchange_rate'], 4, '.', '');
    }

    $mappedItems = [];
    foreach ($items as $itemRow) {
        if ((int) ($itemRow['product_id'] ?? 0) <= 0) {
            continue;
        }
        $mappedItems[] = [
            'product_id' => (int) $itemRow['product_id'],
            'product_name' => (string) ($itemRow['product_name'] ?? ''),
            'product_code' => (string) ($itemRow['product_code'] ?? ''),
            'quantity' => (float) ($itemRow['quantity'] ?? 0),
            'unit_price' => (float) ($itemRow['unit_price'] ?? 0),
            'discount' => (float) ($itemRow['discount_percentage'] ?? 0),
            'tax_percent' => $taxPercentage,
            'line_total' => (float) ($itemRow['line_total'] ?? 0),
            'description' => (string) ($itemRow['description'] ?? ''),
            'image_url' => (string) ($itemRow['image_url'] ?? ''),
        ];
    }

    $quoteDate = !empty($order['quote_date']) ? date('Y-m-d', strtotime((string) $order['quote_date'])) : date('Y-m-d');
    $validUntil = !empty($order['valid_until']) ? date('Y-m-d', strtotime((string) $order['valid_until'])) : '';

    $base['mode'] = 'edit';
    $base['order_id'] = $orderId;
    $base['order'] = [
        'id' => $orderId,
        'order_number' => (string) ($order['order_number'] ?? ''),
        'customer_id' => (int) ($order['customer_id'] ?? 0),
        'quote_date' => $quoteDate,
        'valid_until' => $validUntil,
        'lead_time' => $order['lead_time'] !== null && $order['lead_time'] !== '' ? (string) $order['lead_time'] : '',
        'order_type' => in_array($orderType, ['truck', 'spare'], true) ? $orderType : 'spare',
        'status' => (string) ($order['status'] ?? 'quotation'),
        'created_by' => (int) ($order['created_by'] ?? 0),
        'discount_amount' => $discountAmount,
        'tax_amount' => $taxAmount,
        'tax_percentage' => $taxPercentage,
        'shipping_charges' => (float) ($order['shipping_charges'] ?? 0),
        'subtotal' => $subtotal,
        'total_amount' => (float) ($order['total_amount'] ?? 0),
        'currency' => $primaryCurrency,
        'display_currencies' => $displayCurrencies,
        'currency_rates' => $currencyRates,
        'terms_conditions' => (string) ($order['terms_conditions'] ?? $order['notes'] ?? ''),
    ];
    $base['order_items'] = $mappedItems;
    $base['page_title'] = 'Edit Quotation: ' . ($order['order_number'] ?? ('#' . $orderId));
    $base['submit_label'] = 'Save Changes';
    $base['view_url'] = sales_module_url('orders/view.php', ['id' => $orderId, 'module' => $module]);
    $base['index_url'] = $base['view_url'];

    return $base;
}

/**
 * @param array<string, mixed> $input
 * @return array{order_id:int,redirect:string}
 */
function sales_process_quote_update(array $input, int $orderId, bool $allowAnyStatus = false): array
{
    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $company_id = (int) (currentCompanyId() ?? 0);

    $loaded = sales_order_edit_load($orderId, $allowAnyStatus);
    $existingOrder = $loaded['order'];

    ensureCustomerColumnsExist();
    ensureSalesOrderMultiCurrencyColumns();
    $salesDb->beginTransaction();

    try {
        $orderType = function_exists('salesNormalizeOrderType')
            ? salesNormalizeOrderType((string) ($input['order_type'] ?? $existingOrder['order_type'] ?? 'spare'))
            : 'spare';

        $quoteCurrencyOptions = sales_invoice_currency_options();
        $displayCurrencies = [];
        if (!empty($input['display_currencies'])) {
            $rawCurrencies = $input['display_currencies'];
            $decodedCurrencies = is_string($rawCurrencies) ? json_decode($rawCurrencies, true) : $rawCurrencies;
            if (is_array($decodedCurrencies)) {
                foreach ($decodedCurrencies as $currencyCode) {
                    $currencyCode = strtoupper(trim((string) $currencyCode));
                    if (isset($quoteCurrencyOptions[$currencyCode]) && !in_array($currencyCode, $displayCurrencies, true)) {
                        $displayCurrencies[] = $currencyCode;
                    }
                }
            }
        }

        $selectedCurrency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($selectedCurrency === '' || !isset($quoteCurrencyOptions[$selectedCurrency])) {
            $selectedCurrency = $displayCurrencies[0] ?? strtoupper(trim((string) ($existingOrder['currency'] ?? 'TZS')));
        }
        if (!in_array($selectedCurrency, $displayCurrencies, true)) {
            array_unshift($displayCurrencies, $selectedCurrency);
        }
        if ($displayCurrencies === []) {
            $displayCurrencies = [$selectedCurrency];
        }
        if (function_exists('sales_order_display_currencies_ordered')) {
            $displayCurrencies = sales_order_display_currencies_ordered($displayCurrencies, $selectedCurrency);
        }

        $currencyRates = ['TZS' => 1.0];
        if (!empty($input['currency_rates'])) {
            $rawRates = $input['currency_rates'];
            $decodedRates = is_string($rawRates) ? json_decode($rawRates, true) : $rawRates;
            if (is_array($decodedRates)) {
                foreach ($decodedRates as $rateCode => $rateValue) {
                    $rateCode = strtoupper(trim((string) $rateCode));
                    if (!isset($quoteCurrencyOptions[$rateCode])) {
                        continue;
                    }
                    $currencyRates[$rateCode] = max(0.0, (float) $rateValue);
                }
            }
        }
        $currencyRates['TZS'] = 1.0;
        $postedExchangeRate = (float) ($currencyRates[$selectedCurrency] ?? ($input['exchange_rate'] ?? 1));
        if ($selectedCurrency === 'TZS') {
            $postedExchangeRate = 1.0;
        } elseif ($postedExchangeRate <= 0) {
            $postedExchangeRate = 1.0;
        }
        $currencyRates[$selectedCurrency] = $postedExchangeRate;

        $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $hasLeadTime = in_array('lead_time', $soCols, true);
        $hasOrderType = in_array('order_type', $soCols, true);
        $hasCurrency = in_array('currency', $soCols, true);
        $hasExchangeRate = in_array('exchange_rate', $soCols, true);
        $hasDisplayCurrencies = in_array('display_currencies', $soCols, true);
        $hasCurrencyRates = in_array('currency_rates', $soCols, true);

        $setParts = [
            'customer_id = ?',
            'quote_date = ?',
            'valid_until = ?',
            'subtotal = ?',
            'discount_amount = ?',
            'tax_amount = ?',
            'shipping_charges = ?',
            'total_amount = ?',
            'status = ?',
            'created_by = ?',
            'updated_at = NOW()',
        ];
        $updateValues = [
            !empty($input['customer_id']) ? $input['customer_id'] : null,
            $input['quote_date'] ?? $existingOrder['quote_date'] ?? date('Y-m-d'),
            $input['valid_until'] ?? $existingOrder['valid_until'] ?? null,
            $input['subtotal'],
            $input['discount_amount'] ?? 0,
            $input['tax_amount'],
            $input['shipping_charges'] ?? 0,
            $input['total_amount'],
            $input['status'] ?? $existingOrder['status'] ?? 'quotation',
            !empty($input['created_by']) ? $input['created_by'] : ($_SESSION['user_id'] ?? $existingOrder['created_by'] ?? null),
        ];

        if ($hasLeadTime) {
            $setParts[] = 'lead_time = ?';
            $updateValues[] = ($input['lead_time'] ?? '') !== '' ? $input['lead_time'] : null;
        }
        if ($hasCurrency) {
            $setParts[] = 'currency = ?';
            $updateValues[] = $selectedCurrency;
        }
        if ($hasExchangeRate) {
            $setParts[] = 'exchange_rate = ?';
            $updateValues[] = $postedExchangeRate;
        }
        if ($hasDisplayCurrencies) {
            $setParts[] = 'display_currencies = ?';
            $updateValues[] = json_encode(array_values($displayCurrencies), JSON_UNESCAPED_UNICODE);
        }
        if ($hasCurrencyRates) {
            $setParts[] = 'currency_rates = ?';
            $updateValues[] = json_encode($currencyRates, JSON_UNESCAPED_UNICODE);
        }
        if ($hasOrderType) {
            $setParts[] = 'order_type = ?';
            $updateValues[] = $orderType;
        }

        $updateSql = 'UPDATE sales_orders SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $orderScope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('sales_orders') : ['', []];
        if (!empty($orderScope[0])) {
            $updateSql .= str_replace(' AND ', ' AND ', $orderScope[0]);
        }
        $updateValues[] = $orderId;
        $updateValues = array_merge($updateValues, $orderScope[1]);

        $stmt = $salesDb->prepare($updateSql);
        $stmt->execute($updateValues);

        $salesDb->prepare('DELETE FROM sales_order_items WHERE order_id = ?')->execute([$orderId]);

        if (isset($input['items']) && is_array($input['items'])) {
            $soiCols = $salesDb->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $hasItemDesc = in_array('description', $soiCols, true);
            $hasItemCompanyId = in_array('company_id', $soiCols, true);
            $itemFields = ['order_id', 'product_id', 'quantity', 'unit_price', 'discount_percentage', 'line_total'];
            if ($hasItemDesc) {
                $itemFields[] = 'description';
            }
            if ($hasItemCompanyId) {
                array_splice($itemFields, 1, 0, ['company_id']);
            }
            $itemSql = 'INSERT INTO sales_order_items (' . implode(', ', $itemFields) . ') VALUES (' . implode(', ', array_fill(0, count($itemFields), '?')) . ')';
            $stmtItem = $salesDb->prepare($itemSql);

            foreach ($input['items'] as $item) {
                if (!empty($item['product_id']) && (float) ($item['quantity'] ?? 0) > 0) {
                    $itemValues = [$orderId];
                    if ($hasItemCompanyId) {
                        $itemValues[] = $company_id;
                    }
                    $itemValues = array_merge($itemValues, [
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['discount'] ?? 0,
                        $item['line_total'] ?? ((float) $item['quantity'] * (float) $item['unit_price']),
                    ]);
                    if ($hasItemDesc) {
                        $itemValues[] = $item['description'] ?? '';
                    }
                    $stmtItem->execute($itemValues);
                }
            }
        }

        $salesDb->commit();

        $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
        $redirect = sales_module_url('orders/view.php', ['id' => $orderId, 'module' => $module]);

        return [
            'order_id' => $orderId,
            'redirect' => $redirect,
        ];
    } catch (Throwable $e) {
        if ($salesDb->inTransaction()) {
            $salesDb->rollBack();
        }
        throw $e;
    }
}

function sales_invoice_edit_require_admin(): void
{
    if (!function_exists('isAdmin') || !isAdmin()) {
        throw new RuntimeException('Only an admin can edit this invoice.');
    }
}

/**
 * @return array<string, mixed>
 */
function sales_invoice_edit_init_data(int $invoiceId): array
{
    sales_invoice_edit_require_admin();

    $viewLib = dirname(__DIR__, 2) . '/invoices/includes/invoices-view-lib.php';
    if (is_file($viewLib)) {
        require_once $viewLib;
    }

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    if (!$salesDb instanceof PDO || !function_exists('salesInvoiceViewLoadInvoice')) {
        throw new RuntimeException('Invoice editor is unavailable.');
    }

    $loadedInvoice = salesInvoiceViewLoadInvoice($salesDb, $invoiceId);
    if ($loadedInvoice === null) {
        throw new RuntimeException('Invoice not found.');
    }
    $invoice = $loadedInvoice['invoice'];
    $status = strtolower(trim((string) ($invoice['status'] ?? '')));
    if ($status === 'cancelled') {
        throw new RuntimeException('A cancelled invoice cannot be edited.');
    }

    $orderId = (int) ($invoice['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('This invoice has no linked order to edit.');
    }

    $base = sales_quote_edit_init_data($orderId, true);
    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
    $invoiceDate = !empty($invoice['invoice_date'])
        ? date('Y-m-d', strtotime((string) $invoice['invoice_date']))
        : (string) ($base['order']['quote_date'] ?? date('Y-m-d'));
    $dueDate = !empty($invoice['due_date'])
        ? date('Y-m-d', strtotime((string) $invoice['due_date']))
        : '';

    $subtotal = (float) ($invoice['subtotal'] ?? ($base['order']['subtotal'] ?? 0));
    $discountAmount = (float) ($invoice['discount_amount'] ?? ($base['order']['discount_amount'] ?? 0));
    $taxAmount = (float) ($invoice['tax_amount'] ?? ($base['order']['tax_amount'] ?? 0));
    $taxBase = max(0.0, $subtotal - $discountAmount);
    $taxPercentage = $taxBase > 0 ? round(($taxAmount / $taxBase) * 100, 2) : (float) ($base['order']['tax_percentage'] ?? 18);

    $number = (string) ($loadedInvoice['display_invoice_number'] ?? ($invoice['invoice_number'] ?? ''));
    $base['document_type'] = 'invoice';
    $base['mode'] = 'edit';
    $base['invoice_id'] = $invoiceId;
    $base['order_id'] = $orderId;
    $base['next_invoice_number'] = $number;
    $base['page_title'] = 'Edit Invoice: ' . ($number !== '' ? $number : ('#' . $invoiceId));
    $base['submit_label'] = 'Save invoice';
    $base['view_url'] = sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => $module]);
    $base['index_url'] = $base['view_url'];
    $base['order']['customer_id'] = (int) ($invoice['customer_id'] ?? ($base['order']['customer_id'] ?? 0));
    $base['order']['quote_date'] = $invoiceDate;
    $base['order']['due_date'] = $dueDate;
    $base['order']['subtotal'] = $subtotal;
    $base['order']['discount_amount'] = $discountAmount;
    $base['order']['tax_amount'] = $taxAmount;
    $base['order']['tax_percentage'] = $taxPercentage;
    $base['order']['shipping_charges'] = (float) ($invoice['shipping_charges'] ?? ($base['order']['shipping_charges'] ?? 0));
    $base['order']['total_amount'] = (float) ($invoice['total_amount'] ?? ($base['order']['total_amount'] ?? 0));
    $base['order']['status'] = (string) ($base['order']['status'] ?? '');

    return $base;
}

/**
 * @param array<string, mixed> $input
 * @return array{invoice_id:int,redirect:string}
 */
function sales_process_invoice_update(array $input, int $invoiceId): array
{
    sales_invoice_edit_require_admin();

    $viewLib = dirname(__DIR__, 2) . '/invoices/includes/invoices-view-lib.php';
    if (is_file($viewLib)) {
        require_once $viewLib;
    }

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    if (!$salesDb instanceof PDO || !function_exists('salesInvoiceViewLoadInvoice')) {
        throw new RuntimeException('Invoice editor is unavailable.');
    }

    $loadedInvoice = salesInvoiceViewLoadInvoice($salesDb, $invoiceId);
    if ($loadedInvoice === null) {
        throw new RuntimeException('Invoice not found.');
    }
    $invoice = $loadedInvoice['invoice'];
    if (strtolower(trim((string) ($invoice['status'] ?? ''))) === 'cancelled') {
        throw new RuntimeException('A cancelled invoice cannot be edited.');
    }
    $orderId = (int) ($invoice['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new RuntimeException('This invoice has no linked order to edit.');
    }

    $loadedOrder = sales_order_edit_load($orderId, true);
    $existingOrder = $loadedOrder['order'];
    $input['status'] = (string) ($existingOrder['status'] ?? 'confirmed');
    $input['quote_date'] = (string) ($existingOrder['quote_date'] ?? date('Y-m-d'));
    $input['valid_until'] = (string) ($existingOrder['valid_until'] ?? '');

    $result = sales_process_quote_update($input, $orderId, true);

    $invCols = [];
    try {
        $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $invCols = [];
    }
    $colNames = [];
    $generated = [];
    foreach ($invCols as $col) {
        $name = (string) ($col['Field'] ?? '');
        if ($name === '') {
            continue;
        }
        $colNames[] = $name;
        $extra = strtoupper((string) ($col['Extra'] ?? ''));
        if (str_contains($extra, 'GENERATED') || str_contains($extra, 'VIRTUAL') || str_contains($extra, 'STORED')) {
            $generated[$name] = true;
        }
    }

    $invoiceDate = trim((string) ($input['invoice_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
        $invoiceDate = !empty($invoice['invoice_date'])
            ? date('Y-m-d', strtotime((string) $invoice['invoice_date']))
            : date('Y-m-d');
    }
    $dueDate = trim((string) ($input['due_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $dueDate = !empty($invoice['due_date']) ? date('Y-m-d', strtotime((string) $invoice['due_date'])) : $invoiceDate;
    }

    $totalAmount = (float) ($input['total_amount'] ?? 0);
    $paid = (float) ($invoice['amount_paid'] ?? 0);
    $sets = [
        'customer_id' => !empty($input['customer_id']) ? (int) $input['customer_id'] : (int) ($invoice['customer_id'] ?? 0),
        'invoice_date' => $invoiceDate,
        'due_date' => $dueDate,
        'subtotal' => (float) ($input['subtotal'] ?? 0),
        'discount_amount' => (float) ($input['discount_amount'] ?? 0),
        'tax_amount' => (float) ($input['tax_amount'] ?? 0),
        'shipping_charges' => (float) ($input['shipping_charges'] ?? 0),
        'total_amount' => $totalAmount,
    ];
    if (!isset($generated['balance_due'])) {
        $sets['balance_due'] = max(0, round($totalAmount - $paid, 2));
    }
    $orderType = strtolower(trim((string) ($input['order_type'] ?? '')));
    if (in_array($orderType, ['truck', 'spare'], true)) {
        $sets['order_type'] = $orderType;
    }

    $setParts = [];
    $values = [];
    foreach ($sets as $column => $value) {
        if (!in_array($column, $colNames, true) || isset($generated[$column])) {
            continue;
        }
        $setParts[] = $column . ' = ?';
        $values[] = $value;
    }
    if ($setParts !== []) {
        $updateSql = 'UPDATE invoices SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $values[] = $invoiceId;
        $scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('invoices') : ['', []];
        if (!empty($scope[0])) {
            $updateSql .= $scope[0];
            $values = array_merge($values, $scope[1]);
        }
        $salesDb->prepare($updateSql)->execute($values);
    }

    if (function_exists('syncInvoiceToRevenue') || is_file(dirname(__DIR__, 4) . '/includes/revenue_sync.php')) {
        $syncFile = dirname(__DIR__, 4) . '/includes/revenue_sync.php';
        if (!function_exists('syncInvoiceToRevenue') && is_file($syncFile)) {
            require_once $syncFile;
        }
        if (function_exists('syncInvoiceToRevenue')) {
            try {
                syncInvoiceToRevenue($salesDb, $invoiceId);
            } catch (Throwable $e) {
            }
        }
    }

    $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';

    return [
        'invoice_id' => $invoiceId,
        'order_id' => (int) ($result['order_id'] ?? $orderId),
        'redirect' => sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => $module]),
    ];
}

function salesInvoiceEditRenderReactShell(int $invoiceId): void
{
    try {
        $init = sales_invoice_edit_init_data($invoiceId);
    } catch (Throwable $e) {
        http_response_code(400);
        echo htmlspecialchars($e->getMessage());
        exit;
    }

    $pageTitle = (string) ($init['page_title'] ?? 'Edit Invoice');
    salesDocumentCreateRenderReactShell($pageTitle, 'invoice_edit', 'invoice', (int) ($init['order_id'] ?? 0));
}

function salesOrderEditRenderReactShell(int $orderId): void
{
    try {
        $init = sales_quote_edit_init_data($orderId);
    } catch (Throwable $e) {
        http_response_code(400);
        echo htmlspecialchars($e->getMessage());
        exit;
    }

    $pageTitle = (string) ($init['page_title'] ?? 'Edit Quotation');
    salesDocumentCreateRenderReactShell($pageTitle, 'quote_edit', 'quote', $orderId);
}
