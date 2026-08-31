<?php
require_once '../includes/functions.php';
require_once __DIR__ . '/dispatch-helpers.php';
requireLogin();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_from VARCHAR(255) NOT NULL,
        route_to VARCHAR(255) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        price_currency VARCHAR(3) NOT NULL DEFAULT 'TZS',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Keep page resilient if table creation is blocked.
}
ensure_dispatch_routes_price_currency($pdo);

$routes = [];
try {
    $stmt = $pdo->query("
        SELECT id, route_from, route_to, price, price_currency, created_at
        FROM dispatch_routes
        WHERE UPPER(TRIM(route_to)) <> 'MOROCO'
          AND NOT (
            UPPER(TRIM(route_from)) = 'WST'
            AND REPLACE(REPLACE(REPLACE(UPPER(TRIM(route_to)), ',', ''), '.', ''), ' ', '') IN ('MWANDEGEBARESA','KIVUKONI','TARMAL','KKOOJANGWANI')
          )
        ORDER BY created_at DESC
    ");
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $routes = [];
}

$totalRoutes = count($routes);
$totalPrice = 0.0;
$highestPrice = 0.0;
$highestRoute = '-';
$todayAdded = 0;
$todayDate = date('Y-m-d');
$fromOptions = [];
$toOptions = [];
$rowsPayload = [];

foreach ($routes as $idx => $r) {
    $from = trim((string) ($r['route_from'] ?? ''));
    $to = trim((string) ($r['route_to'] ?? ''));
    $price = (float) ($r['price'] ?? 0);
    $currency = normalize_dispatch_route_currency((string) ($r['price_currency'] ?? 'TZS'));
    $createdAt = (string) ($r['created_at'] ?? '');
    $createdDate = $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : '';
    $createdDisplay = $createdAt !== '' ? date('M j, Y g:i A', strtotime($createdAt)) : '-';

    $totalPrice += $price;
    if ($price > $highestPrice) {
        $highestPrice = $price;
        $highestRoute = ($to !== '' ? $to : '-');
    }
    if ($createdDate === $todayDate) {
        $todayAdded++;
    }
    if ($from !== '') {
        $fromOptions[$from] = true;
    }
    if ($to !== '') {
        $toOptions[$to] = true;
    }

    $rowsPayload[] = [
        'serial' => $idx + 1,
        'id' => (int) ($r['id'] ?? 0),
        'from' => $from,
        'to' => $to,
        'price' => $price,
        'price_display' => dispatch_route_format_price_display($price, $currency),
        'created_by' => 'Admin',
        'created_at' => $createdAt,
        'created_date' => $createdDate,
        'created_display' => $createdDisplay,
        'status' => 'Active',
    ];
}

$averagePrice = $totalRoutes > 0 ? $totalPrice / $totalRoutes : 0;
$fromList = array_keys($fromOptions);
$toList = array_keys($toOptions);
sort($fromList);
sort($toList);

$page_title = 'Saved routes';
$modQ = 'module=dispatch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; font-weight: 300; }
        .main-content { background: #f8fafc; color: #000; }
        .toolbar-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .top-search-wrap { display: flex; justify-content: center; margin: 0.25rem 0 0.9rem; }
        .btn-create-route { background: #7c3aed !important; border-color: #7c3aed !important; color: #fff !important; font-weight: 600; }
        .btn-create-route:hover { background: #6d28d9 !important; border-color: #6d28d9 !important; }
        .search-input { min-width: 200px; width: min(320px, 100%); }
        .kpi-card { display: flex; align-items: center; gap: 0.75rem; }
        .kpi-icon {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.95rem;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            flex-shrink: 0;
        }
        .kpi-icon-purple { background: linear-gradient(145deg, #8b5cf6, #6d28d9); }
        .kpi-icon-blue { background: linear-gradient(145deg, #3b82f6, #1d4ed8); }
        .kpi-icon-green { background: linear-gradient(145deg, #22c55e, #15803d); }
        .kpi-icon-orange { background: linear-gradient(145deg, #fb923c, #ea580c); }
        .filters { background: #fff; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; padding: 1rem 2rem; margin-bottom: 1.25rem; position: sticky; top: 0; z-index: 40; box-shadow: 0 1px 4px rgba(15, 23, 42, 0.04); }
        .filter-grid { display: grid; grid-template-columns: 1.25fr 1.25fr 1.3fr 1fr 1fr auto; gap: 0.6rem; align-items: end; }
        .filter-label { font-size: 0.72rem; color: #64748b; font-weight: 600; margin-bottom: 0.25rem; }
        .price-range-wrap { display: grid; grid-template-columns: 1fr auto 1fr; gap: 0.35rem; align-items: center; }
        .price-range-wrap span { color: #94a3b8; font-size: 0.85rem; }
        .table-card { border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #f1f5f9; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .table-wrap { overflow-x: auto; }
        .table-routes { margin: 0; min-width: 1000px; }
        .table-routes thead th { background: #0f172a; color: #fff; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; border: 0; padding: 0.75rem 0.6rem; white-space: nowrap; }
        .table-routes tbody td { border-color: #eef2f7; padding: 0.65rem 0.6rem; font-size: 0.88rem; color: #334155; vertical-align: middle; }
        .price-text { color: #16a34a; font-weight: 700; }
        .status-pill { display: inline-flex; padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
        .actions { display: inline-flex; gap: 0.3rem; }
        .actions .btn { width: 30px; height: 30px; padding: 0; border-radius: 8px; }
        .table-footer { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 0.9rem; border-top: 1px solid #e5e7eb; background: #fff; }
        .table-footer-text { font-size: 0.8rem; color: #64748b; margin: 0; }
        .pagination-wrap { display: flex; align-items: center; gap: 0.3rem; flex-wrap: wrap; }
        .pagination-wrap .btn { min-width: 34px; height: 32px; border-radius: 8px; font-size: 0.8rem; }
        .pagination-wrap .btn.active-page { background: #7c3aed; border-color: #7c3aed; color: #fff; }
        @media (max-width: 1200px) {
            .filter-grid { grid-template-columns: 1fr 1fr 1fr; }
        }
        @media (max-width: 992px) {
            .filters { padding: 0.9rem 1rem; }
            .search-input { min-width: 0; width: 100%; }
            .toolbar-actions { width: 100%; }
            .toolbar-actions .btn { flex: 1 1 auto; }
            .filter-grid { grid-template-columns: 1fr; }
            html body .main-content, html body .content-wrapper, html body main, html body.dashboard .main-content, html body .header, html body .admin-header, html body .employee-header {
                margin-left: 0 !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important;
            }
            .table-footer { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 992px) {
            .px-8 { padding-left: 1rem !important; padding-right: 1rem !important; }
            .table-wrap { overflow-x: auto !important; border-radius: 8px; -webkit-overflow-scrolling: touch; }
        }
    </style>
</head>
<body class="dashboard">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>
<main class="main-content">
    <div class="bg-gray-50 min-h-screen pb-12">
        <div class="px-8 py-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-light text-black">Saved Routes</h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1 font-light">Manage route pricing used when creating dispatch notes.</p>
            </div>
            <div class="toolbar-actions w-full sm:w-auto">
                <a href="routes.php?<?= htmlspecialchars($modQ) ?>" class="bg-purple-500 text-white px-6 py-2.5 rounded-full hover:bg-purple-600 transition-all font-normal shadow-sm flex items-center justify-center gap-2 text-sm no-underline">
                    <i class="fas fa-plus text-xs"></i> Create Route
                </a>
                <a href="create.php?<?= htmlspecialchars($modQ) ?>" class="bg-white border border-gray-200 text-gray-700 px-6 py-2.5 rounded-full hover:bg-gray-50 transition-all font-normal shadow-sm flex items-center justify-center gap-2 text-sm no-underline">
                    <i class="fas fa-truck text-xs"></i> New Dispatch
                </a>
            </div>
        </div>
        <div class="px-8">
            <div class="top-search-wrap">
                <div class="input-group search-input">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input id="routeSearchInput" type="text" class="form-control" placeholder="Search route, destination, or price...">
                </div>
            </div>
        </div>

        <div class="px-8 pb-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <div class="kpi-card">
                        <span class="kpi-icon kpi-icon-purple"><i class="fas fa-route"></i></span>
                        <div>
                            <div class="text-xs text-gray-500 font-semibold">Total Routes</div>
                            <div class="text-xl font-semibold text-gray-900 mt-1"><?= number_format($totalRoutes) ?></div>
                            <div class="text-xs text-gray-400 mt-1">All saved routes</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <div class="kpi-card">
                        <span class="kpi-icon kpi-icon-blue"><i class="fas fa-chart-line"></i></span>
                        <div>
                            <div class="text-xs text-gray-500 font-semibold">Average Price</div>
                            <div class="text-xl font-semibold text-gray-900 mt-1">TZS <?= number_format($averagePrice, 2) ?></div>
                            <div class="text-xs text-gray-400 mt-1">Across all routes</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <div class="kpi-card">
                        <span class="kpi-icon kpi-icon-green"><i class="fas fa-arrow-trend-up"></i></span>
                        <div>
                            <div class="text-xs text-gray-500 font-semibold">Highest Route Price</div>
                            <div class="text-xl font-semibold text-gray-900 mt-1">TZS <?= number_format($highestPrice, 2) ?></div>
                            <div class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($highestRoute) ?></div>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-100 rounded-xl p-4">
                    <div class="kpi-card">
                        <span class="kpi-icon kpi-icon-orange"><i class="fas fa-calendar-plus"></i></span>
                        <div>
                            <div class="text-xs text-gray-500 font-semibold">Recently Added</div>
                            <div class="text-xl font-semibold text-gray-900 mt-1"><?= number_format($todayAdded) ?></div>
                            <div class="text-xs text-gray-400 mt-1">Today</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="filters">
            <div class="filter-grid">
                    <div>
                        <label class="filter-label">From Location</label>
                        <select id="filterFrom" class="form-select form-select-sm">
                            <option value="">Select from location</option>
                            <?php foreach ($fromList as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="filter-label">To Location</label>
                        <select id="filterTo" class="form-select form-select-sm">
                            <option value="">Select to location</option>
                            <?php foreach ($toList as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="filter-label">Price Range</label>
                        <div class="price-range-wrap">
                            <input id="filterMinPrice" type="number" step="0.01" class="form-control form-control-sm" placeholder="Min Price">
                            <span>-</span>
                            <input id="filterMaxPrice" type="number" step="0.01" class="form-control form-control-sm" placeholder="Max Price">
                        </div>
                    </div>
                    <div>
                        <label class="filter-label">Date</label>
                        <input id="filterDate" type="date" class="form-control form-control-sm">
                    </div>
                    <div>
                        <label class="filter-label">Status</label>
                        <select id="filterStatus" class="form-select form-select-sm">
                            <option value="">Select status</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                    <div>
                        <button id="resetFiltersBtn" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-rotate me-1"></i> Reset Filters</button>
                    </div>
            </div>
        </div>

        <div class="px-8">
            <div class="table-card">
                <div class="table-wrap table-container">
                    <table class="table table-routes w-full text-left border-collapse" id="routesTable">
                        <thead>
                            <tr>
                                <th>S/N</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Price</th>
                                <th>Created By</th>
                                <th>Date Created</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="routesTableBody"></tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <p id="tableFooterText" class="table-footer-text mb-0">Showing 0 to 0 of 0 routes</p>
                    <div class="pagination-wrap">
                        <button id="prevPageBtn" class="btn btn-outline-secondary btn-sm">Previous</button>
                        <span id="pageNumberWrap" class="d-inline-flex gap-1"></span>
                        <button id="nextPageBtn" class="btn btn-outline-secondary btn-sm">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    (function () {
        const allRows = <?= json_encode($rowsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        const pageSize = 10;
        let currentPage = 1;
        let filteredRows = [...allRows];

        const tableBody = document.getElementById('routesTableBody');
        const footerText = document.getElementById('tableFooterText');
        const pageWrap = document.getElementById('pageNumberWrap');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');

        const searchInput = document.getElementById('routeSearchInput');
        const filterFrom = document.getElementById('filterFrom');
        const filterTo = document.getElementById('filterTo');
        const filterMinPrice = document.getElementById('filterMinPrice');
        const filterMaxPrice = document.getElementById('filterMaxPrice');
        const filterDate = document.getElementById('filterDate');
        const filterStatus = document.getElementById('filterStatus');
        const resetBtn = document.getElementById('resetFiltersBtn');

        function normalize(val) {
            return String(val || '').toLowerCase().trim();
        }

        function applyFilters() {
            const q = normalize(searchInput.value);
            const fromVal = normalize(filterFrom.value);
            const toVal = normalize(filterTo.value);
            const minP = filterMinPrice.value === '' ? null : Number(filterMinPrice.value);
            const maxP = filterMaxPrice.value === '' ? null : Number(filterMaxPrice.value);
            const d = normalize(filterDate.value);
            const s = normalize(filterStatus.value);

            filteredRows = allRows.filter((row) => {
                if (q) {
                    const inText = normalize(row.from).includes(q)
                        || normalize(row.to).includes(q)
                        || normalize(row.price_display).includes(q);
                    if (!inText) return false;
                }
                if (fromVal && normalize(row.from) !== fromVal) return false;
                if (toVal && normalize(row.to) !== toVal) return false;
                if (minP !== null && Number(row.price) < minP) return false;
                if (maxP !== null && Number(row.price) > maxP) return false;
                if (d && normalize(row.created_date) !== d) return false;
                if (s && normalize(row.status) !== s) return false;
                return true;
            });

            currentPage = 1;
            render();
        }

        function renderPagination(totalPages) {
            pageWrap.innerHTML = '';
            const maxButtons = 6;
            let start = Math.max(1, currentPage - 2);
            let end = Math.min(totalPages, start + maxButtons - 1);
            start = Math.max(1, end - maxButtons + 1);

            for (let p = start; p <= end; p++) {
                const btn = document.createElement('button');
                btn.className = 'btn btn-sm btn-outline-secondary' + (p === currentPage ? ' active-page' : '');
                btn.textContent = String(p);
                btn.addEventListener('click', () => {
                    currentPage = p;
                    render();
                });
                pageWrap.appendChild(btn);
            }

            prevBtn.disabled = currentPage <= 1;
            nextBtn.disabled = currentPage >= totalPages;
        }

        function render() {
            const total = filteredRows.length;
            const totalPages = Math.max(1, Math.ceil(total / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;

            const startIndex = (currentPage - 1) * pageSize;
            const pageRows = filteredRows.slice(startIndex, startIndex + pageSize);
            const endIndex = startIndex + pageRows.length;

            tableBody.innerHTML = '';

            if (pageRows.length === 0) {
                const tr = document.createElement('tr');
                tr.innerHTML = '<td colspan="8" class="text-center py-4 text-muted">No routes found.</td>';
                tableBody.appendChild(tr);
            } else {
                pageRows.forEach((row, idx) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${startIndex + idx + 1}</td>
                        <td>${escapeHtml(row.from)}</td>
                        <td>${escapeHtml(row.to)}</td>
                        <td><span class="price-text">${escapeHtml(row.price_display)}</span></td>
                        <td>${escapeHtml(row.created_by)}</td>
                        <td>${escapeHtml(row.created_display)}</td>
                        <td><span class="status-pill">${escapeHtml(row.status)}</span></td>
                        <td>
                            <span class="actions">
                                <button class="btn btn-light border" title="View"><i class="fas fa-eye text-secondary"></i></button>
                                <button class="btn btn-light border" title="Edit"><i class="fas fa-pen text-primary"></i></button>
                                <button class="btn btn-light border" title="Delete"><i class="fas fa-trash text-danger"></i></button>
                            </span>
                        </td>
                    `;
                    tableBody.appendChild(tr);
                });
            }

            footerText.textContent = `Showing ${total === 0 ? 0 : startIndex + 1} to ${endIndex} of ${total} routes`;
            renderPagination(totalPages);
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                render();
            }
        });
        nextBtn.addEventListener('click', () => {
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            if (currentPage < totalPages) {
                currentPage++;
                render();
            }
        });

        [searchInput, filterFrom, filterTo, filterMinPrice, filterMaxPrice, filterDate, filterStatus].forEach((el) => {
            el.addEventListener('input', applyFilters);
            el.addEventListener('change', applyFilters);
        });

        resetBtn.addEventListener('click', () => {
            searchInput.value = '';
            filterFrom.value = '';
            filterTo.value = '';
            filterMinPrice.value = '';
            filterMaxPrice.value = '';
            filterDate.value = '';
            filterStatus.value = '';
            applyFilters();
        });

        render();
    })();
</script>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>
