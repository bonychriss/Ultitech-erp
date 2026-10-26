<?php
/**
 * Upload mail zip + temporary Laravel install route, then trigger extract.
 * Usage: FTP_PASS=... php scripts/deploy_mail_via_laravel.php
 */
declare(strict_types=1);

$host = getenv('FTP_HOST') ?: 'ftp.roadmasterspares.com';
$user = getenv('FTP_USER') ?: 'roadmasterspares.com';
$pass = getenv('FTP_PASS') ?: '';
$mailRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail';
$zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmp_mail_roadmaster_deploy.zip';
$key = 'RMMAIL2026';

if ($pass === '') {
    fwrite(STDERR, "FTP_PASS required\n");
    exit(1);
}

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

$prodIndex = (string) file_get_contents(dirname(__DIR__) . '/mail/environments/prod/frontend/web/index.php');

echo "Building zip...\n";
if (is_file($zipPath)) {
    unlink($zipPath);
}
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create zip\n");
    exit(1);
}

$skipDirNames = ['node_modules', '.git', 'runtime', 'vagrant', 'tests', '.idea'];
$skipFiles = ['.DS_Store', 'Thumbs.db', 'yii.bat', 'codeception.yml'];
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
    if (str_starts_with($norm, 'frontend/react/')) {
        continue;
    }
    foreach ($skipDirNames as $d) {
        if (str_contains('/' . $norm . '/', '/' . $d . '/')) {
            continue 2;
        }
    }
    if (in_array(basename($norm), $skipFiles, true)) {
        continue;
    }
    if (in_array($norm, ['frontend/web/.htaccess.live', 'frontend/web/.htaccess.roadmaster'], true)) {
        continue;
    }

    $entry = 'mail/' . $norm;
    if ($norm === 'frontend/web/.htaccess') {
        $zip->addFromString($entry, $htaccessRoadmaster);
    } elseif ($norm === 'frontend/web/index.php') {
        $zip->addFromString($entry, $prodIndex);
    } else {
        $zip->addFile($full, $entry);
    }
    $added++;
    if ($added % 1500 === 0) {
        echo "  $added...\n";
    }
}
if ($zip->locateName('mail/frontend/web/.htaccess') === false) {
    $zip->addFromString('mail/frontend/web/.htaccess', $htaccessRoadmaster);
}
if ($zip->locateName('mail/frontend/web/index.php') === false) {
    $zip->addFromString('mail/frontend/web/index.php', $prodIndex);
}
$zip->close();
echo 'Zip ' . round(filesize($zipPath) / 1048576, 2) . " MB ($added files)\n";

$conn = ftp_connect($host, 21, 60);
if (!$conn || !ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed\n");
    exit(1);
}
ftp_pasv($conn, true);
ftp_set_option($conn, FTP_TIMEOUT_SEC, 600);

echo "Uploading zip...\n";
if (!ftp_put($conn, '/public_html/tmp_mail_roadmaster_deploy.zip', $zipPath, FTP_BINARY)) {
    fwrite(STDERR, "zip upload failed\n");
    exit(2);
}
echo "Zip uploaded\n";

// Download current web.php, inject install route if missing, re-upload
$localRoutes = tempnam(sys_get_temp_dir(), 'routes');
if (!@ftp_get($conn, $localRoutes, '/roadmaster-site/routes/web.php', FTP_ASCII)) {
    fwrite(STDERR, "Cannot download routes/web.php\n");
    exit(2);
}
$routes = (string) file_get_contents($localRoutes);
$backup = $localRoutes . '.bak';
copy($localRoutes, $backup);

$marker = '/* MAIL_INSTALL_ROUTE */';
if (!str_contains($routes, $marker)) {
    $installRoute = <<<'PHP'

/* MAIL_INSTALL_ROUTE */
Route::get('/__mail_install', function () {
    $key = (string) request('key', '');
    if ($key !== 'RMMAIL2026') {
        abort(403);
    }
    $public = base_path('../public_html');
    if (!is_dir($public)) {
        $public = base_path('public');
        // StackCP: app in ~/roadmaster-site, web root ~/public_html
        $candidate = dirname(base_path()) . '/public_html';
        if (is_dir($candidate)) {
            $public = $candidate;
        }
    }
    $zipPath = $public . '/tmp_mail_roadmaster_deploy.zip';
    if (!is_file($zipPath)) {
        return response("zip not found at $zipPath", 404)->header('Content-Type', 'text/plain');
    }
    if (!class_exists(\ZipArchive::class)) {
        return response('ZipArchive missing', 500)->header('Content-Type', 'text/plain');
    }
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return response('Cannot open zip', 500)->header('Content-Type', 'text/plain');
    }
    if (!$zip->extractTo($public)) {
        $zip->close();
        return response('Extract failed', 500)->header('Content-Type', 'text/plain');
    }
    $count = $zip->numFiles;
    $zip->close();
    @unlink($zipPath);

    $out = ["Extracted $count entries"];
    $out[] = is_file($public . '/mail/frontend/web/index.php') ? 'HAS_INDEX' : 'NO_INDEX';
    $out[] = is_file($public . '/mail/vendor/yiisoft/yii2/Yii.php') ? 'HAS_YII' : 'NO_YII';
    $out[] = is_file($public . '/mail/frontend/web/app/index.html') ? 'HAS_APP' : 'NO_APP';

    // Best-effort migrations
    try {
        $mailRoot = $public . '/mail';
        if (is_file($mailRoot . '/yii')) {
            $cmd = 'cd ' . escapeshellarg($mailRoot) . ' && php yii migrate --interactive=0 2>&1';
            $out[] = 'migrate: ' . substr((string) shell_exec($cmd), 0, 500);
        }
    } catch (\Throwable $e) {
        $out[] = 'migrate_skip: ' . $e->getMessage();
    }

    $out[] = 'OK';

    // Remove this route by restoring marker cleanup hint (manual FTP restore of web.php.bak if needed)
    return response(implode("\n", $out) . "\n", 200)->header('Content-Type', 'text/plain; charset=utf-8');
});

PHP;
    // Append before end of file
    $routes = rtrim($routes) . "\n" . $installRoute . "\n";
    file_put_contents($localRoutes, $routes);
    // Backup remote first
    ftp_put($conn, '/roadmaster-site/routes/web.php.pre-mail', $backup, FTP_ASCII);
    if (!ftp_put($conn, '/roadmaster-site/routes/web.php', $localRoutes, FTP_ASCII)) {
        fwrite(STDERR, "Failed uploading routes/web.php\n");
        exit(2);
    }
    echo "Install route uploaded\n";
} else {
    echo "Install route already present\n";
}

ftp_close($conn);

echo "Clearing Laravel caches via HTTP if possible, then extracting...\n";
$urls = [
    "https://roadmasterspares.com/__mail_install?key={$key}",
];
foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: text/plain'],
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "HTTP $code\n$body\n";
    if ($err) {
        echo "err=$err\n";
    }
    if ($code === 200 && str_contains($body, 'OK')) {
        echo "EXTRACT_OK\n";
        break;
    }
}

// Smoke tests
foreach ([
    'https://roadmasterspares.com/mail/frontend/web/index.php',
    'https://roadmasterspares.com/mail/frontend/web/index.php/app',
    'https://roadmasterspares.com/mail/frontend/web/',
] as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => false,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "$code $u len=" . strlen($body) . "\n";
}

@unlink($zipPath);
@unlink($localRoutes);
echo "DONE\n";
