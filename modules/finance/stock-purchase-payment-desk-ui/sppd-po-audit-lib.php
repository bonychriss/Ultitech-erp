<?php
/**
 * Purchase order visibility / edit / attachment audit helpers for the payment desk.
 */
declare(strict_types=1);

require_once __DIR__ . '/sppd-lib.php';

const SPPD_PO_AUDIT_VERSION = '1.0.1';

/**
 * @return array<int, array{key:string,label:string,pdo:PDO,db_name:?string,host:?string,is_desk_pdo:bool,tables:array<string,bool>}>
 */
function sppd_audit_collect_connections(): array
{
    $connections = [];
    $deskPdo = sppdBootstrap();
    $deskId = spl_object_id($deskPdo);

    $add = static function (PDO $pdo, string $key, string $label, bool $isDesk) use (&$connections, $deskId): void {
        $id = spl_object_id($pdo);
        foreach ($connections as $existing) {
            if (spl_object_id($existing['pdo']) === $id) {
                return;
            }
        }

        $meta = sppd_audit_pdo_meta($pdo);
        $connections[] = [
            'key' => $key,
            'label' => $label,
            'pdo' => $pdo,
            'db_name' => $meta['db_name'],
            'host' => $meta['host'],
            'is_desk_pdo' => $isDesk || $id === $deskId,
            'tables' => sppd_audit_table_flags($pdo),
        ];
    };

    $add($deskPdo, 'desk', 'Payment desk PDO (sppdBootstrap)', true);

    if (function_exists('balances_collect_pdo_candidates')) {
        $i = 0;
        foreach (balances_collect_pdo_candidates() as $pdo) {
            if ($pdo instanceof PDO) {
                $add($pdo, 'balances_' . $i, 'Balances candidate #' . $i, false);
                $i++;
            }
        }
    }

    global $pdo, $control_pdo;
    if ($pdo instanceof PDO) {
        $add($pdo, 'global_pdo', 'Global $pdo', false);
    }
    if ($control_pdo instanceof PDO) {
        $add($control_pdo, 'control_pdo', 'Control $control_pdo', false);
    }

    $stockDbPath = dirname(__DIR__, 3) . '/stock/config/database.php';
    if (is_file($stockDbPath)) {
        try {
            $savedPdo = $GLOBALS['pdo'] ?? null;
            require_once $stockDbPath;
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                $add($GLOBALS['pdo'], 'stock_config', 'Stock config PDO', false);
            }
            if ($savedPdo instanceof PDO) {
                $GLOBALS['pdo'] = $savedPdo;
            }
        } catch (Throwable $e) {
            // ignore stock DB bootstrap errors in audit
        }
    }

    return $connections;
}

/**
 * @return array{db_name:?string,host:?string}
 */
function sppd_audit_pdo_meta(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT DATABASE() AS db_name')->fetch(PDO::FETCH_ASSOC) ?: [];
        $host = $pdo->query('SELECT @@hostname AS host')->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'db_name' => isset($row['db_name']) ? (string) $row['db_name'] : null,
            'host' => isset($host['host']) ? (string) $host['host'] : null,
        ];
    } catch (Throwable $e) {
        return ['db_name' => null, 'host' => null];
    }
}

/**
 * @return array<string, bool>
 */
function sppd_audit_table_flags(PDO $pdo): array
{
    $tables = [
        'stocks_purchase_orders',
        'stocks_po_items',
        'stocks_purchase_attachments',
        'stocks_suppliers',
        'purchases',
        'purchase_items',
        'supplier_payments',
        'payment_vouchers',
        'financial_accounts',
    ];
    $flags = [];
    foreach ($tables as $table) {
        $flags[$table] = sppdTableExists($pdo, $table);
    }

    return $flags;
}

/**
 * @return array{desk_ids:array<int,bool>,desk_rows:array<int,array<string,mixed>>}
 */
function sppd_audit_desk_snapshot(PDO $pdo): array
{
    $rows = sppdFetchPurchaseOrders($pdo, sppdDefaultFilters(), true);
    $mapped = array_map('sppdMapPurchaseOrder', $rows);
    $deskIds = [];
    $deskRows = [];
    foreach ($mapped as $order) {
        $id = (int) ($order['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $deskIds[$id] = true;
        $deskRows[$id] = $order;
    }

    return ['desk_ids' => $deskIds, 'desk_rows' => $deskRows];
}

/**
 * @return array<int, string>
 */
function sppd_audit_edit_block_reasons(array $row, string $source): array
{
    $reasons = [];
    $id = (int) ($row['desk_id'] ?? $row['id'] ?? 0);
    $realId = (int) ($row['real_id'] ?? $id);
    $status = trim((string) ($row['status'] ?? ''));
    $workflow = trim((string) ($row['procurement_workflow'] ?? ''));

    if ($source === 'legacy') {
        $reasons[] = 'legacy_po_uses_real_id_edit_link';
    }

    $total = (float) ($row['total_amount'] ?? 0);
    $paid = (float) ($row['amount_paid'] ?? 0);
    $balanceDue = (float) ($row['balance_due'] ?? max(0.0, round($total - $paid, 2)));
    $canEdit = sppdPurchaseOrderDeskCanEdit(
        (string) ($row['payment_status'] ?? 'unpaid'),
        $balanceDue,
    );
    $editUrl = $canEdit ? sppdEditPoUrl($source === 'legacy' ? ($realId + 1000000) : $realId) : '';
    if ($editUrl === '' && $canEdit) {
        $reasons[] = 'edit_url_empty';
    }
    if (!$canEdit) {
        $reasons[] = 'fully_paid_no_edit_in_desk_ui';
    }

    if (function_exists('purchaseOrderAllEditAccessStatuses')) {
        $allowed = purchaseOrderAllEditAccessStatuses($workflow !== '' ? $workflow : null);
        if ($status !== '' && !in_array($status, $allowed, true)) {
            $reasons[] = 'status_not_editable:' . $status;
        }
    } elseif ($status !== '' && !in_array($status, ['Pending', 'Supplier Responded', 'Approved', 'Draft', 'Pending Approval'], true)) {
        $reasons[] = 'status_may_block_edit:' . $status;
    }

    return array_values(array_unique($reasons));
}

/**
 * @return array<int, string>
 */
function sppd_audit_desk_block_reasons(array $row, string $source, bool $onDesk): array
{
    if ($onDesk) {
        return [];
    }

    $reasons = [];
    $status = trim((string) ($row['status'] ?? ''));
    $paymentStatus = strtolower(trim((string) ($row['payment_status'] ?? '')));
    $total = (float) ($row['total_amount'] ?? 0);
    $paid = (float) ($row['amount_paid'] ?? 0);
    $balance = max(0.0, round($total - $paid, 2));

    if ($source === 'legacy' && !in_array($status, ['Approved', 'Received'], true)) {
        $reasons[] = 'legacy_status_excluded:' . ($status !== '' ? $status : 'unknown');
    }

    if ($paymentStatus === 'paid' || $balance <= 0.009) {
        $reasons[] = 'fully_paid_or_zero_balance';
    }

    if ($total <= 0.009) {
        $reasons[] = 'zero_po_total';
    }

    if ($reasons === []) {
        $reasons[] = 'not_in_desk_query_unknown';
    }

    return $reasons;
}

/**
 * @return array<string, mixed>
 */
function sppd_audit_attachment_bundle(PDO $pdo, int $realId, string $source, ?string $invoicePath = null): array
{
    $invoicePath = trim((string) $invoicePath);
    $items = [];
    $issues = [];

    if ($invoicePath !== '') {
        $fileCheck = sppd_audit_resolve_file($invoicePath);
        $items[] = [
            'kind' => 'invoice',
            'name' => 'Supplier invoice',
            'path' => $invoicePath,
            'url' => sppdStockPurchasesUrl('download_invoice.php?id=' . $realId),
            'file_exists' => $fileCheck['exists'],
            'resolved_path' => $fileCheck['resolved'],
        ];
        if (!$fileCheck['exists']) {
            $issues[] = 'invoice_file_missing_on_disk';
        }
    }

    if ($source === 'modern' && sppdTableExists($pdo, 'stocks_purchase_attachments')) {
        $fkColumn = 'purchase_id';
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_attachments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!in_array('purchase_id', $cols, true) && in_array('po_id', $cols, true)) {
                $fkColumn = 'po_id';
            }
        } catch (Throwable $e) {
            $fkColumn = 'purchase_id';
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id, file_name, file_path, file_type, file_size, created_at
                 FROM stocks_purchase_attachments
                 WHERE {$fkColumn} = ?
                 ORDER BY id DESC",
            );
            $stmt->execute([$realId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $att) {
                $path = trim((string) ($att['file_path'] ?? ''));
                $fileCheck = sppd_audit_resolve_file($path);
                $items[] = [
                    'kind' => 'attachment',
                    'id' => (int) ($att['id'] ?? 0),
                    'name' => (string) ($att['file_name'] ?? 'Document'),
                    'path' => $path,
                    'url' => $path !== '' ? sppdStockAssetUrl($path) : '',
                    'file_type' => (string) ($att['file_type'] ?? ''),
                    'file_size' => (int) ($att['file_size'] ?? 0),
                    'created_at' => (string) ($att['created_at'] ?? ''),
                    'file_exists' => $fileCheck['exists'],
                    'resolved_path' => $fileCheck['resolved'],
                ];
                if ($path === '') {
                    $issues[] = 'attachment_empty_path:id=' . (int) ($att['id'] ?? 0);
                } elseif (!$fileCheck['exists']) {
                    $issues[] = 'attachment_file_missing:id=' . (int) ($att['id'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $issues[] = 'attachments_query_failed:' . $e->getMessage();
        }
    }

    return [
        'count' => count($items),
        'items' => $items,
        'issues' => array_values(array_unique($issues)),
        'has_any' => $items !== [],
        'has_missing_files' => in_array('invoice_file_missing_on_disk', $issues, true)
            || count(array_filter($issues, static fn ($i) => str_starts_with($i, 'attachment_file_missing'))) > 0,
    ];
}

/**
 * @return array{exists:bool,resolved:?string}
 */
function sppd_audit_resolve_file(string $relativePath): array
{
    if (function_exists('sppdResolveStockRelativePath')) {
        return sppdResolveStockRelativePath($relativePath);
    }

    $relative = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if ($relative === '') {
        return ['exists' => false, 'resolved' => null];
    }

    $root = dirname(__DIR__, 3);
    $candidates = [
        $root . '/stock/' . $relative,
        $root . '/' . $relative,
        $root . '/assets/' . preg_replace('#^uploads/#i', 'uploads/', $relative),
    ];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if (is_string($real) && is_file($real)) {
            return ['exists' => true, 'resolved' => $real];
        }
    }

    return ['exists' => false, 'resolved' => $candidates[0] ?? null];
}

/**
 * @param array<string, bool> $deskIds
 * @return array<int, array<string, mixed>>
 */
function sppd_audit_scan_modern_pos(PDO $pdo, array $deskIds, array $deskRows, array $filters): array
{
    if (!sppdTableExists($pdo, 'stocks_purchase_orders')) {
        return [];
    }

    $amountExpr = sppdPurchaseOrderAmountExpr('po');
    $paymentStatusSql = sppdPurchaseOrderPaymentStatusSelectSql($pdo, 'po');
    $amountPaidSql = sppdAmountPaidSelectSql($pdo, 'po');
    $itemCountSql = '(SELECT COUNT(*) FROM stocks_po_items pi WHERE pi.po_id = po.id)';

    $params = [];
    $where = ['1=1'];
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(po.po_number LIKE ? OR ss.name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($filters['po_id'])) {
        $where[] = 'po.id = ?';
        $params[] = (int) $filters['po_id'];
    }

    $sql = "
        SELECT po.*, {$paymentStatusSql} AS payment_status,
               {$amountExpr} AS total_amount,
               {$amountPaidSql},
               {$itemCountSql} AS line_count,
               ss.name AS payee_name
        FROM stocks_purchase_orders po
        LEFT JOIN stocks_suppliers ss ON po.supplier_id = ss.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY po.id DESC
        LIMIT 1000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($rows as $row) {
        $realId = (int) ($row['id'] ?? 0);
        $deskId = $realId;
        $onDesk = isset($deskIds[$deskId]);
        $mapped = $onDesk ? ($deskRows[$deskId] ?? sppdMapPurchaseOrder($row)) : sppdMapPurchaseOrder($row);
        $attachments = sppd_audit_attachment_bundle(
            $pdo,
            $realId,
            'modern',
            (string) ($row['invoice_attachment'] ?? ''),
        );

        $paymentLinks = sppd_audit_payment_links($pdo, $realId, false);

        $balanceDue = (float) ($mapped['balanceDue'] ?? 0);
        $paymentStatus = (string) ($mapped['paymentStatus'] ?? $row['payment_status'] ?? 'unpaid');
        $canEditOnDesk = sppdPurchaseOrderDeskCanEdit($paymentStatus, $balanceDue);

        $auditRow = [
            'source' => 'modern',
            'real_id' => $realId,
            'desk_id' => $deskId,
            'po_number' => (string) ($row['po_number'] ?? ''),
            'payee_name' => (string) ($row['payee_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'procurement_workflow' => (string) ($row['procurement_workflow'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'amount_paid' => (float) ($row['amount_paid'] ?? 0),
            'balance_due' => $balanceDue,
            'line_count' => (int) ($row['line_count'] ?? 0),
            'company_id' => isset($row['company_id']) ? (int) $row['company_id'] : null,
            'supplier_id' => isset($row['supplier_id']) ? (int) $row['supplier_id'] : null,
            'on_desk' => $onDesk,
            'desk_block_reasons' => sppd_audit_desk_block_reasons($row, 'modern', $onDesk),
            'edit_url' => $canEditOnDesk ? sppdEditPoUrl($realId) : '',
            'edit_blocked_reasons' => sppd_audit_edit_block_reasons($row, 'modern'),
            'view_url' => sppdViewPoUrl($realId),
            'attachments' => $attachments,
            'payment_links' => $paymentLinks,
            'issues' => [],
        ];

        if (!$onDesk) {
            $auditRow['issues'][] = 'missing_from_payment_desk';
            foreach ($auditRow['desk_block_reasons'] as $reason) {
                $auditRow['issues'][] = 'desk_excluded:' . $reason;
            }
        }
        if ($canEditOnDesk && $auditRow['edit_url'] === '') {
            $auditRow['issues'][] = 'no_edit_link_in_ui';
        }
        if ($auditRow['edit_blocked_reasons'] !== []) {
            $auditRow['issues'][] = 'edit_likely_blocked_in_stock_module';
        }
        if ((int) ($row['line_count'] ?? 0) === 0) {
            $auditRow['issues'][] = 'no_line_items';
        }
        if (trim((string) ($row['payee_name'] ?? '')) === '') {
            $auditRow['issues'][] = 'missing_supplier_name';
        }
        if ($attachments['has_missing_files']) {
            $auditRow['issues'][] = 'attachment_files_missing_on_disk';
        }
        if ($paymentLinks['mismatched_supplier_payment_ids'] > 0) {
            $auditRow['issues'][] = 'supplier_payment_id_mismatch';
        }

        $auditRow['issues'] = array_values(array_unique($auditRow['issues']));
        $results[] = $auditRow;
    }

    return $results;
}

/**
 * @param array<string, bool> $deskIds
 * @return array<int, array<string, mixed>>
 */
function sppd_audit_scan_legacy_pos(PDO $pdo, array $deskIds, array $deskRows, array $filters): array
{
    if (!sppdTableExists($pdo, 'purchases')) {
        return [];
    }

    $legacyAmountExpr = "COALESCE(NULLIF(p.total_amount, 0), (
        SELECT COALESCE(SUM(pi.quantity * pi.unit_price), 0)
        FROM purchase_items pi
        WHERE pi.purchase_id = p.id
    ), 0)";

    $params = [];
    $where = ['1=1'];
    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $where[] = '(p.purchase_no LIKE ? OR ss.name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($filters['po_id'])) {
        $where[] = '(p.id = ? OR p.id + 1000000 = ?)';
        $params[] = (int) $filters['po_id'];
        $params[] = (int) $filters['po_id'];
    }

    $supplierPaidSql = sppdTableExists($pdo, 'supplier_payments')
        ? 'COALESCE((
               SELECT SUM(sp.amount)
               FROM supplier_payments sp
               WHERE sp.purchase_order_id = p.id + 1000000
           ), 0)'
        : '0';
    $hasPaidVouchers = sppdTableExists($pdo, 'payment_vouchers');
    $paidVoucherExistsSql = $hasPaidVouchers
        ? 'EXISTS (
               SELECT 1 FROM payment_vouchers pv
               WHERE pv.linked_stock_po_id = p.id AND pv.is_paid = 1
           )'
        : '0';
    $paymentStatusSql = "CASE WHEN (
        {$paidVoucherExistsSql}
        OR {$supplierPaidSql} >= {$legacyAmountExpr} - 0.009
    ) THEN 'paid' ELSE 'unpaid' END";

    $sql = "
        SELECT p.*, p.purchase_no AS po_number,
               {$legacyAmountExpr} AS total_amount,
               ss.name AS payee_name,
               {$supplierPaidSql} AS amount_paid,
               {$paymentStatusSql} AS payment_status
        FROM purchases p
        LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.id DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($rows as $row) {
        $realId = (int) ($row['id'] ?? 0);
        $deskId = $realId + 1000000;
        $onDesk = isset($deskIds[$deskId]);
        $mapped = $onDesk ? ($deskRows[$deskId] ?? null) : null;
        if ($mapped === null) {
            $mapped = sppdMapPurchaseOrder([
                'id' => $deskId,
                'po_number' => $row['po_number'] ?? '',
                'created_at' => $row['created_at'] ?? '',
                'currency' => $row['currency'] ?? 'TZS',
                'status' => $row['status'] ?? '',
                'payment_status' => $row['payment_status'] ?? 'unpaid',
                'total_amount' => $row['total_amount'] ?? 0,
                'amount_paid' => $row['amount_paid'] ?? 0,
                'payee_name' => $row['payee_name'] ?? '',
                'paid_by_name' => '',
                'effective_date' => $row['created_at'] ?? '',
            ]);
        }

        $attachments = sppd_audit_attachment_bundle(
            $pdo,
            $realId,
            'legacy',
            (string) ($row['invoice_attachment'] ?? ''),
        );
        $paymentLinks = sppd_audit_payment_links($pdo, $realId, true);

        $balanceDue = (float) ($mapped['balanceDue'] ?? 0);
        $paymentStatus = (string) ($mapped['paymentStatus'] ?? $row['payment_status'] ?? 'unpaid');
        $canEditOnDesk = sppdPurchaseOrderDeskCanEdit($paymentStatus, $balanceDue);

        $auditRow = [
            'source' => 'legacy',
            'real_id' => $realId,
            'desk_id' => $deskId,
            'po_number' => (string) ($row['po_number'] ?? ''),
            'payee_name' => (string) ($row['payee_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'procurement_workflow' => '',
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'amount_paid' => (float) ($row['amount_paid'] ?? 0),
            'balance_due' => $balanceDue,
            'line_count' => null,
            'company_id' => null,
            'supplier_id' => isset($row['supplier_id']) ? (int) $row['supplier_id'] : null,
            'on_desk' => $onDesk,
            'desk_block_reasons' => sppd_audit_desk_block_reasons($row, 'legacy', $onDesk),
            'edit_url' => $canEditOnDesk ? sppdEditPoUrl($deskId) : '',
            'edit_blocked_reasons' => sppd_audit_edit_block_reasons($row, 'legacy'),
            'view_url' => sppdViewPoUrl($realId),
            'attachments' => $attachments,
            'payment_links' => $paymentLinks,
            'issues' => [],
        ];

        if (!$onDesk) {
            $auditRow['issues'][] = 'missing_from_payment_desk';
            foreach ($auditRow['desk_block_reasons'] as $reason) {
                $auditRow['issues'][] = 'desk_excluded:' . $reason;
            }
        }
        if ($attachments['has_missing_files']) {
            $auditRow['issues'][] = 'attachment_files_missing_on_disk';
        }
        if ($paymentLinks['mismatched_supplier_payment_ids'] > 0) {
            $auditRow['issues'][] = 'supplier_payment_id_mismatch';
        }

        $auditRow['issues'] = array_values(array_unique($auditRow['issues']));
        $auditRow['legacy_no_edit_expected'] = true;
        $auditRow['open_po_url'] = sppdViewPoUrl($realId);
        $results[] = $auditRow;
    }

    return $results;
}

/**
 * @return array<string, int|array<int, array<string, mixed>>>
 */
function sppd_audit_payment_links(PDO $pdo, int $realId, bool $isLegacy): array
{
    $shiftedId = $isLegacy ? ($realId + 1000000) : $realId;
    $rawIdPayments = 0;
    $shiftedPayments = 0;
    $voucherLinks = 0;
    $rawSamples = [];
    $shiftedSamples = [];

    if (sppdTableExists($pdo, 'supplier_payments')) {
        try {
            $stmt = $pdo->prepare('SELECT id, purchase_order_id, amount, payment_number FROM supplier_payments WHERE purchase_order_id = ? ORDER BY id DESC LIMIT 5');
            $stmt->execute([$realId]);
            $rawSamples = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rawIdPayments = count($rawSamples);

            $stmt = $pdo->prepare('SELECT id, purchase_order_id, amount, payment_number FROM supplier_payments WHERE purchase_order_id = ? ORDER BY id DESC LIMIT 5');
            $stmt->execute([$shiftedId]);
            $shiftedSamples = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $shiftedPayments = count($shiftedSamples);
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (sppdTableExists($pdo, 'payment_vouchers')) {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_vouchers WHERE linked_stock_po_id = ?');
            $stmt->execute([$realId]);
            $voucherLinks = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $voucherLinks = 0;
        }
    }

    $mismatch = 0;
    if ($isLegacy && $rawIdPayments > 0) {
        $mismatch = $rawIdPayments;
    }
    if (!$isLegacy && $shiftedPayments > 0) {
        $mismatch = $shiftedPayments;
    }

    return [
        'supplier_payments_raw_id' => $rawIdPayments,
        'supplier_payments_shifted_id' => $shiftedPayments,
        'payment_vouchers_linked_stock_po_id' => $voucherLinks,
        'mismatched_supplier_payment_ids' => $mismatch,
        'raw_id_samples' => $rawSamples,
        'shifted_id_samples' => $shiftedSamples,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function sppd_audit_cross_database_orphans(array $connections, array $deskIds): array
{
    $seen = [];
    $orphans = [];

    foreach ($connections as $conn) {
        $pdo = $conn['pdo'];
        if (!sppdTableExists($pdo, 'stocks_purchase_orders')) {
            continue;
        }

        try {
            $rows = $pdo->query('SELECT id, po_number, status FROM stocks_purchase_orders ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            continue;
        }

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $key = $id . ':' . ($conn['db_name'] ?? $conn['key']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if (!isset($deskIds[$id]) && ($conn['is_desk_pdo'] ?? false)) {
                continue;
            }

            if (!isset($deskIds[$id])) {
                $orphans[] = [
                    'db' => $conn['db_name'] ?? $conn['key'],
                    'is_desk_database' => (bool) ($conn['is_desk_pdo'] ?? false),
                    'id' => $id,
                    'po_number' => (string) ($row['po_number'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'issue' => 'exists_in_database_but_not_on_payment_desk',
                ];
            }
        }
    }

    return $orphans;
}

/**
 * @param array<string, string> $filters
 * @return array<string, mixed>
 */
function sppd_audit_build_report(array $filters = []): array
{
    $workflowPath = dirname(__DIR__, 3) . '/stock/modules/purchases/purchase_workflow.php';
    if (is_file($workflowPath)) {
        require_once $workflowPath;
    }

    $deskPdo = sppdBootstrap();
    $connections = sppd_audit_collect_connections();
    $deskSnapshot = sppd_audit_desk_snapshot($deskPdo);
    $deskIds = $deskSnapshot['desk_ids'];
    $deskRows = $deskSnapshot['desk_rows'];

    $modern = sppd_audit_scan_modern_pos($deskPdo, $deskIds, $deskRows, $filters);
    $legacy = sppd_audit_scan_legacy_pos($deskPdo, $deskIds, $deskRows, $filters);
    $all = array_merge($modern, $legacy);
    $allForSummary = $all;

    if (!empty($filters['issues_only'])) {
        $all = array_values(array_filter($all, static fn (array $row): bool => ($row['issues'] ?? []) !== []));
    }

    $summary = [
        'desk_listed_count' => count($deskIds),
        'modern_scanned' => count($modern),
        'legacy_scanned' => count($legacy),
        'with_issues' => count(array_filter($allForSummary, static fn (array $r): bool => ($r['issues'] ?? []) !== [])),
        'missing_from_desk' => count(array_filter($allForSummary, static fn (array $r): bool => !($r['on_desk'] ?? false))),
        'legacy_no_edit_expected' => count(array_filter(
            $allForSummary,
            static fn (array $r): bool => !sppdPurchaseOrderDeskCanEdit(
                (string) ($r['payment_status'] ?? 'unpaid'),
                (float) ($r['balance_due'] ?? 0),
            ),
        )),
        'modern_no_edit_link' => count(array_filter(
            $allForSummary,
            static fn (array $r): bool => ($r['source'] ?? '') === 'modern' && ($r['edit_url'] ?? '') === '',
        )),
        'no_edit_link' => count(array_filter($allForSummary, static fn (array $r): bool => ($r['edit_url'] ?? '') === '')),
        'attachment_missing_files' => count(array_filter($allForSummary, static fn (array $r): bool => ($r['attachments']['has_missing_files'] ?? false))),
        'payment_link_mismatch' => count(array_filter(
            $allForSummary,
            static fn (array $r): bool => in_array('supplier_payment_id_mismatch', $r['issues'] ?? [], true),
        )),
        'legacy_on_desk' => count(array_filter($allForSummary, static fn (array $r): bool => ($r['source'] ?? '') === 'legacy' && ($r['on_desk'] ?? false))),
        'attachment_check_note' => 'Invoice files are stored under stock/uploads (often ultimate/stock/uploads on this server).',
    ];

    return [
        'version' => SPPD_PO_AUDIT_VERSION,
        'generated_at' => date('c'),
        'filters' => $filters,
        'company_id' => function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : null,
        'connections' => array_map(static function (array $conn): array {
            return [
                'key' => $conn['key'],
                'label' => $conn['label'],
                'db_name' => $conn['db_name'],
                'host' => $conn['host'],
                'is_desk_pdo' => $conn['is_desk_pdo'],
                'tables' => $conn['tables'],
            ];
        }, $connections),
        'summary' => $summary,
        'stock_root_candidates' => function_exists('sppdStockRootPathCandidates') ? sppdStockRootPathCandidates() : [],
        'desk_orders' => array_values($deskRows),
        'purchase_orders' => $all,
        'cross_database_orphans' => sppd_audit_cross_database_orphans($connections, $deskIds),
    ];
}
