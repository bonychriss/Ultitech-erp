<?php
require_once '../includes/functions.php';
require_once __DIR__ . '/dispatch-helpers.php';
requireLogin();

// Ensure table exists
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
    // Ignore
}
ensure_dispatch_routes_price_currency($pdo);

// Data cleanup: remove routes explicitly requested for removal.
try {
    $pdo->prepare("DELETE FROM dispatch_routes WHERE UPPER(TRIM(route_to)) = ?")->execute(['MOROCO']);

    $stmtCleanupWst = $pdo->prepare(
        "DELETE FROM dispatch_routes
         WHERE UPPER(TRIM(route_from)) = 'WST'
           AND REPLACE(REPLACE(REPLACE(UPPER(TRIM(route_to)), ',', ''), '.', ''), ' ', '') IN ('MWANDEGEBARESA','KIVUKONI','TARMAL','KKOOJANGWANI')"
    );
    $stmtCleanupWst->execute();
} catch (Exception $e) {
    // Keep page functional even if cleanup fails.
}

$error = '';
$success = $_SESSION['route_success'] ?? '';
unset($_SESSION['route_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $routeFrom = trim((string) ($_POST['route_from'] ?? ''));
    $routeTo = trim((string) ($_POST['route_to'] ?? ''));
    $routePrice = trim((string) ($_POST['route_price'] ?? ''));
    $priceCurrency = normalize_dispatch_route_currency((string) ($_POST['price_currency'] ?? 'TZS'));
    if ($routeFrom === '' || $routeTo === '' || $routePrice === '') {
        $error = 'Please fill all route fields.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO dispatch_routes (route_from, route_to, price, price_currency) VALUES (?, ?, ?, ?)");
            $stmt->execute([$routeFrom, $routeTo, $routePrice, $priceCurrency]);
            $_SESSION['route_success'] = 'Route saved successfully.';
            header('Location: routes.php?module=dispatch');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to save route: ' . $e->getMessage();
        }
    }
}

$page_title = 'Dispatch routes';
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        .main-content-wrapper {
            padding: 2rem;
        }
        .page-shell {
            padding-left: 4rem;
        }
        .editor-shell {
            max-width: 1140px;
            margin: 0 auto;
        }
        .editor-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .editor-layout {
            display: grid;
            grid-template-columns: 180px minmax(0, 1fr);
            gap: 2rem;
            align-items: start;
        }
        .section-nav {
            position: sticky;
            top: 96px;
            align-self: start;
        }
        .section-nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .section-nav li + li {
            margin-top: 0.5rem;
        }
        .section-nav a {
            display: block;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .section-nav a:hover {
            background: #eff6ff;
            color: #2563eb;
        }
        .section-nav a.is-active {
            background: #f3e8ff;
            color: #7c3aed;
            font-weight: 600;
        }
        .editor-main {
            min-width: 0;
        }
        .editor-section {
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .editor-section:last-of-type {
            margin-bottom: 1.5rem;
        }
        .section-header {
            margin-bottom: 1.25rem;
        }
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .section-subtitle {
            font-size: 14px;
            color: #94a3b8;
            margin: 0;
        }
        .form-row {
            display: grid;
            grid-template-columns: 210px 1fr;
            align-items: start;
            margin-bottom: 24px;
        }
        .form-row:last-child {
            margin-bottom: 0;
        }
        .field-label {
            font-size: 16px;
            font-weight: 500;
            color: #1e293b;
            padding-top: 12px;
        }
        .form-input {
            width: 100%;
            padding: 13px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            color: #1e293b;
            outline: none;
            transition: all 0.2s;
            background: #fff;
        }
        .dispatch-route-price-group .form-select,
        .dispatch-route-price-group .form-control {
            font-size: 16px;
            min-height: 48px;
        }
        .form-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .btn-save {
            background: #7c3aed !important;
            color: #fff !important;
            border: 1px solid #7c3aed !important;
            padding: 14px 48px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
            transition: all 0.2s;
        }
        .btn-save:hover {
            background: #6d28d9 !important;
            border-color: #6d28d9 !important;
            color: #fff !important;
        }
        .btn-save:focus,
        .btn-save:active {
            background: #6d28d9 !important;
            border-color: #6d28d9 !important;
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

        @media (max-width: 767px) {
            .dispatch-dash-mobile .dash-table-wrapper { overflow-x: visible; }
            .dispatch-dash-mobile .routes-list-table thead { display: none; }
            .dispatch-dash-mobile .routes-list-table tbody { display: block; }
            .dispatch-dash-mobile .routes-list-table tbody tr {
                display: block;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 14px;
                margin-bottom: 12px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }
            .dispatch-dash-mobile .routes-list-table tbody tr:last-child { margin-bottom: 0; }
            .dispatch-dash-mobile .routes-list-table tbody td {
                display: block;
                padding: 12px 14px !important;
                border-bottom: 1px solid #f3f4f6 !important;
                text-align: left !important;
            }
            .dispatch-dash-mobile .routes-list-table tbody td:last-child { border-bottom: none !important; }
            .dispatch-dash-mobile .routes-list-table tbody td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.07em;
                color: #6b7280;
                margin-bottom: 6px;
                font-weight: 600;
            }

            /* Keep Saved Routes in desktop table view on mobile */
            .dispatch-dash-mobile .saved-routes-wrapper {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            .dispatch-dash-mobile .saved-routes-wrapper .routes-list-table.routes-keep-table {
                min-width: 760px !important;
                width: 100% !important;
            }
            .dispatch-dash-mobile .saved-routes-wrapper .routes-list-table.routes-keep-table thead {
                display: table-header-group !important;
            }
            .dispatch-dash-mobile .saved-routes-wrapper .routes-list-table.routes-keep-table tbody {
                display: table-row-group !important;
            }
            .dispatch-dash-mobile .saved-routes-wrapper .routes-list-table.routes-keep-table tbody tr {
                display: table-row !important;
                border-radius: 0 !important;
                border: none !important;
                box-shadow: none !important;
                margin: 0 !important;
                overflow: visible !important;
                background: transparent !important;
            }
            .dispatch-dash-mobile .saved-routes-wrapper .routes-list-table.routes-keep-table tbody td {
                display: table-cell !important;
                border-bottom: 1px solid #f3f4f6 !important;
                padding: 12px 10px !important;
                vertical-align: middle !important;
            }
            .dispatch-dash-mobile .saved-routes-wrapper .routes-list-table.routes-keep-table tbody td[data-label]::before {
                display: none !important;
            }
        }
        @media (min-width: 768px) {
            .routes-list-table { min-width: 560px; }
        }
        @media (max-width: 992px) {
            .main-content-wrapper {
                padding: 1rem !important;
            }
            .page-shell {
                padding-left: 0;
            }
            .editor-topbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .editor-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .section-nav {
                position: static;
            }
            .section-nav ul {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            .section-nav li + li {
                margin-top: 0;
            }
            .form-row {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-bottom: 20px;
            }
            .field-label {
                padding-top: 0;
                font-size: 13px;
            }
            .btn-save {
                width: 100%;
                padding: 14px 24px;
            }
        }
        @media (max-width: 992px) {
            html body .main-content,
            html body .content-wrapper,
            html body main,
            html body.dashboard .main-content,
            html body .header,
            html body .admin-header,
            html body .employee-header {
                margin-left: 0 !important;
                width: 100% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
        }

        /* Currency + amount: seamless Bootstrap input-group (avoid Tailwind radius/border gaps) */
        .dispatch-route-price-group.input-group {
            flex-wrap: nowrap;
            align-items: stretch;
        }
        .dispatch-route-price-group .form-select {
            flex: 0 0 auto;
            width: auto;
            min-width: 6.5rem;
            max-width: 8.5rem;
        }
        .dispatch-route-price-group .form-control {
            flex: 1 1 auto;
            min-width: 0;
        }

        /* Mobile success: bottom sheet (half-screen style) */
        @media (max-width: 767.98px) {
            body.route-success-sheet-open {
                overflow: hidden;
                touch-action: none;
            }
        }
        .route-success-sheet-backdrop {
            display: none;
        }
        .route-success-sheet {
            display: none;
        }
        @media (max-width: 767.98px) {
            .route-success-sheet-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.48);
                z-index: 1990;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.28s ease, visibility 0.28s ease;
            }
            .route-success-sheet-backdrop.is-visible {
                opacity: 1;
                visibility: visible;
            }
            .route-success-sheet {
                display: block;
                position: fixed;
                left: 0.75rem;
                right: 0.75rem;
                bottom: calc(72px + env(safe-area-inset-bottom, 0px));
                max-height: min(52vh, 360px);
                background: #fff;
                border-radius: 1rem;
                box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
                z-index: 2000;
                transform: translateY(105%);
                transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
                padding-bottom: 1rem;
                overflow-y: auto;
            }
            .route-success-sheet.is-visible {
                transform: translateY(0);
            }
            .route-success-sheet-handle {
                width: 40px;
                height: 5px;
                background: #d1d5db;
                border-radius: 999px;
                margin: 12px auto 8px;
                flex-shrink: 0;
            }
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

<main class="main-content dispatch-dash-mobile">
    <div class="main-content-wrapper page-shell">
        <div class="editor-shell">
            <div class="editor-topbar">
                <div>
                    <h1 class="m-0 fw-bold text-dark" style="font-size: 1.5rem;">Dispatch Routes</h1>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">Origin-destination pairs and default prices for dispatch notes.</p>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success mb-3 d-none d-md-block"><?= htmlspecialchars($success) ?></div>
                <div class="d-md-none route-success-sheet-backdrop" id="routeSuccessBackdrop" aria-hidden="true"></div>
                <div class="d-md-none route-success-sheet" id="routeSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="routeSuccessSheetTitle">
                    <div class="route-success-sheet-handle" aria-hidden="true"></div>
                    <div class="px-4 pb-4 pt-0 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-check fa-lg"></i>
                        </div>
                        <h2 id="routeSuccessSheetTitle" class="h5 fw-bold text-gray-900 mb-2">Route saved</h2>
                        <p class="text-gray-600 mb-4 small"><?= htmlspecialchars($success) ?></p>
                        <button type="button" class="btn btn-save w-100 py-2 rounded-pill fw-semibold border-0" id="routeSuccessSheetDismiss">
                            OK
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#section-create-route" class="is-active">Create Route</a></li>
                    </ul>
                </aside>

                <section class="editor-main">
                    <div id="section-create-route" class="editor-section">
                        <div class="section-header">
                            <h2 class="section-title">Create Route</h2>
                            <p class="section-subtitle">Add a from/to pair, amount, and currency.</p>
                        </div>

                        <form method="POST">
                            <div class="form-row">
                                <label class="field-label">From</label>
                                <input type="text" name="route_from" class="form-input" required placeholder="e.g. Warehouse A">
                            </div>

                            <div class="form-row">
                                <label class="field-label">To</label>
                                <input type="text" name="route_to" class="form-input" required placeholder="e.g. Branch North">
                            </div>

                            <div class="form-row">
                                <label class="field-label">Price</label>
                                <div class="input-group dispatch-route-price-group">
                                    <select name="price_currency" class="form-select" aria-label="Currency">
                                        <?php foreach (dispatch_route_currency_options() as $code => $label): ?>
                                            <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === 'TZS' ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" step="0.01" name="route_price" class="form-control" required placeholder="0.00">
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-save">
                                    <i class="fas fa-save me-2"></i>Save Route
                                </button>
                            </div>
                        </form>
                    </div>

                </section>
            </div>
        </div>
    </div>
</main>

<?php if ($success !== ''): ?>
<script>
(function () {
    var sheet = document.getElementById('routeSuccessSheet');
    var backdrop = document.getElementById('routeSuccessBackdrop');
    var btn = document.getElementById('routeSuccessSheetDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('route-success-sheet-open');
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
        document.body.classList.remove('route-success-sheet-open');
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

    if (btn) {
        btn.addEventListener('click', function () {
            window.location.href = 'saved_routes.php?module=dispatch';
        });
    }
    backdrop.addEventListener('click', closeSheet);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) closeSheet();
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../stock/includes/footer.php'; ?>
