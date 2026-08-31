<?php
/**
 * Generate product label PDF via Python (ReportLab) and return file download.
 */
require_once __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/label-lib.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: label.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');

$rateLimitMessage = sms_label_begin_download_request();
if ($rateLimitMessage !== null) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo $rateLimitMessage;
    exit;
}

$downloadSucceeded = false;

try {
    $productIds = (array) ($_POST['product_ids'] ?? []);
    $quantities = (array) ($_POST['quantities'] ?? []);
    $perPage = sms_label_resolve_per_page($_POST['per_page'] ?? 1);

    $stockBasePath = function_exists('app_url') ? app_url('stock/') : '../stock/';
    $payload = sms_build_label_payload($pdo, $productIds, $quantities, $perPage, $stockBasePath);
    $pdfBinary = sms_generate_label_pdf_binary($payload);

    $filename = 'product-labels-' . date('Y-m-d') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    $downloadSucceeded = true;
    echo $pdfBinary;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
} finally {
    sms_label_finish_download_request($downloadSucceeded);
}
