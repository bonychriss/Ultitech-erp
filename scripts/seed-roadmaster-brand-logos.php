<?php
/**
 * Seed FAW / HOWO / SHACMAN / Roadmaster brand logos into UltiTech Roadmaster brands.
 */
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/roadmaster/stock/products';
$_GET['company_slug'] = 'roadmaster';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['company_slug'] = 'roadmaster';
$_SESSION['company_id'] = 2;
$_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;

require 'c:/xampp/htdocs/public_html/stock/config/database.php';
require_once 'c:/xampp/htdocs/public_html/stock/config/functions.php';

$sources = [
    'FAW' => 'c:/xampp/htdocs/roadmasterspares/public_html/media/brand-logos/logo-faw.png',
    'HOWO' => 'c:/xampp/htdocs/roadmasterspares/public_html/media/brand-logos/logo-howo-cnhtc.png',
    'SHACMAN' => 'c:/xampp/htdocs/roadmasterspares/public_html/media/brand-logos/logo-shacman.png',
    'Roadmaster' => 'c:/xampp/htdocs/roadmasterspares/public_html/assets/brand/roadmaster-logo.png',
];

$uploadDir = rtrim(str_replace('\\', '/', stock_brand_upload_dir()), '/') . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

echo 'DB=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'uploadDir=' . $uploadDir . PHP_EOL;

foreach ($sources as $name => $src) {
    if (!is_file($src)) {
        echo "MISSING source for {$name}: {$src}" . PHP_EOL;
        continue;
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION) ?: 'png');
    $destName = 'brand_' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $name) ?? $name) . '_' . time() . '.' . $ext;
    $dest = $uploadDir . $destName;
    if (!copy($src, $dest)) {
        echo "FAIL copy {$name}" . PHP_EOL;
        continue;
    }

    $st = $pdo->prepare('SELECT id, logo FROM brands WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $st->execute([$name]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $old = trim((string) ($row['logo'] ?? ''));
        $pdo->prepare('UPDATE brands SET logo = ? WHERE id = ?')->execute([$destName, (int) $row['id']]);
        if ($old !== '' && $old !== $destName) {
            $oldPath = $uploadDir . basename($old);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
        echo "UPDATED #{$row['id']} {$name} -> {$destName}" . PHP_EOL;
    } else {
        $pdo->prepare('INSERT INTO brands (name, brand_type, logo) VALUES (?, ?, ?)')
            ->execute([$name, $name === 'Roadmaster' ? 'spare_part' : 'truck', $destName]);
        echo 'CREATED #' . $pdo->lastInsertId() . " {$name} -> {$destName}" . PHP_EOL;
    }
    $url = stock_brand_image_url($destName);
    echo "  url={$url}" . PHP_EOL;
}

echo PHP_EOL . 'Sample resolve:' . PHP_EOL;
foreach (['FAW', 'HOWO', 'SHACMAN', 'Roadmaster', 'SINOTRUK'] as $b) {
    echo "  {$b} => " . stock_resolve_brand_logo_url($pdo, $b, $b) . PHP_EOL;
}
