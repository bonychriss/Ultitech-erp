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
requireAdmin();
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// Resolve relative prefix for assets / sibling URLs (admin/, company slug/, nested modules/)
if (!isset($prefix)) {
    $prefix = '';
    if (strpos($script, '/admin/') !== false) {
        $prefix = '../';
    } elseif (substr_count(trim($script, '/'), '/') >= 2) {
        // e.g. /public_html/roadmaster/settings.php or /employee/view-voucher.php
        $prefix = '../';
    }
}
$unread = getTotalHeaderUnreadNotificationCount();
$headerNotifFeed = getHeaderNotificationsMerged(12);
$notifApiPath = $prefix . 'api/get_notifications.php';
$notificationsListUrl = function_exists('app_url') ? app_url('/notifications.php') : ($prefix . 'notifications.php');
$unreadMsgs = getUnreadMessagesCountForCurrentUser();
$modulesLink = '../select-module.php';
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1));
?>
<div class="d-flex w-100 min-vh-100 layout-main-wrapper">
    <?php 
    if (!isset($_GET['print'])) {
        include_once __DIR__ . '/sidebar.php'; 
    }
    ?>
    <div class="flex-grow-1 d-flex flex-column" style="min-width: 0;">
        <header class="header admin-header">
    <div class="header-content">
        <div class="header-left" style="display: flex; align-items: center; gap: 16px;">
            <a href="../index.php" class="company-logo-link" style="display: flex; align-items: center; text-decoration: none;">
                <img src="<?= $prefix ?>assets/images/Untitled.jpg" alt="Logo" class="company-logo-img" style="height: 40px; width: auto;" />
            </a>

        </div>
        
        <div class="header-right header-actions-tray">
            <?php
            if (function_exists('erp_render_theme_toggle_html')) {
                echo erp_render_theme_toggle_html();
            } else {
                require __DIR__ . '/includes/partials/theme_toggle.php';
            }
            ?>
            <a href="<?= $prefix ?>logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
            <?php require __DIR__ . '/includes/partials/header_notifications.php'; ?>
        </div>
    </div>
</header>

<div id="notif-backdrop" class="notif-backdrop" onclick="closeNotif()" aria-hidden="true"></div>
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

    function ensureNotifPanelOnBody() {
        var dd = document.getElementById('notif-dd');
        if (dd && dd.parentElement !== document.body) {
            document.body.appendChild(dd);
        }
        var bd = document.getElementById('notif-backdrop');
        if (bd && bd.parentElement !== document.body) {
            document.body.appendChild(bd);
        }
    }

    function persistNotifDrawerOpen(isOpen) {
        try {
            sessionStorage.setItem('ultitech_notif_drawer_open', isOpen ? '1' : '0');
        } catch (e) {}
    }

    function setNotifDrawerOpen(isOpen) {
        ensureNotifPanelOnBody();
        var dd = document.getElementById('notif-dd');
        var btn = document.querySelector('.sidebar-notif-trigger') || document.querySelector('.header-notif-bell-btn');
        if (!dd) return false;
        dd.classList.toggle('open', !!isOpen);
        dd.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        syncNotifBackdrop(!!isOpen);
        document.body.classList.toggle('notif-panel-open', !!isOpen);
        persistNotifDrawerOpen(!!isOpen);
        return true;
    }

    function restoreNotifDrawerOpen() {
        var shouldOpen = false;
        try {
            shouldOpen = sessionStorage.getItem('ultitech_notif_drawer_open') === '1';
        } catch (e) {}
        if (!shouldOpen) return;
        if (!setNotifDrawerOpen(true)) {
            setTimeout(restoreNotifDrawerOpen, 50);
        }
    }

    function syncNotifBackdrop(isOpen) {
        var bd = document.getElementById('notif-backdrop');
        if (!bd) return;
        if (isOpen) {
            bd.classList.add('is-open');
            bd.style.display = 'block';
        } else {
            bd.classList.remove('is-open');
            bd.style.display = 'none';
        }
    }
    
    function toggleNotif(e){
        if (e) { e.preventDefault(); e.stopPropagation(); }
        ensureNotifPanelOnBody();
        var dd=document.getElementById('notif-dd');
        if(!dd) return;
        setNotifDrawerOpen(!dd.classList.contains('open'));
    }
    function closeNotif(){
        setNotifDrawerOpen(false);
    }

    ensureNotifPanelOnBody();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreNotifDrawerOpen);
    } else {
        restoreNotifDrawerOpen();
    }
    
    // Close drawer when clicking outside
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('notif-dd');
        var btn = e.target.closest('.header-notif-bell-btn, .notif .icon-btn, .sidebar-notif-trigger');
        
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
        }
    });
</script>
<?php
if (function_exists('erp_get_theme_toggle_script_html')) {
    echo erp_get_theme_toggle_script_html();
}
?>

<script src="<?= $prefix ?>assets/js/responsive-table.js"></script>
<?php require_once __DIR__ . '/mobile_footer.php'; ?>

<!-- Floating Chatbot Assets (Admin) -->
<style>
/* Fallback style so the launcher is at bottom-right even if external CSS is cached/blocked */
.chatbot-launcher{position:fixed;bottom:18px;right:18px;z-index:1500;background:#ffffff;color:#111827;border:2px solid #111827;border-radius:9999px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(0,0,0,.18);cursor:pointer;font-size:18px;line-height:1}
.chatbot-launcher:hover{background:#111827;color:#fff}
</style>
<link rel="stylesheet" href="<?= $prefix ?>assets/css/chatbot.css?v=3" />
<script src="<?= $prefix ?>assets/js/chatbot.js?v=3" defer></script>
<script src="<?= $prefix ?>assets/js/chatbot-bootstrap.js?v=3" defer></script>
<!-- Ensure launcher exists even if JS loads late -->
<button id="chatbotLauncher" class="chatbot-launcher" type="button" aria-label="Help Assistant" title="Help">?</button>
