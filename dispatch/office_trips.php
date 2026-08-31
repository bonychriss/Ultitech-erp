<?php
require_once '../includes/functions.php';
require_once __DIR__ . '/dispatch-helpers.php';
requireLogin();

// Ensure dispatch_notes table exists + required columns
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dispatch_number VARCHAR(50) NOT NULL,
        dispatch_date DATE NOT NULL,
        dispatch_from VARCHAR(255) NULL,
        dispatch_to VARCHAR(255) NULL,
        route_price DECIMAL(12,2) NULL,
        address_to VARCHAR(255) NOT NULL,
        contents TEXT NOT NULL,
        created_by INT NOT NULL,
        signature_path VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Ignore
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM dispatch_notes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('dispatch_from', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN dispatch_from VARCHAR(255) NULL AFTER dispatch_date");
    }
    if (!in_array('dispatch_to', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN dispatch_to VARCHAR(255) NULL AFTER dispatch_from");
    }
    if (!in_array('route_price', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN route_price DECIMAL(12,2) NULL AFTER dispatch_to");
    }
    if (!in_array('type', $cols, true)) {
        $pdo->exec("ALTER TABLE dispatch_notes ADD COLUMN type ENUM('dispatch', 'trip') NOT NULL DEFAULT 'dispatch' AFTER id");
    }
} catch (Exception $e) {
    // Ignore
}

$error = '';
$success = $_SESSION['office_trip_success'] ?? '';
unset($_SESSION['office_trip_success']);

// Filters
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$routeFrom = trim((string) ($_GET['route_from'] ?? ''));
$routeTo = trim((string) ($_GET['route_to'] ?? ''));
$createdBy = trim((string) ($_GET['created_by'] ?? ''));
$showTripFilters = ($dateFrom !== '' || $dateTo !== '' || $routeFrom !== '' || $routeTo !== '' || $createdBy !== '');

// Created-by dropdown options (users who have trips)
$tripUsers = [];
try {
    $stU = $pdo->query("SELECT u.id, u.full_name
                        FROM users u
                        WHERE EXISTS (
                            SELECT 1 FROM dispatch_notes dn WHERE dn.type='trip' AND dn.created_by = u.id
                        )
                        ORDER BY u.full_name ASC");
    $tripUsers = $stU ? ($stU->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Exception $e) {
    $tripUsers = [];
}

// Query trips with filters
$where = ["dn.type = 'trip'"];
$params = [];
if ($dateFrom !== '') {
    $where[] = 'dn.dispatch_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'dn.dispatch_date <= ?';
    $params[] = $dateTo;
}
if ($routeFrom !== '') {
    $where[] = 'dn.dispatch_from = ?';
    $params[] = $routeFrom;
}
if ($routeTo !== '') {
    $where[] = 'dn.dispatch_to = ?';
    $params[] = $routeTo;
}
if ($createdBy !== '' && ctype_digit($createdBy)) {
    $where[] = 'dn.created_by = ?';
    $params[] = (int) $createdBy;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$trips = [];
$summary = ['count' => 0, 'total_cost' => 0.0];
try {
    $stmt = $pdo->prepare("SELECT dn.*, u.full_name
                           FROM dispatch_notes dn
                           LEFT JOIN users u ON dn.created_by = u.id
                           $whereSql
                           ORDER BY dn.dispatch_date DESC, dn.id DESC
                           LIMIT 200");
    $stmt->execute($params);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtSum = $pdo->prepare("SELECT COUNT(*) as c, COALESCE(SUM(dn.route_price),0) as t
                              FROM dispatch_notes dn
                              $whereSql");
    $stmtSum->execute($params);
    $row = $stmtSum->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $summary['count'] = (int) ($row['c'] ?? 0);
        $summary['total_cost'] = (float) ($row['t'] ?? 0);
    }
} catch (Exception $e) {
    $trips = [];
}

$dash = [
    'today_count' => 0,
    'month_count' => 0,
    'month_cost' => 0.0,
    'total_count' => 0,
    'total_cost' => 0.0,
];
try {
    $stmtDash = $pdo->query("
        SELECT
            SUM(CASE WHEN dn.dispatch_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
            SUM(CASE WHEN YEAR(dn.dispatch_date) = YEAR(CURDATE()) AND MONTH(dn.dispatch_date) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_count,
            SUM(CASE WHEN YEAR(dn.dispatch_date) = YEAR(CURDATE()) AND MONTH(dn.dispatch_date) = MONTH(CURDATE()) THEN COALESCE(dn.route_price, 0) ELSE 0 END) AS month_cost,
            COUNT(*) AS total_count,
            COALESCE(SUM(dn.route_price), 0) AS total_cost
        FROM dispatch_notes dn
        WHERE dn.type = 'trip'
    ");
    $rowD = $stmtDash ? $stmtDash->fetch(PDO::FETCH_ASSOC) : null;
    if ($rowD) {
        $dash['today_count'] = (int) ($rowD['today_count'] ?? 0);
        $dash['month_count'] = (int) ($rowD['month_count'] ?? 0);
        $dash['month_cost'] = (float) ($rowD['month_cost'] ?? 0);
        $dash['total_count'] = (int) ($rowD['total_count'] ?? 0);
        $dash['total_cost'] = (float) ($rowD['total_cost'] ?? 0);
    }
} catch (Exception $e) {
    // Ignore dashboard failures
}

$page_title = 'Office trips';
$modQ = 'module=dispatch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        /* Table header: use black */
        .office-trips-table thead tr.dash-table-head th {
            background: #111827 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            border-bottom: 2px solid #0b1220 !important;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
        }
        .office-trips-table thead tr.dash-table-head th:not(:last-child) {
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        .sig-preview { max-height: 48px; max-width: 100%; object-fit: contain; }
        @media (max-width: 767px) {
            .office-trips-wrapper { overflow-x: auto !important; -webkit-overflow-scrolling: touch; }
            .office-trips-table { min-width: 1100px !important; width: 100% !important; }
        }

        /* Mobile success bottom sheet (same style as routes/dispatch) */
        @media (max-width: 767.98px) { body.office-trip-success-open { overflow: hidden; touch-action: none; } }
        .office-trip-success-backdrop { display: none; }
        .office-trip-success-sheet { display: none; }
        @media (max-width: 767.98px) {
            .office-trip-success-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.48);
                z-index: 1080;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.28s ease, visibility 0.28s ease;
            }
            .office-trip-success-backdrop.is-visible { opacity: 1; visibility: visible; }
            .office-trip-success-sheet {
                display: block;
                position: fixed;
                left: 0; right: 0; bottom: 0;
                max-height: min(58vh, 420px);
                background: #fff;
                border-radius: 1.25rem 1.25rem 0 0;
                box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
                z-index: 1090;
                transform: translateY(105%);
                transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
                padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
            }
            .office-trip-success-sheet.is-visible { transform: translateY(0); }
            .office-trip-success-handle {
                width: 40px; height: 5px;
                background: #d1d5db;
                border-radius: 999px;
                margin: 12px auto 8px;
            }
        }

        /* Compact, modern filter controls */
        .office-trip-filters .form-label {
            margin-bottom: 0.25rem;
            letter-spacing: 0.06em;
        }
        /* Filters panel (reliable show/hide without Bootstrap collapse) */
        #tripFiltersPanel { display: none; }
        #tripFiltersPanel.is-open { display: block; }
        .office-trip-filters .form-control,
        .office-trip-filters .form-select {
            height: 38px;
            padding-top: 0.35rem;
            padding-bottom: 0.35rem;
            font-size: 0.92rem;
        }
        .office-trip-filters .input-group-text {
            height: 38px;
            background: #F9FAFB;
            color: #6B7280;
            border-color: #D1D5DB;
        }
        .office-trip-filters .form-control,
        .office-trip-filters .form-select {
            border-color: #D1D5DB;
        }
        .office-trip-filters .form-control:focus,
        .office-trip-filters .form-select:focus {
            border-color: rgba(37, 99, 235, 0.55);
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, 0.12);
        }
        .office-trip-filters .btn {
            height: 38px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .office-trip-filters .btn i { margin-right: 0 !important; }
    </style>
</head>
<body class="dashboard">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="dispatch-page-sticky bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-3 py-2 sm:px-4 sm:py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <div class="flex items-center gap-2 min-w-0 flex-grow sm:flex-grow-0">
                    <h1 class="text-base sm:text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2 leading-tight">
                        <i class="fas fa-car text-[#2563EB] text-sm sm:text-base"></i><span>Office trips</span>
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
                    <a href="create_trip.php?module=dispatch" class="btn mov-btn-primary rounded-md px-3 py-2 fw-semibold border-0 inline-flex items-center gap-2 no-underline justify-center">
                        <i class="fas fa-plus text-xs sm:text-sm"></i> Create office trip
                    </a>
                </div>
            </div>
            <div class="px-3 py-1.5 sm:px-4 sm:py-2 flex flex-wrap items-center gap-2 text-xs sm:text-base bg-gray-50/80 border-b border-gray-100 leading-snug">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1 text-[0.7rem] sm:text-sm"></i><?= date('l, d M Y') ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-500 sm:text-gray-600">Company trips and operational logs (all users).</span>
            </div>
        </div>

        <div class="px-4 pt-4 space-y-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mb-0 rounded-lg border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success mb-0 rounded-lg border-0 shadow-sm d-none d-md-block"><?= htmlspecialchars($success) ?></div>
                <div class="d-md-none office-trip-success-backdrop" id="officeTripSuccessBackdrop" aria-hidden="true"></div>
                <div class="d-md-none office-trip-success-sheet" id="officeTripSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="officeTripSuccessTitle">
                    <div class="office-trip-success-handle" aria-hidden="true"></div>
                    <div class="px-4 pb-4 pt-0 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-check fa-lg"></i>
                        </div>
                        <h2 id="officeTripSuccessTitle" class="h5 fw-bold text-gray-900 mb-2">Saved</h2>
                        <p class="text-gray-600 mb-4 small"><?= htmlspecialchars($success) ?></p>
                        <button type="button" class="btn mov-btn-primary w-100 py-2 rounded-pill fw-semibold border-0" id="officeTripSuccessDismiss">OK</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100">
                    <div class="fw-bold text-gray-900">Trips dashboard</div>
                    <div class="text-sm text-gray-500 mt-1">Quick overview of office trips (all users).</div>
                </div>
                <div class="p-4">
                    <div class="row g-3">
                        <div class="col-6 col-lg-3">
                            <div class="rounded-lg border border-gray-200 bg-white p-3 h-100">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Today</div>
                                <div class="mt-1 fs-4 fw-bold text-gray-900 tabular-nums"><?= number_format((int) $dash['today_count']) ?></div>
                                <div class="text-sm text-gray-500">Trips logged</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="rounded-lg border border-gray-200 bg-white p-3 h-100">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">This month</div>
                                <div class="mt-1 fs-4 fw-bold text-gray-900 tabular-nums"><?= number_format((int) $dash['month_count']) ?></div>
                                <div class="text-sm text-gray-500">Trips logged</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="rounded-lg border border-gray-200 bg-white p-3 h-100">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">Cost (month)</div>
                                <div class="mt-1 fs-4 fw-bold text-gray-900 tabular-nums">TZS <?= number_format((float) $dash['month_cost'], 2) ?></div>
                                <div class="text-sm text-gray-500">Sum of trip costs</div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="rounded-lg border border-gray-200 bg-white p-3 h-100">
                                <div class="text-xs text-uppercase text-gray-500 fw-semibold">All time</div>
                                <div class="mt-1 fs-4 fw-bold text-gray-900 tabular-nums"><?= number_format((int) $dash['total_count']) ?></div>
                                <div class="text-sm text-gray-500">Trips  TZS <?= number_format((float) $dash['total_cost'], 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                    <div>
                        <div class="fw-bold text-gray-900">Trips list</div>
                        <div class="text-sm text-gray-500 mt-1">
                            Filter by date, route, and creator.
                            <span class="text-gray-300">|</span>
                            Showing <strong class="text-gray-800"><?= number_format((int) $summary['count']) ?></strong> trips 
                            Cost <strong class="text-gray-800">TZS <?= number_format((float) $summary['total_cost'], 2) ?></strong>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button
                            type="button"
                            class="btn btn-outline-secondary rounded-md px-3 py-2 fw-semibold"
                            aria-expanded="<?= $showTripFilters ? 'true' : 'false' ?>"
                            aria-controls="tripFiltersPanel"
                            data-toggle-target="#tripFiltersPanel"
                            id="tripFiltersToggle"
                        >
                            <i class="fas fa-sliders-h me-2"></i><?= $showTripFilters ? 'Hide filters' : 'Show filters' ?>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <div id="tripFiltersPanel" class="<?= $showTripFilters ? 'is-open' : '' ?>">
                        <form method="GET" class="row g-2 align-items-end office-trip-filters pt-2">
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
                            <div class="col-6 col-lg-2">
                                <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">From</label>
                                <input type="text" name="route_from" class="form-control rounded-md border-gray-300" value="<?= htmlspecialchars($routeFrom) ?>" placeholder="e.g. Office">
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">To</label>
                                <input type="text" name="route_to" class="form-control rounded-md border-gray-300" value="<?= htmlspecialchars($routeTo) ?>" placeholder="e.g. Bank">
                            </div>
                            <div class="col-12 col-lg-2">
                                <label class="form-label small text-uppercase text-gray-500 fw-semibold mb-1">Created by</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-md border-gray-300"><i class="far fa-user"></i></span>
                                    <select name="created_by" class="form-select rounded-md border-gray-300">
                                        <option value="">All</option>
                                    <?php foreach ($tripUsers as $u): ?>
                                        <?php $uid = (string) ($u['id'] ?? ''); ?>
                                        <option value="<?= htmlspecialchars($uid) ?>" <?= $createdBy !== '' && $createdBy === $uid ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) ($u['full_name'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 col-lg-2 d-flex gap-2 justify-content-end align-items-end">
                                <a href="office_trips.php?module=dispatch" class="btn btn-outline-secondary rounded-md px-3 fw-semibold flex-1 flex-lg-grow-0">Reset</a>
                                <button type="submit" class="btn mov-btn-primary rounded-md px-3 fw-semibold border-0 flex-1 flex-lg-grow-0">
                                    <i class="fas fa-filter"></i><span>Filter</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (empty($trips)): ?>
                    <div class="p-5 text-center text-gray-500">
                        <i class="far fa-folder-open fa-3x mb-3 opacity-50 d-block"></i>
                        <p class="mb-0">No trips found for this filter.</p>
                    </div>
                <?php else: ?>
                    <div class="dash-table-wrapper office-trips-wrapper">
                        <table class="table table-hover align-middle mb-0 dash-table office-trips-table">
                            <thead>
                                <tr class="dash-table-head">
                                    <th class="ps-3 py-3">#</th>
                                    <th class="py-3">Date</th>
                                    <th class="py-3">Route</th>
                                    <th class="py-3">Address</th>
                                    <th class="py-3">Created by</th>
                                    <th class="py-3 text-end pe-3">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trips as $t): ?>
                                    <?php $tid = (int) ($t['id'] ?? 0); ?>
                                    <tr
                                        class="border-b border-gray-100 hover:bg-gray-50/80"
                                        role="button"
                                        tabindex="0"
                                        onclick="window.location.href='trip_details.php?module=dispatch&id=<?= $tid ?>';"
                                        onkeydown="if(event.key==='Enter'||event.key===' '){ event.preventDefault(); window.location.href='trip_details.php?module=dispatch&id=<?= $tid ?>'; }"
                                        style="cursor:pointer"
                                    >
                                        <td class="ps-3 py-3 fw-bold text-gray-900"><?= htmlspecialchars((string) ($t['dispatch_number'] ?? '')) ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($t['dispatch_date'] ?? '')) ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars((string) (($t['dispatch_from'] ?? '-') . ' ? ' . ($t['dispatch_to'] ?? '-'))) ?></td>
                                        <td class="py-3 text-gray-700"><?= htmlspecialchars((string) ($t['address_to'] ?? '')) ?></td>
                                        <td class="py-3 text-gray-800 fw-semibold"><?= htmlspecialchars((string) ($t['full_name'] ?? 'Unknown')) ?></td>
                                        <td class="py-3 text-end pe-3 fw-bold tabular-nums text-gray-900">TZS <?= number_format((float) ($t['route_price'] ?? 0), 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Trip creation moved to create_trip.php -->
        </div>
    </div>
</main>

<script>
(function () {
    function initToggle(btnId) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        var sel = btn.getAttribute('data-toggle-target');
        if (!sel) return;
        var target = document.querySelector(sel);
        if (!target) return;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = target.classList.contains('is-open');
            target.classList.toggle('is-open', !isOpen);
            btn.setAttribute('aria-expanded', (!isOpen).toString());
            btn.innerHTML = '<i class="fas fa-sliders-h me-2"></i>' + (isOpen ? 'Show filters' : 'Hide filters');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initToggle('tripFiltersToggle');
        });
    } else {
        initToggle('tripFiltersToggle');
    }
})();
</script>

<?php if ($success !== ''): ?>
<script>
(function () {
    var sheet = document.getElementById('officeTripSuccessSheet');
    var backdrop = document.getElementById('officeTripSuccessBackdrop');
    var btn = document.getElementById('officeTripSuccessDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('office-trip-success-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(closeSheet, 6000);
    }

    function closeSheet() {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('office-trip-success-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) sheet.setAttribute('aria-hidden', 'true');
        }, 350);
    }

    function init() { if (mq.matches) openSheet(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    mq.addEventListener('change', function (e) { if (!e.matches) closeSheet(); });
    if (btn) btn.addEventListener('click', closeSheet);
    backdrop.addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('is-visible')) closeSheet(); });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>

