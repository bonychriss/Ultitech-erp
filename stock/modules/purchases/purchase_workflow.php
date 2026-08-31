<?php
/**
 * Optional procurement workflow: supplier link (Draft ? Pending Supplier ? Pending Approval ? Approved).
 * Default remains "standard" (Pending ? Supplier Responded ? �).
 */

declare(strict_types=1);

const PURCHASE_PROC_STANDARD = 'standard';
const PURCHASE_PROC_SUPPLIER_LINK = 'supplier_link';

const PURCHASE_STATUS_DRAFT = 'Draft';
const PURCHASE_STATUS_PENDING_SUPPLIER = 'Pending Supplier';
const PURCHASE_STATUS_PENDING_APPROVAL = 'Pending Approval';
/** Legacy / standard path */
const PURCHASE_STATUS_PENDING = 'Pending';
const PURCHASE_STATUS_SUPPLIER_RESPONDED = 'Supplier Responded';
const PURCHASE_STATUS_NEGOTIATION = 'Negotiation Requested';
const PURCHASE_STATUS_APPROVED = 'Approved';
const PURCHASE_STATUS_RECEIVED = 'Received';

function ensurePurchaseWorkflowSchema(PDO $pdo): void {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE stocks_purchase_orders MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT \'Pending\'');
    } catch (Throwable $e) {
    }
    if (!in_array('procurement_workflow', $cols, true)) {
        try {
            $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN procurement_workflow VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER status");
        } catch (Throwable $e) {
        }
    }
    if (!in_array('sent_to_supplier_at', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE stocks_purchase_orders ADD COLUMN sent_to_supplier_at DATETIME NULL DEFAULT NULL');
        } catch (Throwable $e) {
        }
    }
    ensureStocksPurchaseAttachmentsSchema($pdo);
}

function ensureStocksPurchaseAttachmentsSchema(PDO $pdo): void {
    try {
        $exists = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_attachments'")->fetchColumn();
        if ($exists) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS stocks_purchase_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(512) NOT NULL,
            file_type VARCHAR(100) NULL,
            file_size INT UNSIGNED NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spa_purchase_id (purchase_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

function isSupplierLinkWorkflow(?string $w): bool {
    return $w === PURCHASE_PROC_SUPPLIER_LINK;
}

/** Statuses where internal user can still edit lines (price/qty) */
function purchaseOrderEditableStatuses(?string $workflow): array {
    if (isSupplierLinkWorkflow($workflow)) {
        return [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_SUPPLIER, PURCHASE_STATUS_NEGOTIATION];
    }
    return [PURCHASE_STATUS_PENDING, PURCHASE_STATUS_SUPPLIER_RESPONDED];
}

/** Approved/received: supplier, qty, and references editable; unit prices locked. */
function purchaseOrderPricesLockedStatuses(): array {
    return [PURCHASE_STATUS_APPROVED, PURCHASE_STATUS_RECEIVED];
}

function purchaseOrderLimitedEditStatuses(): array {
    return purchaseOrderPricesLockedStatuses();
}

function arePurchaseOrderPricesLocked(?string $status): bool {
    return in_array((string) $status, purchaseOrderPricesLockedStatuses(), true);
}

/** Statuses allowed to open edit.php (full or limited edit). */
function purchaseOrderAllEditAccessStatuses(?string $workflow): array {
    return array_values(array_unique(array_merge(
        purchaseOrderEditableStatuses($workflow),
        purchaseOrderLimitedEditStatuses(),
        [PURCHASE_STATUS_DRAFT, PURCHASE_STATUS_PENDING_APPROVAL]
    )));
}

/** Active company for stock purchase screens (matches index list rules). */
function stockPurchaseActiveCompanyId(): int {
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0 && function_exists('defaultCompanyId')) {
        $companyId = (int) (defaultCompanyId() ?? 0);
    }
    return $companyId;
}

/**
 * Control-plane PDO (same database as admin/company-settings.php).
 */
function stockPurchaseControlPdo(): ?PDO
{
    global $control_pdo, $pdo;

    if ($control_pdo instanceof PDO) {
        return $control_pdo;
    }

    return $pdo instanceof PDO ? $pdo : null;
}

/**
 * Company id for PO documents (session, URL slug e.g. ultimate, active company).
 */
function resolveStockPurchaseCompanyIdForProfile(int $companyId = 0): int
{
    if ($companyId > 0) {
        return $companyId;
    }

    $companyId = stockPurchaseActiveCompanyId();
    if ($companyId > 0) {
        return $companyId;
    }

    if (function_exists('currentCompanyId')) {
        $cid = (int) (currentCompanyId() ?? 0);
        if ($cid > 0) {
            return $cid;
        }
    }

    if (!empty($_SESSION['active_company_id'])) {
        return (int) $_SESSION['active_company_id'];
    }

    return 0;
}

/**
 * Company profile for PO view/PDF — mirrors admin company-settings.php (companies + KV on control DB).
 *
 * @return array<string, mixed>
 */
function resolveStockPurchaseCompanyProfile(PDO $pdo, int $companyId = 0): array
{
    $companyId = resolveStockPurchaseCompanyIdForProfile($companyId);
    $controlPdo = stockPurchaseControlPdo();
    $companiesPdo = $controlPdo instanceof PDO ? $controlPdo : $pdo;

    $profile = [
        'company_name' => '',
        'legal_name' => '',
        'address' => '',
        'company_location' => '',
        'city' => '',
        'country' => '',
        'phone' => '',
        'email' => '',
        'default_payment_terms' => 'Net 30',
        'terms_and_conditions' => '',
        'currency' => 'TZS',
        'exchange_rate' => 1,
        'company_logo' => '',
        'bank_details' => '',
    ];

    if ($companyId > 0 && function_exists('tableExists') && tableExists('companies', $companiesPdo)) {
        try {
            $stmt = $companiesPdo->prepare(
                'SELECT company_name, legal_name, email, phone, address, country, base_currency, timezone
                 FROM companies WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $profile['company_name'] = trim((string) ($row['company_name'] ?? ''));
                $profile['legal_name'] = trim((string) ($row['legal_name'] ?? ''));
                $profile['email'] = trim((string) ($row['email'] ?? ''));
                $profile['phone'] = trim((string) ($row['phone'] ?? ''));
                $profile['address'] = trim((string) ($row['address'] ?? ''));
                $profile['country'] = trim((string) ($row['country'] ?? ''));
                $baseCurrency = trim((string) ($row['base_currency'] ?? ''));
                if ($baseCurrency !== '') {
                    $profile['currency'] = $baseCurrency;
                }
            }
        } catch (Throwable $e) {
        }
    }

    if ($companyId > 0 && $controlPdo instanceof PDO && function_exists('fetchCompanySettingsMap')) {
        if (function_exists('ensureCompanySettingsKeyValueSchema')) {
            ensureCompanySettingsKeyValueSchema($controlPdo);
        }
        $kv = fetchCompanySettingsMap($controlPdo, $companyId);
        if (!empty($kv['company_phone'])) {
            $profile['phone'] = trim((string) $kv['company_phone']);
        }
        if (!empty($kv['company_email'])) {
            $profile['email'] = trim((string) $kv['company_email']);
        }
        if (!empty($kv['company_address'])) {
            $profile['address'] = trim((string) $kv['company_address']);
        }
        if (!empty($kv['company_location'])) {
            $profile['company_location'] = trim((string) $kv['company_location']);
            $profile['city'] = $profile['company_location'];
        }
        if (!empty($kv['country']) && trim((string) $profile['country']) === '') {
            $profile['country'] = trim((string) $kv['country']);
        }
        if (!empty($kv['company_logo'])) {
            $profile['company_logo'] = trim((string) $kv['company_logo']);
        }
        if (!empty($kv['bank_details'])) {
            $profile['bank_details'] = trim((string) $kv['bank_details']);
        }
        if (!empty($kv['document_footer_message']) && trim((string) $profile['terms_and_conditions']) === '') {
            $profile['terms_and_conditions'] = trim((string) $kv['document_footer_message']);
        }
        foreach (['currency', 'default_payment_terms'] as $key) {
            if (!empty($kv[$key])) {
                $profile[$key] = trim((string) $kv[$key]);
            }
        }
    }

    if (trim((string) $profile['company_name']) === '' && trim((string) $profile['legal_name']) !== '') {
        $profile['company_name'] = $profile['legal_name'];
    }

    if (function_exists('getCompanySettings')) {
        $legacy = getCompanySettings($pdo);
        if (is_array($legacy)) {
            if (trim((string) $profile['terms_and_conditions']) === '' && !empty($legacy['terms_and_conditions'])) {
                $profile['terms_and_conditions'] = trim((string) $legacy['terms_and_conditions']);
            }
            if (trim((string) $profile['default_payment_terms']) === 'Net 30' && !empty($legacy['default_payment_terms'])) {
                $profile['default_payment_terms'] = trim((string) $legacy['default_payment_terms']);
            }
            if (!empty($legacy['currency']) && ($profile['currency'] === '' || $profile['currency'] === 'TZS')) {
                $legacyCurrency = trim((string) $legacy['currency']);
                if ($legacyCurrency !== '') {
                    $profile['currency'] = $legacyCurrency;
                }
            }
            if ((float) ($profile['exchange_rate'] ?? 0) <= 0 && !empty($legacy['exchange_rate'])) {
                $profile['exchange_rate'] = (float) $legacy['exchange_rate'];
            }
        }
    }

    if (trim((string) $profile['company_name']) === '') {
        $profile['company_name'] = 'Company';
    }

    if ((float) ($profile['exchange_rate'] ?? 0) <= 0) {
        $profile['exchange_rate'] = 1;
    }

    return $profile;
}

/**
 * SQL fragment: rows with NULL/0 company_id or matching tenant company.
 *
 * @param array<int,mixed> $params
 */
function stockPurchaseCompanyScopeSql(string $columnQualified, bool $hasCompanyCol, int $companyId, array &$params): string {
    if (!$hasCompanyCol || $companyId <= 0) {
        return '';
    }
    $params[] = $companyId;
    return " AND ({$columnQualified} IS NULL OR {$columnQualified} = 0 OR {$columnQualified} = ?)";
}

/**
 * Load a PO for internal actions using the same visibility rules as the purchases list.
 *
 * @return array<string, mixed>|null
 */
function loadStockPurchaseOrderForAccess(PDO $pdo, int $id, int $companyId = 0, bool $withSupplierName = false): ?array {
    if ($id <= 0) {
        return null;
    }
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    $po = function_exists('fetchStockPurchaseOrderById')
        ? fetchStockPurchaseOrderById($pdo, $id, $withSupplierName)
        : null;
    if (!$po) {
        return null;
    }

    if (($po['_po_table'] ?? 'stocks_purchase_orders') !== 'stocks_purchase_orders') {
        return $po;
    }

    try {
        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return $po;
    }

    if (!in_array('company_id', $poCols, true) || $companyId <= 0) {
        return $po;
    }

    $rowCompany = (int) ($po['company_id'] ?? 0);
    if ($rowCompany > 0 && $rowCompany !== $companyId) {
        return null;
    }

    return $po;
}

/** Supplier portal may enter prices when */
function supplierPortalWritableStatuses(?string $workflow): array {
    if (isSupplierLinkWorkflow($workflow)) {
        return [PURCHASE_STATUS_PENDING_SUPPLIER, PURCHASE_STATUS_NEGOTIATION];
    }
    return [PURCHASE_STATUS_PENDING, PURCHASE_STATUS_NEGOTIATION];
}

/** Shown as "awaiting procurement" on internal screens */
function purchaseAwaitingApprovalStatuses(): array {
    return [PURCHASE_STATUS_SUPPLIER_RESPONDED, PURCHASE_STATUS_PENDING_APPROVAL];
}

/** Statuses where cancel is allowed (internal). */
function purchaseCancelableStatuses(?string $workflow): array {
    if (isSupplierLinkWorkflow($workflow)) {
        return [
            PURCHASE_STATUS_DRAFT,
            PURCHASE_STATUS_PENDING_SUPPLIER,
            PURCHASE_STATUS_PENDING_APPROVAL,
            PURCHASE_STATUS_NEGOTIATION,
        ];
    }

    return [PURCHASE_STATUS_PENDING, PURCHASE_STATUS_SUPPLIER_RESPONDED];
}

/** Block receiving stock until PO is past supplier/approval gates. */
function purchaseStatusesBlockingReceive(): array {
    return [
        PURCHASE_STATUS_DRAFT,
        PURCHASE_STATUS_PENDING_SUPPLIER,
        PURCHASE_STATUS_PENDING_APPROVAL,
    ];
}

function purchaseDisplayStatusLabel(string $status, ?string $workflow): string {
    if ($status === PURCHASE_STATUS_SUPPLIER_RESPONDED && isSupplierLinkWorkflow($workflow)) {
        return 'Pending approval';
    }
    if ($status === PURCHASE_STATUS_PENDING_APPROVAL) {
        return 'Pending approval';
    }
    if ($status === PURCHASE_STATUS_PENDING_SUPPLIER) {
        return 'Pending supplier';
    }
    if ($status === PURCHASE_STATUS_DRAFT) {
        return 'Draft';
    }
    return $status;
}

/**
 * Load stock PO row by public_token for supplier portal (minimal join).
 */
/** Email / portal columns on stocks_purchase_orders (shared with view_po). */
function ensureStocksPurchaseOrdersWorkflowColumns(PDO $pdo): void {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    $alters = [
        'public_token' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN public_token VARCHAR(64) NULL DEFAULT NULL',
        'token_expiry' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN token_expiry DATETIME NULL DEFAULT NULL',
        'negotiation_notes' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN negotiation_notes TEXT NULL',
        'invoice_attachment' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN invoice_attachment VARCHAR(512) NULL DEFAULT NULL',
        'emailed_to' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN emailed_to VARCHAR(255) NULL DEFAULT NULL',
        'emailed_at' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN emailed_at DATETIME NULL DEFAULT NULL',
        'emailed_by' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN emailed_by INT NULL DEFAULT NULL',
        'supplier_responded_at' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN supplier_responded_at DATETIME NULL DEFAULT NULL',
        'terms_conditions' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN terms_conditions TEXT NULL',
        // Totals & tax (used by create/edit/view pages)
        'subtotal' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN subtotal DECIMAL(18,6) NULL DEFAULT NULL',
        'tax_percentage' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN tax_percentage DECIMAL(10,4) NULL DEFAULT NULL',
        'tax_amount' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN tax_amount DECIMAL(18,6) NULL DEFAULT NULL',
        'discount_percentage' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN discount_percentage DECIMAL(10,4) NULL DEFAULT NULL',
        'discount_amount' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN discount_amount DECIMAL(18,6) NULL DEFAULT NULL',
        'total_amount' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN total_amount DECIMAL(18,6) NULL DEFAULT NULL',
        'currency' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT \'USD\'',
        'exchange_rate' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1.000000',
        // Audit
        'updated_at' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN updated_at DATETIME NULL DEFAULT NULL',
    ];
    foreach ($alters as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
            }
        }
    }
}

/**
 * @param array<int, string> $columns
 * @param array<int, string> $candidates
 */
function stockPurchasePickSupplierColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $col) {
        if (in_array($col, $columns, true)) {
            return $col;
        }
    }
    return null;
}

/**
 * Parse free-text supplier contact_details into email / phone / address lines.
 *
 * @return array{email: string, phone: string, address: string, full: string}
 */
function stockPurchaseParseContactDetails(string $raw): array
{
    $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
    if ($raw === '') {
        return ['email' => '', 'phone' => '', 'address' => '', 'full' => ''];
    }

    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), static function (string $line): bool {
        return $line !== '';
    }));

    $email = '';
    $phone = '';
    $addressParts = [];

    foreach ($lines as $line) {
        if ($email === '' && filter_var($line, FILTER_VALIDATE_EMAIL)) {
            $email = $line;
            continue;
        }
        if ($phone === '' && preg_match('/^[\d\s+\-().]{7,}$/', $line)) {
            $phone = $line;
            continue;
        }
        $addressParts[] = $line;
    }

    return [
        'email' => $email,
        'phone' => $phone,
        'address' => implode("\n", $addressParts),
        'full' => $raw,
    ];
}

/**
 * @return array<int, int>
 */
function stockPurchasePoLinkedVoucherIds(array $po): array
{
    $ids = [];
    $single = (int) ($po['payment_voucher_id'] ?? 0);
    if ($single > 0) {
        $ids[$single] = $single;
    }

    $raw = trim((string) ($po['payment_voucher_ids'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $vid) {
                $vid = (int) $vid;
                if ($vid > 0) {
                    $ids[$vid] = $vid;
                }
            }
        } else {
            foreach (preg_split('/\s*,\s*/', $raw) as $token) {
                $vid = (int) $token;
                if ($vid > 0) {
                    $ids[$vid] = $vid;
                }
            }
        }
    }

    return array_values($ids);
}

/**
 * PO-linked voucher ids from PO columns and payment_vouchers.linked_stock_po_id.
 *
 * @return array<int, int>
 */
function stockPurchaseExpandPoLinkedVoucherIds(array $po, int $poId = 0, int $companyId = 0): array
{
    $ids = [];
    foreach (stockPurchasePoLinkedVoucherIds($po) as $vid) {
        $ids[(int) $vid] = (int) $vid;
    }

    if ($poId <= 0) {
        $poId = (int) ($po['id'] ?? 0);
    }
    if ($poId <= 0) {
        return array_values($ids);
    }
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    foreach (stockPurchaseVoucherPdbs() as $vPdo) {
        if (!function_exists('tableExists') || !tableExists('payment_vouchers', $vPdo)) {
            continue;
        }
        try {
            $pvCols = $vPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            continue;
        }
        if (!in_array('linked_stock_po_id', $pvCols, true)) {
            continue;
        }

        $params = [$poId];
        $where = ['linked_stock_po_id = ?'];
        $hasCompany = in_array('company_id', $pvCols, true) && $companyId > 0;
        if ($hasCompany) {
            $where[] = 'company_id = ?';
            $params[] = $companyId;
        }

        try {
            $stmt = $vPdo->prepare(
                'SELECT id FROM payment_vouchers WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC'
            );
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vid = (int) ($row['id'] ?? 0);
                if ($vid > 0) {
                    $ids[$vid] = $vid;
                }
            }
        } catch (Throwable $e) {
        }
    }

    return array_values($ids);
}

/**
 * Statuses where legacy purchases rows may update supplier from edit.php.
 *
 * @return array<int, string>
 */
function purchaseLegacyEditableStatuses(): array
{
    return [
        'Pending',
        'Approved',
        'Supplier Responded',
        'Negotiation',
        'Pending Approval',
        'Pending Supplier',
    ];
}

/**
 * Persist supplier_id on stocks_purchase_orders or legacy purchases.
 *
 * @return array{ok: bool, message: string}
 */
function stockPurchaseUpdatePoSupplierId(PDO $pdo, int $poId, string $poTable, int $supplierId): array
{
    if ($poId <= 0 || $supplierId <= 0) {
        return ['ok' => false, 'message' => 'Invalid purchase order or supplier.'];
    }

    $table = $poTable === 'purchases' ? 'purchases' : 'stocks_purchase_orders';
    if (!function_exists('tableExists') || !tableExists($table, $pdo)) {
        return ['ok' => false, 'message' => 'Purchase order table is not available.'];
    }

    try {
        $safeTable = str_replace('`', '', $table);
        $cols = $pdo->query('SHOW COLUMNS FROM `' . $safeTable . '`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('supplier_id', $cols, true)) {
            return ['ok' => false, 'message' => 'Supplier cannot be updated on this record.'];
        }
        $sets = ['supplier_id = ?'];
        $vals = [$supplierId];
        if (in_array('updated_at', $cols, true)) {
            $sets[] = 'updated_at = NOW()';
        }
        $pdo->prepare('UPDATE `' . $safeTable . '` SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute(array_merge($vals, [$poId]));

        return ['ok' => true, 'message' => 'Supplier updated successfully.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Supplier update failed: ' . $e->getMessage()];
    }
}

/**
 * Resolve (or create) a stocks_suppliers id from a voucher payee name.
 */
function stockPurchaseResolveSupplierIdFromPayeeName(PDO $pdo, string $payeeName): int
{
    $payeeName = trim($payeeName);
    if ($payeeName === '' || !function_exists('tableExists') || !tableExists('stocks_suppliers', $pdo)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM stocks_suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
        $stmt->execute([$payeeName]);
        $existingId = (int) $stmt->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }

        $ins = $pdo->prepare('INSERT INTO stocks_suppliers (name, created_at) VALUES (?, NOW())');
        $ins->execute([$payeeName]);
        $newId = (int) $pdo->lastInsertId();

        return $newId > 0 ? $newId : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Update PO supplier_id to match the first linked payment voucher payee.
 *
 * @return array{ok: bool, message: string, supplier_id: int, changed?: bool, payee_name?: string}
 */
function stockPurchaseSyncPoSupplierFromVouchers(PDO $pdo, int $poId, int $companyId = 0): array
{
    if ($poId <= 0) {
        return ['ok' => false, 'message' => 'Invalid purchase order.', 'supplier_id' => 0];
    }
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    $po = function_exists('fetchStockPurchaseOrderById')
        ? fetchStockPurchaseOrderById($pdo, $poId, false)
        : null;
    if (!$po) {
        return ['ok' => false, 'message' => 'Purchase order not found.', 'supplier_id' => 0];
    }

    $poTable = (string) ($po['_po_table'] ?? 'stocks_purchase_orders');
    $voucherIds = stockPurchaseExpandPoLinkedVoucherIds($po, $poId, $companyId);
    if ($voucherIds === []) {
        return [
            'ok' => false,
            'message' => 'No payment voucher is linked to this purchase order.',
            'supplier_id' => (int) ($po['supplier_id'] ?? 0),
        ];
    }

    $resolvedSupplierId = 0;
    $resolvedPayee = '';
    foreach ($voucherIds as $voucherId) {
        if (!function_exists('validatePaymentVoucherForStockPoLink')) {
            break;
        }
        $check = validatePaymentVoucherForStockPoLink($pdo, (int) $voucherId, $companyId);
        if (!$check['ok'] || empty($check['row'])) {
            continue;
        }
        $payeeName = trim((string) ($check['row']['payee_name'] ?? ''));
        if ($payeeName === '') {
            continue;
        }
        $supplierId = stockPurchaseResolveSupplierIdFromPayeeName($pdo, $payeeName);
        if ($supplierId > 0) {
            $resolvedSupplierId = $supplierId;
            $resolvedPayee = $payeeName;
            break;
        }
    }

    if ($resolvedSupplierId <= 0) {
        return [
            'ok' => false,
            'message' => 'Could not resolve a supplier from the linked voucher payee.',
            'supplier_id' => (int) ($po['supplier_id'] ?? 0),
        ];
    }

    $currentId = (int) ($po['supplier_id'] ?? 0);
    if ($currentId === $resolvedSupplierId) {
        return [
            'ok' => true,
            'message' => 'Supplier already matches the linked voucher.',
            'supplier_id' => $currentId,
            'changed' => false,
            'payee_name' => $resolvedPayee,
        ];
    }

    try {
        $update = stockPurchaseUpdatePoSupplierId($pdo, $poId, $poTable, $resolvedSupplierId);
        if (!($update['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($update['message'] ?? 'Supplier update failed.'), 'supplier_id' => $currentId];
        }

        return [
            'ok' => true,
            'message' => 'Supplier updated to match voucher payee: ' . $resolvedPayee,
            'supplier_id' => $resolvedSupplierId,
            'changed' => true,
            'payee_name' => $resolvedPayee,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Supplier update failed: ' . $e->getMessage(), 'supplier_id' => $currentId];
    }
}

/**
 * True when linked voucher payee name differs from the PO supplier registry name.
 *
 * @return array{payee_name: string, supplier_name: string}|null
 */
function stockPurchaseDetectSupplierVoucherMismatch(array $po, int $companyId = 0, ?PDO $db = null): ?array
{
    global $pdo;
    $conn = $db instanceof PDO ? $db : $pdo;
    if (!$conn instanceof PDO) {
        return null;
    }
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    $voucherIds = stockPurchaseExpandPoLinkedVoucherIds($po, (int) ($po['id'] ?? 0), $companyId);
    if ($voucherIds === [] || !function_exists('validatePaymentVoucherForStockPoLink')) {
        return null;
    }

    $payeeName = '';
    foreach ($voucherIds as $voucherId) {
        $check = validatePaymentVoucherForStockPoLink($conn, (int) $voucherId, $companyId);
        if (!$check['ok'] || empty($check['row'])) {
            continue;
        }
        $candidate = trim((string) ($check['row']['payee_name'] ?? ''));
        if ($candidate !== '') {
            $payeeName = $candidate;
            break;
        }
    }
    if ($payeeName === '') {
        return null;
    }

    $supplierName = trim((string) ($po['supplier_name'] ?? ''));
    $supplierId = (int) ($po['supplier_id'] ?? 0);
    if ($supplierName === '' && $supplierId > 0) {
        $row = stockPurchaseFetchSupplierRowById($supplierId);
        $supplierName = trim((string) ($row['name'] ?? ''));
    }
    if ($supplierName === '') {
        return null;
    }

    if (strcasecmp($supplierName, $payeeName) === 0) {
        return null;
    }

    return ['payee_name' => $payeeName, 'supplier_name' => $supplierName];
}

/**
 * PDO connections that may hold stocks_suppliers / suppliers rows.
 *
 * @return array<int, PDO>
 */
function stockPurchaseSupplierPdbs(): array
{
    global $pdo;
    $list = [];
    foreach ([
        $pdo instanceof PDO ? $pdo : null,
        function_exists('stock_company_pdo') ? stock_company_pdo() : null,
        function_exists('erp_data_pdo') ? erp_data_pdo() : null,
        stockPurchaseControlPdo(),
    ] as $conn) {
        if ($conn instanceof PDO && !in_array($conn, $list, true)) {
            $list[] = $conn;
        }
    }
    return $list;
}

/**
 * PDO connections that may hold payment_vouchers (often separate from stock tenant DB).
 *
 * @return array<int, PDO>
 */
function stockPurchaseVoucherPdbs(): array
{
    global $pdo, $control_pdo;
    $list = [];
    foreach ([
        function_exists('voucher_operational_pdo') ? voucher_operational_pdo() : null,
        function_exists('erp_data_pdo') ? erp_data_pdo() : null,
        $pdo instanceof PDO ? $pdo : null,
        $control_pdo instanceof PDO ? $control_pdo : null,
        stockPurchaseControlPdo(),
    ] as $conn) {
        if ($conn instanceof PDO && !in_array($conn, $list, true)) {
            $list[] = $conn;
        }
    }
    return $list;
}

/**
 * @return array<string, mixed>|null
 */
function stockPurchaseFetchSupplierRowById(int $supplierId): ?array
{
    if ($supplierId <= 0) {
        return null;
    }
    foreach (stockPurchaseSupplierPdbs() as $db) {
        if (!function_exists('tableExists')) {
            continue;
        }
        if (tableExists('stocks_suppliers', $db)) {
            try {
                $stmt = $db->prepare('SELECT * FROM stocks_suppliers WHERE id = ? LIMIT 1');
                $stmt->execute([$supplierId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['_supplier_table'] = 'stocks_suppliers';
                    return $row;
                }
            } catch (Throwable $e) {
            }
        }
        if (tableExists('suppliers', $db)) {
            try {
                $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
                $stmt->execute([$supplierId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['_supplier_table'] = 'suppliers';
                    return $row;
                }
            } catch (Throwable $e) {
            }
        }
    }
    return null;
}

/**
 * @return array<string, mixed>|null
 */
function stockPurchaseFetchLegacySuppliersRowByName(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    foreach (stockPurchaseSupplierPdbs() as $db) {
        if (!function_exists('tableExists') || !tableExists('suppliers', $db)) {
            continue;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM suppliers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
            $stmt->execute([$name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['_supplier_table'] = 'suppliers';
                return $row;
            }
        } catch (Throwable $e) {
        }
    }
    return null;
}

/**
 * @return array<string, mixed>|null
 */
function stockPurchaseFetchSupplierRowByName(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $candidates = [];
    foreach (stockPurchaseSupplierPdbs() as $db) {
        if (!function_exists('tableExists')) {
            continue;
        }
        foreach (['stocks_suppliers', 'suppliers'] as $table) {
            if (!tableExists($table, $db)) {
                continue;
            }
            try {
                $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                $stmt->execute([$name]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $row['_supplier_table'] = $table;
                    $candidates[] = $row;
                }
            } catch (Throwable $e) {
            }
        }
    }

    if ($candidates === []) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        return stockPurchaseSupplierRowCompleteness($b) <=> stockPurchaseSupplierRowCompleteness($a);
    });

    return $candidates[0];
}

/**
 * Score how complete a supplier registry row is (for picking stocks vs legacy suppliers).
 */
function stockPurchaseSupplierRowCompleteness(array $row): int
{
    $score = 0;
    foreach (['email', 'email_address', 'phone', 'phone_number', 'mobile', 'address', 'location', 'contact_person', 'contact_name', 'contact_details'] as $col) {
        if (trim((string) ($row[$col] ?? '')) !== '') {
            $score++;
        }
    }
    return $score;
}

/**
 * Normalize a supplier registry row for PO display / edit UI (parses contact_details, merges legacy suppliers).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function stockPurchaseEnrichSupplierRecord(array $row): array
{
    $po = [
        'supplier_id' => (int) ($row['id'] ?? 0),
        'supplier_name' => trim((string) ($row['name'] ?? $row['supplier_name'] ?? '')),
        'contact_person' => trim((string) ($row['contact_person'] ?? $row['contact_name'] ?? '')),
        'supplier_email' => trim((string) ($row['email'] ?? $row['email_address'] ?? '')),
        'supplier_phone' => trim((string) ($row['phone'] ?? $row['phone_number'] ?? $row['mobile'] ?? '')),
        'supplier_address' => trim((string) ($row['address'] ?? $row['location'] ?? '')),
    ];

    stockPurchaseApplySupplierRowToPo($po, $row);

    $lookupName = trim((string) ($po['supplier_name'] ?? ''));
    if ($lookupName !== ''
        && (trim((string) ($po['supplier_email'] ?? '')) === ''
            || trim((string) ($po['supplier_phone'] ?? '')) === ''
            || trim((string) ($po['supplier_address'] ?? '')) === ''
            || trim((string) ($po['contact_person'] ?? '')) === '')
    ) {
        $legacy = stockPurchaseFetchLegacySuppliersRowByName($lookupName);
        if (is_array($legacy) && ($legacy['_supplier_table'] ?? '') === 'suppliers') {
            stockPurchaseApplySupplierRowToPo($po, $legacy);
        }
    }

    $addrBlob = trim((string) ($po['supplier_address'] ?? ''));
    if ($addrBlob !== ''
        && trim((string) ($po['supplier_email'] ?? '')) === ''
        && trim((string) ($po['supplier_phone'] ?? '')) === ''
        && (strpos($addrBlob, '@') !== false || preg_match('/\d{3,}/', $addrBlob))
    ) {
        $parsedBlob = stockPurchaseParseContactDetails($addrBlob);
        if ($parsedBlob['email'] !== '') {
            $po['supplier_email'] = $parsedBlob['email'];
        }
        if ($parsedBlob['phone'] !== '') {
            $po['supplier_phone'] = $parsedBlob['phone'];
        }
        if ($parsedBlob['address'] !== '') {
            $po['supplier_address'] = $parsedBlob['address'];
        }
    }

    $row['name'] = trim((string) ($po['supplier_name'] ?: ($row['name'] ?? '')));
    $row['contact_person'] = trim((string) ($po['contact_person'] ?? ''));
    $row['email'] = trim((string) ($po['supplier_email'] ?? ''));
    $row['phone'] = trim((string) ($po['supplier_phone'] ?? ''));
    $row['address'] = trim((string) ($po['supplier_address'] ?? ''));

    return $row;
}

/**
 * Apply a supplier registry row onto PO display fields.
 *
 * @param array<string, mixed> $po
 * @param array<string, mixed> $supplierRow
 */
function stockPurchaseApplySupplierRowToPo(array &$po, array $supplierRow): void
{
    $cols = array_keys($supplierRow);
    $nameCol = stockPurchasePickSupplierColumn($cols, ['name', 'supplier_name', 'company_name']);
    if ($nameCol !== null) {
        $nameVal = trim((string) ($supplierRow[$nameCol] ?? ''));
        if ($nameVal !== '') {
            $po['supplier_name'] = $nameVal;
        }
    }

    $contactCol = stockPurchasePickSupplierColumn($cols, ['contact_person', 'contact_name']);
    if ($contactCol !== null) {
        $contactVal = trim((string) ($supplierRow[$contactCol] ?? ''));
        if ($contactVal !== '') {
            $po['contact_person'] = $contactVal;
        }
    }

    $contactDetailsCol = stockPurchasePickSupplierColumn($cols, ['contact_details']);
    if ($contactDetailsCol !== null) {
        $parsed = stockPurchaseParseContactDetails((string) ($supplierRow[$contactDetailsCol] ?? ''));
        if ($parsed['email'] !== '') {
            $po['supplier_email'] = $parsed['email'];
        }
        if ($parsed['phone'] !== '') {
            $po['supplier_phone'] = $parsed['phone'];
        }
        if ($parsed['address'] !== '' || $parsed['full'] !== '') {
            $po['supplier_address'] = $parsed['address'] !== '' ? $parsed['address'] : $parsed['full'];
        }
    }

    foreach ([
        'supplier_email' => ['email', 'email_address'],
        'supplier_phone' => ['phone', 'phone_number', 'mobile'],
        'supplier_address' => ['address', 'location'],
    ] as $poKey => $candidates) {
        $col = stockPurchasePickSupplierColumn($cols, $candidates);
        if ($col === null) {
            continue;
        }
        $val = trim((string) ($supplierRow[$col] ?? ''));
        if ($val !== '') {
            $po[$poKey] = $val;
        }
    }
}

/**
 * @return array<string, string>|null
 */
function stockPurchaseResolveSupplierFromVouchers(array $po, int $companyId = 0): ?array
{
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    $poId = (int) ($po['id'] ?? 0);
    $voucherIds = stockPurchasePoLinkedVoucherIds($po);

    foreach (stockPurchaseVoucherPdbs() as $vPdo) {
        if (!function_exists('tableExists') || !tableExists('payment_vouchers', $vPdo)) {
            continue;
        }

        try {
            $pvCols = $vPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            continue;
        }

        if (!in_array('payee_name', $pvCols, true)) {
            continue;
        }

        $hasCompany = in_array('company_id', $pvCols, true) && $companyId > 0;
        $hasLinkedPo = in_array('linked_stock_po_id', $pvCols, true);

        $select = ['payee_name'];
        foreach (['payee_email', 'email', 'payee_phone', 'phone', 'payee_address', 'address'] as $col) {
            if (in_array($col, $pvCols, true)) {
                $select[] = $col;
            }
        }
        $selectSql = implode(', ', array_map(static function (string $c): string {
            return '`' . str_replace('`', '', $c) . '`';
        }, array_unique($select)));

        $attempts = [];
        if ($voucherIds !== []) {
            $placeholders = implode(',', array_fill(0, count($voucherIds), '?'));
            $attempts[] = ['sql' => "SELECT {$selectSql} FROM payment_vouchers WHERE id IN ({$placeholders}) ORDER BY id DESC LIMIT 1", 'params' => $voucherIds, 'scoped' => false];
            if ($hasCompany) {
                $attempts[] = ['sql' => "SELECT {$selectSql} FROM payment_vouchers WHERE id IN ({$placeholders}) AND company_id = ? ORDER BY id DESC LIMIT 1", 'params' => array_merge($voucherIds, [$companyId]), 'scoped' => true];
            }
        }
        if ($hasLinkedPo && $poId > 0) {
            $attempts[] = ['sql' => "SELECT {$selectSql} FROM payment_vouchers WHERE linked_stock_po_id = ? ORDER BY id DESC LIMIT 1", 'params' => [$poId], 'scoped' => false];
            if ($hasCompany) {
                $attempts[] = ['sql' => "SELECT {$selectSql} FROM payment_vouchers WHERE linked_stock_po_id = ? AND company_id = ? ORDER BY id DESC LIMIT 1", 'params' => [$poId, $companyId], 'scoped' => true];
            }
        }

        foreach ($attempts as $attempt) {
            try {
                $stmt = $vPdo->prepare($attempt['sql']);
                $stmt->execute($attempt['params']);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $row = null;
            }
            if (!$row) {
                continue;
            }

            $payeeName = trim((string) ($row['payee_name'] ?? ''));
            if ($payeeName === '') {
                continue;
            }

            $result = [
                'supplier_name' => $payeeName,
                'supplier_email' => trim((string) ($row['payee_email'] ?? $row['email'] ?? '')),
                'supplier_phone' => trim((string) ($row['payee_phone'] ?? $row['phone'] ?? '')),
                'supplier_address' => trim((string) ($row['payee_address'] ?? $row['address'] ?? '')),
                'contact_person' => $payeeName,
            ];

            $supplierByName = stockPurchaseFetchSupplierRowByName($payeeName);
            if ($supplierByName) {
                stockPurchaseApplySupplierRowToPo($result, $supplierByName);
                $result['supplier_name'] = trim((string) ($result['supplier_name'] ?: $payeeName));
            }

            return $result;
        }
    }

    return null;
}

/**
 * Fill supplier_name / email / phone / address on a PO row (stocks_suppliers uses contact_details).
 */
function enrichPurchaseOrderSupplierDisplay(array &$po, PDO $pdo, int $companyId = 0): void
{
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    $supplierId = (int) ($po['supplier_id'] ?? 0);
    $supplierRow = stockPurchaseFetchSupplierRowById($supplierId);
    if (is_array($supplierRow)) {
        stockPurchaseApplySupplierRowToPo($po, $supplierRow);
    }

    $lookupName = trim((string) ($po['supplier_name'] ?? ''));
    if ($lookupName === '' && is_array($supplierRow)) {
        $lookupName = trim((string) ($supplierRow['name'] ?? ''));
    }
    if ($lookupName !== ''
        && (trim((string) ($po['supplier_email'] ?? '')) === ''
            || trim((string) ($po['supplier_phone'] ?? '')) === ''
            || trim((string) ($po['supplier_address'] ?? '')) === ''
            || trim((string) ($po['contact_person'] ?? '')) === '')
    ) {
        $byName = stockPurchaseFetchSupplierRowByName($lookupName);
        if (is_array($byName)) {
            stockPurchaseApplySupplierRowToPo($po, $byName);
        }
        $legacyOnly = stockPurchaseFetchLegacySuppliersRowByName($lookupName);
        if (is_array($legacyOnly)) {
            stockPurchaseApplySupplierRowToPo($po, $legacyOnly);
        }
    }

    // SQL joins may put raw contact_details into supplier_address without splitting email/phone.
    $addrBlob = trim((string) ($po['supplier_address'] ?? ''));
    if ($addrBlob !== ''
        && trim((string) ($po['supplier_email'] ?? '')) === ''
        && trim((string) ($po['supplier_phone'] ?? '')) === ''
        && (strpos($addrBlob, '@') !== false || preg_match('/\d{3,}/', $addrBlob))
    ) {
        $parsedBlob = stockPurchaseParseContactDetails($addrBlob);
        if ($parsedBlob['email'] !== '') {
            $po['supplier_email'] = $parsedBlob['email'];
        }
        if ($parsedBlob['phone'] !== '') {
            $po['supplier_phone'] = $parsedBlob['phone'];
        }
        if ($parsedBlob['address'] !== '') {
            $po['supplier_address'] = $parsedBlob['address'];
        } elseif ($parsedBlob['full'] !== '' && $parsedBlob['full'] !== $addrBlob) {
            $po['supplier_address'] = $parsedBlob['full'];
        }
    }

    $supplierName = trim((string) ($po['supplier_name'] ?? ''));
    $isGenericName = $supplierId > 0 && $supplierName === 'Supplier #' . $supplierId;
    $needsVoucherName = $supplierName === '' || $isGenericName;
    $needsVoucherContact = trim((string) ($po['supplier_email'] ?? '')) === ''
        || trim((string) ($po['supplier_phone'] ?? '')) === ''
        || trim((string) ($po['supplier_address'] ?? '')) === ''
        || trim((string) ($po['contact_person'] ?? '')) === '';

    if ($needsVoucherName || $needsVoucherContact) {
        $fromVoucher = stockPurchaseResolveSupplierFromVouchers($po, $companyId);
        if (is_array($fromVoucher)) {
            foreach ($fromVoucher as $key => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                if ($key === 'supplier_name' && !$needsVoucherName) {
                    continue;
                }
                if (trim((string) ($po[$key] ?? '')) === '' || ($key === 'supplier_name' && $needsVoucherName)) {
                    $po[$key] = $value;
                }
            }
        }
    }

    if (trim((string) ($po['supplier_name'] ?? '')) === '' && $supplierId > 0) {
        $po['supplier_name'] = 'Supplier #' . $supplierId;
    }
}

/**
 * Load PO for view_po.php with supplier display fields populated.
 *
 * @return array<string, mixed>|null
 */
function stockPurchaseLoadPoForView(PDO $pdo, int $id, int $companyId = 0): ?array
{
    if ($id <= 0) {
        return null;
    }
    if ($companyId <= 0) {
        $companyId = stockPurchaseActiveCompanyId();
    }

    $po = function_exists('fetchStockPurchaseOrderById')
        ? fetchStockPurchaseOrderById($pdo, $id, true)
        : null;

    if (!$po) {
        return null;
    }

    if ($companyId > 0 && function_exists('loadStockPurchaseOrderForAccess')) {
        $allowed = loadStockPurchaseOrderForAccess($pdo, $id, $companyId, true);
        if ($allowed) {
            $po = $allowed;
        } else {
            $rowCompany = (int) ($po['company_id'] ?? 0);
            if ($rowCompany > 0 && $rowCompany !== $companyId) {
                return null;
            }
        }
    }

    $po['purchase_no'] = $po['po_number'] ?? $po['purchase_no'] ?? ('PO-' . $id);
    $po['procurement_workflow'] = $po['procurement_workflow'] ?? (defined('PURCHASE_PROC_STANDARD') ? PURCHASE_PROC_STANDARD : 'standard');
    $po['contact_person'] = $po['contact_person'] ?? $po['contact_details'] ?? '';

    enrichPurchaseOrderSupplierDisplay($po, $pdo, $companyId);

    return $po;
}

function loadStockPoByPublicToken(PDO $pdo, string $token): ?array {
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $hasLegacy = false;
    try {
        $hasLegacy = (bool) $pdo->query("SHOW TABLES LIKE 'suppliers'")->fetchColumn();
    } catch (Throwable $e) {
    }
    $nameExpr = $hasLegacy
        ? 'COALESCE(ss.name, ls.name, CONCAT(\'Supplier #\', p.supplier_id))'
        : 'COALESCE(ss.name, CONCAT(\'Supplier #\', p.supplier_id))';
    $emailExpr = $hasLegacy ? 'COALESCE(ss.email, ls.email)' : 'ss.email';
    $phoneExpr = $hasLegacy ? 'COALESCE(ss.phone, ls.phone)' : 'ss.phone';
    $sql = "SELECT p.*, {$nameExpr} AS supplier_name, {$emailExpr} AS supplier_email, {$phoneExpr} AS supplier_phone
            FROM stocks_purchase_orders p
            LEFT JOIN stocks_suppliers ss ON p.supplier_id = ss.id";
    if ($hasLegacy) {
        $sql .= ' LEFT JOIN suppliers ls ON p.supplier_id = ls.id';
    }
    $sql .= ' WHERE p.public_token = ? LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve a product image URL for PO line display (works under /stock/ and /ultimate/stock/).
 */
function resolveStockPurchaseLineImageUrl(int $productId, string $imageValue): string
{
    if ($productId <= 0) {
        return '';
    }

    $imageValue = trim($imageValue);
    if ($imageValue !== '' && preg_match('~^https?://~i', $imageValue)) {
        return $imageValue;
    }

    if (function_exists('stock_product_list_image_url')) {
        global $stockBasePath;
        $url = stock_product_list_image_url($productId, $imageValue, 'medium', (string) ($stockBasePath ?? ''));
        if ($url !== '') {
            return $url;
        }
    }

    $params = ['product_id' => $productId, 'size' => 'medium'];
    if ($imageValue !== '') {
        $params['file'] = basename(str_replace('\\', '/', $imageValue));
    }
    $query = http_build_query($params);
    global $stockBasePath;
    if (!empty($stockBasePath)) {
        return rtrim((string) $stockBasePath, '/') . '/product_image.php?' . $query;
    }

    return function_exists('app_url')
        ? (string) app_url('stock/product_image.php?' . $query)
        : '/stock/product_image.php?' . $query;
}

/**
 * Load stock PO line items with product names and image fields (same joins as view_po.php).
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockPurchaseOrderDisplayLineItems(PDO $pdo, int $poId): array
{
    if ($poId <= 0 || !function_exists('tableExists') || !tableExists('stocks_po_items', $pdo)) {
        return [];
    }

    $productCols = [];
    try {
        $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $productCols = [];
    }
    $productImageCol = null;
    if (in_array('image', $productCols, true)) {
        $productImageCol = 'image';
    } elseif (in_array('main_image', $productCols, true)) {
        $productImageCol = 'main_image';
    }

    $imgSelect = 'NULL AS product_image, NULL AS image_product_id';
    $pimgJoin = '';
    if ($productImageCol !== null) {
        $imgSelect = "pimg.`{$productImageCol}` AS product_image, pimg.id AS image_product_id";
        $pimgJoin = "LEFT JOIN products pimg
            ON (LOWER(TRIM(pimg.name)) = LOWER(TRIM(si.name)))
            OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(pimg.product_code)) = LOWER(TRIM(si.sku)))";
    }

    $rows = [];
    try {
        $sql = "SELECT pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
                si.name AS product_name, COALESCE(si.sku, '') AS product_code,
                {$imgSelect}
                FROM stocks_po_items pi
                INNER JOIN stocks_items si ON si.id = pi.item_id
                {$pimgJoin}
                WHERE pi.po_id = ?
                ORDER BY pi.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$poId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    if (!empty($rows)) {
        return $rows;
    }

    $prImg = $productImageCol !== null ? "pr.`{$productImageCol}` AS product_image" : 'NULL AS product_image';
    try {
        $sql2 = "SELECT pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price,
                pr.name AS product_name, COALESCE(pr.product_code, '') AS product_code,
                pr.id AS image_product_id, {$prImg}
                FROM stocks_po_items pi
                INNER JOIN products pr ON pr.id = pi.item_id
                WHERE pi.po_id = ?
                ORDER BY pi.id ASC";
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute([$poId]);
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    if (!empty($rows)) {
        return $rows;
    }

    try {
        $stmt3 = $pdo->prepare(
            'SELECT item_id AS product_id, qty_ordered AS quantity, unit_cost AS unit_price
             FROM stocks_po_items WHERE po_id = ? ORDER BY id ASC'
        );
        $stmt3->execute([$poId]);
        return $stmt3->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Ensure legacy purchase_items can track partial receipts.
 */
function ensureLegacyPurchaseItemsReceivedColumn(PDO $pdo): void
{
    if (!function_exists('tableExists') || !tableExists('purchase_items', $pdo)) {
        return;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM purchase_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('qty_received', $cols, true)) {
            $pdo->exec('ALTER TABLE purchase_items ADD COLUMN qty_received DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantity');
        }
    } catch (Throwable $e) {
    }
}

/**
 * Line items for domestic_receive.php on legacy purchases rows.
 *
 * @return array<int, array<string, mixed>>
 */
function stockPurchaseFetchLegacyReceiveLineItems(PDO $pdo, int $purchaseId): array
{
    if ($purchaseId <= 0 || !function_exists('tableExists')
        || !tableExists('purchase_items', $pdo) || !tableExists('purchases', $pdo)) {
        return [];
    }

    ensureLegacyPurchaseItemsReceivedColumn($pdo);

    $itemCols = [];
    try {
        $itemCols = $pdo->query('SHOW COLUMNS FROM purchase_items')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $itemCols = [];
    }
    $hasQtyReceived = in_array('qty_received', $itemCols, true);
    $receivedExpr = $hasQtyReceived
        ? "CASE WHEN p.status = 'Received' THEN pi.quantity ELSE COALESCE(pi.qty_received, 0) END"
        : "CASE WHEN p.status = 'Received' THEN pi.quantity ELSE 0 END";

    $productCols = [];
    try {
        $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $productCols = [];
    }
    $imageCol = in_array('image', $productCols, true) ? 'image' : (in_array('main_image', $productCols, true) ? 'main_image' : null);
    $imageSelect = $imageCol ? "pr.`{$imageCol}` AS product_image" : 'NULL AS product_image';

    $stockSelect = tableExists('stock', $pdo)
        ? '(SELECT COALESCE(s.quantity, 0) FROM stock s WHERE s.product_id = pi.product_id ORDER BY s.id ASC LIMIT 1)'
        : '0';

    $sql = "SELECT pi.id, pi.product_id AS item_id, pi.product_id AS image_product_id,
                   pi.quantity AS qty_ordered, {$receivedExpr} AS qty_received,
                   pr.name AS item_name, COALESCE(pr.product_code, '') AS sku,
                   {$imageSelect}, {$stockSelect} AS current_stock
            FROM purchase_items pi
            INNER JOIN purchases p ON p.id = pi.purchase_id
            LEFT JOIN products pr ON pr.id = pi.product_id
            WHERE pi.purchase_id = ?
            ORDER BY pi.id ASC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$purchaseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Post a legacy purchases receipt (purchase_items + stock table).
 *
 * @param array<int|string, float|int|string> $receiveQuantities
 * @return array{ok: bool, message: string}
 */
function stockPurchaseProcessLegacyReceive(
    PDO $pdo,
    int $purchaseId,
    array $receiveQuantities,
    string $notes = '',
    ?int $userId = null
): array {
    if ($purchaseId <= 0) {
        return ['ok' => false, 'message' => 'Invalid purchase order.'];
    }
    if (!function_exists('tableExists') || !tableExists('purchases', $pdo) || !tableExists('purchase_items', $pdo)) {
        return ['ok' => false, 'message' => 'Legacy purchase tables are not available.'];
    }

    ensureLegacyPurchaseItemsReceivedColumn($pdo);

    try {
        $stmtPo = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
        $stmtPo->execute([$purchaseId]);
        $po = $stmtPo->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }
        if (($po['status'] ?? '') === 'Cancelled') {
            return ['ok' => false, 'message' => 'Cannot receive a cancelled order.'];
        }
        if (($po['status'] ?? '') === 'Received') {
            return ['ok' => false, 'message' => 'This purchase order is already fully received.'];
        }

        $purchaseNo = trim((string) ($po['purchase_no'] ?? ''));
        if ($purchaseNo === '') {
            $purchaseNo = 'PO#' . $purchaseId;
        }
        $notes = trim($notes);

        $pdo->beginTransaction();

        $anyReceived = false;
        foreach ($receiveQuantities as $lineId => $qtyRaw) {
            $qty = (float) $qtyRaw;
            if ($qty <= 0) {
                continue;
            }

            $lineId = (int) $lineId;
            $stmtItem = $pdo->prepare('SELECT * FROM purchase_items WHERE id = ? AND purchase_id = ? LIMIT 1');
            $stmtItem->execute([$lineId, $purchaseId]);
            $line = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$line) {
                continue;
            }

            $ordered = (float) ($line['quantity'] ?? 0);
            $already = (float) ($line['qty_received'] ?? 0);
            $remaining = max(0, $ordered - $already);
            if ($remaining <= 0) {
                continue;
            }
            if ($qty > $remaining) {
                $qty = $remaining;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $pdo->prepare('UPDATE purchase_items SET qty_received = COALESCE(qty_received, 0) + ? WHERE id = ? AND purchase_id = ?')
                ->execute([$qty, $lineId, $purchaseId]);

            if (tableExists('stock', $pdo)) {
                $stmtStock = $pdo->prepare('SELECT id FROM stock WHERE product_id = ? LIMIT 1');
                $stmtStock->execute([$productId]);
                $stockId = (int) ($stmtStock->fetchColumn() ?: 0);
                if ($stockId > 0) {
                    $pdo->prepare('UPDATE stock SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?')
                        ->execute([$qty, $stockId]);
                } else {
                    $pdo->prepare("INSERT INTO stock (product_id, quantity, location, last_updated) VALUES (?, ?, 'Warehouse A', NOW())")
                        ->execute([$productId, $qty]);
                }
            }

            if (tableExists('stock_movements', $pdo)) {
                $movementNote = 'Received PO ' . $purchaseNo;
                if ($notes !== '') {
                    $movementNote .= ' — ' . $notes;
                }
                try {
                    $pdo->prepare(
                        "INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_at)
                         VALUES (?, 'in', ?, 'purchase', ?, ?, NOW())"
                    )->execute([$productId, $qty, (string) $purchaseId, $movementNote]);
                } catch (Throwable $e) {
                    error_log('stockPurchaseProcessLegacyReceive movement: ' . $e->getMessage());
                }
            }

            $anyReceived = true;
        }

        if (!$anyReceived) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'No valid quantities were processed.'];
        }

        $stmtRemain = $pdo->prepare(
            'SELECT COALESCE(SUM(GREATEST(0, COALESCE(quantity, 0) - COALESCE(qty_received, 0))), 0)
             FROM purchase_items WHERE purchase_id = ?'
        );
        $stmtRemain->execute([$purchaseId]);
        $remainingTotal = (float) $stmtRemain->fetchColumn();

        $poCols = $pdo->query('SHOW COLUMNS FROM purchases')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($remainingTotal <= 0) {
            $sets = ["status = 'Received'"];
            if (in_array('received_date', $poCols, true)) {
                $sets[] = 'received_date = NOW()';
            }
            if (in_array('received_by', $poCols, true) && $userId) {
                $sets[] = 'received_by = ' . (int) $userId;
            }
            if (in_array('updated_at', $poCols, true)) {
                $sets[] = 'updated_at = NOW()';
            }
            $pdo->exec('UPDATE purchases SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $purchaseId);
        } elseif (in_array('updated_at', $poCols, true)) {
            $pdo->prepare('UPDATE purchases SET updated_at = NOW() WHERE id = ?')->execute([$purchaseId]);
        }

        $pdo->commit();

        return ['ok' => true, 'message' => 'Stock received successfully for Order ' . $purchaseNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Error processing receipt: ' . $e->getMessage()];
    }
}
