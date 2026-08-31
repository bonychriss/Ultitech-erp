<?php

declare(strict_types=1);

/**
 * Create a direct sales invoice from POST-style input.
 *
 * @param array<string, mixed> $input
 * @return array{invoice_id:int, redirect:string, stock_deduction?:array<string, mixed>}
 */
function sales_process_direct_invoice_create(array $input): array
{
    require_once dirname(__DIR__, 4) . '/includes/revenue_ledger.php';

    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $company_id = (int) (currentCompanyId() ?? 0);

    ensureCustomerColumnsExist();
    ensureSalesOrderMultiCurrencyColumns();
    $pdo->beginTransaction();

    try {
        $nextNum = getNextOrderNumber();
        $order_number = 'SO-' . date('Y') . '-' . str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);

        $soCols = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $hasLeadTime = in_array('lead_time', $soCols, true);
        $hasShippedAt = in_array('shipped_at', $soCols, true);
        $hasOrderType = in_array('order_type', $soCols, true);
        $hasSoCompanyId = in_array('company_id', $soCols, true);
        $hasCurrency = in_array('currency', $soCols, true);
        $hasExchangeRate = in_array('exchange_rate', $soCols, true);
        $hasDisplayCurrencies = in_array('display_currencies', $soCols, true);
        $hasCurrencyRates = in_array('currency_rates', $soCols, true);

        $invoiceCurrencyOptions = sales_invoice_currency_options();

        $displayCurrencies = [];
        if (!empty($input['display_currencies'])) {
            $rawCurrencies = $input['display_currencies'];
            $decodedCurrencies = is_string($rawCurrencies) ? json_decode($rawCurrencies, true) : $rawCurrencies;
            if (is_array($decodedCurrencies)) {
                foreach ($decodedCurrencies as $currencyCode) {
                    $currencyCode = strtoupper(trim((string) $currencyCode));
                    if (isset($invoiceCurrencyOptions[$currencyCode]) && !in_array($currencyCode, $displayCurrencies, true)) {
                        $displayCurrencies[] = $currencyCode;
                    }
                }
            }
        }

        $orderTypePost = strtolower(trim((string) ($input['order_type'] ?? '')));
        if (!function_exists('salesSupportsTruckInvoices') || !salesSupportsTruckInvoices()) {
            $orderTypePost = 'spare';
        } elseif ($orderTypePost === 'truck' && $displayCurrencies === []) {
            throw new RuntimeException('Please select at least one currency for truck invoices.');
        }

        $selectedCurrency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($selectedCurrency === '' || !isset($invoiceCurrencyOptions[$selectedCurrency])) {
            $selectedCurrency = $displayCurrencies[0] ?? 'TZS';
        }
        if (!in_array($selectedCurrency, $displayCurrencies, true)) {
            array_unshift($displayCurrencies, $selectedCurrency);
        }
        if ($displayCurrencies === []) {
            $displayCurrencies = [$selectedCurrency];
        }

        $displayCurrencies = sales_order_display_currencies_ordered($displayCurrencies, $selectedCurrency);

        $currencyRates = ['TZS' => 1.0];
        if (!empty($input['currency_rates'])) {
            $rawRates = $input['currency_rates'];
            $decodedRates = is_string($rawRates) ? json_decode($rawRates, true) : $rawRates;
            if (is_array($decodedRates)) {
                foreach ($decodedRates as $rateCode => $rateValue) {
                    $rateCode = strtoupper(trim((string) $rateCode));
                    if (!isset($invoiceCurrencyOptions[$rateCode])) {
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
        if (in_array('USD', $displayCurrencies, true) && (float) ($currencyRates['USD'] ?? 0) <= 1.01 && function_exists('sales_invoice_bot_exchange_rates')) {
            $botUsd = sales_invoice_bot_exchange_rates(['USD']);
            if ((float) ($botUsd['USD'] ?? 0) > 1.01) {
                $currencyRates['USD'] = (float) $botUsd['USD'];
            }
        }

        $orderFields = ['order_number', 'customer_id', 'quote_date', 'valid_until'];
        $orderValues = [
            $order_number,
            $input['customer_id'],
            $input['invoice_date'],
            $input['due_date'],
        ];
        if ($hasOrderType) {
            $orderFields[] = 'order_type';
            $orderValues[] = (function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices())
                ? ($input['order_type'] ?? 'spare')
                : 'spare';
        }
        if ($hasLeadTime) {
            $orderFields[] = 'lead_time';
            $orderValues[] = ($input['lead_time'] ?? null) !== '' ? ($input['lead_time'] ?? null) : null;
        }
        $orderFields = array_merge($orderFields, [
            'subtotal', 'discount_amount', 'tax_amount', 'shipping_charges', 'total_amount', 'status',
        ]);
        $orderValues = array_merge($orderValues, [
            $input['subtotal'],
            $input['discount_amount'],
            $input['tax_amount'],
            $input['shipping_charges'],
            $input['total_amount'],
            'invoiced',
        ]);
        if ($hasCurrency) {
            $orderFields[] = 'currency';
            $orderValues[] = $selectedCurrency;
        }
        if ($hasExchangeRate) {
            $orderFields[] = 'exchange_rate';
            $orderValues[] = $postedExchangeRate;
        }
        if ($hasDisplayCurrencies) {
            $orderFields[] = 'display_currencies';
            $orderValues[] = json_encode(array_values($displayCurrencies), JSON_UNESCAPED_UNICODE);
        }
        if ($hasCurrencyRates) {
            $orderFields[] = 'currency_rates';
            $orderValues[] = json_encode($currencyRates, JSON_UNESCAPED_UNICODE);
        }
        $orderFields[] = 'created_by';
        $orderValues[] = $_SESSION['user_id'];
        if ($hasSoCompanyId) {
            $orderFields[] = 'company_id';
            $orderValues[] = $company_id;
        }

        $valuesSqlParts = array_fill(0, count($orderValues), '?');
        if ($hasShippedAt) {
            $orderFields[] = 'shipped_at';
            $valuesSqlParts[] = 'NOW()';
        }
        $sql = 'INSERT INTO sales_orders (' . implode(', ', $orderFields) . ') VALUES (' . implode(', ', $valuesSqlParts) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderValues);

        $order_id = (int) $pdo->lastInsertId();

        if (isset($input['items']) && is_array($input['items'])) {
            $soiCols = $pdo->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $hasDesc = in_array('description', $soiCols, true);
            $hasItemCompanyId = in_array('company_id', $soiCols, true);
            $itemFields = ['order_id', 'product_id', 'quantity', 'unit_price', 'discount_percentage', 'line_total'];
            if ($hasItemCompanyId) {
                array_splice($itemFields, 1, 0, ['company_id']);
            }
            if ($hasDesc) {
                $itemFields[] = 'description';
            }
            $itemSql = 'INSERT INTO sales_order_items (' . implode(', ', $itemFields) . ') VALUES (' . implode(', ', array_fill(0, count($itemFields), '?')) . ')';
            $stmtItem = $pdo->prepare($itemSql);

            foreach ($input['items'] as $item) {
                if (!empty($item['product_id']) && (float) ($item['quantity'] ?? 0) > 0) {
                    $qty = (float) ($item['quantity'] ?? 0);
                    $unit = (float) ($item['unit_price'] ?? 0);
                    $disc = (float) ($item['discount'] ?? 0);
                    $line_total = $qty * $unit;
                    $values = [$order_id];
                    if ($hasItemCompanyId) {
                        $values[] = $company_id;
                    }
                    $values = array_merge($values, [
                        $item['product_id'],
                        $qty,
                        $unit,
                        $disc,
                        $line_total,
                    ]);
                    if ($hasDesc) {
                        $values[] = $item['description'] ?? '';
                    }
                    $stmtItem->execute($values);
                }
            }
        }

        $invoice_number = function_exists('sales_next_invoice_number')
            ? sales_next_invoice_number($salesDb, $company_id)
            : ('INV-' . date('Y') . '-' . str_pad((string) (((int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM invoices')->fetchColumn()) + 1), 4, '0', STR_PAD_LEFT));

        $invCols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $hasInvCompanyId = in_array('company_id', $invCols, true);
        $hasInvOrderType = in_array('order_type', $invCols, true);

        $invoiceFields = [
            'invoice_number', 'order_id', 'customer_id', 'invoice_date', 'due_date',
            'subtotal', 'discount_amount', 'tax_amount', 'shipping_charges', 'total_amount', 'status', 'created_by',
        ];
        $invoiceValueSql = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', "'sent'", '?'];
        $invoiceParams = [
            $invoice_number,
            $order_id,
            $input['customer_id'],
            $input['invoice_date'],
            $input['due_date'],
            $input['subtotal'],
            $input['discount_amount'],
            $input['tax_amount'],
            $input['shipping_charges'],
            $input['total_amount'],
            $_SESSION['user_id'],
        ];
        if ($hasInvOrderType) {
            $invoiceFields[] = 'order_type';
            $invoiceValueSql[] = '?';
            $invoiceParams[] = $input['order_type'] ?? 'spare';
        }
        if ($hasInvCompanyId) {
            $invoiceFields[] = 'company_id';
            $invoiceValueSql[] = '?';
            $invoiceParams[] = $company_id;
        }
        $inv_sql = 'INSERT INTO invoices (' . implode(', ', $invoiceFields) . ') VALUES (' . implode(', ', $invoiceValueSql) . ')';
        $stmtInsertInv = $pdo->prepare($inv_sql);
        $stmtInsertInv->execute($invoiceParams);

        $invoice_id = (int) $pdo->lastInsertId();

        $stockDeduction = function_exists('sales_deduct_stock_for_order_result')
            ? sales_deduct_stock_for_order_result($order_id)
            : [
                'attempted' => false,
                'success' => false,
                'message' => 'Stock deduction result is unavailable.',
                'items_processed' => 0,
            ];

        if (!$stockDeduction['success'] && !empty($stockDeduction['error'])) {
            error_log('invoice_direct_create stock: ' . (string) $stockDeduction['error']);
        }

        $pdo->commit();

        syncInvoiceToRevenueLedger($pdo, $invoice_id, (int) ($_SESSION['user_id'] ?? 0) ?: null);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['sales_invoice_create_flash'] = [
            'invoice_id' => $invoice_id,
            'stock_deduction' => $stockDeduction,
        ];

        $redirectQuery = [
            'id' => $invoice_id,
            'msg' => 'created',
        ];
        if (!empty($stockDeduction['attempted'])) {
            $redirectQuery['stock'] = !empty($stockDeduction['success']) ? 'deducted' : 'failed';
        }

        $redirect = function_exists('sales_module_url')
            ? sales_module_url('invoices/view.php', $redirectQuery)
            : ('view.php?' . http_build_query($redirectQuery));

        return [
            'invoice_id' => $invoice_id,
            'redirect' => $redirect,
            'stock_deduction' => $stockDeduction,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
