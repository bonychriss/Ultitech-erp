<?php
declare(strict_types=1);

define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: ' . app_url('/free-trial.php'));
    exit;
}

$slug = trim((string) ($_SESSION['company_slug'] ?? ''));
$workspaceUrl = $slug !== ''
    ? company_url('select-module', $slug)
    : app_url('/select-module.php');
// Show the short onboarding guideline on select-module after free-trial signup.
$sep = str_contains($workspaceUrl, '?') ? '&' : '?';
$workspaceUrl .= $sep . 'welcome=1';
$_SESSION['show_trial_guide'] = 1;
$welcomeLottieUrl = app_url('/assets/animations/Welcome.lottie');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Welcome | UltiTech ERP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js" type="module"></script>
    <style>
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            min-height: 100%;
            height: 100%;
        }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px 32px;
            gap: 28px;
        }
        .anim {
            width: min(100vw - 32px, 720px);
            height: min(72vh, 560px);
            display: grid;
            place-items: center;
        }
        dotlottie-wc {
            width: 100%;
            height: 100%;
        }
        .cta {
            display: inline-block;
            color: #2ecc71;
            font-size: 15px;
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .cta:hover {
            color: #27ae60;
        }
    </style>
</head>
<body>
    <div class="anim" aria-hidden="true">
        <dotlottie-wc
            id="welcome-lottie"
            src="<?= htmlspecialchars($welcomeLottieUrl, ENT_QUOTES, 'UTF-8') ?>"
            autoplay
            speed="1"
        ></dotlottie-wc>
    </div>
    <a class="cta" id="continue-link" href="<?= htmlspecialchars($workspaceUrl, ENT_QUOTES, 'UTF-8') ?>">Continue to workspace</a>
    <script>
        (function () {
            var dest = <?= json_encode($workspaceUrl, JSON_UNESCAPED_SLASHES) ?>;
            var gone = false;
            function go() {
                if (gone) return;
                gone = true;
                window.location.replace(dest);
            }

            var el = document.getElementById('welcome-lottie');
            if (el) {
                el.addEventListener('complete', go);
                el.addEventListener('dotlottie-player-complete', go);
            }

            // Fallback if the complete event never fires.
            setTimeout(go, 4500);
        })();
    </script>
</body>
</html>
