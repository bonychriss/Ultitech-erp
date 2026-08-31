<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$poId = (int) ($_GET['po_id'] ?? 0);
if ($poId <= 0) {
    redirect('index.php');
}

// PO header
$po = null;
try {
    $stmt = $pdo->prepare("SELECT p.*, s.name as supplier_name
                           FROM stocks_purchase_orders p
                           LEFT JOIN stocks_suppliers s ON s.id = p.supplier_id
                           WHERE p.id = ? LIMIT 1");
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $po = null;
}
if (!$po) {
    flash('success_type', 'error');
    flash('success', 'Purchase Order not found.');
    redirect('index.php');
}

// Items + current stock
$items = [];
try {
    $stmtItems = $pdo->prepare("SELECT pi.id as po_item_id,
                                       pi.item_id,
                                       si.name as item_name,
                                       si.sku,
                                       COALESCE(si.stock_quantity, 0) as current_stock,
                                       COALESCE(pi.qty_ordered, 0) as qty_ordered,
                                       COALESCE(pi.qty_received, 0) as qty_received
                                FROM stocks_po_items pi
                                JOIN stocks_items si ON si.id = pi.item_id
                                WHERE pi.po_id = ?
                                ORDER BY si.name ASC");
    $stmtItems->execute([$poId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $items = [];
}

// Transactions posted for this PO
$txns = [];
try {
    $hasTxn = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_transactions'")->fetchColumn();
} catch (Throwable $e) {
    $hasTxn = false;
}
if ($hasTxn) {
    try {
        $stmtTxn = $pdo->prepare("SELECT t.*, si.name as item_name, si.sku
                                  FROM stocks_transactions t
                                  LEFT JOIN stocks_items si ON si.id = t.item_id
                                  WHERE t.reference_type = 'purchase_order'
                                    AND t.reference_id = ?
                                  ORDER BY t.transaction_date DESC, t.id DESC
                                  LIMIT 500");
        $stmtTxn->execute([$poId]);
        $txns = $stmtTxn->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $txns = [];
    }
}

$page_title = 'Receipt audit  ' . ($po['po_number'] ?? ('#' . $poId));
include '../../includes/header.php';
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .prod-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
    .prod-btn-primary { background-color: #2563EB !important; color: #fff !important; border-color: #2563EB !important; }
    .prod-btn-primary:hover { background-color: #1D4ED8 !important; border-color: #1D4ED8 !important; color: #fff !important; }
    .audit-table thead tr.audit-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .audit-table thead tr.audit-head th:not(:last-child) { border-right: 1px solid rgba(255, 255, 255, 0.08); }
</style>

<main class="main-content prod-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="domestic_receive.php?id=<?php echo (int) $poId; ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Back to receive
                </a>
                <a href="view_po.php?id=<?php echo (int) $poId; ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-file-invoice text-sm"></i> View PO
                </a>
                <?php
                $firstItemName = '';
                if (!empty($items)) {
                    $firstItemName = (string) ($items[0]['item_name'] ?? '');
                }
                ?>
                <a href="../products/index.php?search=<?php echo urlencode($firstItemName); ?>&hl=<?php echo urlencode($firstItemName); ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-warehouse text-sm"></i> Open in inventory
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-warehouse text-[#2563EB]"></i><span>Inventory receipt audit</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <span class="inline-flex items-center px-2.5 py-0.5 text-sm font-semibold rounded-full bg-gray-100 text-gray-800 border border-gray-200">
                    <?php echo htmlspecialchars((string) ($po['po_number'] ?? ('#' . $poId))); ?>
                </span>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-user-tie text-gray-400 me-1"></i><?php echo htmlspecialchars((string) ($po['supplier_name'] ?? '')); ?></span>
                <span class="text-gray-300">|</span>
                <span class="text-gray-600"><i class="fas fa-info-circle text-gray-400 me-1"></i>Verify received quantities and current stock for this PO.</span>
            </div>
        </div>

        <div class="px-4 pt-4">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-gray-100 fw-bold text-gray-900">PO items</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 audit-table">
                        <thead>
                            <tr class="audit-head">
                                <th class="ps-3 py-3">Item</th>
                                <th class="py-3 text-center">Ordered</th>
                                <th class="py-3 text-center">Received</th>
                                <th class="py-3 text-center">Current stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted">No items found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($items as $it): ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="ps-3 py-3">
                                            <div class="fw-semibold text-gray-900">
                                                <a class="text-gray-900 hover:text-[#2563EB] no-underline"
                                                   href="../products/index.php?search=<?php echo urlencode((string) ($it['item_name'] ?? '')); ?>&hl=<?php echo urlencode((string) ($it['item_name'] ?? '')); ?>">
                                                    <?php echo htmlspecialchars((string) ($it['item_name'] ?? '')); ?>
                                                </a>
                                            </div>
                                            <div class="text-xs text-gray-500">SKU: <?php echo htmlspecialchars((string) ($it['sku'] ?? '')); ?></div>
                                        </td>
                                        <td class="py-3 text-center tabular-nums"><?php echo number_format((float) ($it['qty_ordered'] ?? 0)); ?></td>
                                        <td class="py-3 text-center tabular-nums fw-bold text-emerald-700"><?php echo number_format((float) ($it['qty_received'] ?? 0)); ?></td>
                                        <td class="py-3 text-center tabular-nums fw-bold text-gray-900"><?php echo number_format((float) ($it['current_stock'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 fw-bold text-gray-900">Receipt transactions</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 audit-table">
                        <thead>
                            <tr class="audit-head">
                                <th class="ps-3 py-3">Date</th>
                                <th class="py-3">Item</th>
                                <th class="py-3 text-center">Type</th>
                                <th class="py-3 text-center">Qty</th>
                                <th class="py-3">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$hasTxn): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted">Transaction table not available on this install.</td></tr>
                            <?php elseif (empty($txns)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted">No receipt transactions found for this PO.</td></tr>
                            <?php else: ?>
                                <?php foreach ($txns as $t): ?>
                                    <tr class="border-b border-gray-100">
                                        <td class="ps-3 py-3 text-gray-700 whitespace-nowrap">
                                            <?php echo !empty($t['transaction_date']) ? date('M d, Y H:i', strtotime((string) $t['transaction_date'])) : ''; ?>
                                        </td>
                                        <td class="py-3">
                                            <div class="fw-semibold text-gray-900"><?php echo htmlspecialchars((string) ($t['item_name'] ?? 'Item')); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($t['sku'] ?? '')); ?></div>
                                        </td>
                                        <td class="py-3 text-center">
                                            <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200">
                                                <?php echo htmlspecialchars((string) ($t['type'] ?? 'in')); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-center tabular-nums fw-bold text-gray-900"><?php echo number_format((float) ($t['quantity'] ?? 0)); ?></td>
                                        <td class="py-3 text-gray-700">
                                            <div class="text-sm"><?php echo htmlspecialchars((string) ($t['external_reference'] ?? '')); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string) ($t['notes'] ?? '')); ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>

