<?php
/**
 * Recursive FTP upload of mail/ to roadmasterspares.com/public_html/mail/
 * Usage: FTP_PASS=... php scripts/deploy_mail_roadmaster_ftp.php
 */
declare(strict_types=1);

$host = getenv('FTP_HOST') ?: 'ftp.roadmasterspares.com';
$user = getenv('FTP_USER') ?: 'roadmasterspares.com';
$pass = getenv('FTP_PASS') ?: '';
$mailRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mail';
$remoteBase = '/public_html/mail';

if ($pass === '') {
    fwrite(STDERR, "FTP_PASS required\n");
    exit(1);
}

$skipDirNames = ['node_modules', '.git', 'runtime', 'vagrant', 'tests', '.idea'];
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

$prodIndex = (string) file_get_contents(dirname(__DIR__) . '/mail/environments/prod/frontend/web/index.php');

$conn = ftp_connect($host, 21, 60);
if (!$conn || !ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed\n");
    exit(1);
}
ftp_pasv($conn, true);
ftp_set_option($conn, FTP_TIMEOUT_SEC, 300);

function ftpEnsureDir($conn, string $dir): void
{
    static $made = [];
    $dir = '/' . trim(str_replace('\\', '/', $dir), '/');
    if (isset($made[$dir])) {
        return;
    }
    $parts = array_values(array_filter(explode('/', $dir), 'strlen'));
    $path = '';
    foreach ($parts as $part) {
        $path .= '/' . $part;
        if (isset($made[$path])) {
            continue;
        }
        if (@ftp_chdir($conn, $path)) {
            $made[$path] = true;
            continue;
        }
        if (@ftp_mkdir($conn, $path)) {
            $made[$path] = true;
        } elseif (@ftp_chdir($conn, $path)) {
            $made[$path] = true;
        }
    }
}

$uploaded = 0;
$failed = 0;
$t0 = microtime(true);

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
    if ($norm === 'frontend/web/.htaccess.live' || $norm === 'frontend/web/.htaccess.roadmaster') {
        continue;
    }

    $remote = $remoteBase . '/' . $norm;
    $remoteDir = dirname($remote);
    ftpEnsureDir($conn, $remoteDir);

    $ok = false;
    if ($norm === 'frontend/web/.htaccess') {
        $tmp = tempnam(sys_get_temp_dir(), 'ht');
        file_put_contents($tmp, $htaccessRoadmaster);
        $ok = @ftp_put($conn, $remote, $tmp, FTP_ASCII);
        @unlink($tmp);
    } elseif ($norm === 'frontend/web/index.php') {
        $tmp = tempnam(sys_get_temp_dir(), 'ix');
        file_put_contents($tmp, $prodIndex);
        $ok = @ftp_put($conn, $remote, $tmp, FTP_ASCII);
        @unlink($tmp);
    } else {
        $ascii = preg_match('/\.(php|css|js|html|htaccess|json|md|txt|svg|map|xml|yml|yaml|ini|env)$/i', $norm) === 1;
        $ok = @ftp_put($conn, $remote, $full, $ascii ? FTP_ASCII : FTP_BINARY);
    }

    if ($ok) {
        $uploaded++;
    } else {
        $failed++;
        fwrite(STDERR, "FAIL $norm\n");
        // reconnect once
        @ftp_close($conn);
        $conn = ftp_connect($host, 21, 60);
        if (!$conn || !ftp_login($conn, $user, $pass)) {
            fwrite(STDERR, "reconnect failed\n");
            exit(2);
        }
        ftp_pasv($conn, true);
        ftpEnsureDir($conn, $remoteDir);
        $ok2 = @ftp_put($conn, $remote, $full, FTP_BINARY);
        if ($ok2) {
            $uploaded++;
            $failed--;
            echo "RETRY_OK $norm\n";
        }
    }

    if ($uploaded % 200 === 0) {
        $sec = round(microtime(true) - $t0, 1);
        echo "uploaded=$uploaded failed=$failed elapsed={$sec}s last=$norm\n";
    }
}

// Ensure critical overrides even if source missing
ftpEnsureDir($conn, $remoteBase . '/frontend/web');
$tmp = tempnam(sys_get_temp_dir(), 'ht');
file_put_contents($tmp, $htaccessRoadmaster);
ftp_put($conn, $remoteBase . '/frontend/web/.htaccess', $tmp, FTP_ASCII);
@unlink($tmp);
$tmp = tempnam(sys_get_temp_dir(), 'ix');
file_put_contents($tmp, $prodIndex);
ftp_put($conn, $remoteBase . '/frontend/web/index.php', $tmp, FTP_ASCII);
@unlink($tmp);

ftp_close($conn);
$sec = round(microtime(true) - $t0, 1);
echo "DONE uploaded=$uploaded failed=$failed seconds=$sec\n";
exit($failed > 0 ? 3 : 0);
