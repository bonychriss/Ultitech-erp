<?php
/**
 * Build + FTP deploy mail app to roadmasterspares.com/public_html/mail/
 * Usage: FTP_PASS=... php scripts/deploy_mail_roadmaster.php
 */
declare(strict_types=1);

$host = getenv('FTP_HOST') ?: 'ftp.roadmasterspares.com';
$user = getenv('FTP_USER') ?: 'roadmasterspares.com';
$pass = getenv('FTP_PASS') ?: '';
$mailRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail';
$zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmp_mail_roadmaster_deploy.zip';
$extractKey = 'RMMAIL2026';

if ($pass === '') {
    fwrite(STDERR, "FTP_PASS required\n");
    exit(1);
}
if (!is_dir($mailRoot . '/vendor')) {
    fwrite(STDERR, "mail/vendor missing\n");
    exit(1);
}

$skipDirNames = ['node_modules', '.git', 'runtime', 'vagrant', 'tests', '.idea', 'react'];
$skipFiles = ['.DS_Store', 'Thumbs.db', 'yii.bat', 'codeception.yml'];

$htaccessRoadmaster = <<<'HTA'
Options -MultiViews
AcceptPathInfo On

RewriteEngine On
RewriteBase /mail/frontend/web/

RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

RewriteRule ^app(/.*)?$ index.php [L,QSA]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [L]
HTA;

$prodIndex = <<<'PHP'
<?php

defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../../common/config/bootstrap.php';
require __DIR__ . '/../config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../../common/config/main.php',
    require __DIR__ . '/../../common/config/main-local.php',
    require __DIR__ . '/../config/main.php',
    require __DIR__ . '/../config/main-local.php'
);

(new yii\web\Application($config))->run();
PHP;

echo "Building zip...\n";
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create zip\n");
    exit(1);
}

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
        // Skip frontend/react source; keep frontend/web/app build. Also skip other listed dirs.
        if ($d === 'react') {
            if (str_starts_with($norm, 'frontend/react/')) {
                continue 2;
            }
            continue;
        }
        if (str_contains('/' . $norm . '/', '/' . $d . '/')) {
            continue 2;
        }
    }
    if (in_array(basename($norm), $skipFiles, true)) {
        continue;
    }
    if ($norm === 'frontend/web/.htaccess.live' || $norm === 'frontend/web/.htaccess.roadmaster') {
        continue;
    }

    $zipPathEntry = 'mail/' . $norm;
    if ($norm === 'frontend/web/.htaccess') {
        $zip->addFromString('mail/frontend/web/.htaccess', $htaccessRoadmaster);
        $added++;
        continue;
    }
    if ($norm === 'frontend/web/index.php') {
        $zip->addFromString('mail/frontend/web/index.php', $prodIndex);
        $added++;
        continue;
    }

    if (!$zip->addFile($full, $zipPathEntry)) {
        fwrite(STDERR, "Failed add $norm\n");
        exit(2);
    }
    $added++;
    if ($added % 1000 === 0) {
        echo "  added $added files...\n";
    }
}

if ($zip->locateName('mail/frontend/web/.htaccess') === false) {
    $zip->addFromString('mail/frontend/web/.htaccess', $htaccessRoadmaster);
    $added++;
}
if ($zip->locateName('mail/frontend/web/index.php') === false) {
    $zip->addFromString('mail/frontend/web/index.php', $prodIndex);
    $added++;
}

// One-shot migrate + health after extract
$setupPhp = <<<'PHP'
<?php
declare(strict_types=1);
$key = $_GET['key'] ?? '';
if ($key !== 'RMMAIL2026') {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
$web = __DIR__;
$mailRoot = dirname(__DIR__, 2); // .../mail
if (!is_file($mailRoot . '/vendor/autoload.php')) {
    echo "NO_VENDOR\n";
    exit(1);
}
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'prod');
require $mailRoot . '/vendor/autoload.php';
require $mailRoot . '/vendor/yiisoft/yii2/Yii.php';
require $mailRoot . '/common/config/bootstrap.php';
require $mailRoot . '/frontend/config/bootstrap.php';
$config = yii\helpers\ArrayHelper::merge(
    require $mailRoot . '/common/config/main.php',
    require $mailRoot . '/common/config/main-local.php',
    require $mailRoot . '/frontend/config/main.php',
    require $mailRoot . '/frontend/config/main-local.php'
);
new yii\web\Application($config);
try {
    $exit = Yii::$app->runAction('migrate/up', ['interactive' => false]);
    echo "migrate_exit=" . var_export($exit, true) . "\n";
} catch (Throwable $e) {
    echo "migrate_error=" . $e->getMessage() . "\n";
}
echo "index=" . (is_file($web . '/index.php') ? 'yes' : 'no') . "\n";
echo "app=" . (is_file($web . '/app/index.html') ? 'yes' : 'no') . "\n";
echo "htaccess=" . (is_file($web . '/.htaccess') ? 'yes' : 'no') . "\n";
echo "OK\n";
@unlink(__FILE__);
PHP;
$zip->addFromString('mail/frontend/web/_rm_mail_setup.php', $setupPhp);
$added++;

$zip->close();
$mb = round(filesize($zipPath) / 1048576, 2);
echo "Zip ready: $zipPath ({$mb} MB), files=$added\n";

echo "FTP connect...\n";
$conn = ftp_connect($host, 21, 60);
if (!$conn || !ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed\n");
    exit(1);
}
ftp_pasv($conn, true);
ftp_set_option($conn, FTP_TIMEOUT_SEC, 600);

function ftpEnsureDir($conn, string $dir): void
{
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $dir)), 'strlen'));
    $path = '';
    foreach ($parts as $part) {
        $path .= '/' . $part;
        if (@ftp_chdir($conn, $path)) {
            continue;
        }
        if (!@ftp_mkdir($conn, $path)) {
            // may already exist
        }
    }
}

ftpEnsureDir($conn, '/public_html');
@ftp_chdir($conn, '/public_html');

echo "Uploading zip...\n";
$remoteZip = '/public_html/tmp_mail_roadmaster_deploy.zip';
if (!ftp_put($conn, $remoteZip, $zipPath, FTP_BINARY)) {
    fwrite(STDERR, "zip upload failed\n");
    exit(2);
}
echo "Zip uploaded\n";

$extractor = <<<PHP
<?php
declare(strict_types=1);
\$key = \$_GET['key'] ?? '';
if (\$key !== '{$extractKey}') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
\$root = __DIR__;
\$zipPath = \$root . '/tmp_mail_roadmaster_deploy.zip';
if (!is_file(\$zipPath)) {
    http_response_code(404);
    echo 'zip not found';
    exit;
}
\$zip = new ZipArchive();
if (\$zip->open(\$zipPath) !== true) {
    http_response_code(500);
    echo 'Cannot open zip';
    exit;
}
if (!\$zip->extractTo(\$root)) {
    \$zip->close();
    http_response_code(500);
    echo 'Extract failed';
    exit;
}
\$count = \$zip->numFiles;
\$zip->close();
@unlink(\$zipPath);
@unlink(__FILE__);
header('Content-Type: text/plain; charset=utf-8');
echo "Extracted \$count entries\\n";
echo is_file(\$root . '/mail/frontend/web/index.php') ? "HAS_INDEX\\n" : "NO_INDEX\\n";
echo is_file(\$root . '/mail/vendor/yiisoft/yii2/Yii.php') ? "HAS_YII\\n" : "NO_YII\\n";
echo is_file(\$root . '/mail/frontend/web/app/index.html') ? "HAS_APP\\n" : "NO_APP\\n";
echo "OK\\n";
PHP;

$tmpExtract = tempnam(sys_get_temp_dir(), 'ext');
file_put_contents($tmpExtract, $extractor);
if (!ftp_put($conn, '/public_html/_extract_mail_deploy.php', $tmpExtract, FTP_ASCII)) {
    fwrite(STDERR, "extractor upload failed\n");
    exit(2);
}
@unlink($tmpExtract);
ftp_close($conn);
echo "Extractor uploaded\n";

echo "Extracting on server...\n";
$extractUrl = 'https://roadmasterspares.com/_extract_mail_deploy.php?key=' . rawurlencode($extractKey);
$ch = curl_init($extractUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "extract HTTP $code\n$body\n";
if ($err) {
    echo "curl_err=$err\n";
}
if ($code !== 200 || !str_contains($body, 'OK')) {
    fwrite(STDERR, "Extract failed\n");
    exit(3);
}

echo "Running migrate/setup...\n";
$setupUrl = 'https://roadmasterspares.com/mail/frontend/web/_rm_mail_setup.php?key=' . rawurlencode($extractKey);
$ch = curl_init($setupUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "setup HTTP $code\n$body\n";

echo "Smoke test...\n";
foreach ([
    'https://roadmasterspares.com/mail/frontend/web/',
    'https://roadmasterspares.com/mail/frontend/web/index.php/app',
    'https://roadmasterspares.com/mail/frontend/web/app/',
] as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => true,
    ]);
    curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "$c $url\n";
}

@unlink($zipPath);
echo "DONE\n";
