<?php
// Immediate theme loading to prevent style flashing
echo '<script>
(function() {
    var t = localStorage.getItem("theme") || "light";
    document.documentElement.setAttribute("data-theme", t);
})();
</script>';

// Employee header with logo and notifications dropdown
if (!function_exists('isLoggedIn')) { require_once __DIR__ . '/functions.php'; }
requireLogin();
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// Resolve logo path to work from employee/ pages and root pages (e.g., /notifications.php)
if (!isset($logoBase)) {
    $logoBase = (strpos($script, '/employee/') !== false) ? '' : 'employee/';
}
if (!isset($rootPath)) {
    $rootPath = (strpos($script, '/employee/') !== false) ? '../' : '';
}
$unread = getTotalHeaderUnreadNotificationCount();
$headerNotifFeed = getHeaderNotificationsMerged(12);
$notifApiPath = $rootPath . 'api/get_notifications.php';
$notificationsListUrl = app_url('/notifications.php');
$unreadMsgs = getUnreadMessagesCountForCurrentUser();
if (!isset($modulesLink)) { $modulesLink = '../select-module.php'; }
?>
<?php
// Include sidebar if not printing
if (!isset($_GET['print'])) {
    include_once __DIR__ . '/sidebar.php'; 
}
?>
<header class="header employee-header">
    <div class="header-content">
        <div class="header-left" style="display: flex; align-items: center; gap: 16px;">
            <a href="../index.php" class="company-logo-link" style="display: flex; align-items: center; text-decoration: none;">
                <img src="<?= $logoBase ?>assets/images/Untitled.jpg" alt="Logo" class="company-logo-img" style="height: 40px; width: auto;" />
            </a>
            <button type="button" class="hamburger-menu" onclick="toggleHeaderMenu()" aria-label="Menu" title="Menu">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width: 20px; height: 20px;">
                    <line x1="3" y1="6" x2="21" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="3" y1="18" x2="21" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        
        <div class="header-right header-actions-tray">
            <button type="button" id="themeToggleBtn" class="theme-toggle-btn" aria-label="Toggle Theme" title="Toggle Dark/Light Mode">
                <i class="fas fa-moon" id="themeToggleIcon"></i>
            </button>
            <a href="<?= $rootPath ?>logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
            <?php require __DIR__ . '/includes/partials/header_notifications.php'; ?>
        </div>
    </div>
</header>

<div id="notif-backdrop" class="notif-backdrop" onclick="closeNotif()" style="background: transparent; cursor: default;"></div>
<script>
    // Unified Sidebar Toggle
    function toggleHeaderMenu(){
        // Check if mobile (using same breakpoint as CSS media query usually, or just check window width)
        // CSS says min-width: 1024px is desktop. So < 1024 is mobile.
        if (window.innerWidth < 1024) { 
            document.body.classList.toggle('sidebar-mobile-open');
        } else {
            var collapsed = document.body.classList.toggle('sidebar-collapsed');
            try{ localStorage.setItem('sidebar-collapsed', collapsed ? 'true' : 'false'); }catch(e){}
            // Trigger a custom event in case other components need to know
            window.dispatchEvent(new Event('sidebar-state-changed'));
        }
    }

    // Initialize collapse state from storage
    (function(){
        try{
            var saved = localStorage.getItem('sidebar-collapsed');
            if(saved === 'true' && window.innerWidth >= 1024){ document.body.classList.add('sidebar-collapsed'); }
        }catch(e){}
    })();

    // Auto-close mobile menu when resizing to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            document.body.classList.remove('sidebar-mobile-open');
        }
    });
    
    // Header Search functionality
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
            setTimeout(function() { if(input) input.focus(); }, 100);
        }
    }
    
    function handleHeaderSearch(value) {
        var pageSearchInput = document.getElementById('searchInput');
        if(pageSearchInput) {
            pageSearchInput.value = value;
            if(typeof performAdvancedSearch === 'function') performAdvancedSearch();
            if(typeof updateActiveFiltersCount === 'function') updateActiveFiltersCount();
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


    // Clock Function Removed
    function toggleNotif(e){
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var dd=document.getElementById('notif-dd');
        if(!dd) return;
        dd.classList.toggle('open');
    }
    function closeNotif(){
        var dd=document.getElementById('notif-dd'); 
        if(dd) dd.classList.remove('open');
    }
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('notif-dd');
        var btn = e.target.closest('.notif .icon-btn');
        
        // If dropdown is open and click is outside dropdown AND outside toggle button
        if (dd && dd.classList.contains('open') && !dd.contains(e.target) && !btn) {
            closeNotif();
        }
    });
    
    // Header Search functionality
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

<script src="<?= $logoBase ?>../assets/js/responsive-table.js"></script>
<?php require_once __DIR__ . '/mobile_footer.php'; ?>

<!-- Floating Chatbot Assets (Employee) -->
<style>
/* Fallback style so the launcher is at bottom-right even if external CSS is cached/blocked */
.chatbot-launcher{position:fixed;bottom:18px;right:18px;z-index:1500;background:#ffffff;color:#111827;border:2px solid #111827;border-radius:9999px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(0,0,0,.18);cursor:pointer;font-size:18px;line-height:1}
.chatbot-launcher:hover{background:#111827;color:#fff}
</style>
<link rel="stylesheet" href="../assets/css/chatbot.css?v=3" />
<script src="../assets/js/chatbot.js?v=3" defer></script>
<script src="../assets/js/chatbot-bootstrap.js?v=3" defer></script>
<!-- Ensure launcher exists even if JS loads late -->
<button id="chatbotLauncher" class="chatbot-launcher" type="button" aria-label="Help Assistant" title="Help">?</button>
