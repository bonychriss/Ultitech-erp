<?php
/**
 * Mobile bottom-sheet success overlay with Lottie animation.
 */
$supplier_lottie_show = !empty($supplier_lottie_show);
$supplier_lottie_message = trim((string) ($supplier_lottie_message ?? 'Supplier added successfully!'));
$supplier_lottie_view_url = trim((string) ($supplier_lottie_view_url ?? ''));
$supplier_lottie_view_label = trim((string) ($supplier_lottie_view_label ?? 'View supplier'));
$supplier_lottie_okay_label = trim((string) ($supplier_lottie_okay_label ?? 'Continue'));

$supplier_lottie_json_href = '';
if (function_exists('app_url')) {
    $supplier_lottie_json_href = app_url('/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json');
} else {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(.*)/(?:ultimate/)?stock/#', $script, $m)) {
        $supplier_lottie_json_href = $m[1] . '/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json';
    } else {
        $supplier_lottie_json_href = '/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json';
    }
}

$supplier_lottie_file = dirname(__DIR__, 4) . '/loadinganimations/5917507f-1b43-406a-a000-34a2165ce8eb.json';
$supplier_lottie_inline = null;
if ($supplier_lottie_show && is_readable($supplier_lottie_file)) {
    $decoded = json_decode((string) file_get_contents($supplier_lottie_file), true);
    if (is_array($decoded)) {
        $supplier_lottie_inline = $decoded;
    }
}
$supplier_lottie_lib = 'https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js';
?>
<link rel="preload" href="<?= htmlspecialchars($supplier_lottie_lib, ENT_QUOTES, 'UTF-8') ?>" as="script" crossorigin="anonymous">
<?php if ($supplier_lottie_json_href !== ''): ?>
<link rel="preload" href="<?= htmlspecialchars($supplier_lottie_json_href, ENT_QUOTES, 'UTF-8') ?>" as="fetch" crossorigin="anonymous">
<?php endif; ?>
<style>
    .sp-lottie-overlay {
        position: fixed;
        inset: 0;
        z-index: 10060;
        display: none;
        align-items: flex-end;
        justify-content: center;
        padding: 0;
    }
    .sp-lottie-overlay.is-visible { display: flex; }
    .sp-lottie-overlay__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
        opacity: 0;
    }
    .sp-lottie-overlay.is-visible .sp-lottie-overlay__backdrop {
        animation: sp-lottie-backdrop-in 0.28s ease-out forwards;
    }
    .sp-lottie-overlay__panel {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 100%;
        background: #fff;
        border-radius: 20px 20px 0 0;
        box-shadow: 0 -10px 40px rgba(15, 23, 42, 0.16);
        padding: 0.5rem 1.25rem calc(1.25rem + env(safe-area-inset-bottom, 0px));
        transform: translateY(100%);
    }
    .sp-lottie-overlay.is-visible .sp-lottie-overlay__panel {
        animation: sp-lottie-sheet-up 0.42s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    .sp-lottie-overlay__handle {
        display: block;
        width: 42px;
        height: 4px;
        margin: 0.35rem auto 0.75rem;
        border-radius: 999px;
        background: #cbd5e1;
    }
    .sp-lottie-overlay__anim {
        width: 100%;
        max-width: 240px;
        height: 200px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sp-lottie-overlay__anim canvas,
    .sp-lottie-overlay__anim svg {
        width: 100% !important;
        height: 100% !important;
    }
    .sp-lottie-overlay__spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #e2e8f0;
        border-top-color: #7c3aed;
        border-radius: 50%;
        animation: sp-lottie-spin 0.65s linear infinite;
    }
    .sp-lottie-overlay__anim.is-ready .sp-lottie-overlay__spinner { display: none; }
    .sp-lottie-fallback {
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
    .sp-lottie-overlay__anim.show-fallback .sp-lottie-fallback { display: flex; }
    .sp-lottie-overlay__msg {
        margin: 0.35rem 0 0;
        font-size: 1rem;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.4;
        text-align: center;
    }
    .sp-lottie-overlay__actions {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        margin-top: 1rem;
    }
    .sp-lottie-btn {
        min-height: 48px;
        padding: 0.7rem 1rem;
        border-radius: 12px;
        font-size: 0.9375rem;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        border: none;
        width: 100%;
    }
    .sp-lottie-btn--primary { background: #7c3aed; color: #fff; }
    .sp-lottie-btn--ghost {
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    @keyframes sp-lottie-sheet-up {
        from { transform: translateY(100%); opacity: 0.9; }
        to { transform: translateY(0); opacity: 1; }
    }
    @keyframes sp-lottie-backdrop-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes sp-lottie-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .sp-lottie-overlay__panel,
        .sp-lottie-overlay.is-visible .sp-lottie-overlay__panel {
            animation: none;
            transform: none;
        }
    }
</style>

<div id="sp-supplier-lottie-overlay" class="sp-lottie-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="sp-supplier-lottie-msg">
    <div class="sp-lottie-overlay__backdrop" data-sp-lottie-dismiss></div>
    <div class="sp-lottie-overlay__panel">
        <span class="sp-lottie-overlay__handle" aria-hidden="true"></span>
        <div id="sp-supplier-lottie-container" class="sp-lottie-overlay__anim">
            <div class="sp-lottie-overlay__spinner" aria-hidden="true"></div>
            <div class="sp-lottie-fallback" aria-hidden="true"><i class="fas fa-circle-check"></i></div>
        </div>
        <p id="sp-supplier-lottie-msg" class="sp-lottie-overlay__msg"></p>
        <div class="sp-lottie-overlay__actions">
            <?php if ($supplier_lottie_view_url !== ''): ?>
                <button type="button" class="sp-lottie-btn sp-lottie-btn--ghost" id="sp-supplier-lottie-view"><?= htmlspecialchars($supplier_lottie_view_label, ENT_QUOTES, 'UTF-8') ?></button>
            <?php endif; ?>
            <button type="button" class="sp-lottie-btn sp-lottie-btn--primary" id="sp-supplier-lottie-okay"><?= htmlspecialchars($supplier_lottie_okay_label, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($supplier_lottie_lib, ENT_QUOTES, 'UTF-8') ?>" crossorigin="anonymous" id="sp-supplier-lottie-lib"></script>
<script>
(function () {
    var jsonUrl = <?= json_encode($supplier_lottie_json_href, JSON_UNESCAPED_SLASHES) ?>;
    var inlineData = <?= $supplier_lottie_inline !== null
        ? json_encode($supplier_lottie_inline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : 'null' ?>;
    var viewUrl = <?= json_encode($supplier_lottie_view_url, JSON_UNESCAPED_SLASHES) ?>;
    var showOnLoad = <?= $supplier_lottie_show ? 'true' : 'false' ?>;
    var loadMessage = <?= json_encode($supplier_lottie_message, JSON_UNESCAPED_UNICODE) ?>;

    var overlay = document.getElementById('sp-supplier-lottie-overlay');
    var container = document.getElementById('sp-supplier-lottie-container');
    var msgEl = document.getElementById('sp-supplier-lottie-msg');
    var btnOkay = document.getElementById('sp-supplier-lottie-okay');
    var btnView = document.getElementById('sp-supplier-lottie-view');
    var anim = null;
    var animData = null;
    var closed = false;

    function isMobileViewport() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    function hideOverlay() {
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function dismiss() {
        if (closed) return;
        closed = true;
        hideOverlay();
    }

    function showOverlay(message) {
        if (!overlay || !isMobileViewport()) return false;
        closed = false;
        if (msgEl) msgEl.textContent = message || '';
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        return true;
    }

    function fetchData() {
        if (inlineData) {
            animData = inlineData;
            return Promise.resolve(animData);
        }
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
            })
            .catch(function () {
                if (container) container.classList.add('is-ready', 'show-fallback');
            });
    }

    window.StockSupplierSuccessLottie = {
        show: function (message) {
            if (!isMobileViewport()) return false;
            play(message || loadMessage);
            return true;
        },
        isMobile: isMobileViewport
    };

    if (btnOkay) btnOkay.addEventListener('click', dismiss);
    if (btnView && viewUrl) {
        btnView.addEventListener('click', function () {
            window.location.href = viewUrl;
        });
    }
    overlay.querySelectorAll('[data-sp-lottie-dismiss]').forEach(function (el) {
        el.addEventListener('click', dismiss);
    });

    function preload() {
        fetchData().then(function () {
            buildAnim();
        }).catch(function () { /* ignore */ });
    }

    if (typeof lottie !== 'undefined') {
        preload();
    } else {
        var lib = document.getElementById('sp-supplier-lottie-lib');
        if (lib) lib.addEventListener('load', preload);
    }
})();
</script>
