<?php
/**
 * VAT Control - React shell (accounting).
 * accounting/vat-control.php
 */
require_once __DIR__ . '/vat-control-ui/vat-lib.php';

try {
    vatRequireAccess();
} catch (Throwable $e) {
    http_response_code(403);
    die(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$page_title = 'VAT Control';
$employeeHeaderTitle = 'VAT Control';
$module = trim((string) ($_GET['module'] ?? ''));
if ($module === 'accounting' || $module === '') {
    $active_module = 'accounting';
}

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--ld-desk';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-ld-desk';

$uiDir = __DIR__ . '/vat-control-ui';
$distIndex = $uiDir . '/dist/index.html';

if (!is_file($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>VAT Control</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>VAT Control</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>accounting/vat-control-ui/</code>.</p>';
    echo '</body></html>';
    exit;
}

$distHtml = file_get_contents($distIndex) ?: '';
preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
$jsFile = $jsMatch[1] ?? '';
$cssFile = $cssMatch[1] ?? '';

if ($jsFile === '' || $cssFile === '') {
    http_response_code(503);
    die('Built assets not found. Run npm run build in accounting/vat-control-ui/.');
}

$assetBase = vatPublicUrl('vat-control-ui/dist/assets/');
$apiUrl = vatPublicUrl('vat-control-ui/api/index.php');
$companySlug = trim((string) ($_GET['company_slug'] ?? $_SESSION['company_slug'] ?? ''));

$cssPath = $uiDir . '/dist/assets/' . $cssFile;
$jsPath = $uiDir . '/dist/assets/' . $jsFile;
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();

$ldHeadMarkup = '<link rel="preconnect" href="https://fonts.googleapis.com">'
    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
    . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
    . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assetBase . $cssFile . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__VAT_API_BASE__ = ' . json_encode($apiUrl, JSON_UNESCAPED_SLASHES) . ';'
    . 'window.__VAT_COMPANY_SLUG__ = ' . json_encode($companySlug, JSON_UNESCAPED_SLASHES) . ';</script>';

include __DIR__ . '/../modules/balances/includes/header.php';
?>

<style>
body.page-ld-desk.dashboard .layout-main-wrapper {
    align-items: stretch;
}

body.page-ld-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}

body.page-ld-desk,
body.page-ld-desk.dashboard,
body.page-ld-desk .layout-main-wrapper,
body.page-ld-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}

body.page-ld-desk .employee-header.employee-header--ld-desk {
    background: #f1f5f9 !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 64px;
    position: relative !important;
    top: auto !important;
}

body.page-ld-desk .employee-header--ld-desk::after {
    display: none !important;
}

body.page-ld-desk .employee-header--ld-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
}

body.page-ld-desk .employee-header--ld-desk .employee-header-page-title {
    white-space: nowrap;
    font-size: 1.35rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    line-height: 1.2 !important;
    font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif !important;
}

body.page-ld-desk .employee-header--ld-desk .header-content {
    padding: 8px 0 !important;
    min-height: 64px;
    background: transparent !important;
    align-items: center !important;
}

body.page-ld-desk main.main-content.ld-react-root {
    flex: 1 1 auto;
    min-height: 50vh;
    background: #f1f5f9 !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
    font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

main.main-content.ld-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}

@media (max-width: 767.98px) {
    body.page-ld-desk .employee-header.employee-header--ld-desk {
        padding: 0 0.75rem !important;
    }

    body.page-ld-desk main.main-content.ld-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}

/* Stronger active sidebar contrast on VAT Control only */
body.page-ld-desk .menu-item.active a,
body.page-ld-desk #native-sidebar .nav-link.active:not(.text-danger),
body.page-ld-desk .sidebar-container .nav.flex-column .nav-link.active:not(.text-danger) {
    background: #ede9fe !important;
    color: #5b21b6 !important;
    font-weight: 700 !important;
    box-shadow: inset 3px 0 0 #6d5df6;
}

body.page-ld-desk .menu-item.active .d-icon .icon,
body.page-ld-desk #native-sidebar .nav-link.active i {
    color: #6d5df6 !important;
    stroke: #6d5df6 !important;
}

body.page-ld-desk .menu-item a:hover,
body.page-ld-desk #native-sidebar .nav-link:hover:not(.text-danger) {
    background: #f8fafc;
}
</style>

<main class="main-content ld-react-root">
    <noscript>
        <div class="ld-boot-error" role="alert">
            <strong>JavaScript is required</strong>
            <p>Enable JavaScript to use VAT Control.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assetBase . $jsFile . '?v=' . $jsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
