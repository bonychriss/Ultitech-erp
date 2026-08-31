<?php

declare(strict_types=1);

/**
 * React shell for Create Delivery Note.
 */
$deliveriesRoot = dirname(__DIR__);
require_once $deliveriesRoot . '/config/database.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/load-create-delivery-note-data.php';

requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'deliveries';
}
$_SESSION['active_module'] = 'deliveries';

$assets = deliveriesUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Create Delivery Note</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Create Delivery Note</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>deliveries/deliveries-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initPayload = deliveries_load_create_delivery_note_payload($pdo);
$initData = $initPayload['data'] ?? [];

$dlvConfig = [
    'page' => 'create-delivery-note',
    'createNoteInitUrl' => $assets['createNoteInitUrl'],
    'createNoteSubmitUrl' => $assets['createNoteSubmitUrl'],
    'data' => $initData,
];

$page_title = 'Create Delivery Note';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
$GLOBALS['_erp_header_style_linked'] = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Deliveries</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__DELIVERIES_CFG__ = <?= json_encode($dlvConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        :root { --bg-body: #f8fafc; }
        body.dashboard.page-create-delivery-note,
        body.dashboard.page-create-delivery-note .layout-main-wrapper,
        body.dashboard.page-create-delivery-note .layout-main-wrapper > .flex-grow-1 {
            background: #f8fafc !important;
            min-height: 100vh;
        }
        body.dashboard.page-create-delivery-note { font-family: 'Inter', sans-serif; }
        .main-content.create-delivery-note-react-root {
            flex: 1 1 auto;
            width: 100% !important;
            max-width: none !important;
            min-height: calc(100vh - 80px);
            padding: 0.5rem 1.25rem 2.5rem !important;
            box-sizing: border-box;
            background: #f8fafc !important;
        }
        .main-content.create-delivery-note-react-root #root {
            width: 100%;
            max-width: none;
            margin: 0;
            min-height: 100%;
        }
        @media (max-width: 1024px) { .main-content.create-delivery-note-react-root { padding: 1rem 0.875rem 1.5rem !important; } }
        @media (max-width: 767.98px) { .main-content.create-delivery-note-react-root { padding: 0.875rem 0.75rem 1.5rem !important; min-height: calc(100vh - 64px); } }
        body.dashboard.page-create-delivery-note .header,
        body.dashboard.page-create-delivery-note .employee-header {
            background: #f8fafc !important;
            border: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body class="dashboard page-create-delivery-note">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = $initData['urls']['dashboard'] ?? '/select-module.php';
require_once dirname($deliveriesRoot) . '/includes/header_employee.php';
?>

<main class="main-content create-delivery-note-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to use Delivery Logistics.</div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
