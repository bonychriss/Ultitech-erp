<?php
/**
 * Header notification bell + dropdown / mobile full-screen panel.
 */
if (!isset($notifApiPath)) {
    $notifApiPath = function_exists('app_url') ? app_url('/api/get_notifications.php') : 'api/get_notifications.php';
}
if (!isset($notificationsListUrl)) {
    $notificationsListUrl = function_exists('app_url') ? app_url('/notifications.php') : '/notifications.php';
}
$unread = isset($unread) ? (int) $unread : 0;
$headerNotifFeed = isset($headerNotifFeed) && is_array($headerNotifFeed) ? $headerNotifFeed : [];
$notifBellLabel = $unread > 0
    ? 'Notifications (' . ($unread > 99 ? '99+' : (string) $unread) . ' unread)'
    : 'Notifications';
$notifDisplayMode = $notifDisplayMode ?? 'header';
$notifIsSidebar = ($notifDisplayMode === 'sidebar');

$ncAllItems = [];
try {
    if (empty($GLOBALS['_ultitech_skip_nc_feed_in_header']) && function_exists('getNotificationCentreFeedPaged')) {
        $ncAllItems = getNotificationCentreFeedPaged(60, 0);
    }
} catch (Throwable $e) {
    error_log('header notifications feed: ' . $e->getMessage());
    $ncAllItems = [];
}
$ncCountAll = count($ncAllItems);
$ncCountUnread = 0;
$ncCountMentions = 0;
foreach ($ncAllItems as $row) {
    if (empty($row['is_read']) || (int) $row['is_read'] === 0) {
        $ncCountUnread++;
    }
    $blob = strtolower((string) ($row['title'] ?? '') . ' ' . ($row['message'] ?? ''));
    if (strpos($blob, '@') !== false || strpos($blob, 'mention') !== false) {
        $ncCountMentions++;
    }
}
$ncItems = $ncAllItems;
$markAllApi = function_exists('app_url') ? app_url('/includes/notifications_api.php') : '/includes/notifications_api.php';
$ncCss = function_exists('app_url') ? app_url('/assets/css/notifications-centre.css') : '/assets/css/notifications-centre.css';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($ncCss) ?>?v=<?= time() ?>">
<style>
.notif-dropdown { display: none; }
.notif-dropdown.open { display: flex; flex-direction: column; }
.header-notif-bell-btn {
    position: relative;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    min-width: 40px;
    min-height: 40px;
    padding: 0;
    margin: 0;
    border: none !important;
    background: transparent !important;
    box-shadow: none !important;
    color: #111827 !important;
    cursor: pointer;
    line-height: 1;
    flex-shrink: 0;
}
.header-notif-bell-btn:hover {
    opacity: 0.8;
    background: transparent !important;
}
.header-notif-bell-inner {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    pointer-events: none;
}
.header-notif-bell-svg {
    width: 22px;
    height: 22px;
    display: block;
    flex-shrink: 0;
    stroke: #111827;
    color: #111827;
}
.header-notif-dot {
    position: absolute;
    top: 7px;
    right: 9px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #7c3aed;
    border: 2px solid #fff;
    box-sizing: content-box;
    pointer-events: none;
    z-index: 1;
}
<?php if ($notifIsSidebar): ?>
.sidebar-notif-item .notif {
    width: 100%;
    position: relative;
}
.sidebar-notif-item .header-notif-bell-btn {
    width: 100%;
    height: auto;
    min-width: 0;
    min-height: 0;
    padding: 0.55rem 1rem;
    justify-content: flex-start;
    gap: 0.75rem;
    border-radius: 0.375rem;
    color: inherit !important;
    font-weight: 500;
    font-size: 0.95rem;
}
.sidebar-notif-item .header-notif-bell-inner {
    width: 1.25rem;
    height: 1.25rem;
    flex-shrink: 0;
}
.sidebar-notif-item .header-notif-bell-svg {
    width: 1.1rem;
    height: 1.1rem;
}
.sidebar-notif-item .header-notif-dot {
    top: 0.45rem;
    left: 1.65rem;
    right: auto;
}
.sidebar-notif-item .sidebar-notif-label {
    flex: 1 1 auto;
    text-align: start;
}
body.sidebar-collapsed .sidebar-notif-item .sidebar-notif-label {
    display: none;
}
@media (min-width: 768px) {
    .sidebar-notif-item .notif-dropdown--v2.notif-dropdown {
        position: fixed !important;
        top: auto !important;
        left: var(--sidebar-notif-left, 260px) !important;
        right: auto !important;
        margin-top: 0 !important;
        z-index: 10120 !important;
    }
}
<?php endif; ?>
</style>
<div class="notif" style="display:flex;align-items:center;">
    <button type="button" class="header-notif-bell-btn<?= $notifIsSidebar ? ' nav-link sidebar-notif-trigger' : '' ?>" data-notifications-url="<?= htmlspecialchars($notificationsListUrl) ?>" onclick="toggleNotif(event)" aria-label="<?= htmlspecialchars($notifBellLabel) ?>" title="<?= htmlspecialchars($notifBellLabel) ?>" aria-expanded="false" aria-controls="notif-dd">
        <span class="header-notif-bell-inner" aria-hidden="true">
            <?php if ($notifIsSidebar): ?>
            <i class="bi bi-bell"></i>
            <?php else: ?>
            <svg class="header-notif-bell-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#111827" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php endif; ?>
        </span>
        <?php if ($notifIsSidebar): ?>
            <span class="sidebar-text sidebar-notif-label">Notifications<?php if ($unread > 0): ?> <span class="badge rounded-pill bg-primary ms-1"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?></span>
        <?php elseif ($unread > 0): ?>
            <span class="header-notif-dot" aria-hidden="true"></span>
        <?php endif; ?>
    </button>
    <div id="notif-dd" class="notif-dropdown notif-dropdown--v2" onclick="event.stopPropagation();" role="dialog" aria-label="Notifications" aria-hidden="true">
        <header class="nc-page-header">
            <h2 class="nc-page-title">Notifications</h2>
            <?php if ($ncCountUnread > 0): ?>
                <form class="m-0" method="post" action="<?= htmlspecialchars($markAllApi) ?>" onsubmit="fetch(this.action,{method:'POST',body:new URLSearchParams({action:'mark_all_read'}),credentials:'same-origin'}).then(function(){location.reload();}); return false;">
                    <button type="submit" class="nc-mark-all">Mark all as read</button>
                </form>
            <?php endif; ?>
        </header>

        <nav class="nc-tabs nc-tabs--dropdown" aria-label="Filter notifications" data-nc-tabs>
            <div class="nc-tabs-inner">
                <button type="button" class="nc-tab is-active" data-nc-filter="all">
                    All <span class="nc-tab-badge"><?= (int) $ncCountAll ?></span>
                </button>
                <button type="button" class="nc-tab" data-nc-filter="unread">
                    Unread <span class="nc-tab-badge"><?= (int) $ncCountUnread ?></span>
                </button>
                <button type="button" class="nc-tab" data-nc-filter="mentions">
                    Mentions <span class="nc-tab-badge"><?= (int) $ncCountMentions ?></span>
                </button>
            </div>
        </nav>

        <div class="nc-list" id="notif-dd-list">
            <?php require __DIR__ . '/notifications_centre_cards.php'; ?>
        </div>

        <p class="nc-view-all-footer mb-0">
            <a href="<?= htmlspecialchars($notificationsListUrl) ?>" class="nc-mark-all">View all notifications</a>
        </p>
    </div>
</div>
<script>
function headerNotifItemClick(ev, el) {
    if (ev) {
        ev.preventDefault();
        ev.stopPropagation();
    }
    var id = el.getAttribute('data-notif-id');
    var href = (el.getAttribute('href') || '').trim();
    if (id) {
        var api = <?= json_encode($notifApiPath, JSON_UNESCAPED_SLASHES) ?> + '?action=read&id=' + encodeURIComponent(id);
        fetch(api, { credentials: 'same-origin' }).catch(function(){});
    }
    if (!href || href === '#') {
        el.classList.remove('is-unread');
        var dot = el.querySelector('.nc-card-unread-dot');
        if (dot) dot.remove();
        var icon = el.querySelector('.nc-card-icon');
        if (icon) icon.classList.add('nc-card-icon--read');
        return;
    }
    if (typeof closeNotif === 'function') {
        closeNotif();
    }
    window.location.assign(href);
}

(function () {
    var list = document.getElementById('notif-dd-list');
    if (!list) return;
    var cards = list.querySelectorAll('.nc-card');
    var tabs = document.querySelectorAll('[data-nc-tabs] [data-nc-filter]');
    if (!tabs.length) return;

    function cardMatches(card, filter) {
        if (filter === 'all') return true;
        if (filter === 'unread') return card.classList.contains('is-unread');
        if (filter === 'mentions') {
            var t = (card.textContent || '').toLowerCase();
            return t.indexOf('@') !== -1 || t.indexOf('mention') !== -1;
        }
        return true;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var filter = tab.getAttribute('data-nc-filter') || 'all';
            tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
            cards.forEach(function (card) {
                card.style.display = cardMatches(card, filter) ? '' : 'none';
            });
        });
    });
})();
</script>
