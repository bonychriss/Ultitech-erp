<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (isset($_GET['module']) && $_GET['module'] !== '') {
    $_SESSION['active_module'] = (string) $_GET['module'];
} elseif (!isset($_SESSION['active_module']) || $_SESSION['active_module'] === 'dashboard') {
    $_SESSION['active_module'] = 'attendance';
}

$filter = isset($_GET['filter']) ? strtolower((string) $_GET['filter']) : 'all';
if (!in_array($filter, ['all', 'unread', 'mentions'], true)) {
    $filter = 'all';
}

$allItems = getNotificationCentreFeedPaged(80, 0);
$countAll = count($allItems);
$countUnread = 0;
$countMentions = 0;
foreach ($allItems as $row) {
    if (empty($row['is_read']) || (int) $row['is_read'] === 0) {
        $countUnread++;
    }
    $blob = strtolower((string) ($row['title'] ?? '') . ' ' . ($row['message'] ?? ''));
    if (strpos($blob, '@') !== false || strpos($blob, 'mention') !== false) {
        $countMentions++;
    }
}

$ncItems = $allItems;
if ($filter === 'unread') {
    $ncItems = array_values(array_filter($allItems, static function ($row) {
        return empty($row['is_read']) || (int) $row['is_read'] === 0;
    }));
} elseif ($filter === 'mentions') {
    $ncItems = array_values(array_filter($allItems, static function ($row) {
        $blob = strtolower((string) ($row['title'] ?? '') . ' ' . ($row['message'] ?? ''));

        return strpos($blob, '@') !== false || strpos($blob, 'mention') !== false;
    }));
}

$unread = getTotalHeaderUnreadNotificationCount();
$markAllApi = function_exists('app_url') ? app_url('/includes/notifications_api.php') : '/includes/notifications_api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Notifications</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>" />
    <link rel="stylesheet" href="assets/css/notifications-centre.css?v=<?= time() ?>" />
    <style>
        body.dashboard .main-content {
            max-width: 100% !important;
            width: 100% !important;
            padding: 0 !important;
            margin: 0 !important;
            background: #f4f5f7;
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
        <header class="nc-page-header">
            <h1 class="nc-page-title">Notifications</h1>
            <?php if ($countUnread > 0): ?>
                <form class="m-0" method="post" action="<?= htmlspecialchars($markAllApi) ?>" onsubmit="fetch(this.action,{method:'POST',body:new URLSearchParams({action:'mark_all_read'}),credentials:'same-origin'}).then(function(){location.reload();}); return false;">
                    <button type="submit" class="nc-mark-all">Mark all as read</button>
                </form>
            <?php endif; ?>
        </header>

        <nav class="nc-tabs" aria-label="Filter notifications">
            <div class="nc-tabs-inner">
                <a href="?filter=all" class="nc-tab<?= $filter === 'all' ? ' is-active' : '' ?>">
                    All <span class="nc-tab-badge"><?= (int) $countAll ?></span>
                </a>
                <a href="?filter=unread" class="nc-tab<?= $filter === 'unread' ? ' is-active' : '' ?>">
                    Unread <span class="nc-tab-badge"><?= (int) $countUnread ?></span>
                </a>
                <a href="?filter=mentions" class="nc-tab<?= $filter === 'mentions' ? ' is-active' : '' ?>">
                    Mentions <span class="nc-tab-badge"><?= (int) $countMentions ?></span>
                </a>
            </div>
        </nav>

        <div class="nc-list">
            <?php require __DIR__ . '/includes/partials/notifications_centre_cards.php'; ?>
        </div>
    </div>
</main>

</body>
</html>
