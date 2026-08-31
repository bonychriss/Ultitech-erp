<?php

require_once __DIR__ . '/../includes/po-view-lib.php';

poViewDeskRequireAccess();

header('Content-Type: application/json; charset=utf-8');

try {
    $data = poViewReceiptInfoInitData();
    echo poViewJsonEncode($data);
} catch (Throwable $e) {
    $code = str_contains($e->getMessage(), 'not found') ? 404 : 500;
    http_response_code($code);
    echo poViewJsonEncode(['error' => $e->getMessage()]);
}
