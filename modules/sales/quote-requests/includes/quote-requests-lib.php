<?php

declare(strict_types=1);

/**
 * Website quote requests (Roadmaster storefront) � schema + fetch helpers.
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
 * Deleted requests keep their rows so the storefront sync does not import them again.
 */
function salesQuoteRequestsDelete(PDO $pdo, array $quoteNumbers): int
{
    $quoteNumbers = array_values(array_unique(array_filter(array_map('strval', $quoteNumbers), 'strlen')));
    if ($quoteNumbers === []) {
        return 0;
    }
    salesQuoteRequestsEnsureSchema($pdo);
    $marks = implode(', ', array_fill(0, count($quoteNumbers), '?'));
    $stmt = $pdo->prepare('UPDATE website_quote_requests SET status = \'deleted\' WHERE quote_number IN (' . $marks . ') AND COALESCE(status, \'\') <> \'deleted\'');
    $stmt->execute($quoteNumbers);

    return $stmt->rowCount();
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
        WHERE COALESCE(status, \'\') <> \'deleted\'
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

function salesQuoteRequestsAccessSettingKey(): string
{
    return 'website_quote_access_user_ids';
}

/**
 * @return list<int>
 */
function salesQuoteRequestsAllowedUserIds(): array
{
    if (!function_exists('getCompanySetting')) {
        return [];
    }
    $raw = getCompanySetting(salesQuoteRequestsAccessSettingKey(), '[]');
    if (is_array($raw)) {
        $decoded = $raw;
    } else {
        $decoded = json_decode((string) $raw, true);
    }
    if (!is_array($decoded)) {
        return [];
    }
    $ids = [];
    foreach ($decoded as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * @param list<int|string> $userIds
 */
function salesQuoteRequestsSetAllowedUserIds(array $userIds): bool
{
    if (!function_exists('setCompanySetting')) {
        return false;
    }
    $ids = [];
    foreach ($userIds as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $json = json_encode(array_values($ids), JSON_UNESCAPED_SLASHES);

    return setCompanySetting(salesQuoteRequestsAccessSettingKey(), $json !== false ? $json : '[]');
}

function salesQuoteRequestsRoleIsAdmin(string $role): bool
{
    $role = strtolower(trim($role));
    if ($role === '') {
        return false;
    }
    $roleAdmin = defined('ROLE_ADMIN') ? (string) ROLE_ADMIN : 'admin';
    $roleCoAdmin = defined('ROLE_COMPANY_ADMIN') ? (string) ROLE_COMPANY_ADMIN : 'company_admin';

    return $role === strtolower($roleAdmin)
        || $role === strtolower($roleCoAdmin)
        || in_array($role, [
            'admin', 'administrator', 'superadmin', 'super_admin',
            'company_admin', 'company admin', 'owner', 'system_admin', 'platform_admin',
        ], true);
}

function salesQuoteRequestsUserCanAccess(?int $userId = null): bool
{
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }
    if (function_exists('isSuperAdmin') && isSuperAdmin()) {
        return true;
    }
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    if ($role !== '' && salesQuoteRequestsRoleIsAdmin($role)) {
        return true;
    }

    return in_array($uid, salesQuoteRequestsAllowedUserIds(), true);
}

/**
 * @return list<array{id:int,name:string,email:string,role:string,is_admin:bool}>
 */
function salesQuoteRequestsCompanyUsers(?PDO $pdo = null): array
{
    $db = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
    if (!($db instanceof PDO)) {
        return [];
    }
    try {
        $hasStatus = function_exists('columnExists') && columnExists('users', 'status', $db);
        $hasFullName = function_exists('columnExists') && columnExists('users', 'full_name', $db);
        $nameExpr = $hasFullName
            ? "COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(username), ''), NULLIF(TRIM(email), ''), CONCAT('User #', id))"
            : "COALESCE(NULLIF(TRIM(username), ''), NULLIF(TRIM(email), ''), CONCAT('User #', id))";
        $sql = "SELECT id, {$nameExpr} AS display_name, COALESCE(email, '') AS email, COALESCE(role, '') AS role
                FROM users";
        if ($hasStatus) {
            $sql .= " WHERE LOWER(TRIM(COALESCE(status, 'active'))) NOT IN ('disabled', 'inactive', 'deleted', 'suspended')";
        }
        $sql .= ' ORDER BY display_name ASC, id ASC LIMIT 500';
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $role = (string) ($row['role'] ?? '');
        $out[] = [
            'id' => $id,
            'name' => (string) ($row['display_name'] ?? ('User #' . $id)),
            'email' => (string) ($row['email'] ?? ''),
            'role' => $role,
            'is_admin' => salesQuoteRequestsRoleIsAdmin($role),
        ];
    }

    return $out;
}

/**
 * @return list<int>
 */
function salesQuoteRequestsNotifyRecipientIds(?PDO $pdo = null): array
{
    $ids = [];
    foreach (salesQuoteRequestsAllowedUserIds() as $id) {
        $ids[$id] = $id;
    }
    foreach (salesQuoteRequestsCompanyUsers($pdo) as $user) {
        if (!empty($user['is_admin'])) {
            $id = (int) ($user['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}

function salesQuoteRequestsNotifyLink(string $quoteNumber = ''): string
{
    $path = 'website/quotes';
    $qn = trim($quoteNumber);
    if ($qn !== '') {
        $path .= '?quote=' . rawurlencode(substr($qn, 0, 64));
    }
    if (function_exists('company_url')) {
        return company_url($path);
    }
    if (function_exists('app_url')) {
        return app_url('/' . ltrim($path, '/'));
    }

    return '/' . ltrim($path, '/');
}

function salesQuoteRequestsNotifyNew(string $quoteNumber, string $customerName = '', int $itemCount = 0, ?PDO $pdo = null): void
{
    $quoteNumber = trim($quoteNumber);
    if ($quoteNumber === '') {
        return;
    }
    $customerName = trim($customerName);
    $itemCount = max(0, $itemCount);
    $title = 'New website quote request';
    $parts = [$quoteNumber];
    if ($customerName !== '') {
        $parts[] = $customerName;
    }
    if ($itemCount > 0) {
        $parts[] = $itemCount === 1 ? '1 item' : ($itemCount . ' items');
    }
    $message = implode(' � ', $parts);
    $link = salesQuoteRequestsNotifyLink($quoteNumber);

    $recipients = salesQuoteRequestsNotifyRecipientIds($pdo);
    if ($recipients === []) {
        return;
    }

    foreach ($recipients as $userId) {
        if (function_exists('createSystemNotification')) {
            createSystemNotification($userId, $title, $message, $link, 'info');
            continue;
        }
        if (function_exists('createNotification')) {
            createNotification([
                'user_id' => $userId,
                'audience' => 'user',
                'title' => $title,
                'message' => $message,
                'type' => 'info',
            ]);
        }
    }
}
