<?php
require_once '../includes/functions.php';
requireLogin();

require_once __DIR__ . '/ai-assistant-ui/lib.php';
require_once __DIR__ . '/ai-assistant-ui/load-data.php';

$assets = aiAssistantUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>My AI Assistant</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>My AI Assistant</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/ai-assistant-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$companyId = (int) currentCompanyId();
$initPayload = ai_assistant_load_init_payload($pdo, $userId, $companyId, $_GET);
$initData = $initPayload['data'] ?? [];

$aiConfig = [
    'apiUrl' => $assets['apiUrl'],
    'actionUrl' => $assets['actionUrl'],
    'exportUrl' => $assets['exportUrl'],
    'vouchersUrl' => $assets['vouchersUrl'],
    'data' => $initData,
];

$page_title = 'My AI Assistant';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My AI Assistant</title>
    <script>
        (function () {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" crossorigin href="<?= htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__AI_ASSISTANT_CFG__ = <?= json_encode($aiConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        body.dashboard { background: #ffffff; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        body.dashboard .header, body.dashboard .employee-header { background: transparent !important; border: none !important; box-shadow: none !important; }
        .main-content.ai-assistant-react-root {
            padding: 12px 28px 20px;
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 80px);
        }
        .main-content.ai-assistant-react-root #root { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        @media (max-width: 768px) { .main-content.ai-assistant-react-root { padding: 8px 16px 16px; } }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content ai-assistant-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to use the AI assistant.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
