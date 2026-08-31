<?php
// Admin header with logo and notifications dropdown
if (!function_exists('isAdmin')) { require_once __DIR__ . '/functions.php'; }
requireAdmin();
$unread = getUnreadCountForCurrentUser();
$notifs = getNotificationsForCurrentUser(8);
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// Resolve logo path to work from admin/ pages and root pages (e.g., /notifications.php)
$prefix = (strpos($script, '/admin/') !== false) ? '../' : '';
$unreadMsgs = getUnreadMessagesCountForCurrentUser();
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'A', 0, 1));
?>
<header class="header admin-header">
    <style>
        /* Center titles on admin pages while keeping logo left and controls clickable */
        .header .header-content { position: relative; }
        .header .header-logo { display:flex; align-items:center; gap:12px; }
        .header .header-info {
            position: static;
            transform: none;
            width: auto;
            text-align: left;
            pointer-events: none; /* don't block clicks on right side */
        }
        @media (max-width: 640px) { .header .header-info { width: 80%; } }

        /* Admin header theme — Option 3: Minimal light header with gold underline */
        .admin-header { 
            background: #ffffff; 
            color: #111827; 
            box-shadow: 0 1px 0 rgba(17,24,39,0.06);
            position: -webkit-sticky !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 999 !important;
            width: 100% !important;
        }
        .admin-header::after { display: none !important; }
        .admin-header .header-content { border-bottom: 3px solid #f4b400; padding:4px 12px; }
        .admin-header .header-info h1 { color: #111827; font-size:14px; font-weight:600; margin:0; letter-spacing:.5px; }
        .admin-header .header-info h2 { color: #4b5563; font-size:11px; margin:2px 0 0; }
        .admin-header .icon-white { color: #111827; }
        .admin-header .logout-btn { color: #b45309; }
        .admin-header .logout-btn:hover { color: #92400e; }
    /* Ensure avatar colors (account icon) */
    .admin-header .avatar-circle { background:#000 !important; color:#fff !important; width:32px; height:32px; font-size:13px; line-height:32px; font-weight:600; }
    .admin-header .user-info { display:flex; align-items:center; gap:6px; }
    .admin-header .user-info a.icon-link,
    .admin-header .user-info .icon-btn { width:32px; height:32px; padding:4px; display:flex; align-items:center; justify-content:center; border-radius:4px; }
    .admin-header .user-info a.icon-link:hover,
    .admin-header .user-info .icon-btn:hover { background:#f3f4f6; }
    .admin-header .user-info .icon { width:18px; height:18px; }
    .admin-header .user-info .logout-btn { padding:4px 10px; font-size:12px; line-height:1; }
    .admin-header .user-info > div { margin-left:4px; }
    .admin-header .user-info > div p { margin:0; line-height:1.1; font-size:11px; }
    .admin-header .user-info > div p:first-child { font-weight:600; font-size:12px; }
        /* Hide header icons that are duplicated in mobile footer */
        @media (max-width:640px){
            .admin-header .user-info > a[title="Messages"],
            .admin-header .user-info > a[title="Employee View"],
            .admin-header .user-info > a[href*="account.php"],
            .admin-header .user-info .avatar-circle,
            .admin-header .logout-btn { display:none !important; }
        }

        /* Header Search */
        .header-search {
            position: relative;
            margin-right: 12px;
        }
        .header-search-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #111827;
            transition: color 0.2s;
        }
        .header-search-btn:hover {
            color: #2563eb;
        }
        .header-search-container {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 12px;
            min-width: 300px;
            z-index: 1000;
            display: none;
            animation: slideDown 0.2s ease;
        }
        .header-search-container.show {
            display: block;
        }
        .header-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 0;
            font-size: 14px;
            outline: none;
        }
        .header-search-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Hamburger Menu */
        .hamburger-menu {
            display: none;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 8px;
            margin-right: 8px;
            color: #111827;
            z-index: 1001;
        }
        
        .hamburger-menu .icon {
            width: 24px;
            height: 24px;
        }
        
        .mobile-menu {
            position: fixed;
            top: 0;
            left: -300px;
            width: 280px;
            height: 100vh;
            background: #ffffff;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: left 0.3s ease;
            overflow-y: auto;
            padding-top: 60px;
        }
        
        /* Adjust mobile menu for landscape orientation */
        @media (max-width: 896px) and (orientation: landscape) {
            .mobile-menu {
                width: 280px;
                max-width: 60vw;
                padding-top: 40px;
            }
            .mobile-menu-header {
                padding: 12px;
            }
            .mobile-menu-header h3 {
                font-size: 16px;
            }
            .mobile-menu-header p {
                font-size: 12px;
            }
            .mobile-menu-item {
                padding: 10px 16px;
                font-size: 13px;
            }
        }
        
        .mobile-menu.open {
            left: 0;
        }
        
        .mobile-menu-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        
        .mobile-menu-backdrop.show {
            display: block;
        }
        
        .mobile-menu-header {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 8px;
        }
        
        .mobile-menu-header h3 {
            margin: 0;
            font-size: 18px;
            color: #111827;
        }
        
        .mobile-menu-header p {
            margin: 4px 0 0 0;
            font-size: 13px;
            color: #6c757d;
        }
        
        .mobile-menu-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: #111827;
        }
        
        .mobile-menu-item {
            display: block;
            padding: 12px 16px;
            color: #212529;
            text-decoration: none;
            border-bottom: 1px solid #f1f3f5;
            transition: background 0.2s;
            font-size: 14px;
        }
        
        .mobile-menu-item:hover {
            background: #f8f9fa;
            color: #212529;
        }
        
        .mobile-menu-item.active {
            background: #e3f2fd;
            color: #1976d2;
            font-weight: 500;
        }
        
        .mobile-menu-item .icon {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            vertical-align: middle;
        }
        
        .mobile-menu-section {
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 8px;
        }
        
        .mobile-menu-section-title {
            padding: 8px 16px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .mobile-menu-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 8px 0;
        }
        
        /* Show hamburger menu on mobile */
        @media (max-width: 640px) {
            .hamburger-menu {
                display: block;
            }
            
            .admin-header .header-content { padding:3px 8px; }
            .admin-header .company-logo-img { height:36px; width:auto; }
            .admin-header .header-info h1 { font-size:11px; line-height:1.15; }
            .admin-header .user-info a.icon-link,
            .admin-header .user-info .icon-btn { width:28px; height:28px; }
            .admin-header .user-info .icon { width:16px; height:16px; }
            .header-search-container {
                right: -20px;
                min-width: 280px;
            }
        }
        
        @media (max-width: 400px) {
            .admin-header .company-logo-img { height: 32px; }
            .admin-header .header-info h1 { font-size: 11px; }
            .header-search-container {
                min-width: 250px;
                right: -40px;
            }
            
            .mobile-menu {
                width: 100%;
                left: -100%;
            }
        }
        
        /* Mobile landscape: minimize header and show hamburger menu */
        @media (max-width: 896px) and (orientation: landscape) {
            /* Ensure hamburger menu is visible in landscape */
            .admin-header .hamburger-menu {
                display: block !important;
                padding: 2px;
                margin-right: 2px;
            }
            .admin-header .hamburger-menu .icon {
                width: 16px;
                height: 16px;
            }
            
            .admin-header .header-content { padding:2px 6px !important; min-height:30px !important; max-height:34px !important; border-bottom-width:2px !important; }
            .admin-header .company-logo-img { 
                height: 24px !important; 
                width: auto;
                margin-right: 4px;
            }
            .admin-header .header-info {
                width: 45% !important;
                left: 48% !important;
            }
            .admin-header .header-info h1 { 
                font-size: 9px !important; 
                line-height: 1 !important;
                margin: 0;
                letter-spacing: 0.3px;
            }
            .admin-header .user-info { gap:4px !important; }
            .admin-header .header-search-btn,
            .admin-header .icon-btn,
            .admin-header .icon-link { width:24px !important; height:24px !important; padding:2px; margin-right:2px !important; }
            .admin-header .icon { width:14px !important; height:14px !important; }
            .admin-header .notif {
                margin-right: 4px !important;
            }
            .admin-header .notif .badge {
                font-size: 8px;
                padding: 0 3px;
                min-width: 12px;
                line-height: 12px;
                top: -3px;
                right: -3px;
            }
            .admin-header .user-info > div {
                display: none !important;
            }
            .admin-header .logout-btn {
                font-size: 10px !important;
                padding: 1px 3px;
                margin: 0;
            }
            .admin-header .header-search {
                margin-right: 4px !important;
            }
            /* Hide most elements in landscape - move to hamburger menu */
            .admin-header .user-info > a[title="Employee View"],
            .admin-header .user-info > a[title="Messages"],
            .admin-header .user-info > a[href*="account.php"],
            .admin-header .user-info .notif,
            .admin-header .user-info .header-search,
            .admin-header .user-info .logout-btn {
                display: none !important;
            }
        }
        
        /* Hide hamburger menu on desktop (but allow in landscape mobile) */
        @media (min-width: 897px) {
            .hamburger-menu {
                display: none !important;
            }
        }
        
        /* Also hide on portrait tablets and larger */
        @media (min-width: 641px) and (orientation: portrait) {
            .hamburger-menu {
                display: none !important;
            }
        }
    </style>
    <div class="header-content">
        <div class="header-logo">
            <button class="hamburger-menu" onclick="toggleMobileMenu()" aria-label="Menu" title="Menu">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <line x1="3" y1="6" x2="21" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="3" y1="12" x2="21" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="3" y1="18" x2="21" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <?php $homeLink = $prefix . 'index.php'; ?>
            <a href="<?= htmlspecialchars($homeLink) ?>" class="company-logo-link" title="Home" aria-label="Go to homepage" style="display:inline-block;">
                <img src="<?= $prefix ?>assets/images/Untitled.jpg" alt="<?= htmlspecialchars(COMPANY_NAME) ?> Logo" class="company-logo-img" />
            </a>
            <div class="header-info">
                <h1>PAYMENT VOUCHER SYSTEM</h1>
            </div>
        </div>
        <div class="user-info">
            <?php $modulesLink = $prefix . 'select-module.php'; ?>
            <a href="<?= htmlspecialchars($modulesLink) ?>" class="icon-link icon-white" title="About / Select Module" aria-label="About / Select Module" style="margin-right:8px;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
                    <line x1="12" y1="8" x2="12" y2="8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M11 12h1v6h1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <div class="header-search">
                <button class="header-search-btn icon-btn icon-white" type="button" onclick="toggleHeaderSearch(event)" aria-label="Search" title="Search Vouchers">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="11" cy="11" r="8" fill="none" stroke="currentColor" stroke-width="2"/>
                        <path d="m21 21-4.35-4.35" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="header-search-container" id="headerSearchContainer">
                    <input type="text" class="header-search-input" id="headerSearchInput" 
                           placeholder="Search vouchers..." 
                           onkeyup="handleHeaderSearch(this.value)"
                           onclick="event.stopPropagation()">
                </div>
            </div>
            <a href="<?= $prefix ?>messages.php" class="icon-link icon-white" title="Messages" style="margin-right:8px; position:relative;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php if ($unreadMsgs > 0): ?><span class="badge"><?= (int)$unreadMsgs ?></span><?php endif; ?>
            </a>
            <div class="notif">
                <button class="icon-btn icon-white" type="button" onclick="toggleNotif(event)" aria-label="Notifications">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php if ($unread > 0): ?><span class="badge"><?= (int)$unread ?></span><?php endif; ?>
                </button>
                <div id="notif-dd" class="notif-dropdown" onclick="event.stopPropagation();">
                    <div class="titlebar">
                        <div class="title">Notifications</div>
                        <a href="../notifications.php">View all</a>
                    </div>
                    <?php if (empty($notifs)): ?>
                        <div class="empty">No notifications</div>
                    <?php else: ?>
                        <?php foreach ($notifs as $n): $unr = empty($n['is_read']) || $n['is_read'] == 0; ?>
                            <a class="item <?= $unr ? 'unread' : '' ?>" href="<?= '../employee/view-voucher.php?id=' . (int)($n['voucher_id'] ?? 0) ?>" title="<?= htmlspecialchars($n['message'] ?? $n['title']) ?>">
                                <div style="font-weight:600; font-size:13px; display:flex; align-items:center; gap:6px;">
                                    <?php if ($unr): ?><span class="dot"></span><?php endif; ?>
                                    <?= htmlspecialchars($n['title']) ?>
                                </div>
                                <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                                    <?= htmlspecialchars($n['message'] ?? '') ?>
                                </div>
                                <div style="font-size:11px; color:#9ca3af; margin-top:2px;">
                                    <?= date('d M H:i', strtotime($n['created_at'])) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="notif-actions" style="display:flex; justify-content: flex-end; align-items:center; gap:8px;">
                        <form method="post" action="../includes/notifications_api.php" onsubmit="fetch('../includes/notifications_api.php',{method:'POST',body:new URLSearchParams({action:'mark_all_read'})}).then(()=>location.reload()); return false;">
                            <button class="btn btn-secondary" type="submit" style="padding:6px 10px; font-size:12px;">Mark all read</button>
                        </form>
                    </div>
                </div>
            </div>
            <a href="manage_tasks.php" class="icon-link icon-white" title="Manage Tasks" style="margin-right:8px;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>
            <a href="settings.php" class="icon-link icon-white" title="Office Settings" style="margin-right:8px;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 1v6m0 6v10M1 12h6m6 0h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </a>
            <a href="<?= $prefix ?>account.php" title="My Account" style="text-decoration:none; margin-right:8px;">
                <div class="avatar-circle"><?= htmlspecialchars($initial) ?></div>
            </a>
            <a href="<?= $prefix ?>employee/dashboard.php" class="icon-link icon-white" title="Employee View" style="margin-right:8px;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" />
                    <path d="M2 12h20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    <path d="M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </a>
            <div>
                <p>Admin</p>
            </div>
            <a href="../logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
</header>

<!-- Mobile Menu -->
<div id="mobileMenuBackdrop" class="mobile-menu-backdrop" onclick="closeMobileMenu()"></div>
<div id="mobileMenu" class="mobile-menu">
    <button class="mobile-menu-close" onclick="closeMobileMenu()" aria-label="Close menu">
        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
    </button>
    <div class="mobile-menu-header">
        <h3>Admin</h3>
        <p><?= htmlspecialchars($_SESSION['full_name']) ?></p>
    </div>
    <div class="mobile-menu-section">
        <div class="mobile-menu-section-title">Navigation</div>
        <a href="dashboard.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Dashboard
        </a>
        <a href="all-vouchers.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="14 2 14 8 20 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            All Vouchers
        </a>
        <a href="manage-users.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="9" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Manage Users
        </a>
        <a href="reports.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <polyline points="14 2 14 8 20 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Reports
        </a>
        <a href="view-attendance.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="12" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            View Attendance
        </a>
        <a href="manage_tasks.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Manage Tasks
        </a>
        <a href="<?= $prefix ?>employee/dashboard.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" />
                <path d="M2 12h20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                <path d="M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
            Employee View
        </a>
    </div>
    <div class="mobile-menu-section">
        <div class="mobile-menu-section-title">Account</div>
        <a href="<?= $prefix ?>account.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="12" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            My Account
        </a>
        <a href="<?= $prefix ?>messages.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Messages
            <?php if ($unreadMsgs > 0): ?><span style="float: right; background: #ef4444; color: white; border-radius: 10px; padding: 2px 6px; font-size: 11px;"><?= (int)$unreadMsgs ?></span><?php endif; ?>
        </a>
        <a href="../notifications.php" class="mobile-menu-item" onclick="closeMobileMenu()">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Notifications
            <?php if ($unread > 0): ?><span style="float: right; background: #ef4444; color: white; border-radius: 10px; padding: 2px 6px; font-size: 11px;"><?= (int)$unread ?></span><?php endif; ?>
        </a>
    </div>
    <div class="mobile-menu-divider"></div>
    <a href="../logout.php" class="mobile-menu-item" onclick="closeMobileMenu()" style="color: #dc3545;">
        <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <polyline points="16 17 21 12 16 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <line x1="21" y1="12" x2="9" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Logout
    </a>
</div>

<div id="notif-backdrop" class="notif-backdrop" onclick="closeNotif()"></div>
<script>
    // Mobile Menu Functions
    function toggleMobileMenu() {
        var menu = document.getElementById('mobileMenu');
        var backdrop = document.getElementById('mobileMenuBackdrop');
        if (menu && backdrop) {
            var isOpen = menu.classList.contains('open');
            if (isOpen) {
                closeMobileMenu();
            } else {
                menu.classList.add('open');
                backdrop.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }
    }
    
    function closeMobileMenu() {
        var menu = document.getElementById('mobileMenu');
        var backdrop = document.getElementById('mobileMenuBackdrop');
        if (menu && backdrop) {
            menu.classList.remove('open');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
    
    // Close menu on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });
    
    function toggleNotif(e){
        if(e) e.stopPropagation();
        var dd=document.getElementById('notif-dd');
        var bd=document.getElementById('notif-backdrop');
        if(!dd) return;
        var open = dd.classList.contains('open');
        if(open){ dd.classList.remove('open'); if(bd) bd.style.display='none'; }
        else { dd.classList.add('open'); if(bd) bd.style.display='block'; }
    }
    function closeNotif(){
        var dd=document.getElementById('notif-dd'); if(dd) dd.classList.remove('open');
        var bd=document.getElementById('notif-backdrop'); if(bd) bd.style.display='none';
    }
    document.addEventListener('click', closeNotif);
    (function(){ var dd=document.getElementById('notif-dd'); if(dd){ dd.addEventListener('click', function(ev){ ev.stopPropagation(); }); } })();
    
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
