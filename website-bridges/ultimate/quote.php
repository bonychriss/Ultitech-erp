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

require_once __DIR__ . '/bridge-lib.php';

if (!defined('ULTITECH_API_TOKEN')) {
    define('ULTITECH_API_TOKEN', 'roadmaster-storefront-dev-token-change-me');
}
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
    return '<script src="/ultitech/quote-button.js?v=8" defer></script>';
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
                $next = preg_replace('#<script[^>]*' . preg_quote(QUOTE_BUTTON_MARKER, '#') . '[^>]*></script>#', $tag, $value);
                if (is_string($next) && $next !== $value) {
                    $up = $pdo->prepare('UPDATE business_settings SET value = ? WHERE id = ?');
                    $up->execute([$next, (int) $row['id']]);
                }
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
        $now = date('Y-m-d H:i:s');
        if (isset($cols['created_at'])) {
            $data['created_at'] = $now;
        }
        if (isset($cols['updated_at'])) {
            $data['updated_at'] = $now;
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
        ultitechQuoteJson(['success' => false, 'message' => 'Please check the form and try again.'], 400);
    }
    if (trim((string) ($body['company_website'] ?? '')) !== '') {
        ultitechQuoteJson(['success' => true, 'message' => ultitechCustomerMessage()]);
    }
    $items = $body['items'] ?? [];
    if (!is_array($items) || $items === []) {
        $items = [[
            'website_product_id' => (int) ($body['website_product_id'] ?? 0),
            'product_name' => (string) ($body['product_name'] ?? ''),
            'quantity' => $body['quantity'] ?? 1,
        ]];
    }
    try {
        $saved = ultitechSaveQuote($pdo, [
            'customer_name' => $body['customer_name'] ?? '',
            'customer_phone' => $body['customer_phone'] ?? '',
            'customer_email' => $body['customer_email'] ?? '',
            'notes' => $body['notes'] ?? '',
            'items' => $items,
        ]);
    } catch (InvalidArgumentException $e) {
        ultitechQuoteJson(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        ultitechQuoteJson(['success' => false, 'message' => 'Please try again in a moment.'], 500);
    }
    try {
        ultitechSyncQuote($pdo, (int) $saved['id']);
    } catch (Throwable $e) {
        // The quote is already stored. The customer does not need the sync error.
    }
    ultitechQuoteJson([
        'success' => true,
        'message' => ultitechCustomerMessage(),
        'quote_number' => $saved['quote_number'],
    ]);
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
            echo '<p><a href="https://ultimate.co.tz/search">Open a product</a> and use Add to quote next to Add to cart.</p>';
            echo '</body></html>';
            exit;
        }
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'POST required';
    } catch (Throwable $e) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            ultitechQuoteJson(['success' => false, 'message' => 'Please try again in a moment.'], 500);
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
