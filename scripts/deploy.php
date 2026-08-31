<?php
// Simple FTPS deploy script (explicit TLS) for InfinityFree
// Usage (Windows PowerShell): C:\xampp\php\php.exe scripts\deploy.php

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

define('DS', DIRECTORY_SEPARATOR);
$root = dirname(__DIR__);

// Load config
$configFile = __DIR__ . DS . 'deploy.config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing scripts/deploy.config.php. Copy scripts/deploy.config.example.php and fill credentials.\n");
    exit(1);
}
$config = require $configFile;

$host = $config['host'] ?? '';
$port = (int)($config['port'] ?? 21);
$user = $config['user'] ?? '';
$pass = $config['pass'] ?? '';
$secure = (bool)($config['secure'] ?? true);
$remoteDir = rtrim(str_replace('\\', '/', $config['remoteDir'] ?? '/htdocs/'), '/') . '/';
$localRoot = rtrim(str_replace('\\', '/', $config['localRoot'] ?? $root), '/');
$includes = $config['include'] ?? ['**/*'];
$excludes = $config['exclude'] ?? [];
$extras = $config['extra'] ?? [];

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "FTP host/user/pass required in scripts/deploy.config.php\n");
    exit(1);
}

if (!function_exists('ftp_ssl_connect')) {
    fwrite(STDERR, "Warning: ftp_ssl_connect() not available. Falling back to plain FTP.\n");
}

// Connect
$conn = false;
if ($secure && function_exists('ftp_ssl_connect')) {
    $conn = @ftp_ssl_connect($host, $port, 30);
    if (!$conn) { fwrite(STDERR, "FTPS connect failed, trying plain FTP...\n"); }
}
if (!$conn) {
    $conn = @ftp_connect($host, $port, 30);
}
if (!$conn) {
    fwrite(STDERR, "Unable to connect to $host:$port\n");
    exit(2);
}
if (!@ftp_login($conn, $user, $pass)) {
    fwrite(STDERR, "FTP login failed for user $user\n");
    @ftp_close($conn);
    exit(3);
}
// Try PASV mode, fallback to active if it fails
if (!@ftp_pasv($conn, true)) {
    fwrite(STDERR, "Warning: PASV mode failed, using active mode\n");
}
// Set timeout for operations
@ftp_set_option($conn, FTP_TIMEOUT_SEC, 60);

echo "Connected to $host. Starting upload...\n";

// Helpers
function norm_path($p) { return str_replace('\\', '/', $p); }
function rel_path($abs, $base) {
    $abs = norm_path($abs); $base = rtrim(norm_path($base), '/');
    if (strpos($abs, $base . '/') === 0) return substr($abs, strlen($base) + 1);
    return $abs;
}
function match_any($path, $patterns) {
    foreach ($patterns as $pat) {
        if (fnmatch($pat, $path, FNM_PATHNAME | FNM_CASEFOLD)) return true;
    }
    return false;
}
function ensure_remote_dir($conn, $remoteDir, $pathDir) {
    $pathDir = trim($pathDir, '/');
    if ($pathDir === '') return true;
    $parts = explode('/', $pathDir);
    $cwd = rtrim($remoteDir, '/');
    // Try to create each component; ignore errors if it exists
    $accum = '';
    foreach ($parts as $part) {
        $accum .= ($accum === '' ? '' : '/') . $part;
        @ftp_mkdir($conn, $cwd . '/' . $accum);
    }
    return true;
}

// Build file list
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localRoot, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($rii as $file) {
    if ($file->isDir()) continue;
    $abs = norm_path($file->getPathname());
    $rel = rel_path($abs, $localRoot);
    // Skip by exclude patterns
    if (match_any($rel, $excludes)) continue;
    // Include filter (if includes present)
    if ($includes && !match_any($rel, $includes)) continue;
    $files[] = $rel;
}
// Add extra files explicitly
foreach ($extras as $x) {
    $xp = norm_path($localRoot . '/' . ltrim($x, '/'));
    if (is_file($xp)) {
        $rel = rel_path($xp, $localRoot);
        if (!in_array($rel, $files, true)) $files[] = $rel;
    }
}

sort($files, SORT_NATURAL | SORT_FLAG_CASE);

$uploaded = 0; $failed = 0; $start = microtime(true);
foreach ($files as $rel) {
    $local = $localRoot . '/' . $rel;
    $remote = $remoteDir . $rel;
    $remoteDirname = dirname($rel);
    ensure_remote_dir($conn, $remoteDir, $remoteDirname);
    
    // Retry logic for uploads
    $maxRetries = 3;
    $ok = false;
    for ($retry = 0; $retry < $maxRetries && !$ok; $retry++) {
        if ($retry > 0) {
            sleep(1); // Wait 1 second before retry
            // Reconnect if needed
            if (!@ftp_pwd($conn)) {
                $conn = ($secure && function_exists('ftp_ssl_connect')) ? @ftp_ssl_connect($host, $port, 30) : @ftp_connect($host, $port, 30);
                if ($conn && @ftp_login($conn, $user, $pass)) {
                    @ftp_pasv($conn, true);
                    @ftp_set_option($conn, FTP_TIMEOUT_SEC, 60);
                }
            }
        }
        $ok = @ftp_put($conn, $remote, $local, FTP_BINARY);
    }
    
    if ($ok) { echo "UPLOADED: $rel\n"; $uploaded++; }
    else { echo "FAILED:   $rel\n"; $failed++; }
}

$elapsed = number_format(microtime(true) - $start, 2);
@ftp_close($conn);
echo "\nDone. Uploaded: $uploaded, Failed: $failed, Time: {$elapsed}s\n";
exit($failed > 0 ? 4 : 0);
