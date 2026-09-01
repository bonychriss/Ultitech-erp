<?php
/**
 * Fetch product images from roadmaster.enerpize.com and assign to local Roadmaster stock.
 *
 * Usage:
 *   php scripts/fetch-roadmaster-enerpize-images.php --dry-run
 *   php scripts/fetch-roadmaster-enerpize-images.php --force --spares-only
 *   php scripts/fetch-roadmaster-enerpize-images.php --force --product-id=22
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['dry-run', 'limit:', 'product-id:', 'force', 'spares-only', 'all', 'trucks-only', 'min-score:']);
$dryRun = array_key_exists('dry-run', $opts);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
$onlyProductId = isset($opts['product-id']) ? (int) $opts['product-id'] : 0;
$force = array_key_exists('force', $opts);
$sparesOnly = array_key_exists('spares-only', $opts) || (!array_key_exists('all', $opts) && !array_key_exists('trucks-only', $opts));
$trucksOnly = array_key_exists('trucks-only', $opts);
$allTypes = array_key_exists('all', $opts);
$minScore = isset($opts['min-score']) ? max(50, (int) $opts['min-score']) : 72;

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/../stock/classes/ImageProcessor.php';

const RM_ENERPIZE_BASE = 'https://roadmaster.enerpize.com';

function rm_en_is_test_product(string $name, string $code): bool
{
    if (stripos($name, '__DUMMY__') === 0) {
        return true;
    }
    if (preg_match('/\b(test|sample|demo)\b/i', $name)) {
        return true;
    }
    if (preg_match('/^test/i', $code)) {
        return true;
    }

    return false;
}

function rm_en_is_truck(array $product): bool
{
    $code = trim((string) ($product['product_code'] ?? ''));

    return str_starts_with($code, 'TRK-')
        || strtolower((string) ($product['item_type'] ?? '')) === 'vehicle'
        || trim((string) ($product['truck_type'] ?? '')) !== '';
}

function rm_en_should_process(array $product, bool $sparesOnly, bool $trucksOnly, bool $allTypes): bool
{
    $isTruck = rm_en_is_truck($product);
    if ($allTypes) {
        return true;
    }
    if ($trucksOnly) {
        return $isTruck;
    }
    if ($sparesOnly) {
        return !$isTruck;
    }

    return true;
}

function rm_en_normalize_name(string $name): string
{
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = strtolower($name);
    $name = preg_replace('/#\d+/', '', $name) ?? $name;
    $name = str_replace(['disel', 'automative'], ['diesel', 'automotive'], $name);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);

    return $name;
}

function rm_en_name_tokens(string $normalized): array
{
    $stop = ['and', 'with', 'the', 'for', 'of', 'assembly', 'lh', 'rh', 'left', 'right', 'main', 'heavy', 'duty'];
    $parts = explode(' ', $normalized);
    $tokens = [];
    foreach ($parts as $part) {
        if ($part === '' || strlen($part) < 2 || in_array($part, $stop, true)) {
            continue;
        }
        $tokens[] = $part;
    }

    return array_values(array_unique($tokens));
}

/** @return array<string, string> local normalized name => energize catalog name */
function rm_en_alias_map(): array
{
    return [
        'oil filter' => 'Oil filter',
        'diesel filter' => 'Fuel filter',
        'disel filter' => 'Fuel filter',
        'fuel filter' => 'Fuel filter',
        'rotary diesel filter assembly filter element x0710' => 'Fuel filter',
        'air cleaner' => 'Cabin air filter',
        'water separator' => 'Fuel filter',
        'cylinder head gasket' => 'Engine Gasket Set',
        'brake drum' => 'Brake drum',
        'rear brake drum' => 'Brake drum',
        'axle brake shoe with friction plate assembly' => 'Brake Pad',
        'oil pressure sensor' => 'Temperature sensor',
        'oil ring piston' => 'Piston Rings',
        'supercharge' => 'Throttle Body',
        'injection pump' => 'Fuel Rail',
        'clutch cover and pressure plate assembly' => 'Pressure plate',
        'main filter element assembly' => 'Oil filter',
        'expansion tank pressure cover assembly' => 'Coolant reservoir',
        'expansion tank and pressure cover assembly' => 'Coolant reservoir',
        'expansion tank assembly plastic' => 'Coolant reservoir',
        'power steering mechanismassembly' => 'Steering pump',
        'power steering mechanism assembly' => 'Steering pump',
        'brake chamber assembly right' => 'Brake booster',
        'air compressor pump assembly' => 'Belt tensioner',
        'right steering tie rod arm' => 'Steering rod',
        'front leaf spring' => 'leaf spring',
        'fan backing block' => 'Fan blade',
        'rim cover' => 'wheel hub',
        'yunnan white radiator mask' => 'Radiator',
        'front bumper mask assembly primer' => 'Front bumper',
        'center bumper assembly' => 'Front bumper',
        'right bumper welding assembly' => 'Front bumper',
        'towing hook cover bumper' => 'Front bumper',
        'valve' => 'EGR valve',
        'diesel engine oil' => 'Oil cooler',
        'heavy duty automotive gear oil' => 'Oil cooler',
        'left fender assembly' => 'Mudguards',
        'right fender assembly' => 'Mudguards',
        'side trim cover right top cover' => 'Side mirror',
        'side trim cover left top cover' => 'Side mirror',
        'front out panel lh' => 'Grill',
        'front out panel rh' => 'Grill',
        'front wall left deflector assembly' => 'Grill',
        'front wall right deflector assembly' => 'Grill',
        'front wall left trim panel' => 'Grill',
        'front wall right trim panel' => 'Grill',
        'left door outer trim assembly' => 'Door handle',
        'right door outer trim assembly' => 'Door handle',
        'right front pillar outer trim panel' => 'Door hinges',
        'left front pillar outer trim panel' => 'Door hinges',
        'headlight upper right bracket b assembly' => 'Headlight assembly',
        'left level 1 and stage ii foot pedal shield' => 'Cabin mount',
        'left pedal upper baffle' => 'Cabin mount',
        'right pedal upper baffle' => 'Cabin mount',
    ];
}

function rm_en_fetch_url(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'UltiTech-ERP-EnerpizeImport/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body || $code < 200 || $code >= 400) {
        return null;
    }

    return (string) $body;
}

function rm_en_discover_list_pages(string $html): array
{
    $pages = [1];
    if (preg_match_all('/products_list\/page:(\d+)/', $html, $m)) {
        foreach ($m[1] as $page) {
            $pages[] = max(1, (int) $page);
        }
    }

    return array_values(array_unique($pages));
}

/** @return array<int, array{name:string,image:string,normalized:string,tokens:array<int,string>}> */
function rm_en_parse_catalog_html(string $html): array
{
    $catalog = [];
    if (!preg_match_all(
        '/data-image="([^"]+)"[^>]*data-name="([^"]+)"/i',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        return $catalog;
    }

    foreach ($matches as $match) {
        $image = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($name === '' || $image === '') {
            continue;
        }
        $normalized = rm_en_normalize_name($name);
        if ($normalized === '') {
            continue;
        }
        if (!isset($catalog[$normalized])) {
            $catalog[$normalized] = [
                'name' => $name,
                'image' => $image,
                'normalized' => $normalized,
                'tokens' => rm_en_name_tokens($normalized),
            ];
        }
    }

    return $catalog;
}

/** @return array<int, array{name:string,image:string,normalized:string,tokens:array<int,string>}> */
function rm_en_load_catalog(): array
{
    $urls = [
        RM_ENERPIZE_BASE . '/home',
        RM_ENERPIZE_BASE . '/contents/products_list',
    ];

    $html = '';
    foreach ($urls as $url) {
        $chunk = rm_en_fetch_url($url);
        if ($chunk !== null) {
            $html .= "\n" . $chunk;
        }
    }

    if ($html === '') {
        throw new RuntimeException('Could not fetch Enerpize catalog pages.');
    }

    $pages = rm_en_discover_list_pages($html);
    foreach ($pages as $page) {
        if ($page <= 1) {
            continue;
        }
        $chunk = rm_en_fetch_url(RM_ENERPIZE_BASE . '/contents/products_list/page:' . $page);
        if ($chunk !== null) {
            $html .= "\n" . $chunk;
        }
    }

    return rm_en_parse_catalog_html($html);
}

function rm_en_token_overlap_score(array $a, array $b): float
{
    if ($a === [] || $b === []) {
        return 0.0;
    }
    $shared = array_intersect($a, $b);
    $denom = max(count($a), count($b));

    return (count($shared) / $denom) * 100.0;
}

/**
 * @param array<int, array{name:string,image:string,normalized:string,tokens:array<int,string>}> $catalog
 * @return array{score:float,match:array{name:string,image:string,normalized:string,tokens:array<int,string>},method:string}|null
 */
function rm_en_find_match(string $localName, array $catalog, int $minScore): ?array
{
    $normalized = rm_en_normalize_name($localName);
    $aliases = rm_en_alias_map();

    if (isset($aliases[$normalized])) {
        $target = rm_en_normalize_name($aliases[$normalized]);
        if (isset($catalog[$target])) {
            return ['score' => 100.0, 'match' => $catalog[$target], 'method' => 'alias'];
        }
    }

    if (isset($catalog[$normalized])) {
        return ['score' => 100.0, 'match' => $catalog[$normalized], 'method' => 'exact'];
    }

    $tokens = rm_en_name_tokens($normalized);
    $best = null;
    $bestScore = 0.0;
    $bestMethod = 'fuzzy';

    foreach ($catalog as $entry) {
        similar_text($normalized, $entry['normalized'], $pct);
        $tokenScore = rm_en_token_overlap_score($tokens, $entry['tokens']);
        $score = max($pct, $tokenScore);

        if (str_contains($entry['normalized'], $normalized) || str_contains($normalized, $entry['normalized'])) {
            $score = max($score, 85.0);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $entry;
            $bestMethod = $tokenScore >= $pct ? 'tokens' : 'fuzzy';
        }
    }

    if ($best === null || $bestScore < $minScore) {
        return null;
    }

    return ['score' => $bestScore, 'match' => $best, 'method' => $bestMethod];
}

function rm_en_product_has_image(PDO $pdo, int $productId, string $mainImage): bool
{
    $ctx = stock_image_company_context();
    $slug = (string) ($ctx['slug'] ?? 'roadmaster');
    $companyId = (int) ($ctx['company_id'] ?? 2);

    if ($mainImage !== '') {
        $disk = stock_resolve_product_image_file($productId, 'thumbnail', $mainImage, $slug, $companyId);
        if ($disk !== null && is_file($disk)) {
            return true;
        }
    }

    try {
        $st = $pdo->prepare('SELECT image_name FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
        $st->execute([$productId]);
        $gallery = trim((string) $st->fetchColumn());
        if ($gallery !== '') {
            $disk = stock_resolve_product_image_file($productId, 'thumbnail', $gallery, $slug, $companyId);
            if ($disk !== null && is_file($disk)) {
                return true;
            }
        }
    } catch (Throwable $e) {
    }

    return false;
}

function rm_en_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rm_en_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function rm_en_clear_product_images(PDO $pdo, string $baseDir, int $productId): void
{
    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE products SET main_image = NULL WHERE id = ?')->execute([$productId]);
    rm_en_rrmdir(rtrim($baseDir, '/\\') . '/products/' . $productId);
}

function rm_en_download_file(string $url, string $dest): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $fh = fopen($dest, 'wb');
    if ($fh === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 25,
        CURLOPT_USERAGENT => 'UltiTech-ERP-EnerpizeImport/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $code < 200 || $code >= 400) {
        @unlink($dest);

        return false;
    }

    $info = @getimagesize($dest);

    return is_array($info) && ($info[0] ?? 0) > 80 && ($info[1] ?? 0) > 80;
}

function rm_en_assign_product_image(PDO $pdo, ImageProcessor $processor, int $productId, string $sourcePath): string
{
    $filename = $processor->processUploadedImage($sourcePath, $productId);

    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare(
        'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, 1, NULL)'
    )->execute([$productId, $filename]);
    $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$filename, $productId]);

    return $filename;
}

$scope = $allTypes ? 'all products' : ($trucksOnly ? 'trucks only' : 'spares only');
echo 'Roadmaster Enerpize image import [' . $scope . ']' . ($dryRun ? ' [DRY RUN]' : '') . ($force ? ' [FORCE]' : '') . PHP_EOL;

$catalog = rm_en_load_catalog();
echo 'enerpize_catalog=' . count($catalog) . ' products' . PHP_EOL;
echo 'tenant_db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$ctx = stock_image_company_context();
$baseDir = stock_product_upload_base_dir((int) ($ctx['company_id'] ?? 2), (string) ($ctx['slug'] ?? 'roadmaster'));
$processor = new ImageProcessor($baseDir);
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ultitech-rm-enerpize';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$sql = 'SELECT id, product_code, name, main_image, item_type, truck_type FROM products ORDER BY id';
if ($onlyProductId > 0) {
    $sql = 'SELECT id, product_code, name, main_image, item_type, truck_type FROM products WHERE id = ' . $onlyProductId;
}
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$processed = 0;
$skipped = 0;
$failed = 0;
$unmatched = [];

foreach ($rows as $row) {
    if ($limit > 0 && $processed >= $limit) {
        break;
    }

    $productId = (int) ($row['id'] ?? 0);
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['product_code'] ?? ''));

    if ($productId < 1 || rm_en_is_test_product($name, $code)) {
        $skipped++;
        continue;
    }
    if (!rm_en_should_process($row, $sparesOnly, $trucksOnly, $allTypes)) {
        $skipped++;
        continue;
    }

    $mainImage = trim((string) ($row['main_image'] ?? ''));
    if (!$force && rm_en_product_has_image($pdo, $productId, $mainImage)) {
        echo "SKIP has image #{$productId} {$code} {$name}\n";
        $skipped++;
        continue;
    }

    $found = rm_en_find_match($name, $catalog, $minScore);
    if ($found === null) {
        echo "NO MATCH #{$productId} {$code} | {$name}\n";
        $unmatched[] = $name;
        $failed++;
        continue;
    }

    $match = $found['match'];
    echo "MATCH #{$productId} {$code}\n";
    echo '  local: ' . $name . PHP_EOL;
    echo '  energize: ' . $match['name'] . ' [' . $found['method'] . ' score=' . round($found['score'], 1) . ']' . PHP_EOL;

    if ($dryRun) {
        $processed++;
        continue;
    }

    if ($force) {
        rm_en_clear_product_images($pdo, $baseDir, $productId);
    }

    $ext = str_contains(strtolower($match['image']), '.png') ? 'png' : 'jpg';
    $tmpPath = $cacheDir . DIRECTORY_SEPARATOR . 'p' . $productId . '.' . $ext;
    @unlink($tmpPath);

    if (!rm_en_download_file($match['image'], $tmpPath)) {
        echo "  FAIL download\n";
        $failed++;
        continue;
    }

    try {
        $filename = rm_en_assign_product_image($pdo, $processor, $productId, $tmpPath);
        echo "  OK {$filename}\n";
        $processed++;
    } catch (Throwable $e) {
        echo '  FAIL assign: ' . $e->getMessage() . PHP_EOL;
        $failed++;
    }

    @unlink($tmpPath);
    usleep(200000);
}

echo PHP_EOL . "done processed={$processed} skipped={$skipped} failed={$failed} unmatched=" . count($unmatched) . PHP_EOL;
if ($unmatched !== []) {
    echo "Unmatched local products:\n";
    foreach ($unmatched as $name) {
        echo '  - ' . $name . PHP_EOL;
    }
}
