<?php
/**
 * Internal Transfer — React shell (balances).
 * modules/balances/transfer.php
 */
require_once __DIR__ . '/transfer-ui/tf-lib.php';

tfRequireAccess();

$page_title = 'Internal Transfer';
$employeeHeaderTitle = '';
$module = trim((string) ($_GET['module'] ?? ''));
if ($module === 'balances') {
    $active_module = 'balances';
}

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--tf-desk';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-tf-desk';

$uiDir = __DIR__ . '/transfer-ui';
$distIndex = $uiDir . '/dist/index.html';

if (!is_file($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Internal Transfer</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Internal Transfer</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/balances/transfer-ui/</code>.</p>';
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
    die('Built assets not found. Run npm run build in modules/balances/transfer-ui/.');
}

$assetBase = tfDeskPublicUrl('transfer-ui/dist/assets/');
$apiUrl = tfDeskPublicUrl('transfer-ui/api/index.php');

$cssPath = $uiDir . '/dist/assets/' . $cssFile;
$jsPath = $uiDir . '/dist/assets/' . $jsFile;
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();

$tfHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assetBase . $cssFile . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__TF_API_BASE__ = ' . json_encode($apiUrl, JSON_UNESCAPED_SLASHES) . ';</script>';

include __DIR__ . '/includes/header.php';
?>

<style>
body.page-tf-desk.dashboard .layout-main-wrapper {
    align-items: stretch;
}

body.page-tf-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}

body.page-tf-desk,
body.page-tf-desk.dashboard,
body.page-tf-desk .layout-main-wrapper,
body.page-tf-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}

body.page-tf-desk .employee-header.employee-header--tf-desk {
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

body.page-tf-desk .employee-header--tf-desk::after {
    display: none !important;
}

body.page-tf-desk .employee-header--tf-desk .header-content {
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    background: transparent !important;
}

body.page-tf-desk main.main-content.tf-react-root {
    flex: 1 1 auto;
    min-height: 50vh;
    background: #f8fafc !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
}

main.main-content.tf-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}

@media (max-width: 767.98px) {
    body.page-tf-desk .employee-header.employee-header--tf-desk {
        padding: 0 0.75rem !important;
    }

    body.page-tf-desk main.main-content.tf-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
</style>

<main class="main-content tf-react-root">
    <noscript>
        <div class="tf-boot-error" role="alert">
            <strong>JavaScript is required</strong>
            <p>Enable JavaScript to use Internal Transfer.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assetBase . $jsFile . '?v=' . $jsVersion) ?>"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
