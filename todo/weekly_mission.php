<?php




require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/weekly_mission_helpers.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'todo';
}

$userName = $_SESSION['full_name'] ?? 'User';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'employee';
$isAdmin = ($userRole === 'admin' || (function_exists('isAdmin') && isAdmin()));
$userInitial = strtoupper(substr($userName, 0, 1));

$scriptNorm = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
if (preg_match('#/ultimate/todo/#i', $scriptNorm)) {
    $rootPath = '../../';
    $logoBase = '../../';
} else {
    $rootPath = '../';
    $logoBase = '../';
}

// Same shell for admin and employees (sidebar + compact top bar + page content).
$hideHeaderCompanyBranding = true;
$employeeHeaderTitle = null;
$employeeHeaderSubtitle = null;
$employeeHeaderCenterHtml = null;
$employeeHeaderRightHtml = null;
$headerPath = __DIR__ . '/../includes/header_employee.php';

$bounds = wm_get_week_bounds();
$weekRangeLabel = date('M j', strtotime($bounds['week_start'])) . ' – ' . date('M j, Y', strtotime($bounds['week_end']));

$apiUrl = wm_api_url();
$cssUrl = function_exists('app_url') ? app_url('/assets/css/weekly-mission.css') : '../assets/css/weekly-mission.css';
$jsUrl = function_exists('app_url') ? app_url('/assets/js/weekly-mission.js') : '../assets/js/weekly-mission.js';
$assetBase = function_exists('app_url') ? app_url('/') : '../';
$tasksUrl = 'index.php?module=todo';
$arimaCssUrl = function_exists('app_url') ? app_url('/assets/css/arima-local.css') : '/assets/css/arima-local.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Mission - Ultimate ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css') ?>?v=<?= time() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl) ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/todo-my-tasks.css') : '../assets/css/todo-my-tasks.css') ?>?v=<?= time() ?>">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard todo-module todo-app wm-page<?= $isAdmin ? ' wm-admin-layout' : '' ?>">
<?php include_once $headerPath; ?>

<main class="wm-shell" id="wmApp">
    <div class="todo-dash">
    <div class="wm-page-head">
        <div>
            <?php if ($isAdmin): ?>
            <h1 class="wm-title">Weekly Mission — Team Review</h1>
            <p class="wm-lead">Select an employee to review the missions they assigned for this week.</p>
            <?php else: ?>
            <h1 class="wm-title">Weekly Mission</h1>
            <p class="wm-lead">Plan your week, complete your duties, and help the team succeed.</p>
            <?php endif; ?>
        </div>
        <div class="wm-week-tools">
            <button type="button" class="wm-icon-btn" id="wmPrevWeek" aria-label="Previous week"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="wm-week-pill" id="wmWeekPill">
                <i class="far fa-calendar"></i>
                <span id="wmWeekLabel"><?= htmlspecialchars($weekRangeLabel) ?></span>
            </button>
            <button type="button" class="wm-icon-btn" id="wmNextWeek" aria-label="Next week"><i class="fas fa-chevron-right"></i></button>
            <button type="button" class="wm-btn-outline" id="wmThisWeek">This Week</button>
        </div>
    </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8" id="wmStatsGrid">
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--today" aria-hidden="true"><i class="fas fa-bullseye"></i></div>
                <div class="kpi-value" id="wmStatTotal">0</div>
                <div class="kpi-label">Total Missions</div>
                <div class="kpi-hint" id="wmHintTotal"><?= $isAdmin ? 'Across all employees' : 'Assigned for the week' ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--done" aria-hidden="true"><i class="fas fa-circle-check"></i></div>
                <div class="kpi-value" id="wmStatCompleted">0</div>
                <div class="kpi-label">Completed</div>
                <div class="kpi-hint" id="wmHintCompleted">0% completed</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--upcoming" aria-hidden="true"><i class="fas fa-clock"></i></div>
                <div class="kpi-value" id="wmStatReview">0</div>
                <div class="kpi-label">Pending Review</div>
                <div class="kpi-hint">Awaiting admin review</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon--week" aria-hidden="true"><i class="fas fa-star"></i></div>
                <div class="kpi-value" id="wmStatScore">0%</div>
                <div class="kpi-label">Performance Score</div>
                <div class="kpi-hint" id="wmHintScore"><?= $isAdmin ? 'Team completion rate' : 'Keep up the great work!' ?></div>
            </div>
        </div>

    <div class="wm-grid">
        <div class="wm-main">
            <?php if (!$isAdmin): ?>
            <div class="wm-notice">
                <i class="fas fa-circle-info"></i>
                Priority is assigned automatically by the system.
            </div>
            <?php endif; ?>

            <?php if (!$isAdmin): ?>
            <section class="wm-panel wm-add-panel" id="wmAddPanel">
                <h2 class="wm-panel-title">Add a Mission</h2>
                <div class="wm-add-row">
                    <input type="text" id="wmTitle" maxlength="255" placeholder="What will you focus on this week?" autocomplete="off">
                    <button type="button" class="wm-btn-add" id="wmAddBtn"><i class="fas fa-plus"></i> Add Mission</button>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
            <section class="wm-panel wm-admin-filters" id="wmAdminFilters">
                <div class="wm-filter-row">
                    <label class="wm-filter-label">
                        <span>Department</span>
                        <select id="wmFilterDept" class="wm-filter-select">
                            <option value="">All departments</option>
                        </select>
                    </label>
                </div>
            </section>

            <section class="wm-panel wm-admin-grid-panel" id="wmAdminGridPanel">
                <div class="wm-panel-head">
                    <h2 class="wm-panel-title mb-0">Team Members</h2>
                    <span class="wm-muted" id="wmTeamMeta">0 employees</span>
                </div>
                <div class="wm-user-grid" id="wmAdminUserGrid"></div>
            </section>

            <section class="wm-panel wm-admin-detail-panel" id="wmAdminDetailPanel" hidden>
                <div class="wm-admin-detail-head">
                    <button type="button" class="wm-btn-outline wm-back-btn" id="wmAdminBack">
                        <i class="fas fa-arrow-left"></i> Back to team
                    </button>
                    <div class="wm-admin-detail-user" id="wmAdminDetailUser"></div>
                </div>
                <div class="wm-table-wrap">
                    <table class="wm-table">
                        <thead>
                            <tr>
                                <th class="col-mission">Mission</th>
                                <th class="col-status">Status</th>
                                <th class="col-remarks">Admin remarks</th>
                                <th class="col-updated">Updated</th>
                                <th class="col-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="wmAdminDetailBody"></tbody>
                    </table>
                </div>
                <div class="wm-table-foot wm-admin-detail-foot">
                    <span id="wmAdminDetailMeta">Showing 0 missions</span>
                    <button type="button" class="wm-btn-primary wm-btn-sm" id="wmAdminSaveRemarks">
                        <i class="fas fa-save"></i> Save remarks
                    </button>
                </div>
            </section>
            <?php else: ?>
            <section class="wm-panel wm-missions-panel">
                <div class="wm-panel-head">
                    <h2 class="wm-panel-title mb-0">Your Missions</h2>
                </div>
                <div class="wm-table-wrap">
                    <table class="wm-table">
                        <thead>
                            <tr>
                                <th class="col-check"></th>
                                <th class="col-mission">Mission</th>
                                <th class="col-status">Status</th>
                                <th class="col-updated">Updated</th>
                                <th class="col-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="wmMissionBody"></tbody>
                    </table>
                </div>
                <div class="wm-table-foot">
                    <span id="wmTableMeta">Showing 0 of 0 missions</span>
                    <a href="#" class="wm-link" id="wmViewAll" hidden>View all missions →</a>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <aside class="wm-rail">
            <section class="wm-panel" id="wmFollowUpPanel"<?= $isAdmin ? ' hidden' : '' ?>>
                <h2 class="wm-panel-title"><i class="fas fa-comment-dots wm-title-icon"></i> Admin Comments / Follow-up</h2>
                <div id="wmFollowUp">
                    <p class="wm-muted">No admin comments for this week yet.</p>
                </div>
                <div class="wm-reply-box">
                    <textarea id="wmReplyText" rows="2" placeholder="Add a reply..."></textarea>
                    <button type="button" class="wm-btn-primary wm-btn-sm" id="wmReplyBtn">Send</button>
                </div>
            </section>

            <section class="wm-panel wm-award-panel"<?= $isAdmin ? ' hidden' : '' ?>>
                <div class="wm-award-head">
                    <i class="fas fa-trophy wm-award-head-icon" aria-hidden="true"></i>
                    <h2 class="wm-award-head-title">Awards &amp; Performance</h2>
                </div>
                <div class="wm-award-body">
                    <div class="wm-award-left">
                        <div class="wm-award-medal" aria-hidden="true"><i class="fas fa-award"></i></div>
                        <div class="wm-award-copy">
                            <strong id="wmAwardLabel">Keep Going</strong>
                            <small id="wmAwardDate">This month</small>
                        </div>
                    </div>
                    <div class="wm-award-divider" aria-hidden="true"></div>
                    <div class="wm-award-right">
                        <span class="wm-award-period">This Month</span>
                        <strong class="wm-award-pct"><span id="wmMonthScore">0</span>%</strong>
                        <span class="wm-award-pct-label">Performance Score</span>
                        <small class="wm-delta-up" id="wmMonthDelta">&#9650; 0% vs last month</small>
                    </div>
                </div>
            </section>

            <div class="wm-rail-bottom">
                <section class="wm-panel wm-chart-panel">
                    <h2 class="wm-panel-title">Team Progress Over Time</h2>
                    <div class="wm-chart-sm"><canvas id="wmChartTeam"></canvas></div>
                </section>

                <section class="wm-panel wm-leaders-panel">
                    <div class="wm-panel-head">
                        <h2 class="wm-panel-title mb-0">Top Performers</h2>
                        <a href="#" class="wm-link" id="wmViewAllLeaderboard">View all</a>
                    </div>
                    <ul class="wm-top-list" id="wmLeaderboard"></ul>
                </section>
            </div>
        </aside>
    </div>

    <footer class="wm-footer">© <?= date('Y') ?> Ultimate ERP. All rights reserved.</footer>

    </div><!-- /.todo-dash -->

    <a href="#" class="wm-help-fab" title="Help" onclick="return false;"><i class="fas fa-question"></i></a>
</main>

<script>
window.WM_CONFIG = {
    apiUrl: <?= json_encode($apiUrl) ?>,
    assetBase: <?= json_encode($assetBase) ?>,
    userId: <?= $userId ?>,
    userName: <?= json_encode($userName) ?>,
    isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
    maxMissions: <?= (int) WM_MAX_MISSIONS ?>
};
</script>
<script src="<?= htmlspecialchars($jsUrl) ?>?v=<?= time() ?>"></script>
</div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
