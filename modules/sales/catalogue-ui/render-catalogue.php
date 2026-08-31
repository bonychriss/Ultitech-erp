<?php

declare(strict_types=1);

/**
 * React shell for Sales Catalogue.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/load-catalogue-data.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$assets = salesCatalogueUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Sales Catalogue</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Sales Catalogue</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/sales/catalogue-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initPayload = sales_load_catalogue_payload($pdo);
$initData = $initPayload['data'] ?? [];

$catConfig = [
    'initUrl' => $assets['initUrl'],
    'data' => $initData,
];

$page_title = 'Sales Catalogue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> | Sales</title>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= htmlspecialchars(sales_app_url('assets/css/style.css')) ?>" rel="stylesheet">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__CATALOGUE_CFG__ = <?= json_encode($catConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        html:has(body.page-sales-catalogue), body.page-sales-catalogue { background: #f8f9fc !important; }
        body.page-sales-catalogue { font-family: 'Outfit', system-ui, sans-serif; color: #374151; min-height: 100vh; }
        body.page-sales-catalogue .layout-main-wrapper { background: #f8f9fc; width: 100%; }
        body.page-sales-catalogue .layout-main-wrapper > .flex-grow-1 { flex: 1 1 0%; min-width: 0; width: 100%; background: #f8f9fc; }
        body.page-sales-catalogue header.employee-header { background: #f8f9fc !important; box-shadow: none !important; border-bottom: 1px solid rgba(15,23,42,.06); }
        html body .main-content.sales-catalogue-shell { padding: 1rem 1rem 2rem !important; max-width: none !important; width: 100% !important; min-width: 0; background: #f8f9fc; min-height: calc(100vh - 80px); }
        @media (min-width: 993px) { html body .main-content.sales-catalogue-shell { padding: 1.25rem 1.75rem 2rem !important; } }
    </style>
</head>
<body class="page-sales-catalogue text-slate-800 antialiased">
<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>
<div class="main-content sales-catalogue-shell" id="root"></div>
<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
