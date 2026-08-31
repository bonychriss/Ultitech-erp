<?php
require_once '../includes/functions.php';
requireLogin();
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

require_once __DIR__ . '/payees-ui/lib.php';

$assets = payeesUiLoadReactAssets();
if ($assets === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Manage Payees</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>Manage Payees</h1>';
    echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/payees-ui/frontend/</code>.</p>';
    echo '</body></html>';
    exit;
}

// Server-render the initial payee list so the page is populated instantly.
$initialPayees = [];
try {
    $stmt = $pdo->query('SELECT id, name, type, tin, contact_details FROM payees WHERE is_active = 1 ORDER BY name ASC');
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $initialPayees[] = [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'type' => (string) ($r['type'] ?? 'Other'),
            'tin' => (string) ($r['tin'] ?? ''),
            'contact_details' => (string) ($r['contact_details'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $initialPayees = [];
}

$payeesConfig = [
    'apiUrl' => $assets['apiUrl'],
    'payees' => $initialPayees,
    'types' => ['Supplier', 'Staff', 'Service Provider', 'Government', 'Other'],
];

$page_title = 'Manage Payees';
$employeeHeaderTitle = '';
$hideHeaderCompanyBranding = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payees</title>
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
        window.__PAYEES_CFG__ = <?= json_encode($payeesConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <style>
        body.dashboard { background-color: #f8fafc; font-family: 'Inter', sans-serif; }
        html, body.dashboard, .main-content, .layout-main-wrapper { scrollbar-width: none !important; -ms-overflow-style: none !important; }
        html::-webkit-scrollbar, body.dashboard::-webkit-scrollbar, .main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none !important; }
        body.dashboard .header, body.dashboard .employee-header { background: transparent !important; border: none !important; box-shadow: none !important; }
        .main-content.payees-react-root { padding: 6px 25px 25px; }
        @media (max-width: 768px) { .main-content.payees-react-root { padding: 6px 15px 15px; } }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

    <main class="main-content payees-react-root">
        <noscript>
            <div class="alert alert-warning">JavaScript is required to manage payees.</div>
        </noscript>
        <div id="root"></div>
    </main>

    <script type="module" crossorigin src="<?= htmlspecialchars($assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
