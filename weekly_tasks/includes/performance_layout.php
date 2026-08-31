<?php
/**
 * Performance module layout � ERP native sidebar (same pattern as Sales).
 */

function perf_layout_start(string $activePage, string $pageSubtitle = '', bool $showSubnav = true, string $headerActionsHtml = ''): void
{
    global $weekOffset, $weekDisplayLabel, $prevWeek, $perfCss, $modulesLink;

    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'tasks';
    }
    $_SESSION['active_module'] = 'tasks';

    $currentScript = basename($_SERVER['PHP_SELF'] ?? 'ai_assistant.php');
    $weekBase = $currentScript . '?module=tasks';

    $rootPath = '../';
    $logoBase = '../';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderTitle = 'Performance';
    $employeeHeaderSubtitle = htmlspecialchars($pageSubtitle);

    ob_start();
    ?>
    <div class="perf-header-toolbar">
        <div class="dropdown perf-week-dropdown">
            <button class="btn perf-week-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-calendar3"></i>
                <span><?= htmlspecialchars($weekDisplayLabel) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item" href="<?= htmlspecialchars($weekBase . '&week=' . (int) $prevWeek) ?>">Previous week</a></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars($weekBase) ?>">This week</a></li>
                <?php if ($weekOffset < 0): ?>
                <li><a class="dropdown-item" href="<?= htmlspecialchars($weekBase . '&week=' . (int) ($weekOffset + 1)) ?>">Next week</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?= $headerActionsHtml ?>
    </div>
    <?php
    $perfToolbarHtml = ob_get_clean();
    $employeeHeaderRightHtml = '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance - ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php if (function_exists('app_url')): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/css/style.css')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($perfCss) ?>?v=<?= time() ?>">
</head>
<body class="page-performance-tasks perf-app">
<?php include __DIR__ . '/../../includes/header_employee.php'; ?>

<?php if (!empty($perfToolbarHtml)): ?>
<div class="perf-page-toolbar" aria-label="Performance actions">
    <?= $perfToolbarHtml ?>
</div>
<?php endif; ?>

<div class="main-content perf-shell">
    <?php
}

function perf_layout_end(): void
{
    ?>
</div><!-- .main-content.perf-shell -->
    </div><!-- .flex-grow-1 -->
</div><!-- .layout-main-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

function perf_medal_rank(int $index): string
{
    if ($index === 0) {
        return '<span class="perf-medal perf-medal--gold"><i class="bi bi-award-fill"></i></span>';
    }
    if ($index === 1) {
        return '<span class="perf-medal perf-medal--silver"><i class="bi bi-award-fill"></i></span>';
    }
    if ($index === 2) {
        return '<span class="perf-medal perf-medal--bronze"><i class="bi bi-award-fill"></i></span>';
    }
    return '<span class="perf-rank-num">' . ($index + 1) . '</span>';
}

function perf_score_class(int $pct): string
{
    if ($pct >= 80) {
        return 'high';
    }
    if ($pct >= 70) {
        return 'mid';
    }
    return 'low';
}
