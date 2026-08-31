<?php

declare(strict_types=1);

/**
 * Create a sales quotation from POST-style input.
 *
 * @param array<string, mixed> $input
 * @return array{order_id:int, redirect:string}
 */
function sales_process_direct_quote_create(array $input): array
{
    global $pdo;
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    $company_id = (int) (currentCompanyId() ?? 0);

    ensureCustomerColumnsExist();
    ensureSalesOrderMultiCurrencyColumns();
    $salesDb->beginTransaction();

    try {
        $order_number = 'SO-' . date('Y') . '-' . str_pad((string) getNextOrderNumber(), 5, '0', STR_PAD_LEFT);

        $orderType = function_exists('salesNormalizeOrderType')
            ? salesNormalizeOrderType((string) ($input['order_type'] ?? 'spare'))
            : 'spare';
        if (function_exists('isRoadmaster') && isRoadmaster() && !empty($input['items']) && is_array($input['items'])) {
            try {
                $itCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
                if (is_array($itCols) && in_array('item_type', $itCols, true)) {
                    $itStmt = $salesDb->prepare('SELECT LOWER(TRIM(COALESCE(item_type, \'\'))) FROM products WHERE id = ? LIMIT 1');
                    foreach ($input['items'] as $item) {
                        $pid = (int) ($item['product_id'] ?? 0);
                        if ($pid <= 0) {
                            continue;
                        }
                        $itStmt->execute([$pid]);
                        $it = (string) $itStmt->fetchColumn();
                        if ($it === 'vehicle' || $it === 'truck') {
                            $orderType = 'truck';
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                // keep POST order_type
            }
        }

        $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $hasLeadTime = in_array('lead_time', $soCols, true);
        $hasOrderType = in_array('order_type', $soCols, true);
        $hasCompanyId = in_array('company_id', $soCols, true);
        $hasCurrency = in_array('currency', $soCols, true);
        $hasExchangeRate = in_array('exchange_rate', $soCols, true);
        $hasDisplayCurrencies = in_array('display_currencies', $soCols, true);
        $hasCurrencyRates = in_array('currency_rates', $soCols, true);

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

        if ($orderType === 'truck' && function_exists('salesSupportsTruckInvoices') && salesSupportsTruckInvoices() && $displayCurrencies === []) {
            throw new RuntimeException('Please select at least one currency for truck quotations.');
        }

        $selectedCurrency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if ($selectedCurrency === '' || !isset($quoteCurrencyOptions[$selectedCurrency])) {
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
        if (in_array('USD', $displayCurrencies, true) && (float) ($currencyRates['USD'] ?? 0) <= 1.01 && function_exists('sales_invoice_bot_exchange_rates')) {
            $botUsd = sales_invoice_bot_exchange_rates(['USD']);
            if ((float) ($botUsd['USD'] ?? 0) > 1.01) {
                $currencyRates['USD'] = (float) $botUsd['USD'];
            }
        }

        $orderFields = ['order_number', 'customer_id', 'quote_date', 'valid_until'];
        $orderValues = [
            $order_number,
            !empty($input['customer_id']) ? $input['customer_id'] : null,
            $input['quote_date'] ?? $input['invoice_date'] ?? date('Y-m-d'),
            $input['valid_until'] ?? $input['due_date'] ?? date('Y-m-d', strtotime('+7 days')),
        ];
        if ($hasLeadTime) {
            $orderFields[] = 'lead_time';
            $orderValues[] = ($input['lead_time'] ?? '') !== '' ? $input['lead_time'] : null;
        }
        $orderFields = array_merge($orderFields, [
            'subtotal', 'discount_amount', 'tax_amount', 'shipping_charges', 'total_amount', 'status',
        ]);
        $orderValues = array_merge($orderValues, [
            $input['subtotal'],
            $input['discount_amount'] ?? 0,
            $input['tax_amount'],
            $input['shipping_charges'] ?? 0,
            $input['total_amount'],
            $input['status'] ?? 'quotation',
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
        if ($hasOrderType) {
            $orderFields[] = 'order_type';
            $orderValues[] = $orderType;
        }
        $orderFields[] = 'created_by';
        $orderValues[] = !empty($input['created_by']) ? $input['created_by'] : ($_SESSION['user_id'] ?? null);
        if ($hasCompanyId) {
            $orderFields[] = 'company_id';
            $orderValues[] = $company_id;
        }

        $orderSql = 'INSERT INTO sales_orders (' . implode(', ', $orderFields) . ') VALUES (' . implode(', ', array_fill(0, count($orderValues), '?')) . ')';
        $stmt = $salesDb->prepare($orderSql);
        $stmt->execute($orderValues);
        $order_id = (int) $salesDb->lastInsertId();

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
                    $itemValues = [$order_id];
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

        $redirect = function_exists('sales_module_url')
            ? sales_module_url('orders/view.php', ['id' => $order_id, 'module' => 'sales'])
            : ('../orders/view.php?id=' . $order_id);

        return [
            'order_id' => $order_id,
            'redirect' => $redirect,
        ];
    } catch (Throwable $e) {
        if ($salesDb->inTransaction()) {
            $salesDb->rollBack();
        }
        throw $e;
    }
}
