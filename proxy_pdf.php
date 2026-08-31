<?php
// Proxy script to serve files directly via PHP
// Usage: proxy_pdf.php?file=assets/uploads/vouchers/115/filename.pdf

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once __DIR__ . '/includes/media_path_resolver.php';

$rawFile = (string) ($_GET['file'] ?? '');
$file = ltrim(str_replace('\\', '/', $rawFile), '/');

// Basic security: prevent path traversal
if ($file === '' || strpos($file, '..') !== false) {
    http_response_code(400);
    exit('Invalid path.');
}

$allowedRoots = ['assets/', 'storage/', 'uploads/'];
$isAllowedRoot = false;
foreach ($allowedRoots as $rootPrefix) {
    if (strpos($file, $rootPrefix) === 0) {
        $isAllowedRoot = true;
        break;
    }
}
if (!$isAllowedRoot) {
    http_response_code(400);
    exit('Invalid folder.');
}

$companyId = proxyPdfCompanyIdFromRequest();
$fullPath = resolveStoredMediaFilePath($file, $companyId);

if ($fullPath === '') {
    http_response_code(404);
    echo 'File not found via PHP proxy: ' . htmlspecialchars(mediaPathProjectRoot() . '/' . $file, ENT_QUOTES, 'UTF-8');
    exit;
}

// Dynamically detect MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($fullPath);

// Fallback for generic MIME values
if ($mimeType === 'application/octet-stream' || !$mimeType) {
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp'
    ];
    if (isset($mimes[$ext])) {
        $mimeType = $mimes[$ext];
    }
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
readfile($fullPath);
exit;
