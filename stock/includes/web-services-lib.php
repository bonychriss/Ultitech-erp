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

function webSyncFetch(string $url, int $userId = 0, string $run = ''): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl is not enabled.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function () use ($userId, $run): int {
            return webSyncIsCancelled($userId, $run) ? 1 : 0;
        },
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (webSyncIsCancelled($userId, $run) || $errno === CURLE_ABORTED_BY_CALLBACK) {
        throw new RuntimeException('Sync cancelled.');
    }
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

function webQuoteRequestsPanel(): string
{
    $order = [];
    $groups = [];
    foreach (webQuoteRequestGroups() as $quote) {
        $key = (string) ($quote['quote_number'] ?? '');
        $order[] = $key;
        $groups[$key] = $quote;
    }

    $h = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $money = static function (float $amount): string {
        return 'TZS ' . number_format($amount, 2);
    };
    $when = static function (string $value) use ($h): string {
        $ts = strtotime($value);
        return $ts ? $h(date('d M Y, H:i', $ts)) : $h($value);
    };

    $html = '<section class="ugt-web-quotes">'
        . '<div class="ugt-web-quotes-head"><h2>Quote requests</h2>'
        . '<p>Submitted from ultimate.co.tz. A sales person can follow up from the name and phone on each request.</p></div>';
    if ($order === []) {
        $html .= '<div class="ugt-web-quotes-empty">No quotation requests yet.</div></section>';
        return $html . webQuoteRequestsCss();
    }
    foreach ($order as $key) {
        $quote = $groups[$key];
        $subtotal = 0.0;
        $lines = '';
        foreach ($quote['items'] as $item) {
            $line = $item['unit_price'] > 0 ? $item['unit_price'] * $item['quantity'] : 0.0;
            $subtotal += $line;
            $qtyLabel = abs($item['quantity'] - round($item['quantity'])) < 0.001
                ? (string) (int) round($item['quantity'])
                : rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.');
            $thumb = $item['image'] !== ''
                ? '<img src="' . $h($item['image']) . '" alt="">'
                : '<span class="ugt-web-quote-thumb"></span>';
            $lines .= '<div class="ugt-web-quote-line">' . $thumb
                . '<div class="ugt-web-quote-name">' . $h($item['name'] !== '' ? $item['name'] : 'Product') . '</div>'
                . '<div class="ugt-web-quote-qty">' . $h($qtyLabel) . '×</div>'
                . '<div class="ugt-web-quote-price">' . ($line > 0 ? $h($money($line)) : '') . '</div>'
                . '</div>';
        }
        $meta = [];
        if ($quote['customer_phone'] !== '') {
            $meta[] = $h($quote['customer_phone']);
        }
        if ($quote['customer_email'] !== '') {
            $meta[] = $h($quote['customer_email']);
        }
        $html .= '<article class="ugt-web-quote">'
            . '<header><div><strong>' . $h($quote['quote_number']) . '</strong>'
            . '<span>' . $h($quote['customer_name']) . '</span></div>'
            . '<time>' . $when($quote['created_at']) . '</time></header>'
            . ($meta !== [] ? '<p class="ugt-web-quote-meta">' . implode(' · ', $meta) . '</p>' : '')
            . ($quote['notes'] !== '' ? '<p class="ugt-web-quote-notes">' . $h($quote['notes']) . '</p>' : '')
            . $lines
            . ($subtotal > 0 ? '<footer>Subtotal <strong>' . $h($money($subtotal)) . '</strong></footer>' : '')
            . '</article>';
    }
    $html .= '</section>' . webQuoteRequestsCss();

    return $html;
}

function webQuoteRequestsCss(): string
{
    return '<style>
.ugt-web-quotes{margin:0 0 1.25rem;font-family:DM Sans,system-ui,sans-serif;color:#0f172a}
.ugt-web-quotes-head h2{margin:0 0 .25rem;font-size:1.15rem}
.ugt-web-quotes-head p,.ugt-web-quotes-empty{margin:0 0 .75rem;color:#64748b;font-size:.925rem}
.ugt-web-quote{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin:0 0 12px}
.ugt-web-quote header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.ugt-web-quote header strong{display:block;font-size:.95rem}
.ugt-web-quote header span{color:#334155}
.ugt-web-quote time{color:#64748b;font-size:.8rem;white-space:nowrap}
.ugt-web-quote-meta,.ugt-web-quote-notes{margin:.35rem 0 0;color:#475569;font-size:.875rem}
.ugt-web-quote-line{display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #f1f5f9}
.ugt-web-quote-line img,.ugt-web-quote-thumb{width:42px;height:42px;object-fit:cover;border-radius:6px;background:#f1f5f9;flex:0 0 42px}
.ugt-web-quote-name{flex:1;font-weight:600;font-size:.9rem}
.ugt-web-quote-qty,.ugt-web-quote-price{color:#475569;font-size:.875rem;white-space:nowrap}
.ugt-web-quote footer{display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:4px}
html[data-theme="dark"] .ugt-web-quotes{color:#e2e8f0}
html[data-theme="dark"] .ugt-web-quote{background:#1e293b;border-color:#334155}
html[data-theme="dark"] .ugt-web-quotes-head p,html[data-theme="dark"] .ugt-web-quote time,html[data-theme="dark"] .ugt-web-quote-meta,html[data-theme="dark"] .ugt-web-quote-notes,html[data-theme="dark"] .ugt-web-quote-qty,html[data-theme="dark"] .ugt-web-quote-price{color:#94a3b8}
html[data-theme="dark"] .ugt-web-quote header span{color:#e2e8f0}
</style>';
}
