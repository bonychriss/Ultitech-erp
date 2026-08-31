<?php
/**
 * Petty cash Lottie overlay � preloads once for fast playback.
 */
$pc_lottie_redirect = $pc_lottie_redirect ?? '';
$pc_lottie_show_success = !empty($pc_lottie_show_success);
$pc_lottie_submit_message = $pc_lottie_submit_message ?? 'Submitting voucher...';
$pc_lottie_success_message = $pc_lottie_success_message ?? 'Voucher created successfully!';
$pc_lottie_form_id = $pc_lottie_form_id ?? 'voucher-form';
$pc_lottie_okay_label = $pc_lottie_okay_label ?? 'Okay';
$pc_lottie_view_label = $pc_lottie_view_label ?? 'View';

$pc_lottie_module_web_base = '';
if (function_exists('app_url')) {
    $pc_lottie_module_web_base = rtrim((string) app_url('/erp/petty-cash'), '/');
} else {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(.*)/erp/petty-cash(?:/|$)#', $script, $m)) {
        $pc_lottie_module_web_base = $m[1] . '/erp/petty-cash';
    } else {
        $pc_lottie_module_web_base = '/erp/petty-cash';
    }
}
$pc_lottie_json_href = $pc_lottie_module_web_base . '/assets/lottie/voucher-success.json';
$lottieFile = dirname(__DIR__) . '/assets/lottie/voucher-success.json';
if (!empty($pc_lottie_lottie_file)) {
    $lottieFile = (string) $pc_lottie_lottie_file;
}
if (!empty($pc_lottie_json_href_override)) {
    $pc_lottie_json_href = (string) $pc_lottie_json_href_override;
}
$pc_lottie_animation_inline = null;
if (is_readable($lottieFile)) {
    if ($pc_lottie_show_success) {
        $decoded = json_decode((string) file_get_contents($lottieFile), true);
        if (is_array($decoded)) {
            $pc_lottie_animation_inline = $decoded;
        }
    }
} else {
    $pc_lottie_json_href = '';
}

$pc_lottie_lib = 'https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js';
$pc_lottie_success_ms = 2400;
$pc_lottie_desktop_minimal = !empty($pc_lottie_desktop_minimal);
$pc_lottie_mobile_only = !empty($pc_lottie_mobile_only);
$pc_lottie_overlay_classes = 'pc-lottie-overlay'
    . ($pc_lottie_desktop_minimal ? ' pc-lottie-desktop-minimal' : '')
    . ($pc_lottie_mobile_only ? ' pc-lottie-mobile-only' : '');
?>
<link rel="preload" href="<?= htmlspecialchars($pc_lottie_lib, ENT_QUOTES, 'UTF-8') ?>" as="script" crossorigin="anonymous">
<?php if ($pc_lottie_json_href !== ''): ?>
<link rel="preload" href="<?= htmlspecialchars($pc_lottie_json_href, ENT_QUOTES, 'UTF-8') ?>" as="fetch" crossorigin="anonymous">
<?php endif; ?>
<style>
    .pc-lottie-overlay {
        position: fixed;
        inset: 0;
        z-index: 10050;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .pc-lottie-overlay.is-visible {
        display: flex;
    }
    .pc-lottie-overlay__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
    }
    .pc-lottie-overlay__panel {
        position: relative;
        z-index: 1;
        width: min(92vw, 340px);
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
        padding: 1.25rem 1.25rem 1.5rem;
        text-align: center;
    }
    .pc-lottie-overlay__anim {
        width: 100%;
        max-width: 260px;
        margin: 0 auto;
        height: 260px;
        display: flex;
        align-items: center;
        justify-content: center;
        contain: strict;
    }
    .pc-lottie-overlay__anim canvas,
    .pc-lottie-overlay__anim svg {
        width: 100% !important;
        height: 100% !important;
    }
    .pc-lottie-overlay__spinner {
        width: 44px;
        height: 44px;
        border: 3px solid #e2e8f0;
        border-top-color: #22c55e;
        border-radius: 50%;
        animation: pc-lottie-spin 0.65s linear infinite;
    }
    .pc-lottie-overlay__anim.is-ready .pc-lottie-overlay__spinner {
        display: none;
    }
    .pc-lottie-fallback-success {
        display: none !important;
        width: 120px;
        height: 120px;
        margin: 0 auto;
        border-radius: 50%;
        background: #dcfce7;
        color: #16a34a;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        flex-shrink: 0;
    }
    .pc-lottie-overlay__anim.show-fallback .pc-lottie-fallback-success {
        display: flex !important;
    }
    .pc-lottie-overlay__anim.has-lottie .pc-lottie-fallback-success {
        display: none !important;
    }
    @keyframes pc-lottie-spin {
        to { transform: rotate(360deg); }
    }
    .pc-lottie-overlay__msg {
        margin: 0.75rem 0 0;
        font-size: 1rem;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.4;
    }
    .pc-lottie-overlay__actions {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        margin-top: 1rem;
    }
    .pc-lottie-overlay__actions[hidden] {
        display: none !important;
    }
    .pc-lottie-btn {
        flex: 1;
        min-height: 44px;
        padding: 0.65rem 1rem;
        border-radius: 12px;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background 0.15s ease, transform 0.1s ease;
    }
    .pc-lottie-btn:active {
        transform: scale(0.98);
    }
    .pc-lottie-btn--ghost {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    .pc-lottie-btn--ghost:hover {
        background: #e2e8f0;
    }
    .pc-lottie-btn--primary {
        background: #7c3aed;
        color: #fff;
        border: 1px solid #7c3aed;
    }
    .pc-lottie-btn--primary:hover {
        background: #6d28d9;
    }
    .pc-lottie-overlay__handle {
        display: none;
    }
    @keyframes pc-lottie-sheet-up {
        from {
            transform: translateY(100%);
            opacity: 0.85;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    @keyframes pc-lottie-backdrop-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @media (max-width: 768px) {
        .pc-lottie-overlay {
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }
        .pc-lottie-overlay.is-visible .pc-lottie-overlay__backdrop {
            animation: pc-lottie-backdrop-in 0.28s ease-out forwards;
        }
        .pc-lottie-overlay__panel {
            width: 100%;
            max-width: 100%;
            border-radius: 20px 20px 0 0;
            padding: 0.75rem 1.25rem calc(1.25rem + env(safe-area-inset-bottom, 0px));
            box-shadow: 0 -8px 32px rgba(15, 23, 42, 0.18);
            transform: translateY(100%);
            transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease-out;
            opacity: 0.85;
        }
        .pc-lottie-overlay.is-visible .pc-lottie-overlay__panel {
            transform: translateY(0);
            opacity: 1;
        }
        .pc-lottie-overlay.is-visible .pc-lottie-overlay__handle {
            display: block;
            width: 40px;
            height: 4px;
            margin: 0.35rem auto 0.85rem;
            border-radius: 999px;
            background: #cbd5e1;
        }
        .pc-lottie-overlay__anim {
            max-width: 220px;
            height: 200px;
        }
        .pc-lottie-overlay__msg {
            font-size: 0.9375rem;
            margin-top: 0.5rem;
        }
        .pc-lottie-overlay__actions {
            margin-top: 0.85rem;
        }
        .pc-lottie-overlay__actions .pc-lottie-btn {
            min-height: 48px;
            width: 100%;
        }
        .pc-lottie-overlay__actions .pc-lottie-btn--primary {
            order: 2;
        }
        .pc-lottie-overlay__actions .pc-lottie-btn--ghost {
            order: 1;
        }
        /* Balances success sheet: View primary on top, Close below */
        .pc-lottie-overlay.pc-lottie-mobile-only .pc-lottie-overlay__actions {
            flex-direction: column;
            gap: 0.6rem;
        }
        .pc-lottie-overlay.pc-lottie-mobile-only .pc-lottie-overlay__actions .pc-lottie-btn--primary {
            order: 1;
        }
        .pc-lottie-overlay.pc-lottie-mobile-only .pc-lottie-overlay__actions .pc-lottie-btn--ghost {
            order: 2;
        }
    }
    @media (min-width: 769px) {
        .pc-lottie-overlay.pc-lottie-mobile-only,
        .pc-lottie-overlay.pc-lottie-mobile-only.is-visible {
            display: none !important;
        }
        .pc-lottie-overlay__actions {
            flex-direction: row;
        }
        .pc-lottie-overlay__actions .pc-lottie-btn {
            width: auto;
            min-height: 44px;
        }
        .pc-lottie-overlay__actions .pc-lottie-btn--primary,
        .pc-lottie-overlay__actions .pc-lottie-btn--ghost {
            order: unset;
        }
        /* Approved success on list: white card with blurred backdrop */
        .pc-lottie-overlay.pc-lottie-desktop-minimal .pc-lottie-overlay__backdrop {
            display: block;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .pc-lottie-overlay.pc-lottie-desktop-minimal .pc-lottie-overlay__panel {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.2);
            width: min(92vw, 380px);
            padding: 1.25rem 1.25rem 1.5rem;
        }
        .pc-lottie-overlay.pc-lottie-desktop-minimal .pc-lottie-overlay__handle {
            display: none;
        }
    }
    @media (max-width: 768px) and (prefers-reduced-motion: reduce) {
        .pc-lottie-overlay__panel {
            transition: none;
        }
        .pc-lottie-overlay.is-visible .pc-lottie-overlay__panel {
            transform: translateY(0);
            opacity: 1;
        }
    }
</style>

<div id="pc-lottie-overlay" class="<?= htmlspecialchars($pc_lottie_overlay_classes, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="pc-lottie-overlay-msg">
    <div class="pc-lottie-overlay__backdrop"></div>
    <div class="pc-lottie-overlay__panel">
        <span class="pc-lottie-overlay__handle" aria-hidden="true"></span>
        <div id="pc-lottie-container" class="pc-lottie-overlay__anim">
            <div class="pc-lottie-overlay__spinner" aria-hidden="true"></div>
            <div class="pc-lottie-fallback-success" aria-hidden="true"><i class="fas fa-circle-check"></i></div>
        </div>
        <p id="pc-lottie-overlay-msg" class="pc-lottie-overlay__msg"></p>
        <div id="pc-lottie-actions" class="pc-lottie-overlay__actions" hidden>
            <button type="button" class="pc-lottie-btn pc-lottie-btn--ghost" id="pc-lottie-btn-okay"><?= htmlspecialchars($pc_lottie_okay_label, ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="pc-lottie-btn pc-lottie-btn--primary" id="pc-lottie-btn-view"><?= htmlspecialchars($pc_lottie_view_label, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($pc_lottie_lib, ENT_QUOTES, 'UTF-8') ?>" crossorigin="anonymous" id="pc-lottie-lib-js"></script>
<script>
(function () {
    var jsonUrl = <?= json_encode($pc_lottie_json_href, JSON_UNESCAPED_SLASHES) ?>;
    var inlineAnimationData = <?= $pc_lottie_animation_inline !== null
        ? json_encode($pc_lottie_animation_inline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : 'null' ?>;
    var redirectUrl = <?= json_encode($pc_lottie_redirect, JSON_UNESCAPED_SLASHES) ?>;
    var viewUrl = <?= json_encode($pc_lottie_view_url ?? '', JSON_UNESCAPED_SLASHES) ?>;
    var showSuccessOnLoad = <?= $pc_lottie_show_success ? 'true' : 'false' ?>;
    var mobileOnly = <?= $pc_lottie_mobile_only ? 'true' : 'false' ?>;
    var submitMessage = <?= json_encode($pc_lottie_submit_message, JSON_UNESCAPED_UNICODE) ?>;
    var successMessage = <?= json_encode($pc_lottie_success_message, JSON_UNESCAPED_UNICODE) ?>;
    var successDurationMs = <?= (int) $pc_lottie_success_ms ?>;

    function isMobileViewport() {
        if (window.matchMedia('(max-width: 768px)').matches) {
            return true;
        }
        if (window.matchMedia('(hover: none) and (pointer: coarse)').matches) {
            return window.innerWidth <= 1024;
        }
        return false;
    }

    function showDesktopSuccessToast() {
        if (!successMessage || isMobileViewport() || mobileOnly) return;
        if (window.balSuppressSuccessToast || window.balLottieSuccessShown) return;
        function fireToast() {
            if (window.Toast && typeof window.Toast.fire === 'function') {
                window.Toast.fire({ icon: 'success', title: successMessage });
                return;
            }
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: successMessage, toast: true, position: 'top-end', timer: 3000, showConfirmButton: false });
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fireToast, { once: true });
        } else {
            fireToast();
        }
    }

    function markLottieSuccessHandled() {
        window.balSuppressSuccessToast = true;
        window.balLottieSuccessShown = true;
        if (typeof window.balInstallToastMobileGuard === 'function') {
            window.balInstallToastMobileGuard();
        }
    }

    var overlay = document.getElementById('pc-lottie-overlay');
    var container = document.getElementById('pc-lottie-container');
    var msgEl = document.getElementById('pc-lottie-overlay-msg');
    var actionsEl = document.getElementById('pc-lottie-actions');
    var btnOkay = document.getElementById('pc-lottie-btn-okay');
    var btnView = document.getElementById('pc-lottie-btn-view');
    var preloadedAnim = null;
    var animationData = null;
    var preloadPromise = null;
    var redirectTimer = null;
    var completeBound = false;
    var pendingPlay = null;
    var dismissed = false;

    function clearRedirectTimer() {
        if (redirectTimer) {
            clearTimeout(redirectTimer);
            redirectTimer = null;
        }
    }

    function goRedirect() {
        if (!redirectUrl) {
            hideOverlay();
            return;
        }
        window.location.replace(redirectUrl);
    }

    function dismissSuccess() {
        if (dismissed) return;
        dismissed = true;
        clearRedirectTimer();
        completeBound = true;
        goRedirect();
    }

    function setSuccessMode(isSuccess) {
        if (!overlay) return;
        overlay.classList.toggle('pc-lottie-overlay--success', !!isSuccess);
        if (actionsEl) {
            actionsEl.hidden = true;
        }
        if (!isSuccess) {
            dismissed = false;
        }
    }

    function revealSuccessActions() {
        if (actionsEl) {
            actionsEl.hidden = false;
        }
        if (preloadedAnim) {
            try {
                var frame = Math.max(0, preloadedAnim.totalFrames - 1);
                preloadedAnim.goToAndStop(frame, true);
            } catch (e) { /* ignore */ }
        }
    }

    function showOverlay(message, isSuccess) {
        if (!overlay) return;
        setSuccessMode(!!isSuccess);
        if (msgEl) msgEl.textContent = message || '';
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var panel = overlay.querySelector('.pc-lottie-overlay__panel');
        if (panel && isMobileViewport()) {
            requestAnimationFrame(function () {
                panel.style.transform = 'translateY(0)';
                panel.style.opacity = '1';
            });
        }
    }

    function hideOverlay() {
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        overlay.classList.remove('pc-lottie-overlay--success');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        setSuccessMode(false);
    }

    function fetchAnimationData() {
        if (inlineAnimationData) {
            animationData = inlineAnimationData;
            return Promise.resolve(animationData);
        }
        if (!jsonUrl) {
            return Promise.resolve(null);
        }
        if (animationData) {
            return Promise.resolve(animationData);
        }
        return fetch(jsonUrl, { credentials: 'same-origin', cache: 'force-cache' })
            .then(function (r) {
                if (!r.ok) throw new Error('Lottie JSON ' + r.status);
                return r.json();
            })
            .then(function (data) {
                animationData = data;
                return data;
            });
    }

    function buildPreloadedAnimation() {
        if (preloadedAnim || typeof lottie === 'undefined' || !animationData || !container) {
            return preloadedAnim;
        }
        preloadedAnim = lottie.loadAnimation({
            container: container,
            renderer: 'svg',
            loop: false,
            autoplay: false,
            animationData: animationData,
            rendererSettings: {
                progressiveLoad: false,
                hideOnTransparent: true
            }
        });
        function markLottieReady() {
            if (!container) return;
            container.classList.add('has-lottie', 'is-ready');
            container.classList.remove('show-fallback');
        }
        preloadedAnim.addEventListener('data_ready', markLottieReady);
        preloadedAnim.addEventListener('DOMLoaded', markLottieReady);
        preloadedAnim.goToAndStop(0, true);
        if (preloadedAnim.isLoaded) {
            markLottieReady();
        }
        return preloadedAnim;
    }

    function ensurePreloaded() {
        if (preloadPromise) {
            return preloadPromise;
        }
        preloadPromise = new Promise(function (resolve) {
            function done() {
                buildPreloadedAnimation();
                if (container) {
                    container.classList.add('is-ready');
                    if (preloadedAnim) {
                        container.classList.remove('show-fallback');
                    }
                }
                resolve(preloadedAnim);
            }
            if (typeof lottie === 'undefined') {
                resolve(null);
                return;
            }
            fetchAnimationData()
                .then(done)
                .catch(function () {
                    showFallbackSuccess();
                    resolve(null);
                });
        });
        return preloadPromise;
    }

    function bindCompleteOnce(onComplete, isSuccess) {
        if (!preloadedAnim || completeBound) {
            if (isSuccess) {
                revealSuccessActions();
            } else if (typeof onComplete === 'function') {
                onComplete();
            }
            return;
        }
        completeBound = true;
        var done = false;
        function finish() {
            if (done) return;
            done = true;
            clearRedirectTimer();
            if (isSuccess) {
                revealSuccessActions();
            } else if (typeof onComplete === 'function') {
                onComplete();
            }
        }
        preloadedAnim.addEventListener('complete', finish);
        redirectTimer = setTimeout(finish, isSuccess ? successDurationMs : 60000);
    }

    function showFallbackSuccess() {
        if (!container) return;
        container.classList.add('is-ready', 'show-fallback');
        container.classList.remove('has-lottie');
    }

    function startPlayback(onComplete, isSuccess) {
        if (preloadedAnim) {
            try {
                preloadedAnim.goToAndPlay(0, true);
            } catch (e) { /* ignore */ }
            bindCompleteOnce(onComplete, !!isSuccess);
            return;
        }
        clearRedirectTimer();
        if (isSuccess) {
            showFallbackSuccess();
            revealSuccessActions();
        } else if (typeof onComplete === 'function') {
            redirectTimer = setTimeout(onComplete, 1500);
        }
    }

    function playAnimation(message, onComplete, isSuccess) {
        if (mobileOnly && !isMobileViewport()) {
            if (isSuccess) {
                markLottieSuccessHandled();
            } else if (typeof onComplete === 'function') {
                onComplete();
            }
            return;
        }
        if (isSuccess) {
            markLottieSuccessHandled();
        }
        showOverlay(message, !!isSuccess);
        pendingPlay = { onComplete: onComplete, isSuccess: !!isSuccess };
        ensurePreloaded().then(function () {
            if (!pendingPlay) return;
            var cb = pendingPlay.onComplete;
            var isSuccess = pendingPlay.isSuccess;
            pendingPlay = null;
            startPlayback(cb, isSuccess);
        });
    }

    function goView() {
        if (dismissed) return;
        dismissed = true;
        clearRedirectTimer();
        if (viewUrl) {
            window.location.href = viewUrl;
        } else {
            goRedirect();
        }
    }

    window.PcLottieOverlay = {
        showSubmitting: function () {
            playAnimation(submitMessage, null, false);
        },
        showSuccess: function () {
            playAnimation(successMessage, null, true);
        }
    };

    if (btnOkay) {
        btnOkay.addEventListener('click', dismissSuccess);
    }
    if (btnView) {
        if (!viewUrl) {
            btnView.hidden = true;
        } else {
            btnView.addEventListener('click', goView);
        }
    }

    function validateTopupForm(form) {
        var petty = form.querySelector('[name="petty_cash_account_id"]');
        var source = form.querySelector('[name="source_account_id"]');
        var amtEl = form.querySelector('input[name="amount"]');
        var descEl = form.querySelector('[name="description"]');
        var pettyId = petty ? parseInt(petty.value, 10) : 0;
        var sourceId = source ? parseInt(source.value, 10) : 0;
        var amt = amtEl ? parseFloat(amtEl.value, 10) : 0;
        var desc = descEl ? descEl.value.trim() : '';
        if (pettyId <= 0 || sourceId <= 0) {
            alert('Please select both petty cash and source accounts.');
            return false;
        }
        if (pettyId === sourceId) {
            alert('Source and petty cash accounts must be different.');
            return false;
        }
        if (!(amt > 0)) {
            alert('Amount must be greater than zero.');
            return false;
        }
        if (desc === '') {
            alert('Please enter a reason or description.');
            return false;
        }
        return true;
    }

    function validateVoucherForm(form) {
        var amtEl = form.querySelector('input[name="amount"]');
        var amt = amtEl ? parseFloat(amtEl.value, 10) : 0;
        var newCatEl = document.getElementById('new_category_name');
        var catEl = document.getElementById('category');
        var newCat = newCatEl ? newCatEl.value.trim() : '';
        var selectedCat = catEl ? catEl.value.trim() : '';
        if (!(amt > 0)) {
            alert('Amount must be greater than zero.');
            return false;
        }
        if (!newCat && !selectedCat) {
            alert('Please select a category or enter a new petty cash category name.');
            return false;
        }
        return true;
    }

    function bindOneForm(formId) {
        var form = document.getElementById(formId);
        if (!form || form.dataset.pcLottieBound) return;
        if (mobileOnly && !isMobileViewport()) return;
        form.dataset.pcLottieBound = '1';
        var allowSubmit = false;
        var skipValidation = <?= !empty($pc_lottie_skip_validation) ? 'true' : 'false' ?>;
        var isTopup = formId === 'topupRequestForm';
        var isConfirmApprove = formId === 'pcConfirmApproveForm';
        var isVoucherForm = formId === 'voucher-form';
        form.addEventListener('submit', function (e) {
            if (allowSubmit) return;
            if (!skipValidation) {
                var valid = isTopup
                    ? validateTopupForm(form)
                    : ((isConfirmApprove || isVoucherForm) ? true : validateVoucherForm(form));
                if (!valid) {
                    e.preventDefault();
                    return;
                }
            }
            e.preventDefault();
            window.PcLottieOverlay.showSubmitting();
            allowSubmit = true;
            requestAnimationFrame(function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    }

    function bindForm() {
        var formIds = <?= json_encode(
            (isset($pc_lottie_form_ids) && is_array($pc_lottie_form_ids) && $pc_lottie_form_ids !== [])
                ? $pc_lottie_form_ids
                : [$pc_lottie_form_id],
            JSON_UNESCAPED_UNICODE
        ) ?>;
        formIds.forEach(bindOneForm);
    }

    function boot() {
        bindForm();
        ensurePreloaded();
        if (showSuccessOnLoad) {
            markLottieSuccessHandled();
            if (mobileOnly && !isMobileViewport()) {
                /* Balances: desktop uses no SweetAlert after save; mobile uses bottom sheet only */
            } else {
                showOverlay(successMessage, true);
                revealSuccessActions();
                ensurePreloaded().then(function () {
                    startPlayback(null, true);
                });
            }
        }
        document.dispatchEvent(new CustomEvent('pc-lottie-ready'));
    }

    if (typeof lottie !== 'undefined') {
        boot();
    } else {
        var lib = document.getElementById('pc-lottie-lib-js');
        if (lib) {
            lib.addEventListener('load', boot);
            lib.addEventListener('error', boot);
        }
        setTimeout(boot, 3000);
    }
})();
</script>
