<?php

declare(strict_types=1);

/**
 * Public React shell for delivery verification success (final.php).
 */
$deliveriesRoot = dirname(__DIR__);
require_once $deliveriesRoot . '/config/database.php';
require_once dirname($deliveriesRoot) . '/includes/functions.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/load-final-data.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

$assets = deliveriesUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Delivery Verified</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Delivery Verified</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>deliveries/deliveries-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$initPayload = deliveries_load_final_payload($pdo, $_GET);

if (!($initPayload['ok'] ?? false)) {
    if (!empty($initPayload['redirect'])) {
        header('Location: ' . $initPayload['redirect']);
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body style="font-family:sans-serif;padding:2rem;text-align:center;">';
    echo '<h1>Access Denied</h1>';
    echo '<p>' . htmlspecialchars((string) ($initPayload['error'] ?? 'Invalid link.'), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

$initData = $initPayload['data'] ?? [];
$brandName = (string) ($initData['brand']['name'] ?? 'Delivery Verified');

$dlvConfig = [
    'page' => 'final',
    'submitFeedbackUrl' => $assets['submitFeedbackUrl'],
    'data' => $initData,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Verified - <?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__DELIVERIES_CFG__ = <?= json_encode($dlvConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        body.dlv-final-shell {
            margin: 0;
            background: #f8fafc;
            font-family: 'Inter', sans-serif;
        }
        #root { min-height: 100vh; }
    </style>
</head>
<body class="dlv-final-shell">
    <noscript>
        <div style="max-width:600px;margin:2rem auto;padding:1rem;background:#fff;border:1px solid #e2e8f0;text-align:center;">
            JavaScript is required to view this page.
        </div>
    </noscript>
    <div id="root"></div>
    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
