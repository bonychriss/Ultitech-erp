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
        'total_amount' => 'ALTER TABLE stocks_purchase_orders ADD COLUMN total_amount DECIMAL(18,6) NULL DEFAULT NULL',
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
