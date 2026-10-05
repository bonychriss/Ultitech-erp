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
function webQuoteRequestGroups(?PDO $pdo = null, string $quoteNumber = ''): array
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
        if ($quoteNumber !== '') {
            $stmt = $pdo->prepare('SELECT * FROM website_quote_requests WHERE quote_number = ? ORDER BY id DESC LIMIT 200');
            $stmt->execute([$quoteNumber]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $rows = $pdo->query('SELECT * FROM website_quote_requests ORDER BY id DESC LIMIT 400')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
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
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'back' => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'chat' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l2-5.2a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 8.4-8.5 8.4 8.4 0 0 1 8.5 8z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    ];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function webQuoteH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function webQuoteMoney(float $amount): string
{
    return 'TZS ' . number_format($amount, 2);
}

function webQuoteQty(float $qty): string
{
    return abs($qty - round($qty)) < 0.001
        ? (string) (int) round($qty)
        : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}

function webQuoteTotal(array $quote): float
{
    $total = 0.0;
    foreach ($quote['items'] as $item) {
        if ($item['unit_price'] > 0) {
            $total += $item['unit_price'] * $item['quantity'];
        }
    }

    return $total;
}

function webQuotePageUrl(string $quoteNumber = ''): string
{
    $base = function_exists('company_url') ? company_url('website/quotes') : '/ultimate/website/quotes';

    return $quoteNumber === '' ? $base : $base . '?quote=' . rawurlencode($quoteNumber);
}

function webQuoteDate(string $createdAt): string
{
    $ts = strtotime($createdAt) ?: 0;

    return $ts ? date('d M Y, H:i', $ts) : $createdAt;
}

function webQuoteRequestsPanel(bool $withHeading = true): string
{
    $quotes = webQuoteRequestGroups();
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
        . '<p class="uq-sub">' . webQuoteIcon('mail') . '<span>Submitted from ultimate.co.tz. Open a request to see the customer and products.</span></p>'
        . '</div>'
        . '<div class="uq-tools">'
        . '<label class="uq-search">' . webQuoteIcon('search') . '<input type="search" id="uq-search" placeholder="Search by quote ID, customer or product..." autocomplete="off"></label>'
        . '<label class="uq-select">' . webQuoteIcon('calendar') . '<select id="uq-date" aria-label="Date range">'
        . '<option value="">Date range</option><option value="today">Today</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="month">This month</option>'
        . '</select></label>'
        . '<label class="uq-select uq-select--plain"><select id="uq-status" aria-label="Status"><option value="">Status</option>';
    foreach ($statuses as $key => $label) {
        $html .= '<option value="' . webQuoteH($key) . '">' . webQuoteH($label) . '</option>';
    }
    $html .= '</select></label>'
        . '<a class="uq-new" href="' . webQuoteH($newUrl) . '">' . webQuoteIcon('plus') . '<span>New Quote Request</span></a>'
        . '</div></div>';

    if ($quotes === []) {
        return $html . '<div class="uq-empty">No quotation requests yet.</div></section>' . webQuoteRequestsCss();
    }

    $html .= '<div class="uq-list" role="list">'
        . '<div class="uq-list-head"><span>Quote</span><span>Customer</span><span>Items</span><span class="uq-right">Est. total</span><span>Received</span><span>Status</span><span></span></div>';

    foreach ($quotes as $quote) {
        [$statusLabel, $statusKey] = webQuoteStatusInfo((string) ($quote['status'] ?? 'new'));
        $ts = strtotime((string) $quote['created_at']) ?: 0;
        $search = [$quote['quote_number'], $quote['customer_name'], $quote['customer_phone'], $quote['customer_email']];
        foreach ($quote['items'] as $item) {
            $search[] = $item['name'];
        }
        $count = count($quote['items']);
        $total = webQuoteTotal($quote);
        $first = $quote['items'][0]['name'] ?? '';
        $itemsText = $count === 1 ? $first : $count . ' products';

        $html .= '<a class="uq-row" role="listitem" href="' . webQuoteH(webQuotePageUrl($quote['quote_number'])) . '"'
            . ' data-ts="' . $ts . '" data-status="' . webQuoteH($statusKey) . '" data-search="' . webQuoteH(strtolower(implode(' ', $search))) . '">'
            . '<span class="uq-row-quote"><span class="uq-row-icon">' . webQuoteIcon('doc') . '</span><strong>' . webQuoteH($quote['quote_number']) . '</strong></span>'
            . '<span class="uq-row-customer"><span class="uq-row-name">' . webQuoteH($quote['customer_name'] !== '' ? $quote['customer_name'] : 'Website customer') . '</span>'
            . ($quote['customer_phone'] !== '' ? '<span class="uq-row-phone">' . webQuoteH($quote['customer_phone']) . '</span>' : '')
            . '</span>'
            . '<span class="uq-row-items" title="' . webQuoteH($itemsText) . '">' . webQuoteH($itemsText) . '</span>'
            . '<span class="uq-row-total uq-right">' . ($total > 0 ? webQuoteH(webQuoteMoney($total)) : '&mdash;') . '</span>'
            . '<span class="uq-row-date">' . webQuoteH(webQuoteDate((string) $quote['created_at'])) . '</span>'
            . '<span><span class="uq-badge uq-badge--' . webQuoteH($statusKey) . '">' . webQuoteH($statusLabel) . '</span></span>'
            . '<span class="uq-row-go">' . webQuoteIcon('chevron') . '</span>'
            . '</a>';
    }

    $html .= '</div>'
        . '<div class="uq-empty" id="uq-no-match" hidden>No requests match these filters.</div>'
        . '</section>' . webQuoteRequestsCss() . webQuoteRequestsScript();

    return $html;
}

/**
 * @return array<string,mixed>
 */
function webQuoteRequestDetailData(string $quoteNumber): array
{
    $groups = webQuoteRequestGroups(null, $quoteNumber);
    $quote = $groups[0] ?? null;
    $data = [
        'found' => $quote !== null,
        'backUrl' => webQuotePageUrl(),
        'createUrl' => function_exists('company_url') ? company_url('sales/quote-create') : '/ultimate/sales/quote-create',
        'quote' => null,
    ];
    if ($quote === null) {
        return $data;
    }

    [$statusLabel, $statusKey] = webQuoteStatusInfo((string) ($quote['status'] ?? 'new'));
    $name = $quote['customer_name'] !== '' ? $quote['customer_name'] : 'Website customer';
    $phone = $quote['customer_phone'];
    $email = filter_var($quote['customer_email'], FILTER_VALIDATE_EMAIL) ? $quote['customer_email'] : '';
    $tel = preg_replace('/[^0-9+]/', '', $phone) ?? '';
    $wa = preg_replace('/\D/', '', $phone) ?? '';
    if (strpos($wa, '0') === 0) {
        $wa = '255' . substr($wa, 1);
    }

    $items = [];
    $qtyTotal = 0.0;
    foreach ($quote['items'] as $item) {
        $qtyTotal += $item['quantity'];
        $items[] = [
            'name' => $item['name'] !== '' ? $item['name'] : 'Product',
            'quantity' => webQuoteQty($item['quantity']),
            'unitPrice' => $item['unit_price'] > 0 ? webQuoteMoney($item['unit_price']) : '',
            'lineTotal' => $item['unit_price'] > 0 ? webQuoteMoney($item['unit_price'] * $item['quantity']) : '',
            'image' => $item['image'],
        ];
    }
    $total = webQuoteTotal($quote);

    $data['quote'] = [
        'number' => $quote['quote_number'],
        'statusLabel' => $statusLabel,
        'statusKey' => $statusKey,
        'receivedAt' => webQuoteDate((string) $quote['created_at']),
        'customer' => [
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'notes' => $quote['notes'],
        ],
        'items' => $items,
        'quantityTotal' => webQuoteQty($qtyTotal),
        'total' => $total > 0 ? webQuoteMoney($total) : '',
        'links' => [
            'call' => $tel !== '' ? 'tel:' . $tel : '',
            'whatsapp' => $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Hello ' . $name . ', thank you for your quotation request ' . $quote['quote_number'] . ' on ultimate.co.tz.') : '',
            'email' => $email !== '' ? 'mailto:' . $email . '?subject=' . rawurlencode('Your quotation request ' . $quote['quote_number']) : '',
        ],
    ];

    return $data;
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
    var rows = Array.prototype.slice.call(document.querySelectorAll(".uq-row"));
    if (!rows.length) return;
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
        rows.forEach(function (row) {
            var ok = (!q || row.getAttribute("data-search").indexOf(q) !== -1)
                && (!from || parseInt(row.getAttribute("data-ts"), 10) >= from)
                && (!st || row.getAttribute("data-status") === st);
            row.hidden = !ok;
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
.uq-page{font-family:"DM Sans",system-ui,sans-serif;color:#0f172a;padding:8px 0 24px;container:uq/inline-size}
.uq-page svg{width:16px;height:16px;flex:0 0 auto}
.uq-top{display:flex;flex-wrap:wrap;gap:14px 20px;align-items:flex-start;justify-content:space-between;margin:0 0 18px}
.uq-title{flex:1 1 100%}
.uq-title-row{display:flex;align-items:center;gap:12px}
.uq-title-icon{width:40px;height:40px;border-radius:10px;background:#1e3a8a;color:#fff;display:inline-flex;align-items:center;justify-content:center;flex:0 0 40px}
.uq-title-icon svg{width:22px;height:22px}
.uq-page h1{margin:0;font-size:1.75rem;font-weight:800;letter-spacing:-.01em;display:flex;align-items:center;flex-wrap:wrap;gap:10px}
.uq-sub{display:flex;align-items:center;gap:8px;margin:8px 0 0;color:#475569;font-size:.9rem}
.uq-sub svg{color:#64748b}
.uq-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;width:100%}
.uq-search,.uq-select{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;color:#64748b;margin:0}
.uq-search{width:min(380px,100%)}
.uq-search input{border:0;outline:0;background:transparent;width:100%;font-size:.85rem;color:#0f172a}
.uq-select select{border:0;outline:0;background:transparent;font-size:.85rem;color:#334155;min-width:110px;cursor:pointer}
.uq-new{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;border-radius:8px;background:#2563eb;color:#fff!important;font-weight:700;font-size:.85rem;text-decoration:none!important;box-shadow:0 6px 16px rgba(37,99,235,.25);margin-left:auto}
.uq-new:hover{background:#1d4ed8}
.uq-list{display:grid;grid-template-columns:max-content minmax(130px,1.5fr) minmax(90px,1fr) max-content max-content max-content 16px;column-gap:20px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.uq-list-head,.uq-row{grid-column:1/-1;display:grid;grid-template-columns:subgrid;align-items:center;padding:0 18px}
.uq-list-head > span,.uq-row > span{min-width:0}
.uq-list-head{background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;height:40px}
.uq-row{min-height:60px;border-bottom:1px solid #eef2f7;color:#334155!important;text-decoration:none!important;font-size:.875rem;transition:background .12s}
.uq-row:last-child{border-bottom:0}
.uq-row:hover{background:#f5f8ff}
.uq-row[hidden]{display:none}
.uq-row-quote{display:flex;align-items:center;gap:10px;min-width:0}
.uq-row-quote strong{color:#0f172a;font-weight:700;white-space:nowrap}
.uq-row-icon{width:30px;height:30px;border-radius:8px;background:#eff4ff;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px}
.uq-row-customer{display:flex;flex-direction:column;min-width:0}
.uq-row-name{font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.uq-row-phone{font-size:.8rem;color:#64748b}
.uq-row-items{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569}
.uq-row-total{font-weight:700;color:#0f172a}
.uq-row-date{color:#475569;white-space:nowrap}
.uq-row-go{color:#94a3b8;display:inline-flex}
.uq-row:hover .uq-row-go{color:#2563eb}
.uq-badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:.78rem;font-weight:600;white-space:nowrap;letter-spacing:0}
.uq-badge--open{background:#dcfce7;color:#15803d}
.uq-badge--contacted{background:#dbeafe;color:#1d4ed8}
.uq-badge--quoted{background:#ede9fe;color:#6d28d9}
.uq-badge--accepted{background:#ccfbf1;color:#0f766e}
.uq-badge--rejected{background:#fee2e2;color:#b91c1c}
.uq-badge--closed{background:#f1f5f9;color:#475569}
.uq-right{text-align:right!important;white-space:nowrap}
.uq-empty{background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;color:#64748b}
@container uq (max-width:1040px){
  .uq-list{grid-template-columns:max-content minmax(130px,1fr) max-content max-content max-content 16px;column-gap:16px}
  .uq-list-head > span:nth-child(3),.uq-row-items{display:none}
}
@container uq (max-width:820px){
  .uq-list{display:block}
  .uq-list-head{display:none}
  .uq-row{grid-template-columns:1fr auto;grid-template-areas:"quote status" "customer total" "items date";gap:4px 12px;padding:12px 16px}
  .uq-row-quote{grid-area:quote}
  .uq-row > span:nth-child(6){grid-area:status;justify-self:end}
  .uq-row-customer{grid-area:customer}
  .uq-row-total{grid-area:total}
  .uq-row-items{display:block;grid-area:items;font-size:.8rem}
  .uq-row-date{grid-area:date;font-size:.8rem;text-align:right}
  .uq-row-go{display:none}
}
@media (max-width:767.98px){
  .uq-search{width:100%}
  .uq-new{margin-left:0}
}
html[data-theme="dark"] .uq-page{color:#e2e8f0}
html[data-theme="dark"] .uq-list,html[data-theme="dark"] .uq-search,html[data-theme="dark"] .uq-select,html[data-theme="dark"] .uq-empty{background:#1e293b;border-color:#334155}
html[data-theme="dark"] .uq-list-head{background:#0f172a;color:#94a3b8;border-color:#334155}
html[data-theme="dark"] .uq-row{border-color:#334155;color:#cbd5e1!important}
html[data-theme="dark"] .uq-row:hover{background:#243248}
html[data-theme="dark"] .uq-row-icon{background:#1e3a8a;color:#bfdbfe}
html[data-theme="dark"] .uq-row-quote strong,html[data-theme="dark"] .uq-row-name,html[data-theme="dark"] .uq-row-total{color:#f1f5f9!important}
html[data-theme="dark"] .uq-search input,html[data-theme="dark"] .uq-select select{color:#e2e8f0}
html[data-theme="dark"] .uq-select select option{background:#1e293b}
html[data-theme="dark"] .uq-sub,html[data-theme="dark"] .uq-row-date,html[data-theme="dark"] .uq-row-items,html[data-theme="dark"] .uq-row-phone{color:#94a3b8}
</style>
HTML;
}
