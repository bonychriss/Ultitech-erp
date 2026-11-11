<?php
/**
 * Builds the product snapshot used by the 3D office showcase.
 *
 * Reads the UltiTech storefront catalogue (same feed the website uses), picks a
 * display set per category, saves square product photos to ../products/ and
 * writes ../products.json. The showcase page never talks to UltiTech directly,
 * so no API token is exposed on the public website.
 *
 * Run from a terminal:
 *   php export-products.php
 *   php export-products.php --base=https://ultitech.io --token=XXXX --per-category=16
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['base::', 'token::', 'company::', 'per-category::', 'size::']);
$base = rtrim((string) ($opts['base'] ?? 'http://localhost/public_html'), '/');
$token = (string) ($opts['token'] ?? (getenv('STOREFRONT_SYNC_TOKEN') ?: 'roadmaster-storefront-dev-token-change-me'));
$company = (string) ($opts['company'] ?? 'ultimate');
$perCategory = max(1, (int) ($opts['per-category'] ?? 16));
$imageSize = max(128, (int) ($opts['size'] ?? 400));

$outDir = dirname(__DIR__);
$imgDir = $outDir . '/products';
$excludedCategories = ['', 'imported revenue'];

function http_get(string $url, string $token = ''): array
{
    $ch = curl_init($url);
    $headers = ['Accept: */*'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$code, $body === false ? '' : (string) $body, $type, $error];
}

function clean_text(string $value): string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Product names were saved after Unicode was lost: "???" was a dash, "?" between sizes was a times sign.
    $value = preg_replace('/\s*\?{3}\s*/u', " \u{2013} ", $value) ?? $value;
    $value = preg_replace('/(\w)\s*\?{1,2}\s*(?=\d)/u', "\$1 \u{00D7} ", $value) ?? $value;
    $value = str_replace('?', '', $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function looks_like_real_name(string $name): bool
{
    if (preg_match('/^(test|sample|demo|dummy)\b/i', $name) === 1) {
        return false;
    }
    return mb_strlen($name) >= 4 && (str_contains($name, ' ') || preg_match('/[A-Z]{4,}/', $name) === 1);
}

/** Collapses size/colour variants ("SAFETY SHOES ... SIZE 44") into one display item. */
function variant_key(string $name): string
{
    $key = mb_strtoupper($name);
    $key = preg_replace('/\b(SIZE|SZ|NO\.?)\s*[\w\/.-]+/u', '', $key) ?? $key;
    $key = preg_replace('/\b(XXXL|XXL|XL|L|M|S|XS)\b/u', '', $key) ?? $key;
    $key = preg_replace('/[\d]+(\.\d+)?\s*(MM|CM|M|KG|L|ML|V|AH)?\b/u', '', $key) ?? $key;
    $key = preg_replace('/[^A-Z]+/u', ' ', $key) ?? $key;
    return trim($key);
}

function save_square_jpeg(string $bytes, string $path, int $size): bool
{
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return false;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($size, $size);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    $scale = min(($size * 0.92) / $w, ($size * 0.92) / $h);
    $dw = (int) round($w * $scale);
    $dh = (int) round($h * $scale);
    imagecopyresampled($dst, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
    $ok = imagejpeg($dst, $path, 82);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

$catalogUrl = $base . '/api/storefront/catalog.php?company_slug=' . rawurlencode($company);
fwrite(STDOUT, "Reading $catalogUrl\n");
[$code, $body, , $error] = http_get($catalogUrl, $token);
$json = json_decode($body, true);
if ($code !== 200 || !is_array($json) || empty($json['success'])) {
    $reason = is_array($json) ? (string) ($json['error'] ?? '') : ($error ?: substr($body, 0, 200));
    fwrite(STDERR, "Catalogue request failed (HTTP $code): $reason\n");
    exit(1);
}

$byCategory = [];
foreach ($json['products'] ?? [] as $row) {
    $category = clean_text((string) ($row['category'] ?? ''));
    $name = clean_text((string) ($row['name'] ?? ''));
    $imageUrl = (string) ($row['image_url'] ?? '');
    if (in_array(mb_strtolower($category), $excludedCategories, true) || $imageUrl === '' || !looks_like_real_name($name)) {
        continue;
    }
    if ((float) ($row['price'] ?? 0) <= 0) {
        continue;
    }
    $row['name'] = $name;
    $row['category'] = $category;
    $byCategory[$category][] = $row;
}

$selected = [];
foreach ($byCategory as $category => $rows) {
    usort($rows, static function (array $a, array $b): int {
        $aStock = (float) ($a['stock_qty'] ?? 0) > 0 ? 1 : 0;
        $bStock = (float) ($b['stock_qty'] ?? 0) > 0 ? 1 : 0;
        $aDemand = (float) ($a['sold_qty'] ?? 0) + (float) ($a['quoted_qty'] ?? 0);
        $bDemand = (float) ($b['sold_qty'] ?? 0) + (float) ($b['quoted_qty'] ?? 0);
        return [$bStock, $bDemand, $a['name']] <=> [$aStock, $aDemand, $b['name']];
    });
    $seenKeys = [];
    $seenImages = [];
    foreach ($rows as $row) {
        $key = variant_key($row['name']);
        parse_str((string) parse_url((string) $row['image_url'], PHP_URL_QUERY), $q);
        $imageKey = (string) ($q['file'] ?? $row['image_url']);
        if (isset($seenKeys[$key]) || isset($seenImages[$imageKey])) {
            continue;
        }
        $seenKeys[$key] = true;
        $seenImages[$imageKey] = true;
        $selected[$category][] = $row;
        if (count($selected[$category]) >= $perCategory) {
            break;
        }
    }
}
uksort($selected, static fn (string $a, string $b): int => count($selected[$b]) <=> count($selected[$a]) ?: strcmp($a, $b));

if (!is_dir($imgDir) && !mkdir($imgDir, 0755, true)) {
    fwrite(STDERR, "Cannot create $imgDir\n");
    exit(1);
}
foreach (glob($imgDir . '/*.jpg') ?: [] as $old) {
    @unlink($old);
}

$products = [];
$categories = [];
foreach ($selected as $category => $rows) {
    $count = 0;
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $parts = parse_url((string) $row['image_url']);
        $localImageUrl = $base . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $localImageUrl = preg_replace('#(?<!:)//+#', '/', $localImageUrl) ?? $localImageUrl;
        [$imgCode, $bytes, $type] = http_get($localImageUrl);
        if ($imgCode !== 200 || !str_starts_with($type, 'image/') || !save_square_jpeg($bytes, "$imgDir/$id.jpg", $imageSize)) {
            fwrite(STDOUT, "  skip #$id (image HTTP $imgCode)\n");
            continue;
        }
        $description = clean_text(strip_tags((string) ($row['description'] ?? '')));
        if (mb_strlen($description) > 320) {
            $description = rtrim(mb_substr($description, 0, 317)) . '...';
        }
        $products[] = [
            'id' => $id,
            'sku' => clean_text((string) ($row['sku'] ?? '')),
            'name' => $row['name'],
            'category' => $category,
            'brand' => clean_text((string) ($row['brand'] ?? '')),
            'description' => $description,
            'price' => (float) $row['price'],
            'currency' => (string) ($row['currency'] ?? 'TZS'),
            'in_stock' => (float) ($row['stock_qty'] ?? 0) > 0,
            'image' => "products/$id.jpg",
        ];
        $count++;
    }
    if ($count > 0) {
        $categories[] = ['name' => $category, 'count' => $count];
    }
    fwrite(STDOUT, sprintf("  %-28s %d\n", $category, $count));
}

$payload = [
    'company' => $company,
    'generated_at' => date('c'),
    'categories' => $categories,
    'products' => $products,
];
$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded === false) {
    fwrite(STDERR, 'Could not encode products.json: ' . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($outDir . '/products.json', $encoded);
fwrite(STDOUT, sprintf("Wrote %d products in %d categories to products.json\n", count($products), count($categories)));
