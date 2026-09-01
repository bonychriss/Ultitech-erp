<?php
/**
 * Bulk-assign realistic product images for Roadmaster (local CLI).
 *
 * Usage:
 *   php scripts/bulk-fetch-roadmaster-product-images.php --dry-run
 *   php scripts/bulk-fetch-roadmaster-product-images.php --limit=5
 *   php scripts/bulk-fetch-roadmaster-product-images.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['dry-run', 'limit:', 'product-id:', 'force']);
$dryRun = array_key_exists('dry-run', $opts);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
$onlyProductId = isset($opts['product-id']) ? (int) $opts['product-id'] : 0;
$force = array_key_exists('force', $opts);

$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/public_html/roadmaster/stock/modules/products/index.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/../stock/classes/ImageProcessor.php';

function rm_img_is_test_product(string $name, string $code): bool
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

function rm_img_product_has_image(PDO $pdo, int $productId, string $mainImage): bool
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

function rm_img_build_queries(array $product): array
{
    $name = trim((string) ($product['name'] ?? ''));
    $code = trim((string) ($product['product_code'] ?? ''));
    $lower = strtolower($name);
    $isTruck = str_starts_with($code, 'TRK-')
        || strtolower((string) ($product['item_type'] ?? '')) === 'vehicle'
        || trim((string) ($product['truck_type'] ?? '')) !== '';

    if ($isTruck) {
        $queries = [];
        if (str_contains($lower, 'tractor')) {
            $queries[] = 'FAW tractor truck white background';
            $queries[] = 'semi truck tractor unit';
        } elseif (str_contains($lower, 'tipper') || str_contains($lower, 'dump')) {
            $queries[] = 'dump truck tipper white background';
            $queries[] = 'heavy dump truck';
        } elseif (str_contains($lower, 'tank')) {
            $queries[] = 'fuel tank truck white background';
            $queries[] = 'water tank truck';
        } elseif (str_contains($lower, 'cargo') || str_contains($lower, 'closed body')) {
            $queries[] = 'cargo truck box truck white background';
        } else {
            $queries[] = 'heavy truck commercial vehicle';
        }
        $queries[] = 'FAW truck';

        return array_values(array_unique($queries));
    }

    $rules = [
        ['filter', 'automotive oil filter white background'],
        ['separator', 'fuel water separator filter'],
        ['cleaner', 'air filter automotive white background'],
        ['sensor', 'automotive sensor white background'],
        ['spring', 'leaf spring truck part'],
        ['ring', 'piston ring engine'],
        ['rod', 'steering tie rod automotive'],
        ['supercharge', 'turbocharger automotive'],
        ['gasket', 'engine gasket automotive'],
        ['belt', 'serpentine belt automotive'],
        ['brake', 'truck brake pad'],
        ['clutch', 'clutch disc automotive'],
        ['bearing', 'wheel bearing automotive'],
        ['pump', 'fuel pump automotive'],
        ['valve', 'engine valve automotive'],
    ];

    $queries = [];
    foreach ($rules as [$needle, $query]) {
        if (str_contains($lower, $needle)) {
            $queries[] = $query;
        }
    }

    $short = preg_replace('/\s+/', ' ', $name) ?? $name;
    $queries[] = $short . ' truck spare part white background';
    $queries[] = 'truck spare part white background';

    return array_values(array_unique($queries));
}

function rm_img_http_get_json(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 25,
            'user_agent' => 'UltiTech-ERP-ImageBot/1.0 (+local bulk assign)',
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function rm_img_fetch_openverse(string $query): ?string
{
    $url = 'https://api.openverse.org/v1/images/?' . http_build_query([
        'q' => $query,
        'page_size' => 5,
        'license' => 'cc0,pdm,by,by-sa',
    ]);
    $json = rm_img_http_get_json($url);
    if (!$json || empty($json['results']) || !is_array($json['results'])) {
        return null;
    }

    foreach ($json['results'] as $row) {
        $candidate = (string) ($row['url'] ?? $row['thumbnail'] ?? '');
        if ($candidate !== '' && preg_match('/^https?:\/\//i', $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function rm_img_fetch_wikimedia(string $query): ?string
{
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'generator' => 'search',
        'gsrsearch' => $query,
        'gsrnamespace' => 6,
        'prop' => 'imageinfo',
        'iiprop' => 'url',
        'iiurlwidth' => 900,
        'format' => 'json',
    ]);
    $json = rm_img_http_get_json($url);
    if (!$json || empty($json['query']['pages']) || !is_array($json['query']['pages'])) {
        return null;
    }

    foreach ($json['query']['pages'] as $page) {
        $info = $page['imageinfo'][0] ?? null;
        if (!is_array($info)) {
            continue;
        }
        $candidate = (string) ($info['thumburl'] ?? $info['url'] ?? '');
        if ($candidate !== '' && preg_match('/^https?:\/\//i', $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function rm_img_category_fallback_url(array $product): ?string
{
    $name = strtolower(trim((string) ($product['name'] ?? '')));
    $code = trim((string) ($product['product_code'] ?? ''));
    $isTruck = str_starts_with($code, 'TRK-');

    $map = [
        'truck_tractor' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6f/Mercedes-Benz_Actros_1845_Tractor_Unit.jpg/960px-Mercedes-Benz_Actros_1845_Tractor_Unit.jpg',
        'truck_dump' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/5/5d/Volvo_FM_dump_truck.jpg/960px-Volvo_FM_dump_truck.jpg',
        'truck_tank' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4d/Tank_truck.jpg/800px-Tank_truck.jpg',
        'truck_cargo' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/8d/Box_truck.jpg/960px-Box_truck.jpg',
        'truck_generic' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3f/Heavy_truck.jpg/960px-Heavy_truck.jpg',
        'spare_filter' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/0b/Oil_filter.jpg/800px-Oil_filter.jpg',
        'spare_sensor' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/5/5c/Automotive_sensor.jpg/800px-Automotive_sensor.jpg',
        'spare_brake' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/9d/Disc_brake.jpg/800px-Disc_brake.jpg',
        'spare_turbo' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/8a/Turbocharger.jpg/800px-Turbocharger.jpg',
        'spare_valve' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1b/Engine_valve.jpg/800px-Engine_valve.jpg',
        'spare_pump' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4e/Fuel_pump.jpg/800px-Fuel_pump.jpg',
        'spare_clutch' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6e/Clutch_plate.jpg/800px-Clutch_plate.jpg',
        'spare_gasket' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/2f/Head_gasket.jpg/800px-Head_gasket.jpg',
        'spare_spring' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7d/Leaf_spring.jpg/800px-Leaf_spring.jpg',
        'spare_generic' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/Auto_parts_store.jpg/800px-Auto_parts_store.jpg',
    ];

    if ($isTruck) {
        if (str_contains($name, 'tractor')) {
            return $map['truck_tractor'];
        }
        if (str_contains($name, 'tipper') || str_contains($name, 'dump')) {
            return $map['truck_dump'];
        }
        if (str_contains($name, 'tank')) {
            return $map['truck_tank'];
        }
        if (str_contains($name, 'cargo') || str_contains($name, 'closed body')) {
            return $map['truck_cargo'];
        }

        return $map['truck_generic'];
    }

    if (str_contains($name, 'filter') || str_contains($name, 'separator') || str_contains($name, 'cleaner')) {
        return $map['spare_filter'];
    }
    if (str_contains($name, 'sensor')) {
        return $map['spare_sensor'];
    }
    if (str_contains($name, 'brake') || str_contains($name, 'drum')) {
        return $map['spare_brake'];
    }
    if (str_contains($name, 'supercharge') || str_contains($name, 'turbo')) {
        return $map['spare_turbo'];
    }
    if (str_contains($name, 'valve')) {
        return $map['spare_valve'];
    }
    if (str_contains($name, 'pump') || str_contains($name, 'compressor')) {
        return $map['spare_pump'];
    }
    if (str_contains($name, 'clutch')) {
        return $map['spare_clutch'];
    }
    if (str_contains($name, 'gasket')) {
        return $map['spare_gasket'];
    }
    if (str_contains($name, 'spring')) {
        return $map['spare_spring'];
    }

    return $map['spare_generic'];
}

function rm_img_find_image_url(array $queries, array $product): ?string
{
    foreach ($queries as $query) {
        $url = rm_img_fetch_openverse($query);
        if ($url) {
            return $url;
        }
        usleep(350000);
        $url = rm_img_fetch_wikimedia($query);
        if ($url) {
            return $url;
        }
        usleep(350000);
    }

    return rm_img_category_fallback_url($product);
}

function rm_img_resolve_product_disk(PDO $pdo, int $productId, string $size = 'medium'): ?string
{
    $ctx = stock_image_company_context();
    $slug = (string) ($ctx['slug'] ?? 'roadmaster');
    $companyId = (int) ($ctx['company_id'] ?? 2);

    $st = $pdo->prepare('SELECT main_image FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    $mainImage = trim((string) $st->fetchColumn());
    if ($mainImage !== '') {
        $disk = stock_resolve_product_image_file($productId, $size, $mainImage, $slug, $companyId);
        if ($disk !== null && is_file($disk)) {
            return $disk;
        }
    }

    try {
        $gst = $pdo->prepare('SELECT image_name FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1');
        $gst->execute([$productId]);
        $gallery = trim((string) $gst->fetchColumn());
        if ($gallery !== '') {
            $disk = stock_resolve_product_image_file($productId, $size, $gallery, $slug, $companyId);
            if ($disk !== null && is_file($disk)) {
                return $disk;
            }
        }
    } catch (Throwable $e) {
    }

    return null;
}

function rm_img_find_donor_disk_file(PDO $pdo, array $product): ?string
{
    $productId = (int) ($product['id'] ?? 0);
    $code = trim((string) ($product['product_code'] ?? ''));
    $name = strtolower(trim((string) ($product['name'] ?? '')));
    $isTruck = str_starts_with($code, 'TRK-');

    $rows = $pdo->query('SELECT id, product_code, name FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $bestScore = -1;
    $bestDisk = null;

    foreach ($rows as $donor) {
        $donorId = (int) ($donor['id'] ?? 0);
        if ($donorId < 1 || $donorId === $productId) {
            continue;
        }
        $donorCode = trim((string) ($donor['product_code'] ?? ''));
        $donorName = strtolower(trim((string) ($donor['name'] ?? '')));
        $donorIsTruck = str_starts_with($donorCode, 'TRK-');
        if ($donorIsTruck !== $isTruck) {
            continue;
        }

        $disk = rm_img_resolve_product_disk($pdo, $donorId, 'medium');
        if ($disk === null) {
            continue;
        }

        $score = 1;
        if (!$isTruck) {
            foreach (['filter', 'brake', 'sensor', 'pump', 'valve', 'clutch', 'gasket', 'spring', 'separator'] as $kw) {
                if (str_contains($name, $kw) && str_contains($donorName, $kw)) {
                    $score += 3;
                }
            }
        } else {
            foreach (['tractor', 'tipper', 'dump', 'tank', 'cargo'] as $kw) {
                if (str_contains($name, $kw) && str_contains($donorName, $kw)) {
                    $score += 3;
                }
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestDisk = $disk;
        }
    }

    return $bestDisk;
}

function rm_img_download_file(string $url, string $dest): bool
{
    if (function_exists('curl_init')) {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT => 'UltiTech-ERP-ImageBot/1.0 (+local bulk assign)',
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
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'user_agent' => 'UltiTech-ERP-ImageBot/1.0 (+local bulk assign)',
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 1200) {
            return false;
        }
        if (file_put_contents($dest, $data) === false) {
            return false;
        }
    }

    return is_file($dest) && filesize($dest) >= 1200;
}

function rm_img_load_gd(string $path)
{
    $info = @getimagesize($path);
    if ($info === false) {
        return [null, 0];
    }
    [$w, $h, $type] = $info;
    switch ($type) {
        case IMAGETYPE_JPEG:
            return [imagecreatefromjpeg($path), $type];
        case IMAGETYPE_PNG:
            return [imagecreatefrompng($path), $type];
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? [imagecreatefromwebp($path), $type] : [null, 0];
        case IMAGETYPE_GIF:
            return [imagecreatefromgif($path), $type];
        default:
            return [null, 0];
    }
}

function rm_img_composite_white(string $sourcePath, string $destPath, int $size = 900): bool
{
    if (!ImageProcessor::gdAvailable()) {
        return copy($sourcePath, $destPath);
    }

    [$src, $type] = rm_img_load_gd($sourcePath);
    if (!$src) {
        return false;
    }

    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) {
        imagedestroy($src);
        return false;
    }

    $padding = 48;
    $max = $size - ($padding * 2);
    $ratio = min($max / $sw, $max / $sh);
    $nw = max(1, (int) round($sw * $ratio));
    $nh = max(1, (int) round($sh * $ratio));

    $canvas = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);

    $dstX = (int) round(($size - $nw) / 2);
    $dstY = (int) round(($size - $nh) / 2);

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($canvas, true);
    }

    imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $nw, $nh, $sw, $sh);
    imagedestroy($src);

    $ok = imagejpeg($canvas, $destPath, 92);
    imagedestroy($canvas);

    return $ok;
}

function rm_img_assign_product_image(PDO $pdo, ImageProcessor $processor, int $productId, string $preparedJpeg): string
{
    $filename = $processor->processUploadedImage($preparedJpeg, $productId);

    $hasPrimaryStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
    $hasPrimaryStmt->execute([$productId]);
    $hasPrimary = (int) $hasPrimaryStmt->fetchColumn();
    $isPrimary = $hasPrimary === 0 ? 1 : 0;

    $pdo->prepare(
        'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, ?, NULL)'
    )->execute([$productId, $filename, $isPrimary]);

    if ($isPrimary === 1) {
        $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$filename, $productId]);
    }

    return $filename;
}

$ctx = stock_image_company_context();
$baseDir = stock_product_upload_base_dir((int) ($ctx['company_id'] ?? 2), (string) ($ctx['slug'] ?? 'roadmaster'));
$processor = new ImageProcessor($baseDir);
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ultitech-rm-img-cache';
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

echo 'Roadmaster bulk image assign' . ($dryRun ? ' [DRY RUN]' : '') . PHP_EOL;
echo 'tenant_db=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

foreach ($rows as $row) {
    if ($limit > 0 && $processed >= $limit) {
        break;
    }

    $productId = (int) ($row['id'] ?? 0);
    $name = trim((string) ($row['name'] ?? ''));
    $code = trim((string) ($row['product_code'] ?? ''));
    if ($productId < 1 || rm_img_is_test_product($name, $code)) {
        $skipped++;
        continue;
    }

    $mainImage = trim((string) ($row['main_image'] ?? ''));
    if (!$force && rm_img_product_has_image($pdo, $productId, $mainImage)) {
        echo "SKIP has image #{$productId} {$code}\n";
        $skipped++;
        continue;
    }

    $queries = rm_img_build_queries($row);
    echo "FETCH #{$productId} {$code} :: {$name}\n";
    echo '  queries: ' . implode(' | ', array_slice($queries, 0, 3)) . PHP_EOL;

    if ($dryRun) {
        $processed++;
        continue;
    }

    $imageUrl = rm_img_find_image_url($queries, $row);
    if (!$imageUrl) {
        echo "  FAIL no image URL found\n";
        $failed++;
        continue;
    }

    $rawPath = $cacheDir . DIRECTORY_SEPARATOR . 'raw_' . $productId . '.img';
    $jpgPath = $cacheDir . DIRECTORY_SEPARATOR . 'white_' . $productId . '.jpg';
    @unlink($rawPath);
    @unlink($jpgPath);

    if (!rm_img_download_file($imageUrl, $rawPath)) {
        $donorDisk = rm_img_find_donor_disk_file($pdo, $row);
        if ($donorDisk !== null && @copy($donorDisk, $rawPath)) {
            echo "  WARN using donor image copy\n";
        } else {
            echo "  FAIL download\n";
            $failed++;
            continue;
        }
    }

    if (!rm_img_composite_white($rawPath, $jpgPath)) {
        echo "  FAIL white composite\n";
        $failed++;
        continue;
    }

    try {
        $filename = rm_img_assign_product_image($pdo, $processor, $productId, $jpgPath);
        echo "  OK {$filename}\n";
        $processed++;
    } catch (Throwable $e) {
        echo '  FAIL assign: ' . $e->getMessage() . PHP_EOL;
        $failed++;
    }

    @unlink($rawPath);
    @unlink($jpgPath);
    usleep(500000);
}

echo PHP_EOL . "done processed={$processed} skipped={$skipped} failed={$failed}" . PHP_EOL;
