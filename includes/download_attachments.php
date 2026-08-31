<?php
require_once __DIR__ . '/functions.php';
requireLogin();

// Inputs
$voucherId = isset($_GET['voucher_id']) ? (int)$_GET['voucher_id'] : 0;
$scope = isset($_GET['scope']) ? strtolower(trim($_GET['scope'])) : 'all'; // all|supporting|swift
if (!in_array($scope, ['all','supporting','swift'], true)) { $scope = 'all'; }

if ($voucherId <= 0) {
    http_response_code(400);
    echo 'Invalid voucher id';
    exit;
}

// Fetch voucher info
$stmt = $pdo->prepare("SELECT id, voucher_no, swift_document FROM payment_vouchers WHERE id = ? LIMIT 1");
$stmt->execute([$voucherId]);
$voucher = $stmt->fetch();
if (!$voucher) {
    http_response_code(404);
    echo 'Voucher not found';
    exit;
}

// Collect file list
// Collect files with basic path traversal protection (reject any '..')
$files = [];
$baseDir = realpath(dirname(__DIR__));
if ($scope === 'all' || $scope === 'supporting') {
    $atts = getVoucherAttachments($voucherId);
    foreach ($atts as $a) {
        $rel = $a['file_path'];
        if (!$rel || strpos($rel, '..') !== false) continue; // prevent traversal
        $abs = realpath($baseDir . DIRECTORY_SEPARATOR . $rel);
        if (!$abs) continue;
        // Ensure file lives within project (uploads area) to avoid leaking arbitrary files
        if (strpos($abs, $baseDir) !== 0) continue;
        $files[] = [
            'abs' => $abs,
            'name' => $a['original_name'] ?: basename($rel),
            'rel' => $rel,
        ];
    }
}
if (($scope === 'all' || $scope === 'swift') && !empty($voucher['swift_document'])) {
    $rel = $voucher['swift_document'];
    if ($rel && strpos($rel, '..') === false) {
        $abs = realpath($baseDir . DIRECTORY_SEPARATOR . $rel);
        if ($abs && strpos($abs, $baseDir) === 0) {
            $files[] = [
                'abs' => $abs,
                'name' => 'SWIFT-' . ($voucher['voucher_no'] ?: ('voucher-' . $voucherId)) . '-' . basename($rel),
                'rel' => $rel,
            ];
        }
    }
}

// Filter out missing files (secondary safety)
$files = array_values(array_filter($files, function($f){ return !empty($f['abs']) && is_file($f['abs']); }));

if (empty($files)) {
    http_response_code(404);
    echo 'No files available to download.';
    exit;
}

// Build ZIP
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'ZIP functionality is not available on this server (ZipArchive missing).';
    exit;
}

$zip = new ZipArchive();
$zipFile = tempnam(sys_get_temp_dir(), 'pvzip_');
if ($zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP archive.';
    exit;
}

foreach ($files as $f) {
    // Ensure a clean entry name in the zip
    $entry = $f['name'];
    // If duplicates, append a counter
    $base = pathinfo($entry, PATHINFO_FILENAME);
    $ext = pathinfo($entry, PATHINFO_EXTENSION);
    $candidate = $entry;
    $i = 2;
    while ($zip->locateName($candidate) !== false) {
        $candidate = $base . " (" . $i . ")" . ($ext ? "." . $ext : '');
        $i++;
    }
    $zip->addFile($f['abs'], $candidate);
}
$zip->close();

// Stream ZIP
$dlScopeLabel = ($scope === 'swift') ? 'swift-proof' : ($scope === 'supporting' ? 'supporting-docs' : 'all-attachments');
$dlName = 'Voucher-' . preg_replace('/[^A-Za-z0-9\-\/]/', '', ($voucher['voucher_no'] ?: ('ID-' . $voucherId))) . '-' . $dlScopeLabel . '.zip';
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zipFile));
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('X-Content-Type-Options: nosniff');
readfile($zipFile);
@unlink($zipFile);
exit;
