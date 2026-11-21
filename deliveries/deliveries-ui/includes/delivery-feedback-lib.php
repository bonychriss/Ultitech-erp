<?php

declare(strict_types=1);

/**
 * Public delivery-note feedback (reviews + similar products) helpers.
 *
 * Security model:
 * - Per-note random token stored in DB (not guessable from id)
 * - Token expiry (default 180 days)
 * - Legacy HMAC tokens still accepted briefly for already-printed QRs
 * - Rate limits, honeypot, signed form nonce, text sanitization, one-time review
 */

/**
 * Original secret used by already-printed QR links.
 * Do not change — otherwise old delivery-note QRs break.
 */
function deliveries_feedback_legacy_secret(): string
{
    if (defined('APP_KEY') && (string) APP_KEY !== '') {
        return (string) APP_KEY;
    }
    if (defined('DB_PASS') && (string) DB_PASS !== '') {
        return (string) DB_PASS;
    }

    return 'ultitech-dn-feedback';
}

function deliveries_feedback_secret(): string
{
    if (defined('FEEDBACK_HMAC_SECRET') && (string) FEEDBACK_HMAC_SECRET !== '') {
        return (string) FEEDBACK_HMAC_SECRET;
    }
    // Stronger material for new signed nonces / future tokens.
    return hash('sha256', 'dn-feedback-v2|' . deliveries_feedback_legacy_secret());
}

/** Link lifetime for new QR tokens (days). */
function deliveries_feedback_token_ttl_days(): int
{
    return 180;
}

/**
 * Legacy deterministic token (printed QRs before random tokens).
 * Kept only for backward compatibility — must match historical formula.
 */
function deliveries_feedback_legacy_token(int $noteId): string
{
    return substr(hash_hmac('sha256', 'dn-feedback:' . $noteId, deliveries_feedback_legacy_secret()), 0, 32);
}

/** @deprecated Use deliveries_feedback_legacy_token / ensure_public_token */
function deliveries_feedback_token(int $noteId): string
{
    return deliveries_feedback_legacy_token($noteId);
}

function deliveries_feedback_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '' || strlen($ip) > 45) {
        return '0.0.0.0';
    }

    return $ip;
}

function deliveries_feedback_sanitize_user_text(string $text, int $maxLen = 2000): string
{
    $text = str_replace("\0", '', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    // Neutralize obvious script / injection bait without killing normal punctuation.
    $text = preg_replace('/(?:javascript|data|vbscript)\s*:/i', '', $text) ?? $text;
    $text = preg_replace('/on[a-z]+\s*=/i', '', $text) ?? $text;
    $text = trim($text);
    // Soft-limit link spam (allow a couple of URLs in a real comment).
    if (preg_match_all('#https?://#i', $text, $m) && count($m[0]) > 2) {
        $parts = preg_split('#\s+#', $text) ?: [];
        $urls = 0;
        $kept = [];
        foreach ($parts as $part) {
            if (preg_match('#^https?://#i', $part)) {
                $urls++;
                if ($urls > 2) {
                    continue;
                }
            }
            $kept[] = $part;
        }
        $text = trim(implode(' ', $kept));
    }
    if ($maxLen > 0 && mb_strlen($text) > $maxLen) {
        $text = mb_substr($text, 0, $maxLen);
    }

    return $text;
}

/**
 * Simple sliding-window rate limit (file-backed). Returns true when allowed.
 */
function deliveries_feedback_rate_allow(string $bucket, int $maxHits, int $windowSeconds): bool
{
    $maxHits = max(1, $maxHits);
    $windowSeconds = max(30, $windowSeconds);
    $key = hash('sha256', $bucket);
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dn_feedback_rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $path = $dir . DIRECTORY_SEPARATOR . $key . '.json';
    $now = time();
    $hits = [];
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach ($decoded as $ts) {
                $t = (int) $ts;
                if ($t > ($now - $windowSeconds)) {
                    $hits[] = $t;
                }
            }
        }
    }
    if (count($hits) >= $maxHits) {
        return false;
    }
    $hits[] = $now;
    @file_put_contents($path, json_encode($hits), LOCK_EX);

    return true;
}

function deliveries_feedback_form_nonce(int $noteId): string
{
    $ts = time();
    $sig = hash_hmac('sha256', 'dn-fb-nonce|' . $noteId . '|' . $ts, deliveries_feedback_legacy_secret());

    return $ts . '.' . substr($sig, 0, 40);
}

function deliveries_feedback_form_nonce_valid(int $noteId, string $nonce, int $maxAgeSec = 7200): bool
{
    $nonce = trim($nonce);
    if ($noteId <= 0 || $nonce === '' || !str_contains($nonce, '.')) {
        return false;
    }
    [$tsRaw, $sig] = explode('.', $nonce, 2);
    $ts = (int) $tsRaw;
    if ($ts <= 0 || abs(time() - $ts) > max(300, $maxAgeSec)) {
        return false;
    }
    // Accept both current and v2 secrets so mid-deploy refreshes don't fail.
    $payload = 'dn-fb-nonce|' . $noteId . '|' . $ts;
    foreach ([deliveries_feedback_legacy_secret(), deliveries_feedback_secret()] as $secret) {
        $expected = substr(hash_hmac('sha256', $payload, $secret), 0, 40);
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}

function deliveries_feedback_honeypot_tripped(array $post): bool
{
    // Obscure field name — avoid "website/url/company" (browsers autofill those).
    $value = trim((string) ($post['dn_fb_hp'] ?? ''));

    return $value !== '';
}

function deliveries_feedback_ensure_schema(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM delivery_notes')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('customer_rating', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN customer_rating TINYINT NULL DEFAULT NULL');
        }
        if (!in_array('customer_feedback', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN customer_feedback TEXT NULL');
        }
        if (!in_array('feedback_submitted_at', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN feedback_submitted_at DATETIME NULL DEFAULT NULL');
        }
        if (!in_array('product_inquiries_json', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN product_inquiries_json LONGTEXT NULL');
        }
        if (!in_array('feedback_public_token', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN feedback_public_token VARCHAR(64) NULL DEFAULT NULL');
        }
        if (!in_array('feedback_token_expires_at', $cols, true)) {
            $pdo->exec('ALTER TABLE delivery_notes ADD COLUMN feedback_token_expires_at DATETIME NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // Keep page usable even if ALTER is denied.
    }
}

/**
 * Create / refresh a per-note random public token (unguessable, expiring).
 */
function deliveries_feedback_ensure_public_token(PDO $pdo, int $noteId, bool $rotate = false): string
{
    if ($noteId <= 0) {
        return '';
    }
    deliveries_feedback_ensure_schema($pdo);
    $ttlDays = deliveries_feedback_token_ttl_days();

    try {
        $stmt = $pdo->prepare(
            'SELECT feedback_public_token, feedback_token_expires_at
             FROM delivery_notes WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$noteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return '';
        }

        $existing = trim((string) ($row['feedback_public_token'] ?? ''));
        $expiresAt = trim((string) ($row['feedback_token_expires_at'] ?? ''));
        $stillValid = $existing !== ''
            && strlen($existing) >= 32
            && ($expiresAt === '' || strtotime($expiresAt) === false || strtotime($expiresAt) > time());

        if (!$rotate && $stillValid) {
            return $existing;
        }

        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $expires = date('Y-m-d H:i:s', time() + ($ttlDays * 86400));
        $upd = $pdo->prepare(
            'UPDATE delivery_notes
             SET feedback_public_token = ?, feedback_token_expires_at = ?
             WHERE id = ?'
        );
        $upd->execute([$token, $expires, $noteId]);

        return $token;
    } catch (Throwable $e) {
        // Fallback: legacy deterministic token so QR printing never hard-fails.
        return deliveries_feedback_legacy_token($noteId);
    }
}

function deliveries_feedback_token_valid(int $noteId, string $token, ?PDO $pdo = null): bool
{
    if ($noteId <= 0 || $token === '') {
        return false;
    }
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        return false;
    }

    $db = $pdo;
    if (!($db instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $db = $GLOBALS['pdo'];
    }

    if ($db instanceof PDO) {
        try {
            deliveries_feedback_ensure_schema($db);
            $stmt = $db->prepare(
                'SELECT feedback_public_token, feedback_token_expires_at, delivery_date
                 FROM delivery_notes WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$noteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }

            $stored = trim((string) ($row['feedback_public_token'] ?? ''));
            $expiresAt = trim((string) ($row['feedback_token_expires_at'] ?? ''));
            if ($stored !== '' && hash_equals($stored, $token)) {
                if ($expiresAt !== '') {
                    $expTs = strtotime($expiresAt);
                    if ($expTs !== false && $expTs < time()) {
                        return false;
                    }
                }

                return true;
            }

            // Soft age gate for legacy HMAC links (printed before random tokens).
            $deliveryDate = trim((string) ($row['delivery_date'] ?? ''));
            if ($deliveryDate !== '') {
                $delTs = strtotime($deliveryDate . ' 00:00:00');
                if ($delTs !== false && $delTs < (time() - (deliveries_feedback_token_ttl_days() * 86400))) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            // Fall through to legacy check.
        }
    }

    $legacy = deliveries_feedback_legacy_token($noteId);

    return hash_equals($legacy, $token);
}

function deliveries_feedback_public_url(int $noteId, ?PDO $pdo = null): string
{
    if ($noteId <= 0) {
        return '';
    }
    $db = $pdo;
    if (!($db instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $db = $GLOBALS['pdo'];
    }
    $token = ($db instanceof PDO)
        ? deliveries_feedback_ensure_public_token($db, $noteId)
        : deliveries_feedback_legacy_token($noteId);
    if ($token === '') {
        return '';
    }

    $query = [
        'id' => $noteId,
        't' => $token,
        'module' => 'deliveries',
    ];
    if (function_exists('deliveries_app_url')) {
        return deliveries_app_url('deliveries/delivery_feedback.php', $query);
    }
    if (function_exists('app_url')) {
        return (string) app_url('/deliveries/delivery_feedback.php?' . http_build_query($query));
    }

    return '/deliveries/delivery_feedback.php?' . http_build_query($query);
}

/**
 * Absolute public origin for QR / share links (phones need https://host/...).
 */
function deliveries_feedback_public_origin(): string
{
    if (defined('ERP_PUBLIC_SITE_URL')) {
        $configured = rtrim(trim((string) ERP_PUBLIC_SITE_URL), '/');
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return $configured;
        }
    }

    $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = $forwarded === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && !preg_match('/^(localhost|127\.0\.0\.1)(:|$)/i', $host)) {
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    return 'https://ultitech.io';
}

/**
 * Turn a site-relative feedback path into a phone-scannable absolute URL.
 */
function deliveries_feedback_absolute_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $origin = deliveries_feedback_public_origin();

    if (preg_match('#^https?://#i', $url)) {
        // Rewrite accidental localhost / LAN hosts to the public site.
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || preg_match('/^(localhost|127\.0\.0\.1)$/i', $host) || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host)) {
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
            $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';

            return $origin . $path . $query . $fragment;
        }

        return $url;
    }

    return $origin . '/' . ltrim($url, '/');
}

function deliveries_feedback_qr_image_url(string $targetUrl, int $size = 140): string
{
    $targetUrl = trim($targetUrl);
    if ($targetUrl === '') {
        return '';
    }

    // Phone cameras require a full https:// URL — relative /ultimate/... paths fail.
    if (!preg_match('#^https?://#i', $targetUrl) && function_exists('app_url')) {
        $targetUrl = (string) app_url('/' . ltrim($targetUrl, '/'));
    }
    $targetUrl = deliveries_feedback_absolute_url($targetUrl);
    if ($targetUrl === '') {
        return '';
    }

    $size = max(96, min(320, $size));

    // Rounded modules + rounded finders (simple liquid layout).
    return 'https://quickchart.io/qr?' . http_build_query([
        'text' => $targetUrl,
        'size' => $size,
        'margin' => 1,
        'ecLevel' => 'M',
        'dark' => '000000',
        'light' => 'ffffff',
        'dotStyle' => 'rounded',
        'finderStyle' => 'rounded',
        'finderDotStyle' => 'rounded',
        'format' => 'png',
    ]);
}

/**
 * Persist a product interest inquiry from the public feedback slider.
 *
 * @return array{ok:bool,error?:string,inquiry?:array<string,mixed>,whatsapp_url?:string}
 */
function deliveries_feedback_save_inquiry(
    PDO $pdo,
    int $noteId,
    string $token,
    int $productId,
    string $productName,
    string $productCode,
    int $qty,
    string $message,
    string $companyPhone = ''
): array {
    if (!deliveries_feedback_token_valid($noteId, $token, $pdo)) {
        return ['ok' => false, 'error' => 'This feedback link is invalid or has expired.'];
    }

    $ip = deliveries_feedback_client_ip();
    if (!deliveries_feedback_rate_allow('inquiry-ip:' . $ip, 20, 3600)) {
        return ['ok' => false, 'error' => 'Too many requests. Please try again later.'];
    }
    if (!deliveries_feedback_rate_allow('inquiry-note:' . $noteId, 30, 86400)) {
        return ['ok' => false, 'error' => 'Too many quote requests for this delivery.'];
    }

    $productId = max(0, $productId);
    $productName = deliveries_feedback_sanitize_user_text($productName, 180);
    $productCode = deliveries_feedback_sanitize_user_text($productCode, 80);
    $message = deliveries_feedback_sanitize_user_text($message, 1000);
    $qty = max(1, min(9999, $qty));

    // Prefer canonical product fields from catalogue when product_id is known.
    if ($productId > 0) {
        try {
            $pStmt = $pdo->prepare('SELECT name, product_code FROM products WHERE id = ? LIMIT 1');
            $pStmt->execute([$productId]);
            $prod = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($prod)) {
                $dbName = trim((string) ($prod['name'] ?? ''));
                $dbCode = trim((string) ($prod['product_code'] ?? ''));
                if ($dbName !== '') {
                    $productName = $dbName;
                }
                if ($dbCode !== '') {
                    $productCode = $dbCode;
                }
            }
        } catch (Throwable $e) {
            // Keep client-provided sanitized values.
        }
    }

    if ($productName === '' && $productId <= 0) {
        return ['ok' => false, 'error' => 'Please choose a product first.'];
    }

    deliveries_feedback_ensure_schema($pdo);

    $inquiry = [
        'at' => date('c'),
        'product_id' => $productId,
        'product_name' => $productName,
        'product_code' => $productCode,
        'qty' => $qty,
        'message' => $message,
        'ip' => $ip,
    ];

    try {
        $stmt = $pdo->prepare('SELECT product_inquiries_json, note_number, customer_name FROM delivery_notes WHERE id = ? LIMIT 1');
        $stmt->execute([$noteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Delivery note not found.'];
        }

        $list = json_decode((string) ($row['product_inquiries_json'] ?? '[]'), true);
        if (!is_array($list)) {
            $list = [];
        }
        // Cap spam volume per note.
        if (count($list) >= 40) {
            return ['ok' => false, 'error' => 'This delivery has reached the inquiry limit.'];
        }
        $list[] = $inquiry;
        if (count($list) > 40) {
            $list = array_slice($list, -40);
        }

        $upd = $pdo->prepare('UPDATE delivery_notes SET product_inquiries_json = ? WHERE id = ?');
        $upd->execute([json_encode($list, JSON_UNESCAPED_UNICODE), $noteId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save your inquiry. Please try again.'];
    }

    $noteNumber = trim((string) ($row['note_number'] ?? ''));
    $customerName = trim((string) ($row['customer_name'] ?? 'Customer'));
    $waText = "Hi, I'd like a price for *{$productName}* from my delivery feedback link"
        . ($noteNumber !== '' ? " ({$noteNumber})" : '')
        . ".\nCustomer: {$customerName}\nQty: {$qty}";
    if ($message !== '') {
        $waText .= "\nNote: {$message}";
    }
    $waText .= "\nPlease send the price / quote.";

    $whatsappUrl = '';
    $digits = preg_replace('/\D+/', '', $companyPhone) ?? '';
    if ($digits !== '') {
        // Tanzania local 0xxxxxxxxx → 255xxxxxxxxx
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '255' . substr($digits, 1);
        }
        $whatsappUrl = 'https://wa.me/' . $digits . '?text=' . rawurlencode($waText);
    }

    return [
        'ok' => true,
        'inquiry' => $inquiry,
        'whatsapp_url' => $whatsappUrl,
    ];
}

/**
 * @return array{ok:bool,error?:string,note?:array<string,mixed>,items?:list<array<string,mixed>>,brand?:array<string,mixed>,similar?:list<array<string,mixed>>,feedback?:array<string,mixed>}
 */
function deliveries_feedback_load(PDO $pdo, int $noteId, string $token): array
{
    if (!deliveries_feedback_token_valid($noteId, $token, $pdo)) {
        return ['ok' => false, 'error' => 'This feedback link is invalid or has expired.'];
    }

    $ip = deliveries_feedback_client_ip();
    if (!deliveries_feedback_rate_allow('view-ip:' . $ip, 120, 3600)) {
        return ['ok' => false, 'error' => 'Too many requests. Please try again later.'];
    }

    deliveries_feedback_ensure_schema($pdo);

    try {
        $stmt = $pdo->prepare('SELECT * FROM delivery_notes WHERE id = ? LIMIT 1');
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not load delivery note.'];
    }

    if (!$note) {
        return ['ok' => false, 'error' => 'Delivery note not found.'];
    }

    $items = json_decode((string) ($note['items_json'] ?? '[]'), true);
    if (!is_array($items)) {
        $items = [];
    }

    $productIds = [];
    foreach ($items as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        if ($pid > 0) {
            $productIds[] = $pid;
        }
    }
    $productIds = array_values(array_unique($productIds));

    $brand = ['name' => '', 'logoUrl' => '', 'phone' => '', 'address' => ''];
    if (function_exists('deliveries_load_public_company_branding')) {
        $companyId = 0;
        if (function_exists('currentCompanyId')) {
            $companyId = (int) (currentCompanyId() ?? 0);
        }
        $brand = deliveries_load_public_company_branding($pdo, $companyId);
    }

    $rating = $note['customer_rating'] ?? null;
    $feedback = [
        'saved' => $rating !== null && $rating !== '',
        'canSubmit' => $rating === null || $rating === '',
        'rating' => $rating !== null && $rating !== '' ? (int) $rating : null,
        'comment' => (string) ($note['customer_feedback'] ?? ''),
    ];

    return [
        'ok' => true,
        'note' => $note,
        'items' => $items,
        'brand' => $brand,
        'similar' => deliveries_feedback_similar_products($pdo, $productIds, 4),
        'feedback' => $feedback,
    ];
}

/**
 * Rank related catalogue products for the delivery feedback slider.
 *
 * What we show: products the customer did NOT just receive — same/nearby
 * category, same brand, similar price, preferably featured + with a photo.
 * Goal is cross-sell / “more like yours”, not repeating the delivery lines.
 *
 * @param list<int> $productIds
 * @return list<array<string,mixed>>
 */
function deliveries_feedback_similar_products(PDO $pdo, array $productIds, int $limit = 8): array
{
    $productIds = array_values(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0));
    if ($productIds === []) {
        return [];
    }

    $limit = max(1, min(24, $limit));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $seedRows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, category_id, brand, unit_price, main_image, image
            FROM products
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($productIds);
        $seedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    if ($seedRows === []) {
        return [];
    }

    $categoryIds = [];
    $brands = [];
    $prices = [];
    $seedTokens = [];
    foreach ($seedRows as $seed) {
        $cid = (int) ($seed['category_id'] ?? 0);
        if ($cid > 0) {
            $categoryIds[$cid] = $cid;
        }
        $brand = strtolower(trim((string) ($seed['brand'] ?? '')));
        if ($brand !== '') {
            $brands[$brand] = $brand;
        }
        $price = (float) ($seed['unit_price'] ?? 0);
        if ($price > 0) {
            $prices[] = $price;
        }
        foreach (deliveries_feedback_product_tokens((string) ($seed['name'] ?? '')) as $tok) {
            $seedTokens[$tok] = true;
        }
    }

    $avgPrice = $prices !== [] ? array_sum($prices) / count($prices) : 0.0;
    $minPrice = $avgPrice > 0 ? $avgPrice * 0.45 : 0.0;
    $maxPrice = $avgPrice > 0 ? $avgPrice * 2.2 : 0.0;

    // Pull a wider candidate pool, then score in PHP.
    $fetchLimit = max(40, $limit * 12);
    $excludePlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $where = [
        "p.id NOT IN ($excludePlaceholders)",
        "(p.status IS NULL OR p.status = '' OR LOWER(p.status) IN ('active','enabled','1'))",
        "(p.catalogue_visibility IS NULL OR p.catalogue_visibility = '' OR p.catalogue_visibility = 'visible')",
    ];
    $params = $productIds;

    $orParts = [];
    if ($categoryIds !== []) {
        $catPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $orParts[] = "p.category_id IN ($catPlaceholders)";
        $params = array_merge($params, array_values($categoryIds));
    }
    if ($brands !== []) {
        $brandPlaceholders = implode(',', array_fill(0, count($brands), '?'));
        $orParts[] = "LOWER(TRIM(COALESCE(p.brand, ''))) IN ($brandPlaceholders)";
        $params = array_merge($params, array_values($brands));
    }
    if ($orParts === []) {
        // No category/brand signal — fall back to recent active products with images.
        $orParts[] = '1=1';
    }
    $where[] = '(' . implode(' OR ', $orParts) . ')';

    $rows = [];
    try {
        $sql = "
            SELECT p.id, p.name, p.product_code, p.description, p.unit_price, p.main_image, p.image, p.currency,
                   p.category_id, p.brand, p.is_featured, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.is_featured DESC, p.id DESC
            LIMIT {$fetchLimit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Older schemas may lack catalogue_visibility / is_featured / brand.
        try {
            $sql = "
                SELECT p.id, p.name, p.product_code, p.description, p.unit_price, p.main_image, p.image, p.currency,
                       p.category_id, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.id NOT IN ($excludePlaceholders)
                  AND (p.status IS NULL OR p.status = '' OR LOWER(p.status) IN ('active','enabled','1'))
                  " . ($categoryIds !== [] ? 'AND p.category_id IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ')' : '') . "
                ORDER BY p.id DESC
                LIMIT {$fetchLimit}
            ";
            $stmt = $pdo->prepare($sql);
            $fallbackParams = $productIds;
            if ($categoryIds !== []) {
                $fallbackParams = array_merge($fallbackParams, array_values($categoryIds));
            }
            $stmt->execute($fallbackParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }

    if (function_exists('sales_load_stock_image_helpers')) {
        sales_load_stock_image_helpers();
    }

    $scored = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $img = trim((string) ($row['main_image'] ?? ''));
        if ($img === '') {
            $img = trim((string) ($row['image'] ?? ''));
        }
        $imageUrl = '';
        if ($img !== '') {
            if (function_exists('stock_product_list_image_url')) {
                $imageUrl = (string) stock_product_list_image_url($pid, $img, 'large');
            } elseif (function_exists('app_url')) {
                $imageUrl = (string) app_url(
                    'stock/product_image.php?' . http_build_query([
                        'product_id' => $pid,
                        'size' => 'large',
                        'file' => basename($img),
                    ])
                );
            }
        }

        // Slider needs a photo — skip blank catalogue cards first.
        if ($imageUrl === '') {
            continue;
        }

        $score = 0;
        $cid = (int) ($row['category_id'] ?? 0);
        if ($cid > 0 && isset($categoryIds[$cid])) {
            $score += 40;
        }

        $brand = strtolower(trim((string) ($row['brand'] ?? '')));
        if ($brand !== '' && isset($brands[$brand])) {
            $score += 22;
        }

        $price = (float) ($row['unit_price'] ?? 0);
        if ($avgPrice > 0 && $price > 0) {
            if ($price >= $minPrice && $price <= $maxPrice) {
                $score += 18;
            } else {
                $ratio = $price / $avgPrice;
                if ($ratio >= 0.25 && $ratio <= 3.5) {
                    $score += 6;
                }
            }
        }

        if (!empty($row['is_featured'])) {
            $score += 12;
        }

        $name = (string) ($row['name'] ?? '');
        $overlap = 0;
        foreach (deliveries_feedback_product_tokens($name) as $tok) {
            if (isset($seedTokens[$tok])) {
                $overlap++;
            }
        }
        if ($overlap > 0) {
            $score += min(20, $overlap * 6);
        }

        // Mild recency nudge so newer SKUs surface among equal scores.
        $score += min(5, (int) floor($pid / 1000));

        $currency = strtoupper(trim((string) ($row['currency'] ?? 'TZS')));
        if ($currency === '') {
            $currency = 'TZS';
        }

        $description = trim((string) ($row['description'] ?? ''));
        if (mb_strlen($description) > 800) {
            $description = rtrim(mb_substr($description, 0, 800)) . '…';
        }

        $scored[] = [
            'score' => $score,
            'product' => [
                'id' => $pid,
                'name' => $name,
                'product_code' => (string) ($row['product_code'] ?? ''),
                'brand' => trim((string) ($row['brand'] ?? '')),
                'description' => $description,
                'category_name' => (string) ($row['category_name'] ?? ''),
                'unit_price' => $price,
                'currency' => $currency,
                'image_url' => $imageUrl,
            ],
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return ($b['product']['id'] <=> $a['product']['id']);
        }
        return $b['score'] <=> $a['score'];
    });

    // Diversify: at most 2 from the same category when possible, then fill.
    $diversified = [];
    $catCounts = [];
    $deferred = [];
    foreach ($scored as $entry) {
        $prod = $entry['product'];
        $catKey = strtolower(trim((string) ($prod['category_name'] ?? '')));
        if ($catKey === '') {
            $catKey = '_';
        }
        $count = $catCounts[$catKey] ?? 0;
        if ($count >= 2) {
            $deferred[] = $prod;
            continue;
        }
        $catCounts[$catKey] = $count + 1;
        $diversified[] = $prod;
        if (count($diversified) >= $limit) {
            break;
        }
    }
    foreach ($deferred as $prod) {
        if (count($diversified) >= $limit) {
            break;
        }
        $diversified[] = $prod;
    }

    return array_slice($diversified, 0, $limit);
}

/**
 * @return list<string>
 */
function deliveries_feedback_product_tokens(string $name): array
{
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9\s]+/', ' ', $name) ?? '';
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $stop = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'size' => true,
        'pair' => true, 'pcs' => true, 'pc' => true, 'set' => true, 'of' => true,
        'a' => true, 'an' => true, 'x' => true,
    ];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if (strlen($part) < 3 || isset($stop[$part])) {
            continue;
        }
        $out[$part] = $part;
    }
    return array_values($out);
}

/**
 * @return array{ok:bool,error?:string,feedback?:array<string,mixed>}
 */
function deliveries_feedback_save(PDO $pdo, int $noteId, string $token, int $rating, string $comment): array
{
    if (!deliveries_feedback_token_valid($noteId, $token, $pdo)) {
        return ['ok' => false, 'error' => 'This feedback link is invalid or has expired.'];
    }
    if ($rating < 1 || $rating > 5) {
        return ['ok' => false, 'error' => 'Please choose a rating from 1 to 5 stars.'];
    }

    $ip = deliveries_feedback_client_ip();
    if (!deliveries_feedback_rate_allow('review-ip:' . $ip, 12, 3600)) {
        return ['ok' => false, 'error' => 'Too many requests. Please try again later.'];
    }
    if (!deliveries_feedback_rate_allow('review-note:' . $noteId, 5, 86400)) {
        return ['ok' => false, 'error' => 'This delivery already received feedback attempts recently.'];
    }

    deliveries_feedback_ensure_schema($pdo);
    $comment = deliveries_feedback_sanitize_user_text($comment, 2000);

    // One-time lock: never overwrite an existing customer review.
    try {
        $chk = $pdo->prepare(
            'SELECT customer_rating FROM delivery_notes WHERE id = ? LIMIT 1'
        );
        $chk->execute([$noteId]);
        $existing = $chk->fetchColumn();
        if ($existing !== false && $existing !== null && $existing !== '') {
            return ['ok' => false, 'error' => 'Feedback was already submitted for this delivery.'];
        }
    } catch (Throwable $e) {
        // Continue; save path still uses WHERE guard below when possible.
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE delivery_notes
             SET customer_rating = ?, customer_feedback = ?, feedback_submitted_at = NOW()
             WHERE id = ?
               AND customer_rating IS NULL'
        );
        $stmt->execute([$rating, $comment, $noteId]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Feedback was already submitted for this delivery.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save your review. Please try again.'];
    }

    // Mirror onto linked delivery order so the internal Reviews screen stays complete.
    try {
        $orderId = 0;

        $stmtOrder = $pdo->prepare('SELECT id FROM delivery_orders WHERE delivery_note_id = ? ORDER BY id DESC LIMIT 1');
        $stmtOrder->execute([$noteId]);
        $orderId = (int) ($stmtOrder->fetchColumn() ?: 0);

        $noteMeta = null;
        try {
            $stmtNote = $pdo->prepare('SELECT order_id, note_number FROM delivery_notes WHERE id = ? LIMIT 1');
            $stmtNote->execute([$noteId]);
            $noteMeta = $stmtNote->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $noteMeta = null;
        }

        if ($orderId <= 0 && is_array($noteMeta)) {
            $salesOrderId = (int) ($noteMeta['order_id'] ?? 0);
            if ($salesOrderId > 0) {
                try {
                    $stmtBySales = $pdo->prepare('SELECT id FROM delivery_orders WHERE order_id = ? OR sales_order_id = ? ORDER BY id DESC LIMIT 1');
                    $stmtBySales->execute([$salesOrderId, $salesOrderId]);
                } catch (Throwable $e) {
                    $stmtBySales = $pdo->prepare('SELECT id FROM delivery_orders WHERE order_id = ? ORDER BY id DESC LIMIT 1');
                    $stmtBySales->execute([$salesOrderId]);
                }
                $orderId = (int) ($stmtBySales->fetchColumn() ?: 0);
            }
        }

        if ($orderId <= 0 && is_array($noteMeta)) {
            $noteNumber = trim((string) ($noteMeta['note_number'] ?? ''));
            if ($noteNumber !== '') {
                try {
                    $stmtByRef = $pdo->prepare('SELECT id FROM delivery_orders WHERE invoice_ref = ? ORDER BY id DESC LIMIT 1');
                    $stmtByRef->execute([$noteNumber]);
                    $orderId = (int) ($stmtByRef->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                    $orderId = 0;
                }
            }
        }

        if ($orderId > 0) {
            try {
                $upd = $pdo->prepare(
                    'UPDATE delivery_orders
                     SET customer_rating = ?, customer_feedback = ?, delivery_note_id = COALESCE(delivery_note_id, ?)
                     WHERE id = ?'
                );
                $upd->execute([$rating, $comment, $noteId, $orderId]);
            } catch (Throwable $e) {
                $upd = $pdo->prepare('UPDATE delivery_orders SET customer_rating = ?, customer_feedback = ? WHERE id = ?');
                $upd->execute([$rating, $comment, $orderId]);
            }
        }
    } catch (Throwable $e) {
        // Note review already saved — reviews page also reads delivery_notes directly.
    }

    try {
        deliveries_feedback_notify_staff($pdo, $noteId, $rating, $comment);
    } catch (Throwable $e) {
        // Review is saved; notification is best-effort.
    }

    return [
        'ok' => true,
        'feedback' => [
            'saved' => true,
            'canSubmit' => false,
            'rating' => $rating,
            'comment' => $comment,
        ],
    ];
}

/**
 * Strip auto-appended follow-up lines from a review comment.
 */
function deliveries_feedback_comment_body(string $comment): string
{
    $lines = preg_split('/\R+/', $comment) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        if (preg_match('/^follow\s*up\s*:\s*(yes|no)\s*$/i', $trim)) {
            continue;
        }
        $kept[] = $trim;
    }

    return trim(implode("\n", $kept));
}

/**
 * @return list<int>
 */
function deliveries_feedback_notify_recipient_ids(PDO $pdo, int $noteId = 0): array
{
    $ids = [];

    try {
        $hasActive = false;
        try {
            $pdo->query('SELECT is_active FROM users LIMIT 1');
            $hasActive = true;
        } catch (Throwable $e) {
            $hasActive = false;
        }

        $hasStatus = false;
        try {
            $pdo->query('SELECT status FROM users LIMIT 1');
            $hasStatus = true;
        } catch (Throwable $e) {
            $hasStatus = false;
        }

        $sql = 'SELECT id FROM users WHERE 1=1';
        if ($hasActive) {
            $sql .= ' AND is_active = 1';
        }
        if ($hasStatus) {
            $sql .= " AND LOWER(TRIM(COALESCE(status, 'active'))) NOT IN ('disabled', 'inactive', 'deleted', 'suspended')";
        }
        $sql .= ' ORDER BY id ASC LIMIT 500';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $uid = (int) $id;
            if ($uid > 0) {
                $ids[$uid] = $uid;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return array_values($ids);
}

function deliveries_feedback_reviews_link(): string
{
    if (function_exists('deliveries_module_url')) {
        return deliveries_module_url('deliveries/customer_reviews.php');
    }
    if (function_exists('deliveries_app_url')) {
        return deliveries_app_url('deliveries/customer_reviews.php', ['module' => 'deliveries']);
    }
    if (function_exists('company_url')) {
        return company_url('deliveries/customer_reviews.php');
    }
    if (function_exists('app_url')) {
        return (string) app_url('/deliveries/customer_reviews.php?module=deliveries');
    }

    return 'deliveries/customer_reviews.php?module=deliveries';
}

/**
 * Bell notification when a delivery review includes a written comment.
 */
function deliveries_feedback_notify_staff(PDO $pdo, int $noteId, int $rating, string $comment): void
{
    $body = deliveries_feedback_comment_body($comment);
    if ($body === '') {
        return;
    }

    $noteNumber = '';
    $clientName = '';
    try {
        $stmt = $pdo->prepare('SELECT note_number, customer_name FROM delivery_notes WHERE id = ? LIMIT 1');
        $stmt->execute([$noteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $noteNumber = trim((string) ($row['note_number'] ?? ''));
        $clientName = trim((string) ($row['customer_name'] ?? ''));
    } catch (Throwable $e) {
        // ignore
    }

    $stars = max(1, min(5, $rating));
    $title = 'Delivery feedback comment';
    $parts = [];
    if ($noteNumber !== '') {
        $parts[] = $noteNumber;
    }
    if ($clientName !== '') {
        $parts[] = $clientName;
    }
    $parts[] = $stars . '/5';
    $snippet = $body;
    if (mb_strlen($snippet) > 160) {
        $snippet = mb_substr($snippet, 0, 157) . '…';
    }
    $message = implode(' · ', $parts) . ' — “' . $snippet . '”';
    $type = $stars <= 3 ? 'warning' : 'info';
    $link = deliveries_feedback_reviews_link();

    $recipients = deliveries_feedback_notify_recipient_ids($pdo, $noteId);
    if ($recipients === []) {
        if (function_exists('createNotification')) {
            createNotification([
                'user_id' => null,
                'audience' => 'all',
                'title' => $title,
                'message' => $message,
                'type' => $type,
            ]);
        }

        return;
    }

    // One linked bell notification for every active user.
    foreach ($recipients as $userId) {
        if (function_exists('createSystemNotification')) {
            createSystemNotification($userId, $title, $message, $link !== '' ? $link : null, $type);
            continue;
        }
        if (function_exists('createNotification')) {
            createNotification([
                'user_id' => $userId,
                'audience' => 'user',
                'title' => $title,
                'message' => $message,
                'type' => $type,
            ]);
        }
    }
}
