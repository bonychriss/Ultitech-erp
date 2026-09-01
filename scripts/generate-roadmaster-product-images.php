<?php
/**
 * Generate name-specific AI product photos for Roadmaster stock items.
 *
 * Usage:
 *   php scripts/generate-roadmaster-product-images.php --dry-run
 *   php scripts/generate-roadmaster-product-images.php --limit=3
 *   php scripts/generate-roadmaster-product-images.php --force --spares-only
 *   php scripts/generate-roadmaster-product-images.php --force --all
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['dry-run', 'limit:', 'product-id:', 'force', 'spares-only', 'all', 'trucks-only']);
$dryRun = array_key_exists('dry-run', $opts);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
$onlyProductId = isset($opts['product-id']) ? (int) $opts['product-id'] : 0;
$force = array_key_exists('force', $opts);
$sparesOnly = array_key_exists('spares-only', $opts) || (!array_key_exists('all', $opts) && !array_key_exists('trucks-only', $opts));
$trucksOnly = array_key_exists('trucks-only', $opts);
$allTypes = array_key_exists('all', $opts);

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/../stock/classes/ImageProcessor.php';

function rm_gen_is_test_product(string $name, string $code): bool
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

function rm_gen_is_truck(array $product): bool
{
    $code = trim((string) ($product['product_code'] ?? ''));

    return str_starts_with($code, 'TRK-')
        || strtolower((string) ($product['item_type'] ?? '')) === 'vehicle'
        || trim((string) ($product['truck_type'] ?? '')) !== '';
}

function rm_gen_should_process(array $product, bool $sparesOnly, bool $trucksOnly, bool $allTypes): bool
{
    $isTruck = rm_gen_is_truck($product);
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

function rm_gen_product_has_image(PDO $pdo, int $productId, string $mainImage): bool
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

function rm_gen_build_prompt(array $product): string
{
    $name = trim((string) ($product['name'] ?? 'Product'));
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $isTruck = rm_gen_is_truck($product);

    if ($isTruck) {
        return "Professional automotive catalog photograph of {$name}, commercial heavy-duty truck, photorealistic, accurate proportions, three-quarter front view, centered, pure white seamless background, bright studio lighting, sharp focus, dealership product photo, no people, no text, no watermark, no logo";
    }

    return "Professional e-commerce product photograph of {$name}, heavy-duty truck spare part, photorealistic, accurate part shape and details matching the name, single component centered, pure white seamless background, bright studio lighting, sharp macro focus, catalog listing photo, no people, no text, no watermark, no packaging box";
}

function rm_gen_rrmdir(string $dir): void
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
            rm_gen_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function rm_gen_clear_product_images(PDO $pdo, string $baseDir, int $productId): void
{
    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare('UPDATE products SET main_image = NULL WHERE id = ?')->execute([$productId]);
    rm_gen_rrmdir(rtrim($baseDir, '/\\') . '/products/' . $productId);
}

function rm_gen_download_file(string $url, string $dest): bool
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
        CURLOPT_USERAGENT => 'UltiTech-ERP-ImageGen/1.0',
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

    return is_array($info) && ($info[0] ?? 0) > 200 && ($info[1] ?? 0) > 200;
}

function rm_gen_fetch_ai_image(string $prompt, int $seed, string $dest): bool
{
    $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
        . '?width=900&height=900&model=flux&nologo=true&enhance=true&seed=' . max(1, $seed);

    return rm_gen_download_file($url, $dest);
}

function rm_gen_composite_white(string $sourcePath, string $destPath, int $size = 900): bool
{
    if (!ImageProcessor::gdAvailable()) {
        return copy($sourcePath, $destPath);
    }

    $info = @getimagesize($sourcePath);
    if ($info === false) {
        return false;
    }
    [$sw, $sh, $type] = $info;

    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            $src = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : null;
            break;
        case IMAGETYPE_GIF:
            $src = imagecreatefromgif($sourcePath);
            break;
        default:
            $src = null;
    }

    if (!$src) {
        return false;
    }

    $padding = 40;
    $max = $size - ($padding * 2);
    $ratio = min($max / $sw, $max / $sh);
    $nw = max(1, (int) round($sw * $ratio));
    $nh = max(1, (int) round($sh * $ratio));

    $canvas = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);

    $dstX = (int) round(($size - $nw) / 2);
    $dstY = (int) round(($size - $nh) / 2);
    imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);

    $ok = imagejpeg($canvas, $destPath, 93);
    imagedestroy($canvas);

    return $ok;
}

function rm_gen_assign_product_image(PDO $pdo, ImageProcessor $processor, int $productId, string $preparedJpeg): string
{
    $filename = $processor->processUploadedImage($preparedJpeg, $productId);

    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    $pdo->prepare(
        'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, 1, NULL)'
    )->execute([$productId, $filename]);
    $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$filename, $productId]);

    return $filename;
}

$ctx = stock_image_company_context();
$baseDir = stock_product_upload_base_dir((int) ($ctx['company_id'] ?? 2), (string) ($ctx['slug'] ?? 'roadmaster'));
$processor = new ImageProcessor($baseDir);
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ultitech-rm-gen';
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

$scope = $allTypes ? 'all products' : ($trucksOnly ? 'trucks only' : 'spares only');
echo 'Roadmaster AI image generation [' . $scope . ']' . ($dryRun ? ' [DRY RUN]' : '') . ($force ? ' [FORCE]' : '') . PHP_EOL;
echo 'tenant_db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

foreach ($rows as $row) {
    if ($limit > 0 && $processed >= $limit) {
        break;
    }

    $productId = (int) ($row['id'] ?? 0);
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['product_code'] ?? ''));

    if ($productId < 1 || rm_gen_is_test_product($name, $code)) {
        $skipped++;
        continue;
    }
    if (!rm_gen_should_process($row, $sparesOnly, $trucksOnly, $allTypes)) {
        $skipped++;
        continue;
    }

    $mainImage = trim((string) ($row['main_image'] ?? ''));
    if (!$force && rm_gen_product_has_image($pdo, $productId, $mainImage)) {
        echo "SKIP has image #{$productId} {$code}\n";
        $skipped++;
        continue;
    }

    $prompt = rm_gen_build_prompt($row);
    echo "GEN #{$productId} {$code}\n";
    echo '  name: ' . $name . PHP_EOL;
    echo '  prompt: ' . substr($prompt, 0, 140) . '...' . PHP_EOL;

    if ($dryRun) {
        $processed++;
        continue;
    }

    if ($force) {
        rm_gen_clear_product_images($pdo, $baseDir, $productId);
    }

    $rawPath = $cacheDir . DIRECTORY_SEPARATOR . 'raw_' . $productId . '.jpg';
    $jpgPath = $cacheDir . DIRECTORY_SEPARATOR . 'white_' . $productId . '.jpg';
    @unlink($rawPath);
    @unlink($jpgPath);

    $ok = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $seed = ($productId * 1000) + $attempt;
        if (rm_gen_fetch_ai_image($prompt, $seed, $rawPath)) {
            $ok = true;
            break;
        }
        echo "  retry {$attempt}/3\n";
        sleep(2);
    }

    if (!$ok) {
        echo "  FAIL generation\n";
        $failed++;
        continue;
    }

    if (!rm_gen_composite_white($rawPath, $jpgPath)) {
        echo "  FAIL composite\n";
        $failed++;
        continue;
    }

    try {
        $filename = rm_gen_assign_product_image($pdo, $processor, $productId, $jpgPath);
        echo "  OK {$filename}\n";
        $processed++;
    } catch (Throwable $e) {
        echo '  FAIL assign: ' . $e->getMessage() . PHP_EOL;
        $failed++;
    }

    @unlink($rawPath);
    @unlink($jpgPath);
    sleep(3);
}

echo PHP_EOL . "done processed={$processed} skipped={$skipped} failed={$failed}" . PHP_EOL;
