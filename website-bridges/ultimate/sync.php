<?php
/**
 * Connect UltiTech Ultimate products to the ultimate.co.tz shop.
 *
 * Upload this file to the shop server:
 *   /home/ultimate/public_html/ultitech/sync.php
 *
 * Then open (once):
 *   https://ultimate.co.tz/ultitech/sync.php?key=ugt7k-sync-4m2p
 *
 * It reads the shop's Laravel .env, creates missing categories, creates or
 * updates products (price, details, photo, stock), and sends later website
 * orders back to UltiTech. The existing product cards, cart, and checkout stay.
 *
 * Cron, every 15 minutes:
 *   php /home/ultimate/public_html/ultitech/sync.php
 */

declare(strict_types=1);

const ULTITECH_CATALOG_URL = 'https://ultitech.io/api/storefront/catalog.php?company_slug=ultimate';
const ULTITECH_ORDER_URL = 'https://ultitech.io/api/storefront/order.php?company_slug=ultimate';
const ULTITECH_API_TOKEN = 'roadmaster-storefront-dev-token-change-me';
const SYNC_WEB_KEY = 'ugt7k-sync-4m2p';
const BATCH_SIZE = 10;

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $given = (string) ($_GET['key'] ?? '');
    if ($given === '' || !hash_equals(SYNC_WEB_KEY, $given)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="font-family:Georgia,serif;max-width:720px;margin:40px auto;padding:0 16px">';
        echo '<h1>UltiTech shop connection</h1>';
        echo '<p>This file links UltiTech products to ultimate.co.tz. Open it with the <code>key</code> set at the top of <code>sync.php</code>.</p>';
        echo '</body></html>';
        exit;
    }
}

@set_time_limit(180);
date_default_timezone_set('Africa/Dar_es_Salaam');

$log = [];
function note(string $line): void
{
    global $log;
    $log[] = $line;
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

function cacheDir(): string
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    return $dir;
}

function laravelRoot(): string
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

function envValue(string $file): array
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

function shopPdo(): PDO
{
    $root = laravelRoot();
    if ($root === '') {
        throw new RuntimeException('Shop .env was not found. Upload this file to public_html/ultitech/sync.php, next to the website (the folder that contains .env, artisan, and public).');
    }
    $env = envValue($root . '/.env');
    $name = $env['DB_DATABASE'] ?? '';
    $user = $env['DB_USERNAME'] ?? '';
    $pass = $env['DB_PASSWORD'] ?? '';
    $host = $env['DB_HOST'] ?? 'localhost';
    $port = $env['DB_PORT'] ?? '3306';
    if ($name === '' || $user === '') {
        throw new RuntimeException('DB_DATABASE or DB_USERNAME is missing in the shop .env.');
    }
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function tableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll();
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[$row['Field']] = $row;
    }
    return $cache[$table] = $out;
}

function tableReady(PDO $pdo, string $table): bool
{
    return tableColumns($pdo, $table) !== [];
}

function defaultForType(string $type): mixed
{
    $type = strtolower($type);
    if (preg_match('/int|decimal|float|double|bit/', $type)) {
        return 0;
    }
    if (str_contains($type, 'json')) {
        return '[]';
    }
    if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
        return date('Y-m-d H:i:s');
    }
    if ($type === 'date' || str_starts_with($type, 'date')) {
        return date('Y-m-d');
    }
    return '';
}

function clip(PDO $pdo, string $table, string $column, string $value): string
{
    $cols = tableColumns($pdo, $table);
    if (!isset($cols[$column])) {
        return $value;
    }
    $type = (string) ($cols[$column]['Type'] ?? '');
    if (preg_match('/varchar\((\d+)\)/i', $type, $m)) {
        $max = (int) $m[1];
        if (mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max);
        }
    }
    return $value;
}

function insertRow(PDO $pdo, string $table, array $data): int
{
    $cols = tableColumns($pdo, $table);
    $filtered = [];
    foreach ($data as $key => $value) {
        if (isset($cols[$key])) {
            $filtered[$key] = $value;
        }
    }
    foreach ($cols as $name => $meta) {
        if (array_key_exists($name, $filtered)) {
            continue;
        }
        $extra = (string) ($meta['Extra'] ?? '');
        if (stripos($extra, 'auto_increment') !== false || stripos($extra, 'GENERATED') !== false) {
            continue;
        }
        if (($meta['Null'] ?? '') === 'YES') {
            continue;
        }
        if (($meta['Default'] ?? null) !== null) {
            continue;
        }
        $filtered[$name] = defaultForType((string) ($meta['Type'] ?? ''));
    }
    $fields = array_keys($filtered);
    if ($fields === []) {
        throw new RuntimeException('Nothing to insert into ' . $table);
    }
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($filtered));
    return (int) $pdo->lastInsertId();
}

function updateRow(PDO $pdo, string $table, array $data, string $pk, int $id): void
{
    $cols = tableColumns($pdo, $table);
    $sets = [];
    $vals = [];
    foreach ($data as $key => $value) {
        if ($key === $pk || !isset($cols[$key])) {
            continue;
        }
        $sets[] = '`' . $key . '` = ?';
        $vals[] = $value;
    }
    if ($sets === []) {
        return;
    }
    $vals[] = $id;
    $pdo->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `' . $pk . '` = ?')->execute($vals);
}

function normKey(string $value): string
{
    $value = strtolower($value);
    $value = str_replace('&', '', $value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    return $value;
}

function slugify(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'product';
    }
    return mb_substr($slug, 0, 180);
}

function htmlText(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if ($text[0] === '<') {
        return $text;
    }
    return '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
}

function ensureLinkTables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS ultitech_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ultitech_product_id INT NOT NULL,
        website_product_id INT NOT NULL,
        sku VARCHAR(160) NULL,
        image_url TEXT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_ultitech_product (ultitech_product_id),
        UNIQUE KEY uq_website_product (website_product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ultitech_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        website_order_id INT NOT NULL,
        ultitech_order_id INT NULL,
        ultitech_order_number VARCHAR(80) NULL,
        status VARCHAR(40) NOT NULL,
        message TEXT NULL,
        created_at DATETIME NULL,
        UNIQUE KEY uq_website_order (website_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function languageCodes(PDO $pdo): array
{
    if (!tableReady($pdo, 'languages')) {
        return ['en'];
    }
    $cols = tableColumns($pdo, 'languages');
    $codeCol = isset($cols['code']) ? 'code' : (isset($cols['app_lang_code']) ? 'app_lang_code' : '');
    if ($codeCol === '') {
        return ['en'];
    }
    $sql = 'SELECT `' . $codeCol . '` FROM languages';
    if (isset($cols['status'])) {
        $sql .= ' WHERE status = 1';
    }
    $codes = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $codes = array_values(array_filter(array_map('strval', $codes)));
    return $codes !== [] ? $codes : ['en'];
}

function adminUserId(PDO $pdo): int
{
    if (!tableReady($pdo, 'users')) {
        return 1;
    }
    $cols = tableColumns($pdo, 'users');
    if (isset($cols['user_type'])) {
        $id = (int) $pdo->query("SELECT id FROM users WHERE user_type = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }
    return (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1;
}

function saveTranslation(PDO $pdo, string $table, string $fk, int $fkId, string $lang, array $data): void
{
    if (!tableReady($pdo, $table)) {
        return;
    }
    $cols = tableColumns($pdo, $table);
    if (!isset($cols[$fk], $cols['lang'])) {
        return;
    }
    $st = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE `' . $fk . '` = ? AND lang = ? LIMIT 1');
    $st->execute([$fkId, $lang]);
    $id = (int) $st->fetchColumn();
    $data[$fk] = $fkId;
    $data['lang'] = $lang;
    if ($id > 0) {
        updateRow($pdo, $table, $data, 'id', $id);
        return;
    }
    insertRow($pdo, $table, $data);
}

function fetchCatalog(): array
{
    $file = cacheDir() . '/catalog.json';
    $offset = (int) ($_GET['offset'] ?? 0);
    if (PHP_SAPI === 'cli') {
        $offset = 0;
    }
    if ($offset > 0 && is_file($file) && (time() - (int) filemtime($file)) < 1800) {
        $cached = json_decode((string) file_get_contents($file), true);
        if (is_array($cached) && !empty($cached['products'])) {
            return $cached;
        }
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Enable the PHP curl extension in cPanel ? Select PHP Version ? Extensions.');
    }
    $ch = curl_init(ULTITECH_CATALOG_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . ULTITECH_API_TOKEN,
        ],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $body === '') {
        throw new RuntimeException('Could not reach UltiTech. ' . $err);
    }
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['success'])) {
        $message = is_array($json) ? (string) ($json['error'] ?? 'UltiTech refused the catalog.') : 'UltiTech did not return JSON.';
        if ($code === 401) {
            $message = 'UltiTech refused the token.';
        }
        throw new RuntimeException($message);
    }
    file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $json;
}

function categoryId(PDO $pdo, string $name, array &$map, int $adminId, array $langs): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $key = normKey($name);
    if ($key !== '' && isset($map[$key])) {
        return $map[$key];
    }
    $now = date('Y-m-d H:i:s');
    $slug = slugify($name);
    $base = $slug;
    $n = 2;
    while (true) {
        $st = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        if (!(int) $st->fetchColumn()) {
            break;
        }
        $slug = $base . '-' . $n;
        $n++;
    }
    $id = insertRow($pdo, 'categories', [
        'name' => clip($pdo, 'categories', 'name', $name),
        'slug' => clip($pdo, 'categories', 'slug', $slug),
        'parent_id' => 0,
        'level' => 0,
        'order_level' => 0,
        'commision_rate' => 0,
        'featured' => 1,
        'top' => 0,
        'digital' => 0,
        'published' => 1,
        'meta_title' => clip($pdo, 'categories', 'meta_title', $name),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ($langs as $lang) {
        saveTranslation($pdo, 'category_translations', 'category_id', $id, $lang, [
            'name' => clip($pdo, 'category_translations', 'name', $name),
        ]);
    }
    $map[$key] = $id;
    note('Category created: ' . $name);
    return $id;
}

function loadCategoryMap(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT id, name FROM categories') as $row) {
        $key = normKey((string) $row['name']);
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = (int) $row['id'];
        }
    }
    return $map;
}

function loadNameMap(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT id, name FROM products') as $row) {
        $key = normKey((string) $row['name']);
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = (int) $row['id'];
        }
    }
    return $map;
}

function linkForUlti(PDO $pdo, int $ultiId): ?array
{
    $st = $pdo->prepare('SELECT * FROM ultitech_links WHERE ultitech_product_id = ? LIMIT 1');
    $st->execute([$ultiId]);
    $row = $st->fetch();
    return $row ?: null;
}

function websiteTaken(PDO $pdo, int $websiteId, int $ultiId): bool
{
    $st = $pdo->prepare('SELECT ultitech_product_id FROM ultitech_links WHERE website_product_id = ? LIMIT 1');
    $st->execute([$websiteId]);
    $other = (int) $st->fetchColumn();
    return $other > 0 && $other !== $ultiId;
}

function findByBarcode(PDO $pdo, string $sku): int
{
    if ($sku === '') {
        return 0;
    }
    $cols = tableColumns($pdo, 'products');
    if (isset($cols['barcode'])) {
        $st = $pdo->prepare('SELECT id FROM products WHERE barcode = ? LIMIT 1');
        $st->execute([$sku]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }
    if (tableReady($pdo, 'product_stocks') && isset(tableColumns($pdo, 'product_stocks')['sku'])) {
        $st = $pdo->prepare('SELECT product_id FROM product_stocks WHERE sku = ? LIMIT 1');
        $st->execute([$sku]);
        return (int) $st->fetchColumn();
    }
    return 0;
}

function uniqueSlug(PDO $pdo, string $base, int $exceptId = 0): string
{
    $slug = $base;
    $n = 2;
    while (true) {
        $sql = 'SELECT id FROM products WHERE slug = ?';
        $params = [$slug];
        if ($exceptId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $st = $pdo->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if (!(int) $st->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $n;
        $n++;
        if ($n > 40) {
            return $base . '-ut-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
    }
}

function attachCategory(PDO $pdo, int $productId, int $categoryId): void
{
    if ($categoryId <= 0 || !tableReady($pdo, 'product_categories')) {
        return;
    }
    $cols = tableColumns($pdo, 'product_categories');
    if (!isset($cols['product_id'], $cols['category_id'])) {
        return;
    }
    $pdo->prepare('DELETE FROM product_categories WHERE product_id = ?')->execute([$productId]);
    insertRow($pdo, 'product_categories', [
        'product_id' => $productId,
        'category_id' => $categoryId,
    ]);
}

function upsertStock(PDO $pdo, int $productId, string $sku, float $price, float $qty): void
{
    if (!tableReady($pdo, 'product_stocks')) {
        return;
    }
    $cols = tableColumns($pdo, 'product_stocks');
    $where = 'product_id = ?';
    $params = [$productId];
    if (isset($cols['variant'])) {
        $where .= " AND (variant = '' OR variant IS NULL)";
    }
    $st = $pdo->prepare('SELECT id FROM product_stocks WHERE ' . $where . ' LIMIT 1');
    $st->execute($params);
    $id = (int) $st->fetchColumn();
    $stockSku = $sku !== '' ? $sku : ('UT' . $productId);
    $data = [
        'product_id' => $productId,
        'variant' => '',
        'sku' => clip($pdo, 'product_stocks', 'sku', $stockSku),
        'price' => $price,
        'qty' => $qty,
        'image' => null,
    ];
    if ($id > 0) {
        updateRow($pdo, 'product_stocks', $data, 'id', $id);
        return;
    }
    try {
        insertRow($pdo, 'product_stocks', $data);
    } catch (Throwable $e) {
        $data['sku'] = clip($pdo, 'product_stocks', 'sku', 'UT-' . $productId);
        insertRow($pdo, 'product_stocks', $data);
    }
}

function saveUpload(PDO $pdo, int $adminId, string $relative, string $original, int $bytes, string $ext): int
{
    if (!tableReady($pdo, 'uploads')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT id FROM uploads WHERE file_name = ? LIMIT 1');
    $st->execute([$relative]);
    $id = (int) $st->fetchColumn();
    $data = [
        'file_original_name' => clip($pdo, 'uploads', 'file_original_name', $original),
        'file_name' => $relative,
        'user_id' => $adminId,
        'file_size' => $bytes,
        'extension' => $ext,
        'type' => 'image',
    ];
    if ($id > 0) {
        updateRow($pdo, 'uploads', $data, 'id', $id);
        return $id;
    }
    return insertRow($pdo, 'uploads', $data);
}

function productImage(PDO $pdo, int $adminId, int $ultiId, string $url, string $publicDir): int
{
    $url = trim($url);
    if ($url === '' || !function_exists('curl_init')) {
        return 0;
    }
    if (!is_dir($publicDir) && !@mkdir($publicDir, 0755, true)) {
        return 0;
    }
    $tmp = $publicDir . '/ut-' . $ultiId . '.download';
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        return 0;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code !== 200 || !str_starts_with(strtolower($type), 'image/')) {
        @unlink($tmp);
        return 0;
    }
    $ext = 'jpg';
    if (str_contains($type, 'png')) {
        $ext = 'png';
    } elseif (str_contains($type, 'webp')) {
        $ext = 'webp';
    } elseif (str_contains($type, 'gif')) {
        $ext = 'gif';
    }
    $filename = 'ut-' . $ultiId . '.' . $ext;
    $dest = $publicDir . '/' . $filename;
    @unlink($dest);
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return 0;
    }
    return saveUpload($pdo, $adminId, 'uploads/all/' . $filename, $filename, (int) filesize($dest), $ext);
}

function rememberLink(PDO $pdo, int $ultiId, int $websiteId, string $sku, string $imageUrl): void
{
    $st = $pdo->prepare('SELECT id FROM ultitech_links WHERE ultitech_product_id = ? LIMIT 1');
    $st->execute([$ultiId]);
    $id = (int) $st->fetchColumn();
    $data = [
        'ultitech_product_id' => $ultiId,
        'website_product_id' => $websiteId,
        'sku' => $sku,
        'image_url' => $imageUrl,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if ($id > 0) {
        updateRow($pdo, 'ultitech_links', $data, 'id', $id);
        return;
    }
    $pdo->prepare('DELETE FROM ultitech_links WHERE website_product_id = ?')->execute([$websiteId]);
    insertRow($pdo, 'ultitech_links', $data);
}

function syncProduct(PDO $pdo, array $product, int $categoryId, int $adminId, array $langs, array &$names, string $publicDir): string
{
    $ultiId = (int) ($product['id'] ?? 0);
    $name = trim((string) ($product['name'] ?? ''));
    if ($ultiId <= 0 || $name === '') {
        return 'skip';
    }
    $sku = trim((string) ($product['sku'] ?? ''));
    $price = (float) ($product['price'] ?? 0);
    $qty = max(0, (float) ($product['stock_qty'] ?? 0));
    $description = htmlText((string) ($product['description'] ?? ''));
    $imageUrl = trim((string) ($product['image_url'] ?? ''));
    $now = date('Y-m-d H:i:s');

    $link = linkForUlti($pdo, $ultiId);
    $websiteId = $link ? (int) $link['website_product_id'] : 0;
    if ($websiteId <= 0 && $sku !== '') {
        $found = findByBarcode($pdo, $sku);
        if ($found > 0 && !websiteTaken($pdo, $found, $ultiId)) {
            $websiteId = $found;
        }
    }
    if ($websiteId <= 0) {
        $key = normKey($name);
        $found = (int) ($names[$key] ?? 0);
        if ($found > 0 && !websiteTaken($pdo, $found, $ultiId)) {
            $websiteId = $found;
        }
    }

    $uploadId = 0;
    $previousImage = $link['image_url'] ?? '';
    if ($imageUrl !== '' && $imageUrl !== (string) $previousImage) {
        $uploadId = productImage($pdo, $adminId, $ultiId, $imageUrl, $publicDir);
    }

    $shared = [
        'name' => clip($pdo, 'products', 'name', $name),
        'description' => $description,
        'unit_price' => $price,
        'current_stock' => $qty,
        'discount' => 0,
        'discount_type' => 'amount',
        'published' => 1,
        'approved' => 1,
        'barcode' => clip($pdo, 'products', 'barcode', $sku),
        'meta_title' => clip($pdo, 'products', 'meta_title', $name),
        'updated_at' => $now,
    ];
    if ($categoryId > 0) {
        $shared['category_id'] = $categoryId;
    }
    if ($uploadId > 0) {
        $shared['thumbnail_img'] = (string) $uploadId;
        $shared['photos'] = (string) $uploadId;
        $shared['meta_img'] = (string) $uploadId;
    }

    $created = false;
    if ($websiteId > 0) {
        $exists = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $exists->execute([$websiteId]);
        if (!(int) $exists->fetchColumn()) {
            $websiteId = 0;
        }
    }
    if ($websiteId > 0) {
        updateRow($pdo, 'products', $shared, 'id', $websiteId);
    } else {
        $created = true;
        $slug = uniqueSlug($pdo, slugify($name));
        $websiteId = insertRow($pdo, 'products', $shared + [
            'added_by' => 'admin',
            'user_id' => $adminId,
            'slug' => clip($pdo, 'products', 'slug', $slug),
            'purchase_price' => 0,
            'variant_product' => 0,
            'attributes' => '[]',
            'choice_options' => '[]',
            'colors' => '[]',
            'todays_deal' => 0,
            'published' => 1,
            'approved' => 1,
            'stock_visibility_state' => 'quantity',
            'cash_on_delivery' => 1,
            'featured' => 0,
            'seller_featured' => 0,
            'unit' => 'pc',
            'min_qty' => 1,
            'low_stock_quantity' => 1,
            'tax' => 0,
            'tax_type' => 'amount',
            'shipping_type' => 'free',
            'shipping_cost' => 0,
            'is_quantity_multiplied' => 0,
            'num_of_sale' => 0,
            'rating' => 0,
            'digital' => 0,
            'auction_product' => 0,
            'wholesale_product' => 0,
            'refundable' => 0,
            'created_at' => $now,
        ]);
        $names[normKey($name)] = $websiteId;
    }

    foreach ($langs as $lang) {
        saveTranslation($pdo, 'product_translations', 'product_id', $websiteId, $lang, [
            'name' => clip($pdo, 'product_translations', 'name', $name),
            'description' => $description,
            'unit' => 'pc',
        ]);
    }
    attachCategory($pdo, $websiteId, $categoryId);
    upsertStock($pdo, $websiteId, $sku, $price, $qty);
    rememberLink($pdo, $ultiId, $websiteId, $sku, $uploadId > 0 ? $imageUrl : (string) $previousImage);
    return $created ? 'created' : 'updated';
}

function orderMarker(): int
{
    $file = cacheDir() . '/orders-after-id.txt';
    if (is_file($file)) {
        return (int) trim((string) file_get_contents($file));
    }
    return -1;
}

function rememberOrderMarker(PDO $pdo): void
{
    $file = cacheDir() . '/orders-after-id.txt';
    if (is_file($file)) {
        return;
    }
    if (!tableReady($pdo, 'orders')) {
        file_put_contents($file, '0');
        return;
    }
    $max = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM orders')->fetchColumn();
    file_put_contents($file, (string) $max);
    note('Website orders already placed (up to #' . $max . ') stay on the shop. Orders placed after this connection are sent to UltiTech.');
}

function customerFromOrder(PDO $pdo, array $order): array
{
    $addr = json_decode((string) ($order['shipping_address'] ?? ''), true);
    if (!is_array($addr)) {
        $addr = [];
    }
    $name = trim((string) ($addr['name'] ?? ''));
    $email = trim((string) ($addr['email'] ?? ''));
    $phone = trim((string) ($addr['phone'] ?? ''));
    $userId = (int) ($order['user_id'] ?? 0);
    if ($userId > 0 && tableReady($pdo, 'users') && ($name === '' || $email === '' || $phone === '')) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $user = $st->fetch() ?: [];
        if ($name === '') {
            $name = trim((string) ($user['name'] ?? ''));
        }
        if ($email === '') {
            $email = trim((string) ($user['email'] ?? ''));
        }
        if ($phone === '') {
            $phone = trim((string) ($user['phone'] ?? ''));
        }
    }
    if ($name === '') {
        $name = 'Website customer';
    }
    return [$name, $email, $phone];
}

function saveOrderResult(PDO $pdo, int $websiteOrderId, array $data): void
{
    $st = $pdo->prepare('SELECT id FROM ultitech_orders WHERE website_order_id = ? LIMIT 1');
    $st->execute([$websiteOrderId]);
    $id = (int) $st->fetchColumn();
    $data['website_order_id'] = $websiteOrderId;
    if ($id > 0) {
        updateRow($pdo, 'ultitech_orders', $data, 'id', $id);
        return;
    }
    insertRow($pdo, 'ultitech_orders', $data);
}

function pushOrders(PDO $pdo): void
{
    if (!tableReady($pdo, 'orders') || !tableReady($pdo, 'order_details')) {
        note('This database has no shop orders table. Products were saved, but order sending needs the website shop database.');
        return;
    }
    $after = orderMarker();
    if ($after < 0) {
        rememberOrderMarker($pdo);
        $after = orderMarker();
    }
    $detailCols = tableColumns($pdo, 'order_details');
    $qtyCol = isset($detailCols['quantity']) ? 'quantity' : '';
    $priceCol = isset($detailCols['price']) ? 'price' : '';
    if ($qtyCol === '' || !isset($detailCols['product_id'])) {
        note('Shop order lines are missing quantity or product id, so orders were not sent.');
        return;
    }
    $st = $pdo->prepare('SELECT o.* FROM orders o
        LEFT JOIN ultitech_orders u ON u.website_order_id = o.id
        WHERE o.id > ? AND (u.id IS NULL OR u.status = \'error\')
        ORDER BY o.id ASC LIMIT 20');
    $st->execute([$after]);
    $orders = $st->fetchAll();
    if ($orders === []) {
        note('No new website orders to send.');
        return;
    }
    foreach ($orders as $order) {
        $websiteOrderId = (int) $order['id'];
        $status = strtolower(trim((string) ($order['delivery_status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            saveOrderResult($pdo, $websiteOrderId, [
                'status' => 'skipped',
                'message' => 'Cancelled on the website.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            continue;
        }
        $lines = $pdo->prepare('SELECT * FROM order_details WHERE order_id = ?');
        $lines->execute([$websiteOrderId]);
        $items = [];
        foreach ($lines->fetchAll() as $line) {
            $webProduct = (int) ($line['product_id'] ?? 0);
            $map = $pdo->prepare('SELECT ultitech_product_id FROM ultitech_links WHERE website_product_id = ? LIMIT 1');
            $map->execute([$webProduct]);
            $ultiProduct = (int) $map->fetchColumn();
            $qty = (float) ($line[$qtyCol] ?? 0);
            if ($ultiProduct <= 0 || $qty <= 0) {
                continue;
            }
            $item = [
                'product_id' => $ultiProduct,
                'quantity' => $qty,
            ];
            if ($priceCol !== '') {
                $item['unit_price'] = (float) $line[$priceCol];
            }
            $items[] = $item;
        }
        if ($items === []) {
            saveOrderResult($pdo, $websiteOrderId, [
                'status' => 'skipped',
                'message' => 'No UltiTech products on this order.',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            note('Order #' . $websiteOrderId . ' has no linked UltiTech products.');
            continue;
        }
        [$name, $email, $phone] = customerFromOrder($pdo, $order);
        $code = trim((string) ($order['code'] ?? ''));
        $payload = [
            'idempotency_key' => 'ugt-web-' . $websiteOrderId,
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'notes' => 'Order from ultimate.co.tz' . ($code !== '' ? ' ' . $code : ' #' . $websiteOrderId),
            'items' => $items,
        ];
        $ch = curl_init(ULTITECH_ORDER_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . ULTITECH_API_TOKEN,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        $ok = is_array($json) && !empty($json['success']);
        saveOrderResult($pdo, $websiteOrderId, [
            'ultitech_order_id' => $ok ? (int) ($json['order_id'] ?? 0) : null,
            'ultitech_order_number' => $ok ? (string) ($json['order_number'] ?? '') : null,
            'status' => $ok ? 'sent' : 'error',
            'message' => $ok ? (string) ($json['message'] ?? 'Sent') : (string) (is_array($json) ? ($json['message'] ?? $json['error'] ?? $body) : $body),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($ok) {
            note('Order #' . $websiteOrderId . ' sent to UltiTech as ' . (string) ($json['order_number'] ?? ''));
        } else {
            note('Order #' . $websiteOrderId . ' was not accepted: ' . (string) (is_array($json) ? ($json['message'] ?? $json['error'] ?? '') : ''));
        }
    }
}

function statsPath(): string
{
    return cacheDir() . '/stats.json';
}

function loadStats(): array
{
    $raw = is_file(statsPath()) ? file_get_contents(statsPath()) : '';
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    return $data + ['created' => 0, 'updated' => 0, 'images' => 0, 'errors' => []];
}

function saveStats(array $stats): void
{
    file_put_contents(statsPath(), json_encode($stats, JSON_UNESCAPED_UNICODE));
}

function runBatch(PDO $pdo, array $catalog, int $offset): array
{
    $products = $catalog['products'] ?? [];
    if (!is_array($products)) {
        $products = [];
    }
    $total = count($products);
    $adminId = adminUserId($pdo);
    $langs = languageCodes($pdo);
    $categories = loadCategoryMap($pdo);
    $names = loadNameMap($pdo);
    $root = laravelRoot();
    $publicDir = $root . '/public/uploads/all';
    $stats = $offset === 0 ? ['created' => 0, 'updated' => 0, 'images' => 0, 'errors' => []] : loadStats();
    if ($offset === 0) {
        rememberOrderMarker($pdo);
    }
    $slice = array_slice($products, $offset, BATCH_SIZE);
    foreach ($slice as $product) {
        if (!is_array($product)) {
            continue;
        }
        try {
            $categoryName = trim((string) ($product['category'] ?? ''));
            $before = count($categories);
            $categoryId = categoryId($pdo, $categoryName, $categories, $adminId, $langs);
            if ($categoryId > 0 && count($categories) > $before) {
                // counted via note()
            }
            $result = syncProduct($pdo, $product, $categoryId, $adminId, $langs, $names, $publicDir);
            if ($result === 'created') {
                $stats['created']++;
            } elseif ($result === 'updated') {
                $stats['updated']++;
            }
        } catch (Throwable $e) {
            $label = trim((string) ($product['name'] ?? 'product'));
            $stats['errors'][] = $label . ': ' . $e->getMessage();
            note('Failed ' . $label . ': ' . $e->getMessage());
        }
    }
    $stats['errors'] = array_slice($stats['errors'], -30);
    saveStats($stats);
    $next = $offset + count($slice);
    return [
        'total' => $total,
        'next' => $next,
        'done' => $next >= $total,
        'stats' => $stats,
    ];
}

try {
    if (!tableReady($probe = shopPdo(), 'products') || !tableReady($probe, 'categories')) {
        throw new RuntimeException('Connected to the database, but it is not the shop catalog (products and categories tables were not found).');
    }
    $pdo = $probe;
    ensureLinkTables($pdo);
    $quoteFile = __DIR__ . '/quote.php';
    if (is_file($quoteFile)) {
        require_once $quoteFile;
        if (function_exists('ultitechInstallQuoteButton')) {
            note(ultitechInstallQuoteButton($pdo));
        }
    }
    if (!$isCli && strtolower((string) ($_GET['format'] ?? '')) === 'status') {
        $ids = array_map('intval', $pdo->query('SELECT ultitech_product_id FROM ultitech_links')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'linked_ids' => $ids,
            'count' => count($ids),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $catalog = fetchCatalog();
    $count = count($catalog['products'] ?? []);
    note('UltiTech returned ' . $count . ' Ultimate products.');

    if ($isCli) {
        $offset = 0;
        $last = null;
        do {
            $last = runBatch($pdo, $catalog, $offset);
            $offset = (int) $last['next'];
            note('Saved ' . $offset . ' of ' . $last['total'] . '.');
        } while (empty($last['done']));
        pushOrders($pdo);
        note('Finished. Created ' . $last['stats']['created'] . ', updated ' . $last['stats']['updated'] . '.');
        exit(empty($last['stats']['errors']) ? 0 : 1);
    }

    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $phase = (string) ($_GET['phase'] ?? 'products');
    if ($phase === 'orders') {
        pushOrders($pdo);
        $stats = loadStats();
        $done = true;
        $nextUrl = '';
    } else {
        $batch = runBatch($pdo, $catalog, $offset);
        $stats = $batch['stats'];
        $done = !empty($batch['done']);
        $nextUrl = $done
            ? ('?key=' . rawurlencode(SYNC_WEB_KEY) . '&phase=orders')
            : ('?key=' . rawurlencode(SYNC_WEB_KEY) . '&offset=' . (int) $batch['next']);
        note('Saved ' . min($count, (int) $batch['next']) . ' of ' . $count . ' products.');
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:Georgia,serif;max-width:720px;margin:40px auto;padding:0 16px">';
    echo '<h1>Connection stopped</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$self = htmlspecialchars((string) ($_SERVER['PHP_SELF'] ?? 'sync.php'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($nextUrl !== ''): ?>
<meta http-equiv="refresh" content="1;url=<?= $self . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<title>UltiTech connection</title>
<style>
  body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: #f4f1ea; color: #1c1915; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 20px 64px; }
  .card { background: #fff; border: 1px solid #e2d9cc; border-radius: 12px; padding: 16px 18px; }
  li { line-height: 1.45; }
</style>
</head>
<body>
<main>
  <h1><?= $done && $phase === 'orders' ? 'Ultimate products are on the website' : 'Connecting UltiTech to ultimate.co.tz' ?></h1>
  <div class="card">
    <ul>
      <?php foreach ($log as $line): ?>
        <li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
      <li>Created <?= (int) ($stats['created'] ?? 0) ?>, updated <?= (int) ($stats['updated'] ?? 0) ?>.</li>
    </ul>
    <?php if (!empty($stats['errors'])): ?>
      <p><strong>Needs a look</strong></p>
      <ul>
        <?php foreach ($stats['errors'] as $err): ?>
          <li><?= htmlspecialchars((string) $err, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($done && $phase === 'orders'): ?>
      <p>Active Ultimate products now use the shop�s normal pages. A customer can open a product and place an order when its UltiTech stock is above zero. Orders placed from now on are sent to UltiTech the next time this file runs.</p>
      <p>In cPanel ? Cron Jobs, run this every 15 minutes so prices and stock stay current:</p>
      <p><code>php <?= htmlspecialchars(str_replace('\\', '/', __FILE__), ENT_QUOTES, 'UTF-8') ?></code></p>
      <p><a href="https://ultimate.co.tz/search">Open the shop catalog</a></p>
    <?php else: ?>
      <p>This page continues on its own until every product is saved.</p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
