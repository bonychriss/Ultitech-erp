<?php
/**
 * Admin attendance records — React shell (expenses-desk chrome).
 * URL: /admin/view-attendance.php?module=attendance
 *      /{slug}/admin/view-attendance.php?module=attendance
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../attendance/lib.php';
requireAdmin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
$active_module = 'attendance';

if (function_exists('ensureAttendanceClockModuleSchema')) {
    ensureAttendanceClockModuleSchema();
}

$dateFilter = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$userFilter = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$moduleKey = trim((string) ($_GET['module'] ?? 'attendance'));

$payload = attendanceAdminFetchPayload($pdo, [
    'date' => $dateFilter,
    'user_id' => $userFilter,
    'module' => $moduleKey,
]);

$jsPath = __DIR__ . '/../attendance/attendance-ui/dist/assets/attendance-ui.js';
$cssPath = __DIR__ . '/../attendance/attendance-ui/dist/assets/attendance-ui.css';
$built = is_file($jsPath) && is_file($cssPath);
$assetBase = rtrim(app_url('/attendance'), '/') . '/';
$assetVersion = max(
    (int) (@filemtime($jsPath) ?: 0),
    (int) (@filemtime($cssPath) ?: 0),
    time()
);

if (!$built) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Attendance Records</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Attendance Records</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>attendance/attendance-ui/</code>.</p>';
    echo '</body></html>';
    exit;
}

$page_title = 'Attendance Records';
$employeeHeaderTitle = 'Attendance';
$employeeHeaderSubtitle = null;
$employeeHeaderExtraClass = 'employee-header--exp-desk employee-header--att-desk';
$hideHeaderCompanyBranding = true;
$bodyExtraClass = 'page-exp-desk page-att-desk';
$GLOBALS['_erp_header_style_linked'] = true;

$boot = [
    'page' => 'records',
    'data' => $payload,
];
$bootJson = json_encode(
    $boot,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
);
if ($bootJson === false) {
    $bootJson = '{"page":"records","data":{}}';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - ERP</title>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/css/style.css?v=' . time()), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (function_exists('erp_dark_theme_css_url')): ?>
        <link rel="stylesheet" id="erp-dark-theme" href="<?= htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>
    <script>window.__ATTENDANCE_PAGE__ = <?= $bootJson ?>;</script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>attendance-ui/dist/assets/attendance-ui.css?v=<?= (int) $assetVersion ?>">
</head>
<body class="dashboard page-exp-desk page-att-desk">

<?php include __DIR__ . '/../includes/header_employee.php'; ?>

<style>
body.page-att-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-att-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-att-desk,
body.page-att-desk.dashboard,
body.page-att-desk .layout-main-wrapper,
body.page-att-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-att-desk .employee-header.employee-header--exp-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-att-desk .employee-header--exp-desk::after { display: none !important; }
body.page-att-desk .employee-header--exp-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-att-desk .employee-header--exp-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-att-desk .employee-header--exp-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-att-desk .employee-header--exp-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
    align-self: flex-start;
    overflow: visible;
    flex-shrink: 0;
}
main.main-content.att-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.att-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
@media (max-width: 1280px) {
    main.main-content.att-desk-react-root { padding: 0 1rem 1.75rem !important; }
}
@media (max-width: 1024px) {
    main.main-content.att-desk-react-root { padding: 0 0.875rem 1.5rem !important; }
}
@media (max-width: 767.98px) {
    body.page-att-desk { --header-height: 3rem; }
    body.page-att-desk .employee-header.employee-header--exp-desk { padding: 0 0.75rem !important; }
    body.page-att-desk .employee-header--exp-desk .header-content {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }
    body.page-att-desk .employee-header--exp-desk .employee-header-page-title {
        font-size: 1rem !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    main.main-content.att-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-att-desk,
html[data-theme="dark"] body.page-att-desk.dashboard,
html[data-theme="dark"] body.page-att-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-att-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-att-desk main.main-content.att-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-att-desk .employee-header.employee-header--exp-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-att-desk .employee-header--exp-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>

<main class="main-content att-desk-react-root" role="main">
    <noscript>
        <div style="padding:1rem;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px;">
            <strong>JavaScript is required</strong>
            <p style="margin:0.35rem 0 0;">Enable JavaScript to use Attendance Records.</p>
        </div>
    </noscript>
    <div id="root">
        <div class="att-desk-boot-loading" role="status" aria-live="polite">Loading attendance…</div>
    </div>
</main>

<script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>attendance-ui/dist/assets/attendance-ui.js?v=<?= (int) $assetVersion ?>"></script>

</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>
</html>
