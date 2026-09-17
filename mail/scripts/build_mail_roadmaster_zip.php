<?php
/**
 * Build mail deploy zip for roadmasterspares.com ? public_html/mail/
 * Usage: php build_mail_roadmaster_zip.php
 */
declare(strict_types=1);

$mailRoot = dirname(__DIR__); // public_html/mail
$zipFile = 'c:/xampp/tmp_mail_roadmaster_deploy.zip';

if (!is_dir($mailRoot . '/vendor')) {
    fwrite(STDERR, "mail/vendor missing\n");
    exit(1);
}
if (is_file($zipFile)) {
    unlink($zipFile);
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create zip\n");
    exit(1);
}

$skipDirNames = ['node_modules', '.git', 'runtime', 'vagrant', 'tests', '.idea'];
$skipFiles = ['.DS_Store', 'Thumbs.db', 'yii.bat', 'codeception.yml'];

$htaccessRoadmaster = <<<'HTA'
RewriteEngine On
RewriteBase /mail/frontend/web/

RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [L]
HTA;

$added = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($mailRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }
    $full = $file->getPathname();
    $rel = substr($full, strlen($mailRoot) + 1);
    $norm = str_replace('\\', '/', $rel);

    foreach ($skipDirNames as $d) {
        if (str_contains('/' . $norm . '/', '/' . $d . '/')) {
            continue 2;
        }
    }
    if (in_array(basename($norm), $skipFiles, true)) {
        continue;
    }

    // Zip paths under mail/
    $zipPath = 'mail/' . $norm;
    if ($norm === 'frontend/web/.htaccess.live') {
        continue;
    }
    if ($norm === 'frontend/web/.htaccess') {
        $zip->addFromString('mail/frontend/web/.htaccess', $htaccessRoadmaster);
        $added++;
        continue;
    }

    if (!$zip->addFile($full, $zipPath)) {
        fwrite(STDERR, "Failed add $norm\n");
        exit(2);
    }
    $added++;
}

// Ensure roadmaster htaccess exists even if source missing
if ($zip->locateName('mail/frontend/web/.htaccess') === false) {
    $zip->addFromString('mail/frontend/web/.htaccess', $htaccessRoadmaster);
    $added++;
}

// Remote extractor
$extractor = <<<'PHP'
<?php
declare(strict_types=1);
$key = $_GET['key'] ?? '';
if ($key !== 'RMMAIL2026') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
$root = __DIR__;
$zipPath = $root . '/tmp_mail_roadmaster_deploy.zip';
if (!is_file($zipPath)) {
    http_response_code(404);
    echo 'zip not found';
    exit;
}
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    http_response_code(500);
    echo 'Cannot open zip';
    exit;
}
if (!$zip->extractTo($root)) {
    $zip->close();
    http_response_code(500);
    echo 'Extract failed';
    exit;
}
$count = $zip->numFiles;
$zip->close();
@unlink($zipPath);
@unlink(__FILE__);
header('Content-Type: text/plain; charset=utf-8');
echo "Extracted $count entries\n";
echo is_file($root . '/mail/frontend/web/index.php') ? "HAS_INDEX\n" : "NO_INDEX\n";
echo is_file($root . '/mail/vendor/yiisoft/yii2/Yii.php') ? "HAS_YII\n" : "NO_YII\n";
echo "OK\n";
PHP;

$zip->addFromString('_extract_mail_deploy.php', $extractor);
$added++;

$zip->close();
echo 'Created ' . $zipFile . ' (' . round(filesize($zipFile) / 1048576, 2) . " MB), files=$added\n";
exit(0);
