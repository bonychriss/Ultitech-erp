<?php
/**
 * Starts the UltiTech shop sync from the website itself, about every 15 minutes,
 * when someone opens ultimate.co.tz. No cPanel cron job is required.
 *
 * Upload next to sync.php:
 *   /home/ultimate/public_html/ultitech/keepalive.php
 *
 * Then add this one line to /home/ultimate/public_html/.user.ini
 * (create that file if it is not there; do not remove lines already in it):
 *
 *   auto_prepend_file="/home/ultimate/public_html/ultitech/keepalive.php"
 *
 * .user.ini can take a few minutes to apply.
 */

if (PHP_SAPI === 'cli') {
    return;
}

$stamp = __DIR__ . '/cache/last-auto-sync.txt';
$dir = __DIR__ . '/cache';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$fp = @fopen($stamp, 'c+');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    if (is_resource($fp)) {
        fclose($fp);
    }
    return;
}

$last = (int) stream_get_contents($fp);
if ((time() - $last) < 900) {
    flock($fp, LOCK_UN);
    fclose($fp);
    return;
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, (string) time());
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

$php = '/usr/local/bin/ea-php82';
if (!is_file($php)) {
    $php = 'php';
}
$script = __DIR__ . '/sync.php';
if (!is_file($script) || !function_exists('exec')) {
    return;
}

@exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
