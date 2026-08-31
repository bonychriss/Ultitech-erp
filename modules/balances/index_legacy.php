<?php
/**
 * Liquidity Dashboard — React shell (balances).
 * modules/balances/index.php
 */
require_once __DIR__ . '/liquidity-dashboard-ui/ld-lib.php';

ldRequireAccess();

$page_title = 'Liquidity Dashboard';
$employeeHeaderTitle = 'Liquidity Dashboard';
$module = trim((string) ($_GET['module'] ?? ''));
if ($module === 'balances' || $module === '') {
    $active_module = 'balances';
}

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--ld-desk';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-ld-desk';

$uiDir = __DIR__ . '/liquidity-dashboard-ui';
$distIndex = $uiDir . '/dist/index.html';

if (!is_file($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Liquidity Dashboard</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Liquidity Dashboard</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/balances/liquidity-dashboard-ui/</code>.</p>';
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
    die('Built assets not found. Run npm run build in modules/balances/liquidity-dashboard-ui/.');
}

$assetBase = ldDeskPublicUrl('liquidity-dashboard-ui/dist/assets/');
$apiUrl = ldDeskPublicUrl('liquidity-dashboard-ui/api/index.php');

$cssPath = $uiDir . '/dist/assets/' . $cssFile;
$jsPath = $uiDir . '/dist/assets/' . $jsFile;
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();

$ldHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assetBase . $cssFile . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__LD_API_BASE__ = ' . json_encode($apiUrl, JSON_UNESCAPED_SLASHES) . ';</script>';

include __DIR__ . '/includes/header.php';
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
    background: #f8fafc !important;
}

body.page-ld-desk .employee-header.employee-header--ld-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
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
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
}

body.page-ld-desk .employee-header--ld-desk .header-content {
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    background: transparent !important;
}

body.page-ld-desk main.main-content.ld-react-root {
    flex: 1 1 auto;
    min-height: 50vh;
    background: #f8fafc !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
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
</style>

<main class="main-content ld-react-root">
    <noscript>
        <div class="ld-boot-error" role="alert">
            <strong>JavaScript is required</strong>
            <p>Enable JavaScript to use the Liquidity Dashboard.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assetBase . $jsFile . '?v=' . $jsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
