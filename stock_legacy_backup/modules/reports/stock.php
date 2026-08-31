<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$page_title = 'Stock Report';
include '../../includes/header.php';

$hasProductCurrency = false;
try {
    $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    $hasProductCurrency = in_array('currency', $productCols, true);
} catch (Throwable $e) {
    $hasProductCurrency = false;
}

$defaultCurrency = getCompanySettings($pdo)['currency'] ?? 'USD';

// Fetch Stock Data (currency column is optional across deployments)
$baseSql = 'SELECT p.product_code, p.name, c.name as category, s.quantity, p.cost_price as buying_price, %s (s.quantity * p.cost_price) as total_value, s.location 
        FROM products p 
        LEFT JOIN stock s ON p.id = s.product_id 
        LEFT JOIN stocks_categories c ON p.category_id = c.id 
        ORDER BY p.name ASC';
if ($hasProductCurrency) {
    $sql = sprintf($baseSql, 'p.currency,');
    $stocks = $pdo->query($sql)->fetchAll();
} else {
    $sql = sprintf($baseSql, ':def_currency AS currency,');
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['def_currency' => $defaultCurrency]);
    $stocks = $stmt->fetchAll();
}

$total_usd = 0;
$total_tzs = 0;
$qtyPositive = 0;
foreach ($stocks as $stock) {
    if (($stock['currency'] ?? 'USD') == 'TZS') {
        $total_tzs += (float) ($stock['total_value'] ?? 0);
    } else {
        $total_usd += (float) ($stock['total_value'] ?? 0);
    }
    if ((float) ($stock['quantity'] ?? 0) > 0) {
        $qtyPositive++;
    }
}
$rowCount = count($stocks);
$symUsd = getCurrencySymbol('USD');
$symTzs = getCurrencySymbol('TZS');
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .mov-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .mov-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .mov-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .stockrep-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
        position: relative;
    }
    .stockrep-table-wrapper::-webkit-scrollbar {
        height: 8px;
    }
    .stockrep-table-wrapper::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    .stockrep-table-wrapper::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .stockrep-table {
        table-layout: fixed;
        width: 100%;
    }
    .stockrep-table thead tr.stockrep-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .stockrep-table thead tr.stockrep-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .stockrep-stat-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
</style>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="../../index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="Stock module home">
                    <i class="fas fa-arrow-left text-sm"></i> Stock
                </a>
                <a href="export_stock.php" class="btn mov-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0 no-underline" title="Download CSV">
                    <i class="fas fa-file-csv text-sm"></i> Export CSV
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-warehouse text-[#2563EB]"></i><span>Stock level report</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-chart-bar text-gray-400 me-1"></i>On-hand quantities and inventory value by product.</span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="font-semibold text-gray-800 tabular-nums"><?php echo (int) $rowCount; ?> product<?php echo $rowCount === 1 ? '' : 's'; ?></span>
                <?php if ($rowCount > 0): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-sm font-semibold bg-slate-100 text-slate-800 border border-slate-200">
                        <i class="fas fa-boxes text-xs"></i> With stock &gt; 0: <?php echo (int) $qtyPositive; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="stockrep-stat-card p-4 h-100 border-s-4 border-s-blue-600">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Non-TZS value (USD &amp; other)</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo htmlspecialchars($symUsd); ?><?php echo number_format($total_usd, 2); ?></div>
                        <p class="text-sm text-gray-500 mb-0 mt-2">Sum of line totals where currency is not TZS.</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stockrep-stat-card p-4 h-100 border-s-4 border-s-emerald-600">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">TZS value</div>
                        <div class="text-2xl font-bold text-gray-900 tabular-nums"><?php echo htmlspecialchars($symTzs); ?><?php echo number_format($total_tzs, 2); ?></div>
                        <p class="text-sm text-gray-500 mb-0 mt-2">Sum of line totals priced in Tanzanian shillings.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-4 pb-4">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-4 py-2 border-b border-gray-100 flex flex-wrap items-center gap-2 bg-gray-50/50">
                    <label for="stockReportSearchInput" class="visually-hidden">Search table</label>
                    <div class="relative flex-1 min-w-[200px] max-w-md">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                        <input type="search" id="stockReportSearchInput" class="w-full pl-9 pr-3 py-2 text-base bg-white border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB]" placeholder="Search code, name, category, locationâ€¦" autocomplete="off">
                    </div>
                </div>
                <div class="stockrep-table-wrapper">
                    <table class="table table-hover align-middle mb-0 stockrep-table border-0" id="stockReportTable" style="font-size: 1rem;">
                        <thead>
                            <tr class="stockrep-table-head">
                                <th class="ps-3 py-3" style="width: 10%; min-width: 88px;">Code</th>
                                <th class="py-3" style="width: 22%; min-width: 140px;">Product</th>
                                <th class="py-3" style="width: 14%; min-width: 100px;">Category</th>
                                <th class="py-3" style="width: 14%; min-width: 100px;">Location</th>
                                <th class="text-center py-3" style="width: 9%; min-width: 72px;">Qty</th>
                                <th class="text-end py-3" style="width: 15%; min-width: 100px;">Unit cost</th>
                                <th class="text-end py-3 pe-3" style="width: 16%; min-width: 110px;">Line value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stocks)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-16 px-4 border-0">
                                        <i class="fas fa-inbox text-5xl text-gray-300 mb-3 d-block"></i>
                                        <p class="text-gray-800 text-lg font-semibold mb-1">No products</p>
                                        <p class="text-gray-500 text-base mb-0">Add products in the catalog to see stock levels here.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($stocks as $stock): ?>
                                    <?php
                                    $cur = $stock['currency'] ?? 'USD';
                                    $sym = getCurrencySymbol($cur);
                                    $code = (string) ($stock['product_code'] ?? '');
                                    $name = (string) ($stock['name'] ?? '');
                                    $cat = (string) ($stock['category'] ?? 'N/A');
                                    $loc = (string) ($stock['location'] ?? '');
                                    $qty = $stock['quantity'] ?? null;
                                    $qtyDisp = $qty === null || $qty === '' ? 'â€”' : number_format((float) $qty, 0);
                                    $searchBlob = strtolower($code . ' ' . $name . ' ' . $cat . ' ' . $loc);
                                    $bp = (float) ($stock['buying_price'] ?? 0);
                                    $tv = (float) ($stock['total_value'] ?? 0);
                                    ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50/80 stockrep-data-row" data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                        <td class="ps-3 py-3 text-gray-800 font-medium text-base"><?php echo htmlspecialchars($code); ?></td>
                                        <td class="py-3 fw-semibold text-gray-900 text-base"><?php echo htmlspecialchars($name); ?></td>
                                        <td class="py-3 text-gray-600 text-base"><?php echo htmlspecialchars($cat); ?></td>
                                        <td class="py-3 text-gray-600 text-base"><?php echo htmlspecialchars($loc !== '' ? $loc : 'â€”'); ?></td>
                                        <td class="text-center py-3 tabular-nums fw-semibold text-gray-900 text-base"><?php echo $qtyDisp; ?></td>
                                        <td class="text-end py-3 tabular-nums text-gray-800 text-base"><?php echo htmlspecialchars($sym) . number_format($bp, 2); ?></td>
                                        <td class="text-end py-3 pe-3 fw-bold tabular-nums text-gray-900 text-base"><?php echo htmlspecialchars($sym) . number_format($tv, 2); ?></td>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('stockReportSearchInput');
    var rows = document.querySelectorAll('#stockReportTable tbody tr.stockrep-data-row');
    if (!input || !rows.length) {
        return;
    }
    input.addEventListener('input', function () {
        var q = (input.value || '').toLowerCase().trim();
        rows.forEach(function (row) {
            var hay = (row.getAttribute('data-search') || '').toLowerCase();
            row.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
