<?php
/**
 * Task add/edit success  Lottie overlay (bottom sheet on mobile, centered on desktop).
 */
$todo_lottie_message = trim((string) ($todo_lottie_message ?? 'Task added successfully!'));
$todo_lottie_okay_label = trim((string) ($todo_lottie_okay_label ?? 'Done'));

$todo_lottie_json_href = function_exists('app_url')
    ? app_url('/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json')
    : '/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json';

$todo_lottie_file = dirname(__DIR__, 2) . '/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json';
if (!is_readable($todo_lottie_file)) {
    $todo_lottie_file = dirname(__DIR__, 2) . '/modules/balances/assets/lottie/voucher-success.json';
    if (is_readable($todo_lottie_file) && function_exists('app_url')) {
        $todo_lottie_json_href = app_url('/modules/balances/assets/lottie/voucher-success.json');
    }
}

$todo_lottie_lib = 'https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js';
?>
<link rel="preload" href="<?= htmlspecialchars($todo_lottie_lib, ENT_QUOTES, 'UTF-8') ?>" as="script" crossorigin="anonymous">
<link rel="preload" href="<?= htmlspecialchars($todo_lottie_json_href, ENT_QUOTES, 'UTF-8') ?>" as="fetch" crossorigin="anonymous">
<style>
    .todo-lottie-overlay {
        position: fixed;
        inset: 0;
        z-index: 10070;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .todo-lottie-overlay.is-visible { display: flex; }
    .todo-lottie-overlay__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
    }
    .todo-lottie-overlay__panel {
        position: relative;
        z-index: 1;
        width: min(92vw, 360px);
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
        padding: 1.25rem 1.25rem calc(1.25rem + env(safe-area-inset-bottom, 0px));
        text-align: center;
    }
    .todo-lottie-overlay__handle { display: none; }
    .todo-lottie-overlay__anim {
        width: 100%;
        max-width: 240px;
        height: 200px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .todo-lottie-overlay__anim canvas,
    .todo-lottie-overlay__anim svg {
        width: 100% !important;
        height: 100% !important;
    }
    .todo-lottie-overlay__spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #e2e8f0;
        border-top-color: #94a3ff;
        border-radius: 50%;
        animation: todo-lottie-spin 0.65s linear infinite;
    }
    .todo-lottie-overlay__anim.is-ready .todo-lottie-overlay__spinner { display: none; }
    .todo-lottie-fallback {
        display: none;
        width: 88px;
        height: 88px;
        border-radius: 50%;
        background: #dcfce7;
        color: #16a34a;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }
    .todo-lottie-overlay__anim.show-fallback .todo-lottie-fallback { display: flex; }
    .todo-lottie-overlay__msg {
        margin: 0.35rem 0 0;
        font-size: 1rem;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.4;
    }
    .todo-lottie-overlay__actions { margin-top: 1rem; }
    .todo-lottie-btn {
        min-height: 48px;
        width: 100%;
        padding: 0.7rem 1rem;
        border-radius: 12px;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        border: none;
        background: linear-gradient(135deg, #94a3ff 0%, #7f9eff 100%);
        color: #fff;
    }
    @keyframes todo-lottie-spin { to { transform: rotate(360deg); } }
    @keyframes todo-lottie-sheet-up {
        from { transform: translateY(100%); opacity: 0.9; }
        to { transform: translateY(0); opacity: 1; }
    }
    @keyframes todo-lottie-sheet-down {
        from { transform: translateY(0); opacity: 1; }
        to { transform: translateY(100%); opacity: 0.85; }
    }
    @keyframes todo-lottie-panel-down {
        from { transform: translateY(0) scale(1); opacity: 1; }
        to { transform: translateY(48px) scale(0.98); opacity: 0; }
    }
    .todo-lottie-overlay.is-closing .todo-lottie-overlay__backdrop {
        opacity: 0;
        transition: opacity 0.34s ease;
    }
    .todo-lottie-overlay.is-closing .todo-lottie-overlay__panel {
        animation: todo-lottie-panel-down 0.38s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }
    @media (max-width: 768px) {
        .todo-lottie-overlay {
            align-items: flex-end;
            padding: 0;
        }
        .todo-lottie-overlay__panel {
            width: 100%;
            max-width: 100%;
            border-radius: 20px 20px 0 0;
            transform: translateY(100%);
            animation: todo-lottie-sheet-up 0.38s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        .todo-lottie-overlay__handle {
            display: block;
            width: 42px;
            height: 4px;
            margin: 0.35rem auto 0.75rem;
            border-radius: 999px;
            background: #cbd5e1;
        }
        .todo-lottie-overlay.is-closing .todo-lottie-overlay__panel {
            animation: todo-lottie-sheet-down 0.38s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
    }
</style>

<div id="todo-task-lottie-overlay" class="todo-lottie-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="todo-task-lottie-msg">
    <div class="todo-lottie-overlay__backdrop" data-todo-lottie-dismiss></div>
    <div class="todo-lottie-overlay__panel">
        <span class="todo-lottie-overlay__handle" aria-hidden="true"></span>
        <div id="todo-task-lottie-container" class="todo-lottie-overlay__anim">
            <div class="todo-lottie-overlay__spinner" aria-hidden="true"></div>
            <div class="todo-lottie-fallback" aria-hidden="true"><i class="fas fa-circle-check"></i></div>
        </div>
        <p id="todo-task-lottie-msg" class="todo-lottie-overlay__msg"></p>
        <div class="todo-lottie-overlay__actions">
            <button type="button" class="todo-lottie-btn" id="todo-task-lottie-okay"><?= htmlspecialchars($todo_lottie_okay_label, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($todo_lottie_lib, ENT_QUOTES, 'UTF-8') ?>" crossorigin="anonymous" id="todo-task-lottie-lib"></script>
<script>
(function () {
    var jsonUrl = <?= json_encode($todo_lottie_json_href, JSON_UNESCAPED_SLASHES) ?>;
    var defaultMessage = <?= json_encode($todo_lottie_message, JSON_UNESCAPED_UNICODE) ?>;

    var overlay = document.getElementById('todo-task-lottie-overlay');
    var container = document.getElementById('todo-task-lottie-container');
    var msgEl = document.getElementById('todo-task-lottie-msg');
    var btnOkay = document.getElementById('todo-task-lottie-okay');
    var anim = null;
    var animData = null;
    var preloadStarted = false;
    var autoDismissTimer = null;
    var dismissSafetyTimer = null;
    var AUTO_DISMISS_MS = 2600;
    var POST_ANIM_BUFFER_MS = 350;
    var panel = overlay ? overlay.querySelector('.todo-lottie-overlay__panel') : null;

    function clearDismissTimers() {
        if (autoDismissTimer) {
            clearTimeout(autoDismissTimer);
            autoDismissTimer = null;
        }
        if (dismissSafetyTimer) {
            clearTimeout(dismissSafetyTimer);
            dismissSafetyTimer = null;
        }
    }

    function hideOverlayImmediate() {
        if (!overlay) return;
        clearDismissTimers();
        overlay.classList.remove('is-visible', 'is-closing');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('todo-modal-open');
        if (panel) panel.style.animation = '';
    }

    function hideOverlay() {
        if (!overlay || !overlay.classList.contains('is-visible') || overlay.classList.contains('is-closing')) {
            return;
        }
        clearDismissTimers();
        overlay.classList.add('is-closing');
        overlay.setAttribute('aria-hidden', 'true');

        function finish() {
            hideOverlayImmediate();
        }

        if (panel) {
            panel.addEventListener('animationend', finish, { once: true });
        }
        dismissSafetyTimer = setTimeout(finish, 450);
    }

    function scheduleAutoDismiss() {
        clearDismissTimers();
        autoDismissTimer = setTimeout(function () {
            hideOverlay();
        }, POST_ANIM_BUFFER_MS);
    }

    function showOverlay(message) {
        if (!overlay) return false;
        clearDismissTimers();
        overlay.classList.remove('is-closing');
        if (panel) {
            panel.style.animation = 'none';
            void panel.offsetWidth;
            panel.style.animation = '';
        }
        if (msgEl) msgEl.textContent = message || defaultMessage;
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('todo-modal-open');
        return true;
    }

    function fetchData() {
        if (animData) return Promise.resolve(animData);
        if (!jsonUrl) return Promise.resolve(null);
        return fetch(jsonUrl, { credentials: 'same-origin', cache: 'force-cache' })
            .then(function (r) {
                if (!r.ok) throw new Error('lottie');
                return r.json();
            })
            .then(function (data) {
                animData = data;
                return data;
            });
    }

    function buildAnim() {
        if (anim || typeof lottie === 'undefined' || !animData || !container) return anim;
        anim = lottie.loadAnimation({
            container: container,
            renderer: 'svg',
            loop: false,
            autoplay: false,
            animationData: animData
        });
        function ready() {
            container.classList.add('is-ready');
            container.classList.remove('show-fallback');
        }
        anim.addEventListener('DOMLoaded', ready);
        anim.addEventListener('data_ready', ready);
        if (anim.isLoaded) ready();
        return anim;
    }

    function preload() {
        if (preloadStarted) return;
        preloadStarted = true;
        fetchData().then(buildAnim).catch(function () { /* ignore */ });
    }

    function bindAutoDismissAfterPlay() {
        if (anim) {
            var onComplete = function () {
                anim.removeEventListener('complete', onComplete);
                scheduleAutoDismiss();
            };
            anim.addEventListener('complete', onComplete);
            autoDismissTimer = setTimeout(function () {
                hideOverlay();
            }, AUTO_DISMISS_MS);
            return;
        }
        autoDismissTimer = setTimeout(function () {
            hideOverlay();
        }, AUTO_DISMISS_MS);
    }

    function play(message) {
        if (!showOverlay(message)) return false;
        return fetchData()
            .then(function () {
                buildAnim();
                if (anim) {
                    try { anim.goToAndPlay(0, true); } catch (e) { /* ignore */ }
                } else if (container) {
                    container.classList.add('is-ready', 'show-fallback');
                }
                bindAutoDismissAfterPlay();
            })
            .catch(function () {
                if (container) container.classList.add('is-ready', 'show-fallback');
                autoDismissTimer = setTimeout(function () {
                    hideOverlay();
                }, AUTO_DISMISS_MS);
            })
            .then(function () { return true; });
    }

    window.TodoTaskSuccessLottie = {
        show: function (message) {
            play(message || defaultMessage);
            return true;
        },
        preload: preload
    };

    if (btnOkay) btnOkay.addEventListener('click', hideOverlay);
    overlay.querySelectorAll('[data-todo-lottie-dismiss]').forEach(function (el) {
        el.addEventListener('click', hideOverlay);
    });

    function bootLib() {
        preload();
        document.dispatchEvent(new CustomEvent('todo-task-lottie-ready'));
    }

    if (typeof lottie !== 'undefined') {
        bootLib();
    } else {
        var lib = document.getElementById('todo-task-lottie-lib');
        if (lib) lib.addEventListener('load', bootLib);
        setTimeout(bootLib, 2500);
    }
})();
</script>
