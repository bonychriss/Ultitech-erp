<?php
/**
 * Serve the latest UltiTech ERP Windows desktop installer (Electron build output).
 * Standalone script — does not bootstrap the ERP (avoids tenant slug routing on /client-apps/...).
 */
declare(strict_types=1);

$distDir = __DIR__ . DIRECTORY_SEPARATOR . 'desktop' . DIRECTORY_SEPARATOR . 'dist';
$pattern = $distDir . DIRECTORY_SEPARATOR . 'UltiTech-ERP-Setup-*.exe';
$files = glob($pattern) ?: [];

if ($files === []) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Desktop app</title></head><body style="font-family:sans-serif;padding:2rem;max-width:40rem;">';
    echo '<h1>Desktop app not ready</h1>';
    echo '<p>The Windows installer has not been placed on this server yet.</p>';
    echo '<p><strong>On your dev PC</strong>, build it with:</p>';
    echo '<pre style="background:#f1f5f9;padding:1rem;border-radius:8px;">cd client-apps/desktop&#10;npm install&#10;npm run dist:win</pre>';
    echo '<p>Then copy the file to:</p>';
    echo '<pre style="background:#f1f5f9;padding:1rem;border-radius:8px;">client-apps/desktop/dist/UltiTech-ERP-Setup-1.0.0.exe</pre>';
    echo '<p>On production (cPanel), upload that <code>.exe</code> via FTP/File Manager.</p>';
    echo '</body></html>';
    exit;
}

usort($files, static function (string $a, string $b): int {
    return filemtime($b) <=> filemtime($a);
});

$file = $files[0];
$filename = basename($file);

if (!is_readable($file)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Installer file is not readable.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($file);
exit;
