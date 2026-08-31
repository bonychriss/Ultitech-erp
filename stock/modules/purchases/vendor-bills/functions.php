<?php
/**
 * Vendor Bills module helpers (Phase 4A).
 * No HTML output. All writes scoped by company_id passed from caller session layer.
 */

if (!function_exists('vendorBillTableExists')) {

    function vendorBillTableExists(PDO $pdo): bool
    {
        if (function_exists('tableExists')) {
            return tableExists('vendor_bills', $pdo);
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'vendor_bills'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    function vendorBillItemsTableExists(PDO $pdo): bool
    {
        if (function_exists('tableExists')) {
            return tableExists('vendor_bill_items', $pdo);
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'vendor_bill_items'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    function vendorBillSupplierPaymentsTableExists(PDO $pdo): bool
    {
        if (function_exists('tableExists')) {
            return tableExists('supplier_payments', $pdo);
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'supplier_payments'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{where:string,params:array<int,mixed>}
     */
    function vendorBillBuildFilterSql(int $companyId, array $filters = []): array
    {
        $where = ['vb.company_id = ?'];
        $params = [$companyId];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'vb.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['payment_status'])) {
            $where[] = 'vb.payment_status = ?';
            $params[] = (string) $filters['payment_status'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'vb.status = ?';
            $params[] = (string) $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'vb.bill_date >= ?';
            $params[] = (string) $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'vb.bill_date <= ?';
            $params[] = (string) $filters['date_to'];
        }

        if (!empty($filters['q'])) {
            $q = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(vb.bill_number LIKE ? OR vb.supplier_invoice_number LIKE ?)';
            $params[] = $q;
            $params[] = $q;
        }

        return [
            'where' => implode(' AND ', $where),
            'params' => $params,
        ];
    }

    function vendorBillListSelectSql(PDO $pdo): string
    {
        $supplierJoin = '';
        $supplierName = "CONCAT('Supplier #', vb.supplier_id)";

        if (function_exists('tableExists') && tableExists('stocks_suppliers', $pdo)) {
            $supplierJoin = 'LEFT JOIN stocks_suppliers ss ON ss.id = vb.supplier_id';
            $supplierName = 'COALESCE(ss.name, CONCAT(\'Supplier #\', vb.supplier_id))';
        }

        $poJoin = '';
        $poNumber = 'NULL';

        if (function_exists('tableExists') && tableExists('stocks_purchase_orders', $pdo)) {
            $poJoin = 'LEFT JOIN stocks_purchase_orders po ON po.id = COALESCE(vb.purchase_order_id, vb.linked_stock_po_id)';
            $poNumber = 'po.po_number';
        }

        return "
            SELECT vb.*,
                   {$supplierName} AS supplier_name,
                   {$poNumber} AS po_number
            FROM vendor_bills vb
            {$supplierJoin}
            {$poJoin}
        ";
    }

    function generateVendorBillNumber(PDO $pdo, int $companyId): string
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('Company id is required to generate a vendor bill number.');
        }

        $year = (int) date('Y');
        $prefix = 'VB-' . $year . '-';
        $nextSeq = 1;

        if (vendorBillTableExists($pdo)) {
            $stmt = $pdo->prepare(
                'SELECT bill_number
                 FROM vendor_bills
                 WHERE company_id = ?
                   AND bill_number LIKE ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute([$companyId, $prefix . '%']);
            $last = (string) ($stmt->fetchColumn() ?: '');

            if ($last !== '' && preg_match('/-(\d+)$/', $last, $matches)) {
                $nextSeq = ((int) $matches[1]) + 1;
            }
        }

        return $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }

    function getVendorBillById(PDO $pdo, int $billId, int $companyId): ?array
    {
        if ($billId <= 0 || $companyId <= 0 || !vendorBillTableExists($pdo)) {
            return null;
        }

        $sql = vendorBillListSelectSql($pdo) . ' WHERE vb.id = ? AND vb.company_id = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$billId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    function getVendorBillItems(PDO $pdo, int $billId, int $companyId): array
    {
        if ($billId <= 0 || $companyId <= 0 || !vendorBillItemsTableExists($pdo)) {
            return [];
        }

        $stockJoin = '';
        $stockName = 'NULL';

        if (function_exists('tableExists') && tableExists('stocks_items', $pdo)) {
            $stockJoin = 'LEFT JOIN stocks_items si ON si.id = vbi.stock_item_id';
            $stockName = 'si.name';
        }

        $sql = "
            SELECT vbi.*,
                   {$stockName} AS stock_item_name
            FROM vendor_bill_items vbi
            {$stockJoin}
            WHERE vbi.vendor_bill_id = ?
              AND vbi.company_id = ?
            ORDER BY vbi.sort_order ASC, vbi.id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$billId, $companyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function listVendorBills(PDO $pdo, int $companyId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        if ($companyId <= 0 || !vendorBillTableExists($pdo)) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $filter = vendorBillBuildFilterSql($companyId, $filters);
        $sql = vendorBillListSelectSql($pdo)
            . ' WHERE ' . $filter['where']
            . ' ORDER BY vb.bill_date DESC, vb.id DESC'
            . ' LIMIT ' . (int) $limit
            . ' OFFSET ' . (int) $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($filter['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function countVendorBills(PDO $pdo, int $companyId, array $filters = []): int
    {
        if ($companyId <= 0 || !vendorBillTableExists($pdo)) {
            return 0;
        }

        $filter = vendorBillBuildFilterSql($companyId, $filters);
        $sql = 'SELECT COUNT(*) FROM vendor_bills vb WHERE ' . $filter['where'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filter['params']);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array{subtotal:float,tax_amount:float,total_amount:float,lines:array<int,array<string,mixed>>}
     */
    function calculateVendorBillTotals(array $lines): array
    {
        $subtotal = 0.0;
        $taxAmount = 0.0;
        $normalizedLines = [];

        foreach ($lines as $index => $line) {
            $normalized = vendorBillNormalizeLine($line, (int) $index);
            $subtotal += $normalized['line_subtotal'];
            $taxAmount += $normalized['tax_amount'];
            $normalizedLines[] = $normalized;
        }

        $totalAmount = round($subtotal + $taxAmount, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => $totalAmount,
            'lines' => $normalizedLines,
        ];
    }

    function deriveVendorBillPaymentStatus(float $totalAmount, float $paidAmount): string
    {
        $totalAmount = round(max(0, $totalAmount), 2);
        $paidAmount = round(max(0, $paidAmount), 2);

        if ($paidAmount <= 0) {
            return 'unpaid';
        }

        if ($paidAmount > $totalAmount) {
            return 'overpaid';
        }

        if ($paidAmount >= $totalAmount) {
            return 'paid';
        }

        return 'partially_paid';
    }

    /**
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $lines
     * @return array{success:bool,message?:string,id?:int}
     */
    function saveVendorBillDraft(PDO $pdo, int $companyId, int $userId, array $header, array $lines): array
    {
        if (!vendorBillTableExists($pdo) || !vendorBillItemsTableExists($pdo)) {
            return ['success' => false, 'message' => 'Vendor bill tables are not available. Run Phase 3B migration first.'];
        }

        if ($companyId <= 0) {
            return ['success' => false, 'message' => 'Company is required.'];
        }

        $supplierId = (int) ($header['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return ['success' => false, 'message' => 'Supplier is required.'];
        }

        if (count($lines) === 0) {
            return ['success' => false, 'message' => 'At least one line item is required.'];
        }

        $totals = calculateVendorBillTotals($lines);
        if ($totals['total_amount'] <= 0) {
            return ['success' => false, 'message' => 'Total amount must be greater than zero.'];
        }

        foreach ($totals['lines'] as $line) {
            if ((float) $line['quantity'] <= 0) {
                return ['success' => false, 'message' => 'Each line quantity must be greater than zero.'];
            }
        }

        $purchaseOrderId = vendorBillNullableInt($header['purchase_order_id'] ?? null);
        $linkedStockPoId = vendorBillNullableInt($header['linked_stock_po_id'] ?? null);
        if ($linkedStockPoId === null && $purchaseOrderId !== null) {
            $linkedStockPoId = $purchaseOrderId;
        }
        if ($purchaseOrderId === null && $linkedStockPoId !== null) {
            $purchaseOrderId = $linkedStockPoId;
        }

        try {
            $pdo->beginTransaction();

            $billNumber = generateVendorBillNumber($pdo, $companyId);
            $billDate = vendorBillNormalizeDate($header['bill_date'] ?? null) ?: date('Y-m-d');
            $dueDate = vendorBillNormalizeDate($header['due_date'] ?? null);
            $currency = strtoupper(trim((string) ($header['currency'] ?? 'TZS'))) ?: 'TZS';
            $exchangeRate = vendorBillNormalizeMoney($header['exchange_rate'] ?? 1, 6);
            if ($exchangeRate <= 0) {
                $exchangeRate = 1;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO vendor_bills (
                    company_id, supplier_id, bill_number, supplier_invoice_number,
                    purchase_order_id, linked_stock_po_id, grn_id,
                    bill_date, due_date, currency, exchange_rate,
                    subtotal, tax_amount, total_amount,
                    paid_amount, balance_due, payment_status, status,
                    notes, created_by
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    0, ?, ?, ?,
                    ?, ?
                )'
            );

            $stmt->execute([
                $companyId,
                $supplierId,
                $billNumber,
                vendorBillNullableString($header['supplier_invoice_number'] ?? null),
                $purchaseOrderId,
                $linkedStockPoId,
                vendorBillNullableInt($header['grn_id'] ?? null),
                $billDate,
                $dueDate,
                $currency,
                $exchangeRate,
                $totals['subtotal'],
                $totals['tax_amount'],
                $totals['total_amount'],
                $totals['total_amount'],
                'unpaid',
                'draft',
                vendorBillNullableString($header['notes'] ?? null),
                $userId > 0 ? $userId : null,
            ]);

            $billId = (int) $pdo->lastInsertId();
            vendorBillInsertItems($pdo, $billId, $companyId, $totals['lines']);

            $pdo->commit();

            return ['success' => true, 'id' => $billId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (strpos($e->getMessage(), 'uq_vendor_bills_company_bill_number') !== false) {
                return ['success' => false, 'message' => 'Bill number conflict. Please try again.'];
            }

            error_log('saveVendorBillDraft: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to save vendor bill draft.'];
        }
    }

    /**
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $lines
     * @return array{success:bool,message?:string,id?:int}
     */
    function updateVendorBillDraft(PDO $pdo, int $billId, int $companyId, array $header, array $lines): array
    {
        if ($billId <= 0 || $companyId <= 0) {
            return ['success' => false, 'message' => 'Invalid vendor bill.'];
        }

        if (!vendorBillTableExists($pdo) || !vendorBillItemsTableExists($pdo)) {
            return ['success' => false, 'message' => 'Vendor bill tables are not available.'];
        }

        $existing = getVendorBillById($pdo, $billId, $companyId);
        if (!$existing) {
            return ['success' => false, 'message' => 'Vendor bill not found.'];
        }

        if (($existing['status'] ?? '') !== 'draft') {
            return ['success' => false, 'message' => 'Only draft vendor bills can be edited.'];
        }

        $supplierId = (int) ($header['supplier_id'] ?? $existing['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return ['success' => false, 'message' => 'Supplier is required.'];
        }

        if (count($lines) === 0) {
            return ['success' => false, 'message' => 'At least one line item is required.'];
        }

        $totals = calculateVendorBillTotals($lines);
        if ($totals['total_amount'] <= 0) {
            return ['success' => false, 'message' => 'Total amount must be greater than zero.'];
        }

        foreach ($totals['lines'] as $line) {
            if ((float) $line['quantity'] <= 0) {
                return ['success' => false, 'message' => 'Each line quantity must be greater than zero.'];
            }
        }

        $purchaseOrderId = vendorBillNullableInt($header['purchase_order_id'] ?? $existing['purchase_order_id'] ?? null);
        $linkedStockPoId = vendorBillNullableInt($header['linked_stock_po_id'] ?? $existing['linked_stock_po_id'] ?? null);
        if ($linkedStockPoId === null && $purchaseOrderId !== null) {
            $linkedStockPoId = $purchaseOrderId;
        }
        if ($purchaseOrderId === null && $linkedStockPoId !== null) {
            $purchaseOrderId = $linkedStockPoId;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE vendor_bills SET
                    supplier_id = ?,
                    supplier_invoice_number = ?,
                    purchase_order_id = ?,
                    linked_stock_po_id = ?,
                    grn_id = ?,
                    bill_date = ?,
                    due_date = ?,
                    currency = ?,
                    exchange_rate = ?,
                    subtotal = ?,
                    tax_amount = ?,
                    total_amount = ?,
                    balance_due = ?,
                    payment_status = ?,
                    notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                   AND company_id = ?
                   AND status = ?'
            );

            $billDate = vendorBillNormalizeDate($header['bill_date'] ?? $existing['bill_date'] ?? null) ?: date('Y-m-d');
            $dueDate = vendorBillNormalizeDate($header['due_date'] ?? $existing['due_date'] ?? null);
            $currency = strtoupper(trim((string) ($header['currency'] ?? $existing['currency'] ?? 'TZS'))) ?: 'TZS';
            $exchangeRate = vendorBillNormalizeMoney($header['exchange_rate'] ?? $existing['exchange_rate'] ?? 1, 6);
            if ($exchangeRate <= 0) {
                $exchangeRate = 1;
            }

            $stmt->execute([
                $supplierId,
                vendorBillNullableString($header['supplier_invoice_number'] ?? $existing['supplier_invoice_number'] ?? null),
                $purchaseOrderId,
                $linkedStockPoId,
                vendorBillNullableInt($header['grn_id'] ?? $existing['grn_id'] ?? null),
                $billDate,
                $dueDate,
                $currency,
                $exchangeRate,
                $totals['subtotal'],
                $totals['tax_amount'],
                $totals['total_amount'],
                $totals['total_amount'],
                'unpaid',
                vendorBillNullableString($header['notes'] ?? $existing['notes'] ?? null),
                $billId,
                $companyId,
                'draft',
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Vendor bill is no longer editable.'];
            }

            $deleteItems = $pdo->prepare(
                'DELETE FROM vendor_bill_items WHERE vendor_bill_id = ? AND company_id = ?'
            );
            $deleteItems->execute([$billId, $companyId]);

            vendorBillInsertItems($pdo, $billId, $companyId, $totals['lines']);

            $pdo->commit();

            return ['success' => true, 'id' => $billId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('updateVendorBillDraft: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to update vendor bill draft.'];
        }
    }

    /**
     * @return array{success:bool,message?:string,id?:int}
     */
    function cancelVendorBill(PDO $pdo, int $billId, int $companyId, int $userId): array
    {
        if ($billId <= 0 || $companyId <= 0) {
            return ['success' => false, 'message' => 'Invalid vendor bill.'];
        }

        if (!vendorBillTableExists($pdo)) {
            return ['success' => false, 'message' => 'Vendor bill tables are not available.'];
        }

        $bill = getVendorBillById($pdo, $billId, $companyId);
        if (!$bill) {
            return ['success' => false, 'message' => 'Vendor bill not found.'];
        }

        $status = (string) ($bill['status'] ?? '');
        if ($status === 'cancelled') {
            return ['success' => true, 'id' => $billId, 'message' => 'Vendor bill is already cancelled.'];
        }

        $paidAmount = (float) ($bill['paid_amount'] ?? 0);
        if ($status === 'posted') {
            if ($paidAmount > 0) {
                return ['success' => false, 'message' => 'Cannot cancel a vendor bill that has payments applied.'];
            }

            if (vendorBillSupplierPaymentsTableExists($pdo)) {
                $payStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM supplier_payments WHERE vendor_bill_id = ? AND company_id = ?'
                );
                $payStmt->execute([$billId, $companyId]);
                if ((int) $payStmt->fetchColumn() > 0) {
                    return ['success' => false, 'message' => 'Cannot cancel a vendor bill with supplier payments.'];
                }
            }
        } elseif ($status !== 'draft') {
            return ['success' => false, 'message' => 'This vendor bill cannot be cancelled.'];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE vendor_bills
                 SET status = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                   AND company_id = ?
                   AND status IN (?, ?)'
            );
            $stmt->execute(['cancelled', $billId, $companyId, 'draft', 'posted']);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Vendor bill could not be cancelled.'];
            }

            $pdo->commit();

            return ['success' => true, 'id' => $billId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('cancelVendorBill: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to cancel vendor bill.'];
        }
    }

    function getDefaultApAccountId(PDO $pdo, int $companyId): ?int
    {
        $settingId = vendorBillGetCompanySettingAccountId($pdo, $companyId, 'default_ap_account_id');
        if ($settingId !== null) {
            return $settingId;
        }

        return vendorBillFindErpAccountId($pdo, $companyId, [
            'type' => 'liability',
            'name_patterns' => ['%payable%', '%accounts payable%', '%a/p%'],
            'code_patterns' => ['%2200%', '%2100%', '%AP%'],
        ]);
    }

    function getDefaultInventoryAccountId(PDO $pdo, int $companyId): ?int
    {
        $settingId = vendorBillGetCompanySettingAccountId($pdo, $companyId, 'default_inventory_account_id');
        if ($settingId !== null) {
            return $settingId;
        }

        return vendorBillFindErpAccountId($pdo, $companyId, [
            'type' => 'asset',
            'name_patterns' => ['%inventory%', '%stock%', '%merchandise%'],
            'code_patterns' => ['%1300%', '%1200%', '%INV%'],
        ]);
    }

    /**
     * Post vendor bill to GL (draft -> posted). Does not touch bank/cash or payment vouchers.
     *
     * @return array{success:bool,message?:string,id?:int,journal_entry_id?:int}
     */
    function postVendorBillToJournal(PDO $pdo, int $billId, int $companyId, int $userId): array
    {
        if ($billId <= 0 || $companyId <= 0) {
            return ['success' => false, 'message' => 'Invalid vendor bill.'];
        }

        if (!vendorBillTableExists($pdo)) {
            return ['success' => false, 'message' => 'Vendor bill tables are not available.'];
        }

        $bill = getVendorBillById($pdo, $billId, $companyId);
        if (!$bill) {
            return ['success' => false, 'message' => 'Vendor bill not found.'];
        }

        if (($bill['status'] ?? '') !== 'draft') {
            return ['success' => false, 'message' => 'Only draft vendor bills can be posted.'];
        }

        $items = getVendorBillItems($pdo, $billId, $companyId);
        if (count($items) === 0) {
            return ['success' => false, 'message' => 'Cannot post a vendor bill without line items.'];
        }

        $totalAmount = (float) ($bill['total_amount'] ?? 0);
        if ($totalAmount <= 0) {
            return ['success' => false, 'message' => 'Total amount must be greater than zero.'];
        }

        $apAccountId = getDefaultApAccountId($pdo, $companyId);
        if ($apAccountId === null) {
            return ['success' => false, 'message' => 'Accounts Payable account is not configured. Set default_ap_account_id in company settings or add a payable liability account.'];
        }

        $defaultInventoryAccountId = getDefaultInventoryAccountId($pdo, $companyId);

        $serviceCheck = vendorBillAccountingServiceReady($pdo);
        if (!$serviceCheck['ready']) {
            return ['success' => false, 'message' => $serviceCheck['message']];
        }

        $debitBuckets = [];
        foreach ($items as $item) {
            $lineTotal = round((float) ($item['line_total'] ?? 0), 2);
            if ($lineTotal <= 0) {
                continue;
            }

            $accountId = vendorBillNullableInt($item['account_id'] ?? null);
            if ($accountId === null) {
                $accountId = $defaultInventoryAccountId;
            }

            if ($accountId === null) {
                return ['success' => false, 'message' => 'Inventory or expense account is not configured for one or more bill lines. Set default_inventory_account_id or line account_id.'];
            }

            if (!isset($debitBuckets[$accountId])) {
                $debitBuckets[$accountId] = 0.0;
            }
            $debitBuckets[$accountId] += $lineTotal;
        }

        $debitTotal = round(array_sum($debitBuckets), 2);
        $creditTotal = round($totalAmount, 2);

        if ($debitTotal <= 0) {
            return ['success' => false, 'message' => 'Unable to build journal lines for this vendor bill.'];
        }

        if (abs($debitTotal - $creditTotal) > 0.01) {
            return ['success' => false, 'message' => 'Bill line totals do not match bill total. Recalculate the bill before posting.'];
        }

        $journalItems = [];
        foreach ($debitBuckets as $accountId => $amount) {
            $journalItems[] = [
                'account_id' => (int) $accountId,
                'debit' => round($amount, 2),
                'credit' => 0,
            ];
        }
        $journalItems[] = [
            'account_id' => (int) $apAccountId,
            'debit' => 0,
            'credit' => $creditTotal,
        ];

        $reference = (string) ($bill['bill_number'] ?? ('VB-' . $billId));
        $description = 'Vendor Bill Posted: ' . $reference;
        $billDate = vendorBillNormalizeDate($bill['bill_date'] ?? null) ?: date('Y-m-d');

        if (!class_exists('AccountingService')) {
            $servicePath = dirname(__DIR__, 4) . '/includes/accounting_service.php';
            if (is_file($servicePath)) {
                require_once $servicePath;
            }
        }

        if (!class_exists('AccountingService')) {
            return ['success' => false, 'message' => 'AccountingService is not available.'];
        }

        $service = new AccountingService($pdo);
        $journalEntryId = $service->postEntry($billDate, $reference, $description, $journalItems);

        if (!$journalEntryId) {
            return ['success' => false, 'message' => 'Journal entry could not be created. Check GL schema and account configuration.'];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE vendor_bills
                 SET status = ?,
                     posted_by = ?,
                     posted_at = NOW(),
                     journal_entry_id = ?,
                     payment_status = ?,
                     balance_due = total_amount - paid_amount,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?
                   AND company_id = ?
                   AND status = ?'
            );
            $stmt->execute([
                'posted',
                $userId > 0 ? $userId : null,
                (int) $journalEntryId,
                'unpaid',
                $billId,
                $companyId,
                'draft',
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Vendor bill status was not updated after journal creation. Journal entry #' . (int) $journalEntryId . ' may need review.',
                    'journal_entry_id' => (int) $journalEntryId,
                ];
            }

            $pdo->commit();

            return [
                'success' => true,
                'id' => $billId,
                'journal_entry_id' => (int) $journalEntryId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('postVendorBillToJournal: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Vendor bill posted to journal but status update failed. Journal entry #' . (int) $journalEntryId . ' may need review.',
                'journal_entry_id' => (int) $journalEntryId,
            ];
        }
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    function vendorBillNormalizeLine(array $line, int $sortOrder = 0): array
    {
        $quantity = vendorBillNormalizeMoney($line['quantity'] ?? 0, 4);
        $unitCost = vendorBillNormalizeMoney($line['unit_cost'] ?? 0, 4);
        $taxRate = vendorBillNormalizeMoney($line['tax_rate'] ?? 0, 4);

        $lineSubtotal = round($quantity * $unitCost, 2);
        $taxAmount = round($lineSubtotal * $taxRate / 100, 2);
        $lineTotal = round($lineSubtotal + $taxAmount, 2);

        return [
            'stock_item_id' => vendorBillNullableInt($line['stock_item_id'] ?? null),
            'product_id' => vendorBillNullableInt($line['product_id'] ?? null),
            'description' => vendorBillNullableString($line['description'] ?? null),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'line_subtotal' => $lineSubtotal,
            'line_total' => $lineTotal,
            'account_id' => vendorBillNullableInt($line['account_id'] ?? null),
            'po_item_id' => vendorBillNullableInt($line['po_item_id'] ?? null),
            'sort_order' => isset($line['sort_order']) ? (int) $line['sort_order'] : $sortOrder,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    function vendorBillInsertItems(PDO $pdo, int $billId, int $companyId, array $lines): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO vendor_bill_items (
                vendor_bill_id, company_id, stock_item_id, product_id, description,
                quantity, unit_cost, tax_rate, tax_amount, line_total,
                account_id, po_item_id, sort_order
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?
            )'
        );

        foreach ($lines as $line) {
            $stmt->execute([
                $billId,
                $companyId,
                $line['stock_item_id'] ?? null,
                $line['product_id'] ?? null,
                $line['description'] ?? null,
                $line['quantity'] ?? 0,
                $line['unit_cost'] ?? 0,
                $line['tax_rate'] ?? 0,
                $line['tax_amount'] ?? 0,
                $line['line_total'] ?? 0,
                $line['account_id'] ?? null,
                $line['po_item_id'] ?? null,
                (int) ($line['sort_order'] ?? 0),
            ]);
        }
    }

    function vendorBillNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    function vendorBillNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    function vendorBillNormalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    function vendorBillNormalizeMoney($value, int $scale = 2): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $number = is_numeric($value) ? (float) $value : 0.0;
        return round($number, $scale);
    }

    function vendorBillGetCompanySettingAccountId(PDO $pdo, int $companyId, string $settingKey): ?int
    {
        if ($companyId <= 0 || $settingKey === '') {
            return null;
        }

        if (!function_exists('tableExists') || !tableExists('company_settings', $pdo)) {
            return null;
        }

        if (!function_exists('columnExists') || !columnExists('company_settings', 'setting_key', $pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT setting_value
                 FROM company_settings
                 WHERE company_id = ?
                   AND setting_key = ?
                 LIMIT 1'
            );
            $stmt->execute([$companyId, $settingKey]);
            $value = (int) ($stmt->fetchColumn() ?: 0);

            return $value > 0 ? $value : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array{type?:string,name_patterns?:array<int,string>,code_patterns?:array<int,string>} $criteria
     */
    function vendorBillFindErpAccountId(PDO $pdo, int $companyId, array $criteria): ?int
    {
        if (!function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
            return null;
        }

        $hasCompanyId = function_exists('columnExists') && columnExists('erp_accounts', 'company_id', $pdo);
        $hasType = function_exists('columnExists') && columnExists('erp_accounts', 'type', $pdo);
        $hasName = function_exists('columnExists') && columnExists('erp_accounts', 'name', $pdo);
        $hasCode = function_exists('columnExists') && columnExists('erp_accounts', 'code', $pdo);
        $hasStatus = function_exists('columnExists') && columnExists('erp_accounts', 'status', $pdo);

        $where = ['1=1'];
        $params = [];

        if ($hasCompanyId && $companyId > 0) {
            $where[] = '(company_id = ? OR company_id IS NULL)';
            $params[] = $companyId;
        }

        if ($hasType && !empty($criteria['type'])) {
            $where[] = 'LOWER(COALESCE(type, \'\')) LIKE ?';
            $params[] = '%' . strtolower((string) $criteria['type']) . '%';
        }

        if ($hasStatus) {
            $where[] = "(status IS NULL OR status = '' OR LOWER(status) = 'active')";
        }

        $patternGroups = [];
        if ($hasName && !empty($criteria['name_patterns'])) {
            foreach ($criteria['name_patterns'] as $pattern) {
                $patternGroups[] = 'LOWER(COALESCE(name, \'\')) LIKE ?';
                $params[] = strtolower((string) $pattern);
            }
        }
        if ($hasCode && !empty($criteria['code_patterns'])) {
            foreach ($criteria['code_patterns'] as $pattern) {
                $patternGroups[] = 'LOWER(COALESCE(code, \'\')) LIKE ?';
                $params[] = strtolower((string) $pattern);
            }
        }

        if (count($patternGroups) === 0) {
            return null;
        }

        $where[] = '(' . implode(' OR ', $patternGroups) . ')';

        $sql = 'SELECT id FROM erp_accounts WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $id = (int) ($stmt->fetchColumn() ?: 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{ready:bool,message:string}
     */
    function vendorBillAccountingServiceReady(PDO $pdo): array
    {
        if (!function_exists('tableExists') || !tableExists('erp_journal_entries', $pdo) || !tableExists('erp_journal_items', $pdo)) {
            return [
                'ready' => false,
                'message' => 'General ledger tables (erp_journal_entries / erp_journal_items) are not available.',
            ];
        }

        $requiredEntryColumns = ['date', 'description'];
        $requiredItemColumns = ['journal_id', 'account_id', 'debit', 'credit'];

        foreach ($requiredEntryColumns as $column) {
            if (!function_exists('columnExists') || !columnExists('erp_journal_entries', $column, $pdo)) {
                return [
                    'ready' => false,
                    'message' => 'Journal schema is missing required column erp_journal_entries.' . $column . '.',
                ];
            }
        }

        foreach ($requiredItemColumns as $column) {
            if (!function_exists('columnExists') || !columnExists('erp_journal_items', $column, $pdo)) {
                return [
                    'ready' => false,
                    'message' => 'Journal schema is missing required column erp_journal_items.' . $column . '.',
                ];
            }
        }

        $servicePath = dirname(__DIR__, 4) . '/includes/accounting_service.php';
        if (!is_file($servicePath)) {
            return [
                'ready' => false,
                'message' => 'AccountingService file is not available.',
            ];
        }

        $usesReference = function_exists('columnExists') && columnExists('erp_journal_entries', 'reference', $pdo);
        $usesCreatedAt = function_exists('columnExists') && columnExists('erp_journal_entries', 'created_at', $pdo);

        if (!$usesReference || !$usesCreatedAt) {
            return [
                'ready' => false,
                'message' => 'AccountingService journal schema is incompatible with this database (erp_journal_entries requires reference and created_at columns). Align schema or use manual journal posting before posting vendor bills.',
            ];
        }

        return ['ready' => true, 'message' => 'OK'];
    }
}
