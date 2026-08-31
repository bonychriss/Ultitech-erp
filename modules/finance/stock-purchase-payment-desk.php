<?php
/**
 * Stock Purchase Payment Desk — React shell (balances / finance).
 * modules/finance/stock-purchase-payment-desk.php
 */
require_once __DIR__ . '/stock-purchase-payment-desk-ui/sppd-lib.php';

sppdRequireAccess();

$tab = sppdNormalizeTab((string) ($_GET['tab'] ?? 'needs_classification'));
$module = trim((string) ($_GET['module'] ?? ''));

$page_title = 'Purchase Payment';
$employeeHeaderTitle = 'Purchase Payment';
if ($module === 'balances') {
    $active_module = 'balances';
}

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--sppd-desk';
$employeeHeaderRightHtml = '';
$bodyExtraClass = 'page-sppd-desk';

$uiDir = __DIR__ . '/stock-purchase-payment-desk-ui';
$distIndex = $uiDir . '/dist/index.html';

if (!is_file($distIndex)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Stock Purchase Payment Desk</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Stock Purchase Payment Desk</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/finance/stock-purchase-payment-desk-ui/</code>.</p>';
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
    die('Built assets not found. Run npm run build in modules/finance/stock-purchase-payment-desk-ui/.');
}

$assetBase = sppdDeskPublicUrl('stock-purchase-payment-desk-ui/dist/assets/');
$apiUrl = sppdDeskPublicUrl('stock-purchase-payment-desk-ui/api/index.php');

$cssPath = $uiDir . '/dist/assets/' . $cssFile;
$jsPath = $uiDir . '/dist/assets/' . $jsFile;
$cssVersion = is_file($cssPath) ? (string) filemtime($cssPath) : (string) time();
$jsVersion = is_file($jsPath) ? (string) filemtime($jsPath) : (string) time();

$sppdHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assetBase . $cssFile . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') . '">'
    . "\n" . '<script>window.__SPPD_API_BASE__ = ' . json_encode($apiUrl, JSON_UNESCAPED_SLASHES) . ';</script>';

$financeHeaderPath = __DIR__ . '/includes/header.php';
if (is_file($financeHeaderPath)) {
    include $financeHeaderPath;
} else {
    include __DIR__ . '/../../includes/header_employee.php';
}
?>

<style>
body.page-sppd-desk.dashboard .layout-main-wrapper {
    align-items: stretch;
}

body.page-sppd-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}

body.page-sppd-desk,
body.page-sppd-desk.dashboard,
body.page-sppd-desk .layout-main-wrapper,
body.page-sppd-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}

body.page-sppd-desk .employee-header.employee-header--sppd-desk {
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

body.page-sppd-desk .employee-header--sppd-desk::after {
    display: none !important;
}

body.page-sppd-desk .employee-header--sppd-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
}

body.page-sppd-desk .employee-header--sppd-desk .employee-header-page-title {
    white-space: nowrap;
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
}

body.page-sppd-desk .employee-header--sppd-desk .header-content {
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    background: transparent !important;
}

body.page-sppd-desk .employee-header--sppd-desk .header-right.header-actions-tray {
    gap: 12px;
}

body.page-sppd-desk main.main-content.sppd-react-root {
    flex: 1 1 auto;
    min-height: 50vh;
    background: #f8fafc !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    width: 100% !important;
    max-width: none !important;
    box-sizing: border-box;
}
main.main-content.sppd-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-sppd-desk .employee-header.employee-header--sppd-desk {
        padding: 0 0.75rem !important;
    }

    body.page-sppd-desk main.main-content.sppd-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }

    body.page-sppd-desk .employee-header--sppd-desk .header-content {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }

    body.page-sppd-desk .employee-header--sppd-desk .header-left {
        position: static !important;
        top: auto !important;
        left: auto !important;
        flex: 0 0 auto;
        order: 1;
    }

    body.page-sppd-desk .employee-header--sppd-desk .employee-header-page-heading {
        order: 2;
        flex: 1 1 auto;
        min-width: 0;
        margin-left: 0 !important;
        padding-left: 0 !important;
        padding-right: 0.25rem !important;
    }

    body.page-sppd-desk .employee-header--sppd-desk .employee-header-page-title {
        font-size: 1rem !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body.page-sppd-desk .employee-header--sppd-desk .header-right.header-actions-tray {
        order: 3;
        flex: 0 0 auto;
        gap: 0.5rem !important;
        margin-left: auto !important;
    }

    body.page-sppd-desk .employee-header--sppd-desk .employee-header-menu-btn {
        margin-right: 0 !important;
    }

    html[data-theme="dark"] body.page-sppd-desk .employee-header--sppd-desk .employee-header-menu-btn {
        color: #e2e8f0 !important;
    }
}

.sppd-modal.sppd-pay-modal .sppd-pay-modal-title,
.sppd-modal.sppd-pay-modal .sppd-pay-section-title,
.sppd-modal.sppd-pay-modal .sppd-pay-field label {
    color: #000 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-modal-title,
html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-section-title,
html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-field label {
    color: #f8fafc !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-modal-head {
    background: #1e293b !important;
    border-bottom-color: #334155 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-modal-foot {
    background: #0f172a !important;
    border-top-color: #334155 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-field input,
html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-field select {
    background: #0f172a !important;
    border-color: #475569 !important;
    color: #e2e8f0 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-pay-upload {
    background: #0f172a !important;
    border-color: #475569 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-pay-modal .sppd-btn-secondary {
    background: #334155 !important;
    border-color: #475569 !important;
    color: #e2e8f0 !important;
}

html[data-theme="dark"] body.page-sppd-desk,
html[data-theme="dark"] body.page-sppd-desk.dashboard,
html[data-theme="dark"] body.page-sppd-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-sppd-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-sppd-desk main.main-content.sppd-react-root {
    background: #0f172a !important;
}

html[data-theme="dark"] body.page-sppd-desk .employee-header.employee-header--sppd-desk {
    background: #0f172a !important;
}

html[data-theme="dark"] body.page-sppd-desk .employee-header--sppd-desk .employee-header-page-title {
    color: #f8fafc !important;
}

body.page-sppd-desk .sppd-search-field {
    overflow: hidden;
}

body.page-sppd-desk .sppd-search-field input[type="search"].sppd-search-input {
    border: none !important;
    border-radius: 9999px !important;
    outline: none !important;
    box-shadow: none !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal .sppd-modal-head {
    background: #1e293b !important;
    border-bottom-color: #334155 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal .sppd-kpi-trace-table th {
    background: #0f172a !important;
    color: #f8fafc !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal .sppd-kpi-trace-table td {
    background: #1e293b !important;
    color: #e2e8f0 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal .sppd-kpi-trace-po-no {
    color: #93c5fd !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal .sppd-kpi-trace-table .sppd-amt-paid {
    color: #4ade80 !important;
}

html[data-theme="dark"] body.page-sppd-desk .sppd-modal.sppd-kpi-trace-modal .sppd-kpi-trace-table .sppd-amt-due {
    color: #f87171 !important;
}
</style>

<main class="main-content sppd-react-root">
    <noscript>
        <div class="sppd-boot-error" role="alert">
            <strong>JavaScript is required</strong>
            <p>Enable JavaScript to use the Stock Purchase Payment Desk.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assetBase . $jsFile . '?v=' . $jsVersion) ?>"></script>

<?php
$financeFooterPath = __DIR__ . '/includes/footer.php';
if (is_file($financeFooterPath)) {
    include $financeFooterPath;
} else {
    include __DIR__ . '/../../includes/footer.php';
}
