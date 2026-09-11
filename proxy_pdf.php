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
    header('Content-Type: text/html; charset=utf-8');
    $safeName = htmlspecialchars(basename($file), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Attachment missing</title>'
        . '<style>body{font-family:Segoe UI,sans-serif;background:#f8fafc;color:#0f172a;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}'
        . '.box{max-width:28rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.4rem;box-shadow:0 8px 24px rgba(15,23,42,.06)}'
        . 'h1{font-size:1.15rem;margin:0 0 .5rem}p{margin:0;color:#64748b;line-height:1.5}.name{margin-top:.75rem;font-weight:600;color:#0f172a;word-break:break-all}</style>'
        . '</head><body><div class="box"><h1>Attachment file not found</h1>'
        . '<p>This voucher still has an attachment record, but the file is not present on this server. Re-upload it, or sync uploads from the live environment.</p>'
        . '<div class="name">' . $safeName . '</div></div></body></html>';
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
