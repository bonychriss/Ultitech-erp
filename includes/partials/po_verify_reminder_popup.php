<?php
/**
 * Popup reminders when warehouse asks procurement to verify a PO.
 * Include near </body> on select-module and stock pages (SweetAlert optional — loads CDN if missing).
 */
declare(strict_types=1);

if (!empty($GLOBALS['_ultitech_po_verify_reminder_popup_rendered'])) {
    return;
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    return;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !function_exists('fetchUnreadPoVerifyReminders')) {
    return;
}

$reminders = fetchUnreadPoVerifyReminders($userId, 8);
if ($reminders === []) {
    return;
}

$GLOBALS['_ultitech_po_verify_reminder_popup_rendered'] = true;

$markReadUrl = function_exists('app_url')
    ? app_url('/api/get_notifications.php')
    : '/api/get_notifications.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  var reminders = <?= json_encode($reminders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var markBase = <?= json_encode($markReadUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  if (!Array.isArray(reminders) || !reminders.length || typeof Swal === 'undefined') {
    return;
  }

  function markRead(id) {
    if (!id) return;
    var url = markBase + (markBase.indexOf('?') >= 0 ? '&' : '?') + 'action=read&id=' + encodeURIComponent('s' + id);
    try {
      fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    } catch (e) {}
  }

  function showNext(index) {
    if (index >= reminders.length) return;
    var item = reminders[index] || {};
    var title = String(item.title || 'PO verification reminder');
    var message = String(item.message || 'You are being reminded to verify a purchase order.');
    var link = item.link ? String(item.link) : '';
    var id = Number(item.id || 0);

    var opts = {
      icon: 'info',
      title: title,
      text: message,
      confirmButtonText: link ? 'Open PO' : 'OK',
      showCancelButton: !!link,
      cancelButtonText: 'Dismiss',
      allowOutsideClick: false,
      allowEscapeKey: true,
    };

    Swal.fire(opts).then(function (result) {
      markRead(id);
      if (result.isConfirmed && link) {
        window.location.href = link;
        return;
      }
      showNext(index + 1);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { showNext(0); });
  } else {
    showNext(0);
  }
})();
</script>
