<?php
/**
 * Attendance desk — React shell.
 * URL: /{company_slug}/attendance/?module=attendance
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Attendance.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'attendance';
}
$active_module = 'attendance';

$attendance = new Attendance($pdo);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$stmtUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$todayRecord = $attendance->getTodayRecord($userId);
$history = $attendance->getHistory($userId);
$stats = $attendance->getStats($userId);
$currentIp = $attendance->getCurrentUserIp();
$isIpAllowed = $attendance->isIpAllowed($currentIp);

$pendingTasks = [];
try {
    if (function_exists('tableExists') && tableExists('user_tasks', $pdo)) {
        $pendingTasksStmt = $pdo->prepare(
            'SELECT id, task_description, task_date FROM user_tasks WHERE user_id = ? AND is_completed = 0 ORDER BY task_date ASC'
        );
        $pendingTasksStmt->execute([$userId]);
        $pendingTasks = $pendingTasksStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('attendance pending tasks: ' . $e->getMessage());
}

$signatureUrl = '';
$sigPath = (string) ($user['signature_path'] ?? '');
if ($sigPath !== '') {
    $fsPath = dirname(__DIR__) . '/' . ltrim($sigPath, '/');
    if (is_file($fsPath)) {
        $signatureUrl = app_url('/' . ltrim($sigPath, '/'));
    }
}

$attClockTz = new DateTimeZone('Africa/Dar_es_Salaam');
$attClockNow = new DateTime('now', $attClockTz);

$jsPath = __DIR__ . '/attendance-ui/dist/assets/attendance-ui.js';
$cssPath = __DIR__ . '/attendance-ui/dist/assets/attendance-ui.css';
$built = is_file($jsPath) && is_file($cssPath);

$assetBase = rtrim(app_url('/attendance'), '/') . '/';
$assetVersion = max(
    (int) (@filemtime($jsPath) ?: 0),
    (int) (@filemtime($cssPath) ?: 0),
    time()
);

$page_title = 'Attendance';
$employeeHeaderTitle = null;
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk page-attendance-desk';

include __DIR__ . '/includes/header.php';

$boot = [
    'page' => 'clock',
    'data' => [
        'timeZone' => 'Africa/Dar_es_Salaam',
        'dateSummary' => $attClockNow->format('l, d M Y'),
        'todayRecord' => $todayRecord ?: null,
        'history' => $history ?: [],
        'stats' => $stats ?: [],
        'pendingTasks' => $pendingTasks,
        'isIpAllowed' => (bool) $isIpAllowed,
        'currentIp' => (string) $currentIp,
        'message' => '',
        'msgType' => '',
        'clockInSuccess' => null,
        'clockOutSuccess' => null,
        'user' => [
            'id' => $userId,
            'name' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
            'signatureUrl' => $signatureUrl,
        ],
        'apiUrl' => $assetBase . 'api/action.php',
        'links' => [
            'modules' => app_url('/select-module.php'),
            'logout' => app_url('/logout.php'),
            'settings' => app_url('/attendance/settings.php'),
        ],
        'wallpapers' => [
            'sunrise' => app_url('/wallpapers/sunrise2.jpg'),
            'sunset' => app_url('/wallpapers/sunset.jpg'),
        ],
    ],
];
$bootJson = json_encode(
    $boot,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
);
if ($bootJson === false) {
    $bootJson = '{"page":"clock","data":{}}';
}
$wallpaperFile = ((int) $attClockNow->format('G')) >= 12 ? 'sunset.jpg' : 'sunrise2.jpg';
$wallpaperUrl = htmlspecialchars(app_url('/wallpapers/' . $wallpaperFile), ENT_QUOTES, 'UTF-8');
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
body.page-attendance-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-attendance-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
@media (min-width: 992px) {
    body.page-attendance-desk .layout-main-wrapper {
        gap: 12px !important;
    }
}
body.page-attendance-desk,
body.page-attendance-desk.dashboard,
body.page-attendance-desk .layout-main-wrapper,
body.page-attendance-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-attendance-desk .employee-header.employee-header--products-desk {
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
}
body.page-attendance-desk .employee-header--products-desk::after { display: none !important; }
body.page-attendance-desk .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.65rem 0 !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem;
}
body.page-attendance-desk .employee-header--products-desk .header-right.header-actions-tray {
    margin-left: auto !important;
}
main.main-content.att-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2.5rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.att-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-attendance-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
    main.main-content.att-react-root { padding: 0 0.75rem 2rem !important; }
}
html[data-theme="dark"] body.page-attendance-desk,
html[data-theme="dark"] body.page-attendance-desk.dashboard,
html[data-theme="dark"] body.page-attendance-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-attendance-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-attendance-desk main.main-content.att-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-attendance-desk .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}

/* Wallpaper only on the clock card */
body.page-attendance-desk .clock-card-v2 {
    background-color: #1c1917 !important;
    background-image:
        linear-gradient(160deg, rgba(15, 23, 42, 0.55) 0%, rgba(15, 23, 42, 0.28) 45%, rgba(28, 25, 23, 0.45) 100%),
        url('<?= $wallpaperUrl ?>') !important;
    background-position: center center !important;
    background-size: cover !important;
    background-repeat: no-repeat !important;
    box-shadow: 0 14px 36px rgba(15, 23, 42, 0.28) !important;
}
</style>

<main class="main-content att-react-root" role="main">
    <script>
        window.__ATTENDANCE_PAGE__ = <?= $bootJson ?>;
    </script>
<?php if ($built): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>attendance-ui/dist/assets/attendance-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root">
        <div class="att-shell" style="padding:24px 0;font-family:Outfit,system-ui,sans-serif;color:#64748b;">
            Loading attendance…
        </div>
    </div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>attendance-ui/dist/assets/attendance-ui.js?v=<?= (int) $assetVersion ?>"></script>
<?php else: ?>
    <div class="att-shell" style="padding:24px 0;font-family:system-ui,sans-serif;">
        <h1 style="font-size:1.25rem;font-weight:700;color:#0f172a;">Attendance UI not built</h1>
        <p style="color:#64748b;margin-top:8px;">
            Run <code>npm install</code> and <code>npm run build</code> inside
            <code>attendance/attendance-ui/</code>.
        </p>
    </div>
<?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
