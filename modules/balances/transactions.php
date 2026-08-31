<?php
/**
 * Transaction Ledger — React shell (balances).
 * modules/balances/transactions.php
 */
require_once __DIR__ . '/transaction-ledger-ui/tl-lib.php';

tlRequireAccess();

$page_title = 'Transaction Ledger';
$employeeHeaderTitle = '';
$module = trim((string) ($_GET['module'] ?? ''));
if ($module === 'balances') {
    $active_module = 'balances';
}

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--tl-desk';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-tl-desk';

$uiDir = __DIR__ . '/transaction-ledger-ui';
$distIndex = $uiDir . '/dist/index.html';

if (!is_file($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Transaction Ledger</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Transaction Ledger</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/balances/transaction-ledger-ui/</code>.</p>';
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
    die('Built assets not found. Run npm run build in modules/balances/transaction-ledger-ui/.');
}

$assetBase = tlDeskPublicUrl('transaction-ledger-ui/dist/assets/');
$apiUrl = tlDeskPublicUrl('transaction-ledger-ui/api/index.php');

$cssPath = $uiDir . '/dist/assets/' . $cssFile;
$jsPath = $uiDir . '/dist/assets/' . $jsFile;
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();

$tlHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assetBase . $cssFile . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__TL_API_BASE__ = ' . json_encode($apiUrl, JSON_UNESCAPED_SLASHES) . ';</script>';

include __DIR__ . '/includes/header.php';
?>

<style>
body.page-tl-desk.dashboard .layout-main-wrapper {
    align-items: stretch;
}

body.page-tl-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}

body.page-tl-desk,
body.page-tl-desk.dashboard,
body.page-tl-desk .layout-main-wrapper,
body.page-tl-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}

body.page-tl-desk .employee-header.employee-header--tl-desk {
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

body.page-tl-desk .employee-header--tl-desk::after {
    display: none !important;
}

body.page-tl-desk .employee-header--tl-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
}

body.page-tl-desk .employee-header--tl-desk .employee-header-page-title {
    white-space: nowrap;
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
}

body.page-tl-desk .employee-header--tl-desk .header-content {
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    background: transparent !important;
}

body.page-tl-desk .tl-page button.tl-kpi-card,
body.page-tl-desk button.tl-kpi-card {
    border-radius: 18px !important;
}

body.page-tl-desk main.main-content.tl-react-root {
    flex: 1 1 auto;
    min-height: 50vh;
    background: #f8fafc !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
}

main.main-content.tl-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}

@media (max-width: 767.98px) {
    body.page-tl-desk .employee-header.employee-header--tl-desk {
        padding: 0 0.75rem !important;
    }

    body.page-tl-desk main.main-content.tl-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
</style>

<main class="main-content tl-react-root">
    <noscript>
        <div class="tl-boot-error" role="alert">
            <strong>JavaScript is required</strong>
            <p>Enable JavaScript to use the Transaction Ledger.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assetBase . $jsFile . '?v=' . $jsVersion) ?>"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
