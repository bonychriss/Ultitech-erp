<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$lib = dirname(__DIR__, 2) . '/modules/sales/quote-requests/includes/quote-requests-lib.php';
if (!is_file($lib)) {
    storefrontApiJson(['success' => false, 'error' => 'Quote requests library missing'], 500);
}
require_once $lib;

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        storefrontApiJson(['success' => false, 'error' => 'POST required'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($body)) {
        storefrontApiJson(['success' => false, 'error' => 'Invalid JSON payload'], 400);
    }

    $pdo = storefrontApiPdo();
    salesQuoteRequestsEnsureSchema($pdo);

    $quoteNumber = trim((string) ($body['quote_number'] ?? ''));
    $name = trim((string) ($body['customer_name'] ?? ''));
    $email = trim((string) ($body['customer_email'] ?? ''));
    $phone = trim((string) ($body['customer_phone'] ?? ''));
    $notes = trim((string) ($body['notes'] ?? ''));
    $createdAt = trim((string) ($body['created_at'] ?? date('c')));
    if ($createdAt === '') {
        $createdAt = date('c');
    }

    $items = [];
    if (!empty($body['items']) && is_array($body['items'])) {
        foreach ($body['items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $row;
        }
    } else {
        $items[] = $body;
    }

    if ($items === []) {
        storefrontApiJson(['success' => false, 'error' => 'No items provided'], 400);
    }
    if ($quoteNumber === '') {
        $quoteNumber = 'QT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    $ids = [];
    foreach ($items as $item) {
        $ids[] = salesQuoteRequestsInsert($pdo, [
            'quote_number' => $quoteNumber,
            'product_id' => $item['product_id'] ?? null,
            'product_sku' => $item['product_sku'] ?? ($item['sku'] ?? ''),
            'product_name' => $item['product_name'] ?? ($item['name'] ?? ''),
            'quantity' => $item['quantity'] ?? 1,
            'customer_name' => $name !== '' ? $name : ($item['customer_name'] ?? ''),
            'customer_email' => $email !== '' ? $email : ($item['customer_email'] ?? ''),
            'customer_phone' => $phone !== '' ? $phone : ($item['customer_phone'] ?? ''),
            'notes' => $notes !== '' ? $notes : ($item['notes'] ?? ''),
            'payload' => $item,
            'source' => 'website',
            'status' => 'new',
            'created_at' => $createdAt,
        ]);
    }

    storefrontApiJson([
        'success' => true,
        'quote_number' => $quoteNumber,
        'ids' => $ids,
        'count' => count($ids),
    ]);
} catch (Throwable $e) {
    storefrontApiJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
}
