<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (isset($_GET['module']) && $_GET['module'] !== '') {
    $_SESSION['active_module'] = (string) $_GET['module'];
} elseif (!isset($_SESSION['active_module']) || $_SESSION['active_module'] === 'dashboard') {
    $_SESSION['active_module'] = 'attendance';
}

if (function_exists('reconcileStalePaymentVoucherActionNotificationsForUser')) {
    try {
        reconcileStalePaymentVoucherActionNotificationsForUser();
    } catch (Throwable $e) {
        /* ignore */
    }
}

$allItems = getNotificationCentreFeedPaged(120, 0);

$ncPeriodOf = static function ($createdAt): string {
    $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime((string) $createdAt);
    if (!$ts) {
        return 'earlier';
    }
    $startToday = strtotime('today');
    $startYesterday = strtotime('yesterday');
    if ($ts >= $startToday) {
        return 'today';
    }
    if ($ts >= $startYesterday) {
        return 'yesterday';
    }

    return 'earlier';
};

$sections = [
    'today' => [],
    'yesterday' => [],
    'earlier' => [],
];
foreach ($allItems as $row) {
    $period = $ncPeriodOf($row['created_at'] ?? '');
    if ($period === 'today') {
        $sections['today'][] = $row;
    } elseif ($period === 'yesterday') {
        $sections['yesterday'][] = $row;
    } else {
        $sections['earlier'][] = $row;
    }
}

$countUnread = 0;
foreach ($allItems as $row) {
    if (empty($row['is_read']) || (int) $row['is_read'] === 0) {
        $countUnread++;
    }
}

$markAllApi = function_exists('app_url') ? app_url('/includes/notifications_api.php') : '/includes/notifications_api.php';
$settingsUrl = function_exists('company_url')
    ? company_url('employee/account.php')
    : (function_exists('app_url') ? app_url('/employee/account.php') : '/employee/account.php');

$sectionLabels = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'earlier' => 'Earlier',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/style.css') : 'assets/css/style.css', ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" />
    <link rel="stylesheet" href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/notifications-centre.css') : 'assets/css/notifications-centre.css', ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" />
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
<body class="dashboard has-mobile-footer page-notifications-wide">
<?php if (isAdmin()): ?>
    <?php require __DIR__ . '/includes/header_admin.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/includes/header_employee.php'; ?>
<?php endif; ?>

<main class="main-content">
    <div class="nc-page nc-page--wide">
        <div class="nc-panel nc-panel--wide">
            <header class="nc-page-header nc-page-header--wide">
                <h1 class="nc-page-title">Notifications</h1>
                <div class="nc-page-header-actions">
                    <?php if ($countUnread > 0): ?>
                        <form class="m-0" method="post" action="<?= htmlspecialchars($markAllApi) ?>" onsubmit="fetch(this.action,{method:'POST',body:new URLSearchParams({action:'mark_all_read'}),credentials:'same-origin'}).then(function(){location.reload();}); return false;">
                            <button type="submit" class="nc-mark-all-btn">Mark all as read</button>
                        </form>
                    <?php else: ?>
                        <span class="nc-mark-all-btn is-disabled">Mark all as read</span>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8') ?>" class="nc-settings-btn" title="Settings" aria-label="Settings">
                        <i class="fas fa-cog" aria-hidden="true"></i>
                    </a>
                </div>
            </header>

            <div class="nc-list nc-list--wide">
                <?php if ($allItems === []): ?>
                    <div class="nc-empty">
                        <div class="nc-empty-icon" aria-hidden="true"><i class="far fa-bell"></i></div>
                        <p class="mb-0 fw-semibold">You&rsquo;re all caught up</p>
                        <p class="mb-0 small">No notifications to show right now.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sectionLabels as $key => $label): ?>
                        <?php
                        $ncItems = $sections[$key] ?? [];
                        if ($ncItems === []) {
                            continue;
                        }
                        ?>
                        <section class="nc-section" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                            <h2 class="nc-section-heading"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="nc-section-list">
                                <?php require __DIR__ . '/includes/partials/notifications_centre_cards.php'; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

</body>
</html>
