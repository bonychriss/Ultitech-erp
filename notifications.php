<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (isset($_GET['module']) && $_GET['module'] !== '') {
    $_SESSION['active_module'] = (string) $_GET['module'];
} elseif (!isset($_SESSION['active_module']) || $_SESSION['active_module'] === 'dashboard') {
    $_SESSION['active_module'] = 'attendance';
}

$filter = isset($_GET['filter']) ? strtolower((string) $_GET['filter']) : 'today';
if (!in_array($filter, ['today', 'week', 'earlier'], true)) {
    $filter = 'today';
}

if (function_exists('reconcileStalePaymentVoucherActionNotificationsForUser')) {
    try {
        reconcileStalePaymentVoucherActionNotificationsForUser();
    } catch (Throwable $e) {
        /* ignore */
    }
}

$allItems = getNotificationCentreFeedPaged(80, 0);

$ncPeriodOf = static function ($createdAt): string {
    $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime((string) $createdAt);
    if (!$ts) {
        return 'earlier';
    }
    $startToday = strtotime('today');
    $startWeek = strtotime('monday this week');
    if ($startWeek === false || $startWeek > $startToday) {
        $startWeek = strtotime('-6 days', $startToday);
    }
    if ($ts >= $startToday) {
        return 'today';
    }
    if ($ts >= $startWeek) {
        return 'week';
    }

    return 'earlier';
};

$ncItems = [];
foreach ($allItems as $row) {
    $period = $ncPeriodOf($row['created_at'] ?? '');
    if ($filter === 'today' && $period !== 'today') {
        continue;
    }
    if ($filter === 'week' && !in_array($period, ['today', 'week'], true)) {
        continue;
    }
    if ($filter === 'earlier' && $period !== 'earlier') {
        continue;
    }
    $ncItems[] = $row;
}

$countUnread = 0;
foreach ($allItems as $row) {
    if (empty($row['is_read']) || (int) $row['is_read'] === 0) {
        $countUnread++;
    }
}

$unread = getTotalHeaderUnreadNotificationCount();
$markAllApi = function_exists('app_url') ? app_url('/includes/notifications_api.php') : '/includes/notifications_api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AI Notification Center</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>" />
    <link rel="stylesheet" href="assets/css/notifications-centre.css?v=<?= time() ?>" />
    <style>
        body.dashboard .main-content {
            max-width: 100% !important;
            width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
            background: transparent;
        }
    </style>
</head>
<body class="dashboard has-mobile-footer">
<?php if (isAdmin()): ?>
    <?php require __DIR__ . '/includes/header_admin.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/includes/header_employee.php'; ?>
<?php endif; ?>

<main class="main-content">
    <div class="nc-page">
        <div class="nc-panel">
            <header class="nc-page-header">
                <h1 class="nc-page-title">AI Notification Center</h1>
                <?php if ($countUnread > 0): ?>
                    <form class="m-0" method="post" action="<?= htmlspecialchars($markAllApi) ?>" onsubmit="fetch(this.action,{method:'POST',body:new URLSearchParams({action:'mark_all_read'}),credentials:'same-origin'}).then(function(){location.reload();}); return false;">
                        <button type="submit" class="nc-see-all">Mark all</button>
                    </form>
                <?php else: ?>
                    <span class="nc-see-all" style="opacity:0.55;cursor:default;">See All</span>
                <?php endif; ?>
            </header>

            <nav class="nc-tabs" aria-label="Filter notifications">
                <div class="nc-tabs-inner">
                    <a href="?filter=today" class="nc-tab<?= $filter === 'today' ? ' is-active' : '' ?>">Today</a>
                    <a href="?filter=week" class="nc-tab<?= $filter === 'week' ? ' is-active' : '' ?>">This Week</a>
                    <a href="?filter=earlier" class="nc-tab<?= $filter === 'earlier' ? ' is-active' : '' ?>">Earlier</a>
                </div>
            </nav>

            <div class="nc-list">
                <?php require __DIR__ . '/includes/partials/notifications_centre_cards.php'; ?>
            </div>
        </div>
    </div>
</main>

</body>
</html>
