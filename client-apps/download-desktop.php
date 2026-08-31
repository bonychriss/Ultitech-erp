<?php
/**
 * Serve the latest UltiTech ERP Windows desktop installer (Electron build output).
 */
require_once __DIR__ . '/../includes/config.php';

$distDir = __DIR__ . '/desktop/dist';
$pattern = $distDir . DIRECTORY_SEPARATOR . 'UltiTech-ERP-Setup-*.exe';
$files = glob($pattern) ?: [];

if ($files === []) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Desktop app</title></head><body style="font-family:sans-serif;padding:2rem;max-width:36rem;">';
    echo '<h1>Desktop app not ready</h1>';
    echo '<p>The Windows installer has not been built yet. On the server or dev machine, run:</p>';
    echo '<pre style="background:#f1f5f9;padding:1rem;border-radius:8px;">cd client-apps/desktop\nnpm install\nnpm run dist:win</pre>';
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
