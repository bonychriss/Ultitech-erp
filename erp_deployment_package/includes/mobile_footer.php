<?php
// Mobile bottom navigation (account, home, search, messages)
if (!function_exists('isLoggedIn')) { require_once __DIR__ . '/functions.php'; }
if (!isLoggedIn()) { return; }
$isAdmin = isAdmin();
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// Determine prefix based on current script location (admin/ or employee/ pages)
$prefix = (strpos($script, '/admin/') !== false || strpos($script, '/employee/') !== false) ? '../' : '';
$homeUrl = $prefix . ($isAdmin ? 'admin/dashboard.php' : 'employee/dashboard.php');
$acctUrl = $prefix . ($isAdmin ? 'admin/account.php' : 'employee/account.php');
$searchUrl = $prefix . ($isAdmin ? 'admin/all-vouchers.php' : 'employee/my-vouchers.php');
$msgUrl = $prefix . 'messages.php';
$unreadMsgs = (int)(getUnreadMessagesCountForCurrentUser() ?? 0);
?>
<style>
@media (max-width: 640px) {
  .mobile-footer { position: fixed; bottom: 0; left: 0; right: 0; z-index: 1005; background: #ffffff; border-top: 1px solid #e5e7eb; box-shadow: 0 -2px 12px rgba(0,0,0,0.06); will-change: transform; }
  .mobile-footer .bar { display: flex; align-items: center; justify-content: space-around; gap: 4px; padding: 8px 10px calc(env(safe-area-inset-bottom, 0) + 8px); }
  .mobile-footer a { flex: 1; text-align: center; text-decoration: none; color: #374151; position: relative; font-size: 12px; line-height: 1.1; }
  .mobile-footer .icon { width: 22px; height: 22px; color: #111827; display:block; margin:0 auto; }
  .mobile-footer .label { display: block; font-size: 11px; color: #6b7280; margin-top: 2px; white-space: nowrap; }
  .mobile-footer .badge { position: absolute; top: -4px; right: 28%; transform: translateX(50%); background: #ef4444; color: #fff; border-radius: 999px; font-size: 10px; line-height: 16px; min-width: 16px; padding: 0 4px; }
  /* Reserve space at bottom so content is never hidden */
  body.has-mobile-footer { padding-bottom: calc(64px + env(safe-area-inset-bottom, 0)); }
  .mobile-footer { display: block; }
  /* Improve tap targets */
  .mobile-footer a { padding:4px 0; }
  .mobile-footer a:active { background:#f3f4f6; }
}
@media (min-width: 641px) { .mobile-footer { display: none; } }
@media print { .mobile-footer { display: none !important; } }
</style>
<nav class="mobile-footer" role="navigation" aria-label="Mobile navigation">
  <div class="bar">
    <a href="<?= htmlspecialchars($acctUrl) ?>" title="Account" aria-label="Account">
      <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5z" fill="currentColor"/>
      </svg>
      <span class="label">Account</span>
    </a>
    <a href="<?= htmlspecialchars($homeUrl) ?>" title="Home" aria-label="Home">
      <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M12 3l9 7-1.5 2L18 10.5V20a1 1 0 0 1-1 1h-4v-6H11v6H7a1 1 0 0 1-1-1v-9.5L4.5 12 3 10l9-7z" fill="currentColor"/>
      </svg>
      <span class="label">Home</span>
    </a>
    <a href="<?= htmlspecialchars($searchUrl) ?>" title="Search" aria-label="Search">
      <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M10 18a8 8 0 1 1 5.293-2.707L21 21l-1.414 1.414-5.707-5.707A7.962 7.962 0 0 1 10 18zm0-2a6 6 0 1 0 0-12 6 6 0 0 0 0 12z" fill="currentColor"/>
      </svg>
      <span class="label">Search</span>
    </a>
    <a href="<?= htmlspecialchars($msgUrl) ?>" title="Messages" aria-label="Messages" style="position:relative;">
      <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <?php if ($unreadMsgs > 0): ?><span class="badge"><?= $unreadMsgs ?></span><?php endif; ?>
      <span class="label">Messages</span>
    </a>
  </div>
</nav>
<script>
  // Ensure bottom spacing so footer doesn't overlap content on small screens
  (function(){
    function applyFooterPadding(){
      if (window.matchMedia && window.matchMedia('(max-width: 640px)').matches) {
        document.body.classList.add('has-mobile-footer');
      } else {
        document.body.classList.remove('has-mobile-footer');
      }
    }
    applyFooterPadding();
    window.addEventListener('resize', function(){
      clearTimeout(window.__mfResizeTimer);
      window.__mfResizeTimer = setTimeout(applyFooterPadding, 120);
    });
  })();
</script>
