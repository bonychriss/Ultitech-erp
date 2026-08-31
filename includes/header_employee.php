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
if (!isset($rootPath)) {
    if (strpos($script, '/modules/') !== false) {
        $rootPath = '../../';
    } elseif (preg_match('#/(employee|todo|attendance|deliveries|dispatch|stock|weekly_tasks)(/|$)#', $script)) {
        $rootPath = '../';
    } else {
        $rootPath = '';
    }
}
if (!isset($logoBase)) {
    $logoBase = $rootPath;
}
$includeVoucherHeaderNotifs = empty($suppressHeaderPaymentVoucherNotifications);

// Module-specific header notification behavior:
// - Sales: show only sales-related system notifications; suppress core voucher feed.
$onlySystemModuleKey = null;
$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$moduleKey = (string)($_GET['module'] ?? '');
if ($moduleKey === 'sales' || strpos($scriptPath, '/modules/sales/') !== false) {
    $includeVoucherHeaderNotifs = false;
    $onlySystemModuleKey = 'sales';
}

$unread = getTotalHeaderUnreadNotificationCount($includeVoucherHeaderNotifs, $onlySystemModuleKey);
$headerNotifFeed = getHeaderNotificationsMerged(12, $includeVoucherHeaderNotifs, $onlySystemModuleKey);
$notifApiPath = function_exists('app_url') ? app_url('/api/get_notifications.php') : ($rootPath . 'api/get_notifications.php');
$notificationsListUrl = company_url('notifications');
$unreadMsgs = getUnreadMessagesCountForCurrentUser();
if (!isset($modulesLink)) { $modulesLink = company_url('select-module'); }
$headerCompany = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: null) : null;
$headerCompanyName = (string) ($headerCompany['company_name'] ?? ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company')));
$headerCompanyLogoSrc = '';
if (function_exists('getCompanyLogoUrl')) {
    $headerCompanyLogoSrc = getCompanyLogoUrl();
}
if ($headerCompanyLogoSrc === '') {
    $headerCompanyLogo = trim((string) getCompanySetting('company_logo', ''));
    if ($headerCompanyLogo !== '') {
        $logoRel = ltrim(str_replace('\\', '/', $headerCompanyLogo), '/');
        $logoDisk = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoRel);
        if (is_file($logoDisk) && function_exists('app_url')) {
            $headerCompanyLogoSrc = app_url('/' . $logoRel);
        }
    }
}
$headerEnabledModules = function_exists('getCompanyModules') ? getCompanyModules(true) : [];
if (!isset($employeeHeaderTitle)) {
    $employeeHeaderTitle = null;
}
if (!isset($employeeHeaderSubtitle)) {
    $employeeHeaderSubtitle = null;
}
if (!isset($employeeHeaderCenterHtml)) {
    $employeeHeaderCenterHtml = null;
}
if (!isset($employeeHeaderRightHtml)) {
    $employeeHeaderRightHtml = null;
}
if (!isset($employeeHeaderExtraClass)) {
    $employeeHeaderExtraClass = '';
}
if (!isset($hideHeaderCompanyBranding)) {
    $headerScriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $headerModuleKey = strtolower((string) ($_GET['module'] ?? ''));
    $hideHeaderCompanyBranding = (
        strpos($headerScriptPath, '/attendance/') !== false
        || strpos($headerScriptPath, '/employee/') !== false
        || $headerModuleKey === 'voucher'
    );
}
if (!isset($hideHeaderThemeAndNotifications)) {
    $hideHeaderThemeAndNotifications = false;
}
$__employeeHeaderShowHeading = ($employeeHeaderTitle !== null && $employeeHeaderTitle !== '');
$__employeeHeaderCenter = ($employeeHeaderCenterHtml !== null && $employeeHeaderCenterHtml !== '');

if (empty($GLOBALS['_erp_header_style_linked']) && function_exists('app_url')) {
    $GLOBALS['_erp_header_style_linked'] = true;
    $erpStylePath = dirname(__DIR__) . '/assets/css/style.css';
    $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
    echo '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css')) . '?v=' . $erpStyleVer . '">' . "\n";
    if (function_exists('erp_dark_theme_css_url')) {
        echo '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url()) . '">' . "\n";
    }
    $fontPartial = __DIR__ . '/partials/system_font.php';
    if (is_file($fontPartial)) {
        require $fontPartial;
    }
}
?>
<div class="d-flex w-100 min-vh-100 layout-main-wrapper">
    <?php 
    if (!isset($_GET['print'])) {
        $sidebarPath = __DIR__ . '/../sidebar.php';
        if (file_exists($sidebarPath)) {
            include_once $sidebarPath;
        } else {
            include_once __DIR__ . '/sidebar.php';
        }
    }
    ?>
    <div class="flex-grow-1 d-flex flex-column" style="min-width: 0;">
        <header class="header employee-header<?= $__employeeHeaderShowHeading ? ' employee-header--page-context' : '' ?><?= $__employeeHeaderCenter ? ' employee-header--has-center-slot' : '' ?><?= $employeeHeaderExtraClass !== '' ? ' ' . htmlspecialchars((string) $employeeHeaderExtraClass, ENT_QUOTES, 'UTF-8') : '' ?>" <?= $__employeeHeaderShowHeading ? 'style="background: transparent; border: none; box-shadow: none; padding-bottom: 0;"' : '' ?>>
    <div class="header-content">
        <div class="header-left" style="display: flex; align-items: center; gap: 16px;">
            <!-- Mobile Toggle Button -->
            <button type="button" class="btn btn-link d-lg-none p-0 me-2 employee-header-menu-btn" onclick="toggleNativeSidebar()" style="color: #333; text-decoration: none;" aria-label="Open menu">
                <span class="erp-hamburger" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
        </div>

        <?php if ($__employeeHeaderShowHeading): ?>
        <div class="employee-header-page-heading px-1 px-md-2 text-start <?= $__employeeHeaderCenter ? 'employee-header-page-heading--with-center flex-shrink-0' : 'flex-grow-1' ?>" style="margin-left: 10px; display: flex; flex-direction: column; gap: 4px;">
            <h1 class="employee-header-page-title mb-0" style="font-size: 22px; font-weight: 700; color: #111827; letter-spacing: -0.01em; line-height: 1.2;"><?= htmlspecialchars((string) $employeeHeaderTitle) ?></h1>
            <?php if ($employeeHeaderSubtitle !== null && $employeeHeaderSubtitle !== ''): ?>
                <p class="employee-header-page-subtitle mb-0" style="font-size: 13px; color: #9ca3af; display: flex; gap: 8px; align-items: center; line-height: 1;">
                    <?= $employeeHeaderSubtitle ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($__employeeHeaderCenter): ?>
        <div class="employee-header-center-slot flex-grow-1 d-flex justify-content-center align-items-center px-1 px-md-2" style="min-width: 0;">
            <?= $employeeHeaderCenterHtml ?>
        </div>
        <?php endif; ?>

        <div class="header-right header-actions-tray" style="margin-left:auto; display:flex; align-items:center; justify-content:flex-end; gap:16px;">
            <?php if (empty($hideHeaderCompanyBranding)): ?>
            <div class="d-none d-md-flex align-items-center gap-2 text-muted small" title="<?= htmlspecialchars($headerCompanyName) ?>">
                <?php if ($headerCompanyLogoSrc !== ''): ?>
                    <img src="<?= htmlspecialchars($headerCompanyLogoSrc) ?>" alt="" width="20" height="20" style="width:20px;height:20px;border-radius:50%;object-fit:cover;">
                <?php endif; ?>
                <span><?= htmlspecialchars($headerCompanyName) ?></span>
                <?php if (!empty($headerEnabledModules)): ?>
                    <span class="badge bg-light text-secondary border"><?= count($headerEnabledModules) ?> modules</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($employeeHeaderRightHtml)): ?>
                <?= $employeeHeaderRightHtml ?>
            <?php endif; ?>
            <?php if (empty($hideHeaderThemeAndNotifications)): ?>
            <button type="button" id="themeToggleBtn" class="theme-toggle-btn" aria-label="Toggle Theme" title="Toggle Dark/Light Mode">
                <i class="fas fa-moon" id="themeToggleIcon"></i>
            </button>
            <?php require __DIR__ . '/partials/header_notifications.php'; ?>
            <?php endif; ?>
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
            var nd = document.getElementById('notif-dd');
            if(nd && nd.classList.contains('open')) { nd.classList.remove('open'); }
        }
    });


    // Clock Function Removed
    function positionMobileNotifDropdown() {
        var dd = document.getElementById('notif-dd');
        var sidebarBtn = document.querySelector('.sidebar-notif-trigger');
        var btn = sidebarBtn || document.querySelector('.header-notif-bell-btn');
        if (!dd || !btn) {
            if (dd) { dd.style.top = ''; dd.style.right = ''; dd.style.left = ''; }
            return;
        }
        if (window.matchMedia('(max-width: 767.98px)').matches) {
            if (sidebarBtn) {
                dd.style.top = '';
                dd.style.right = '';
                dd.style.left = '';
                return;
            }
            var r = btn.getBoundingClientRect();
            dd.style.top = Math.round(r.bottom + 8) + 'px';
            dd.style.right = Math.max(8, Math.round(window.innerWidth - r.right)) + 'px';
            dd.style.left = '';
            return;
        }
        if (sidebarBtn) {
            var rect = sidebarBtn.getBoundingClientRect();
            dd.style.position = 'fixed';
            dd.style.top = Math.round(rect.top) + 'px';
            dd.style.left = Math.round(rect.right + 8) + 'px';
            dd.style.right = 'auto';
            return;
        }
        dd.style.position = '';
        dd.style.top = '';
        dd.style.right = '';
        dd.style.left = '';
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
        var btn = e && e.currentTarget ? e.currentTarget : (document.querySelector('.sidebar-notif-trigger') || document.querySelector('.header-notif-bell-btn'));
        if(!dd) return;
        var willOpen = !dd.classList.contains('open');
        dd.classList.toggle('open');
        dd.setAttribute('aria-hidden', willOpen ? 'false' : 'true');
        if (btn) btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen && !dd.classList.contains('notif-dropdown--v2')) {
            positionMobileNotifDropdown();
        } else if (willOpen && document.querySelector('.sidebar-notif-trigger')) {
            positionMobileNotifDropdown();
        } else {
            dd.style.top = '';
            dd.style.right = '';
            dd.style.left = '';
            dd.style.position = '';
        }
        syncNotifBackdrop(dd.classList.contains('open'));
        if (willOpen) document.body.classList.add('notif-panel-open');
        else document.body.classList.remove('notif-panel-open');
    }
    function closeNotif(){
        var dd=document.getElementById('notif-dd');
        var btn = document.querySelector('.sidebar-notif-trigger') || document.querySelector('.header-notif-bell-btn');
        if(dd) {
            dd.classList.remove('open');
            dd.setAttribute('aria-hidden', 'true');
            dd.style.top = '';
            dd.style.right = '';
        }
        if (btn) btn.setAttribute('aria-expanded', 'false');
        syncNotifBackdrop(false);
        document.body.classList.remove('notif-panel-open');
        if (dd) {
            dd.style.position = '';
            dd.style.left = '';
        }
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

<?php
$erpDesktopLatestVersion = null;
$erpDesktopDownloadUrl = function_exists('app_url') ? app_url('/client-apps/download-desktop.php') : '/client-apps/download-desktop.php';
$selectModuleLib = __DIR__ . '/../select-module-ui/lib.php';
if (is_file($selectModuleLib)) {
    require_once $selectModuleLib;
    if (function_exists('selectModuleDesktopLatestVersion')) {
        $erpDesktopLatestVersion = selectModuleDesktopLatestVersion();
    }
}
if ($erpDesktopLatestVersion !== null):
    $desktopBannerCss = dirname(__DIR__) . '/assets/css/desktop-update-banner.css';
    $desktopBannerJs = dirname(__DIR__) . '/assets/js/desktop-update-banner.js';
    $desktopBannerCssVer = is_file($desktopBannerCss) ? (int) filemtime($desktopBannerCss) : time();
    $desktopBannerJsVer = is_file($desktopBannerJs) ? (int) filemtime($desktopBannerJs) : time();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/css/desktop-update-banner.css')) ?>?v=<?= $desktopBannerCssVer ?>">
<script>
window.__ERP_DESKTOP_UPDATE__ = <?= json_encode([
    'latestVersion' => $erpDesktopLatestVersion,
    'downloadUrl' => $erpDesktopDownloadUrl,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars(app_url('/assets/js/desktop-update-banner.js')) ?>?v=<?= $desktopBannerJsVer ?>" defer></script>
<?php endif; ?>

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
<?php
// Global Flash Message Handler
$flashTypes = ['success', 'error', 'warning', 'info'];
foreach ($flashTypes as $ftype) {
    if (isset($_SESSION[$ftype])) {
        $msg = $_SESSION[$ftype];
        $icon = ($ftype == 'error') ? 'error' : $ftype;
        unset($_SESSION[$ftype]);
        echo "
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: '$icon',
                    title: '" . addslashes($msg) . "',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true
                });
            });
        </script>";
    }
}
?>
<script>
    // Notification Poller
    (function(){
        // Only poll if window is active to save resources
        let pollInterval = setInterval(checkNotifications, 10000); // 10 seconds
        
        async function checkNotifications() {
            if(document.hidden) return;
            try {
                // Adjust path based on location (header logic handles logoBase, assume api is at ../api or ./api)
                // Since this is included, we can try absolute path or relative from root
                const runPath = '<?= $rootPath ?>api/get_notifications.php?action=poll';
                const response = await fetch(runPath);
                const data = await response.json();
                
                if(data.success && data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(notif => {
                        // Show Toast
                        Swal.fire({
                            icon: notif.type || 'info',
                            title: notif.title,
                            text: notif.message,
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 5000,
                            timerProgressBar: true,
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', Swal.stopTimer)
                                toast.addEventListener('mouseleave', Swal.resumeTimer)
                                if(notif.link) {
                                    toast.addEventListener('click', () => window.location.href = notif.link);
                                    toast.style.cursor = 'pointer';
                                }
                            }
                        });
                        
                        // Mark as read immediately
                        fetch('<?= $rootPath ?>api/get_notifications.php?action=read&id=' + encodeURIComponent(notif.id));
                    });
                }
            } catch(e) {
                console.error('Notification poll error', e);
            }
        }
    })();
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
