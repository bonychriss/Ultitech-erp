<?php
require_once '../includes/functions.php';
require_once '../includes/document_layouts.php';
requireLogin();

// Fetch filter inputs
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$routeFrom = trim((string) ($_GET['route_from'] ?? ''));
$routeTo = trim((string) ($_GET['route_to'] ?? ''));

// Build query
$where = [];
$params = [];
if ($dateFrom !== '') {
    $where[] = "dn.dispatch_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "dn.dispatch_date <= ?";
    $params[] = $dateTo;
}
if ($routeFrom !== '') {
    $where[] = "dn.dispatch_from = ?";
    $params[] = $routeFrom;
}
if ($routeTo !== '') {
    $where[] = "dn.dispatch_to = ?";
    $params[] = $routeTo;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$records = [];
$summary = ['count' => 0, 'total_price' => 0.00];
try {
    $stmt = $pdo->prepare("SELECT dn.*, u.full_name 
                           FROM dispatch_notes dn
                           LEFT JOIN users u ON dn.created_by = u.id
                           $whereSql
                           ORDER BY dn.dispatch_date DESC, dn.id DESC");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtSum = $pdo->prepare("SELECT COUNT(*) as c, COALESCE(SUM(route_price),0) as total
                              FROM dispatch_notes dn
                              $whereSql");
    $stmtSum->execute($params);
    $sumRow = $stmtSum->fetch(PDO::FETCH_ASSOC);
    if ($sumRow) {
        $summary['count'] = (int) ($sumRow['c'] ?? 0);
        $summary['total_price'] = (float) ($sumRow['total'] ?? 0);
    }
} catch (Exception $e) {
    $records = [];
}

$periodLabel = 'All Dates';
if ($dateFrom && $dateTo) {
    $periodLabel = $dateFrom . ' to ' . $dateTo;
} elseif ($dateFrom) {
    $periodLabel = 'From ' . $dateFrom;
} elseif ($dateTo) {
    $periodLabel = 'Up to ' . $dateTo;
}

$pdfMode = isset($_GET['download']);
$baseUrl = getDocumentBaseUrl();
$layoutVars = [
    'records' => $records,
    'summary' => $summary,
    'periodLabel' => $periodLabel,
    'pdfMode' => $pdfMode,
    'baseUrl' => $baseUrl
];
if ($pdfMode) {
    $fileName = 'Dispatch_Report_' . date('Ymd_His') . '.pdf';
    downloadDocumentPdf('dispatch_report', $layoutVars, $fileName);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Dispatch Records - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
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
        .dash-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .dash-table-wrapper {
            overflow-x: auto;
            overflow-y: visible !important;
            position: relative;
            -webkit-overflow-scrolling: touch;
        }
        .dash-table thead tr.dash-table-head th {
            background-color: #111827 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            border-bottom: 2px solid #0b1220 !important;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
        }
        .dash-table thead tr.dash-table-head th:not(:last-child) {
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        /* Compact filters */
        .dispatch-record-filters .form-label { margin-bottom: 0.25rem; letter-spacing: 0.06em; }
        .dispatch-record-filters .form-control { height: 38px; padding-top: 0.35rem; padding-bottom: 0.35rem; font-size: 0.92rem; border-color: #D1D5DB; }
        .dispatch-record-filters .input-group-text { height: 38px; background: #F9FAFB; color: #6B7280; border-color: #D1D5DB; }
        .dispatch-record-filters .form-control:focus { border-color: rgba(37, 99, 235, 0.55); box-shadow: 0 0 0 .2rem rgba(37, 99, 235, 0.12); }
        .dispatch-record-filters .btn { height: 38px; line-height: 1; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        @media (max-width: 767.98px) {
            .records-table { min-width: 1100px !important; width: 100% !important; }
        }
    </style>
</head>
<body class="dashboard dispatch-page">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
$modQ = 'module=dispatch';
?>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8 dispatch-dash-mobile">
    <div class="max-w-full mx-auto px-0">
        <div class="dispatch-page-sticky bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-3 py-2 sm:px-4 sm:py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <div class="flex items-center gap-2 min-w-0 flex-grow sm:flex-grow-0">
                    <h1 class="text-base sm:text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2 leading-tight">
                        <i class="fas fa-file-alt text-[#2563EB] text-sm sm:text-base"></i><span>Dispatch records</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px] hidden sm:block"></div>
                <div class="d-flex gap-2 flex-wrap w-100 w-sm-auto">
                    <a href="index.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-chart-line text-xs sm:text-sm"></i> Overview
                    </a>
                    <a href="routes.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-map-marked-alt text-xs sm:text-sm"></i> Routes
                    </a>
                </div>
            </div>
            <div class="px-3 py-1.5 sm:px-4 sm:py-2 flex flex-wrap items-center gap-2 text-xs sm:text-base bg-gray-50/80 border-b border-gray-100 leading-snug">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1 text-[0.7rem] sm:text-sm"></i><?= date('l, d M Y') ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-500 sm:text-gray-600">Filter records and export PDF.</span>
            </div>
        </div>

        <div class="px-4 pt-4 space-y-4">
            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                    <div>
                        <div class="fw-bold text-gray-900">Dispatch report</div>
                        <div class="text-sm text-gray-500 mt-1">Period: <strong class="text-gray-800"><?= htmlspecialchars($periodLabel) ?></strong></div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary rounded-md px-3 py-2 fw-semibold" href="records.php?module=dispatch">Reset</a>
                        <a class="btn btn-outline-primary rounded-md px-3 py-2 fw-semibold" href="invoice_prepare.php?module=dispatch&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&route_from=<?= urlencode($routeFrom) ?>&route_to=<?= urlencode($routeTo) ?>">
                            <i class="fas fa-receipt"></i><span>Invoice</span>
                        </a>
                        <a class="btn mov-btn-primary rounded-md px-3 py-2 fw-semibold border-0" href="records.php?download=1&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&route_from=<?= urlencode($routeFrom) ?>&route_to=<?= urlencode($routeTo) ?>">
                            <i class="fas fa-file-pdf"></i><span>PDF</span>
                        </a>
                    </div>
                </div>
                <div class="p-4">
                    <form method="GET" class="row g-2 align-items-end dispatch-record-filters">
                        <input type="hidden" name="module" value="dispatch">
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Date from</label>
                            <div class="input-group">
                                <span class="input-group-text rounded-md border-gray-300"><i class="far fa-calendar"></i></span>
                                <input type="date" name="date_from" class="form-control rounded-md border-gray-300" value="<?= htmlspecialchars($dateFrom) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Date to</label>
                            <div class="input-group">
                                <span class="input-group-text rounded-md border-gray-300"><i class="far fa-calendar"></i></span>
                                <input type="date" name="date_to" class="form-control rounded-md border-gray-300" value="<?= htmlspecialchars($dateTo) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">From</label>
                            <input type="text" name="route_from" class="form-control rounded-md border-gray-300" value="<?= htmlspecialchars($routeFrom) ?>" placeholder="e.g. Office">
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">To</label>
                            <input type="text" name="route_to" class="form-control rounded-md border-gray-300" value="<?= htmlspecialchars($routeTo) ?>" placeholder="e.g. Bank">
                        </div>
                        <div class="col-12 col-lg-2 d-flex gap-2 justify-content-end align-items-end">
                            <button class="btn mov-btn-primary rounded-md px-3 fw-semibold border-0 w-100" type="submit">
                                <i class="fas fa-filter"></i><span>Filter</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-wrap gap-3 align-items-center justify-content-between">
                    <div class="fw-bold text-gray-900">Summary</div>
                    <div class="text-sm text-gray-600">
                        <span class="me-3"><strong class="text-gray-900"><?= number_format((int) $summary['count']) ?></strong> records</span>
                        <span><strong class="text-gray-900"><?= number_format((float) $summary['total_price'], 2) ?></strong> total route price</span>
                    </div>
                </div>
            </div>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                    <div class="fw-bold text-gray-900">Dispatch records</div>
                    <div class="text-sm text-gray-500">Showing <?= number_format(count($records)) ?> rows</div>
                </div>
                <div class="p-0">
                    <?php if (empty($records)): ?>
                        <div class="p-5 text-center text-gray-500">No records found.</div>
                    <?php else: ?>
                        <div class="dash-table-wrapper">
                            <table class="table table-hover align-middle mb-0 dash-table records-table">
                                <thead>
                                    <tr class="dash-table-head">
                                        <th class="ps-3 py-3">#</th>
                                        <th class="py-3">Date</th>
                                        <th class="py-3">Route</th>
                                        <th class="py-3 text-end">Price</th>
                                        <th class="py-3">Address</th>
                                        <th class="py-3">Contents</th>
                                        <th class="py-3 pe-3">Created by</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $r): ?>
                                        <tr class="border-b border-gray-100 hover:bg-gray-50/80">
                                            <td class="ps-3 py-3 fw-bold text-gray-900"><?= htmlspecialchars((string) ($r['dispatch_number'] ?? '')) ?></td>
                                            <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($r['dispatch_date'] ?? '')) ?></td>
                                            <td class="py-3 text-gray-700"><?= htmlspecialchars((string) (($r['dispatch_from'] ?? '-') . ' â†’ ' . ($r['dispatch_to'] ?? '-'))) ?></td>
                                            <td class="py-3 text-end fw-bold tabular-nums text-gray-900"><?= number_format((float) ($r['route_price'] ?? 0), 2) ?></td>
                                            <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($r['address_to'] ?? '')) ?></td>
                                            <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($r['contents'] ?? '')) ?></td>
                                            <td class="py-3 pe-3 text-gray-800 fw-semibold"><?= htmlspecialchars((string) ($r['full_name'] ?? 'User')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
