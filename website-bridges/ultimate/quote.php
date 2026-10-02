<?php
/**
 * Request quote button for ultimate.co.tz product pages.
 *
 * Upload next to sync.php:
 *   /home/ultimate/public_html/ultitech/quote.php
 *   /home/ultimate/public_html/ultitech/quote-button.js
 *
 * Then open once:
 *   https://ultimate.co.tz/ultitech/quote.php?key=ugt7k-sync-4m2p&install=1
 */
declare(strict_types=1);

const ULTITECH_QUOTE_URL = 'https://ultitech.io/api/storefront/quote.php?company_slug=ultimate';
const ULTITECH_API_TOKEN = 'roadmaster-storefront-dev-token-change-me';
const QUOTE_WEB_KEY = 'ugt7k-sync-4m2p';
const QUOTE_BUTTON_MARKER = 'ultitech/quote-button.js';

function ultitechQuoteRoot(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if (is_file($dir . '/.env') && (is_file($dir . '/artisan') || is_dir($dir . '/public'))) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return '';
}

function ultitechQuoteEnv(string $file): array
{
    $vars = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $vars;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if ($val !== '' && (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'")))) {
            $val = substr($val, 1, -1);
        }
        $vars[$key] = $val;
    }
    return $vars;
}

function ultitechQuotePdo(): PDO
{
    $root = ultitechQuoteRoot();
    if ($root === '') {
        throw new RuntimeException('Shop .env was not found.');
    }
    $env = ultitechQuoteEnv($root . '/.env');
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? '';
    $pass = $env['DB_PASSWORD'] ?? '';
    $host = $env['DB_HOST'] ?? 'localhost';
    $port = $env['DB_PORT'] ?? '3306';
    if ($name === '' || $user === '') {
        throw new RuntimeException('Shop database settings are missing.');
    }
    return new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function ultitechQuoteColumns(PDO $pdo, string $table): array
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[(string) $row['Field']] = true;
    }
    return $cols;
}

function ultitechQuoteScriptTag(): string
{
    return '<script src="/ultitech/quote-button.js?v=1" defer></script>';
}

function ultitechInstallQuoteButton(PDO $pdo): string
{
    $cols = ultitechQuoteColumns($pdo, 'business_settings');
    if (!isset($cols['type'], $cols['value'])) {
        throw new RuntimeException('Shop settings table cannot store the quote button.');
    }
    $tag = ultitechQuoteScriptTag();
    $types = ['footer_script', 'custom_script', 'custom_js'];
    $updated = false;
    foreach ($types as $type) {
        $st = $pdo->prepare('SELECT id, value FROM business_settings WHERE type = ?');
        $st->execute([$type]);
        $rows = $st->fetchAll();
        if ($rows === []) {
            continue;
        }
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if (str_contains($value, QUOTE_BUTTON_MARKER)) {
                $updated = true;
                continue;
            }
            $next = rtrim($value) . ($value === '' ? '' : "\n") . $tag;
            $up = $pdo->prepare('UPDATE business_settings SET value = ? WHERE id = ?');
            $up->execute([$next, (int) $row['id']]);
            $updated = true;
        }
    }
    if (!$updated) {
        $data = ['type' => 'footer_script', 'value' => $tag];
        if (isset($cols['lang'])) {
            $data['lang'] = 'en';
        }
        $fields = array_keys($data);
        $sql = 'INSERT INTO business_settings (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
        $pdo->prepare($sql)->execute(array_values($data));
    }
    ultitechQuoteClearCache();
    return 'Request quote button is on product pages.';
}

function ultitechQuoteClearCache(): void
{
    $dir = ultitechQuoteRoot() . '/storage/framework/cache/data';
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            @unlink($file->getPathname());
        }
    }
}

function ultitechQuoteJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ultitechQuoteSubmit(PDO $pdo): void
{
    $raw = file_get_contents('php://input');
    $body = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($body)) {
        ultitechQuoteJson(['success' => false, 'error' => 'Invalid request'], 400);
    }
    if (trim((string) ($body['company_website'] ?? '')) !== '') {
        ultitechQuoteJson(['success' => true]);
    }
    $name = trim((string) ($body['customer_name'] ?? ''));
    $phone = trim((string) ($body['customer_phone'] ?? ''));
    $email = trim((string) ($body['customer_email'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $productName = trim((string) ($body['product_name'] ?? ''));
    $websiteId = (int) ($body['website_product_id'] ?? 0);
    $qty = (float) ($body['quantity'] ?? 1);
    if ($name === '' || $phone === '') {
        ultitechQuoteJson(['success' => false, 'error' => 'Name and phone are required.'], 400);
    }
    if ($qty <= 0) {
        $qty = 1;
    }

    $ultiId = 0;
    $sku = '';
    try {
        $st = $pdo->prepare('SELECT ultitech_product_id, sku FROM ultitech_links WHERE website_product_id = ? LIMIT 1');
        $st->execute([$websiteId]);
        $link = $st->fetch();
        if (is_array($link)) {
            $ultiId = (int) ($link['ultitech_product_id'] ?? 0);
            $sku = (string) ($link['sku'] ?? '');
        }
    } catch (Throwable $e) {
        $ultiId = 0;
    }

    $item = [
        'product_sku' => $sku,
        'product_name' => $productName,
        'quantity' => $qty,
        'website_product_id' => $websiteId,
    ];
    if ($ultiId > 0) {
        $item['product_id'] = $ultiId;
    }
    $payload = [
        'customer_name' => $name,
        'customer_email' => $email,
        'customer_phone' => $phone,
        'notes' => $notes,
        'items' => [$item],
    ];

    if (!function_exists('curl_init')) {
        ultitechQuoteJson(['success' => false, 'error' => 'Could not reach UltiTech.'], 500);
    }
    $ch = curl_init(ULTITECH_QUOTE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . ULTITECH_API_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode(is_string($response) ? $response : '', true);
    if (!is_array($json) || $code >= 400 || empty($json['success'])) {
        $error = is_array($json) ? (string) ($json['error'] ?? '') : '';
        ultitechQuoteJson(['success' => false, 'error' => $error !== '' ? $error : 'Could not send the request.'], 502);
    }
    ultitechQuoteJson(['success' => true, 'quote_number' => (string) ($json['quote_number'] ?? '')]);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            ultitechQuoteSubmit(ultitechQuotePdo());
        }
        $given = (string) ($_GET['key'] ?? '');
        $install = (string) ($_GET['install'] ?? '');
        if ($install === '1' && $given !== '' && hash_equals(QUOTE_WEB_KEY, $given)) {
            $message = ultitechInstallQuoteButton(ultitechQuotePdo());
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><body style="font-family:Georgia,serif;max-width:720px;margin:40px auto;padding:0 16px">';
            echo '<h1>Request quote</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
            echo '<p><a href="https://ultimate.co.tz/search">Open a product</a> and use Request quote next to Add to cart.</p>';
            echo '</body></html>';
            exit;
        }
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'POST required';
    } catch (Throwable $e) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            ultitechQuoteJson(['success' => false, 'error' => $e->getMessage()], 500);
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
