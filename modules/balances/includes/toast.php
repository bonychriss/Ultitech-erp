<?php
/**
 * Lightweight corner toast (same look as the profile page) for $_SESSION['bal_toast'].
 */
$balToastMessage = trim((string) ($_SESSION['bal_toast'] ?? ''));
unset($_SESSION['bal_toast']);
if ($balToastMessage === '') {
    return;
}
?>
<style>
    .bal-toast {
        position: fixed; top: 14px; right: 24px; z-index: 2147483000; display: flex; align-items: center; gap: 12px;
        min-width: 280px; max-width: 380px; padding: 12px; background: #fff; border: 1px solid #eef0f4;
        border-radius: 12px; box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12); font-size: 13.5px; font-weight: 500; color: #1f2937;
        opacity: 0; transform: translateY(-8px); transition: opacity .2s ease, transform .2s ease;
    }
    .bal-toast.is-in { opacity: 1; transform: none; }
    .bal-toast i { flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; background: #dcfce7; color: #16a34a; }
    .bal-toast span { flex: 1; line-height: 1.4; }
    .bal-toast .bal-toast-close { flex-shrink: 0; width: 26px; height: 26px; border: 0; border-radius: 6px !important; background: transparent; color: #9ca3af; font-size: 18px; line-height: 1; cursor: pointer; padding: 0; }
    .bal-toast .bal-toast-close:hover { background: #f3f4f6; color: #374151; }
    html[data-theme="dark"] .bal-toast { background: #202c33; border-color: #2a3942; color: #e9edef; }
    html[data-theme="dark"] .bal-toast i { background: rgba(34, 197, 94, 0.14); color: #86efac; }
    html[data-theme="dark"] .bal-toast .bal-toast-close:hover { background: #2a3942; color: #fff; }
    @media (max-width: 640px) { .bal-toast { top: 12px; left: 12px; right: 12px; min-width: 0; max-width: none; } }
</style>
<div class="bal-toast" id="balToast" role="status" aria-live="polite">
    <i class="fas fa-circle-check" aria-hidden="true"></i>
    <span><?= htmlspecialchars($balToastMessage, ENT_QUOTES, 'UTF-8') ?></span>
    <button type="button" class="bal-toast-close" aria-label="Dismiss" onclick="this.parentNode.remove()">&times;</button>
</div>
<script>
    (function () {
        var t = document.getElementById('balToast');
        if (!t) return;
        requestAnimationFrame(function () { t.classList.add('is-in'); });
        setTimeout(function () {
            t.classList.remove('is-in');
            setTimeout(function () { t.remove(); }, 250);
        }, 3000);
    })();
</script>
