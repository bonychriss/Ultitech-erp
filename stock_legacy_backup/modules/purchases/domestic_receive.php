<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('success_type', 'error');
    flash('success', 'Invalid Purchase Order ID.');
    redirect('index.php');
}

// Supplier tables differ across installs. Build a compatible supplier SELECT.
$supplierCols = [];
try {
    $supplierCols = $pdo->query("SHOW COLUMNS FROM stocks_suppliers")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $supplierCols = [];
}
$supplierContactExpr = 'NULL';
if (in_array('contact_person', $supplierCols, true)) {
    $supplierContactExpr = 's.contact_person';
} elseif (in_array('contact_name', $supplierCols, true)) {
    $supplierContactExpr = 's.contact_name';
} elseif (in_array('contact_details', $supplierCols, true)) {
    $supplierContactExpr = 's.contact_details';
}
$supplierPhoneExpr = 'NULL';
if (in_array('phone', $supplierCols, true)) {
    $supplierPhoneExpr = 's.phone';
} elseif (in_array('phone_number', $supplierCols, true)) {
    $supplierPhoneExpr = 's.phone_number';
} elseif (in_array('mobile', $supplierCols, true)) {
    $supplierPhoneExpr = 's.mobile';
}

// Fetch PO with Supplier info
$stmt = $pdo->prepare("
    SELECT
        p.*,
        s.name as supplier_name,
        $supplierContactExpr as supplier_contact,
        $supplierPhoneExpr as supplier_phone
    FROM stocks_purchase_orders p
    LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    flash('success_type', 'error');
    flash('success', 'Purchase Order not found.');
    redirect('index.php');
}

$poType = $po['purchase_type'] ?? 'domestic';
$poType = in_array($poType, ['domestic', 'import'], true) ? $poType : 'domestic';

ensure_shipment_po_linking_schema($pdo);
if ($poType === 'import' && !stocks_po_has_linked_shipment($pdo, $id)) {
    flash('success_type', 'error');
    flash('success', 'Outdoor POs need a linked shipment before you can receive stock. Open this PO on the purchase list and use Create shipment.');
    redirect('index.php');
}

// Fetch PO Items with current stock info
$stmtItems = $pdo->prepare("
    SELECT pi.*, si.name as item_name, si.sku, si.stock_quantity as current_stock
    FROM stocks_po_items pi
    JOIN stocks_items si ON pi.item_id = si.id
    WHERE pi.po_id = ?
");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll();

$remainingTotal = 0.0;
$fullyReceived = false;
if (!empty($items)) {
    foreach ($items as $it) {
        $ordered = (float)($it['qty_ordered'] ?? 0);
        $received = (float)($it['qty_received'] ?? 0);
        $remainingTotal += max(0, $ordered - $received);
    }
    $fullyReceived = $remainingTotal <= 0;
}

// Reconcile status if quantities show fully received but status isn't updated.
if ($fullyReceived && (($po['status'] ?? '') !== 'Received')) {
    try {
        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('updated_at', $poCols, true)) {
            $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received', updated_at = NOW() WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE stocks_purchase_orders SET status = 'Received' WHERE id = ?")->execute([$id]);
        }
        $po['status'] = 'Received';
    } catch (Throwable $e) {
        // ignore
    }
}

$issuedInvoices = [];
$hasInvoicesTable = false;
try {
    $hasInvoicesTable = (bool) $pdo->query("SHOW TABLES LIKE 'invoices'")->fetchColumn();
} catch (Throwable $e) {
    $hasInvoicesTable = false;
}
if ($hasInvoicesTable) {
    try {
        $issuedInvoices = $pdo->query("
            SELECT i.id, i.invoice_number, i.invoice_date,
                   COALESCE(c.company_name, CONCAT('Customer #', i.customer_id)) AS customer_name
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            ORDER BY i.invoice_date DESC, i.id DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $issuedInvoices = [];
    }
}

$page_title = 'Receive stock â€” ' . ($po['po_number'] ?: '#' . $id);
include '../../includes/header.php';
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .dr-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .btn.dr-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .btn.dr-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .qty-input {
        max-width: 100px;
        text-align: center;
        font-weight: 700;
    }
    .receive-table thead tr.dr-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .receive-table thead tr.dr-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .receive-table tbody tr:hover {
        background-color: #f9fafb;
    }
</style>

<main class="main-content dr-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Purchase orders
                </a>
                <a href="view_po.php?id=<?php echo $id; ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-eye text-sm"></i> View PO
                </a>
                <div class="flex items-center gap-2 min-w-0 flex-wrap">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">
                        Receive stock <span class="text-[#2563EB]"><?php echo htmlspecialchars($po['po_number'] ?: '#' . $id); ?></span>
                    </h1>
                    <?php if ($poType === 'import'): ?>
                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-blue-50 text-blue-700 border border-blue-200 whitespace-nowrap">Outdoor</span>
                    <?php else: ?>
                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-gray-100 text-gray-800 border border-gray-200 whitespace-nowrap">Domestic</span>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-gray-800 border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-times text-sm"></i> Cancel
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span class="inline-flex items-center gap-1.5"><i class="fas fa-user-tie text-gray-400 text-sm"></i><?php echo htmlspecialchars($po['supplier_name'] ?? 'â€”'); ?></span>
                <span class="text-gray-300">|</span>
                <span class="inline-flex items-center gap-1.5"><i class="fas fa-calendar-alt text-gray-400 text-sm"></i>Ordered <?php echo date('M d, Y', strtotime($po['created_at'])); ?></span>
                <?php if (!empty($po['status'])): ?>
                    <span class="ms-auto inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-gray-200 text-gray-800"><?php echo htmlspecialchars($po['status']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <form action="domestic_receive_process.php" method="POST" id="receivingForm">
            <input type="hidden" name="po_id" value="<?php echo $id; ?>">

            <div class="px-4 pt-4 flex flex-col lg:flex-row gap-4 items-start">
                <div class="flex-1 min-w-0 w-full">
                    <?php if ($fullyReceived): ?>
                        <?php
                        $firstItemName = '';
                        if (!empty($items)) {
                            $firstItemName = (string) ($items[0]['item_name'] ?? '');
                        }
                        ?>
                        <div class="alert alert-success border-0 shadow-sm mb-4">
                            <i class="fas fa-check-circle me-2"></i>
                            This purchase order is already fully received. There is nothing left to post.
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <a href="../products/index.php?search=<?php echo urlencode($firstItemName); ?>&hl=<?php echo urlencode($firstItemName); ?>" class="btn btn-sm dr-btn-primary border-0 rounded-md fw-bold">
                                    <i class="fas fa-warehouse me-2"></i> Open in inventory (highlight)
                                </a>
                                <a href="receipt_audit.php?po_id=<?php echo (int) $id; ?>" class="btn btn-sm btn-outline-secondary rounded-md fw-bold">
                                    <i class="fas fa-receipt me-2"></i> View receipt audit
                                </a>
                                <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-md fw-bold">
                                    <i class="fas fa-arrow-left me-2"></i> Back to purchase orders
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="bg-white border border-gray-200 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
                            <i class="fas fa-boxes text-[#2563EB]"></i>
                            <span class="font-semibold text-gray-900 text-base">Itemized receipt</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="table table-hover align-middle mb-0 receive-table w-100">
                                <thead>
                                    <tr class="dr-table-head">
                                        <th class="ps-4 py-3">Item details</th>
                                        <th class="text-center py-3">Ordered</th>
                                        <th class="text-center py-3">Prev. received</th>
                                        <th class="text-center py-3">Remaining</th>
                                        <th class="text-center py-3" style="width: 150px;">Receive now</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item):
                                        $ordered = (float)($item['qty_ordered'] ?? 0);
                                        $received = (float)($item['qty_received'] ?? 0);
                                        $remaining = max(0, $ordered - $received);
                                    ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="ps-4 py-3">
                                            <div class="fw-bold text-gray-900 text-base"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                            <div class="text-gray-500 text-sm mt-0.5">SKU: <span class="fw-medium"><?php echo htmlspecialchars($item['sku']); ?></span></div>
                                        </td>
                                        <td class="text-center py-3 text-base text-gray-800 tabular-nums fw-medium">
                                            <?php echo number_format($ordered); ?>
                                        </td>
                                        <td class="text-center py-3">
                                            <span class="inline-flex items-center px-3 py-0.5 rounded-pill text-sm font-medium bg-gray-100 text-gray-800 border border-gray-200">
                                                <?php echo number_format($received); ?>
                                            </span>
                                        </td>
                                        <td class="text-center py-3 bg-gray-50/80">
                                            <span class="fw-bold text-base tabular-nums <?php echo $remaining > 0 ? 'text-[#2563EB]' : 'text-[#28A745]'; ?>">
                                                <?php echo number_format($remaining); ?>
                                            </span>
                                        </td>
                                        <td class="text-center py-3 pe-4">
                                            <input type="number"
                                                   name="receive_qty[<?php echo $item['id']; ?>]"
                                                   class="form-control qty-input mx-auto rounded-md border-gray-300 focus:border-[#2563EB]"
                                                   value="<?php echo $remaining; ?>"
                                                   min="0"
                                                   max="<?php echo $remaining; ?>"
                                                   <?php echo $remaining == 0 ? 'disabled' : ''; ?>
                                                   data-remaining="<?php echo $remaining; ?>">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (empty($items)): ?>
                            <div class="text-center py-16 px-4">
                                <i class="fas fa-exclamation-triangle text-5xl text-amber-300 mb-4"></i>
                                <p class="text-gray-600 text-lg font-medium">No items found for this order.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="w-full lg:w-80 flex-shrink-0 lg:sticky lg:top-[7.5rem] self-start">
                    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mb-4">
                        <div class="px-4 py-3 border-b border-gray-100">
                            <h2 class="m-0 text-sm font-bold text-gray-500 uppercase tracking-wide">Receipt details</h2>
                        </div>
                        <div class="p-4">
                            <?php if ($poType !== 'import'): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-gray-600 mb-1" for="recv_reference">Delivery ref / invoice #</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-gray-50 border-gray-200 border-end-0"><i class="fas fa-file-invoice text-gray-400"></i></span>
                                    <input type="text" name="reference" id="recv_reference" class="form-control border-gray-200 border-start-0 rounded-md" placeholder="e.g. DN-5562" autocomplete="off">
                                </div>
                                <div class="form-text text-gray-500 small mt-1">Supplier delivery note or invoice number.</div>
                            </div>
                            <?php if ($hasInvoicesTable && !empty($issuedInvoices)): ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-gray-600 mb-1" for="issued_invoices">Issued invoices (optional)</label>
                                <select name="issued_invoice_ids[]" id="issued_invoices" class="form-select border-gray-200 rounded-md" multiple size="6">
                                    <?php foreach ($issuedInvoices as $inv): ?>
                                        <option value="<?php echo (int) $inv['id']; ?>">
                                            <?php echo htmlspecialchars((string) ($inv['invoice_number'] ?? 'INV')); ?>
                                            <?php if (!empty($inv['customer_name'])): ?> â€” <?php echo htmlspecialchars((string) $inv['customer_name']); ?><?php endif; ?>
                                            <?php if (!empty($inv['invoice_date'])): ?> (<?php echo htmlspecialchars((string) $inv['invoice_date']); ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text text-gray-500 small mt-1">Hold Ctrl/Shift to select multiple invoices. Selected invoice numbers will be appended to the reference.</div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-gray-600 mb-1">Receiving notes</label>
                                <textarea name="notes" class="form-control border-gray-200 rounded-md" rows="4" placeholder="Optional comments (condition, packaging, etc.)"></textarea>
                            </div>

                            <button type="submit" class="btn dr-btn-primary w-100 btn-lg rounded-md py-3 fw-bold shadow-sm border-0" id="submitBtn">
                                <i class="fas fa-check-circle me-2"></i>Post goods receipt
                            </button>
                        </div>
                    </div>

                    <div class="rounded-lg border border-blue-100 bg-blue-50/80 px-4 py-3 text-sm text-gray-700">
                        <i class="fas fa-info-circle text-[#2563EB] me-2"></i>
                        <strong class="text-gray-800">Tip:</strong> Quantities post to on-hand stock.
                        <?php if ($poType === 'import'): ?>
                            <span class="d-block mt-2 text-gray-600">Outdoor POs are received here; use <strong>Shipments</strong> only for tracking ETA, freight, and customs â€” not for posting inventory.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('receivingForm');
    const submitBtn = document.getElementById('submitBtn');
    const issuedSelect = document.getElementById('issued_invoices');
    const refInput = document.getElementById('recv_reference');
    const fullyReceived = <?php echo $fullyReceived ? 'true' : 'false'; ?>;

    if (issuedSelect && refInput) {
        const updateRefFromInvoices = () => {
            const selected = Array.from(issuedSelect.selectedOptions || []);
            const invNos = selected
                .map(o => (o.textContent || '').trim().split('â€”')[0].trim())
                .filter(Boolean);
            const base = (refInput.value || '').split('| Invoices:')[0].trim();
            if (invNos.length > 0) {
                refInput.value = (base ? base + ' ' : '') + '| Invoices: ' + invNos.join(', ');
            } else {
                refInput.value = base;
            }
        };
        issuedSelect.addEventListener('change', updateRefFromInvoices);
    }

    form.addEventListener('submit', function(e) {
        let totalReceiving = 0;
        const inputs = document.querySelectorAll('.qty-input:not(:disabled)');
        
        inputs.forEach(input => {
            totalReceiving += parseFloat(input.value) || 0;
        });

        if (totalReceiving <= 0) {
            e.preventDefault();
            Swal.fire({
                icon: fullyReceived ? 'info' : 'warning',
                title: fullyReceived ? 'Already received' : 'Nothing to receive',
                text: fullyReceived ? 'This purchase order is fully received.' : 'Please enter at least one quantity to receive.',
                confirmButtonColor: '#2563EB'
            });
            return;
        }

        // Confirmation dialog
        e.preventDefault();
        Swal.fire({
            title: 'Confirm Receipt?',
            text: "You are about to post inventory for " + totalReceiving + " items. This action will update your stock levels and cannot be easily undone.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Post it!',
            cancelButtonText: 'Review Once More'
        }).then((result) => {
            if (result.isConfirmed) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                form.submit();
            }
        });
    });

    // Auto-select value on focus for easier typing
    document.querySelectorAll('.qty-input').forEach(input => {
        input.addEventListener('focus', function() {
            this.select();
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
