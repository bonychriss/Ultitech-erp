<?php
/**
 * Copy Ultimate product photos from live ultitech.io into local tenant storage.
 * Git never contains these files (storage/ is gitignored).
 *
 * Usage: php scripts/pull-ultimate-product-images-from-live.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$_GET['company_slug'] = 'ultimate';
$_SERVER['REQUEST_URI'] = '/ultitech_erp/ultimate/stock/product_image.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../stock/config/functions.php';

$pdo = function_exists('erp_data_pdo') ? erp_data_pdo() : ($GLOBALS['pdo'] ?? null);
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "No tenant PDO.\n");
    exit(1);
}

$pairs = [];
$add = static function (int $id, string $name) use (&$pairs): void {
    $name = basename(str_replace('\\', '/', trim($name)));
    if ($id < 1 || $name === '' || $name === '.' || $name === '..') {
        return;
    }
    $pairs[$id . '|' . $name] = [$id, $name];
};

try {
    foreach ($pdo->query('SELECT id, main_image, image FROM products') as $row) {
        $add((int) $row['id'], (string) ($row['main_image'] ?? ''));
        $add((int) $row['id'], (string) ($row['image'] ?? ''));
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
try {
    foreach ($pdo->query('SELECT product_id, image_name FROM product_images') as $row) {
        $add((int) $row['product_id'], (string) ($row['image_name'] ?? ''));
    }
} catch (Throwable $e) {
}

$destRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
    . 'tenant_1' . DIRECTORY_SEPARATOR . 'products';
if (!is_dir($destRoot) && !mkdir($destRoot, 0755, true) && !is_dir($destRoot)) {
    fwrite(STDERR, "Cannot create $destRoot\n");
    exit(1);
}

$baseUrl = 'https://ultitech.io/ultimate/stock/product_image.php';
$ok = 0;
$skip = 0;
$fail = 0;
$total = count($pairs);
$i = 0;

foreach ($pairs as [$productId, $filename]) {
    $i++;
    $dir = $destRoot . DIRECTORY_SEPARATOR . $productId . DIRECTORY_SEPARATOR . 'medium';
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (is_file($dest) && filesize($dest) > 100) {
        $skip++;
        continue;
    }
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $fail++;
        fwrite(STDERR, "mkdir fail $dir\n");
        continue;
    }
    $url = $baseUrl . '?' . http_build_query([
        'product_id' => $productId,
        'size' => 'medium',
        'file' => $filename,
        'company_slug' => 'ultimate',
    ]);
    $tmp = $dest . '.part';
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        $fail++;
        continue;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_USERAGENT => 'Ultitech-erp local image pull',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $okCurl = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);
    $size = is_file($tmp) ? filesize($tmp) : 0;
    $isImage = $okCurl && $code === 200 && $size > 100 && stripos($ctype, 'image/') !== false;
    if ($isImage) {
        rename($tmp, $dest);
        $ok++;
        echo "[$i/$total] ok product $productId $filename ($size bytes)\n";
    } else {
        @unlink($tmp);
        $fail++;
        echo "[$i/$total] FAIL product $productId $filename http=$code type=$ctype size=$size\n";
    }
}

echo "done ok=$ok skipped=$skip failed=$fail total=$total dest=$destRoot\n";
exit($fail > 0 && $ok === 0 ? 1 : 0);
