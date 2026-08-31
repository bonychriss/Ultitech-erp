<?php

require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

require_once __DIR__ . '/system-font-ui/lib.php';

$assets = systemFontUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>System Font</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>System Font</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/personalization/system-font-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$systemFontConfig = systemFontUiBuildInitialConfig($userId);
$systemFontConfig['apiUrl'] = $assets['apiUrl'];

$page_title = 'System Font';
$employeeHeaderTitle = '';
$employeeHeaderSubtitle = null;
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--system-font';
$active_module = 'personalization';
$GLOBALS['_erp_header_style_linked'] = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function () {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <?= erp_get_theme_init_html() ?>
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__SYSTEM_FONT_CFG__ = <?= json_encode($systemFontConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body class="dashboard page-personalization page-system-font">

<?php require_once __DIR__ . '/../../includes/header_employee.php'; ?>

<style>
body.page-system-font.dashboard .layout-main-wrapper {
    align-items: stretch;
}
body.page-system-font.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-system-font,
body.page-system-font.dashboard,
body.page-system-font .layout-main-wrapper,
body.page-system-font .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-system-font .employee-header.employee-header--system-font {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-system-font .employee-header--system-font::after {
    display: none !important;
}
body.page-system-font .employee-header--system-font .header-content {
    padding: 0.65rem 0 0.35rem !important;
    min-height: 0;
}
main.main-content.system-font-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: visible !important;
    box-sizing: border-box;
    background: #f8fafc !important;
    display: flex;
    flex-direction: column;
}
main.main-content.system-font-react-root #root {
    flex: 1 1 auto;
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
html[data-theme="dark"] body.page-system-font,
html[data-theme="dark"] body.page-system-font .layout-main-wrapper,
html[data-theme="dark"] body.page-system-font main.main-content.system-font-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-system-font .employee-header.employee-header--system-font {
    background: #0f172a !important;
}
</style>

<main class="main-content system-font-react-root">
    <noscript>
        <div style="padding:1rem;margin:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to customize your system font.</p>
        </div>
    </noscript>
    <div id="root"></div>
</main>

<script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
<?= erp_get_system_font_body_override_html() ?>
<?= erp_get_dark_theme_body_override_html() ?>
<style id="system-font-react-isolation">
html body.page-system-font #root,
html body.page-system-font #root *:not(.sf-preview-box):not(.sf-preview-box *) {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
}
html body.page-system-font .sf-preview-box,
html body.page-system-font .sf-preview-box *:not(.fa):not(.fas):not(.far):not(.fab):not(.bi) {
    font-family: var(--preview-font-stack, inherit) !important;
}
</style>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
