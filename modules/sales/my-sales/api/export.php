<?php

require_once __DIR__ . '/../../includes/my-sales-lib.php';

try {
    mySalesExportPdf();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed: ' . $e->getMessage();
}
