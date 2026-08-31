<?php
require_once '../includes/functions.php';
require_once __DIR__ . '/dispatch-helpers.php';
requireLogin();

// Ensure tables exist
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_routes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_from VARCHAR(255) NOT NULL,
        route_to VARCHAR(255) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        price_currency VARCHAR(3) NOT NULL DEFAULT 'TZS',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // Ignore; fallback to runtime errors if table is locked or permissions are limited
}
ensure_dispatch_routes_price_currency($pdo);

// Ensure columns exist
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
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
$defaultDate = date('Y-m-d');
$defaultDispatchNumber = '';

try {
    $year = date('y');
    $prefix = "DN-$year-";
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(dispatch_number, ?) AS UNSIGNED)) 
                           FROM dispatch_notes 
                           WHERE dispatch_number LIKE ?");
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $maxNum = $stmt->fetchColumn();
    $nextNum = ($maxNum ? (int) $maxNum : 0) + 1;
    $defaultDispatchNumber = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
} catch (Exception $e) {
    $defaultDispatchNumber = 'DN-' . date('y') . '-' . str_pad('1', 4, '0', STR_PAD_LEFT);
}

// Fetch current user's signature
$signaturePath = null;
try {
    $stmtSig = $pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
    $stmtSig->execute([$_SESSION['user_id']]);
    $signaturePath = $stmtSig->fetchColumn() ?: null;
} catch (Exception $e) {
    $signaturePath = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dispatchNumber = trim((string) ($_POST['dispatch_number'] ?? ''));
    if ($dispatchNumber === '') {
        $dispatchNumber = $defaultDispatchNumber;
    }
    $dispatchDate = trim((string) ($_POST['dispatch_date'] ?? ''));
    if ($dispatchDate === '') {
        $dispatchDate = $defaultDate;
    }
    $dispatchFrom = trim((string) ($_POST['dispatch_from'] ?? ''));
    $dispatchTo = trim((string) ($_POST['dispatch_to'] ?? ''));
    $routePrice = trim((string) ($_POST['route_price'] ?? ''));
    $addressTo = trim((string) ($_POST['address_to'] ?? ''));
    $contents = trim((string) ($_POST['contents'] ?? ''));

    if ($dispatchNumber === '' || $dispatchDate === '' || $addressTo === '' || $contents === '' || $dispatchFrom === '' || $dispatchTo === '') {
        $error = 'Please fill all required fields.';
    } else {
        try {
            if ($routePrice === '') {
                $stmtPrice = $pdo->prepare("SELECT price FROM dispatch_routes WHERE route_from = ? AND route_to = ? LIMIT 1");
                $stmtPrice->execute([$dispatchFrom, $dispatchTo]);
                $routePrice = $stmtPrice->fetchColumn();
            }
            $stmt = $pdo->prepare("INSERT INTO dispatch_notes 
                (dispatch_number, dispatch_date, dispatch_from, dispatch_to, route_price, address_to, contents, created_by, signature_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $dispatchNumber,
                $dispatchDate,
                $dispatchFrom,
                $dispatchTo,
                $routePrice,
                $addressTo,
                $contents,
                $_SESSION['user_id'],
                $signaturePath
            ]);
            $_SESSION['success'] = 'Dispatch note created successfully.';
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to create dispatch note: ' . $e->getMessage();
        }
    }
}

// Recent dispatch notes
$dispatches = [];
try {
    $stmt = $pdo->query("SELECT dn.*, u.full_name 
                         FROM dispatch_notes dn 
                         LEFT JOIN users u ON dn.created_by = u.id 
                         ORDER BY dn.created_at DESC 
                         LIMIT 20");
    $dispatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $dispatches = [];
}

$routes = [];
try {
    $stmt = $pdo->query("SELECT id, route_from, route_to, price, price_currency FROM dispatch_routes ORDER BY created_at DESC");
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $routes = [];
}

$routesByFrom = [];
foreach ($routes as $r) {
    $from = $r['route_from'];
    if (!isset($routesByFrom[$from])) {
        $routesByFrom[$from] = [];
    }
    $cur = normalize_dispatch_route_currency((string) ($r['price_currency'] ?? 'TZS'));
    $routesByFrom[$from][] = [
        'to' => $r['route_to'],
        'price' => $r['price'],
        'currency' => $cur,
    ];
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$stmtMonth = $pdo->prepare("SELECT COUNT(*) FROM dispatch_notes WHERE dispatch_date >= ?");
$stmtMonth->execute([$monthStart]);
$monthCount = $stmtMonth->fetchColumn();

$stmtCost = $pdo->prepare("SELECT SUM(route_price) FROM dispatch_notes WHERE dispatch_date >= ?");
$stmtCost->execute([$monthStart]);
$monthCost = $stmtCost->fetchColumn() ?: 0;

$stmtToday = $pdo->prepare("SELECT COUNT(*) FROM dispatch_notes WHERE dispatch_date = ?");
$stmtToday->execute([$today]);
$todayCount = $stmtToday->fetchColumn();

$page_title = 'Dispatch';
$modQ = 'module=dispatch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        .dash-table-wrapper {
            overflow-x: auto;
            overflow-y: visible !important;
            position: relative;
            -webkit-overflow-scrolling: touch;
        }
        .dash-table thead tr.dash-table-head th {
            background-color: #1c2331 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            border-bottom: 2px solid #151a24 !important;
            vertical-align: middle;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
        }
        .dash-table thead tr.dash-table-head th:not(:last-child) {
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }
        .mini-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Mobile: stack rows as cards (same idea as prior dispatch mobile CSS) */
        @media (max-width: 767px) {
            .dispatch-dash-mobile .dash-table-wrapper { overflow-x: visible; }
            .dispatch-dash-mobile .dispatch-dash-table thead { display: none; }
            .dispatch-dash-mobile .dispatch-dash-table tbody { display: block; }
            .dispatch-dash-mobile .dispatch-dash-table tbody tr {
                display: block;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 14px;
                margin-bottom: 12px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }
            .dispatch-dash-mobile .dispatch-dash-table tbody tr:last-child { margin-bottom: 0; }
            .dispatch-dash-mobile .dispatch-dash-table tbody td {
                display: block;
                padding: 12px 14px !important;
                border-bottom: 1px solid #f3f4f6 !important;
                text-align: left !important;
                vertical-align: top !important;
            }
            .dispatch-dash-mobile .dispatch-dash-table tbody td:last-child { border-bottom: none !important; }
            .dispatch-dash-mobile .dispatch-dash-table tbody td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.07em;
                color: #6b7280;
                margin-bottom: 6px;
                font-weight: 600;
            }
            .dispatch-dash-mobile .dispatch-dash-table tbody td.dispatch-td-actions {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                background: #f9fafb;
            }
            .dispatch-dash-mobile .dispatch-dash-table tbody td.dispatch-td-actions::before { display: none; }
            .dispatch-dash-mobile .dispatch-contents-mobile {
                max-width: none !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: unset !important;
            }

            /* Keep Recent Dispatches in desktop table view on mobile */
            .dispatch-dash-mobile .recent-dispatches-wrapper {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table {
                min-width: 920px !important;
                width: 100% !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table thead {
                display: table-header-group !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table tbody {
                display: table-row-group !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table tbody tr {
                display: table-row !important;
                border-radius: 0 !important;
                border: none !important;
                box-shadow: none !important;
                margin: 0 !important;
                overflow: visible !important;
                background: transparent !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table tbody td {
                display: table-cell !important;
                border-bottom: 1px solid #f3f4f6 !important;
                padding: 12px 10px !important;
                vertical-align: middle !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table tbody td[data-label]::before {
                display: none !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table tbody td.dispatch-td-actions {
                display: table-cell !important;
                background: transparent !important;
            }
            .dispatch-dash-mobile .recent-dispatches-wrapper .dispatch-dash-table.dispatch-keep-table .dispatch-contents-mobile {
                max-width: 280px !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }

        }
        @media (min-width: 768px) {
            .dispatch-dash-table { min-width: 720px; }
        }

        .dispatch-page-sticky .btn.mov-btn-primary.dispatch-new-btn {
            padding: 0.3rem 0.55rem !important;
            font-size: 0.75rem !important;
            line-height: 1.2 !important;
            font-weight: 600 !important;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.15) !important;
        }
        @media (min-width: 576px) {
            .dispatch-page-sticky .btn.mov-btn-primary.dispatch-new-btn {
                padding: 0.35rem 0.7rem !important;
                font-size: 0.8125rem !important;
            }
        }

        @media (max-width: 767px) {
            .dispatch-toolbar-secondary {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }
            .dispatch-toolbar-secondary a {
                min-height: 36px;
                padding-top: 0.35rem !important;
                padding-bottom: 0.35rem !important;
                font-size: 0.8125rem !important;
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }
        }

        /* Mobile success: bottom sheet (half-screen style) */
        @media (max-width: 767.98px) {
            body.dispatch-success-sheet-open {
                overflow: hidden;
                touch-action: none;
            }
        }
        .dispatch-success-sheet-backdrop {
            display: none;
        }
        .dispatch-success-sheet {
            display: none;
        }
        @media (max-width: 767.98px) {
            .dispatch-success-sheet-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.48);
                z-index: 1080;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.28s ease, visibility 0.28s ease;
            }
            .dispatch-success-sheet-backdrop.is-visible {
                opacity: 1;
                visibility: visible;
            }
            .dispatch-success-sheet {
                display: block;
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                max-height: min(58vh, 420px);
                background: #fff;
                border-radius: 1.25rem 1.25rem 0 0;
                box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
                z-index: 1090;
                transform: translateY(105%);
                transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
                padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
            }
            .dispatch-success-sheet.is-visible {
                transform: translateY(0);
            }
            .dispatch-success-sheet-handle {
                width: 40px;
                height: 5px;
                background: #d1d5db;
                border-radius: 999px;
                margin: 12px auto 8px;
                flex-shrink: 0;
            }
        }

        /* Simple stat cards */
        .dispatch-stat-card {
            padding: 14px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .dispatch-stat-label {
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 6px;
        }
        .dispatch-stat-value {
            font-size: 1.25rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.15;
        }
        .dispatch-stat-sub {
            margin-top: 6px;
            font-size: 0.85rem;
            color: #6b7280;
        }
        @media (min-width: 768px) {
            .dispatch-stat-card {
                padding: 18px 18px;
            }
            .dispatch-stat-value {
                font-size: 1.5rem;
            }
        }

        /* (intentionally no view-mode selector; table view only) */
    </style>
</head>
<body class="dashboard">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';
require_once __DIR__ . '/../includes/header_employee.php';
?>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8 dispatch-dash-mobile">
    <div class="max-w-full mx-auto px-0">
        <div class="dispatch-page-sticky bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-3 py-2 sm:px-4 sm:py-3 flex flex-wrap items-start sm:items-center gap-2 sm:gap-3 border-b border-gray-100">
                <div class="w-full sm:flex-1 sm:min-w-0 flex flex-col gap-2">
                    <h1 class="text-base sm:text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-1.5 sm:gap-2 leading-tight">
                        <i class="fas fa-truck text-[#2563EB] text-sm sm:text-base"></i><span>Dispatch</span>
                    </h1>
                    <a href="create.php?<?= htmlspecialchars($modQ) ?>" class="dispatch-new-btn btn mov-btn-primary rounded px-2 py-1 sm:px-2.5 sm:py-1.5 text-xs sm:text-sm font-semibold inline-flex items-center gap-1 border-0 no-underline w-full sm:w-auto justify-center leading-tight self-stretch sm:self-start">
                        <i class="fas fa-plus text-[0.65rem] sm:text-xs"></i> New dispatch
                    </a>
                </div>
                <div class="flex-1 min-w-[8px] hidden sm:block"></div>
                <div class="dispatch-toolbar-secondary flex flex-wrap items-center gap-2 w-full sm:w-auto justify-stretch sm:justify-end">
                    <a href="routes.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-2.5 py-1.5 sm:px-3 sm:py-2 bg-white inline-flex items-center gap-1.5 sm:gap-2 no-underline flex-1 sm:flex-initial justify-center">
                        <i class="fas fa-map-marked-alt text-xs sm:text-sm"></i> Routes
                    </a>
                    <a href="invoice_prepare.php?module=dispatch&date_from=<?= urlencode($monthStart) ?>&date_to=<?= urlencode($today) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#7c2d12] border border-gray-200 rounded-md px-2.5 py-1.5 sm:px-3 sm:py-2 bg-white inline-flex items-center gap-1.5 sm:gap-2 no-underline flex-1 sm:flex-initial justify-center" title="Generate invoice for this month">
                        <i class="fas fa-receipt text-xs sm:text-sm"></i> Invoice
                    </a>
                    <a href="trips.php?<?= htmlspecialchars($modQ) ?>" class="text-sm sm:text-base font-medium text-gray-600 hover:text-[#059669] border border-gray-200 rounded-md px-2.5 py-1.5 sm:px-3 sm:py-2 bg-white inline-flex items-center gap-1.5 sm:gap-2 no-underline flex-1 sm:flex-initial justify-center">
                        <i class="fas fa-car text-xs sm:text-sm"></i> My logs
                    </a>
                </div>
                <a href="/select-module.php" class="hidden sm:inline-flex text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white items-center gap-2 no-underline justify-center">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-3 py-1.5 sm:px-4 sm:py-2 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-0.5 sm:gap-2 text-xs sm:text-base bg-gray-50/80 border-b border-gray-100 leading-snug">
                <span class="text-gray-600"><i class="fas fa-calendar text-gray-400 me-1 text-[0.7rem] sm:text-sm"></i><?= date('l, d M Y') ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span class="text-gray-500 sm:text-gray-600">Dispatch notes, routes, and logistics overview.</span>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="px-4 pt-4">
                <div class="alert alert-danger mb-0 rounded-lg border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="px-4 pt-4">
                <div class="alert alert-success mb-0 rounded-lg border-0 shadow-sm d-none d-md-block"><?= htmlspecialchars($success) ?></div>
                <div class="d-md-none dispatch-success-sheet-backdrop" id="dispatchSuccessBackdrop" aria-hidden="true"></div>
                <div class="d-md-none dispatch-success-sheet" id="dispatchSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="dispatchSuccessSheetTitle">
                    <div class="dispatch-success-sheet-handle" aria-hidden="true"></div>
                    <div class="px-4 pb-4 pt-0 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-check fa-lg"></i>
                        </div>
                        <h2 id="dispatchSuccessSheetTitle" class="h5 fw-bold text-gray-900 mb-2">Dispatch saved</h2>
                        <p class="text-gray-600 mb-4 small"><?= htmlspecialchars($success) ?></p>
                        <button type="button" class="btn mov-btn-primary w-100 py-2 rounded-pill fw-semibold border-0" id="dispatchSuccessSheetDismiss">
                            OK
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="px-4 pt-4 pb-3">
            <div class="row row-cols-2 row-cols-xl-3 g-3">
                <div class="col">
                    <div class="dispatch-stat-card h-100">
                        <div class="dispatch-stat-label">Active dispatches</div>
                        <div class="dispatch-stat-value tabular-nums"><?= number_format((int) $monthCount) ?></div>
                        <div class="dispatch-stat-sub">This month</div>
                    </div>
                </div>
                <div class="col">
                    <div class="dispatch-stat-card h-100">
                        <div class="dispatch-stat-label">Total cost (month)</div>
                        <div class="dispatch-stat-value tabular-nums">TZS <?= number_format((float) $monthCost / 1000, 1) ?>k</div>
                        <div class="dispatch-stat-sub">Sum of route prices</div>
                    </div>
                </div>
                <div class="col">
                    <div class="dispatch-stat-card h-100">
                        <div class="dispatch-stat-label">Today's activity</div>
                        <div class="dispatch-stat-value tabular-nums"><?= number_format((int) $todayCount) ?></div>
                        <div class="dispatch-stat-sub">Notes dated today</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-4 pb-4">
            <div class="dash-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                    <div class="fw-bold text-gray-900">Recent dispatches</div>
                    <a href="routes.php?<?= htmlspecialchars($modQ) ?>" class="text-sm font-semibold text-gray-700 hover:text-[#2563EB] no-underline">Manage routes <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <?php if (empty($dispatches)): ?>
                    <div class="p-5 text-center text-gray-500">
                        <i class="far fa-folder-open fa-3x mb-3 opacity-50 d-block"></i>
                        <p class="mb-0">No dispatch notes found. Create your first one.</p>
                    </div>
                <?php else: ?>
                    <div class="dash-table-wrapper recent-dispatches-wrapper">
                        <table class="table table-hover align-middle mb-0 dash-table dispatch-dash-table dispatch-keep-table">
                            <thead>
                                <tr class="dash-table-head">
                                    <th class="ps-3 py-3">Reference</th>
                                    <th class="py-3">Route</th>
                                    <th class="py-3">Contents</th>
                                    <th class="py-3">Created by</th>
                                    <th class="py-3 text-end">Cost</th>
                                    <th class="py-3 text-end pe-3" style="width: 72px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dispatches as $d): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50/80">
                                        <td class="ps-3 py-3" data-label="Reference">
                                            <div class="fw-bold text-gray-900 d-flex flex-wrap align-items-center gap-2">
                                                <?= htmlspecialchars($d['dispatch_number']) ?>
                                                <?php if (($d['type'] ?? 'dispatch') === 'trip'): ?>
                                                    <span class="badge bg-secondary rounded-pill" style="font-size: 0.65rem;">TRIP</span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill" style="font-size: 0.65rem; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">DISPATCH</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-gray-500 mt-1">
                                                <i class="far fa-calendar me-1"></i><?= date('M d, Y', strtotime($d['dispatch_date'])) ?>
                                            </div>
                                        </td>
                                        <td class="py-3" data-label="Route &amp; address">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($d['dispatch_from'] ?? '?') ?></span>
                                                <i class="fas fa-arrow-right small text-gray-400"></i>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($d['dispatch_to'] ?? '?') ?></span>
                                            </div>
                                            <div class="small text-gray-600 mt-2">
                                                <i class="fas fa-map-marker-alt text-gray-400 me-1"></i><?= htmlspecialchars($d['address_to']) ?>
                                            </div>
                                        </td>
                                        <td class="py-3" data-label="Contents">
                                            <div class="dispatch-contents-mobile text-gray-800 small fw-semibold" style="max-width: 280px;">
                                                <?= htmlspecialchars($d['contents']) ?>
                                            </div>
                                        </td>
                                        <td class="py-3" data-label="Created by">
                                            <span class="text-gray-800 fw-semibold"><?= htmlspecialchars($d['full_name'] ?? 'Unknown') ?></span>
                                        </td>
                                        <td class="py-3 text-end" data-label="Cost">
                                            <?php if (($d['type'] ?? 'dispatch') === 'trip'): ?>
                                                <span class="small text-gray-500 fst-italic">Office op</span>
                                            <?php else: ?>
                                                <span class="fw-bold text-gray-900 tabular-nums">TZS <?= number_format((float) ($d['route_price'] ?? 0), 2) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 text-end pe-3 dispatch-td-actions" data-label="">
                                            <a href="#" class="btn btn-sm btn-light border text-gray-700 px-3 py-2" style="min-width: 44px; min-height: 44px;" title="Print" aria-label="Print note"><i class="fas fa-print"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>

<?php if ($success !== ''): ?>
<script>
(function () {
    var sheet = document.getElementById('dispatchSuccessSheet');
    var backdrop = document.getElementById('dispatchSuccessBackdrop');
    var btn = document.getElementById('dispatchSuccessSheetDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('dispatch-success-sheet-open');
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
        document.body.classList.remove('dispatch-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) {
                sheet.setAttribute('aria-hidden', 'true');
            }
        }, 350);
    }

    function init() {
        if (mq.matches) openSheet();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    mq.addEventListener('change', function (e) {
        if (!e.matches) closeSheet();
    });

    if (btn) btn.addEventListener('click', closeSheet);
    backdrop.addEventListener('click', closeSheet);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) closeSheet();
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>
