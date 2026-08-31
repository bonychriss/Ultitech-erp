<?php
/**
 * Legacy purchases table: supplier-only edit (full line edit is stocks_purchase_orders only).
 *
 * @var array<string, mixed> $po
 * @var PDO $pdo
 * @var int $id
 * @var int $company_id
 * @var array<int, string> $supplierCols
 * @var bool $hasSupplierCompanyId
 */

$legacyEditable = function_exists('purchaseLegacyEditableStatuses')
    ? purchaseLegacyEditableStatuses()
    : ['Pending', 'Approved', 'Supplier Responded'];

if (!in_array((string) ($po['status'] ?? ''), $legacyEditable, true)) {
    flash('success', 'This legacy purchase order can no longer be edited (status: ' . ($po['status'] ?? '') . ').', 'error');
    redirect('view_po.php?id=' . $id);
}

$suppliers = [];
try {
    $supSql = 'SELECT id, name FROM stocks_suppliers';
    $supParams = [];
    if (!empty($hasSupplierCompanyId) && $company_id > 0) {
        $supSql .= ' WHERE company_id = ?';
        $supParams[] = $company_id;
    }
    $supSql .= ' ORDER BY name ASC';
    $stmtSuppliers = $pdo->prepare($supSql);
    $stmtSuppliers->execute($supParams);
    $suppliers = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $suppliers = [];
}

$legacyError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = (int) ($_POST['supplier_id'] ?? 0);
    if ($supplierId <= 0) {
        $legacyError = 'Please select a supplier.';
    } elseif (function_exists('stockPurchaseUpdatePoSupplierId')) {
        $result = stockPurchaseUpdatePoSupplierId($pdo, $id, 'purchases', $supplierId);
        if ($result['ok'] ?? false) {
            flash('success', (string) ($result['message'] ?? 'Supplier updated.'));
            redirect('view_po.php?id=' . $id);
        }
        $legacyError = (string) ($result['message'] ?? 'Could not update supplier.');
    } else {
        $legacyError = 'Supplier update is not available.';
    }
}

$page_title = 'Edit Supplier � ' . ($po['purchase_no'] ?? $po['po_number'] ?? ('PO-' . $id));
include __DIR__ . '/../../includes/header.php';
?>
<main class="main-content">
    <div class="stock-container" style="max-width: 720px; margin: 0 auto;">
        <div class="d-flex align-items-center justify-content-between mb-4">



            <div>
                <h1 class="h4 mb-1">Edit supplier</h1>
                <p class="text-muted small mb-0">
                    Legacy purchase <?php echo htmlspecialchars((string) ($po['purchase_no'] ?? $po['po_number'] ?? ('#' . $id))); ?>
                    � line items cannot be changed here; update supplier only.
                </p>
            </div>
            <a href="view_po.php?id=<?php echo (int) $id; ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to PO
            </a>
        </div>

        <?php flash('success'); flash('error'); ?>
        <?php if ($legacyError !== ''): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($legacyError); ?></div>
        <?php endif; ?>

        <?php if (function_exists('stockPurchaseDetectSupplierVoucherMismatch')) {
            $legacyMismatch = stockPurchaseDetectSupplierVoucherMismatch($po, $company_id, $pdo);
            if ($legacyMismatch): ?>
            <div class="alert alert-warning">
                Linked voucher payee is <strong><?php echo htmlspecialchars($legacyMismatch['payee_name']); ?></strong>,
                but this PO currently shows <strong><?php echo htmlspecialchars($legacyMismatch['supplier_name']); ?></strong>.
                <a class="alert-link ms-1" href="view_po.php?id=<?php echo (int) $id; ?>&sync_supplier=1"
                   onclick="return confirm('Set supplier from linked voucher payee?');">Fix from voucher</a>
            </div>
        <?php endif; } ?>

        <form method="post" class="card shadow-sm border-0">
            <div class="card-body">
                <div class="mb-3">
                    <label for="supplier_id" class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                        <option value="">� Select supplier �</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo (int) $sup['id']; ?>"
                                <?php echo (string) ($po['supplier_id'] ?? '') === (string) $sup['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $sup['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save supplier
                    </button>
                    <a href="view_po.php?id=<?php echo (int) $id; ?>" class="btn btn-light">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</main>
<?php
include __DIR__ . '/../../includes/footer.php';
exit;
