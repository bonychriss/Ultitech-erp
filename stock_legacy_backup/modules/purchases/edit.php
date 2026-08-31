<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/purchase_workflow.php';
requireLogin();
$company_id = (int) (currentCompanyId() ?? 0);

ensurePurchaseWorkflowSchema($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

try {
    $cols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchase_type', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN purchase_type ENUM('domestic','import') NOT NULL DEFAULT 'domestic' AFTER supplier_id");
    }
    if (!in_array('supplier_invoice_no', $cols, true)) {
        $pdo->exec("ALTER TABLE stocks_purchase_orders ADD COLUMN supplier_invoice_no VARCHAR(50) NULL AFTER purchase_type");
    }
} catch (Exception $e) {
}

$stmt = $pdo->prepare('SELECT * FROM stocks_purchase_orders WHERE id = ? AND company_id = ?');
$stmt->execute([$id, $company_id]);
$po = $stmt->fetch();

if (!$po) {
    flash('success', 'Purchase order not found.', 'error');
    redirect('index.php');
}

$editableStatuses = purchaseOrderEditableStatuses($po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD);

if (!in_array($po['status'] ?? '', $editableStatuses, true)) {
    flash('success', 'This order can no longer be edited from this screen.', 'error');
    redirect('index.php');
}

$stmtItems = $pdo->prepare('
    SELECT pi.id AS line_id, pi.item_id AS product_id, pi.qty_ordered AS quantity, pi.unit_cost AS unit_price, pi.qty_received
    FROM stocks_po_items pi
    WHERE pi.po_id = ? AND pi.company_id = ?
    ORDER BY pi.id ASC
');
$stmtItems->execute([$id, $company_id]);
$existing_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$suppliers = [];
try {
    $stmtSuppliers = $pdo->prepare('SELECT * FROM stocks_suppliers WHERE company_id = ? ORDER BY name ASC');
    $stmtSuppliers->execute([$company_id]);
    $suppliers = $stmtSuppliers->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $suppliers = [];
}

$productCols = [];
try {
    $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $productCols = [];
}

$productImageCol = in_array('image', $productCols, true) ? 'image' : (in_array('main_image', $productCols, true) ? 'main_image' : null);
$productBuyingPriceCol = in_array('buying_price', $productCols, true) ? 'buying_price' : (in_array('cost_price', $productCols, true) ? 'cost_price' : 'unit_price');
$productSupplierCol = in_array('supplier_id', $productCols, true) ? 'supplier_id' : null;

$productImageSelect = $productImageCol ? "`$productImageCol` AS main_image" : 'NULL AS main_image';
$productBuyingPriceSelect = "`$productBuyingPriceCol` AS buying_price";
$productSupplierSelect = $productSupplierCol ? "`$productSupplierCol` AS supplier_id" : 'NULL AS supplier_id';

$products = $pdo->query("
    SELECT
        si.id,
        si.name,
        COALESCE(
            (SELECT p.product_code FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
            si.sku
        ) AS product_code,
        COALESCE(
            (SELECT $productBuyingPriceSelect FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
            0
        ) AS unit_price,
        COALESCE(
            (SELECT $productSupplierSelect FROM products p
             WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(si.name))
                OR (si.sku IS NOT NULL AND si.sku <> '' AND LOWER(TRIM(p.product_code)) = LOWER(TRIM(si.sku)))
             LIMIT 1),
            NULL
        ) AS supplier_id
    FROM stocks_items si
    WHERE si.company_id = " . (int)$company_id . "
    ORDER BY si.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$settings = getCompanySettings($pdo);
$rate = (float) ($settings['exchange_rate'] ?? 1);
if ($rate <= 0) {
    $rate = 1.0;
}
$currency = getCurrencySymbol($settings['currency'] ?? 'USD');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'] ?? '';
    $supplier_invoice_no = clean_input($_POST['supplier_invoice_no'] ?? '');
    $tax_percentage = isset($_POST['tax_percentage']) ? (float) $_POST['tax_percentage'] : 0.0;
    if ($tax_percentage < 0) $tax_percentage = 0.0;
    if ($tax_percentage > 100) $tax_percentage = 100.0;
    $line_ids = $_POST['line_id'] ?? [];
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];

    $validRows = [];
    if (is_array($product_ids)) {
        for ($i = 0; $i < count($product_ids); $i++) {
            if ($product_ids[$i] === '' || $product_ids[$i] === null) {
                continue;
            }
            $validRows[] = [
                'line_id' => isset($line_ids[$i]) && $line_ids[$i] !== '' ? (int) $line_ids[$i] : null,
                'item_id' => (int) $product_ids[$i],
                'qty' => isset($quantities[$i]) ? (float) $quantities[$i] : 0,
                'price_display' => isset($unit_prices[$i]) ? (float) $unit_prices[$i] : 0,
            ];
        }
    }

    if ($supplier_id === '' || $supplier_id === null) {
        $error = 'Please select a supplier.';
    } elseif (count($validRows) === 0) {
        $error = 'Please add at least one line item.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtPo = $pdo->prepare('SELECT * FROM stocks_purchase_orders WHERE id = ? AND company_id = ? FOR UPDATE');
            $stmtPo->execute([$id, $company_id]);
            $poRow = $stmtPo->fetch();
            $rowEditable = purchaseOrderEditableStatuses($poRow['procurement_workflow'] ?? PURCHASE_PROC_STANDARD);
            if (!$poRow || !in_array($poRow['status'] ?? '', $rowEditable, true)) {
                throw new Exception('This order is no longer editable.');
            }

            $stmtEx = $pdo->prepare('SELECT * FROM stocks_po_items WHERE po_id = ? AND company_id = ?');
            $stmtEx->execute([$id, $company_id]);
            $existingDb = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
            $existingById = [];
            foreach ($existingDb as $row) {
                $existingById[(int) $row['id']] = $row;
            }

            $postedLineIds = [];
            foreach ($validRows as $r) {
                if ($r['line_id']) {
                    $postedLineIds[$r['line_id']] = true;
                }
            }

            foreach ($existingDb as $ex) {
                $lid = (int) $ex['id'];
                if (isset($postedLineIds[$lid])) {
                    continue;
                }
                if ((float) ($ex['qty_received'] ?? 0) > 0) {
                    throw new Exception('Cannot remove a line that already has received quantity.');
                }
                $pdo->prepare('DELETE FROM stocks_po_items WHERE id = ? AND po_id = ? AND company_id = ?')->execute([$lid, $id, $company_id]);
                unset($existingById[$lid]);
            }

            foreach ($validRows as $r) {
                if ($r['qty'] <= 0) {
                    throw new Exception('Quantities must be greater than zero.');
                }
                $unitUsd = $r['price_display'] / $rate;

                if ($r['line_id'] && isset($existingById[$r['line_id']])) {
                    $ex = $existingById[$r['line_id']];
                    $received = (float) ($ex['qty_received'] ?? 0);
                    if ($r['qty'] < $received) {
                        throw new Exception('Ordered quantity cannot be less than quantity already received.');
                    }
                    if ($received > 0 && (int) $ex['item_id'] !== $r['item_id']) {
                        throw new Exception('Cannot change the product on a line that already has receipts.');
                    }
                    $pdo->prepare('UPDATE stocks_po_items SET item_id = ?, qty_ordered = ?, unit_cost = ?, landed_cost = ? WHERE id = ? AND po_id = ? AND company_id = ?')
                        ->execute([$r['item_id'], $r['qty'], $unitUsd, $unitUsd, $r['line_id'], $id, $company_id]);
                } elseif (!$r['line_id']) {
                    $pdo->prepare('INSERT INTO stocks_po_items (company_id, po_id, item_id, qty_ordered, qty_received, unit_cost, landed_cost) VALUES (?, ?, ?, ?, 0, ?, ?)')
                        ->execute([$company_id, $id, $r['item_id'], $r['qty'], $unitUsd, $unitUsd]);
                } else {
                    throw new Exception('Invalid line reference.');
                }
            }

            // Recalculate totals in base currency (USD) from the posted rows.
            $subtotalUsd = 0.0;
            foreach ($validRows as $r) {
                $qty = (float) ($r['qty'] ?? 0);
                $unitUsd = ((float) ($r['price_display'] ?? 0)) / $rate;
                $subtotalUsd += $qty * $unitUsd;
            }
            $taxAmountUsd = $subtotalUsd * ($tax_percentage / 100.0);
            $grandTotalUsd = $subtotalUsd + $taxAmountUsd;

            // Update PO header with schema-safe columns.
            $poCols = [];
            try {
                $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (Throwable $e) {
                $poCols = [];
            }

            $sets = ['supplier_id = ?', 'supplier_invoice_no = ?'];
            $vals = [$supplier_id, ($supplier_invoice_no !== '' ? $supplier_invoice_no : null)];

            if (in_array('tax_percentage', $poCols, true)) { $sets[] = 'tax_percentage = ?'; $vals[] = $tax_percentage; }
            if (in_array('tax_amount', $poCols, true)) { $sets[] = 'tax_amount = ?'; $vals[] = $taxAmountUsd; }
            if (in_array('subtotal', $poCols, true)) { $sets[] = 'subtotal = ?'; $vals[] = $subtotalUsd; }
            if (in_array('total_amount', $poCols, true)) { $sets[] = 'total_amount = ?'; $vals[] = $grandTotalUsd; }
            if (in_array('updated_at', $poCols, true)) { $sets[] = 'updated_at = NOW()'; }

            $vals[] = $id;
            $pdo->prepare('UPDATE stocks_purchase_orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND company_id = ' . (int)$company_id)->execute($vals);

            $pdo->commit();
            flash('success', 'Purchase order updated successfully.');
            redirect('index.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$page_title = 'Edit Purchase Order';
include '../../includes/header.php';

$poTypeLabel = ($po['purchase_type'] ?? 'domestic') === 'import' ? 'Import' : 'Domestic';
$statusLabel = htmlspecialchars((string) ($po['status'] ?? ''));
ob_start();
flash('success');
$flash_markup = trim(ob_get_clean());
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .po-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
    .po-edit-items-table { table-layout: fixed; width: 100%; }
    .po-edit-items-table thead tr.po-table-head th {
        background-color: #1c2331 !important;
        color: #fff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
    }
    .po-edit-items-table thead tr.po-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .po-btn-primary {
        background-color: #2563EB;
        color: #fff !important;
        border: 1px solid #2563EB;
    }
    .po-btn-primary:hover {
        background-color: #1D4ED8;
        border-color: #1D4ED8;
        color: #fff !important;
    }
    .po-btn-outline {
        border: 1px solid #e5e7eb;
        color: #374151;
        background: #fff;
    }
    .po-btn-outline:hover {
        border-color: #2563EB;
        color: #2563EB;
        background: #eff6ff;
    }
    .po-card-surface {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        box-shadow: 0 1px 2px rgb(0 0 0 / 0.04);
    }
    .po-edit-items-table tbody td { border-color: #f3f4f6; vertical-align: middle; }
    .po-edit-items-table tfoot td { background: #f9fafb; border-top: 1px solid #e5e7eb; }
    .supplier-info-edit {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        border-left: 3px solid #2563EB;
    }
    .po-edit-label { font-weight: 600; font-size: 0.875rem; color: #111827; margin-bottom: 0.375rem; display: block; }
    .po-totals-panel {
        position: sticky;
        top: 5.5rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgb(0 0 0 / 0.06);
    }
    .po-totals-panel .grand-line { border-top: 2px solid #e5e7eb; }
    .po-totals-panel .grand-value { color: #2563EB; font-size: 1.25rem; font-weight: 700; }
</style>

<main class="main-content po-shell bg-[#F9F9F9] min-h-[50vh] pb-10">
    <div class="max-w-[1400px] mx-auto px-3 sm:px-4">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm mb-4 rounded-b-lg overflow-hidden">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="po-btn-outline px-3 py-2 rounded-md text-sm font-semibold inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-xs"></i> Back to list
                </a>
                <div class="flex flex-col min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 leading-tight">Edit purchase order</h1>
                    <span class="text-sm text-gray-500 font-medium truncate"><?php echo htmlspecialchars($po['po_number'] ?? ('PO #' . $id)); ?></span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-gray-100 text-gray-700 border border-gray-200"><?php echo htmlspecialchars($poTypeLabel); ?></span>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-50 text-[#2563EB] border border-blue-100"><?php echo $statusLabel; ?></span>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <?php if ($flash_markup !== '' || $error !== ''): ?>
            <div class="px-4 py-2 bg-gray-50/80 border-b border-gray-100 space-y-2">
                <?php echo $flash_markup; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger mb-0 py-2 rounded-md border-0 shadow-sm"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <form method="post" action="edit.php?id=<?php echo $id; ?>" class="space-y-4">
            <section class="po-card-surface overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-white flex items-center gap-2">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-gray-100 text-gray-600"><i class="fas fa-truck-field"></i></span>
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wide m-0">Supplier &amp; reference</h2>
                </div>
                <div class="p-4 sm:p-5">
                    <div class="row g-3 g-md-4">
                        <div class="col-md-6">
                            <label for="supplier_id" class="po-edit-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select rounded-md border-gray-200" id="supplier_id" name="supplier_id" required onchange="updateSupplierDetails()">
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?php echo (int) $sup['id']; ?>" <?php echo (string) $po['supplier_id'] === (string) $sup['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sup['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="supplierInfo" class="supplier-info-edit mt-3 p-3 text-sm text-gray-600 d-none"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="po-edit-label">PO date</label>
                            <input type="text" class="form-control rounded-md border-gray-200 bg-gray-50 text-gray-600" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime($po['created_at'] ?? 'now'))); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="po-edit-label">Type</label>
                            <input type="text" class="form-control rounded-md border-gray-200 bg-gray-50 text-gray-600" value="<?php echo ($po['purchase_type'] ?? 'domestic') === 'import' ? 'Outdoor / Import' : 'Domestic'; ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="supplier_invoice_no" class="po-edit-label">Supplier invoice #</label>
                            <input type="text" class="form-control rounded-md border-gray-200" id="supplier_invoice_no" name="supplier_invoice_no" value="<?php echo htmlspecialchars($po['supplier_invoice_no'] ?? ''); ?>" placeholder="Optional">
                        </div>
                    </div>
                </div>
            </section>

            <section class="po-card-surface overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-white flex items-center gap-2">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-gray-100 text-gray-600"><i class="fas fa-list-ul"></i></span>
                    <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wide m-0">Line items</h2>
                    <span class="text-xs text-gray-500 font-normal normal-case ms-1">Stock catalogue</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-hover mb-0 po-edit-items-table border-0" id="itemsTable">
                        <thead>
                            <tr class="po-table-head">
                                <th class="px-3 py-2.5 text-sm" style="width: 38%;">Item</th>
                                <th class="px-3 py-2.5 text-sm text-center" style="width: 14%;">Qty</th>
                                <th class="px-3 py-2.5 text-sm" style="width: 20%;">Unit (<?php echo htmlspecialchars($currency); ?>)</th>
                                <th class="px-3 py-2.5 text-sm" style="width: 20%;">Line total</th>
                                <th class="px-3 py-2.5 text-sm text-center" style="width: 8%;"><i class="fas fa-ellipsis-h text-white/60" title="Remove"></i></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="px-3 py-3">
                                    <button type="button" class="po-btn-outline px-4 py-2 rounded-md text-sm font-semibold inline-flex items-center gap-2" onclick="addItemRow()">
                                        <i class="fas fa-plus text-xs text-[#2563EB]"></i> Add line
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div class="row g-4 align-items-start">
                <div class="col-lg-7">
                    <div class="rounded-lg border border-amber-100 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                        <i class="fas fa-info-circle text-amber-600 me-1"></i>
                        Lines with <strong>received</strong> quantity cannot be removed or have the product changed. Ordered quantity cannot go below what is already received.
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="po-totals-panel p-4">
                        <div class="d-flex justify-content-between align-items-center mb-2 text-gray-600 text-sm">
                            <span>Subtotal</span>
                            <span class="fw-semibold text-gray-900" id="displaySubtotal"><?php echo htmlspecialchars($currency); ?>0.00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 text-gray-600 text-sm">
                            <span>Tax rate</span>
                            <div class="d-flex align-items-center gap-2">
                                <input type="number"
                                       step="0.01"
                                       min="0"
                                       max="100"
                                       class="form-control form-control-sm rounded-md border-gray-200 text-end"
                                       style="width: 90px;"
                                       name="tax_percentage"
                                       id="taxPercentage"
                                       value="<?php echo htmlspecialchars((string) ((float) ($po['tax_percentage'] ?? 0))); ?>"
                                       oninput="calculateGrandTotal()">
                                <span class="text-gray-500">%</span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 text-gray-600 text-sm">
                            <span>Tax amount</span>
                            <span class="fw-semibold text-gray-900" id="displayTax"><?php echo htmlspecialchars($currency); ?>0.00</span>
                        </div>
                        <div class="grand-line d-flex justify-content-between align-items-center pt-3 mt-2">
                            <span class="fw-bold text-gray-900">Total</span>
                            <span class="grand-value" id="displayGrandTotal"><?php echo htmlspecialchars($currency); ?>0.00</span>
                        </div>
                        <button type="submit" class="po-btn-primary w-100 rounded-md mt-4 py-2.5 fw-bold text-base shadow-sm">
                            <i class="fas fa-save me-2"></i> Update purchase order
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</main>

<script>
const productsData = <?php echo json_encode($products); ?>;
const suppliersData = <?php echo json_encode($suppliers); ?>;
const EXCHANGE_RATE = <?php echo json_encode($rate); ?>;
const CURRENCY_SYMBOL = <?php echo json_encode($currency); ?>;

const existingItems = <?php echo json_encode($existing_items); ?>;

function addItemRow(data = null) {
    const tbody = document.getElementById('itemsBody');
    const rowId = 'row_' + Date.now() + Math.random().toString(36).substr(2, 5);

    let productOptions = '<option value="">Select item</option>';
    productsData.forEach(p => {
        const selected = (data && String(data.product_id) === String(p.id)) ? 'selected' : '';
        const code = p.product_code ? ` (${p.product_code})` : '';
        productOptions += `<option value="${p.id}" data-price="${p.unit_price}" ${selected}>${p.name}${code}</option>`;
    });

    let qty = 1;
    let lineIdField = '';
    let unitPriceVal = '0.00';
    let totalVal = '0.00';
    let receivedNote = '';
    let disableProduct = false;

    if (data) {
        qty = parseFloat(data.quantity) || 1;
        const priceUsd = parseFloat(data.unit_price) || 0;
        unitPriceVal = (priceUsd * EXCHANGE_RATE).toFixed(2);
        totalVal = (qty * parseFloat(unitPriceVal)).toFixed(2);
        if (data.line_id) {
            lineIdField = `<input type="hidden" name="line_id[]" value="${data.line_id}">`;
        } else {
            lineIdField = `<input type="hidden" name="line_id[]" value="">`;
        }
        const received = parseFloat(data.qty_received) || 0;
        if (received > 0) {
            receivedNote = `<div class="small text-amber-700 mt-1 fw-medium">${received} already received — product locked</div>`;
            disableProduct = true;
        }
    } else {
        lineIdField = `<input type="hidden" name="line_id[]" value="">`;
    }

    const tr = document.createElement('tr');
    tr.id = rowId;
    tr.innerHTML = `
        <td class="px-3 py-2.5">
            ${lineIdField}
            <select class="form-select form-select-sm rounded-md border-gray-200" name="product_id[]" onchange="updateRowPrice(this)" required ${disableProduct ? 'disabled' : ''}>
                ${productOptions}
            </select>
            ${disableProduct ? `<input type="hidden" name="product_id[]" value="${data.product_id}">` : ''}
            ${receivedNote}
        </td>
        <td class="px-3 py-2.5 text-center">
            <input type="number" class="form-control form-control-sm rounded-md border-gray-200 text-center" name="quantity[]" min="0.01" step="0.01" value="${qty}" oninput="updateRowTotal(this)" required>
        </td>
        <td class="px-3 py-2.5">
            <input type="number" step="0.01" class="form-control form-control-sm rounded-md border-gray-200" name="unit_price[]" value="${unitPriceVal}" oninput="updateRowTotal(this)" required>
        </td>
        <td class="px-3 py-2.5">
            <input type="text" class="form-control form-control-sm rounded-md bg-gray-50 border-gray-200" name="total[]" value="${totalVal}" readonly>
        </td>
        <td class="px-3 py-2.5 text-center">
            ${(!data || !(parseFloat(data.qty_received) > 0)) ? `<button type="button" class="btn btn-sm border-0 bg-transparent text-gray-400 hover:text-red-600 p-1 rounded" title="Remove line" onclick="removeRow('${rowId}')"><i class="fas fa-trash-alt"></i></button>` : ''}
        </td>
    `;

    tbody.appendChild(tr);
}

function removeRow(rowId) {
    const el = document.getElementById(rowId);
    if (el) el.remove();
    calculateGrandTotal();
}

function updateRowPrice(select) {
    const row = select.closest('tr');
    const option = select.options[select.selectedIndex];
    const basePrice = parseFloat(option.getAttribute('data-price')) || 0;
    const displayPrice = basePrice * EXCHANGE_RATE;
    row.querySelector('input[name="unit_price[]"]').value = displayPrice.toFixed(2);
    updateRowTotal(select);
}

function updateRowTotal(element) {
    const row = element.closest('tr');
    const qty = parseFloat(row.querySelector('input[name="quantity[]"]').value) || 0;
    const price = parseFloat(row.querySelector('input[name="unit_price[]"]').value) || 0;
    const total = qty * price;
    const totalInput = row.querySelector('input[name="total[]"]');
    if (totalInput) totalInput.value = total.toFixed(2);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let subtotal = 0;
    document.querySelectorAll('input[name="total[]"]').forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    const taxPctEl = document.getElementById('taxPercentage');
    const taxPct = taxPctEl ? (parseFloat(taxPctEl.value) || 0) : 0;
    const taxAmount = subtotal * (taxPct / 100);
    const grand = subtotal + taxAmount;

    document.getElementById('displaySubtotal').innerText = CURRENCY_SYMBOL + subtotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const taxEl = document.getElementById('displayTax');
    if (taxEl) {
        taxEl.innerText = CURRENCY_SYMBOL + taxAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    document.getElementById('displayGrandTotal').innerText = CURRENCY_SYMBOL + grand.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function updateSupplierDetails() {
    const id = document.getElementById('supplier_id').value;
    const infoBox = document.getElementById('supplierInfo');
    if (!id) {
        infoBox.classList.add('d-none');
        infoBox.innerHTML = '';
        return;
    }
    const supplier = suppliersData.find(s => String(s.id) === String(id));
    if (supplier) {
        infoBox.classList.remove('d-none');
        let html = `<strong>${supplier.name}</strong><br>`;
        const addr = supplier.address || supplier.contact_details;
        if (addr) html += `${String(addr).replace(/\n/g, '<br>')}<br>`;
        const cp = supplier.contact_person || supplier.contact_name;
        if (cp) html += `Attn: ${cp}<br>`;
        if (supplier.phone) html += `Phone: ${supplier.phone}<br>`;
        if (supplier.email) html += `Email: ${supplier.email}`;
        infoBox.innerHTML = html;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (existingItems && existingItems.length > 0) {
        existingItems.forEach(item => addItemRow(item));
    } else {
        addItemRow();
    }
    calculateGrandTotal();
    updateSupplierDetails();
});
</script>

<?php include '../../includes/footer.php'; ?>
