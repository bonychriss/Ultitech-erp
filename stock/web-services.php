<?php
/**
 * Ultimate stock ? ultimate.co.tz
 * URL: /ultimate/stock/web-services
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

if (!function_exists('isUltimate') || !isUltimate()) {
    header('Location: ' . (function_exists('company_url') ? company_url('stock') : 'dashboard.php'));
    exit;
}

const WEB_SYNC_URL = 'https://ultimate.co.tz/ultitech/sync.php?key=ugt7k-sync-4m2p';

function webSyncEnsureTable(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS website_sync_sent (
        product_id INT NOT NULL PRIMARY KEY,
        synced_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function webSyncActiveProducts(PDO $pdo): array
{
    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cols = [];
    }
    $where = '1=1';
    if (in_array('is_active', $cols, true)) {
        $where = 'COALESCE(p.is_active, 1) = 1';
    } elseif (in_array('status', $cols, true)) {
        $where = "LOWER(TRIM(COALESCE(p.status, 'active'))) IN ('active', '1', '')";
    }
    $hasCat = in_array('category_id', $cols, true);
    $sql = 'SELECT p.id, p.product_code, p.name, p.unit_price';
    $sql .= $hasCat ? ", COALESCE(c.name, '') AS category_name" : ", '' AS category_name";
    $sql .= ', COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) AS stock_qty';
    $sql .= ' FROM products p';
    if ($hasCat) {
        $sql .= ' LEFT JOIN categories c ON c.id = p.category_id';
    }
    $sql .= ' WHERE ' . $where . ' ORDER BY p.id DESC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function webSyncSentIds(PDO $pdo): array
{
    webSyncEnsureTable($pdo);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM website_sync_sent')->fetchColumn();
    if ($count === 0) {
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare('INSERT IGNORE INTO website_sync_sent (product_id, synced_at) VALUES (?, ?)');
        foreach (webSyncActiveProducts($pdo) as $row) {
            $ins->execute([(int) $row['id'], $now]);
        }
    }
    return array_map('intval', $pdo->query('SELECT product_id FROM website_sync_sent')->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function webSyncMarkSent(PDO $pdo, array $ids): void
{
    webSyncEnsureTable($pdo);
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare('INSERT INTO website_sync_sent (product_id, synced_at) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE synced_at = VALUES(synced_at)');
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ins->execute([$id, $now]);
        }
    }
}

function webSyncPending(PDO $pdo): array
{
    $sent = array_fill_keys(webSyncSentIds($pdo), true);
    $pending = [];
    foreach (webSyncActiveProducts($pdo) as $row) {
        if (!isset($sent[(int) $row['id']])) {
            $pending[] = $row;
        }
    }
    return $pending;
}

function webSyncFetch(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl is not enabled.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $body === '' || $code >= 400) {
        throw new RuntimeException('Website sync failed (HTTP ' . $code . '). ' . $err);
    }
    return $body;
}

function webSyncNextUrl(string $html, string $current): ?string
{
    if (!preg_match('/http-equiv="refresh"[^>]*content="\d+\s*;\s*url=([^"]+)"/i', $html, $m)) {
        return null;
    }
    $next = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    if (preg_match('#^https?://#i', $next)) {
        return $next;
    }
    $parts = parse_url($current);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'ultimate.co.tz');
    if (str_starts_with($next, '/')) {
        return $origin . $next;
    }
    return $origin . '/ultitech/' . ltrim($next, '/');
}

function webSyncRun(): array
{
    @set_time_limit(0);
    $url = WEB_SYNC_URL;
    $seen = [];
    $summary = '';
    for ($step = 1; $step <= 80; $step++) {
        if (isset($seen[$url])) {
            throw new RuntimeException('Sync repeated the same step.');
        }
        $seen[$url] = true;
        $html = webSyncFetch($url);
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) ?? '');
        if (str_contains($text, 'Connection stopped')) {
            throw new RuntimeException('The website sync stopped.');
        }
        if (preg_match('/Created \d+, updated \d+\./', $text, $m)) {
            $summary = $m[0];
        }
        $next = webSyncNextUrl($html, $url);
        if ($next === null) {
            return ['ok' => true, 'summary' => $summary !== '' ? $summary : 'Sync finished.'];
        }
        $url = $next;
    }
    throw new RuntimeException('Sync did not finish.');
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $action = (string) ($_GET['ajax'] ?? '');
        if ($action === 'sync') {
            $before = array_map(static fn ($row) => (int) $row['id'], webSyncActiveProducts($pdo));
            $result = webSyncRun();
            webSyncMarkSent($pdo, $before);
            $result['pending'] = count(webSyncPending($pdo));
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pending = [];
$loadError = '';
try {
    $pending = webSyncPending($pdo);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$page_title = 'webServices';
$employeeHeaderTitle = 'webServices';
$hideHeaderCompanyBranding = true;
include __DIR__ . '/includes/header.php';
$ajaxPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$ajaxSync = ($ajaxPath !== '' ? $ajaxPath : 'web-services.php') . '?ajax=sync';
?>
<main class="main-content">
    <div class="container-fluid py-4" style="max-width: 980px;">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h1 class="h4 mb-1">webServices</h1>
                <p class="text-muted mb-0">Send Ultimate products to ultimate.co.tz. New products that are not on the website yet are listed below.</p>
            </div>
            <button type="button" class="btn btn-primary" id="web-sync-btn">Sync to website</button>
        </div>
        <div id="web-sync-status" class="mb-3"></div>
        <?php if ($loadError !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($pending === []): ?>
            <div class="alert alert-success mb-0">No new products waiting. Everything active in UltiTech has been sent, or nothing has been added since the last sync.</div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3"><?= count($pending) ?> not synced yet</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th class="text-end">Price</th>
                                    <th class="text-end">Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pending as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['product_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end"><?= number_format((float) ($row['unit_price'] ?? 0), 2) ?></td>
                                    <td class="text-end"><?= htmlspecialchars((string) ($row['stock_qty'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
document.getElementById('web-sync-btn')?.addEventListener('click', async function () {
    const btn = this;
    const status = document.getElementById('web-sync-status');
    btn.disabled = true;
    status.innerHTML = '<div class="alert alert-info mb-0">Syncing products to ultimate.co.tz. This can take a few minutes.</div>';
    try {
        const res = await fetch(<?= json_encode($ajaxSync) ?>, { method: 'POST', credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Sync failed');
        }
        status.innerHTML = '<div class="alert alert-success mb-0">' + (data.summary || 'Sync finished.') + '</div>';
        window.setTimeout(function () { window.location.reload(); }, 900);
    } catch (err) {
        status.innerHTML = '<div class="alert alert-danger mb-0">' + (err && err.message ? err.message : 'Sync failed') + '</div>';
        btn.disabled = false;
    }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
