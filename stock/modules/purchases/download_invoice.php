<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

$poId = (int) ($_GET['id'] ?? 0);
$forceDownload = isset($_GET['download']) && (string) $_GET['download'] !== '0';

if ($poId <= 0) {
    http_response_code(400);
    exit('Invalid purchase order.');
}

$invoicePath = '';
$poNumber = '';

try {
    $hasStocksPo = tableExists('stocks_purchase_orders', $pdo);
    if ($hasStocksPo) {
        $stmt = $pdo->prepare('SELECT po_number, invoice_attachment FROM stocks_purchase_orders WHERE id = ? LIMIT 1');
        $stmt->execute([$poId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $invoicePath = trim((string) ($row['invoice_attachment'] ?? ''));
            $poNumber = trim((string) ($row['po_number'] ?? ''));
        }
    }

    if ($invoicePath === '' && tableExists('purchases', $pdo)) {
        $stmt = $pdo->prepare('SELECT purchase_no, invoice_attachment FROM purchases WHERE id = ? LIMIT 1');
        $stmt->execute([$poId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $invoicePath = trim((string) ($row['invoice_attachment'] ?? ''));
            $poNumber = trim((string) ($row['purchase_no'] ?? ''));
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not load invoice.');
}

if ($invoicePath === '') {
    http_response_code(404);
    exit('No supplier invoice attached to this purchase order.');
}

$relative = ltrim(str_replace('\\', '/', $invoicePath), '/');
$candidates = [];

if (preg_match('#^uploads/invoices/#i', $relative)) {
    $candidates[] = realpath(__DIR__ . '/../../' . $relative);
}
$candidates[] = realpath(__DIR__ . '/../../' . $relative);
$candidates[] = realpath(dirname(__DIR__, 3) . '/' . $relative);
$candidates[] = realpath(dirname(__DIR__, 3) . '/assets/' . preg_replace('#^uploads/#i', 'uploads/', $relative));

$absFile = null;
foreach ($candidates as $path) {
    if (is_string($path) && is_file($path)) {
        $absFile = $path;
        break;
    }
}

if ($absFile === null) {
    http_response_code(404);
    exit('Invoice file not found on server.');
}

$stockRoot = realpath(__DIR__ . '/../..');
$publicRoot = realpath(dirname(__DIR__, 3));
$absNorm = str_replace('\\', '/', $absFile);
$allowed = false;
foreach ([$stockRoot, $publicRoot] as $root) {
    if (!is_string($root) || $root === '') {
        continue;
    }
    $rootNorm = str_replace('\\', '/', $root);
    if (strpos($absNorm, rtrim($rootNorm, '/') . '/') === 0 || $absNorm === $rootNorm) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$ext = strtolower(pathinfo($absFile, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

$downloadName = basename($absFile);
if ($poNumber !== '') {
    $safePo = preg_replace('/[^A-Za-z0-9._-]+/', '_', $poNumber) ?: 'PO';
    $downloadName = $safePo . '_supplier_invoice.' . ($ext !== '' ? $ext : 'bin');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absFile));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

if ($forceDownload) {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
} else {
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
}

readfile($absFile);
exit;
