<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$page_title = 'Stock Movements';
include '../../includes/header.php';

// Filter Parameters
$product_id = $_GET['product_id'] ?? '';
$type = $_GET['type'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Build Query
$query = "SELECT sm.*, p.name as product_name, p.product_code 
          FROM stock_movements sm 
          JOIN products p ON sm.product_id = p.id 
          WHERE 1=1";
$params = [];

if ($product_id) {
    $query .= " AND sm.product_id = ?";
    $params[] = $product_id;
}
if ($type) {
    $query .= " AND sm.movement_type = ?";
    $params[] = $type;
}
if ($start_date) {
    $query .= " AND DATE(sm.created_at) >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $query .= " AND DATE(sm.created_at) <= ?";
    $params[] = $end_date;
}

$query .= " ORDER BY sm.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Fetch Products for Filter
$products = $pdo->query("SELECT id, name, product_code FROM products ORDER BY name")->fetchAll();

$movCount = count($movements);
$cntIn = 0;
$cntOut = 0;
$cntAdj = 0;
foreach ($movements as $m) {
    $mt = $m['movement_type'] ?? '';
    if ($mt === 'in') {
        $cntIn++;
    } elseif ($mt === 'out') {
        $cntOut++;
    } else {
        $cntAdj++;
    }
}
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
    .movements-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
        position: relative;
    }
    .movements-table-wrapper::-webkit-scrollbar {
        height: 8px;
    }
    .movements-table-wrapper::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    .movements-table-wrapper::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }
    .movements-table {
        table-layout: fixed;
        width: 100%;
    }
    .movements-table th,
    .movements-table td {
        overflow: hidden;
        text-overflow: ellipsis;
        word-wrap: break-word;
    }
    .movements-table thead tr.mov-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .movements-table thead tr.mov-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .mov-filter-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .mov-stat-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
    }
</style>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="../../index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="Stock module home">
                    <i class="fas fa-arrow-left text-sm"></i> Stock
                </a>
                <a href="adjust.php" class="btn mov-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0 no-underline">
                    <i class="fas fa-sliders-h text-sm"></i> New adjustment
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-exchange-alt text-[#2563EB]"></i><span>Stock movements</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 gap-y-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-history text-gray-400 me-1"></i>Audit log of inventory changes for the selected period.</span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="font-semibold text-gray-800 tabular-nums"><?php echo (int) $movCount; ?> row<?php echo $movCount === 1 ? '' : 's'; ?></span>
                <?php if ($movCount > 0): ?>
                    <span class="mov-stat-pill bg-emerald-50 text-emerald-800 border border-emerald-200"><i class="fas fa-arrow-down text-xs"></i> In <?php echo (int) $cntIn; ?></span>
                    <span class="mov-stat-pill bg-rose-50 text-rose-800 border border-rose-200"><i class="fas fa-arrow-up text-xs"></i> Out <?php echo (int) $cntOut; ?></span>
                    <span class="mov-stat-pill bg-amber-50 text-amber-900 border border-amber-200"><i class="fas fa-wrench text-xs"></i> Adj <?php echo (int) $cntAdj; ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <div class="mov-filter-card p-4">
                <form method="GET" class="row g-3 align-items-end mb-0" id="movementsFilterForm">
                    <div class="col-12 col-lg-4">
                        <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1" style="letter-spacing:0.04em;">Product</label>
                        <select name="product_id" class="form-select border-gray-200 rounded-md">
                            <option value="">All products</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $product_id == $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-6 col-lg-2">
                        <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1" style="letter-spacing:0.04em;">Type</label>
                        <select name="type" class="form-select border-gray-200 rounded-md">
                            <option value="">All types</option>
                            <option value="in" <?= $type == 'in' ? 'selected' : '' ?>>In (received)</option>
                            <option value="out" <?= $type == 'out' ? 'selected' : '' ?>>Out (sold/sent)</option>
                            <option value="adjustment" <?= $type == 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1" style="letter-spacing:0.04em;">From</label>
                        <input type="date" name="start_date" class="form-control border-gray-200 rounded-md" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1" style="letter-spacing:0.04em;">To</label>
                        <input type="date" name="end_date" class="form-control border-gray-200 rounded-md" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="col-12 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn mov-btn-primary flex-grow-1 rounded-md fw-semibold border-0">
                            <i class="fas fa-filter me-1"></i> Apply
                        </button>
                        <a href="movements.php" class="btn btn-outline-secondary rounded-md fw-semibold" title="Clear filters">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="px-4 pb-4">
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div class="px-4 py-2 border-b border-gray-100 flex flex-wrap items-center gap-2 bg-gray-50/50">
                    <label for="movementSearchInput" class="visually-hidden">Search in table</label>
                    <div class="relative flex-1 min-w-[200px] max-w-md">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                        <input type="search" id="movementSearchInput" class="w-full pl-9 pr-3 py-2 text-base bg-white border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB]" placeholder="Search product, reference, notesâ€¦" autocomplete="off">
                    </div>
                </div>
                <div class="movements-table-wrapper">
                    <table class="table table-hover align-middle mb-0 movements-table border-0" id="movementsTable" style="font-size: 1rem;">
                        <thead>
                            <tr class="mov-table-head">
                                <th class="ps-3 py-3" style="width: 11%; min-width: 100px;">Date</th>
                                <th class="py-3" style="width: 22%; min-width: 160px;">Product</th>
                                <th class="text-center py-3" style="width: 9%; min-width: 80px;">Type</th>
                                <th class="text-center py-3" style="width: 9%; min-width: 72px;">Qty</th>
                                <th class="py-3" style="width: 18%; min-width: 120px;">Reference</th>
                                <th class="pe-3 py-3" style="width: 31%; min-width: 140px;">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($movements)): ?>
                                <tr class="no-filter">
                                    <td colspan="6" class="text-center py-16 px-4 border-0">
                                        <i class="fas fa-clipboard-list text-5xl text-gray-300 mb-3 d-block"></i>
                                        <p class="text-gray-800 text-lg font-semibold mb-1">No movements found</p>
                                        <p class="text-gray-500 text-base mb-0">Try widening the date range or clearing filters.</p>
                                        <a href="adjust.php" class="btn mov-btn-primary mt-4 rounded-md border-0">Record an adjustment</a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movements as $mov): ?>
                                    <?php
                                    $refTypeRaw = trim((string) ($mov['reference_type'] ?? ''));
                                    $refIdRaw = trim((string) ($mov['reference_id'] ?? ''));
                                    $refTypeLabel = $refTypeRaw !== '' ? ucfirst($refTypeRaw) : 'â€”';
                                    $searchBlob = strtolower(
                                        ($mov['product_name'] ?? '') . ' ' .
                                        ($mov['product_code'] ?? '') . ' ' .
                                        $refTypeRaw . ' ' .
                                        $refIdRaw . ' ' .
                                        ($mov['notes'] ?? '')
                                    );
                                    ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50/80 movements-data-row" data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                                        <td class="ps-3 py-3 text-gray-700 text-base whitespace-nowrap"><?= date('M d, Y H:i', strtotime($mov['created_at'])) ?></td>
                                        <td class="py-3">
                                            <div class="fw-semibold text-gray-900 text-base"><?= htmlspecialchars($mov['product_name']) ?></div>
                                            <small class="text-gray-500"><?= htmlspecialchars($mov['product_code']) ?></small>
                                        </td>
                                        <td class="text-center py-3">
                                            <?php if ($mov['movement_type'] == 'in'): ?>
                                                <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200">In</span>
                                            <?php elseif ($mov['movement_type'] == 'out'): ?>
                                                <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-rose-50 text-rose-800 border border-rose-200">Out</span>
                                            <?php else: ?>
                                                <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-amber-50 text-amber-900 border border-amber-200">Adjust</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center py-3 fw-bold text-base tabular-nums <?= $mov['movement_type'] == 'in' ? 'text-emerald-700' : ($mov['movement_type'] == 'out' ? 'text-rose-700' : 'text-gray-800') ?>">
                                            <?= $mov['movement_type'] == 'out' ? '-' : '+' ?><?= (int) $mov['quantity'] ?>
                                        </td>
                                        <td class="py-3">
                                            <span class="inline-flex flex-wrap items-center gap-1 px-2 py-1 rounded-md bg-gray-50 border border-gray-200 text-gray-800 text-sm font-medium">
                                                <?= htmlspecialchars($refTypeLabel) ?>
                                                <?php if ($refIdRaw !== ''): ?>
                                                    <span class="text-gray-500 font-normal">#<?= htmlspecialchars($refIdRaw) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="pe-3 py-3 text-gray-600 small" style="white-space: normal;"><?= htmlspecialchars((string) ($mov['notes'] ?? '')) ?></td>
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
    var input = document.getElementById('movementSearchInput');
    var rows = document.querySelectorAll('#movementsTable tbody tr.movements-data-row');
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
