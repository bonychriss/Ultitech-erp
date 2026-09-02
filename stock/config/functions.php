<?php
// config/functions.php

// Stock uploads (ajax_upload.php, etc.)
if (file_exists(dirname(__DIR__, 2) . '/includes/UploadHelper.php')) {
    require_once dirname(__DIR__, 2) . '/includes/UploadHelper.php';
}

if (!function_exists('clean_input')) {
    function clean_input($data) {
        if ($data === null) return '';
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit();
    }
}

// Auth functions helper
if (!function_exists('hasRole')) {
    function hasRole($roles) {
        if (!isset($_SESSION['role'])) return false;
        if (is_array($roles)) {
            return in_array($_SESSION['role'], $roles);
        }
        return $_SESSION['role'] === $roles;
    }
}

if (!function_exists('requireRole')) {
    function requireRole($roles) {
        requireLogin();
        if (!hasRole($roles)) {
            require_once __DIR__ . '/paths.php';
            header('Location: ' . $rootPath . 'select-module.php?error=access_denied');
            exit();
        }
    }
}

if (!function_exists('flash')) {
    function flash($name, $text = '', $type = 'success') {
        if ($text != '') {
            $_SESSION[$name] = $text;
            $_SESSION[$name.'_type'] = $type;
        } else {
            if (isset($_SESSION[$name])) {
                $type = isset($_SESSION[$name.'_type']) ? $_SESSION[$name.'_type'] : 'success';
                $class = ($type == 'error') ? 'danger' : $type;
                echo '<div class="alert alert-' . $class . ' alert-dismissible fade show" role="alert">
                        ' . $_SESSION[$name] . '
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>';
                unset($_SESSION[$name]);
                unset($_SESSION[$name.'_type']);
            }
        }
    }
}

// Settings Helper
if (!function_exists('getCompanySettings')) {
    function getCompanySettings($pdo) {
        if (isset($GLOBALS['company_settings_cache'])) {
            return $GLOBALS['company_settings_cache'];
        }

        $settings = null;
        try {
            $stmt = $pdo->query('SELECT * FROM company_settings LIMIT 1');
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $settings = null;
        }

        if (!$settings) {
            $settings = array('currency' => 'TZS', 'company_name' => 'My Company');
        }

        $GLOBALS['company_settings_cache'] = $settings;
        return $settings;
    }
}

// Helper to convert currency for display
if (!function_exists('convertCurrency')) {
    function convertCurrency($amount, $rate = 1) {
       if (!is_numeric($amount)) return 0;
       return $amount * $rate;
    }
}

if (!function_exists('getCurrencySymbol')) {
    function getCurrencySymbol($currencyCode) {
        $code = strtoupper(trim((string) $currencyCode));
        $symbols = [
            'TZS' => 'TSh ',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'KES' => 'KSh ',
            'UGX' => 'USh ',
            'RWF' => 'RWF ',
            'ZAR' => 'R ',
            'CNY' => '¥',
            'INR' => '₹',
            'AED' => 'AED ',
            'SAR' => 'SAR ',
            'JPY' => '¥',
            'CHF' => 'CHF ',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'SGD' => 'S$',
            'HKD' => 'HK$',
            'NGN' => '₦',
            'GHS' => 'GH₵',
            'ZMW' => 'ZK ',
            'MZN' => 'MT ',
            'BIF' => 'BIF ',
            'MWK' => 'MK ',
            'EGP' => 'E£',
            'QAR' => 'QAR ',
            'KWD' => 'KD ',
            'OMR' => 'OMR ',
            'BHD' => 'BHD ',
            'SEK' => 'kr ',
            'NOK' => 'kr ',
            'DKK' => 'kr ',
            'PLN' => 'zł',
            'TRY' => '₺',
            'BRL' => 'R$',
            'MXN' => 'MX$',
            'NZD' => 'NZ$',
            'PKR' => '₨',
            'MYR' => 'RM ',
            'KRW' => '₩',
            'BWP' => 'P ',
            'NAD' => 'N$',
        ];

        return $symbols[$code] ?? ($code !== '' ? $code . ' ' : '$');
    }
}

/**
 * Ensure stock_movements exists (referenced by shipments / stock history).
 * Safe no-op if the table is already present.
 */
if (!function_exists('ensureStockMovementsTable')) {
    function ensureStockMovementsTable(PDO $pdo)
    {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'stock_movements'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($exists) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE stock_movements (
                id INT(11) NOT NULL AUTO_INCREMENT,
                product_id INT(11) NOT NULL,
                movement_type ENUM('in','out','adjustment') NOT NULL,
                quantity INT(11) NOT NULL,
                reference_type ENUM('purchase','sale','adjustment') NOT NULL,
                reference_id VARCHAR(50) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_stock_movements_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('ensureStockMovementsTable: ' . $e->getMessage());
        }
    }
}

/**
 * Pending warehouse receipts — procurement records delivery; store manager verifies before stock is added.
 */
if (!function_exists('ensureStoreWarehouseReceiptsTable')) {
    function ensureStoreWarehouseReceiptsTable(PDO $pdo): void
    {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'store_warehouse_receipts'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if (!$exists) {
            try {
                $pdo->exec("CREATE TABLE store_warehouse_receipts (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    warehouse_id INT(11) NOT NULL,
                    product_id INT(11) NOT NULL,
                    po_id INT(11) DEFAULT NULL,
                    po_line_id INT(11) DEFAULT NULL,
                    po_reference VARCHAR(100) DEFAULT NULL,
                    qty_expected DECIMAL(12,2) NOT NULL DEFAULT 0,
                    qty_verified DECIMAL(12,2) DEFAULT NULL,
                    qty_original_expected DECIMAL(12,2) DEFAULT NULL,
                    qty_prior_received DECIMAL(12,2) DEFAULT NULL,
                    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                    procured_notes TEXT DEFAULT NULL,
                    verify_notes TEXT DEFAULT NULL,
                    procured_by INT(11) DEFAULT NULL,
                    verified_by INT(11) DEFAULT NULL,
                    procured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    verified_at TIMESTAMP NULL DEFAULT NULL,
                    company_id INT(11) DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_swr_warehouse_status (warehouse_id, status),
                    KEY idx_swr_product (product_id),
                    KEY idx_swr_po (po_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            } catch (Throwable $e) {
                error_log('ensureStoreWarehouseReceiptsTable: ' . $e->getMessage());
            }

            return;
        }

        // Existing installs: add partial-confirm tracking columns when missing.
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM store_warehouse_receipts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('qty_original_expected', $cols, true)) {
                $pdo->exec('ALTER TABLE store_warehouse_receipts ADD COLUMN qty_original_expected DECIMAL(12,2) DEFAULT NULL AFTER qty_verified');
            }
            if (!in_array('qty_prior_received', $cols, true)) {
                $pdo->exec('ALTER TABLE store_warehouse_receipts ADD COLUMN qty_prior_received DECIMAL(12,2) DEFAULT NULL AFTER qty_original_expected');
            }
        } catch (Throwable $e) {
            error_log('ensureStoreWarehouseReceiptsTable alter: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensureStoreReleaseDocumentsTable')) {
    function ensureStoreReleaseDocumentsTable(PDO $pdo): void
    {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'store_release_documents'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($exists) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE store_release_documents (
                id INT(11) NOT NULL AUTO_INCREMENT,
                delivery_id INT(11) NOT NULL,
                invoice_id INT(11) NOT NULL,
                warehouse_id INT(11) NOT NULL,
                doc_type ENUM('supporting','invoice') NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                uploaded_by INT(11) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_srd_delivery (delivery_id),
                KEY idx_srd_invoice (invoice_id),
                KEY idx_srd_warehouse (warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('ensureStoreReleaseDocumentsTable: ' . $e->getMessage());
        }
    }
}

if (!function_exists('storeReleaseRecordDocuments')) {
    /**
     * @param array<int, array{doc_type: string, file_path: string, original_name: string}> $documents
     */
    function storeReleaseRecordDocuments(
        PDO $pdo,
        int $deliveryId,
        int $invoiceId,
        int $warehouseId,
        array $documents,
        ?int $userId = null
    ): void {
        ensureStoreReleaseDocumentsTable($pdo);
        if ($documents === []) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO store_release_documents (delivery_id, invoice_id, warehouse_id, doc_type, file_path, original_name, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($documents as $doc) {
            $stmt->execute([
                $deliveryId,
                $invoiceId,
                $warehouseId,
                (string) ($doc['doc_type'] ?? 'supporting'),
                (string) ($doc['file_path'] ?? ''),
                (string) ($doc['original_name'] ?? ''),
                $userId,
            ]);
        }
    }
}

if (!function_exists('ensureStoreReceiptDocumentsTable')) {
    function ensureStoreReceiptDocumentsTable(PDO $pdo): void
    {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'store_receipt_documents'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($exists) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE store_receipt_documents (
                id INT(11) NOT NULL AUTO_INCREMENT,
                receipt_id INT(11) NOT NULL,
                warehouse_id INT(11) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                uploaded_by INT(11) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_srdc_receipt (receipt_id),
                KEY idx_srdc_warehouse (warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('ensureStoreReceiptDocumentsTable: ' . $e->getMessage());
        }
    }
}

if (!function_exists('storeReceiptRecordDocuments')) {
    /**
     * @param array<int, int> $receiptIds
     * @param array<int, array{file_path: string, original_name: string}> $documents
     */
    function storeReceiptRecordDocuments(
        PDO $pdo,
        array $receiptIds,
        int $warehouseId,
        array $documents,
        ?int $userId = null
    ): void {
        ensureStoreReceiptDocumentsTable($pdo);
        if ($receiptIds === [] || $documents === [] || $warehouseId <= 0) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO store_receipt_documents (receipt_id, warehouse_id, file_path, original_name, uploaded_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($receiptIds as $receiptId) {
            $receiptId = (int) $receiptId;
            if ($receiptId <= 0) {
                continue;
            }
            foreach ($documents as $doc) {
                $path = trim((string) ($doc['file_path'] ?? ''));
                $name = trim((string) ($doc['original_name'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $stmt->execute([$receiptId, $warehouseId, $path, $name !== '' ? $name : basename($path), $userId]);
            }
        }
    }
}

if (!function_exists('storeReceiptFetchDocuments')) {
    /**
     * @param array<int, int> $receiptIds
     * @return array<int, list<array{id:int,receipt_id:int,file_path:string,original_name:string}>>
     */
    function storeReceiptFetchDocuments(PDO $pdo, array $receiptIds): array
    {
        ensureStoreReceiptDocumentsTable($pdo);
        $ids = array_values(array_filter(array_map('intval', $receiptIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, receipt_id, file_path, original_name
             FROM store_receipt_documents
             WHERE receipt_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rid = (int) ($row['receipt_id'] ?? 0);
            if (!isset($grouped[$rid])) {
                $grouped[$rid] = [];
            }
            $grouped[$rid][] = [
                'id' => (int) ($row['id'] ?? 0),
                'receipt_id' => $rid,
                'file_path' => (string) ($row['file_path'] ?? ''),
                'original_name' => (string) ($row['original_name'] ?? ''),
            ];
        }

        return $grouped;
    }
}

if (!function_exists('storeReceiptActiveCompanyId')) {
    function storeReceiptActiveCompanyId(): int
    {
        if (function_exists('stockPurchaseActiveCompanyId')) {
            return (int) stockPurchaseActiveCompanyId();
        }
        if (function_exists('currentCompanyId')) {
            return (int) (currentCompanyId() ?? 0);
        }

        return 0;
    }
}

if (!function_exists('storeReceiptCreatePending')) {
    function storeReceiptCreatePending(
        PDO $pdo,
        int $warehouseId,
        int $productId,
        float $qty,
        ?int $poId = null,
        ?int $poLineId = null,
        ?string $poReference = null,
        ?string $notes = null,
        ?int $userId = null,
        ?float $qtyOriginalExpected = null,
        ?float $qtyPriorReceived = null
    ): int {
        ensureStoreWarehouseReceiptsTable($pdo);
        if ($warehouseId <= 0 || $productId <= 0 || $qty <= 0) {
            return 0;
        }

        $companyId = storeReceiptActiveCompanyId();
        $fields = ['warehouse_id', 'product_id', 'qty_expected', 'status', 'procured_notes', 'procured_by'];
        $values = [$warehouseId, $productId, $qty, 'pending', $notes, $userId];
        $placeholders = ['?', '?', '?', '?', '?', '?'];

        if ($poId !== null && $poId > 0) {
            $fields[] = 'po_id';
            $values[] = $poId;
            $placeholders[] = '?';
        }
        if ($poLineId !== null && $poLineId > 0) {
            $fields[] = 'po_line_id';
            $values[] = $poLineId;
            $placeholders[] = '?';
        }
        if ($poReference !== null && $poReference !== '') {
            $fields[] = 'po_reference';
            $values[] = $poReference;
            $placeholders[] = '?';
        }
        if ($qtyOriginalExpected !== null && $qtyOriginalExpected > 0) {
            $fields[] = 'qty_original_expected';
            $values[] = $qtyOriginalExpected;
            $placeholders[] = '?';
        }
        if ($qtyPriorReceived !== null && $qtyPriorReceived > 0) {
            $fields[] = 'qty_prior_received';
            $values[] = $qtyPriorReceived;
            $placeholders[] = '?';
        }
        if ($companyId > 0) {
            $fields[] = 'company_id';
            $values[] = $companyId;
            $placeholders[] = '?';
        }

        $sql = 'INSERT INTO store_warehouse_receipts (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $pdo->prepare($sql)->execute($values);

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('storeReceiptFetchPending')) {
    function storeReceiptFetchPending(PDO $pdo, int $warehouseId, int $limit = 100): array
    {
        ensureStoreWarehouseReceiptsTable($pdo);
        if ($warehouseId <= 0) {
            return [];
        }

        $companyId = storeReceiptActiveCompanyId();
        $sql = "SELECT r.*, p.name AS product_name, p.product_code
                FROM store_warehouse_receipts r
                INNER JOIN products p ON p.id = r.product_id
                WHERE r.warehouse_id = ? AND r.status = 'pending'";
        $params = [$warehouseId];
        if ($companyId > 0) {
            $sql .= ' AND (r.company_id IS NULL OR r.company_id = 0 OR r.company_id = ?)';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY r.procured_at ASC LIMIT ' . max(1, min(200, $limit));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('storeReceiptVerify')) {
    function storeReceiptVerify(
        PDO $pdo,
        int $receiptId,
        int $warehouseId,
        float $qtyVerified,
        ?string $notes,
        ?int $userId = null
    ): array {
        ensureStoreWarehouseReceiptsTable($pdo);
        if ($receiptId <= 0 || $warehouseId <= 0 || $qtyVerified < 0) {
            return ['ok' => false, 'message' => 'Invalid verification request.'];
        }

        $stmt = $pdo->prepare('SELECT * FROM store_warehouse_receipts WHERE id = ? AND warehouse_id = ? AND status = ? LIMIT 1');
        $stmt->execute([$receiptId, $warehouseId, 'pending']);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$receipt) {
            return ['ok' => false, 'message' => 'Pending receipt not found or already processed.'];
        }

        $expected = (float) ($receipt['qty_expected'] ?? 0);
        $notesTrim = $notes !== null ? trim($notes) : '';

        if ($qtyVerified <= 0) {
            $pdo->prepare("UPDATE store_warehouse_receipts SET status = 'rejected', qty_verified = 0, verify_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$notesTrim !== '' ? $notesTrim : 'Rejected at store verification', $userId, $receiptId]);

            return ['ok' => true, 'message' => 'Receipt rejected — no stock was added.', 'stock' => 0];
        }

        if ($qtyVerified > $expected && $expected > 0) {
            $qtyVerified = $expected;
        }

        $isShortfall = $expected > 0 && ($expected - $qtyVerified) > 0.0001;
        if ($isShortfall && $notesTrim === '') {
            return [
                'ok' => false,
                'message' => 'Verified quantity is lower than expected. Explain why before confirming.',
            ];
        }

        $productId = (int) $receipt['product_id'];
        $poRef = trim((string) ($receipt['po_reference'] ?? ''));
        if ($poRef === '' && !empty($receipt['po_id'])) {
            $poRef = 'PO#' . (int) $receipt['po_id'];
        }
        $remainingQty = $isShortfall ? max(0, $expected - $qtyVerified) : 0.0;

        if (!function_exists('stockIncrementProductStock')) {
            return ['ok' => false, 'message' => 'Stock update helper is not available.'];
        }

        // Schema/DDL helpers (CREATE/ALTER) cause MySQL to implicitly commit.
        // Run them before starting the data transaction.
        if (function_exists('ensureWarehousesSchema')) {
            ensureWarehousesSchema($pdo);
        }
        if (function_exists('ensureStoreReceiptDocumentsTable')) {
            ensureStoreReceiptDocumentsTable($pdo);
        }

        $hasStocksSync = function_exists('tableExists')
            && tableExists('stocks_po_items')
            && tableExists('stocks_items');
        $hasStockMovements = function_exists('tableExists') && tableExists('stock_movements');
        $mvCols = [];
        if ($hasStockMovements) {
            $mvCols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            stockIncrementProductStock($pdo, $productId, $qtyVerified, $warehouseId);

            // Keep stocks catalogue qty in sync when this receipt came from a stocks PO line.
            $poLineId = (int) ($receipt['po_line_id'] ?? 0);
            if ($hasStocksSync && $poLineId > 0) {
                $poLineStmt = $pdo->prepare('SELECT item_id FROM stocks_po_items WHERE id = ? LIMIT 1');
                $poLineStmt->execute([$poLineId]);
                $stocksItemId = (int) ($poLineStmt->fetchColumn() ?: 0);
                if ($stocksItemId > 0) {
                    $pdo->prepare('UPDATE stocks_items SET stock_quantity = COALESCE(stock_quantity, 0) + ? WHERE id = ?')
                        ->execute([$qtyVerified, $stocksItemId]);
                }
            }

            $movementNote = 'Store verified receipt';
            if ($poRef !== '') {
                $movementNote = 'Verified receipt for ' . $poRef;
            }
            if (abs($qtyVerified - $expected) > 0.0001) {
                $movementNote .= sprintf(' (expected %s, verified %s)', $expected, $qtyVerified);
            }
            if ($notesTrim !== '') {
                $movementNote .= ' — ' . $notesTrim;
            }

            if ($hasStockMovements) {
                $refType = 'purchase';
                $refId = (string) ((int) ($receipt['po_id'] ?? 0));
                $fields = ['product_id', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_at'];
                $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()'];
                $values = [$productId, 'in', $qtyVerified, $refType, $refId, $movementNote];
                if (in_array('warehouse_id', $mvCols, true)) {
                    array_splice($fields, 1, 0, ['warehouse_id']);
                    array_splice($placeholders, 1, 0, ['?']);
                    array_splice($values, 1, 0, [$warehouseId]);
                }
                $quoted = implode(', ', array_map(static fn(string $f) => "`$f`", $fields));
                $pdo->prepare("INSERT INTO stock_movements ($quoted) VALUES (" . implode(', ', $placeholders) . ')')
                    ->execute($values);
            }

            $verifyNotes = $notesTrim;
            if ($remainingQty > 0) {
                $verifyNotes = trim(
                    ($verifyNotes !== '' ? $verifyNotes . ' | ' : '')
                    . sprintf('Partial confirm: %s of %s. Remaining %s left pending.', $qtyVerified, $expected, $remainingQty)
                );
            }

            $pdo->prepare("UPDATE store_warehouse_receipts SET status = 'verified', qty_verified = ?, verify_notes = ?, verified_by = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$qtyVerified, $verifyNotes !== '' ? $verifyNotes : null, $userId, $receiptId]);

            $remainderReceiptId = 0;
            if ($remainingQty > 0 && function_exists('storeReceiptCreatePending')) {
                $origExpected = (float) ($receipt['qty_original_expected'] ?? 0);
                if ($origExpected <= 0) {
                    $origExpected = $expected;
                }
                $priorReceived = (float) ($receipt['qty_prior_received'] ?? 0) + $qtyVerified;
                $remainderNote = $notesTrim !== ''
                    ? ('Shortfall reason: ' . $notesTrim)
                    : 'Remaining after partial confirm';
                $remainderReceiptId = storeReceiptCreatePending(
                    $pdo,
                    $warehouseId,
                    $productId,
                    $remainingQty,
                    !empty($receipt['po_id']) ? (int) $receipt['po_id'] : null,
                    !empty($receipt['po_line_id']) ? (int) $receipt['po_line_id'] : null,
                    $poRef !== '' ? $poRef : null,
                    $remainderNote,
                    $userId,
                    $origExpected,
                    $priorReceived
                );
                if ($remainderReceiptId <= 0) {
                    throw new Exception('Verified stock was added, but remaining quantity could not be kept pending.');
                }

                // Carry delivery attachments forward so the remainder can still be reviewed.
                if (function_exists('storeReceiptFetchDocuments') && function_exists('storeReceiptRecordDocuments')) {
                    $docs = storeReceiptFetchDocuments($pdo, [$receiptId]);
                    $srcDocs = $docs[$receiptId] ?? [];
                    if ($srcDocs !== []) {
                        storeReceiptRecordDocuments($pdo, [$remainderReceiptId], $warehouseId, $srcDocs, $userId);
                    }
                }
            }

            if ($pdo->inTransaction()) {
                try {
                    $pdo->commit();
                } catch (Throwable $commitErr) {
                    // PDO can still report inTransaction() after MySQL implicit-commit from DDL.
                    if (stripos($commitErr->getMessage(), 'no active transaction') === false) {
                        throw $commitErr;
                    }
                }
            }

            if ($remainingQty > 0) {
                return [
                    'ok' => true,
                    'message' => sprintf(
                        'Confirmed %s into stock. Remaining %s kept pending — fill it later with a reason.',
                        $qtyVerified,
                        $remainingQty
                    ),
                    'stock' => $qtyVerified,
                    'remaining' => $remainingQty,
                    'remainder_receipt_id' => $remainderReceiptId,
                ];
            }

            return ['ok' => true, 'message' => 'Stock verified and added to warehouse.', 'stock' => $qtyVerified];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

/**
 * Ensure brands table exists (products UI / spare import).
 */
if (!function_exists('ensureBrandsTable')) {
    function ensureBrandsTable(PDO $pdo)
    {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'brands'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($exists) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE brands (
                id INT(11) NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                brand_type VARCHAR(50) DEFAULT 'spare_part',
                logo VARCHAR(255) DEFAULT NULL,
                meta_title VARCHAR(255) DEFAULT NULL,
                meta_description TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_brands_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            error_log('ensureBrandsTable: ' . $e->getMessage());
        }
    }
}

/**
 * PDO for the active company tenant (Roadmaster vs Ultimate), not the shared DATA_DB fallback.
 */
if (!function_exists('stock_company_pdo')) {
    function stock_company_pdo()
    {
        global $pdo, $control_pdo;

        if (isset($GLOBALS['tenant_pdo']) && $GLOBALS['tenant_pdo'] instanceof PDO) {
            return $GLOBALS['tenant_pdo'];
        }

        $cid = 0;
        if (function_exists('currentCompanyId')) {
            $cid = (int) (currentCompanyId() ?? 0);
        }
        if ($cid <= 0 && !empty($_SESSION['company_id'])) {
            $cid = (int) $_SESSION['company_id'];
        }

        $meta = ($control_pdo instanceof PDO) ? $control_pdo : $pdo;
        if ($cid > 0 && $meta instanceof PDO && function_exists('tableExists') && tableExists('companies', $meta)) {
            try {
                $hasHost = columnExists('companies', 'db_host', $meta);
                $hasUser = columnExists('companies', 'db_user', $meta);
                $hasPass = columnExists('companies', 'db_pass', $meta);
                $cols = 'db_name' . ($hasHost ? ', db_host' : '') . ($hasUser ? ', db_user' : '') . ($hasPass ? ', db_pass' : '');
                $st = $meta->prepare('SELECT ' . $cols . ' FROM companies WHERE id = ? LIMIT 1');
                $st->execute(array($cid));
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: array();
                $dbName = trim((string) ($row['db_name'] ?? ''));
                if ($dbName !== '' && function_exists('connectToTenantDatabase')) {
                    $tenantPdo = connectToTenantDatabase(
                        $dbName,
                        $hasHost ? trim((string) ($row['db_host'] ?? '')) : null,
                        $hasUser ? trim((string) ($row['db_user'] ?? '')) : null,
                        $hasPass && array_key_exists('db_pass', $row) ? (string) $row['db_pass'] : null
                    );
                    if ($tenantPdo instanceof PDO) {
                        return $tenantPdo;
                    }
                }
            } catch (Throwable $e) {
                error_log('stock_company_pdo: ' . $e->getMessage());
            }
        }

        return ($pdo instanceof PDO) ? $pdo : $control_pdo;
    }
}

/**
 * Legacy on-disk product uploads root for this company (slug-scoped when present).
 */
if (!function_exists('stock_uploads_shared_legacy_products_dir')) {
    /** Flat pre-tenant tree: stock/uploads/products (shared across companies). */
    function stock_uploads_shared_legacy_products_dir()
    {
        $legacy = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';

        return (is_dir($legacy) ? (realpath($legacy) ?: $legacy) : null);
    }
}

if (!function_exists('stock_uploads_legacy_products_dir')) {
    function stock_uploads_legacy_products_dir($companySlug = '')
    {
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        $slug = strtolower(trim((string) $companySlug));
        if ($slug === '' && !empty($_SESSION['company_slug'])) {
            $slug = strtolower(trim((string) $_SESSION['company_slug']));
        }
        if ($slug !== '') {
            $candidates = array(
                $base . DIRECTORY_SEPARATOR . 'companies' . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'products',
                $base . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'products',
            );
            foreach ($candidates as $scoped) {
                if (is_dir($scoped)) {
                    return realpath($scoped) ?: $scoped;
                }
            }

            // Do not fall back to flat stock/uploads/products when a company slug is known.
            return null;
        }
        $legacy = $base . DIRECTORY_SEPARATOR . 'products';

        return (is_dir($legacy) ? (realpath($legacy) ?: $legacy) : null);
    }
}

if (!function_exists('stock_uploads_tenant_base_dir')) {
    function stock_uploads_tenant_base_dir($companyId = 0)
    {
        $companyId = (int) $companyId;
        if ($companyId <= 0 && function_exists('currentCompanyId')) {
            $companyId = (int) currentCompanyId();
        }
        if ($companyId <= 0) {
            return null;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;

        return (is_dir($path) ? (realpath($path) ?: $path) : null);
    }
}

if (!function_exists('stock_product_upload_base_dir')) {
    /**
     * Writable base directory for product image processing.
     * ImageProcessor appends /products/{id}/..., so this returns the company base root.
     */
    function stock_product_upload_base_dir($companyId = 0, $companySlug = '')
    {
        $companyId = (int) $companyId;
        $companySlug = strtolower(trim((string) $companySlug));

        if ($companyId <= 0 && function_exists('currentCompanyId')) {
            $companyId = (int) currentCompanyId();
        }
        if ($companyId <= 0 && !empty($_SESSION['company_id'])) {
            $companyId = (int) $_SESSION['company_id'];
        }
        if ($companySlug === '' && !empty($_SESSION['company_slug'])) {
            $companySlug = strtolower(trim((string) $_SESSION['company_slug']));
        }
        if ($companyId <= 0) {
            if ($companySlug === 'ultimate') {
                $companyId = 1;
            } elseif ($companySlug === 'roadmaster') {
                $companyId = 2;
            }
        }

        if ($companyId > 0) {
            $tenantBase = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;
            if (!is_dir($tenantBase)) {
                @mkdir($tenantBase, 0755, true);
            }
            if (is_dir($tenantBase)) {
                return realpath($tenantBase) ?: $tenantBase;
            }
        }

        $legacyRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
        if ($companySlug !== '') {
            $scopedBases = array(
                $legacyRoot . DIRECTORY_SEPARATOR . 'companies' . DIRECTORY_SEPARATOR . $companySlug,
                $legacyRoot . DIRECTORY_SEPARATOR . $companySlug,
            );
            foreach ($scopedBases as $base) {
                if (!is_dir($base)) {
                    @mkdir($base, 0755, true);
                }
                if (is_dir($base)) {
                    return realpath($base) ?: $base;
                }
            }
        }

        if (!is_dir($legacyRoot)) {
            @mkdir($legacyRoot, 0755, true);
        }

        return (is_dir($legacyRoot) ? (realpath($legacyRoot) ?: $legacyRoot) : null);
    }
}

if (!function_exists('stock_uploads_resolve_delete_base_dir')) {
    /**
     * Absolute base directory for delete/recycle (paths are relative to this root, usually products/…).
     *
     * @param string $source tenant|legacy
     */
    function stock_uploads_resolve_delete_base_dir($source, $companyId = 0, $companySlug = '', $folder = 'products')
    {
        $source = strtolower(trim((string) $source));
        $companyId = (int) $companyId;
        if ($companyId <= 0 && function_exists('stock_uploads_resolve_company_id')) {
            $companyId = stock_uploads_resolve_company_id();
        }

        if ($source === 'tenant') {
            return stock_uploads_tenant_base_dir($companyId);
        }

        if (function_exists('stock_uploads_allow_shared_legacy') && !stock_uploads_allow_shared_legacy()) {
            $scoped = stock_uploads_legacy_products_dir($companySlug);
            if (!$scoped) {
                return null;
            }
            $parent = dirname($scoped);
            if (basename(str_replace('\\', '/', $parent)) === 'uploads') {
                return realpath($parent) ?: $parent;
            }

            return realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads') ?: null;
        }

        $legacyRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
        if (!$legacyRoot) {
            return null;
        }
        $folder = strtolower(trim((string) $folder));
        if ($folder === 'products') {
            $scoped = stock_uploads_legacy_products_dir($companySlug);
            if ($scoped) {
                $parent = dirname($scoped);
                if (basename(str_replace('\\', '/', $parent)) === 'uploads') {
                    return $parent;
                }

                return $legacyRoot;
            }
        }

        return $legacyRoot;
    }
}

/**
 * Product IDs that belong to the active company database only.
 *
 * @return array<int,bool>
 */
if (!function_exists('stock_uploads_company_product_ids')) {
    function stock_uploads_company_product_ids(PDO $pdo = null)
    {
        static $cache = array();
        $conn = ($pdo instanceof PDO) ? $pdo : stock_company_pdo();
        $dbKey = 'default';
        try {
            $dbKey = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
        }
        if (isset($cache[$dbKey])) {
            return $cache[$dbKey];
        }
        $ids = array();
        try {
            foreach ($conn->query('SELECT id FROM products')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $ids[(int) $pid] = true;
            }
            $st = $conn->query('SELECT DISTINCT product_id FROM product_images');
            if ($st) {
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $ids[(int) $pid] = true;
                }
            }
        } catch (Throwable $e) {
        }
        $cache[$dbKey] = $ids;
        return $ids;
    }
}

/**
 * Resolve on-disk path for a product image (tenant storage, slug-scoped legacy, shared legacy).
 *
 * @return string|null Absolute file path
 */
if (!function_exists('stock_resolve_product_image_file')) {
    function stock_resolve_product_image_file($productId, $size = 'medium', $filename = '', $companySlug = '', $companyId = 0)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return null;
        }
        $size = strtolower(trim((string) $size));
        if (!in_array($size, array('thumbnail', 'medium', 'large', 'original'), true)) {
            $size = 'medium';
        }
        $filename = basename(str_replace('\\', '/', trim((string) $filename)));
        $companyId = (int) $companyId;
        $slug = strtolower(trim((string) $companySlug));

        $roots = array();
        $tenantOnly = ($companyId > 0 || $slug !== '');

        if ($companyId > 0) {
            $tenant = realpath(dirname(__DIR__, 2) . '/storage/tenant_' . $companyId . '/products');
            if ($tenant && is_dir($tenant)) {
                $roots[] = $tenant;
            }
        }
        if (function_exists('stock_uploads_legacy_products_dir')) {
            $legacyScoped = stock_uploads_legacy_products_dir($slug);
            if ($legacyScoped && is_dir($legacyScoped)) {
                $roots[] = $legacyScoped;
            }
        }
        // Shared flat stock/uploads/products/ must not be used for a known tenant (ID collision → wrong company image).
        $allowSharedFlat = !$tenantOnly
            || (function_exists('stock_uploads_allow_shared_legacy') && stock_uploads_allow_shared_legacy());
        if ($allowSharedFlat) {
            $legacyFlat = realpath(dirname(__DIR__) . '/uploads/products');
            if ($legacyFlat && is_dir($legacyFlat)) {
                $roots[] = $legacyFlat;
            }
        }
        $roots = array_values(array_unique(array_filter($roots)));

        $sizeOrder = array_unique(array($size, 'medium', 'thumbnail', 'large', 'original'));

        foreach ($roots as $base) {
            if ($filename !== '') {
                foreach ($sizeOrder as $sz) {
                    $candidate = $base . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . $sz . DIRECTORY_SEPARATOR . $filename;
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            } else {
                foreach ($sizeOrder as $sz) {
                    $dir = $base . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . $sz;
                    if (!is_dir($dir)) {
                        continue;
                    }
                    $patterns = array(
                        $dir . DIRECTORY_SEPARATOR . '*.jpg',
                        $dir . DIRECTORY_SEPARATOR . '*.jpeg',
                        $dir . DIRECTORY_SEPARATOR . '*.png',
                        $dir . DIRECTORY_SEPARATOR . '*.gif',
                        $dir . DIRECTORY_SEPARATOR . '*.webp',
                    );
                    $candidates = array();
                    foreach ($patterns as $pattern) {
                        $found = glob($pattern);
                        if (is_array($found)) {
                            foreach ($found as $f) {
                                if (is_file($f)) {
                                    $candidates[] = $f;
                                }
                            }
                        }
                    }
                    if (!empty($candidates)) {
                        usort($candidates, function ($a, $b) {
                            return (filemtime($b) ?: 0) - (filemtime($a) ?: 0);
                        });
                        return $candidates[0];
                    }
                }
            }
        }

        // Backward-compatibility for products uploaded before tenant-aware writes were fixed.
        // Only use the shared flat tree when a specific filename is known to avoid ambiguous cross-tenant picks.
        if ($tenantOnly && $filename !== '') {
            $sharedLegacy = function_exists('stock_uploads_shared_legacy_products_dir')
                ? stock_uploads_shared_legacy_products_dir()
                : null;
            if ($sharedLegacy && is_dir($sharedLegacy)) {
                foreach ($sizeOrder as $sz) {
                    $candidate = $sharedLegacy . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . $sz . DIRECTORY_SEPARATOR . $filename;
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }
}

/**
 * Company slug + id for image resolution (session + rewrite query).
 */
if (!function_exists('stock_image_company_context')) {
    function stock_image_company_context()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $slug = strtolower(trim((string) ($_GET['company_slug'] ?? '')));
        if ($slug === '') {
            $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($slug === '' && stripos($uri, '/roadmaster/') !== false) {
            $slug = 'roadmaster';
        } elseif ($slug === '' && stripos($uri, '/ultimate/') !== false) {
            $slug = 'ultimate';
        }
        if ($slug === '' && function_exists('getRequestedCompanySlug')) {
            $slug = strtolower(trim((string) getRequestedCompanySlug()));
        }

        $companyId = 0;
        if (function_exists('currentCompanyId')) {
            $companyId = (int) (currentCompanyId() ?? 0);
        }
        if ($companyId <= 0) {
            $companyId = (int) ($_SESSION['company_id'] ?? 0);
        }

        $slugToId = array('ultimate' => 1, 'roadmaster' => 2);
        if ($slug !== '' && isset($slugToId[$slug])) {
            if ($companyId <= 0 || $companyId !== (int) $slugToId[$slug]) {
                $companyId = (int) $slugToId[$slug];
            }
        } elseif ($companyId > 0) {
            if ($companyId === 2) {
                $slug = 'roadmaster';
            } elseif ($companyId === 1) {
                $slug = 'ultimate';
            }
        }

        if ($companyId <= 0) {
            $companyId = ($slug === 'roadmaster') ? 2 : 1;
        }
        if ($slug === '' && $companyId === 2) {
            $slug = 'roadmaster';
        } elseif ($slug === '' && $companyId === 1) {
            $slug = 'ultimate';
        }

        return array('slug' => $slug, 'company_id' => $companyId);
    }
}

/**
 * Writable directory for category cover/icon/banner files.
 */
if (!function_exists('stock_category_upload_dir')) {
    function stock_category_upload_dir()
    {
        $ctx = function_exists('stock_image_company_context')
            ? stock_image_company_context()
            : array('company_id' => 0, 'slug' => '');
        $companyId = (int) ($ctx['company_id'] ?? 0);

        if ($companyId > 0) {
            $tenantBase = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;
            if (!is_dir($tenantBase)) {
                @mkdir($tenantBase, 0755, true);
            }
            $dir = $tenantBase . DIRECTORY_SEPARATOR . 'categories';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir)) {
                return realpath($dir) ?: $dir;
            }
        }

        $legacy = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'categories';
        if (!is_dir($legacy)) {
            @mkdir($legacy, 0755, true);
        }

        return realpath($legacy) ?: $legacy;
    }
}

/**
 * Public URL for a category image. Never uses /{company}/stock/uploads/ (those 404).
 */
if (!function_exists('stock_category_image_url')) {
    function stock_category_image_url($filename)
    {
        $raw = trim(str_replace('\\', '/', (string) $filename));
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        $ctx = function_exists('stock_image_company_context')
            ? stock_image_company_context()
            : array('company_id' => 0, 'slug' => '');
        $companyId = (int) ($ctx['company_id'] ?? 0);

        if (preg_match('#^storage/#i', $raw)) {
            $clean = ltrim($raw, '/');
            if (function_exists('app_url')) {
                return app_url('/' . $clean);
            }
            $base = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');

            return ($base === '/' ? '' : $base) . '/' . $clean;
        }

        $basename = basename($raw);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return '';
        }

        $candidates = array();
        if ($companyId > 0) {
            $candidates[] = array(
                'disk' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId . DIRECTORY_SEPARATOR . 'categories' . DIRECTORY_SEPARATOR . $basename,
                'url' => 'storage/tenant_' . $companyId . '/categories/' . $basename,
            );
        }
        $candidates[] = array(
            'disk' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'categories' . DIRECTORY_SEPARATOR . $basename,
            'url' => 'stock/uploads/categories/' . $basename,
        );

        foreach ($candidates as $cand) {
            if (!is_file($cand['disk']) || @filesize($cand['disk']) < 1) {
                continue;
            }
            if (function_exists('app_url')) {
                return app_url('/' . ltrim($cand['url'], '/'));
            }
            $base = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');

            return ($base === '/' ? '' : $base) . '/' . ltrim($cand['url'], '/');
        }

        return '';
    }
}

/**
 * Writable directory for brand logo files.
 */
if (!function_exists('stock_brand_upload_dir')) {
    function stock_brand_upload_dir()
    {
        $ctx = function_exists('stock_image_company_context')
            ? stock_image_company_context()
            : array('company_id' => 0, 'slug' => '');
        $companyId = (int) ($ctx['company_id'] ?? 0);

        if ($companyId > 0) {
            $tenantBase = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;
            if (!is_dir($tenantBase)) {
                @mkdir($tenantBase, 0755, true);
            }
            $dir = $tenantBase . DIRECTORY_SEPARATOR . 'brands';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir)) {
                return realpath($dir) ?: $dir;
            }
        }

        $legacy = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'brands';
        if (!is_dir($legacy)) {
            @mkdir($legacy, 0755, true);
        }

        return realpath($legacy) ?: $legacy;
    }
}

/**
 * Public URL for a brand logo. Never uses /{company}/stock/uploads/ (those 404).
 */
if (!function_exists('stock_brand_image_url')) {
    function stock_brand_image_url($filename)
    {
        $raw = trim(str_replace('\\', '/', (string) $filename));
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        $ctx = function_exists('stock_image_company_context')
            ? stock_image_company_context()
            : array('company_id' => 0, 'slug' => '');
        $companyId = (int) ($ctx['company_id'] ?? 0);

        if (preg_match('#^storage/#i', $raw)) {
            $clean = ltrim($raw, '/');
            if (function_exists('app_url')) {
                return app_url('/' . $clean);
            }
            $base = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');

            return ($base === '/' ? '' : $base) . '/' . $clean;
        }

        $basename = basename($raw);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return '';
        }

        $candidates = array();
        if ($companyId > 0) {
            $candidates[] = array(
                'disk' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId . DIRECTORY_SEPARATOR . 'brands' . DIRECTORY_SEPARATOR . $basename,
                'url' => 'storage/tenant_' . $companyId . '/brands/' . $basename,
            );
        }
        $candidates[] = array(
            'disk' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'brands' . DIRECTORY_SEPARATOR . $basename,
            'url' => 'stock/uploads/brands/' . $basename,
        );

        foreach ($candidates as $cand) {
            if (!is_file($cand['disk']) || @filesize($cand['disk']) < 1) {
                continue;
            }
            if (function_exists('app_url')) {
                return app_url('/' . ltrim($cand['url'], '/'));
            }
            $base = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');

            return ($base === '/' ? '' : $base) . '/' . ltrim($cand['url'], '/');
        }

        return '';
    }
}

/**
 * Public URL for a product image file (always under /stock/uploads/, not /{company}/stock/uploads/).
 */
if (!function_exists('stock_product_image_url')) {
    function stock_product_image_url($productId, $filename, $size = 'medium')
    {
        return stock_product_list_image_url($productId, $filename, $size, '');
    }
}

/**
 * List/grid image URL — always via product_image.php (static /ultimate/stock/uploads/ paths 404 on disk).
 */
if (!function_exists('stock_product_list_image_url')) {
    function stock_product_list_image_url($productId, $filename = '', $size = 'medium', $stockBasePath = '')
    {
        $productId = (int) $productId;
        if ($productId < 1) {
            return '';
        }
        $size = in_array($size, array('thumbnail', 'medium', 'large', 'original'), true) ? $size : 'medium';

        $raw = trim(str_replace('\\', '/', (string) $filename));
        // No DB filename → no list image (do not invent one from leftover disk files).
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        if (preg_match('#^storage/#i', $raw)) {
            $ctx = stock_image_company_context();
            if (preg_match('#^storage/tenant_(\d+)/#i', $raw, $tenantMatch)) {
                $pathTenantId = (int) $tenantMatch[1];
                if ($pathTenantId > 0 && $pathTenantId !== (int) $ctx['company_id']) {
                    $raw = basename($raw);
                } else {
                    $clean = ltrim($raw, '/');
                    if (function_exists('app_url')) {
                        return app_url('/' . $clean);
                    }
                    $base = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');

                    return ($base === '/' ? '' : $base) . '/' . $clean;
                }
            } else {
                $clean = ltrim($raw, '/');
                if (function_exists('app_url')) {
                    return app_url('/' . $clean);
                }
                $base = '/' . trim((string) (defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');

                return ($base === '/' ? '' : $base) . '/' . $clean;
            }
        }
        if (preg_match('#(?:^|/)products/\d+/(?:thumbnail|medium|large|original)/(.+)$#i', $raw, $m)) {
            $raw = $m[1];
        }
        $basename = basename($raw);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return '';
        }

        $ctx = stock_image_company_context();
        $disk = stock_resolve_product_image_file($productId, $size, $basename, $ctx['slug'], (int) $ctx['company_id']);
        // Do not fall back to "any file in folder" — that resurrects orphan images for products with no photo.
        if ($disk === null || !is_file($disk)) {
            return '';
        }

        $params = array(
            'product_id' => $productId,
            'size' => $size,
            'file' => basename($disk),
        );
        if ($ctx['slug'] !== '') {
            $params['company_slug'] = $ctx['slug'];
        }

        if ($stockBasePath !== '') {
            return rtrim((string) $stockBasePath, '/') . '/product_image.php?' . http_build_query($params);
        }
        if (function_exists('app_url')) {
            return app_url('stock/product_image.php?' . http_build_query($params));
        }

        return '/stock/product_image.php?' . http_build_query($params);
    }
}

/**
 * SQL expression for a product's display image (main_image, image, or first gallery row).
 */
if (!function_exists('stock_product_main_image_sql')) {
    function stock_product_main_image_sql(PDO $pdo, $alias = 'p')
    {
        $alias = preg_replace('/[^a-z_]/i', '', (string) $alias) ?: 'p';
        static $meta = array();
        $metaKey = spl_object_id($pdo);
        if (!isset($meta[$metaKey])) {
            $cols = array();
            $hasGallery = false;
            try {
                $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: array();
            } catch (Throwable $e) {
                $cols = array();
            }
            try {
                $pdo->query('SELECT image_name FROM product_images LIMIT 1');
                $hasGallery = true;
            } catch (Throwable $e) {
                $hasGallery = false;
            }
            $meta[$metaKey] = array('cols' => $cols, 'gallery' => $hasGallery);
        }
        $cols = $meta[$metaKey]['cols'];
        $parts = array();
        if (in_array('main_image', $cols, true)) {
            $parts[] = "NULLIF(TRIM({$alias}.main_image), '')";
        }
        if (in_array('image', $cols, true)) {
            $parts[] = "NULLIF(TRIM({$alias}.image), '')";
        }
        if (!empty($meta[$metaKey]['gallery'])) {
            $parts[] = "(SELECT pi.image_name FROM product_images pi
                WHERE pi.product_id = {$alias}.id
                ORDER BY pi.is_primary DESC, pi.id ASC LIMIT 1)";
        }
        if ($parts === array()) {
            return 'NULL';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }
}

/**
 * Dashboard/list thumbnail markup (uses product_image.php + tenant disk resolution).
 */
if (!function_exists('stock_product_thumb_html')) {
    function stock_product_thumb_html($productId, $filename, $stockBasePath = '', $boxClass = 'prod-icon')
    {
        $productId = (int) $productId;
        $boxClass = preg_replace('/[^a-z0-9_\- ]/i', '', (string) $boxClass) ?: 'prod-icon';
        $url = $productId > 0 && function_exists('stock_product_list_image_url')
            ? stock_product_list_image_url($productId, (string) $filename, 'thumbnail', (string) $stockBasePath)
            : '';
        if ($url === '') {
            return '<div class="' . htmlspecialchars($boxClass, ENT_QUOTES, 'UTF-8') . ' prod-thumb-fallback">'
                . '<i class="fas fa-box"></i></div>';
        }
        $src = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $cls = htmlspecialchars($boxClass, ENT_QUOTES, 'UTF-8');

        return '<div class="' . $cls . ' prod-thumb-wrap">'
            . '<img src="' . $src . '" alt="" loading="lazy" '
            . 'onerror="var p=this.parentElement;p.classList.add(\'prod-thumb-fallback\');p.innerHTML=\'<i class=\\\'fas fa-box\\\'></i>\';">'
            . '</div>';
    }
}

/**
 * Base URL ending with / for catalogue React (no company slug in uploads path).
 */
if (!function_exists('stock_uploads_products_base_url')) {
    function stock_uploads_products_base_url()
    {
        if (function_exists('app_url')) {
            return app_url('stock/uploads/products/');
        }
        $root = isset($GLOBALS['rootPath']) ? (string) $GLOBALS['rootPath'] : '/';
        return rtrim($root, '/') . '/stock/uploads/products/';
    }
}

/**
 * Two-letter initials from a display name (e.g. "Acme Motors" → "AM").
 */
if (!function_exists('stock_profile_initials')) {
    function stock_profile_initials($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($parts) && count($parts) >= 2) {
            $a = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1, 'UTF-8') : substr($parts[0], 0, 1);
            $b = function_exists('mb_substr') ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : substr($parts[count($parts) - 1], 0, 1);

            return strtoupper($a . $b);
        }
        if (function_exists('mb_substr')) {
            return strtoupper(mb_substr($name, 0, 2, 'UTF-8'));
        }

        return strtoupper(substr($name, 0, 2));
    }
}

/**
 * Deterministic colorful background for profile avatars (hex color).
 */
if (!function_exists('stock_profile_avatar_color')) {
    function stock_profile_avatar_color($key)
    {
        $palette = array(
            '#7c3aed',
            '#2563eb',
            '#059669',
            '#d97706',
            '#db2777',
            '#0891b2',
            '#4f46e5',
            '#ea580c',
            '#16a34a',
            '#9333ea',
            '#0d9488',
            '#e11d48',
        );
        $hash = crc32((string) $key);
        $idx = abs((int) $hash) % count($palette);

        return $palette[$idx];
    }
}

/**
 * Inline style for a circular colorful initials avatar.
 */
if (!function_exists('stock_profile_avatar_style')) {
    function stock_profile_avatar_style($key)
    {
        $bg = stock_profile_avatar_color($key);

        return 'background:' . $bg . ';color:#fff;border-color:transparent;';
    }
}


/**
 * BOT USD rate (TZS per 1 USD) for purchase-order currency conversion.
 */
if (!function_exists('stock_po_bot_usd_rate')) {
    function stock_po_bot_usd_rate(): float
    {
        if (!function_exists('bot_get_exchange_rate')) {
            return 0.0;
        }
        $info = bot_get_exchange_rate('USD');

        return $info ? (float) $info['rate'] : 0.0;
    }
}

/**
 * Revenue-style BOT display rate (TZS per 1 unit of selected currency).
 *
 * @return array{rate: float, meta: ?array}
 */
if (!function_exists('stock_po_bot_display_rate')) {
    function stock_po_bot_display_rate(string $currency): array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'TZS') {
            return ['rate' => 1.0, 'meta' => null];
        }
        if (!function_exists('bot_get_exchange_rate')) {
            return ['rate' => $currency === 'USD' ? 1.0 : 0.0, 'meta' => null];
        }
        $info = bot_get_exchange_rate($currency);

        return [
            'rate' => $info ? (float) $info['rate'] : ($currency === 'USD' ? 1.0 : 0.0),
            'meta' => $info,
        ];
    }
}

/**
 * Convert revenue-style BOT field value to PO storage multiplier (USD → PO currency).
 */
if (!function_exists('stock_po_storage_exchange_rate')) {
    function stock_po_storage_exchange_rate(string $currency, float $displayBotRate, float $usdBotRate = 0): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'USD') {
            return 1.0;
        }
        if ($usdBotRate <= 0) {
            $usdBotRate = stock_po_bot_usd_rate();
        }
        if ($currency === 'TZS') {
            return $usdBotRate > 0 ? $usdBotRate : ($displayBotRate > 0 ? $displayBotRate : 1.0);
        }
        if ($displayBotRate > 0 && $usdBotRate > 0) {
            return $usdBotRate / $displayBotRate;
        }

        return $displayBotRate > 0 ? $displayBotRate : 1.0;
    }
}

/**
 * Hint text for BOT exchange rate field (matches revenue module wording).
 */
if (!function_exists('stock_po_exchange_rate_hint')) {
    function stock_po_exchange_rate_hint(string $currency, ?array $meta = null): string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'TZS') {
            return 'TZS is the base currency (rate 1.00).';
        }
        if ($meta !== null) {
            $srcLabel = !empty($meta['via_ai']) ? 'BOT (AI)' : 'BOT';
            $asOf = !empty($meta['as_of']) ? ' as of ' . $meta['as_of'] : '';
            return sprintf(
                '%s mean rate: %s TZS per 1 %s (%s%s). You may adjust before saving.',
                $srcLabel,
                number_format((float) ($meta['rate'] ?? 0), 4, '.', ''),
                $currency,
                $srcLabel,
                $asOf
            );
        }

        return 'Bank of Tanzania (BOT) mean rate per 1 unit vs TZS. Updates when you change currency.';
    }
}

/**
 * Reverse BOT display rate from stored PO multiplier when editing classification.
 */
if (!function_exists('stock_po_display_bot_from_storage_rate')) {
    function stock_po_display_bot_from_storage_rate(string $currency, float $storageRate, float $usdBotRate = 0): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'TZS') {
            return 1.0;
        }
        if ($usdBotRate <= 0) {
            $usdBotRate = stock_po_bot_usd_rate();
        }
        if ($currency === 'USD') {
            return $usdBotRate > 0 ? $usdBotRate : 1.0;
        }
        if ($storageRate > 0 && $usdBotRate > 0) {
            return $usdBotRate / $storageRate;
        }

        $pack = stock_po_bot_display_rate($currency);

        return (float) $pack['rate'];
    }
}

/**
 * Convert stored PO line/header amount to display currency.
 * TZS orders with exchange_rate ~1 store amounts in TZS directly on unit_cost.
 */
if (!function_exists('stock_po_amount_to_display')) {
    function stock_po_amount_to_display(float $storedAmount, string $currency, float $exchangeRate): float
    {
        $currency = strtoupper(trim($currency));
        $exchangeRate = (float) $exchangeRate;
        if ($exchangeRate <= 0) {
            $exchangeRate = 1.0;
        }
        if ($currency === 'TZS' && $exchangeRate <= 1.01) {
            return $storedAmount;
        }

        return convertCurrency($storedAmount, $exchangeRate);
    }
}

/**
 * Whether PO line amounts are stored in PO currency (not USD base).
 */
if (!function_exists('stock_po_uses_native_currency_storage')) {
    function stock_po_uses_native_currency_storage(string $currency, float $exchangeRate): bool
    {
        $currency = strtoupper(trim($currency));
        $exchangeRate = (float) $exchangeRate;

        return $currency === 'TZS' && $exchangeRate > 0 && $exchangeRate <= 1.01;
    }
}


/**
 * Ensure the warehouses schema is present and backfilled.
 */
if (!function_exists('ensureWarehousesSchema')) {
    function ensureWarehousesSchema(PDO $pdo)
    {
        try {
            // 1. Create warehouses table
            $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (
                id INT(11) NOT NULL AUTO_INCREMENT,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(150) NOT NULL,
                address TEXT DEFAULT NULL,
                contact_person VARCHAR(100) DEFAULT NULL,
                contact_phone VARCHAR(20) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_warehouse_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 2. Ensure default warehouse exists
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
            if ($cnt === 0) {
                $pdo->exec("INSERT INTO warehouses (id, code, name, address, contact_person, contact_phone, is_active) 
                            VALUES (1, 'WH-A', 'Warehouse A', 'Main Store Location', 'Main Storekeeper', '', 1)");
            }

            // Helper to check column existence
            $checkCol = function($table, $col) use ($pdo) {
                try {
                    $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                    return (bool)$q->fetch();
                } catch (Throwable $e) {
                    return false;
                }
            };

            // Helper to check index existence
            $checkIdx = function($table, $idxName) use ($pdo) {
                try {
                    $q = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$idxName'");
                    return (bool)$q->fetch();
                } catch (Throwable $e) {
                    return false;
                }
            };

            // Check table existence helper
            $tblExists = function($table) use ($pdo) {
                try {
                    return (bool)$pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn();
                } catch (Throwable $e) {
                    return false;
                }
            };

            // 3. Update stock table
            if ($tblExists('stock')) {
                if (!$checkCol('stock', 'warehouse_id')) {
                    $pdo->exec("ALTER TABLE stock ADD COLUMN warehouse_id INT(11) NOT NULL DEFAULT 1 AFTER product_id");
                    $pdo->exec("UPDATE stock SET warehouse_id = 1 WHERE warehouse_id IS NULL OR warehouse_id = 0");
                }
                if (!$checkIdx('stock', 'uq_product_warehouse')) {
                    try {
                        $pdo->exec("ALTER TABLE stock DROP INDEX product_id");
                    } catch (Throwable $e) {}
                    try {
                        $pdo->exec("ALTER TABLE stock ADD UNIQUE KEY uq_product_warehouse (product_id, warehouse_id)");
                    } catch (Throwable $e) {}
                }
            }

            // 4. Update product_batches table
            if ($tblExists('product_batches')) {
                if (!$checkCol('product_batches', 'warehouse_id')) {
                    $pdo->exec("ALTER TABLE product_batches ADD COLUMN warehouse_id INT(11) NOT NULL DEFAULT 1 AFTER product_id");
                    $pdo->exec("UPDATE product_batches SET warehouse_id = 1 WHERE warehouse_id IS NULL OR warehouse_id = 0");
                }
            }

            // 5. Update stock_movements table
            if ($tblExists('stock_movements')) {
                if (!$checkCol('stock_movements', 'warehouse_id')) {
                    $pdo->exec("ALTER TABLE stock_movements ADD COLUMN warehouse_id INT(11) NOT NULL DEFAULT 1 AFTER product_id");
                    $pdo->exec("UPDATE stock_movements SET warehouse_id = 1 WHERE warehouse_id IS NULL OR warehouse_id = 0");
                }
            }

            // 6. Create stock_transfers table
            $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfers (
                id INT(11) NOT NULL AUTO_INCREMENT,
                transfer_no VARCHAR(50) NOT NULL,
                from_warehouse_id INT(11) NOT NULL,
                to_warehouse_id INT(11) NOT NULL,
                status ENUM('requested', 'dispatched', 'received', 'cancelled') NOT NULL DEFAULT 'requested',
                dispatched_by INT(11) DEFAULT NULL,
                dispatched_at TIMESTAMP NULL DEFAULT NULL,
                received_by INT(11) DEFAULT NULL,
                received_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_by INT(11) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_transfer_no (transfer_no),
                KEY idx_transfer_from (from_warehouse_id),
                KEY idx_transfer_to (to_warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 7. Create stock_transfer_items table
            $pdo->exec("CREATE TABLE IF NOT EXISTS stock_transfer_items (
                id INT(11) NOT NULL AUTO_INCREMENT,
                transfer_id INT(11) NOT NULL,
                product_id INT(11) NOT NULL,
                quantity_requested INT(11) NOT NULL,
                quantity_dispatched INT(11) DEFAULT 0,
                quantity_received INT(11) DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_transfer_item_trans (transfer_id),
                KEY idx_transfer_item_prod (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 8. Create warehouse_rooms table
            $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_rooms (
                id INT(11) NOT NULL AUTO_INCREMENT,
                warehouse_id INT(11) NOT NULL,
                name VARCHAR(150) NOT NULL,
                width INT(11) DEFAULT 12,
                height INT(11) DEFAULT 12,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_room_warehouse (warehouse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 9. Create warehouse_shelves table
            $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_shelves (
                id INT(11) NOT NULL AUTO_INCREMENT,
                room_id INT(11) NOT NULL,
                name VARCHAR(150) NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT 'standard',
                x_pos INT(11) NOT NULL DEFAULT 0,
                y_pos INT(11) NOT NULL DEFAULT 0,
                width INT(11) NOT NULL DEFAULT 2,
                height INT(11) NOT NULL DEFAULT 1,
                `rows` INT(11) NOT NULL DEFAULT 4,
                `cols` INT(11) NOT NULL DEFAULT 5,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_shelf_room (room_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 10. Create product_shelf_assignments table
            $pdo->exec("CREATE TABLE IF NOT EXISTS product_shelf_assignments (
                id INT(11) NOT NULL AUTO_INCREMENT,
                warehouse_id INT(11) NOT NULL,
                product_id INT(11) NOT NULL,
                room_id INT(11) NOT NULL,
                shelf_id INT(11) NOT NULL,
                shelf_row VARCHAR(10) NOT NULL,
                shelf_col INT(11) NOT NULL,
                location_code VARCHAR(100) NOT NULL,
                quantity INT(11) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_assign_warehouse (warehouse_id),
                KEY idx_assign_product (product_id),
                KEY idx_assign_shelf (shelf_id),
                UNIQUE KEY uq_shelf_slot (shelf_id, shelf_row, shelf_col, product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // 11. Create default rooms for existing warehouses if they don't have any
            try {
                $whList = $pdo->query("SELECT id FROM warehouses")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($whList as $wh) {
                    $roomCountStmt = $pdo->prepare("SELECT COUNT(*) FROM warehouse_rooms WHERE warehouse_id = ?");
                    $roomCountStmt->execute([$wh['id']]);
                    $roomCount = (int)$roomCountStmt->fetchColumn();
                    if ($roomCount === 0) {
                        $stmtRoom = $pdo->prepare("INSERT INTO warehouse_rooms (warehouse_id, name, width, height) VALUES (?, 'Main Storage Area', 12, 12)");
                        $stmtRoom->execute([$wh['id']]);
                    }
                }
            } catch (Throwable $t) {
                // Ignore silent database/column existence errors during startup
            }

        } catch (Throwable $e) {
            error_log('ensureWarehousesSchema error: ' . $e->getMessage());
        }
    }
}

/**
 * Increment on-hand quantity in the main `stock` table (warehouse / company / legacy aware).
 */
if (!function_exists('stockIncrementProductStock')) {
    function stockIncrementProductStock(PDO $pdo, int $productId, float $qty, int $warehouseId = 1): void
    {
        if ($productId <= 0 || $qty <= 0) {
            return;
        }

        // DDL (CREATE/ALTER) implicitly commits in MySQL — never run it mid-transaction.
        if (function_exists('ensureWarehousesSchema') && !$pdo->inTransaction()) {
            ensureWarehousesSchema($pdo);
        }

        $stockCols = [];
        try {
            $stockCols = $pdo->query('SHOW COLUMNS FROM stock')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return;
        }
        if ($stockCols === []) {
            return;
        }

        $hasWarehouseId = in_array('warehouse_id', $stockCols, true);
        $hasCompanyId = in_array('company_id', $stockCols, true);
        $hasLastUpdated = in_array('last_updated', $stockCols, true);
        $hasLocation = in_array('location', $stockCols, true);

        $companyId = 0;
        if ($hasCompanyId) {
            if (function_exists('stockPurchaseActiveCompanyId')) {
                $companyId = (int) stockPurchaseActiveCompanyId();
            } elseif (function_exists('currentCompanyId')) {
                $companyId = (int) (currentCompanyId() ?? 0);
            }
        }

        if ($hasWarehouseId) {
            $whCode = 'WH-A';
            try {
                $whStmt = $pdo->prepare('SELECT code FROM warehouses WHERE id = ? LIMIT 1');
                $whStmt->execute([$warehouseId]);
                $code = $whStmt->fetchColumn();
                if ($code !== false && trim((string) $code) !== '') {
                    $whCode = trim((string) $code);
                }
            } catch (Throwable $e) {
                // warehouses table may be missing on older tenant DBs
            }

            $existsSql = 'SELECT COUNT(*) FROM stock WHERE product_id = ? AND warehouse_id = ?';
            $existsParams = [$productId, $warehouseId];
            if ($hasCompanyId && $companyId > 0) {
                $existsSql .= ' AND company_id = ?';
                $existsParams[] = $companyId;
            }
            $stmtCheck = $pdo->prepare($existsSql);
            $stmtCheck->execute($existsParams);
            if ((int) $stmtCheck->fetchColumn() === 0) {
                $insCols = ['product_id', 'warehouse_id', 'quantity'];
                $insVals = [$productId, $warehouseId, 0];
                $insPh = ['?', '?', '?'];
                if ($hasLocation) {
                    $insCols[] = 'location';
                    $insVals[] = $whCode;
                    $insPh[] = '?';
                }
                if ($hasCompanyId && $companyId > 0) {
                    $insCols[] = 'company_id';
                    $insVals[] = $companyId;
                    $insPh[] = '?';
                }
                if ($hasLastUpdated) {
                    $insCols[] = 'last_updated';
                    $insPh[] = 'NOW()';
                }
                $pdo->prepare(
                    'INSERT INTO stock (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $insPh) . ')'
                )->execute($insVals);
            }

            $updSql = 'UPDATE stock SET quantity = COALESCE(quantity, 0) + ?';
            $updParams = [$qty];
            if ($hasLastUpdated) {
                $updSql .= ', last_updated = NOW()';
            }
            $updSql .= ' WHERE product_id = ? AND warehouse_id = ?';
            $updParams[] = $productId;
            $updParams[] = $warehouseId;
            if ($hasCompanyId && $companyId > 0) {
                $updSql .= ' AND company_id = ?';
                $updParams[] = $companyId;
            }
            $pdo->prepare($updSql)->execute($updParams);

            return;
        }

        $existsSql = 'SELECT id FROM stock WHERE product_id = ?';
        $existsParams = [$productId];
        if ($hasCompanyId && $companyId > 0) {
            $existsSql .= ' AND company_id = ?';
            $existsParams[] = $companyId;
        }
        $existsSql .= ' LIMIT 1';
        $stmtStock = $pdo->prepare($existsSql);
        $stmtStock->execute($existsParams);
        $stockId = (int) ($stmtStock->fetchColumn() ?: 0);

        if ($stockId > 0) {
            $updSql = 'UPDATE stock SET quantity = COALESCE(quantity, 0) + ?';
            $updParams = [$qty];
            if ($hasLastUpdated) {
                $updSql .= ', last_updated = NOW()';
            }
            $updSql .= ' WHERE id = ?';
            $updParams[] = $stockId;
            $pdo->prepare($updSql)->execute($updParams);

            return;
        }

        $insCols = ['product_id', 'quantity'];
        $insVals = [$productId, $qty];
        $insPh = ['?', '?'];
        if ($hasLocation) {
            $insCols[] = 'location';
            $insVals[] = 'Warehouse A';
            $insPh[] = '?';
        }
        if ($hasCompanyId && $companyId > 0) {
            $insCols[] = 'company_id';
            $insVals[] = $companyId;
            $insPh[] = '?';
        }
        if ($hasLastUpdated) {
            $insCols[] = 'last_updated';
            $insPh[] = 'NOW()';
        }
        $pdo->prepare(
            'INSERT INTO stock (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $insPh) . ')'
        )->execute($insVals);
    }
}

/**
 * Next PUR-YYYYMMDD-### number for today (avoids duplicate po_number on concurrent creates).
 */
function stock_generate_purchase_order_number(PDO $pdo): string
{
    $datePrefix = date('Ymd');
    $likePattern = 'PUR-' . $datePrefix . '-%';

    $stmt = $pdo->prepare('
        SELECT po_number
        FROM stocks_purchase_orders
        WHERE po_number LIKE ?
        ORDER BY CAST(SUBSTRING_INDEX(po_number, "-", -1) AS UNSIGNED) DESC
        LIMIT 1
    ');
    $stmt->execute([$likePattern]);
    $last = $stmt->fetchColumn();

    $seq = 1;
    if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
        $seq = max(1, (int) $m[1] + 1);
    }

    return sprintf('PUR-%s-%03d', $datePrefix, $seq);
}

// End of functions.php
