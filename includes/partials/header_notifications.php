<?php
/**
 * Header notification bell + dropdown / mobile full-screen panel.
 */
if (!isset($notifApiPath)) {
    $notifApiPath = function_exists('app_url') ? app_url('/api/get_notifications.php') : 'api/get_notifications.php';
}
// Always resolve the full React notifications centre URL (not settings).
$slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = strtolower(trim((string) getRequestedCompanySlug()));
}
if ($slug === '' && !empty($_SERVER['REQUEST_URI']) && preg_match('#/(?:public_html/)?([A-Za-z0-9-]+)/#', (string) $_SERVER['REQUEST_URI'], $mSlug)) {
    $maybe = strtolower(trim((string) $mSlug[1]));
    if ($maybe !== '' && $maybe !== 'public_html' && !(function_exists('ultitechReservedPathSegments') && in_array($maybe, ultitechReservedPathSegments(), true))) {
        $slug = $maybe;
    }
}
if ($slug !== '' && function_exists('company_url')) {
    $notificationsListUrl = company_url('notifications.php', $slug);
} elseif (function_exists('app_url')) {
    $notificationsListUrl = app_url('/notifications.php');
} else {
    $notificationsListUrl = '/notifications.php';
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
    if (function_exists('reconcileStalePaymentVoucherActionNotificationsForUser')) {
        reconcileStalePaymentVoucherActionNotificationsForUser();
    }
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
    if ((int) ($row['is_read'] ?? 0) === 0) {
        $ncCountUnread++;
    }
    $blob = strtolower((string) ($row['title'] ?? '') . ' ' . ($row['message'] ?? ''));
    if (strpos($blob, '@') !== false || strpos($blob, 'mention') !== false) {
        $ncCountMentions++;
    }
}
$ncItems = $ncAllItems;
$showNotifDot = ($unread > 0 || $ncCountUnread > 0);
    $markAllApi = function_exists('app_url') ? app_url('/api/notifications.php') : '/api/notifications.php';
$ncCss = function_exists('app_url') ? app_url('/assets/css/notifications-centre.css') : '/assets/css/notifications-centre.css';
$ncPanelAlreadyBuilt = !empty($GLOBALS['_ultitech_nc_panel_built']);
$GLOBALS['_ultitech_nc_panel_built'] = true;
?>
<?php if (!$ncPanelAlreadyBuilt): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($ncCss) ?>?v=<?= time() ?>">
<style>
.notif-dropdown:not(.notif-dropdown--v2) { display: none; }
.notif-dropdown:not(.notif-dropdown--v2).open { display: flex; flex-direction: column; }
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
    position: relative;
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
    top: -1px;
    right: -3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
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
    top: -1px;
    right: -3px;
    left: auto;
}
.sidebar-notif-item .sidebar-notif-label {
    flex: 1 1 auto;
    text-align: start;
}
body.sidebar-collapsed .sidebar-notif-item .sidebar-notif-label {
    display: none;
}
<?php endif; ?>
</style>
<?php endif; ?>
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
            <?php if ($showNotifDot): ?>
            <span class="header-notif-dot" aria-hidden="true"></span>
            <?php endif; ?>
        </span>
        <?php if ($notifIsSidebar): ?>
            <span class="sidebar-text sidebar-notif-label">Notifications</span>
        <?php endif; ?>
    </button>
<?php if (!$ncPanelAlreadyBuilt): ?>
    <div id="notif-dd" class="notif-dropdown notif-dropdown--v2" onclick="event.stopPropagation();" role="dialog" aria-label="AI Notification Center" aria-hidden="true">
        <header class="nc-page-header">
            <h2 class="nc-page-title">AI Notification Center</h2>
            <a href="<?= htmlspecialchars($notificationsListUrl) ?>" class="nc-see-all nc-view-all" data-nc-view-all>View All</a>
        </header>

        <nav class="nc-tabs nc-tabs--dropdown" aria-label="Filter notifications" data-nc-tabs>
            <div class="nc-tabs-inner">
                <button type="button" class="nc-tab is-active" data-nc-filter="today">Today</button>
                <button type="button" class="nc-tab" data-nc-filter="week">This Week</button>
                <button type="button" class="nc-tab" data-nc-filter="earlier">Earlier</button>
            </div>
        </nav>

        <div class="nc-list" id="notif-dd-list">
            <?php require __DIR__ . '/notifications_centre_cards.php'; ?>
        </div>
    </div>
</div>
<script>
var ncNotificationsListUrl = <?= json_encode($notificationsListUrl, JSON_UNESCAPED_SLASHES) ?>;
function syncHeaderNotifDot() {
    var inners = document.querySelectorAll('.header-notif-bell-inner');
    var unreadCards = document.querySelectorAll('#notif-dd-list .nc-card.is-unread');
    inners.forEach(function (inner) {
        var dot = inner.querySelector('.header-notif-dot');
        if (unreadCards.length > 0) {
            if (!dot) {
                dot = document.createElement('span');
                dot.className = 'header-notif-dot';
                dot.setAttribute('aria-hidden', 'true');
                inner.appendChild(dot);
            }
        } else if (dot) {
            dot.remove();
        }
    });
}

function headerNotifItemClick(ev, el) {
    if (ev) {
        ev.preventDefault();
        ev.stopPropagation();
    }
    var id = el.getAttribute('data-notif-id');
    var href = (el.getAttribute('href') || '').trim();
    var apiBase = <?= json_encode($notifApiPath, JSON_UNESCAPED_SLASHES) ?>;

    el.classList.remove('is-unread');
    var badge = el.querySelector('.nc-card-unread-label, .nc-card-unread-dot');
    if (badge) badge.remove();
    if (typeof syncHeaderNotifDot === 'function') {
        syncHeaderNotifDot();
    }

    try {
        var coachTitle = (el.getAttribute('data-nc-coach-title') || '').trim();
        var coachBody = (el.getAttribute('data-nc-coach-body') || '').trim();
        if (coachTitle || coachBody) {
            sessionStorage.setItem('ultitech_nc_coach', JSON.stringify({
                title: coachTitle,
                body: coachBody,
                action: (el.getAttribute('data-nc-coach-action') || 'Got it').trim() || 'Got it',
                at: Date.now()
            }));
        }
    } catch (e) {}

    function go() {
        if (!href || href === '#') {
            if (typeof showUltitechNcCoach === 'function') {
                showUltitechNcCoach();
            }
            return;
        }
        if (typeof closeNotif === 'function') {
            closeNotif();
        }
        window.location.assign(href);
    }

    if (!id) {
        go();
        return;
    }

    var api = apiBase + '?action=read&id=' + encodeURIComponent(id);
    var done = false;
    function finish() {
        if (done) return;
        done = true;
        go();
    }
    fetch(api, { credentials: 'same-origin', keepalive: true, method: 'GET', cache: 'no-store' })
        .catch(function () {})
        .then(finish);
    setTimeout(finish, 1200);
}

(function () {
    var list = document.getElementById('notif-dd-list');
    if (!list) return;
    var cards = list.querySelectorAll('.nc-card');
    var tabs = document.querySelectorAll('#notif-dd [data-nc-tabs] [data-nc-filter]');
    if (!tabs.length) return;

    function cardMatches(card, filter) {
        var period = (card.getAttribute('data-nc-period') || 'earlier').toLowerCase();
        if (filter === 'today') return period === 'today';
        if (filter === 'week') return period === 'today' || period === 'yesterday' || period === 'week';
        if (filter === 'earlier') return period === 'earlier';
        return true;
    }

    function applyFilter(filter) {
        var visible = 0;
        cards.forEach(function (card) {
            var show = cardMatches(card, filter);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        var empty = list.querySelector('.nc-empty-filter');
        if (visible === 0) {
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'nc-empty nc-empty-filter';
                empty.innerHTML = '<p class="mb-0 fw-semibold">No notifications here</p><p class="mb-0 small">Try another time range.</p><a class="nc-view-all-btn" data-nc-view-all href="' + String(ncNotificationsListUrl || '').replace(/"/g, '&quot;') + '">View All</a>';
                list.appendChild(empty);
                empty.querySelectorAll('[data-nc-view-all]').forEach(function (el) {
                    el.setAttribute('href', ncNotificationsListUrl);
                    el.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        try { sessionStorage.setItem('ultitech_notif_drawer_open', '0'); } catch (err) {}
                        if (typeof window.setNotifDrawerOpen === 'function') {
                            window.setNotifDrawerOpen(false);
                        }
                        window.location.assign(ncNotificationsListUrl);
                    });
                });
            }
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var filter = tab.getAttribute('data-nc-filter') || 'today';
            tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
            applyFilter(filter);
        });
    });

    applyFilter('today');
})();

(function () {
    var url = typeof ncNotificationsListUrl === 'string' ? ncNotificationsListUrl : '';
    if (!url) return;

    function goToNotificationsCentre(ev) {
        if (ev) {
            ev.preventDefault();
            ev.stopPropagation();
        }
        // Only mark tip seen if it was actually shown.
        if (document.getElementById('nc-va-tip-root')) {
            try { localStorage.setItem('ultitech_nc_viewall_tip_v2', '1'); } catch (e) {}
        }
        try { sessionStorage.setItem('ultitech_notif_drawer_open', '0'); } catch (e) {}
        if (typeof window.setNotifDrawerOpen === 'function') {
            window.setNotifDrawerOpen(false);
        } else {
            var dd = document.getElementById('notif-dd');
            if (dd) {
                dd.classList.remove('open');
                dd.setAttribute('aria-hidden', 'true');
            }
            document.body.classList.remove('notif-panel-open');
        }
        window.location.assign(url);
    }

    function bindViewAll(root) {
        (root || document).querySelectorAll('#notif-dd [data-nc-view-all], #notif-dd .nc-view-all-btn, #notif-dd .nc-view-all').forEach(function (el) {
            if (el.getAttribute('data-nc-view-all-bound') === '1') return;
            el.setAttribute('data-nc-view-all-bound', '1');
            el.setAttribute('href', url);
            el.addEventListener('click', goToNotificationsCentre);
        });
    }

    bindViewAll(document);
    // Re-bind when empty-state markup is injected by filters.
    var list = document.getElementById('notif-dd-list');
    if (list && typeof MutationObserver !== 'undefined') {
        var mo = new MutationObserver(function () { bindViewAll(list); });
        mo.observe(list, { childList: true, subtree: true });
    }
})();
</script>
<?php else: ?>
</div>
<?php endif; ?>
<?php if (empty($GLOBALS['_ultitech_nc_viewall_tip_script'])):
    $GLOBALS['_ultitech_nc_viewall_tip_script'] = true;
    ?>
<script>
(function () {
    if (window.__ultitechNcViewAllTipBooted) return;
    window.__ultitechNcViewAllTipBooted = true;

    var SEEN_KEY = 'ultitech_nc_viewall_tip_v2';
    var showTimer = null;
    var positioned = false;

    function wasSeen() {
        try { return localStorage.getItem(SEEN_KEY) === '1'; } catch (e) { return false; }
    }
    function markSeen() {
        try { localStorage.setItem(SEEN_KEY, '1'); } catch (e) {}
    }

    function findDrawer() {
        return document.getElementById('notif-dd');
    }

    function findTarget(dd) {
        return (dd || findDrawer() || document).querySelector('.nc-page-header [data-nc-view-all], .nc-page-header .nc-view-all');
    }

    function clearTip() {
        var root = document.getElementById('nc-va-tip-root');
        if (root && root.parentNode) root.parentNode.removeChild(root);
        document.querySelectorAll('.nc-va-tip-target').forEach(function (el) {
            el.classList.remove('nc-va-tip-target');
        });
        window.removeEventListener('resize', positionTip);
        positioned = false;
    }

    function positionTip() {
        var root = document.getElementById('nc-va-tip-root');
        if (!root) return;
        var card = root.querySelector('.nc-va-tip-card');
        var beak = root.querySelector('.nc-va-tip-beak');
        var dd = findDrawer();
        var target = findTarget(dd);
        if (!card || !target || !dd) return;

        var drawerRect = dd.getBoundingClientRect();
        var rect = target.getBoundingClientRect();
        var cardW = Math.min(280, Math.max(200, drawerRect.width - 32));
        var left = rect.right - drawerRect.left - cardW;
        if (left < 12) left = 12;
        if (left + cardW > drawerRect.width - 12) {
            left = Math.max(12, drawerRect.width - cardW - 12);
        }
        var top = rect.bottom - drawerRect.top + 12;
        var cardH = card.offsetHeight || 150;
        if (top + cardH > drawerRect.height - 12) {
            top = Math.max(12, rect.top - drawerRect.top - cardH - 12);
            if (beak) {
                beak.style.top = 'auto';
                beak.style.bottom = '-7px';
            }
        } else if (beak) {
            beak.style.top = '-7px';
            beak.style.bottom = 'auto';
        }

        card.style.width = cardW + 'px';
        card.style.left = left + 'px';
        card.style.top = top + 'px';
        card.style.right = 'auto';
        card.style.bottom = 'auto';

        if (beak) {
            var beakX = (rect.left - drawerRect.left) + rect.width / 2 - left - 7;
            beakX = Math.max(16, Math.min(cardW - 30, beakX));
            beak.style.left = beakX + 'px';
            beak.style.right = 'auto';
        }
        positioned = true;
    }

    function dismissTip() {
        markSeen();
        clearTip();
    }

    function showTip() {
        if (wasSeen()) return;
        if (document.getElementById('nc-va-tip-root')) return;
        var dd = findDrawer();
        if (!dd || !dd.classList.contains('open')) return;
        var target = findTarget(dd);
        if (!target) return;

        target.classList.add('nc-va-tip-target');

        var root = document.createElement('div');
        root.id = 'nc-va-tip-root';
        root.className = 'nc-va-tip-root nc-va-tip-root--in-drawer';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-label', 'Tip');
        root.innerHTML =
            '<div class="nc-va-tip-card">' +
                '<span class="nc-va-tip-beak" aria-hidden="true"></span>' +
                '<h3 class="nc-va-tip-title">Open the full Notification Center</h3>' +
                '<p class="nc-va-tip-body">Tap View All to open the full page with every notification, filters, and settings.</p>' +
                '<div class="nc-va-tip-actions">' +
                    '<button type="button" class="nc-va-tip-btn" data-nc-va-dismiss="1">Got it</button>' +
                '</div>' +
            '</div>';

        root.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var t = ev.target;
            if (t && t.getAttribute && t.getAttribute('data-nc-va-dismiss') === '1') {
                dismissTip();
            }
        });
        function onKey(ev) {
            if (ev.key === 'Escape') {
                dismissTip();
                document.removeEventListener('keydown', onKey);
            }
        }
        document.addEventListener('keydown', onKey);

        // Mount inside the drawer so the tip stays visible with the panel.
        if (getComputedStyle(dd).position === 'static') {
            dd.style.position = 'fixed';
        }
        dd.appendChild(root);
        positionTip();
        window.addEventListener('resize', positionTip);
        requestAnimationFrame(function () {
            positionTip();
            setTimeout(positionTip, 50);
        });
    }

    function drawerIsOpen() {
        var dd = findDrawer();
        return !!(dd && dd.classList.contains('open')) || document.body.classList.contains('notif-panel-open');
    }

    function onDrawerChange(isOpen) {
        if (showTimer) {
            clearTimeout(showTimer);
            showTimer = null;
        }
        if (!isOpen) {
            clearTip();
            return;
        }
        // Wait for slide-in animation, then show.
        showTimer = setTimeout(function () {
            showTip();
            // Retry once if target wasn't ready.
            if (!document.getElementById('nc-va-tip-root') && !wasSeen()) {
                showTimer = setTimeout(showTip, 300);
            }
        }, 480);
    }

    function boot() {
        function wrapSetter() {
            var prev = window.setNotifDrawerOpen;
            if (typeof prev !== 'function' || prev.__ncVaTipWrapped) return false;
            var wrapped = function (isOpen) {
                var ok = prev.apply(this, arguments);
                onDrawerChange(!!isOpen);
                return ok;
            };
            wrapped.__ncVaTipWrapped = true;
            window.setNotifDrawerOpen = wrapped;
            return true;
        }

        function watchDrawer(dd) {
            if (!dd || dd.__ncVaTipObserved) return;
            dd.__ncVaTipObserved = true;
            if (typeof MutationObserver !== 'undefined') {
                new MutationObserver(function () {
                    onDrawerChange(dd.classList.contains('open'));
                }).observe(dd, { attributes: true, attributeFilter: ['class'] });
            }
        }

        function attach() {
            wrapSetter();
            watchDrawer(findDrawer());
            if (drawerIsOpen()) onDrawerChange(true);
        }

        attach();
        // Panel may be created/moved after boot.
        var tries = 0;
        var wait = setInterval(function () {
            tries += 1;
            wrapSetter();
            watchDrawer(findDrawer());
            if (tries > 60) clearInterval(wait);
        }, 100);

        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () {
                watchDrawer(findDrawer());
            }).observe(document.body, { childList: true, subtree: false });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    // Also boot after delayed scripts that define setNotifDrawerOpen.
    window.addEventListener('load', function () {
        if (drawerIsOpen() && !wasSeen() && !document.getElementById('nc-va-tip-root')) {
            onDrawerChange(true);
        }
    });
})();
</script>
<?php endif; ?>
<?php if (empty($GLOBALS['_ultitech_nc_coach_script'])):
    $GLOBALS['_ultitech_nc_coach_script'] = true;
    ?>
<script>
(function () {
    var KEY = 'ultitech_nc_coach';
    var MAX_AGE_MS = 5 * 60 * 1000;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function readCoach() {
        try {
            var raw = sessionStorage.getItem(KEY);
            if (!raw) return null;
            var data = JSON.parse(raw);
            if (!data || (!data.title && !data.body)) return null;
            if (data.at && (Date.now() - Number(data.at)) > MAX_AGE_MS) {
                sessionStorage.removeItem(KEY);
                return null;
            }
            return data;
        } catch (e) {
            return null;
        }
    }

    function clearCoach() {
        try { sessionStorage.removeItem(KEY); } catch (e) {}
    }

    window.showUltitechNcCoach = function showUltitechNcCoach(forced) {
        var data = forced || readCoach();
        if (!data) return;
        clearCoach();
        if (document.getElementById('nc-coach-root')) return;

        var root = document.createElement('div');
        root.id = 'nc-coach-root';
        root.className = 'nc-coach-root';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', data.title || 'Next step');
        root.innerHTML =
            '<div class="nc-coach-scrim" data-nc-coach-dismiss="1"></div>' +
            '<div class="nc-coach-card">' +
                '<div class="nc-coach-header">' +
                    '<div class="nc-coach-icon" aria-hidden="true">' +
                        '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M7 3.5h7.2L17.5 7v13.5H7V3.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>' +
                            '<path d="M14.2 3.5V7H17.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>' +
                            '<path d="M9.2 11h5.6M9.2 14h4.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                            '<path d="M9.4 18.2c1.1-.9 2-.7 2.8.1.7.7 1.5 1 2.6.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>' +
                        '</svg>' +
                    '</div>' +
                    '<div class="nc-coach-heading">' +
                        '<p class="nc-coach-kicker">Next step</p>' +
                        '<h3 class="nc-coach-title">' + escapeHtml(data.title || 'Continue') + '</h3>' +
                    '</div>' +
                '</div>' +
                '<p class="nc-coach-body">' + escapeHtml(data.body || '') + '</p>' +
                '<div class="nc-coach-footer">' +
                    '<div class="nc-coach-actions">' +
                        '<button type="button" class="nc-coach-btn" data-nc-coach-dismiss="1">' +
                            escapeHtml(data.action || 'Got it') +
                        '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';

        function dismiss() {
            if (root.parentNode) root.parentNode.removeChild(root);
            document.removeEventListener('keydown', onKey);
        }
        function onKey(ev) {
            if (ev.key === 'Escape') dismiss();
        }
        root.addEventListener('click', function (ev) {
            if (ev.target && ev.target.getAttribute('data-nc-coach-dismiss') === '1') {
                dismiss();
            }
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(root);
        var btn = root.querySelector('.nc-coach-btn');
        if (btn) {
            try { btn.blur(); } catch (e) {}
        }
    };

    function boot() {
        if (readCoach()) {
            window.showUltitechNcCoach();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
<?php endif; ?>
