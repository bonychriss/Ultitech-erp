<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$hasCategoriesTable = false;
$hasStocksCategoriesTable = false;
$useCategoriesTable = false;
try {
    $hasCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'categories'")->fetchColumn();
    $hasStocksCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_categories'")->fetchColumn();
    $catsCount = $hasCategoriesTable ? (int)($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() ?: 0) : 0;
    $stocksCatsCount = $hasStocksCategoriesTable ? (int)($pdo->query("SELECT COUNT(*) FROM stocks_categories")->fetchColumn() ?: 0) : 0;
    // Prefer the table that contains the real categories (live uses `categories`).
    $useCategoriesTable = $hasCategoriesTable && ($catsCount >= $stocksCatsCount);
} catch (Throwable $e) {
    $hasCategoriesTable = false;
    $hasStocksCategoriesTable = false;
    $useCategoriesTable = false;
}

$whereClause = 'WHERE 1=1';
$params = [];

$hasProductsSupplierId = false;
try {
    $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasProductsSupplierId = in_array('supplier_id', $cols, true);
} catch (Throwable $e) {
    $hasProductsSupplierId = false;
}

if (isset($_GET['search']) && $_GET['search'] !== '') {
    $whereClause .= ' AND (p.name LIKE ? OR p.product_code LIKE ?)';
    $params[] = '%' . $_GET['search'] . '%';
    $params[] = '%' . $_GET['search'] . '%';
}

if (isset($_GET['category']) && $_GET['category'] !== '') {
    $whereClause .= ' AND p.category_id = ?';
    $params[] = $_GET['category'];
}

if ($hasProductsSupplierId && isset($_GET['supplier']) && $_GET['supplier'] !== '') {
    $whereClause .= ' AND p.supplier_id = ?';
    $params[] = $_GET['supplier'];
}

$sql = 'SELECT p.*, c.name as category_name, ' . ($hasProductsSupplierId ? 's.name' : 'NULL') . ' as supplier_name, st.quantity as gross_stock, st.location,
        (
            SELECT SUM(soi.quantity)
            FROM sales_order_items soi
            JOIN sales_orders so ON soi.order_id = so.id
            WHERE soi.product_id = p.id
            AND so.status IN ("confirmed", "invoiced", "paid")
            AND so.status NOT IN ("shipped", "delivered", "cancelled")
            AND (so.shipped_at IS NULL OR so.shipped_at = "0000-00-00 00:00:00")
        ) as pending_demand
        FROM products p
        LEFT JOIN ' . ($useCategoriesTable ? 'categories' : 'stocks_categories') . ' c ON p.category_id = c.id
        ' . ($hasProductsSupplierId ? 'LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id' : '') . '
        LEFT JOIN stock st ON p.id = st.product_id
        ' . $whereClause . '
        ORDER BY p.name ASC';

$products = [];
$dbError = '';
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$categories = [];
try {
    if ($useCategoriesTable) {
        $categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    } else {
        $categories = $pdo->query('SELECT * FROM stocks_categories ORDER BY name')->fetchAll();
    }
} catch (Throwable $e) {
    $categories = [];
}

$suppliers = [];
if ($hasProductsSupplierId) {
    try {
        $suppliers = $pdo->query('SELECT * FROM stocks_suppliers ORDER BY name')->fetchAll();
    } catch (Throwable $e) {
        $suppliers = [];
    }
}

$page_title = 'Products';
include '../../includes/header.php';
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .prod-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .prod-topbar {
        flex-wrap: nowrap !important;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .prod-topbar::-webkit-scrollbar {
        height: 6px;
    }
    .prod-topbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .prod-topbar::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.6);
        border-radius: 999px;
    }
    .prod-topbar > * {
        flex: 0 0 auto;
    }
    .prod-icon-btn {
        width: 42px;
        height: 42px;
        padding: 0 !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: transparent !important;
        border: 0 !important;
        box-shadow: none !important;
    }
    .prod-icon-btn i {
        font-size: 16px;
        line-height: 1;
    }
    .prod-icon-primary {
        color: #2563EB !important;
    }
    .prod-icon-muted {
        color: #374151 !important;
    }
    .prod-icon-muted:hover {
        color: #2563EB !important;
    }
    .prod-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .prod-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .products-table thead tr.prod-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .products-table thead tr.prod-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .products-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
    }
    .clickable-row {
        cursor: pointer;
        transition: background 0.15s;
    }
    .clickable-row:hover {
        background-color: #f9fafb !important;
    }
    div.dataTables_wrapper {
        padding: 0 1rem 1rem;
        background: #fff;
    }
    div.dataTables_wrapper .dataTables_length {
        padding-top: 0.75rem;
        margin-bottom: 0.25rem;
    }
    div.dataTables_wrapper .dataTables_length select {
        border-radius: 0.375rem;
        border: 1px solid #e5e7eb;
        padding: 0.35rem 2rem 0.35rem 0.5rem;
        font-size: 0.95rem;
        background-color: #fff;
    }
    div.dataTables_wrapper .dataTables_info,
    div.dataTables_wrapper .dataTables_paginate {
        padding-top: 0.75rem;
        font-size: 0.95rem;
        color: #6b7280;
    }
    .po-highlight {
        outline: 2px solid rgba(37, 99, 235, 0.55);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        background: linear-gradient(90deg, rgba(37, 99, 235, 0.10), rgba(255, 255, 255, 0.0) 55%);
        animation: poPulse 1.2s ease-in-out 0s 3;
    }
    @keyframes poPulse {
        0% { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10); }
        50% { box-shadow: 0 0 0 6px rgba(37, 99, 235, 0.18); }
        100% { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10); }
    }
    @media (max-width: 768px) {
        .products-table {
            min-width: 900px;
        }
        .fab {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #2563EB;
            color: white;
            border: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            z-index: 1000;
            transition: all 0.3s;
            text-decoration: none !important;
        }
        .fab:hover {
            background: #1D4ED8;
            transform: scale(1.05);
            color: white;
        }
    }
</style>

<main class="main-content prod-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex items-center gap-3 border-b border-gray-100 prod-topbar">
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Products</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="add.php"
                   class="btn prod-icon-btn prod-icon-primary"
                   title="Add product"
                   aria-label="Add product">
                    <i class="fas fa-plus" aria-hidden="true"></i>
                </a>
                <a href="categories.php"
                   class="text-base font-medium prod-icon-btn prod-icon-muted inline-flex items-center justify-content-center no-underline"
                   title="Categories"
                   aria-label="Categories">
                    <i class="fas fa-tags" aria-hidden="true"></i>
                </a>
                <a href="../purchases/index.php"
                   class="text-base font-medium prod-icon-btn prod-icon-muted inline-flex items-center justify-content-center no-underline"
                   title="Purchases"
                   aria-label="Purchases">
                    <i class="fas fa-shopping-cart" aria-hidden="true"></i>
                </a>
                <button type="button"
                        class="btn btn-sm prod-icon-btn prod-icon-muted d-md-none"
                        id="mobileProductsFilterToggleBtn"
                        aria-controls="productsFilters"
                        aria-expanded="false"
                        title="Show filters"
                        aria-label="Show filters">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                </button>
            </div>
            <div class="d-none d-md-block" id="productsFilters">
                <form method="get" class="px-4 py-3 bg-gray-50/80 border-b border-gray-100">
                    <div class="flex flex-col lg:flex-row flex-wrap gap-3 items-stretch lg:items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label class="visually-hidden" for="prodSearch">Search products</label>
                            <input type="text" id="prodSearch" name="search" class="form-control border-gray-200 rounded-md"
                                   placeholder="Search by name or codeâ€¦" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                        <div class="min-w-[160px]">
                            <label class="visually-hidden" for="prodCategory">Category</label>
                            <select name="category" id="prodCategory" class="form-select border-gray-200 rounded-md">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo (string) ($cat['id'] ?? '') === (string) ($_GET['category'] ?? '') ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="min-w-[160px]">
                            <?php if ($hasProductsSupplierId): ?>
                                <label class="visually-hidden" for="prodSupplier">Supplier</label>
                                <select name="supplier" id="prodSupplier" class="form-select border-gray-200 rounded-md">
                                    <option value="">All suppliers</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo (int) $sup['id']; ?>" <?php echo (string) ($sup['id'] ?? '') === (string) ($_GET['supplier'] ?? '') ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span class="text-sm text-gray-500 d-block py-2">Supplier filter unavailable</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex gap-2 flex-shrink-0">
                            <button type="submit" class="btn btn-outline-secondary border-gray-300 rounded-md px-3">
                                <i class="fas fa-search me-1"></i> Apply
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary border-gray-300 rounded-md px-3">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($dbError !== ''): ?>
            <div class="px-4 pt-4">
                <div class="alert alert-danger mb-0">Database error: <?php echo htmlspecialchars($dbError); ?></div>
            </div>
        <?php endif; ?>

        <div class="px-4 pt-4">
            <?php flash('success'); ?>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="products-table-wrapper">
                <table class="table table-hover align-middle mb-0 products-table w-100">
                    <thead>
                        <tr class="prod-table-head">
                            <th class="ps-3 py-3" style="width: 56px;">Image</th>
                            <th class="py-3">Code</th>
                            <th class="py-3">Name</th>
                            <th class="py-3">Category</th>
                            <th class="text-center py-3">Stock</th>
                            <th class="py-3"><?php echo in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true) ? 'Cost / price' : 'Price'; ?></th>
                            <th class="py-3">Supplier</th>
                            <th class="text-center py-3 pe-3"><i class="fas fa-sliders-h text-white/70" title="Actions"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product):
                            $rowImg = (string) ($product['image'] ?? $product['main_image'] ?? '');
                            $thumbRelUrl = resolveProductImageUrl($product['id'], $rowImg, 'thumbnail');
                            $thumbExists = ($thumbRelUrl !== "/stock/assets/images/no-image.png");
                            ?>
                            <tr class="clickable-row border-b border-gray-100"
                                data-href="view.php?id=<?php echo (int) $product['id']; ?>"
                                data-product-id="<?php echo (int) $product['id']; ?>"
                                data-product-code="<?php echo htmlspecialchars((string) ($product['product_code'] ?? ''), ENT_QUOTES); ?>"
                                data-product-name="<?php echo htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES); ?>">
                                <td class="ps-3 py-3" data-label="Image">
                                    <?php if ($thumbExists && $thumbRelUrl !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($thumbRelUrl); ?>?t=<?php echo time(); ?>"
                                             width="40" height="40" style="object-fit: cover;" class="rounded border" alt="">
                                    <?php else: ?>
                                        <div class="bg-gray-50 border rounded d-flex align-items-center justify-content-center text-gray-400" style="width: 40px; height: 40px;">
                                            <i class="fas fa-camera small"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-base text-gray-800" data-label="Code"><?php echo htmlspecialchars($product['product_code'] ?? ''); ?></td>
                                <td class="py-3" data-label="Name">
                                    <div class="fw-bold text-gray-900 text-base"><?php echo htmlspecialchars($product['name'] ?? ''); ?></div>
                                    <?php 
                                    $gross_low = (float)($product['gross_stock'] ?? 0);
                                    $pending_low = (float)($product['pending_demand'] ?? 0);
                                    $available_low = $gross_low - $pending_low;
                                    if ($available_low <= ($product['reorder_level'] ?? 0)): 
                                    ?>
                                        <span class="inline-block mt-1 px-2 py-0.5 text-xs font-semibold rounded-full bg-[#DC3545] text-white">Low stock</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-base text-gray-700" data-label="Category"><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                                <td class="text-center py-3 text-base tabular-nums" data-label="Stock">
                                    <?php
                                    $gross = (float)($product['gross_stock'] ?? 0);
                                    $pending = (float)($product['pending_demand'] ?? 0);
                                    $available = $gross - $pending;
                                    $reorder = (float)($product['reorder_level'] ?? 0);
                                    
                                    $cls = ($available <= $reorder) ? 'text-danger fw-bold' : 'text-success fw-bold';
                                    
                                    // Hidden breakdown in title for hover access, but hidden from UI
                                    $tooltip = "Physical: {$gross} | Pending: {$pending}";
                                    echo '<div class="' . $cls . ' mb-0" style="font-size: 1.1rem;" title="' . $tooltip . '">' . htmlspecialchars((string) $available) . '</div>';
                                    ?>
                                </td>
                                <?php
                                $currency = $product['currency'] ?? 'USD';
                                $symbol = ($currency === 'TZS') ? 'TSh ' : '$';
                                ?>
                                <td class="py-3 text-base" data-label="Price">
                                    <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true)): ?>
                                        <div class="text-gray-500 small">Cost: <?php echo $symbol . number_format((float) ($product['cost_price'] ?? 0), 2); ?></div>
                                        <div class="fw-bold text-gray-900">Price: <?php echo $symbol . number_format((float) ($product['unit_price'] ?? 0), 2); ?></div>
                                    <?php else: ?>
                                        <?php echo $symbol . number_format((float) ($product['unit_price'] ?? 0), 2); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-base text-gray-700" data-label="Supplier"><?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></td>
                                <td class="text-center py-3 pe-3" data-label="Actions" onclick="event.stopPropagation()">
                                    <div class="d-flex justify-content-center gap-3">
                                        <a href="view.php?id=<?php echo (int) $product['id']; ?>" class="text-gray-500 hover:text-[#2563EB]" title="View"><i class="fas fa-eye"></i></a>
                                        <a href="edit.php?id=<?php echo (int) $product['id']; ?>" class="text-gray-500 hover:text-[#2563EB]" title="Edit"><i class="fas fa-edit"></i></a>
                                        <?php if (hasRole('admin')): ?>
                                            <a href="reset_stock.php?id=<?php echo (int) $product['id']; ?>" class="text-warning" title="Reset stock"
                                               onclick="event.stopPropagation(); return confirm('Reset stock count to 0?');"><i class="fas fa-undo"></i></a>
                                        <?php endif; ?>
                                        <a href="delete.php?id=<?php echo (int) $product['id']; ?>" class="text-danger" title="Delete"
                                           onclick="event.stopPropagation(); return confirm('Delete this product? Stock history will be removed.');"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products) && $dbError === ''): ?>
                            <tr>
                                <td colspan="8" class="text-center py-16 px-4">
                                    <i class="fas fa-box-open text-5xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-700 text-lg font-medium mb-1">No products found</p>
                                    <p class="text-gray-500 text-base">Try different search or filters, or add a product.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <a href="add.php" class="fab d-md-none" title="Add product"><i class="fas fa-plus"></i></a>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    (function () {
        var panel = document.getElementById('productsFilters');
        var btn = document.getElementById('mobileProductsFilterToggleBtn');
        if (!panel || !btn) return;

        var mq = window.matchMedia('(min-width: 768px)');
        var storageKey = 'staff:stock:filters:' + location.pathname + ':products';

        function setBtnExpanded(isExpanded) {
            btn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            var nextTitle = isExpanded ? 'Hide filters' : 'Show filters';
            btn.setAttribute('title', nextTitle);
            btn.setAttribute('aria-label', nextTitle);
        }

        function ensureDesktopOpen() {
            if (!mq.matches) return;
            panel.classList.remove('d-none');
            setBtnExpanded(true);
        }

        function applyInitialState() {
            if (mq.matches) {
                ensureDesktopOpen();
                return;
            }
            var saved = null;
            try { saved = localStorage.getItem(storageKey); } catch (e) {}
            var open = saved === 'open';
            panel.classList.toggle('d-none', !open);
            setBtnExpanded(open);
        }

        btn.addEventListener('click', function () {
            if (mq.matches) return;
            var isHidden = panel.classList.contains('d-none');
            var open = isHidden;
            panel.classList.toggle('d-none', !open);
            setBtnExpanded(open);
            try { localStorage.setItem(storageKey, open ? 'open' : 'closed'); } catch (e) {}
        });

        mq.addEventListener('change', function () {
            applyInitialState();
        });

        applyInitialState();
    })();

    var params = new URLSearchParams(window.location.search || '');
    var hl = (params.get('hl') || '').trim();
    var hlId = (params.get('hl_id') || '').trim();

    function highlightRows() {
        if (!hl && !hlId) return;
        var rows = document.querySelectorAll('.products-table tbody tr.clickable-row');
        if (!rows || !rows.length) return;
        var first = null;
        rows.forEach(function(row) {
            row.classList.remove('po-highlight');
            var pid = (row.getAttribute('data-product-id') || '').trim();
            var code = (row.getAttribute('data-product-code') || '').trim().toLowerCase();
            var name = (row.getAttribute('data-product-name') || '').trim().toLowerCase();
            var ok = false;
            if (hlId && pid === hlId) ok = true;
            if (hl) {
                var q = hl.toLowerCase();
                if (code === q || name === q || name.indexOf(q) !== -1 || code.indexOf(q) !== -1) ok = true;
            }
            if (ok) {
                row.classList.add('po-highlight');
                if (!first) first = row;
            }
        });
        if (first) {
            try { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { /* ignore */ }
        }
    }

    var wrap = document.querySelector('.products-table-wrapper');
    if (wrap) {
        wrap.addEventListener('click', function(e) {
            var row = e.target.closest('.clickable-row');
            if (!row || !row.dataset.href) {
                return;
            }
            if (e.target.closest('a') || e.target.closest('button') || e.target.closest('.dropdown')) {
                return;
            }
            window.location.href = row.dataset.href;
        });
    }

    if (typeof jQuery === 'undefined' || !jQuery.fn.DataTable) {
        highlightRows();
        return;
    }
    <?php if (empty($products)): ?>
    return;
    <?php endif; ?>
    var $t = jQuery('.products-table');
    if (!$t.length) {
        return;
    }
    if (jQuery.fn.DataTable.isDataTable($t)) {
        $t.DataTable().destroy();
    }
    var dt = $t.DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        stripeClasses: [],
        order: [[2, 'asc']],
        autoWidth: false,
        dom: 'lrtip',
        columnDefs: [
            { orderable: false, targets: [0, 7] }
        ],
        language: {
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'No entries to show',
            infoFiltered: '(filtered from _MAX_ total entries)'
        }
    });

    if (hl && !params.get('search')) {
        var searchEl = document.getElementById('prodSearch');
        if (searchEl && !searchEl.value) searchEl.value = hl;
    }
    if (hl) {
        dt.search(hl).draw();
    }
    dt.on('draw', function() {
        highlightRows();
    });
    highlightRows();
});
</script>

<?php include '../../includes/footer.php'; ?>
