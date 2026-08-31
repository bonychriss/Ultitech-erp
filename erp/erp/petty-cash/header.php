<?php
// Ensure functions are loaded if not already
require_once __DIR__ . '/../../includes/functions.php';

// Fetch notifications for the current user
$notifs = getNotificationsForCurrentUser(8);
$unread = getUnreadCountForCurrentUser();
?>
<header class="header" style="height: 60px; padding: 0 20px; width: 100%;">
    <div class="header-content" style="height: 100%; width: 100%; display: flex; justify-content: space-between; align-items: center;">
        <div class="header-logo" style="display: flex; align-items: center; gap: 12px;">
            <a href="../../select-module.php">
                <img src="../../assets/images/Untitled.jpg" alt="Ultimate Logo" class="company-logo-img" style="height: 32px; width: auto;">
            </a>
            <div class="header-info">
                <h1 style="font-size: 1.1rem; font-weight: 600; color: #111827;">PETTY CASH MANAGEMENT</h1>
            </div>
        </div>
        <div class="header-actions">
            <!-- Notification Dropdown -->
            <div class="notif" style="position: relative; margin-right: 12px;">
                <button class="icon-btn" type="button" onclick="toggleNotif(event)" aria-label="Notifications" style="background:none; border:none; cursor:pointer; position:relative; display:flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%; transition:all 0.2s; color:#555;">
                    <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:20px; height:20px;">
                        <path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php if ($unread > 0): ?>
                        <span class="badge" style="position:absolute; top:2px; right:2px; background:#ef4444; color:white; font-size:9px; font-weight:bold; min-width:16px; height:16px; border-radius:8px; display:flex; align-items:center; justify-content:center; padding:0 2px; border:1px solid #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);"><?= (int)$unread ?></span>
                    <?php endif; ?>
                </button>
                
                <div id="notif-dd" class="notif-dropdown" onclick="event.stopPropagation();" style="display:none; position:absolute; top:120%; right:-10px; width:400px; background:white; border-radius:12px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); z-index:1000; border:1px solid #f3f4f6; overflow:hidden; animation: slideDown 0.2s ease-out;">
                    <!-- Notification styles remain mostly same but slightly more compact if needed, skipping deep refactor inside dropdown for now as request focused on main UI -->
                    <style>
                        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
                        .notif-item { display: flex; gap: 10px; padding: 12px; text-decoration: none; border-bottom: 1px solid #f3f4f6; transition: background 0.2s; align-items: flex-start; }
                        .notif-item:hover { background: #f9fafb; }
                        .notif-item:last-child { border-bottom: none; }
                        .notif-item.unread { background: #f0f9ff; }
                        .notif-item.unread:hover { background: #e0f2fe; }
                        .notif-icon-box { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
                        .notif-content { flex: 1; min-width: 0; }
                        .notif-title-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; }
                        .notif-title { font-weight: 600; color: #111827; font-size: 13px; line-height: 1.3; }
                        .notif-time { font-size: 10px; color: #9ca3af; white-space: nowrap; margin-left: 6px; }
                        .notif-msg { font-size: 12px; color: #6b7280; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
                        .notif-dot { width: 6px; height: 6px; background: #2563eb; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
                    </style>
                    
                    <div class="titlebar" style="padding:12px 16px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:center; background:white;">
                        <div class="title" style="font-weight:600; color:#111827; font-size:14px;">Notifications</div>
                        <a href="../../notifications.php" onclick="window.location.href='../../notifications.php'; return false;" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:500;">View all</a>
                    </div>
                    
                    <div class="notif-list" style="max-height:350px; overflow-y:auto;">
                        <?php if (empty($notifs)): ?>
                            <div class="empty" style="padding:32px 20px; text-align:center; color:#6b7280;">
                                <div style="font-size:24px; margin-bottom:8px;">📭</div>
                                <div style="font-size:13px; font-weight:500;">No notifications yet</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifs as $n): $unr = empty($n['is_read']) || $n['is_read'] == 0; ?>
                                <?php 
                                    $type = $n['type'] ?? 'info';
                                    $icon = 'ℹ️';
                                    $bg = '#eff6ff';
                                    
                                    if ($type === 'success') { $icon = '✅'; $bg = '#ecfdf5'; }
                                    elseif ($type === 'warning') { $icon = '⚠️'; $bg = '#fffbeb'; }
                                    elseif ($type === 'danger') { $icon = '🚨'; $bg = '#fef2f2'; }
                                ?>
                                <a class="notif-item <?= $unr ? 'unread' : '' ?>" href="<?= ($n['voucher_id'] ? 'view-voucher.php?id=' . (int)$n['voucher_id'] : '#') ?>">
                                    <div class="notif-icon-box" style="background: <?= $bg ?>;">
                                        <?= $icon ?>
                                    </div>
                                    <div class="notif-content">
                                        <div class="notif-title-row">
                                            <span class="notif-title"><?= htmlspecialchars($n['title']) ?></span>
                                            <span class="notif-time"><?= date('M d', strtotime($n['created_at'])) ?></span>
                                        </div>
                                        <div class="notif-msg">
                                            <?= htmlspecialchars($n['message'] ?? '') ?>
                                        </div>
                                    </div>
                                    <?php if ($unr): ?><div class="notif-dot"></div><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="notif-actions" style="padding:10px 16px; background:#f9fafb; border-top:1px solid #f3f4f6; display:flex; justify-content:center;">
                        <form method="post" action="../../includes/notifications_api.php" onsubmit="fetch('../../includes/notifications_api.php',{method:'POST',body:new URLSearchParams({action:'mark_all_read'})}).then(()=>location.reload()); return false;" style="width:100%;">
                            <button class="btn" type="submit" style="width:100%; padding:6px; font-size:12px; background:white; border:1px solid #e5e7eb; border-radius:6px; cursor:pointer; color:#374151; font-weight:500; transition:all 0.2s;">
                                Mark all as read
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <a href="../../select-module.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px;">
                    <path d="M19 12H5M12 19l-7-7 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Back to Modules
            </a>
            
            <?php if (basename($_SERVER['PHP_SELF']) !== 'create-voucher.php'): ?>
            <a href="create-voucher.php" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem;">
                <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px;">
                    <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                New Voucher
            </a>
            <?php endif; ?>
        </div>
    </div>
</header>


<script>
function toggleNotif(e) {
    if (e) e.stopPropagation();
    var dd = document.getElementById('notif-dd');
    if(!dd) return;
    
    if (dd.style.display === 'block') {
        dd.style.display = 'none';
    } else {
        dd.style.display = 'block';
    }
}

function closeNotif() {
    var dd = document.getElementById('notif-dd');
    if (dd) dd.style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    var dd = document.getElementById('notif-dd');
    var btn = e.target.closest('.notif .icon-btn');
    
    // If dropdown is open and click is outside dropdown AND outside toggle button
    if (dd && dd.style.display === 'block' && !dd.contains(e.target) && !btn) {
        closeNotif();
    }
});
</script>
