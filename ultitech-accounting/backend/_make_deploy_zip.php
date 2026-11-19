<?php
declare(strict_types=1);

$base = __DIR__;
$zipPath = dirname($base) . DIRECTORY_SEPARATOR . 'backend-deploy.zip';
if (is_file($zipPath)) {
    unlink($zipPath);
}

$z = new ZipArchive();
if ($z->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Failed to open zip\n");
    exit(1);
}

$excludeRegex = [
    '#(^|/)\.git(/|$)#i',
    '#(^|/)tests(/|$)#i',
    '#(^|/)node_modules(/|$)#i',
    '#(^|/)storage/logs/#i',
    '#(^|/)storage/framework/cache/data/#i',
    '#(^|/)_make_deploy_zip\.php$#i',
    '#(^|/)_seed_sqlite\.php$#i',
    '#(^|/)\.env$#i',
    '#(^|/)\.env\.deploy$#i',
    '#(^|/)phpunit#i',
];

$count = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (! $file->isFile()) {
        continue;
    }
    $full = $file->getPathname();
    $rel = substr($full, strlen($base) + 1);
    $rel = str_replace('\\', '/', $rel);
    $skip = false;
    foreach ($excludeRegex as $rx) {
        if (preg_match($rx, $rel)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }
    $z->addFile($full, $rel);
    $count++;
}

// Production env becomes live .env
$z->addFile($base . DIRECTORY_SEPARATOR . '.env.production', '.env');
$z->close();

echo "files={$count}\n";
echo 'zip_bytes=' . filesize($zipPath) . "\n";
echo "zip={$zipPath}\n";
