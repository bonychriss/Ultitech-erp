<?php
// Immediate theme loading to prevent style flashing
echo '<script>
(function() {
    var t = localStorage.getItem("theme") || "light";
    document.documentElement.setAttribute("data-theme", t);
})();
</script>';

// Admin header with logo and notifications dropdown
if (!function_exists('isAdmin')) { require_once __DIR__ . '/functions.php'; }
if (!defined('ALLOW_ANONYMOUS_PAYROLL')) {
    requireAdmin();
}
// Ensure global stylesheet + system font when header is included without header_employee.
if (empty($GLOBALS['_erp_header_style_linked']) && function_exists('app_url')) {
    $GLOBALS['_erp_header_style_linked'] = true;
    $erpStylePath = dirname(__DIR__) . '/assets/css/style.css';
    $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
    echo '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css')) . '?v=' . $erpStyleVer . '">' . "\n";
    if (function_exists('erp_dark_theme_css_url')) {
        echo '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url()) . '">' . "\n";
    }
    if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    }
}
$unread = getTotalHeaderUnreadNotificationCount();
$headerNotifFeed = getHeaderNotificationsMerged(12);
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// Resolve logo path to work from admin/ pages and root pages (e.g., /notifications.php)
if (!isset($prefix)) {
    if (strpos($script, '/modules/') !== false) {
        $prefix = '../../';
    } elseif (strpos($script, '/admin/') !== false || strpos($script, '/employee/') !== false) {
        $prefix = '../';
    } else {
        $prefix = '';
    }
}
$notifApiPath = function_exists('app_url') ? app_url('/api/get_notifications.php') : ($prefix . 'api/get_notifications.php');
$notificationsListUrl = app_url('/notifications.php');
$unreadMsgs = getUnreadMessagesCountForCurrentUser();
$modulesLink = app_url('/select-module.php');
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1));
if (!isset($employeeHeaderRightHtml)) {
    $employeeHeaderRightHtml = null;
}
if (!isset($employeeHeaderAfterThemeHtml)) {
    $employeeHeaderAfterThemeHtml = null;
}
if (!isset($employeeHeaderCenterHtml)) {
    $employeeHeaderCenterHtml = null;
}
$__adminHeaderCenter = ($employeeHeaderCenterHtml !== null && $employeeHeaderCenterHtml !== '');
?>
<div class="d-flex w-100 min-vh-100 layout-main-wrapper">
    <?php 
    if (!isset($_GET['print'])) {
        include_once __DIR__ . '/../sidebar.php'; 
    }
    ?>
    <div class="flex-grow-1 d-flex flex-column" style="min-width: 0;">
        <header class="header admin-header<?= isset($employeeHeaderTitle) && $employeeHeaderTitle !== '' ? ' admin-header--page-context' : '' ?><?= $__adminHeaderCenter ? ' admin-header--has-center-slot' : '' ?>" <?= isset($employeeHeaderTitle) && $employeeHeaderTitle !== '' ? 'style="background: transparent; border: none; box-shadow: none; padding-bottom: 0;"' : '' ?>>
    <div class="header-content">
        <div class="header-left" style="display: flex; align-items: center; gap: 16px;">
            <!-- Mobile Toggle Button -->
            <button type="button" class="btn btn-link d-lg-none p-0 me-2 employee-header-menu-btn" onclick="toggleNativeSidebar()" style="color: #333; text-decoration: none;" aria-label="Open menu">
                <span class="erp-hamburger" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
        </div>

        <?php if (isset($employeeHeaderTitle) && $employeeHeaderTitle !== ''): ?>
        <div class="employee-header-page-heading px-1 px-md-2 text-start <?= $__adminHeaderCenter ? 'flex-shrink-0' : 'flex-grow-1' ?>" style="margin-left: 10px; display: flex; flex-direction: column; gap: 4px;">
            <h1 class="employee-header-page-title mb-0" style="font-size: 22px; font-weight: 700; color: #111827; letter-spacing: -0.01em; line-height: 1.2;"><?= htmlspecialchars((string) $employeeHeaderTitle) ?></h1>
            <?php if (isset($employeeHeaderSubtitle) && $employeeHeaderSubtitle !== ''): ?>
                <p class="employee-header-page-subtitle mb-0" style="font-size: 13px; color: #9ca3af; display: flex; gap: 8px; align-items: center; line-height: 1;">
                    <?= $employeeHeaderSubtitle ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($__adminHeaderCenter): ?>
        <div class="admin-header-center-slot flex-grow-1 d-flex justify-content-center align-items-center px-1 px-md-2" style="min-width: 0;">
            <?= $employeeHeaderCenterHtml ?>
        </div>
        <?php endif; ?>
        
        <div class="header-right header-actions-tray" style="margin-left:auto; display:flex; align-items:center; justify-content:flex-end; gap:16px;">
            <button type="button" id="themeToggleBtn" class="theme-toggle-btn" aria-label="Toggle Theme" title="Toggle Dark/Light Mode">
                <i class="fas fa-moon" id="themeToggleIcon"></i>
            </button>
            <?php if (!empty($employeeHeaderAfterThemeHtml)): ?>
                <?= $employeeHeaderAfterThemeHtml ?>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/header_notifications.php'; ?>
            <?php if (!empty($employeeHeaderRightHtml)): ?>
                <?= $employeeHeaderRightHtml ?>
            <?php endif; ?>
        </div>
    </div>
</header>

<div id="notif-backdrop" class="notif-backdrop" onclick="closeNotif()" style="background: transparent; cursor: default;"></div>
<script>
    // Unified Sidebar Toggle
    function toggleHeaderMenu(){
        if (window.innerWidth < 1024) { 
            document.body.classList.toggle('sidebar-mobile-open');
        } else {
            var collapsed = document.body.classList.toggle('sidebar-collapsed');
            try{ localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0'); }catch(e){}
        }
    }

    // Initialize collapse state from storage
    (function(){
        try{
            var saved = localStorage.getItem('sidebarCollapsed');
            if(saved === '1' && window.innerWidth >= 1024){ document.body.classList.add('sidebar-collapsed'); }
        }catch(e){}
    })();

    // Auto-close mobile menu when resizing to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            document.body.classList.remove('sidebar-mobile-open');
        }
    });
    
    function positionMobileNotifDropdown() {
        var dd = document.getElementById('notif-dd');
        var btn = document.querySelector('.header-notif-bell-btn');
        if (!dd || !btn || !window.matchMedia('(max-width: 767.98px)').matches) {
            if (dd) { dd.style.top = ''; dd.style.right = ''; }
            return;
        }
        var r = btn.getBoundingClientRect();
        dd.style.top = Math.round(r.bottom + 8) + 'px';
        dd.style.right = Math.max(8, Math.round(window.innerWidth - r.right)) + 'px';
    }

    function syncNotifBackdrop(isOpen) {
        var bd = document.getElementById('notif-backdrop');
        if (!bd) return;
        if (isOpen && window.matchMedia('(max-width: 767.98px)').matches) {
            bd.classList.add('is-open');
        } else {
            bd.classList.remove('is-open');
        }
    }

    function toggleNotif(e){
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var dd=document.getElementById('notif-dd');
        var btn = e && e.currentTarget ? e.currentTarget : document.querySelector('.header-notif-bell-btn');
        if(!dd) return;
        var willOpen = !dd.classList.contains('open');
        dd.classList.toggle('open');
        dd.setAttribute('aria-hidden', willOpen ? 'false' : 'true');
        if (btn) btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen && !dd.classList.contains('notif-dropdown--v2')) {
            positionMobileNotifDropdown();
        } else {
            dd.style.top = '';
            dd.style.right = '';
        }
        syncNotifBackdrop(dd.classList.contains('open'));
        if (willOpen) document.body.classList.add('notif-panel-open');
        else document.body.classList.remove('notif-panel-open');
    }
    function closeNotif(){
        var dd=document.getElementById('notif-dd');
        var btn = document.querySelector('.header-notif-bell-btn');
        if(dd) {
            dd.classList.remove('open');
            dd.setAttribute('aria-hidden', 'true');
            dd.style.top = '';
            dd.style.right = '';
        }
        if (btn) btn.setAttribute('aria-expanded', 'false');
        syncNotifBackdrop(false);
        document.body.classList.remove('notif-panel-open');
    }

    window.addEventListener('resize', function () {
        var dd = document.getElementById('notif-dd');
        if (dd && dd.classList.contains('open')) {
            positionMobileNotifDropdown();
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('notif-dd');
        var btn = e.target.closest('.header-notif-bell-btn, .notif .icon-btn');
        
        // If dropdown is open and click is outside dropdown AND outside toggle button
        if (dd && dd.classList.contains('open') && !dd.contains(e.target) && !btn) {
            closeNotif();
        }
    });
    
    // Header Search functionality

    // Clock Function Removed

    function toggleHeaderSearch(e) {
        if(e) e.stopPropagation();
        var container = document.getElementById('headerSearchContainer');
        var input = document.getElementById('headerSearchInput');
        if(!container) return;
        
        var isOpen = container.classList.contains('show');
        if(isOpen) {
            container.classList.remove('show');
        } else {
            container.classList.add('show');
            // Focus input after animation
            setTimeout(function() {
                if(input) input.focus();
            }, 100);
        }
    }
    
    function handleHeaderSearch(value) {
        // Sync with page search if it exists (for my-vouchers.php)
        var pageSearchInput = document.getElementById('searchInput');
        if(pageSearchInput) {
            pageSearchInput.value = value;
            // Trigger search if function exists
            if(typeof performAdvancedSearch === 'function') {
                performAdvancedSearch();
            }
            if(typeof updateActiveFiltersCount === 'function') {
                updateActiveFiltersCount();
            }
        } else {
            // If not on my-vouchers page, redirect to it with search query
            if(value.trim() !== '') {
                var currentPath = window.location.pathname;
                if(currentPath.indexOf('/employee/my-vouchers.php') === -1) {
                    // Could redirect or store for later
                    // For now, just show in header search
                }
            }
        }
    }
    
    // Close header search when clicking outside
    document.addEventListener('click', function(e) {
        var container = document.getElementById('headerSearchContainer');
        var searchBtn = e.target.closest('.header-search-btn');
        if(container && !container.contains(e.target) && !searchBtn) {
            container.classList.remove('show');
        }
    });
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if(e.key === 'Escape') {
            var container = document.getElementById('headerSearchContainer');
            if(container && container.classList.contains('show')) {
                container.classList.remove('show');
            }
            var nd = document.getElementById('notif-dd');
            if(nd && nd.classList.contains('open')) { nd.classList.remove('open'); }
        }
    });
</script>
<script>
(function() {
    var btn = document.getElementById('themeToggleBtn');
    var icon = document.getElementById('themeToggleIcon');
    if (!btn || !icon) return;

    function updateIcon(theme) {
        if (theme === 'dark') {
            icon.className = 'fas fa-sun';
        } else {
            icon.className = 'fas fa-moon';
        }
    }

    function showToast(message) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: message,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true
            });
        } else {
            var toast = document.createElement('div');
            toast.textContent = message;
            toast.style.position = 'fixed';
            toast.style.top = '20px';
            toast.style.right = '20px';
            toast.style.backgroundColor = '#10b981';
            toast.style.color = '#fff';
            toast.style.padding = '12px 24px';
            toast.style.borderRadius = '8px';
            toast.style.boxShadow = '0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05)';
            toast.style.zIndex = '99999';
            toast.style.fontFamily = 'sans-serif';
            toast.style.fontSize = '14px';
            toast.style.fontWeight = '600';
            toast.style.transition = 'opacity 0.3s ease';
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                setTimeout(function() {
                    document.body.removeChild(toast);
                }, 300);
            }, 2000);
        }
    }

    var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    updateIcon(currentTheme);

    btn.addEventListener('click', function() {
        var activeTheme = document.documentElement.getAttribute('data-theme') || 'light';
        var newTheme = activeTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateIcon(newTheme);
        
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: newTheme } }));
        showToast(newTheme === 'dark' ? 'Dark theme activated' : 'Light theme activated');
    });
})();
</script>

<script src="<?= app_url('/assets/js/responsive-table.js') ?>"></script>
<?php require_once __DIR__ . '/mobile_footer.php'; ?>

<!-- Floating Chatbot (React) -->
<?php
$chatbotUiCss = __DIR__ . '/../assets/chatbot-ui/dist/assets/chatbot-ui.css';
$chatbotUiJs = __DIR__ . '/../assets/chatbot-ui/dist/assets/chatbot-ui.js';
$chatbotUiVer = max(
    (int) (@filemtime($chatbotUiCss) ?: 0),
    (int) (@filemtime($chatbotUiJs) ?: 0)
);
?>
<div id="erp-chatbot-root"></div>
<script>
window.__CHATBOT__ = {
  apiUrl: <?= json_encode(app_url('/chatbot_api.php'), JSON_UNESCAPED_SLASHES) ?>,
  appBase: <?= json_encode(app_url('/'), JSON_UNESCAPED_SLASHES) ?>,
  cssUrl: <?= json_encode(app_url('/assets/chatbot-ui/dist/assets/chatbot-ui.css') . '?v=' . (int) $chatbotUiVer, JSON_UNESCAPED_SLASHES) ?>
};
</script>
<?php if (is_file($chatbotUiCss)): ?>
<link rel="stylesheet" href="<?= app_url('/assets/chatbot-ui/dist/assets/chatbot-ui.css') ?>?v=<?= (int) $chatbotUiVer ?>" />
<?php endif; ?>
<?php if (is_file($chatbotUiJs)): ?>
<script type="module" src="<?= app_url('/assets/chatbot-ui/dist/assets/chatbot-ui.js') ?>?v=<?= (int) $chatbotUiVer ?>"></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
