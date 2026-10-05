<?php

declare(strict_types=1);

function webSyncUrl(): string
{
    return 'https://ultimate.co.tz/ultitech/sync.php?key=ugt7k-sync-4m2p';
}

function webSyncRunId(string $run): string
{
    $run = preg_replace('/[^a-zA-Z0-9._-]/', '', $run) ?? '';

    return $run !== '' ? $run : '0';
}

function webSyncCancelPath(int $userId, string $run): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ugt-web-sync-cancel-' . $userId . '-' . webSyncRunId($run);
}

function webSyncRequestCancel(int $userId, string $run): void
{
    if ($userId > 0) {
        file_put_contents(webSyncCancelPath($userId, $run), (string) time());
    }
}

function webSyncClearCancel(int $userId, string $run): void
{
    $path = webSyncCancelPath($userId, $run);
    if (is_file($path)) {
        @unlink($path);
    }
}

function webSyncIsCancelled(int $userId, string $run): bool
{
    return $userId > 0 && is_file(webSyncCancelPath($userId, $run));
}

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
            $pending[] = [
                'id' => (int) ($row['id'] ?? 0),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'stock_qty' => (float) ($row['stock_qty'] ?? 0),
            ];
        }
    }

    return $pending;
}

/**
 * Hosting server behind Cloudflare for ultimate.co.tz (cPanel host.sadjawebtools.com).
 * Used when Cloudflare refuses requests coming from the UltiTech server.
 */
function webSyncOriginIp(): string
{
    $env = getenv('ULTIMATE_SHOP_ORIGIN_IP');
    if (is_string($env) && filter_var(trim($env), FILTER_VALIDATE_IP)) {
        return trim($env);
    }

    return '213.136.73.52';
}

/**
 * @return array{body:string,code:int,errno:int,error:string,cloudflare:bool}
 */
function webSyncCurl(string $url, int $userId, string $run, bool $viaOrigin): array
{
    $cloudflare = false;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function () use ($userId, $run): int {
            return webSyncIsCancelled($userId, $run) ? 1 : 0;
        },
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$cloudflare): int {
            if (stripos($line, 'cf-ray:') === 0 || stripos($line, 'server: cloudflare') === 0) {
                $cloudflare = true;
            }
            return strlen($line);
        },
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];
    if ($viaOrigin) {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $opts[CURLOPT_RESOLVE] = [$host . ':443:' . webSyncOriginIp(), $host . ':80:' . webSyncOriginIp()];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $result = [
        'body' => is_string($body) ? $body : '',
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'errno' => curl_errno($ch),
        'error' => curl_error($ch),
        'cloudflare' => $cloudflare,
    ];
    curl_close($ch);

    return $result;
}

function webSyncFetch(string $url, int $userId = 0, string $run = ''): string
{
    static $useOrigin = false;
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl is not enabled.');
    }
    $res = webSyncCurl($url, $userId, $run, $useOrigin);
    if (webSyncIsCancelled($userId, $run) || $res['errno'] === CURLE_ABORTED_BY_CALLBACK) {
        throw new RuntimeException('Sync cancelled.');
    }
    $blocked = in_array($res['code'], [403, 429, 503], true) || $res['errno'] !== 0;
    if (!$useOrigin && $blocked) {
        $first = $res;
        $res = webSyncCurl($url, $userId, $run, true);
        if (webSyncIsCancelled($userId, $run) || $res['errno'] === CURLE_ABORTED_BY_CALLBACK) {
            throw new RuntimeException('Sync cancelled.');
        }
        if ($res['body'] !== '' && $res['code'] < 400 && $res['errno'] === 0) {
            $useOrigin = true;
        } elseif ($first['cloudflare']) {
            throw new RuntimeException('Cloudflare blocked the UltiTech server (HTTP ' . $first['code'] . '), and the shop server answered HTTP ' . $res['code'] . '.');
        }
    }
    if ($res['body'] === '' || $res['code'] >= 400) {
        throw new RuntimeException('Website sync failed (HTTP ' . $res['code'] . '). ' . $res['error']);
    }

    return $res['body'];
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

function webSyncRun(int $userId = 0, string $run = ''): array
{
    @set_time_limit(0);
    $url = webSyncUrl();
    $seen = [];
    $summary = '';
    $finish = static function (array $result) use ($userId, $run): array {
        webSyncClearCancel($userId, $run);

        return $result;
    };
    for ($step = 1; $step <= 80; $step++) {
        if (webSyncIsCancelled($userId, $run)) {
            return $finish(['ok' => false, 'cancelled' => true]);
        }
        if (isset($seen[$url])) {
            throw new RuntimeException('Sync repeated the same step.');
        }
        $seen[$url] = true;
        try {
            $html = webSyncFetch($url, $userId, $run);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Sync cancelled.' || webSyncIsCancelled($userId, $run)) {
                return $finish(['ok' => false, 'cancelled' => true]);
            }
            throw $e;
        }
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) ?? '');
        if (str_contains($text, 'Connection stopped')) {
            throw new RuntimeException('The website sync stopped.');
        }
        if (preg_match('/Created \d+, updated \d+\./', $text, $m)) {
            $summary = $m[0];
        }
        $next = webSyncNextUrl($html, $url);
        if ($next === null) {
            return $finish(['ok' => true, 'summary' => $summary !== '' ? $summary : 'Sync finished.']);
        }
        $url = $next;
    }
    throw new RuntimeException('Sync did not finish.');
}

function webQuoteSafeImage(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/')) {
        return $url;
    }

    return '';
}

/**
 * @param list<int> $ids
 * @return array<int,string>
 */
function webQuoteProductImages(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', $ids);
    $files = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('main_image', $cols, true)) {
            foreach ($pdo->query('SELECT id, main_image FROM products WHERE id IN (' . $in . ')') as $row) {
                $files[(int) $row['id']] = (string) ($row['main_image'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $files = [];
    }
    try {
        foreach ($pdo->query('SELECT product_id, image_name FROM product_images WHERE product_id IN (' . $in . ') ORDER BY is_primary DESC, id ASC') as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid > 0 && empty($files[$pid])) {
                $files[$pid] = (string) ($row['image_name'] ?? '');
            }
        }
    } catch (Throwable $e) {
        // product_images is optional
    }
    $map = [];
    foreach ($files as $pid => $file) {
        if (!function_exists('stock_product_list_image_url')) {
            break;
        }
        $url = webQuoteSafeImage((string) stock_product_list_image_url($pid, $file, 'thumbnail'));
        if ($url !== '') {
            $map[$pid] = $url;
        }
    }

    return $map;
}

/**
 * @return list<array<string,mixed>>
 */
function webQuoteRequestGroups(?PDO $pdo = null): array
{
    $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
    if (!($pdo instanceof PDO)) {
        return [];
    }
    $lib = dirname(__DIR__, 2) . '/modules/sales/quote-requests/includes/quote-requests-lib.php';
    if (is_file($lib)) {
        require_once $lib;
        salesQuoteRequestsEnsureSchema($pdo);
    }
    try {
        $rows = $pdo->query('SELECT * FROM website_quote_requests ORDER BY id DESC LIMIT 400')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $productIds = [];
    foreach ($rows as $row) {
        $productIds[] = (int) ($row['product_id'] ?? 0);
    }
    $images = webQuoteProductImages($pdo, $productIds);
    $groups = [];
    $order = [];
    foreach ($rows as $row) {
        $key = trim((string) ($row['quote_number'] ?? ''));
        if ($key === '') {
            $key = 'row-' . (int) ($row['id'] ?? 0);
        }
        if (!isset($groups[$key])) {
            if (count($order) >= 40) {
                continue;
            }
            $order[] = $key;
            $groups[$key] = [
                'quote_number' => $key,
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_email' => (string) ($row['customer_email'] ?? ''),
                'customer_phone' => (string) ($row['customer_phone'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'status' => strtolower(trim((string) ($row['status'] ?? 'new'))) ?: 'new',
                'created_at' => (string) ($row['created_at'] ?? ''),
                'items' => [],
            ];
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $pid = (int) ($row['product_id'] ?? 0);
        $image = $images[$pid] ?? webQuoteSafeImage((string) ($payload['image'] ?? ''));
        $price = isset($payload['unit_price']) ? (float) $payload['unit_price'] : 0.0;
        $qty = (float) ($row['quantity'] ?? 1);
        array_unshift($groups[$key]['items'], [
            'name' => (string) ($row['product_name'] ?? ''),
            'quantity' => $qty,
            'unit_price' => $price,
            'image' => $image,
        ]);
    }

    $list = [];
    foreach ($order as $key) {
        $list[] = $groups[$key];
    }

    return $list;
}

/**
 * @return array{0:string,1:string}
 */
function webQuoteStatusInfo(string $status): array
{
    $status = strtolower(trim($status));
    $map = [
        'new' => ['Open', 'open'],
        'open' => ['Open', 'open'],
        'pending' => ['Open', 'open'],
        'contacted' => ['Contacted', 'contacted'],
        'quoted' => ['Quoted', 'quoted'],
        'accepted' => ['Accepted', 'accepted'],
        'closed' => ['Closed', 'closed'],
        'rejected' => ['Rejected', 'rejected'],
    ];

    return $map[$status] ?? [ucfirst($status !== '' ? $status : 'open'), 'closed'];
}

function webQuoteIcon(string $name): string
{
    $paths = [
        'doc' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        'list' => '<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function webQuoteRequestsPanel(bool $withHeading = true): string
{
    $quotes = webQuoteRequestGroups();

    $h = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $money = static function (float $amount): string {
        return 'TZS ' . number_format($amount, 2);
    };
    $newUrl = function_exists('company_url') ? company_url('sales/quote-create') : '/ultimate/sales/quote-create';

    $statuses = [];
    foreach ($quotes as $quote) {
        [$label, $key] = webQuoteStatusInfo((string) ($quote['status'] ?? 'new'));
        $statuses[$key] = $label;
    }

    $html = '<section class="uq-page">'
        . '<div class="uq-top">'
        . '<div class="uq-title">'
        . ($withHeading ? '<div class="uq-title-row"><span class="uq-title-icon">' . webQuoteIcon('list') . '</span><h1>Quote requests</h1></div>' : '')
        . '<p class="uq-sub">' . webQuoteIcon('mail') . '<span>Submitted from ultimate.co.tz. A sales person can follow up from the name and phone on each request.</span></p>'
        . '</div>'
        . '<div class="uq-tools">'
        . '<label class="uq-search">' . webQuoteIcon('search') . '<input type="search" id="uq-search" placeholder="Search by quote ID, customer or product..." autocomplete="off"></label>'
        . '<label class="uq-select">' . webQuoteIcon('calendar') . '<select id="uq-date" aria-label="Date range">'
        . '<option value="">Date range</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="month">This month</option>'
        . '</select></label>'
        . '<label class="uq-select uq-select--plain"><select id="uq-status" aria-label="Status"><option value="">Status</option>';
    foreach ($statuses as $key => $label) {
        $html .= '<option value="' . $h($key) . '">' . $h($label) . '</option>';
    }
    $html .= '</select></label>'
        . '<a class="uq-new" href="' . $h($newUrl) . '">' . webQuoteIcon('plus') . '<span>New Quote Request</span></a>'
        . '</div></div>';

    if ($quotes === []) {
        return $html . '<div class="uq-empty">No quotation requests yet.</div></section>' . webQuoteRequestsCss();
    }

    foreach ($quotes as $quote) {
        [$statusLabel, $statusKey] = webQuoteStatusInfo((string) ($quote['status'] ?? 'new'));
        $ts = strtotime((string) $quote['created_at']) ?: 0;
        $search = [$quote['quote_number'], $quote['customer_name'], $quote['customer_phone'], $quote['customer_email']];
        $rows = '';
        $subtotal = 0.0;
        $n = 0;
        foreach ($quote['items'] as $item) {
            $n++;
            $search[] = $item['name'];
            $line = $item['unit_price'] > 0 ? $item['unit_price'] * $item['quantity'] : 0.0;
            $subtotal += $line;
            $qty = abs($item['quantity'] - round($item['quantity'])) < 0.001
                ? (string) (int) round($item['quantity'])
                : rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.');
            $thumb = $item['image'] !== ''
                ? '<img src="' . $h($item['image']) . '" alt="" loading="lazy">'
                : '<span class="uq-thumb-empty"></span>';
            $rows .= '<tr>'
                . '<td class="uq-num">' . $n . '</td>'
                . '<td><div class="uq-product">' . $thumb . '<span>' . $h($item['name'] !== '' ? $item['name'] : 'Product') . '</span></div></td>'
                . '<td>' . $h($qty) . ' &times;</td>'
                . '<td>' . ($item['unit_price'] > 0 ? $h($money($item['unit_price'])) : '&mdash;') . '</td>'
                . '<td class="uq-right">' . ($line > 0 ? $h($money($line)) : '&mdash;') . '</td>'
                . '</tr>';
        }

        $contact = '';
        if ($quote['customer_phone'] !== '') {
            $tel = preg_replace('/[^0-9+]/', '', $quote['customer_phone']) ?? '';
            $contact .= '<a href="tel:' . $h($tel) . '">' . webQuoteIcon('phone') . '<span>' . $h($quote['customer_phone']) . '</span></a>';
        }
        if ($quote['customer_email'] !== '' && filter_var($quote['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $contact .= '<a href="mailto:' . $h($quote['customer_email']) . '">' . webQuoteIcon('mail') . '<span>' . $h($quote['customer_email']) . '</span></a>';
        }

        $html .= '<article class="uq-card" data-ts="' . $ts . '" data-status="' . $h($statusKey) . '" data-search="' . $h(strtolower(implode(' ', $search))) . '">'
            . '<header class="uq-card-head">'
            . '<span class="uq-card-icon">' . webQuoteIcon('doc') . '</span>'
            . '<div class="uq-card-who"><strong>' . $h($quote['quote_number']) . '</strong>'
            . '<span class="uq-card-name">' . $h(strtoupper($quote['customer_name'])) . '</span>'
            . ($contact !== '' ? '<div class="uq-contact">' . $contact . '</div>' : '')
            . '</div>'
            . '<div class="uq-card-meta"><span class="uq-date">' . webQuoteIcon('calendar')
            . '<span>' . ($ts ? $h(date('d M Y, H:i', $ts)) : $h((string) $quote['created_at'])) . '</span></span>'
            . '<span class="uq-badge uq-badge--' . $h($statusKey) . '">' . $h($statusLabel) . '</span></div>'
            . '</header>'
            . ($quote['notes'] !== '' ? '<p class="uq-notes">' . $h($quote['notes']) . '</p>' : '')
            . '<div class="uq-table-wrap"><table class="uq-table">'
            . '<thead><tr><th class="uq-num">#</th><th>Product</th><th>Quantity</th><th>Unit Price</th><th class="uq-right">Total</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td colspan="3"></td><td class="uq-sub-label">Subtotal</td><td class="uq-right uq-sub-total">'
            . ($subtotal > 0 ? $h($money($subtotal)) : '&mdash;') . '</td></tr></tfoot>'
            . '</table></div>'
            . '</article>';
    }

    $html .= '<div class="uq-empty" id="uq-no-match" hidden>No requests match these filters.</div>'
        . '</section>' . webQuoteRequestsCss() . webQuoteRequestsScript();

    return $html;
}

function webQuoteRequestsScript(): string
{
    return <<<'HTML'
<script>
(function () {
    var search = document.getElementById("uq-search");
    var date = document.getElementById("uq-date");
    var status = document.getElementById("uq-status");
    var none = document.getElementById("uq-no-match");
    var cards = Array.prototype.slice.call(document.querySelectorAll(".uq-card"));
    if (!cards.length) return;
    function since(value) {
        var now = new Date();
        if (value === "today") return new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime() / 1000;
        if (value === "month") return new Date(now.getFullYear(), now.getMonth(), 1).getTime() / 1000;
        if (value === "7" || value === "30") return now.getTime() / 1000 - parseInt(value, 10) * 86400;
        return 0;
    }
    function apply() {
        var q = (search.value || "").trim().toLowerCase();
        var from = since(date.value);
        var st = status.value;
        var shown = 0;
        cards.forEach(function (card) {
            var ok = (!q || card.getAttribute("data-search").indexOf(q) !== -1)
                && (!from || parseInt(card.getAttribute("data-ts"), 10) >= from)
                && (!st || card.getAttribute("data-status") === st);
            card.hidden = !ok;
            if (ok) shown++;
        });
        none.hidden = shown > 0;
    }
    search.addEventListener("input", apply);
    date.addEventListener("change", apply);
    status.addEventListener("change", apply);
})();
</script>
HTML;
}

function webQuoteRequestsCss(): string
{
    return <<<'HTML'
<style>
.uq-page{font-family:"DM Sans",system-ui,sans-serif;color:#0f172a;padding:8px 0 24px}
.uq-page svg{width:16px;height:16px;flex:0 0 auto}
.uq-top{display:flex;flex-wrap:wrap;gap:14px 20px;align-items:flex-start;justify-content:space-between;margin:0 0 18px}
.uq-title-row{display:flex;align-items:center;gap:12px}
.uq-title-icon{width:40px;height:40px;border-radius:10px;background:#1e3a8a;color:#fff;display:inline-flex;align-items:center;justify-content:center}
.uq-title-icon svg{width:22px;height:22px}
.uq-title h1{margin:0;font-size:1.75rem;font-weight:800;letter-spacing:-.01em}
.uq-sub{display:flex;align-items:center;gap:8px;margin:8px 0 0;color:#475569;font-size:.9rem}
.uq-sub svg{color:#64748b}
.uq-title{flex:1 1 100%}
.uq-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;width:100%}
.uq-search,.uq-select{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;color:#64748b;margin:0}
.uq-search{width:min(380px,100%)}
.uq-search input{border:0;outline:0;background:transparent;width:100%;font-size:.85rem;color:#0f172a}
.uq-select select{border:0;outline:0;background:transparent;font-size:.85rem;color:#334155;min-width:110px;cursor:pointer}
.uq-new{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;border-radius:8px;background:#2563eb;color:#fff!important;font-weight:700;font-size:.85rem;text-decoration:none;box-shadow:0 6px 16px rgba(37,99,235,.25);margin-left:auto}
.uq-new:hover{background:#1d4ed8}
.uq-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px 14px;margin:0 0 12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.uq-card[hidden]{display:none}
.uq-card-head{display:flex;gap:12px;align-items:flex-start}
.uq-card-icon{width:30px;height:30px;border-radius:8px;background:#2563eb;color:#fff;display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px;margin-top:2px}
.uq-card-icon svg{width:16px;height:16px}
.uq-card-who{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:2px 18px}
.uq-card-who strong{flex:0 0 100%;font-size:1rem;font-weight:800;letter-spacing:.01em;line-height:1.3}
.uq-card-name{color:#334155;font-size:.85rem}
.uq-contact{display:flex;flex-wrap:wrap;gap:4px 18px}
.uq-contact a{display:inline-flex;align-items:center;gap:6px;color:#2563eb;font-size:.85rem;text-decoration:underline}
.uq-contact svg{width:14px;height:14px}
.uq-card-meta{display:flex;align-items:center;gap:18px;white-space:nowrap}
.uq-date{display:inline-flex;align-items:center;gap:6px;color:#475569;font-size:.85rem}
.uq-badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:.78rem;font-weight:600}
.uq-badge--open{background:#dcfce7;color:#15803d}
.uq-badge--contacted{background:#dbeafe;color:#1d4ed8}
.uq-badge--quoted{background:#ede9fe;color:#6d28d9}
.uq-badge--accepted{background:#ccfbf1;color:#0f766e}
.uq-badge--rejected{background:#fee2e2;color:#b91c1c}
.uq-badge--closed{background:#f1f5f9;color:#475569}
.uq-notes{margin:6px 0 0 42px;color:#475569;font-size:.85rem}
.uq-table-wrap{margin-top:10px;border:1px solid #e2e8f0;border-radius:10px;overflow-x:auto}
.uq-table{width:100%;min-width:640px;border-collapse:collapse;font-size:.85rem;table-layout:fixed}
.uq-table th:nth-child(3){width:14%}
.uq-table th:nth-child(4),.uq-table th:nth-child(5){width:18%}
.uq-table th{background:#f8fafc;color:#475569;font-weight:600;text-align:left;padding:7px 12px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.uq-table td{padding:5px 12px;border-bottom:1px solid #eef2f7;vertical-align:middle;color:#334155}
.uq-table tbody tr:last-child td{border-bottom:0}
.uq-num{width:44px;color:#64748b}
.uq-right{text-align:right!important;white-space:nowrap}
.uq-product{display:flex;align-items:center;gap:10px}
.uq-product img,.uq-thumb-empty{width:30px;height:30px;object-fit:contain;border-radius:6px;background:#fff;flex:0 0 30px}
.uq-thumb-empty{background:#f1f5f9}
.uq-product span{font-weight:600;color:#0f172a}
.uq-table tfoot td{background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:0;padding:8px 12px}
.uq-sub-label{font-weight:600;color:#334155}
.uq-sub-total{font-size:1rem;font-weight:800;color:#0f172a}
.uq-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;color:#64748b}
@media (max-width:767.98px){
  .uq-tools{width:100%}
  .uq-search{width:100%}
  .uq-card-head{flex-wrap:wrap}
  .uq-card-meta{width:100%;justify-content:space-between;padding-left:42px}
}
html[data-theme="dark"] .uq-page{color:#e2e8f0}
html[data-theme="dark"] .uq-card,html[data-theme="dark"] .uq-search,html[data-theme="dark"] .uq-select,html[data-theme="dark"] .uq-empty{background:#1e293b;border-color:#334155}
html[data-theme="dark"] .uq-search input,html[data-theme="dark"] .uq-select select{color:#e2e8f0}
html[data-theme="dark"] .uq-select select option{background:#1e293b}
html[data-theme="dark"] .uq-table-wrap{border-color:#334155}
html[data-theme="dark"] .uq-table th,html[data-theme="dark"] .uq-table tfoot td{background:#0f172a;color:#94a3b8;border-color:#334155}
html[data-theme="dark"] .uq-table td{color:#cbd5e1;border-color:#334155}
html[data-theme="dark"] .uq-product span,html[data-theme="dark"] .uq-sub-total,html[data-theme="dark"] .uq-sub-label{color:#f1f5f9}
html[data-theme="dark"] .uq-card-name,html[data-theme="dark"] .uq-sub,html[data-theme="dark"] .uq-date,html[data-theme="dark"] .uq-notes{color:#94a3b8}
html[data-theme="dark"] .uq-contact a{color:#60a5fa}
</style>
HTML;
}
