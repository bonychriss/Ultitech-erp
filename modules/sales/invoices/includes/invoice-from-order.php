<?php

declare(strict_types=1);

/**
 * Redirect helper that still works if headers were already sent.
 */
function sales_invoice_redirect(string $url): void
{
    $url = trim($url);
    if ($url === '') {
        sales_invoice_fail('Invoice was created but the redirect URL was empty.');
    }

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">';
    echo '<title>Redirecting</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<p>Redirecting to invoice&hellip;</p>';
    echo '<p><a href="' . $safeUrl . '">Continue</a></p></body></html>';
    exit;
}

function sales_invoice_fail(string $message, int $status = 500): void
{
    error_log('sales invoice from order: ' . $message);
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Create Invoice</title></head>';
    echo '<body style="font-family:sans-serif;padding:2rem;max-width:40rem;">';
    echo '<h1 style="color:#b91c1c;">Could not create invoice</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

/**
 * Convert a confirmed sales order into an invoice.
 *
 * @return array{invoice_id:int, redirect:string, stock_deduction:array<string, mixed>}
 */
function sales_convert_order_to_invoice(int $orderId, string $module = 'sales', bool $redirectIfExists = true): array
{
    global $pdo;

    if (!function_exists('syncInvoiceToRevenueLedger')) {
        $ledgerPath = dirname(__DIR__, 4) . '/includes/revenue_ledger.php';
        if (is_file($ledgerPath)) {
            require_once $ledgerPath;
        }
    }

    $salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
    if (!$salesDb instanceof PDO) {
        throw new RuntimeException('Sales database connection is unavailable.');
    }

    $companyId = (int) (currentCompanyId() ?? 0);
    $module = trim($module) !== '' ? trim($module) : 'sales';

    $soCols = [];
    $invCols = [];
    try {
        $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $soCols = [];
    }
    try {
        $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $invCols = [];
    }

    if ($invCols === []) {
        throw new RuntimeException('Invoices table is not available in the sales database.');
    }

    $orderSql = 'SELECT * FROM sales_orders WHERE id = ?';
    $orderParams = [$orderId];
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($orderSql, $orderParams, 'sales_orders');
    }
    $stmt = $salesDb->prepare($orderSql);
    $stmt->execute($orderParams);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $orderStatus = strtolower(trim((string) ($order['status'] ?? '')));

    $invoiceCheckSql = 'SELECT id FROM invoices WHERE order_id = ?';
    $invoiceCheckParams = [$orderId];
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($invoiceCheckSql, $invoiceCheckParams, 'invoices');
    }
    $stmtCheck = $salesDb->prepare($invoiceCheckSql);
    $stmtCheck->execute($invoiceCheckParams);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if (!$redirectIfExists) {
            throw new RuntimeException('This order already has an invoice.');
        }
        $redirect = function_exists('sales_module_url')
            ? sales_module_url('invoices/view.php', ['id' => (int) $existing['id'], 'module' => $module])
            : ('view.php?id=' . (int) $existing['id']);
        return [
            'invoice_id' => (int) $existing['id'],
            'redirect' => $redirect,
        ];
    }

    $blockedInvoiceStatuses = ['cancelled', 'canceled', 'delivered'];
    if (in_array($orderStatus, $blockedInvoiceStatuses, true)) {
        throw new RuntimeException(
            'This order cannot be invoiced. Current status: ' . ($orderStatus !== '' ? $orderStatus : 'unknown') . '.'
        );
    }
    if (in_array($orderStatus, ['invoiced', 'paid'], true)) {
        throw new RuntimeException(
            'This order is marked as invoiced but no invoice record was found. Please contact support.'
        );
    }
    $autoConfirmForInvoice = in_array($orderStatus, ['draft', 'quotation', 'sent'], true);
    if (!$autoConfirmForInvoice && $orderStatus !== 'confirmed') {
        throw new RuntimeException(
            'Only quotations and confirmed sales orders can be invoiced. Current status: '
            . ($orderStatus !== '' ? $orderStatus : 'unknown') . '.'
        );
    }

    $resolvedCustomerId = (int) ($order['customer_id'] ?? 0);
    if ($resolvedCustomerId <= 0 && isset($order['customer']) && is_numeric($order['customer'])) {
        $resolvedCustomerId = (int) $order['customer'];
    }
    if ($resolvedCustomerId <= 0) {
        $customerCols = [];
        try {
            $customerCols = $salesDb->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $customerCols = [];
        }
        $fallbackCustomerSql = "SELECT id FROM customers WHERE status = 'active'";
        $fallbackCustomerParams = [];
        if ($companyId > 0 && in_array('company_id', $customerCols, true)) {
            $fallbackCustomerSql .= ' AND company_id = ?';
            $fallbackCustomerParams[] = $companyId;
        }
        $fallbackCustomerSql .= ' ORDER BY id ASC LIMIT 1';
        $stmtFallbackCustomer = $salesDb->prepare($fallbackCustomerSql);
        $stmtFallbackCustomer->execute($fallbackCustomerParams);
        $resolvedCustomerId = (int) ($stmtFallbackCustomer->fetchColumn() ?: 0);
    }
    if ($resolvedCustomerId <= 0) {
        throw new RuntimeException('No customer found. Please assign a customer to this order first.');
    }

    $invoiceNumber = function_exists('sales_next_invoice_number')
        ? sales_next_invoice_number($salesDb, $companyId)
        : ('INV-' . date('Y') . '-' . str_pad((string) (((int) $salesDb->query('SELECT COALESCE(MAX(id), 0) FROM invoices')->fetchColumn()) + 1), 4, '0', STR_PAD_LEFT));

    $totalAmount = (float) ($order['total_amount'] ?? 0);
    $scalarFields = [
        'invoice_number' => $invoiceNumber,
        'order_id' => (int) $order['id'],
        'customer_id' => $resolvedCustomerId,
        'subtotal' => (float) ($order['subtotal'] ?? $totalAmount),
        'discount_amount' => (float) ($order['discount_amount'] ?? 0),
        'tax_amount' => (float) ($order['tax_amount'] ?? 0),
        'shipping_charges' => (float) ($order['shipping_charges'] ?? 0),
        'total_amount' => $totalAmount,
        'amount_paid' => 0.0,
        'status' => 'sent',
        'created_by' => (int) ($_SESSION['user_id'] ?? 0),
    ];
    if (in_array('order_type', $invCols, true)) {
        $scalarFields['order_type'] = (string) ($order['order_type'] ?? 'spare');
    }
    if (in_array('company_id', $invCols, true)) {
        $scalarFields['company_id'] = $companyId;
    }

    $skipColumns = ['balance_due' => true];

    $invoiceFields = [];
    $invoiceValueSql = [];
    $invoiceParams = [];

    // Invoice date is the conversion date, not the original quotation date.
    $invoiceDate = date('Y-m-d');
    $dueDate = trim((string) ($order['valid_until'] ?? ''));
    $quoteDate = trim((string) ($order['quote_date'] ?? ''));
    // Preserve the original quote→valid window relative to the new invoice date when possible.
    if (
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $quoteDate)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)
    ) {
        $termDays = (int) round((strtotime($dueDate) - strtotime($quoteDate)) / 86400);
        if ($termDays < 0) {
            $termDays = 30;
        }
        $dueDate = date('Y-m-d', strtotime('+' . $termDays . ' days', strtotime($invoiceDate)));
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) || $dueDate < $invoiceDate) {
        $dueDate = date('Y-m-d', strtotime('+30 days', strtotime($invoiceDate)));
    }

    if (in_array('invoice_date', $invCols, true)) {
        $invoiceFields[] = 'invoice_date';
        $invoiceValueSql[] = '?';
        $invoiceParams[] = $invoiceDate;
    }
    if (in_array('due_date', $invCols, true)) {
        $invoiceFields[] = 'due_date';
        $invoiceValueSql[] = '?';
        $invoiceParams[] = $dueDate;
    }

    foreach ($scalarFields as $column => $value) {
        if (!in_array($column, $invCols, true) || isset($skipColumns[$column])) {
            continue;
        }
        if ($column === 'created_by' && (int) $value <= 0) {
            continue;
        }
        $invoiceFields[] = $column;
        $invoiceValueSql[] = '?';
        $invoiceParams[] = $value;
    }

    if ($invoiceFields === []) {
        throw new RuntimeException('Invoices table schema is missing required columns.');
    }

    $salesDb->beginTransaction();
    try {
        if ($autoConfirmForInvoice) {
            $confirmSql = "UPDATE sales_orders SET status = 'confirmed' WHERE id = ?";
            $confirmParams = [$orderId];
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($confirmSql, $confirmParams, 'sales_orders');
            }
            $stmtConfirm = $salesDb->prepare($confirmSql);
            $stmtConfirm->execute($confirmParams);
            if ($stmtConfirm->rowCount() <= 0) {
                throw new RuntimeException('Order could not be confirmed before invoicing.');
            }
            $orderStatus = 'confirmed';
        }

        $sql = 'INSERT INTO invoices (' . implode(', ', $invoiceFields) . ') VALUES (' . implode(', ', $invoiceValueSql) . ')';
        $stmtInsert = $salesDb->prepare($sql);
        $stmtInsert->execute($invoiceParams);
        $invoiceId = (int) $salesDb->lastInsertId();
        if ($invoiceId <= 0) {
            throw new RuntimeException('Invoice insert failed (no invoice id returned).');
        }

        $orderUpdateSql = "UPDATE sales_orders SET status = 'invoiced'";
        if (in_array('shipped_at', $soCols, true)) {
            $orderUpdateSql .= ', shipped_at = NOW()';
        }
        $orderUpdateSql .= ' WHERE id = ?';
        $orderUpdateParams = [$orderId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($orderUpdateSql, $orderUpdateParams, 'sales_orders');
        }
        $stmtUpdate = $salesDb->prepare($orderUpdateSql);
        $stmtUpdate->execute($orderUpdateParams);

        $verifySql = 'SELECT status FROM sales_orders WHERE id = ?';
        $verifyParams = [$orderId];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($verifySql, $verifyParams, 'sales_orders');
        }
        $stmtVerify = $salesDb->prepare($verifySql);
        $stmtVerify->execute($verifyParams);
        $updatedStatus = strtolower(trim((string) ($stmtVerify->fetchColumn() ?: '')));
        if ($updatedStatus !== 'invoiced') {
            throw new RuntimeException('Order status was not updated to invoiced. Please try again or contact support.');
        }

        $salesDb->commit();
    } catch (Throwable $e) {
        if ($salesDb->inTransaction()) {
            $salesDb->rollBack();
        }
        throw $e;
    }

    $stockDeduction = function_exists('sales_deduct_stock_for_order_result')
        ? sales_deduct_stock_for_order_result($orderId)
        : [
            'attempted' => false,
            'success' => false,
            'message' => 'Stock deduction result is unavailable.',
            'items_processed' => 0,
        ];

    if (!$stockDeduction['success'] && !empty($stockDeduction['error'])) {
        error_log('sales_convert_order_to_invoice stock: ' . (string) $stockDeduction['error']);
    }

    try {
        if (function_exists('syncInvoiceToRevenueLedger')) {
            syncInvoiceToRevenueLedger($salesDb, $invoiceId, (int) ($_SESSION['user_id'] ?? 0) ?: null);
        }
    } catch (Throwable $e) {
        error_log('sales_convert_order_to_invoice revenue: ' . $e->getMessage());
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['sales_invoice_create_flash'] = [
        'invoice_id' => $invoiceId,
        'stock_deduction' => $stockDeduction,
    ];

    $redirectQuery = [
        'id' => $invoiceId,
        'msg' => 'created',
        'module' => $module,
    ];
    if (!empty($stockDeduction['attempted'])) {
        $redirectQuery['stock'] = !empty($stockDeduction['success']) ? 'deducted' : 'failed';
    }

    $redirect = function_exists('sales_module_url')
        ? sales_module_url('invoices/view.php', $redirectQuery)
        : ('view.php?' . http_build_query($redirectQuery));

    return [
        'invoice_id' => $invoiceId,
        'redirect' => $redirect,
        'stock_deduction' => $stockDeduction,
    ];
}
