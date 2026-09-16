<?php

declare(strict_types=1);

/**
 * Website quote requests (Roadmaster storefront) — schema + fetch helpers.
 */

function salesQuoteRequestsEnsureSchema(?PDO $pdo = null): void
{
    $db = $pdo instanceof PDO ? $pdo : (function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null));
    if (!($db instanceof PDO)) {
        return;
    }

    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec("
            CREATE TABLE IF NOT EXISTS website_quote_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                quote_number TEXT,
                product_id INTEGER,
                product_sku TEXT,
                product_name TEXT,
                quantity REAL NOT NULL DEFAULT 1,
                customer_name TEXT,
                customer_email TEXT,
                customer_phone TEXT,
                notes TEXT,
                payload TEXT,
                source TEXT DEFAULT 'website',
                status TEXT DEFAULT 'new',
                created_at TEXT
            )
        ");
        return;
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS website_quote_requests (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quote_number VARCHAR(64) NULL,
            product_id INT NULL,
            product_sku VARCHAR(191) NULL,
            product_name VARCHAR(255) NULL,
            quantity DOUBLE NOT NULL DEFAULT 1,
            customer_name VARCHAR(255) NULL,
            customer_email VARCHAR(255) NULL,
            customer_phone VARCHAR(64) NULL,
            notes TEXT NULL,
            payload LONGTEXT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'website',
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            created_at VARCHAR(64) NULL,
            KEY idx_wqr_created (created_at),
            KEY idx_wqr_quote_number (quote_number),
            KEY idx_wqr_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * @param array<string,mixed> $row
 */
function salesQuoteRequestsInsert(PDO $pdo, array $row): int
{
    salesQuoteRequestsEnsureSchema($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO website_quote_requests (
            quote_number, product_id, product_sku, product_name, quantity,
            customer_name, customer_email, customer_phone, notes, payload,
            source, status, created_at
        ) VALUES (
            :quote_number, :product_id, :product_sku, :product_name, :quantity,
            :customer_name, :customer_email, :customer_phone, :notes, :payload,
            :source, :status, :created_at
        )
    ");
    $stmt->execute([
        ':quote_number' => (string) ($row['quote_number'] ?? ''),
        ':product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
        ':product_sku' => (string) ($row['product_sku'] ?? ''),
        ':product_name' => (string) ($row['product_name'] ?? ''),
        ':quantity' => (float) ($row['quantity'] ?? 1),
        ':customer_name' => (string) ($row['customer_name'] ?? ''),
        ':customer_email' => (string) ($row['customer_email'] ?? ''),
        ':customer_phone' => (string) ($row['customer_phone'] ?? ''),
        ':notes' => (string) ($row['notes'] ?? ''),
        ':payload' => is_string($row['payload'] ?? null)
            ? (string) $row['payload']
            : json_encode($row['payload'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
        ':source' => (string) ($row['source'] ?? 'website'),
        ':status' => (string) ($row['status'] ?? 'new'),
        ':created_at' => (string) ($row['created_at'] ?? date('c')),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Pull rows from the Roadmaster Spares Yii sf_quotes table when reachable.
 */
function salesQuoteRequestsSyncFromStorefront(?PDO $companyPdo = null): int
{
    $company = $companyPdo instanceof PDO
        ? $companyPdo
        : (function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null));
    if (!($company instanceof PDO)) {
        return 0;
    }

    salesQuoteRequestsEnsureSchema($company);
    $storefront = salesQuoteRequestsStorefrontPdo();
    if (!($storefront instanceof PDO)) {
        return 0;
    }

    try {
        $storefront->query('SELECT 1 FROM sf_quotes LIMIT 1');
    } catch (Throwable $e) {
        return 0;
    }

    $imported = 0;
    $rows = $storefront->query('
        SELECT quote_number, product_id, product_sku, product_name, quantity,
               customer_name, customer_email, customer_phone, notes, payload, created_at
        FROM sf_quotes
        ORDER BY id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $qn = trim((string) ($row['quote_number'] ?? ''));
        $sku = trim((string) ($row['product_sku'] ?? ''));
        $created = trim((string) ($row['created_at'] ?? ''));
        if ($qn === '') {
            continue;
        }
        $check = $company->prepare('
            SELECT id FROM website_quote_requests
            WHERE quote_number = ? AND COALESCE(product_sku, \'\') = ? AND COALESCE(created_at, \'\') = ?
            LIMIT 1
        ');
        $check->execute([$qn, $sku, $created]);
        if ($check->fetchColumn()) {
            continue;
        }
        salesQuoteRequestsInsert($company, [
            'quote_number' => $qn,
            'product_id' => $row['product_id'] ?? null,
            'product_sku' => $sku,
            'product_name' => $row['product_name'] ?? '',
            'quantity' => $row['quantity'] ?? 1,
            'customer_name' => $row['customer_name'] ?? '',
            'customer_email' => $row['customer_email'] ?? '',
            'customer_phone' => $row['customer_phone'] ?? '',
            'notes' => $row['notes'] ?? '',
            'payload' => $row['payload'] ?? null,
            'source' => 'website',
            'status' => 'new',
            'created_at' => $created !== '' ? $created : date('c'),
        ]);
        $imported++;
    }

    return $imported;
}

function salesQuoteRequestsStorefrontPdo(): ?PDO
{
    static $cached = false;
    static $pdo = null;
    if ($cached) {
        return $pdo instanceof PDO ? $pdo : null;
    }
    $cached = true;

    $candidates = [
        dirname(__DIR__, 4) . '/roadmasterspares/public_html/.env',
        'c:/xampp/htdocs/roadmasterspares/public_html/.env',
        dirname(__DIR__, 3) . '/../roadmasterspares/public_html/.env',
    ];
    $envFile = '';
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $envFile = $path;
            break;
        }
    }
    if ($envFile === '') {
        return null;
    }

    $vars = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $vars[trim($k)] = trim($v, " \t\"'");
    }

    $host = $vars['DB_HOST'] ?? '127.0.0.1';
    $name = $vars['DB_NAME'] ?? '';
    $user = $vars['DB_USER'] ?? 'root';
    $pass = $vars['DB_PASSWORD'] ?? ($vars['DB_PASS'] ?? '');
    if ($name === '') {
        return null;
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (Throwable $e) {
        $pdo = null;
        return null;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function salesQuoteRequestsFetchAll(?PDO $pdo = null): array
{
    $db = $pdo instanceof PDO ? $pdo : (function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null));
    if (!($db instanceof PDO)) {
        return [];
    }
    salesQuoteRequestsEnsureSchema($db);
    try {
        salesQuoteRequestsSyncFromStorefront($db);
    } catch (Throwable $e) {
        // keep listing local rows
    }

    $rows = $db->query('
        SELECT *
        FROM website_quote_requests
        ORDER BY id DESC
        LIMIT 500
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['quantity'] = (float) ($row['quantity'] ?? 1);
        $row['product_id'] = isset($row['product_id']) ? (int) $row['product_id'] : null;
        return $row;
    }, $rows);
}
