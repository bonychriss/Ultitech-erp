<?php
/**
 * Bank Reconciliation — React shell (Coming Soon).
 * accounting/reconciliation.php
 */
require_once __DIR__ . '/reconciliation-ui/rc-lib.php';

rcRequireAccess();

$boot = rcBuildBootPayload();
$module = trim((string) ($boot['module'] ?? 'balances'));

$page_title = 'Bank Reconciliation';
$employeeHeaderTitle = '';
$active_module = $module === 'balances' ? 'balances' : $module;
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--rc-desk';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-rc-desk';

$uiDir = __DIR__ . '/reconciliation-ui';
$distIndex = $uiDir . '/dist/index.html';

if (!is_file($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Bank Reconciliation</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Bank Reconciliation</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>accounting/reconciliation-ui/</code>.</p>';
    echo '</body></html>';
    exit;
}

$distHtml = file_get_contents($distIndex);
preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
$jsFile = $jsMatch[1] ?? '';
$cssFile = $cssMatch[1] ?? '';

if ($jsFile === '' || $cssFile === '') {
    http_response_code(503);
    die('Built assets not found. Run npm run build in accounting/reconciliation-ui/.');
}

$assetBase = rcDeskPublicUrl('reconciliation-ui/dist/assets/');
$cssPath = $uiDir . '/dist/assets/' . $cssFile;
$jsPath = $uiDir . '/dist/assets/' . $jsFile;
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();

$rcHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assetBase . $cssFile . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__RECON_BOOT__ = ' . json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';

include __DIR__ . '/../modules/balances/includes/header.php';
?>

<style>
body.page-rc-desk.dashboard .layout-main-wrapper {
    align-items: stretch;
}

body.page-rc-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}

body.page-rc-desk,
body.page-rc-desk.dashboard,
body.page-rc-desk .layout-main-wrapper,
body.page-rc-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}

body.page-rc-desk .employee-header.employee-header--rc-desk {
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

body.page-rc-desk .employee-header--rc-desk::after {
    display: none !important;
}

body.page-rc-desk .employee-header--rc-desk .header-content {
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    background: transparent !important;
}

body.page-rc-desk main.main-content.rc-react-root {
    flex: 1 1 auto;
    min-height: 50vh;
    background: #f8fafc !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
}

main.main-content.rc-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}

@media (max-width: 767.98px) {
    body.page-rc-desk .employee-header.employee-header--rc-desk {
        padding: 0 0.75rem !important;
    }

    body.page-rc-desk main.main-content.rc-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
</style>

<main class="main-content rc-react-root">
    <noscript>
        <div class="rc-boot-error" role="alert">
            <strong>JavaScript is required</strong>
            <p>Enable JavaScript to view Bank Reconciliation.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assetBase . $jsFile . '?v=' . $jsVersion) ?>"></script>

<?php include __DIR__ . '/../modules/balances/includes/footer.php'; ?>
