<?php

declare(strict_types=1);

/**
 * React shell helpers for the public homepage.
 */

function homeUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    // Keep a trailing slash when the caller passes one (assetBase needs it).
    if (function_exists('app_url')) {
        return (string) app_url('/home-ui/' . $relativePath);
    }
    return '/home-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function homeUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => homeUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

/**
 * @param array<string,mixed> $cfg
 */
function homeUiRenderReactShell(array $cfg): void
{
    $assets = homeUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>UltiTech ERP</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>UltiTech ERP</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>home-ui/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($cfgJson === false) {
        $cfgJson = '{}';
    }

    $cssHref = htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8');
    $jsHref = htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UltiTech ERP | Welcome</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if ($assets['cssFile'] !== ''): ?>
    <link rel="stylesheet" crossorigin href="<?= $cssHref ?>">
    <?php endif; ?>
    <script>
      window.__HOME_CFG__ = <?= $cfgJson ?>;
    </script>
</head>
<body class="home-ui-page">
    <noscript>
        <div style="padding:2rem;font-family:sans-serif;">JavaScript is required to view this page.</div>
    </noscript>
    <div id="root"></div>
    <script type="module" crossorigin src="<?= $jsHref ?>"></script>
</body>
</html>
    <?php
}
