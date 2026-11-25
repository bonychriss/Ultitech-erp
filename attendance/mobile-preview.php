<?php
/**
 * Mobile phone preview for Attendance desk.
 * URL: /attendance/mobile-preview.php?module=attendance
 *      /{company}/attendance/mobile-preview.php?module=attendance
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
if ($slug === '' && function_exists('getRequestedCompanySlug')) {
    $slug = trim((string) getRequestedCompanySlug());
}
if ($slug === '') {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/([A-Za-z0-9-]+)/attendance/#i', $script, $m)) {
        $slug = strtolower($m[1]);
    }
}

$deskPath = 'attendance/index.php?module=attendance';
$deskUrl = function_exists('company_url')
    ? (string) company_url($deskPath, $slug !== '' ? $slug : null)
    : (function_exists('app_url') ? (string) app_url('/' . ($slug !== '' ? $slug . '/' : '') . $deskPath) : '/' . $deskPath);

$phones = [
    ['id' => 'iphone-se', 'label' => 'iPhone SE', 'group' => 'Apple', 'w' => 375, 'h' => 667, 'radius' => 36],
    ['id' => 'iphone-12-mini', 'label' => 'iPhone 12 mini', 'group' => 'Apple', 'w' => 360, 'h' => 780, 'radius' => 40],
    ['id' => 'iphone-13', 'label' => 'iPhone 13', 'group' => 'Apple', 'w' => 390, 'h' => 844, 'radius' => 44],
    ['id' => 'iphone-14', 'label' => 'iPhone 14', 'group' => 'Apple', 'w' => 390, 'h' => 844, 'radius' => 44],
    ['id' => 'iphone-14-pro', 'label' => 'iPhone 14 Pro', 'group' => 'Apple', 'w' => 393, 'h' => 852, 'radius' => 48],
    ['id' => 'iphone-14-pro-max', 'label' => 'iPhone 14 Pro Max', 'group' => 'Apple', 'w' => 430, 'h' => 932, 'radius' => 48],
    ['id' => 'iphone-15-pro', 'label' => 'iPhone 15 Pro', 'group' => 'Apple', 'w' => 393, 'h' => 852, 'radius' => 50],
    ['id' => 'iphone-15-pro-max', 'label' => 'iPhone 15 Pro Max', 'group' => 'Apple', 'w' => 430, 'h' => 932, 'radius' => 50],
    ['id' => 'iphone-16', 'label' => 'iPhone 16', 'group' => 'Apple', 'w' => 393, 'h' => 852, 'radius' => 50],
    ['id' => 'iphone-16-pro-max', 'label' => 'iPhone 16 Pro Max', 'group' => 'Apple', 'w' => 440, 'h' => 956, 'radius' => 52],
    ['id' => 'pixel-5', 'label' => 'Pixel 5', 'group' => 'Google', 'w' => 393, 'h' => 851, 'radius' => 36],
    ['id' => 'pixel-7', 'label' => 'Pixel 7', 'group' => 'Google', 'w' => 412, 'h' => 915, 'radius' => 40],
    ['id' => 'pixel-8', 'label' => 'Pixel 8', 'group' => 'Google', 'w' => 412, 'h' => 915, 'radius' => 42],
    ['id' => 'pixel-8-pro', 'label' => 'Pixel 8 Pro', 'group' => 'Google', 'w' => 448, 'h' => 998, 'radius' => 44],
    ['id' => 'galaxy-s21', 'label' => 'Galaxy S21', 'group' => 'Samsung', 'w' => 360, 'h' => 800, 'radius' => 36],
    ['id' => 'galaxy-s23', 'label' => 'Galaxy S23', 'group' => 'Samsung', 'w' => 360, 'h' => 780, 'radius' => 38],
    ['id' => 'galaxy-s24', 'label' => 'Galaxy S24', 'group' => 'Samsung', 'w' => 360, 'h' => 780, 'radius' => 40],
    ['id' => 'galaxy-s24-ultra', 'label' => 'Galaxy S24 Ultra', 'group' => 'Samsung', 'w' => 412, 'h' => 915, 'radius' => 28],
    ['id' => 'galaxy-z-fold', 'label' => 'Galaxy Z Fold (cover)', 'group' => 'Samsung', 'w' => 344, 'h' => 882, 'radius' => 28],
    ['id' => 'oneplus-12', 'label' => 'OnePlus 12', 'group' => 'Other', 'w' => 412, 'h' => 919, 'radius' => 40],
    ['id' => 'xiaomi-14', 'label' => 'Xiaomi 14', 'group' => 'Other', 'w' => 390, 'h' => 844, 'radius' => 42],
];

$active = strtolower(trim((string) ($_GET['phone'] ?? 'iphone-14')));
$validIds = array_merge(['all'], array_column($phones, 'id'));
if (!in_array($active, $validIds, true)) {
    // Back-compat for older short ids
    $legacy = [
        'se' => 'iphone-se',
        'pro' => 'iphone-14',
        'max' => 'iphone-14-pro-max',
    ];
    $active = $legacy[$active] ?? 'iphone-14';
}

$activeLabel = 'All phones';
if ($active !== 'all') {
    foreach ($phones as $phone) {
        if ($phone['id'] === $active) {
            $activeLabel = $phone['label'] . ' · ' . $phone['w'] . '×' . $phone['h'];
            break;
        }
    }
}

$groups = [];
foreach ($phones as $phone) {
    $groups[$phone['group']][] = $phone;
}

$pageTitle = 'Attendance · Mobile preview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --bg: #0b1220;
            --text: #e5e7eb;
            --muted: #94a3b8;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            height: 100%;
            background:
                radial-gradient(1200px 500px at 50% -10%, #1e293b 0%, transparent 55%),
                var(--bg);
            color: var(--text);
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100dvh;
            min-height: 100vh;
        }
        .mp-top {
            flex: 0 0 auto;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem 1rem;
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(15, 23, 42, 0.92);
            z-index: 5;
        }
        .mp-top h1 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 650;
            letter-spacing: -0.02em;
        }
        .mp-top p {
            margin: 0.15rem 0 0;
            color: var(--muted);
            font-size: 0.82rem;
        }
        .mp-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
            align-items: center;
        }
        .mp-menu {
            position: relative;
        }
        .mp-menu-btn, .mp-link {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(15, 23, 42, 0.55);
            color: var(--text);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }
        .mp-menu-btn {
            min-width: 220px;
            justify-content: space-between;
        }
        .mp-menu-btn:hover, .mp-link:hover, .mp-menu.is-open .mp-menu-btn {
            border-color: rgba(56, 189, 248, 0.55);
        }
        .mp-menu-btn .mp-caret {
            font-size: 0.65rem;
            opacity: 0.8;
            transition: transform 0.15s ease;
        }
        .mp-menu.is-open .mp-caret {
            transform: rotate(180deg);
        }
        .mp-menu-panel {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            width: min(320px, 86vw);
            max-height: min(420px, 60vh);
            overflow: auto;
            padding: 0.4rem;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: #0f172a;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
            z-index: 20;
        }
        .mp-menu.is-open .mp-menu-panel {
            display: block;
        }
        .mp-menu-group {
            padding: 0.35rem 0.45rem 0.2rem;
            color: var(--muted);
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .mp-menu-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            width: 100%;
            padding: 0.5rem 0.65rem;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--text);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 560;
            cursor: pointer;
        }
        .mp-menu-item:hover {
            background: rgba(56, 189, 248, 0.12);
        }
        .mp-menu-item.is-active {
            background: rgba(14, 165, 233, 0.18);
            color: #e0f2fe;
        }
        .mp-menu-meta {
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .mp-stage {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            align-content: center;
            gap: 1.5rem 2rem;
            padding: 1rem 1.25rem;
            overflow: auto;
        }
        .mp-phone-wrap {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 0.55rem;
        }
        .mp-phone-wrap.is-active,
        .mp-phone-wrap.is-all {
            display: flex;
        }
        .mp-label {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            flex: 0 0 auto;
        }
        .mp-fit {
            width: calc((var(--w) + 24px) * var(--fit, 1));
            height: calc((var(--h) + 24px) * var(--fit, 1));
            position: relative;
        }
        .mp-device {
            position: absolute;
            top: 0;
            left: 0;
            width: calc(var(--w) + 24px);
            height: calc(var(--h) + 24px);
            transform: scale(var(--fit, 1));
            transform-origin: top left;
            background: linear-gradient(160deg, #1f2937, #020617 55%, #000);
            padding: 12px;
            border-radius: calc(var(--r) + 10px);
            box-shadow:
                0 0 0 2px #334155,
                0 22px 50px rgba(0, 0, 0, 0.55),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }
        .mp-screen {
            position: relative;
            width: var(--w);
            height: var(--h);
            border-radius: var(--r);
            overflow: hidden;
            background: #020617;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.06);
        }
        .mp-notch {
            position: absolute;
            top: 8px;
            left: 50%;
            transform: translateX(-50%);
            width: 34%;
            height: 22px;
            border-radius: 14px;
            background: #000;
            z-index: 2;
            pointer-events: none;
        }
        .mp-screen iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            background: #fff;
        }
        .mp-hint {
            flex: 0 0 auto;
            text-align: center;
            color: var(--muted);
            font-size: 0.78rem;
            padding: 0.35rem 1rem 0.75rem;
        }
        .mp-hint code {
            color: #cbd5e1;
            background: rgba(148, 163, 184, 0.12);
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
            font-size: 0.74rem;
        }
    </style>
</head>
<body>
    <header class="mp-top">
        <div>
            <h1>Mobile display preview</h1>
            <p>Attendance desk inside phone frames</p>
        </div>
        <div class="mp-actions">
            <div class="mp-menu" id="mpPhoneMenu">
                <button type="button" class="mp-menu-btn" id="mpPhoneMenuBtn" aria-haspopup="listbox" aria-expanded="false">
                    <span id="mpPhoneMenuLabel"><?= htmlspecialchars($activeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="mp-caret" aria-hidden="true">?</span>
                </button>
                <div class="mp-menu-panel" role="listbox" aria-label="Phone models">
                    <a
                        class="mp-menu-item<?= $active === 'all' ? ' is-active' : '' ?>"
                        href="?module=attendance&amp;phone=all"
                        role="option"
                        aria-selected="<?= $active === 'all' ? 'true' : 'false' ?>"
                    >
                        <span>All phones</span>
                        <span class="mp-menu-meta"><?= count($phones) ?></span>
                    </a>
                    <?php foreach ($groups as $groupName => $groupPhones): ?>
                        <div class="mp-menu-group"><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php foreach ($groupPhones as $phone): ?>
                            <a
                                class="mp-menu-item<?= $active === $phone['id'] ? ' is-active' : '' ?>"
                                href="?module=attendance&amp;phone=<?= rawurlencode($phone['id']) ?>"
                                role="option"
                                aria-selected="<?= $active === $phone['id'] ? 'true' : 'false' ?>"
                            >
                                <span><?= htmlspecialchars($phone['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="mp-menu-meta"><?= (int) $phone['w'] ?>×<?= (int) $phone['h'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <a class="mp-link" href="<?= htmlspecialchars($deskUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open desk ?</a>
        </div>
    </header>

    <main class="mp-stage" id="mpStage">
        <?php foreach ($phones as $phone):
            $show = ($active === 'all' || $active === $phone['id']);
            $cls = $show ? ($active === 'all' ? 'is-all' : 'is-active') : '';
            ?>
            <section
                class="mp-phone-wrap <?= $cls ?>"
                data-phone="<?= htmlspecialchars($phone['id'], ENT_QUOTES, 'UTF-8') ?>"
            >
                <div class="mp-label">
                    <?= htmlspecialchars($phone['label'], ENT_QUOTES, 'UTF-8') ?>
                    · <?= (int) $phone['w'] ?>×<?= (int) $phone['h'] ?>
                </div>
                <div
                    class="mp-fit"
                    data-natural-w="<?= (int) $phone['w'] + 24 ?>"
                    data-natural-h="<?= (int) $phone['h'] + 24 ?>"
                >
                    <div
                        class="mp-device"
                        style="--w: <?= (int) $phone['w'] ?>px; --h: <?= (int) $phone['h'] ?>px; --r: <?= (int) $phone['radius'] ?>px;"
                    >
                        <div class="mp-screen">
                            <div class="mp-notch" aria-hidden="true"></div>
                            <iframe
                                title="<?= htmlspecialchars($phone['label'] . ' attendance preview', ENT_QUOTES, 'UTF-8') ?>"
                                src="<?= htmlspecialchars($deskUrl, ENT_QUOTES, 'UTF-8') ?>"
                                loading="lazy"
                            ></iframe>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </main>

    <p class="mp-hint">
        Preview URL:
        <code><?= htmlspecialchars($deskUrl, ENT_QUOTES, 'UTF-8') ?></code>
        · Phone stays fully visible; browser zoom still works
    </p>

    <script>
    (function () {
        var menu = document.getElementById('mpPhoneMenu');
        var btn = document.getElementById('mpPhoneMenuBtn');
        if (menu && btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = menu.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function () {
                menu.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            });
            menu.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        }

        var stage = document.getElementById('mpStage');
        if (!stage) return;

        function readZoom() {
            if (window.visualViewport && window.visualViewport.scale) {
                return window.visualViewport.scale;
            }
            if (window.outerWidth && window.innerWidth) {
                return window.outerWidth / window.innerWidth;
            }
            return 1;
        }

        var baseZoom = readZoom();
        var userZoomed = false;

        function fitPhones() {
            if (userZoomed) return;

            var wraps = stage.querySelectorAll('.mp-phone-wrap.is-active, .mp-phone-wrap.is-all');
            if (!wraps.length) return;

            var stageStyle = window.getComputedStyle(stage);
            var padX = (parseFloat(stageStyle.paddingLeft) || 0) + (parseFloat(stageStyle.paddingRight) || 0);
            var padY = (parseFloat(stageStyle.paddingTop) || 0) + (parseFloat(stageStyle.paddingBottom) || 0);
            var availW = Math.max(120, stage.clientWidth - padX);
            var availH = Math.max(160, stage.clientHeight - padY);

            var count = wraps.length;
            var gap = count > 1 ? 32 : 0;
            var cols = count > 1 ? Math.min(count, Math.max(1, Math.floor(availW / 220))) : 1;
            var perW = cols > 1 ? (availW - gap * (cols - 1)) / cols : availW;

            wraps.forEach(function (wrap) {
                var fit = wrap.querySelector('.mp-fit');
                var label = wrap.querySelector('.mp-label');
                if (!fit) return;
                var natW = parseFloat(fit.getAttribute('data-natural-w')) || 414;
                var natH = parseFloat(fit.getAttribute('data-natural-h')) || 868;
                var labelH = (label ? label.offsetHeight : 18) + 10;
                var scale = Math.min(1, perW / natW, (availH - labelH) / natH);
                if (!isFinite(scale) || scale <= 0) scale = 1;
                fit.style.setProperty('--fit', String(scale));
            });
        }

        function onViewportChange() {
            var z = readZoom();
            if (Math.abs(z - baseZoom) > 0.04) {
                userZoomed = true;
                return;
            }
            userZoomed = false;
            fitPhones();
        }

        fitPhones();
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('orientationchange', function () {
            userZoomed = false;
            baseZoom = readZoom();
            setTimeout(fitPhones, 80);
        });
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', onViewportChange);
        }

        stage.addEventListener('dblclick', function () {
            userZoomed = false;
            baseZoom = readZoom();
            fitPhones();
        });
    })();
    </script>
</body>
</html>
