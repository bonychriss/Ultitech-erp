<?php
/**
 * Local-first quote storage and UltiTech sync for ultimate.co.tz.
 * Included by quote.php, cron.php, and sync.php. Not a public page.
 */
declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const ULTITECH_QUOTE_API = 'https://ultitech.io/api/storefront/quote.php?company_slug=ultimate';

function ultitechCustomerMessage(): string
{
    return 'Your request has been received successfully. Our sales person will contact you shortly.';
}

function ultitechRedact(string $text): string
{
    $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
    $text = preg_replace('/(token|password|secret|key)\s*[:=]\s*\S+/i', '$1 [redacted]', $text) ?? $text;
    return substr($text, 0, 500);
}

function ultitechApiToken(): string
{
    $env = getenv('ULTITECH_API_TOKEN');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (defined('ULTITECH_API_TOKEN')) {
        return (string) ULTITECH_API_TOKEN;
    }
    return '';
}

function ultitechEnsureQuoteSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotes (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        quote_number VARCHAR(40) NOT NULL,
        customer_name VARCHAR(255) NOT NULL,
        customer_phone VARCHAR(64) NOT NULL,
        customer_email VARCHAR(255) NULL,
        customer_notes TEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        sync_status VARCHAR(32) NOT NULL DEFAULT 'pending',
        ultitech_reference VARCHAR(64) NULL,
        last_sync_attempt DATETIME NULL,
        last_sync_error TEXT NULL,
        admin_notes TEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_quote_number (quote_number),
        KEY idx_quotes_status (status),
        KEY idx_quotes_sync (sync_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        product_id INT NULL,
        ultitech_product_id INT NULL,
        product_name VARCHAR(255) NOT NULL,
        sku VARCHAR(191) NULL,
        quantity DECIMAL(12,2) NOT NULL,
        unit_price DECIMAL(20,2) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        KEY idx_quote_items_quote (quote_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_queue (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(32) NOT NULL,
        entity_id INT NOT NULL,
        action VARCHAR(32) NOT NULL,
        payload LONGTEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        last_attempt_at DATETIME NULL,
        next_attempt_at DATETIME NULL,
        last_error TEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        KEY idx_sync_queue_due (status, next_attempt_at),
        KEY idx_sync_queue_entity (entity_type, entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ultitechNextQuoteNumber(PDO $pdo): string
{
    $year = date('Y');
    $prefix = 'QT-ULT-' . $year . '-';
    $st = $pdo->prepare('SELECT quote_number FROM quotes WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = (string) $st->fetchColumn();
    $n = 1;
    if ($last !== '' && preg_match('/(\d+)$/', $last, $m)) {
        $n = ((int) $m[1]) + 1;
    }
    return $prefix . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/**
 * @param array<string,mixed> $input
 * @return array{id:int,quote_number:string}
 */
function ultitechSaveQuote(PDO $pdo, array $input): array
{
    ultitechEnsureQuoteSchema($pdo);
    $name = trim((string) ($input['customer_name'] ?? ''));
    $phone = trim((string) ($input['customer_phone'] ?? ''));
    $email = trim((string) ($input['customer_email'] ?? ''));
    $notes = trim((string) ($input['customer_notes'] ?? $input['notes'] ?? ''));
    $items = $input['items'] ?? [];
    if ($name === '' || $phone === '') {
        throw new InvalidArgumentException('Please enter your name and phone.');
    }
    if (!is_array($items) || $items === []) {
        throw new InvalidArgumentException('Add at least one product to the quote.');
    }

    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $number = ultitechNextQuoteNumber($pdo);
        $ins = $pdo->prepare('INSERT INTO quotes (
            quote_number, customer_name, customer_phone, customer_email, customer_notes,
            status, sync_status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, \'pending\', \'pending\', ?, ?)');
        $ins->execute([$number, $name, $phone, $email !== '' ? $email : null, $notes !== '' ? $notes : null, $now, $now]);
        $quoteId = (int) $pdo->lastInsertId();
        $itemIns = $pdo->prepare('INSERT INTO quote_items (
            quote_id, product_id, ultitech_product_id, product_name, sku, quantity, unit_price, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $saved = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? 0);
            if ($qty <= 0 || $qty > 1000000) {
                continue;
            }
            $websiteId = (int) ($item['website_product_id'] ?? $item['product_id'] ?? 0);
            $productName = trim((string) ($item['product_name'] ?? $item['name'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? ''));
            $price = isset($item['unit_price']) ? (float) $item['unit_price'] : null;
            $ultiId = 0;
            if ($websiteId > 0) {
                try {
                    $link = $pdo->prepare('SELECT ultitech_product_id, sku FROM ultitech_links WHERE website_product_id = ? LIMIT 1');
                    $link->execute([$websiteId]);
                    $row = $link->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $ultiId = (int) ($row['ultitech_product_id'] ?? 0);
                        if ($sku === '') {
                            $sku = (string) ($row['sku'] ?? '');
                        }
                    }
                    $prod = $pdo->prepare('SELECT name, unit_price, barcode FROM products WHERE id = ? LIMIT 1');
                    $prod->execute([$websiteId]);
                    $product = $prod->fetch(PDO::FETCH_ASSOC);
                    if (is_array($product)) {
                        if ($productName === '') {
                            $productName = (string) $product['name'];
                        }
                        if ($price === null) {
                            $price = (float) $product['unit_price'];
                        }
                        if ($sku === '' && !empty($product['barcode'])) {
                            $sku = (string) $product['barcode'];
                        }
                    }
                } catch (Throwable $e) {
                    $ultiId = 0;
                }
            }
            if ($productName === '') {
                $productName = 'Product';
            }
            $itemIns->execute([
                $quoteId,
                $websiteId > 0 ? $websiteId : null,
                $ultiId > 0 ? $ultiId : null,
                $productName,
                $sku !== '' ? $sku : null,
                $qty,
                $price,
                $now,
                $now,
            ]);
            $saved++;
        }
        if ($saved === 0) {
            throw new InvalidArgumentException('Add at least one product to the quote.');
        }
        $pdo->prepare('INSERT INTO sync_queue (
            entity_type, entity_id, action, status, attempts, next_attempt_at, created_at, updated_at
        ) VALUES (\'quote\', ?, \'push\', \'pending\', 0, ?, ?, ?)')->execute([$quoteId, $now, $now, $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    ultitechNotifyAdmin($pdo, $quoteId, $number, $name, $saved);
    return ['id' => $quoteId, 'quote_number' => $number];
}

function ultitechNotifyAdmin(PDO $pdo, int $quoteId, string $number, string $name, int $count): void
{
    try {
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE user_type = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
        $typeId = (int) $pdo->query("SELECT id FROM notification_types WHERE user_type = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($adminId <= 0 || $typeId <= 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO notifications (
            id, notification_type_id, type, notifiable_type, notifiable_id, data, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0, 65535), random_int(0, 65535), random_int(0, 65535), random_int(16384, 20479), random_int(32768, 49151), random_int(0, 65535), random_int(0, 65535), random_int(0, 65535)),
            $typeId,
            'App\\Notifications\\QuoteRequestNotification',
            'App\\Models\\User',
            $adminId,
            json_encode([
                'quote_number' => $number,
                'message' => $name . ' requested ' . $count . ' product' . ($count === 1 ? '' : 's') . '.',
            ], JSON_UNESCAPED_UNICODE),
            $now,
            $now,
        ]);
    } catch (Throwable $e) {
        return;
    }
}

function ultitechSyncQuote(PDO $pdo, int $quoteId): bool
{
    ultitechEnsureQuoteSchema($pdo);
    $st = $pdo->prepare('SELECT * FROM quotes WHERE id = ? LIMIT 1');
    $st->execute([$quoteId]);
    $quote = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($quote)) {
        return false;
    }
    if ((string) $quote['sync_status'] === 'synced') {
        return true;
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE quotes SET sync_status = \'syncing\', last_sync_attempt = ?, updated_at = ? WHERE id = ?')
        ->execute([$now, $now, $quoteId]);
    $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id ASC');
    $items->execute([$quoteId]);
    $payloadItems = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $row = [
            'product_name' => (string) $item['product_name'],
            'product_sku' => (string) ($item['sku'] ?? ''),
            'quantity' => (float) $item['quantity'],
        ];
        if (!empty($item['ultitech_product_id'])) {
            $row['product_id'] = (int) $item['ultitech_product_id'];
        }
        $payloadItems[] = $row;
    }
    $payload = [
        'quote_number' => (string) $quote['quote_number'],
        'customer_name' => (string) $quote['customer_name'],
        'customer_email' => (string) ($quote['customer_email'] ?? ''),
        'customer_phone' => (string) $quote['customer_phone'],
        'notes' => (string) ($quote['customer_notes'] ?? ''),
        'items' => $payloadItems,
    ];
    $token = ultitechApiToken();
    $error = 'UltiTech sync is not configured.';
    $ok = false;
    $reference = '';
    if ($token !== '' && function_exists('curl_init')) {
        $ch = curl_init(ULTITECH_QUOTE_API);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        $json = json_decode(is_string($body) ? $body : '', true);
        if ($errno === 0 && is_array($json) && !empty($json['success'])) {
            $ok = true;
            $reference = (string) ($json['quote_number'] ?? $quote['quote_number']);
        } else {
            $error = is_array($json) ? (string) ($json['error'] ?? 'Sync failed') : 'Sync failed';
        }
    }
    $done = date('Y-m-d H:i:s');
    if ($ok) {
        $pdo->prepare('UPDATE quotes SET sync_status = \'synced\', ultitech_reference = ?, last_sync_error = NULL, updated_at = ? WHERE id = ?')
            ->execute([$reference, $done, $quoteId]);
        $pdo->prepare('UPDATE sync_queue SET status = \'synced\', last_error = NULL, updated_at = ? WHERE entity_type = \'quote\' AND entity_id = ? AND status <> \'synced\'')
            ->execute([$done, $quoteId]);
        return true;
    }
    $safe = ultitechRedact($error);
    $pdo->prepare('UPDATE quotes SET sync_status = \'failed\', last_sync_error = ?, updated_at = ? WHERE id = ?')
        ->execute([$safe, $done, $quoteId]);
    $pdo->prepare('UPDATE sync_queue SET status = \'pending\', attempts = attempts + 1, last_attempt_at = ?, next_attempt_at = DATE_ADD(?, INTERVAL 15 MINUTE), last_error = ?, updated_at = ? WHERE entity_type = \'quote\' AND entity_id = ? AND status <> \'synced\'')
        ->execute([$done, $done, $safe, $done, $quoteId]);
    return false;
}

function ultitechProcessQueue(PDO $pdo, int $limit = 20): int
{
    ultitechEnsureQuoteSchema($pdo);
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT id, entity_id FROM sync_queue WHERE entity_type = \'quote\' AND status = \'pending\' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id ASC LIMIT ' . (int) $limit);
    $st->execute([$now]);
    $done = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (ultitechSyncQuote($pdo, (int) $row['entity_id'])) {
            $done++;
        }
    }
    return $done;
}
