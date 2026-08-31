<?php
/**
 * Bridge between CRM Market and the Client Market (Ultimate Online Platform) MySQL store.
 */
declare(strict_types=1);

/**
 * @return array{host:string,port:int,user:string,password:string,database:string}
 */
function crmMarketMysqlConfig(): array
{
    $fallback = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'new_lead-35313030a221',
        'password' => 'xz%lUalV.QS!',
        'database' => 'new_lead-35313030a221',
    ];

    $crmRoot = dirname(__DIR__);
    $configFiles = [
        $crmRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql-config.json',
        $crmRoot . DIRECTORY_SEPARATOR . 'client-market' . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql-config.json',
        $crmRoot . DIRECTORY_SEPARATOR . 'client Market' . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql-config.json',
    ];

    $decoded = null;
    foreach ($configFiles as $configFile) {
        if (!is_file($configFile)) {
            continue;
        }
        $raw = @file_get_contents($configFile);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($json)) {
            $decoded = $json;
            break;
        }
    }

    $cfg = $fallback;
    if (is_array($decoded)) {
        $cfg = array_merge($fallback, [
            'host' => (string) ($decoded['host'] ?? $fallback['host']),
            'port' => (int) ($decoded['port'] ?? $fallback['port']),
            'user' => (string) ($decoded['user'] ?? $fallback['user']),
            'password' => (string) ($decoded['password'] ?? $fallback['password']),
            'database' => (string) ($decoded['database'] ?? $fallback['database']),
        ]);
        $prodHost = trim((string) ($decoded['productionHost'] ?? $decoded['production_host'] ?? ''));
        if ($prodHost !== '' && !crmMarketIsLocalHttpHost()) {
            $cfg['host'] = $prodHost;
        }
    }

    if (!crmMarketIsLocalHttpHost()) {
        $envHost = crmMarketEnvDbHost();
        $loopback = in_array(strtolower($cfg['host']), ['127.0.0.1', 'localhost', '::1'], true);
        if ($loopback && $envHost !== '') {
            $cfg['host'] = $envHost;
        }
    }

    return $cfg;
}

function crmMarketIsLocalHttpHost(): bool
{
    $host = strtolower(trim(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]));
    return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
        || str_ends_with($host, '.local') || str_contains($host, 'localhost');
}

function crmMarketEnvDbHost(): string
{
    foreach (['DB_HOST', 'ROADMASTER_DB_HOST'] as $key) {
        if (!empty($GLOBALS[$key]) && is_string($GLOBALS[$key])) {
            $host = trim($GLOBALS[$key]);
            if ($host !== '') {
                return $host;
            }
        }
    }
    return '';
}

function crmMarketIsLoopbackUrl(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
}

function crmMarketPublicEmbedUrl(): string
{
    if (function_exists('crmDeskPublicUrl')) {
        $path = rtrim(crmDeskPublicUrl('market-app/proxy.php'), '/');
    } else {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $path = '/modules/crm/market-app/proxy.php';
        if (preg_match('#^(.*?/modules/crm)(?:/|$)#i', $script, $m)) {
            $path = rtrim($m[1], '/') . '/market-app/proxy.php';
        }
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return rtrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'))[0]);
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

function crmMarketAppUrl(): string
{
    $url = '';
    try {
        global $pdo;
        if ($pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'crm_market_app_url' LIMIT 1");
            $stmt->execute();
            $url = trim((string) ($stmt->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) {
        $url = '';
    }

    if ($url !== '' && !crmMarketIsLoopbackUrl($url)) {
        return rtrim($url, '/');
    }

    return rtrim(crmMarketPublicEmbedUrl(), '/');
}

/**
 * @return PDO|null
 */
function crmMarketPdo(bool $ensureSchema = true): ?PDO
{
    static $cached = null;
    static $failed = false;

    if ($failed) {
        return null;
    }
    if ($cached instanceof PDO) {
        return $cached;
    }

    $cfg = crmMarketMysqlConfig();
    $dbName = preg_replace('/[^a-zA-Z0-9_-]/', '', $cfg['database']) ?: 'new_lead-35313030a221';
    $hosts = array_values(array_unique(array_filter([
        $cfg['host'],
        'localhost',
        '127.0.0.1',
        crmMarketEnvDbHost(),
    ])));
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $admin = null;
    foreach ($hosts as $host) {
        try {
            $admin = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $cfg['port'], $dbName),
                $cfg['user'],
                $cfg['password'],
                $options
            );
            $cfg['host'] = $host;
            break;
        } catch (Throwable $e) {
            $admin = null;
        }
        try {
            $admin = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $cfg['port']),
                $cfg['user'],
                $cfg['password'],
                $options
            );
            $cfg['host'] = $host;
            break;
        } catch (Throwable $e) {
            $admin = null;
        }
    }

    if (!($admin instanceof PDO)) {
        $failed = true;
        return null;
    }

    try {
        if ($ensureSchema) {
            try {
                $admin->exec(
                    "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
            } catch (Throwable $e) {
                /* Limited users cannot CREATE DATABASE; the target schema must already exist. */
            }
            try {
                $admin->exec("USE `{$dbName}`");
            } catch (Throwable $e) {
                /* Already selected via DSN. */
            }
            $admin->exec("
                CREATE TABLE IF NOT EXISTS customers (
                  id VARCHAR(191) PRIMARY KEY,
                  username VARCHAR(191) NOT NULL,
                  name VARCHAR(255) NOT NULL,
                  category VARCHAR(191) NOT NULL,
                  location VARCHAR(191) NULL,
                  email VARCHAR(191) NULL,
                  phone VARCHAR(64) NULL,
                  website VARCHAR(255) NULL,
                  score INT NOT NULL DEFAULT 0,
                  level VARCHAR(32) NOT NULL,
                  source VARCHAR(191) NOT NULL,
                  keyword VARCHAR(191) NULL,
                  found_at DATETIME NOT NULL,
                  channel VARCHAR(32) NOT NULL DEFAULT 'apify',
                  emailed_at DATETIME NULL,
                  assigned_to INT NULL,
                  assigned_user_name VARCHAR(191) NULL,
                  company_id INT NOT NULL DEFAULT 0,
                  UNIQUE KEY uq_company_channel_user (company_id, channel, username),
                  KEY idx_customers_company (company_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        try {
            $admin->exec("ALTER TABLE customers ADD COLUMN assigned_to INT NULL");
        } catch (Throwable $e) {}
        try {
            $admin->exec("ALTER TABLE customers ADD COLUMN assigned_user_name VARCHAR(191) NULL");
        } catch (Throwable $e) {}
        crmMarketEnsureCompanyScopeSchema($admin);
        try {
            $admin->exec("
                CREATE TABLE IF NOT EXISTS search_history (
                  id VARCHAR(191) PRIMARY KEY,
                  search_text VARCHAR(255) NOT NULL,
                  location VARCHAR(191) NULL,
                  category VARCHAR(191) NULL,
                  result_count INT NOT NULL DEFAULT 0,
                  inserted_count INT NOT NULL DEFAULT 0,
                  skipped_count INT NOT NULL DEFAULT 0,
                  created_at DATETIME NOT NULL,
                  company_id INT NOT NULL DEFAULT 0,
                  KEY idx_search_history_company (company_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $admin->exec("
                CREATE TABLE IF NOT EXISTS search_results (
                  id VARCHAR(191) NOT NULL,
                  history_id VARCHAR(191) NOT NULL,
                  sort_order INT NOT NULL DEFAULT 0,
                  name VARCHAR(255) NOT NULL,
                  type VARCHAR(191) NULL,
                  city VARCHAR(191) NULL,
                  address TEXT NULL,
                  phone VARCHAR(64) NULL,
                  website VARCHAR(255) NULL,
                  email VARCHAR(191) NULL,
                  rating DECIMAL(4,2) NULL,
                  assigned_to INT NULL,
                  assigned_user_name VARCHAR(191) NULL,
                  company_id INT NOT NULL DEFAULT 0,
                  PRIMARY KEY (history_id, id),
                  KEY idx_search_results_history (history_id),
                  KEY idx_search_results_company (company_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $admin->exec("
                CREATE TABLE IF NOT EXISTS search_history_views (
                  user_id INT NOT NULL,
                  history_id VARCHAR(191) NOT NULL,
                  viewed_at DATETIME NOT NULL,
                  PRIMARY KEY (user_id, history_id),
                  KEY idx_search_history_views_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {}
        crmMarketEnsureCompanyScopeSchema($admin);

        try {
            $admin->exec("USE `{$dbName}`");
        } catch (Throwable $e) {}

        $cached = $admin;
        return $cached;
    } catch (Throwable $e) {
        $failed = true;
        return null;
    }
}

/**
 * Ensure Market tables are scoped by ERP company_id.
 */
function crmMarketEnsureCompanyScopeSchema(PDO $admin): void
{
    foreach (['customers', 'search_history', 'search_results'] as $table) {
        try {
            $admin->exec("ALTER TABLE `{$table}` ADD COLUMN company_id INT NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            /* column exists */
        }
        try {
            $admin->exec("ALTER TABLE `{$table}` ADD INDEX idx_{$table}_company (company_id)");
        } catch (Throwable $e) {
            /* index exists */
        }
    }
    try {
        $admin->exec('ALTER TABLE customers DROP INDEX uq_channel_user');
    } catch (Throwable $e) {
        /* old unique may already be gone */
    }
    try {
        $admin->exec('ALTER TABLE customers ADD UNIQUE KEY uq_company_channel_user (company_id, channel, username)');
    } catch (Throwable $e) {
        /* unique exists */
    }
    crmMarketBackfillLegacyCompanyScope($admin);
}

/**
 * Pre-isolation Market rows used company_id = 0. Assign them to the first
 * (default) ERP company so that company's saved searches reappear.
 */
function crmMarketLegacyOwnerCompanyId(): int
{
    if (function_exists('defaultCompanyId')) {
        $id = (int) (defaultCompanyId() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

function crmMarketBackfillLegacyCompanyScope(PDO $admin, int $forceOwnerId = 0): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $ownerId = $forceOwnerId > 0 ? $forceOwnerId : crmMarketLegacyOwnerCompanyId();
    if ($ownerId <= 0) {
        return;
    }
    try {
        $pending = (int) $admin->query(
            'SELECT COUNT(*) FROM search_history WHERE company_id = 0'
        )->fetchColumn();
        $pending += (int) $admin->query(
            'SELECT COUNT(*) FROM customers WHERE company_id = 0'
        )->fetchColumn();
        if ($pending <= 0) {
            $done = true;
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    foreach (['search_history', 'search_results', 'customers'] as $table) {
        try {
            $stmt = $admin->prepare("UPDATE `{$table}` SET company_id = ? WHERE company_id = 0");
            $stmt->execute([$ownerId]);
        } catch (Throwable $e) {
            /* keep going — unique collisions on customers are rare for legacy 0 */
        }
    }
    $done = true;
}

/**
 * @return array{connected:bool,database:string,app_url:string,lead_count:int,message:string}
 */
function crmMarketStatus(int $companyId = 0): array
{
    $cfg = crmMarketMysqlConfig();
    $pdo = crmMarketPdo(true);
    $count = 0;
    $connected = $pdo instanceof PDO;
    $message = $connected
        ? 'Client Market database is ready.'
        : 'Could not connect to Client Market MySQL. Start XAMPP MySQL, then open Client Market once to finish setup.';

    if ($connected) {
        try {
            if ($companyId > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE company_id = ?');
                $stmt->execute([$companyId]);
                $count = (int) $stmt->fetchColumn();
            } else {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
            }
        } catch (Throwable $e) {
            $connected = false;
            $message = 'Client Market tables are missing. Open Client Market to initialize them.';
        }
    }

    return [
        'connected' => $connected,
        'database' => $cfg['database'],
        'app_url' => crmMarketAppUrl(),
        'lead_count' => $count,
        'message' => $message,
    ];
}

/**
 * @return array{assigned_to:int,assigned_user_name:string}|null
 */
function crmMarketLookupAssignment(PDO $erpPdo, string $name, string $email = '', string $phone = ''): ?array
{
    $norm = static function (string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    };

    $keys = array_values(array_filter([
        $norm($name),
        strtolower(trim($email)),
        preg_replace('/\D/', '', $phone) ?: '',
    ]));

    if (empty($keys)) {
        return null;
    }

    try {
        $stmt = $erpPdo->query("
            SELECT c.company_name, c.created_by, u.full_name, u.username
            FROM customers c
            INNER JOIN users u ON u.id = c.created_by
            WHERE c.company_name IS NOT NULL AND TRIM(c.company_name) <> ''
              AND LOWER(TRIM(u.department)) = 'sales'
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $companyKey = $norm((string) ($row['company_name'] ?? ''));
            foreach ($keys as $key) {
                if ($key !== '' && ($companyKey === $key || str_contains($companyKey, $key) || str_contains($key, $companyKey))) {
                    $owner = trim((string) ($row['full_name'] ?? ''));
                    if ($owner === '') {
                        $owner = (string) ($row['username'] ?? '');
                    }
                    if ($owner !== '' && (int) ($row['created_by'] ?? 0) > 0) {
                        return [
                            'assigned_to' => (int) $row['created_by'],
                            'assigned_user_name' => $owner,
                        ];
                    }
                }
            }
        }

        $stmt = $erpPdo->query("
            SELECT cc.organization, cc.name, cc.email, cc.phone, cc.created_by, u.full_name, u.username
            FROM crm_contacts cc
            INNER JOIN users u ON u.id = cc.created_by
            WHERE LOWER(TRIM(u.department)) = 'sales'
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $candidates = [
                $norm((string) ($row['organization'] ?? '')),
                $norm((string) ($row['name'] ?? '')),
                strtolower(trim((string) ($row['email'] ?? ''))),
                preg_replace('/\D/', '', (string) ($row['phone'] ?? '')) ?: '',
            ];
            foreach ($keys as $key) {
                foreach ($candidates as $candidate) {
                    if ($candidate === '' || $key === '') {
                        continue;
                    }
                    if ($candidate === $key || str_contains($candidate, $key) || str_contains($key, $candidate)) {
                        $owner = trim((string) ($row['full_name'] ?? ''));
                        if ($owner === '') {
                            $owner = (string) ($row['username'] ?? '');
                        }
                        if ($owner !== '' && (int) ($row['created_by'] ?? 0) > 0) {
                            return [
                                'assigned_to' => (int) $row['created_by'],
                                'assigned_user_name' => $owner,
                            ];
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function crmMarketQueryActiveUsers(PDO $conn): array
{
    $queries = [
        'SELECT id, full_name, username, department, role FROM users WHERE COALESCE(is_active, 1) = 1 ORDER BY id ASC',
        'SELECT id, full_name, username, department, role FROM users ORDER BY id ASC',
        'SELECT id, full_name, username FROM users ORDER BY id ASC',
    ];
    foreach ($queries as $sql) {
        try {
            $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows) && $rows) {
                return $rows;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return [];
}

function crmMarketUsersLookLikeSales(array $user): bool
{
    $dept = strtolower(trim((string) ($user['department'] ?? '')));
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    return str_contains($dept, 'sale') || str_contains($role, 'sale');
}

/**
 * Load sales people from the tenant ERP first (not the control-plane admin DB).
 *
 * @return list<array<string, mixed>>
 */
function crmMarketCollectSalesUsers(?PDO $erpPdo = null): array
{
    global $pdo, $control_pdo;
    $ordered = [];
    $seen = [];
    foreach ([$erpPdo, $pdo, $GLOBALS['tenant_pdo'] ?? null, $control_pdo] as $conn) {
        if (!($conn instanceof PDO)) {
            continue;
        }
        $oid = spl_object_id($conn);
        if (isset($seen[$oid])) {
            continue;
        }
        $seen[$oid] = true;
        $ordered[] = $conn;
    }

    foreach ($ordered as $conn) {
        $users = crmMarketQueryActiveUsers($conn);
        $sales = array_values(array_filter($users, 'crmMarketUsersLookLikeSales'));
        if ($sales) {
            return $sales;
        }
    }
    foreach ($ordered as $conn) {
        $users = crmMarketQueryActiveUsers($conn);
        if ($users) {
            return $users;
        }
    }

    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        return [[
            'id' => $uid,
            'full_name' => (string) ($_SESSION['full_name'] ?? ''),
            'username' => (string) ($_SESSION['username'] ?? ''),
        ]];
    }
    return [];
}

function crmMarketActiveSalesUsers(PDO $erpPdo): array
{
    return crmMarketCollectSalesUsers($erpPdo);
}

function crmMarketUserDisplayName(array $user): string
{
    $name = trim((string) ($user['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['username'] ?? ''));
    }
    if ($name === '') {
        $name = 'User #' . (int) ($user['id'] ?? 0);
    }
    return $name;
}

/**
 * @return list<string>
 */
function crmMarketCurrentUserAliases(): array
{
    $aliases = [];
    foreach (['full_name', 'name', 'username', 'user_name'] as $key) {
        $value = strtolower(trim((string) ($_SESSION[$key] ?? '')));
        if ($value !== '') {
            $aliases[$value] = true;
        }
    }
    return array_keys($aliases);
}

function crmMarketIsAssignedToUser(array $row, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $assignedId = (int) ($row['assigned_to'] ?? $row['assignedTo'] ?? 0);
    if ($assignedId === $userId) {
        return true;
    }
    $assignedName = strtolower(trim((string) ($row['assigned_user_name'] ?? $row['assignedToName'] ?? '')));
    if ($assignedName === '' || $assignedName === 'unassigned') {
        return false;
    }
    return in_array($assignedName, crmMarketCurrentUserAliases(), true);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function crmMarketFilterAssignedToUser(array $rows, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row) && crmMarketIsAssignedToUser($row, $userId)) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Split this search's companies evenly across sales users.
 *
 * @param list<array<string, mixed>> $leads
 * @param list<array<string, mixed>> $users
 * @return list<array<string, mixed>>
 */
function crmMarketAssignLeadsEvenly(array $leads, array $users): array
{
    if (empty($users) || empty($leads)) {
        return $leads;
    }
    $update = null;
    $marketPdo = crmMarketPdo(true);
    if ($marketPdo instanceof PDO) {
        try {
            $update = $marketPdo->prepare('UPDATE customers SET assigned_to = ?, assigned_user_name = ? WHERE id = ?');
        } catch (Throwable $e) {
            $update = null;
        }
    }
    $n = count($users);
    foreach ($leads as $i => $lead) {
        $user = $users[$i % $n];
        $uid = (int) ($user['id'] ?? 0);
        $name = crmMarketUserDisplayName($user);
        $id = (string) ($lead['id'] ?? '');
        if ($uid <= 0) {
            continue;
        }
        if ($update instanceof PDOStatement && $id !== '') {
            try {
                $update->execute([$uid, $name, $id]);
            } catch (Throwable $e) {
                /* keep going */
            }
        }
        $leads[$i]['assigned_to'] = $uid;
        $leads[$i]['assigned_user_name'] = $name;
        $leads[$i]['assignedToName'] = $name;
        $leads[$i]['assignedTo'] = $uid;
    }
    return $leads;
}

/**
 * After search distribution, push each assigned company into that user's Prospects.
 *
 * @param list<array<string, mixed>> $leads
 * @return array{created:int,skipped:int,imported_ids:list<string>}
 */
function crmMarketImportAssignedLeads(PDO $crmPdo, int $companyId, array $leads): array
{
    $created = 0;
    $skipped = 0;
    $importedIds = [];
    $fallbackOwner = (int) ($_SESSION['user_id'] ?? 0);
    if ($companyId <= 0) {
        return ['created' => 0, 'skipped' => count($leads), 'imported_ids' => []];
    }
    foreach ($leads as $lead) {
        $leadId = (string) ($lead['id'] ?? '');
        $ownerId = (int) ($lead['assigned_to'] ?? $lead['assignedTo'] ?? 0);
        if ($ownerId <= 0) {
            $ownerId = $fallbackOwner;
        }
        if ($leadId === '' || $ownerId <= 0) {
            $skipped++;
            continue;
        }
        try {
            // Prospects desk (tab=prospects), not My Customers.
            $result = crmMarketImportLead($crmPdo, $companyId, $ownerId, $leadId, 'prospect');
            $importedIds[] = $leadId;
            $contactNotes = (string) (($result['contact']['notes'] ?? ''));
            if (preg_match('/market_id:([^\s\n]+)/', $contactNotes, $m)) {
                $importedIds[] = $m[1];
            }
            if (!empty($result['created'])) {
                $created++;
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            $skipped++;
        }
    }
    return ['created' => $created, 'skipped' => $skipped, 'imported_ids' => $importedIds];
}

function crmMarketAllocateLeadsRoundRobin(int $companyId = 0): int
{
    $marketPdo = crmMarketPdo(true);
    if (!($marketPdo instanceof PDO)) {
        return 0;
    }

    global $pdo;
    $erpPdo = $pdo;
    if (!($erpPdo instanceof PDO)) {
        return 0;
    }
    $companyId = max(0, $companyId);

    try {
        $users = crmMarketActiveSalesUsers($erpPdo);
        if (empty($users)) {
            return 0;
        }

        $salesIds = array_map(static fn(array $u): int => (int) ($u['id'] ?? 0), $users);
        $salesIds = array_values(array_filter($salesIds, static fn(int $id): bool => $id > 0));
        $inList = implode(',', $salesIds);
        if ($inList === '') {
            return 0;
        }

        $counts = [];
        foreach ($users as $u) {
            $counts[(int) $u['id']] = 0;
        }
        if ($companyId > 0) {
            $countStmt = $marketPdo->prepare("SELECT assigned_to, COUNT(*) AS c FROM customers WHERE company_id = ? AND assigned_to IS NOT NULL GROUP BY assigned_to");
            $countStmt->execute([$companyId]);
        } else {
            $countStmt = $marketPdo->query("SELECT assigned_to, COUNT(*) AS c FROM customers WHERE assigned_to IS NOT NULL GROUP BY assigned_to");
        }
        while ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int) ($row['assigned_to'] ?? 0);
            if ($uid > 0 && in_array($uid, $salesIds, true)) {
                $counts[$uid] = (int) ($row['c'] ?? 0);
            }
        }

        if ($companyId > 0) {
            $unassignedStmt = $marketPdo->prepare("
                SELECT id, name, email, phone
                FROM customers
                WHERE company_id = ?
                  AND (
                    assigned_to IS NULL
                    OR assigned_user_name IS NULL
                    OR TRIM(assigned_user_name) = ''
                    OR assigned_to NOT IN ($inList)
                  )
                ORDER BY found_at ASC
            ");
            $unassignedStmt->execute([$companyId]);
            $unassigned = $unassignedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $unassigned = $marketPdo->query("
                SELECT id, name, email, phone
                FROM customers
                WHERE assigned_to IS NULL
                   OR assigned_user_name IS NULL
                   OR TRIM(assigned_user_name) = ''
                   OR assigned_to NOT IN ($inList)
                ORDER BY found_at ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (empty($unassigned)) {
            return 0;
        }

        $assignedCount = 0;
        $updateStmt = $marketPdo->prepare("UPDATE customers SET assigned_to = ?, assigned_user_name = ? WHERE id = ?");

        foreach ($unassigned as $lead) {
            $matched = crmMarketLookupAssignment(
                $erpPdo,
                (string) ($lead['name'] ?? ''),
                (string) ($lead['email'] ?? ''),
                (string) ($lead['phone'] ?? '')
            );

            if ($matched !== null) {
                $uid = (int) $matched['assigned_to'];
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
                $updateStmt->execute([
                    $uid,
                    (string) $matched['assigned_user_name'],
                    (string) $lead['id'],
                ]);
                $assignedCount++;
                continue;
            }

            $pick = $users[0];
            $min = $counts[(int) $pick['id']] ?? 0;
            foreach ($users as $u) {
                $uid = (int) $u['id'];
                $c = $counts[$uid] ?? 0;
                if ($c < $min) {
                    $min = $c;
                    $pick = $u;
                }
            }
            $uid = (int) $pick['id'];
            $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            $updateStmt->execute([$uid, crmMarketUserDisplayName($pick), (string) $lead['id']]);
            $assignedCount++;
        }

        return $assignedCount;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function crmMarketListLeads(int $limit = 100, string $search = '', ?int $assignedTo = null, int $companyId = 0): array
{
    crmMarketAllocateLeadsRoundRobin($companyId);

    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO)) {
        return [];
    }

    $limit = max(1, min(500, $limit));
    $search = trim($search);
    $companyId = max(0, $companyId);
    $params = [];
    $where = [];
    $sql = '
        SELECT id, username, name, category, location, email, phone, website,
               score, level, source, keyword, found_at, channel, emailed_at,
               assigned_to, assigned_user_name
        FROM customers
    ';
    if ($companyId > 0) {
        $where[] = 'company_id = ?';
        $params[] = $companyId;
    }
    if ($assignedTo !== null && $assignedTo > 0) {
        $where[] = 'assigned_to = ?';
        $params[] = $assignedTo;
    }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ? OR category LIKE ? OR location LIKE ? OR assigned_user_name LIKE ? OR keyword LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY found_at DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $clean = static function ($value): string {
            $text = (string) ($value ?? '');
            $text = str_replace("\xEF\xBF\xBD", '', $text);
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
            if ($text === '?' || $text === '') {
                return '';
            }
            return $text;
        };

        return [
            'id' => (string) ($row['id'] ?? ''),
            'username' => $clean($row['username'] ?? ''),
            'name' => $clean($row['name'] ?? '') ?: 'Unknown',
            'category' => $clean($row['category'] ?? ''),
            'location' => $clean($row['location'] ?? ''),
            'email' => $clean($row['email'] ?? ''),
            'phone' => $clean($row['phone'] ?? ''),
            'website' => $clean($row['website'] ?? ''),
            'score' => (int) ($row['score'] ?? 0),
            'level' => (string) ($row['level'] ?? ''),
            'source' => $clean($row['source'] ?? ''),
            'keyword' => $clean($row['keyword'] ?? ''),
            'found_at' => (string) ($row['found_at'] ?? ''),
            'channel' => (string) ($row['channel'] ?? ''),
            'emailed_at' => $row['emailed_at'] !== null ? (string) $row['emailed_at'] : '',
            'assigned_to' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
            'assigned_user_name' => $clean($row['assigned_user_name'] ?? '') ?: 'Unassigned',
        ];
    }, $rows);
}

/**
 * Prospects for the logged-in account: assigned_to user id or matching display name / username.
 *
 * @return list<array<string, mixed>>
 */
function crmMarketListLeadsForUser(int $userId, int $limit = 100, string $search = '', int $companyId = 0): array
{
    crmMarketAllocateLeadsRoundRobin($companyId);

    if ($userId <= 0) {
        return [];
    }

    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO)) {
        return [];
    }

    $aliases = [];
    foreach (['full_name', 'name', 'username', 'user_name'] as $key) {
        $value = strtolower(trim((string) ($_SESSION[$key] ?? '')));
        if ($value !== '') {
            $aliases[$value] = true;
        }
    }
    $names = array_keys($aliases);

    $limit = max(1, min(500, $limit));
    $search = trim($search);
    $companyId = max(0, $companyId);
    $params = [];
    $where = [];
    if ($companyId > 0) {
        $where[] = 'company_id = ?';
        $params[] = $companyId;
    }
    $nameSql = '';
    $assignParams = [$userId];
    if ($names) {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $nameSql = " OR LOWER(TRIM(assigned_user_name)) IN ({$placeholders})";
        foreach ($names as $name) {
            $assignParams[] = $name;
        }
    }
    $where[] = '(assigned_to = ?' . $nameSql . ')';
    foreach ($assignParams as $p) {
        $params[] = $p;
    }

    $sql = '
        SELECT id, username, name, category, location, email, phone, website,
               score, level, source, keyword, found_at, channel, emailed_at,
               assigned_to, assigned_user_name
        FROM customers
        WHERE ' . implode(' AND ', $where) . '
    ';
    if ($search !== '') {
        $sql .= ' AND (name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ? OR category LIKE ? OR location LIKE ? OR assigned_user_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY found_at DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
            $clean = static function ($value): string {
                $text = (string) ($value ?? '');
                $text = str_replace("\xEF\xBF\xBD", '', $text);
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
                $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
                if ($text === '?' || $text === '') {
                    return '';
                }
                return $text;
            };

            return [
                'id' => (string) ($row['id'] ?? ''),
                'username' => $clean($row['username'] ?? ''),
                'name' => $clean($row['name'] ?? '') ?: 'Unknown',
                'category' => $clean($row['category'] ?? ''),
                'location' => $clean($row['location'] ?? ''),
                'email' => $clean($row['email'] ?? ''),
                'phone' => $clean($row['phone'] ?? ''),
                'website' => $clean($row['website'] ?? ''),
                'score' => (int) ($row['score'] ?? 0),
                'level' => (string) ($row['level'] ?? ''),
                'source' => $clean($row['source'] ?? ''),
                'keyword' => $clean($row['keyword'] ?? ''),
                'found_at' => (string) ($row['found_at'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'emailed_at' => $row['emailed_at'] !== null ? (string) $row['emailed_at'] : '',
                'assigned_to' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
                'assigned_user_name' => $clean($row['assigned_user_name'] ?? '') ?: 'Unassigned',
            ];
        }, $rows);
}

/**
 * @param list<string> $importedIds market lead ids already in CRM for this company
 * @return list<string>
 */
function crmMarketImportedIds(PDO $crmPdo, int $companyId, ?string $excludeStatus = null): array
{
    try {
        $sql = "
            SELECT notes, status FROM crm_contacts
            WHERE company_id = ? AND notes LIKE '%market_id:%'
        ";
        $params = [$companyId];
        if ($excludeStatus !== null && $excludeStatus !== '') {
            $sql .= ' AND status <> ?';
            $params[] = $excludeStatus;
        }
        $stmt = $crmPdo->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notes = (string) ($row['notes'] ?? '');
            if (preg_match_all('/market_id:([^\s\n]+)/', $notes, $m)) {
                foreach ($m[1] as $id) {
                    $ids[] = (string) $id;
                }
            }
        }
        return array_values(array_unique($ids));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Market lead ids already saved as full My Customers (linked sales customer).
 * Thin CRM-only imports and Prospects do not count — those still need the customer form.
 *
 * @return list<string>
 */
function crmMarketImportedCustomerIds(PDO $crmPdo, int $companyId): array
{
    try {
        $stmt = $crmPdo->prepare("
            SELECT notes FROM crm_contacts
            WHERE company_id = ?
              AND notes LIKE '%market_id:%'
              AND status <> 'prospect'
              AND COALESCE(customer_id, 0) > 0
        ");
        $stmt->execute([$companyId]);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notes = (string) ($row['notes'] ?? '');
            if (preg_match_all('/market_id:([^\s\n]+)/', $notes, $m)) {
                foreach ($m[1] as $id) {
                    $ids[] = (string) $id;
                }
            }
        }
        return array_values(array_unique($ids));
    } catch (Throwable $e) {
        return crmMarketImportedIds($crmPdo, $companyId, 'prospect');
    }
}

/**
 * Create/update a full My Customers record from the customer form, linked to a market lead.
 *
 * @param array<string, mixed> $formData
 * @return array{contact:array<string,mixed>,created:bool,promoted:bool,message:string}
 */
function crmMarketImportLeadFromCustomerForm(
    PDO $crmPdo,
    int $companyId,
    int $userId,
    string $leadId,
    array $formData
): array {
    require_once __DIR__ . '/crm-engine.php';
    require_once __DIR__ . '/crm-sales-bridge.php';
    crmEngineEnsureSchema($crmPdo);
    crmSalesBridgeLoadDeps();

    $lead = crmMarketGetLead($leadId, $companyId);
    if ($lead === null) {
        throw new InvalidArgumentException('Market lead not found.');
    }

    $canonicalId = (string) ($lead['id'] ?? $leadId);
    $notes = trim((string) ($formData['notes'] ?? ''));
    if (!preg_match('/market_id:' . preg_quote($canonicalId, '/') . '\b/', $notes)) {
        $extra = implode("\n", array_filter([
            'market_id:' . $canonicalId,
            !empty($lead['website']) ? 'Website: ' . (string) $lead['website'] : '',
            !empty($lead['category']) ? 'Category: ' . (string) $lead['category'] : '',
            'Imported from CRM Market / Client Market',
        ]));
        $notes = $notes === '' ? $extra : ($notes . "\n" . $extra);
    }
    $formData['notes'] = $notes;
    $status = crmEngineNormalizeStatus((string) ($formData['status'] ?? 'lead'));
    if ($status === 'prospect') {
        $status = 'lead';
    }
    $formData['status'] = $status;

    $stmt = $crmPdo->prepare("
        SELECT * FROM crm_contacts
        WHERE company_id = ? AND (notes LIKE ? OR notes LIKE ?)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, '%market_id:' . $canonicalId . '%', '%market_id:' . $leadId . '%']);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing)) {
        $contactId = (int) ($existing['id'] ?? 0);
        if ($contactId <= 0) {
            throw new InvalidArgumentException('Existing market contact is invalid.');
        }
        $hadCustomer = (int) ($existing['customer_id'] ?? 0) > 0;
        $contact = crmSalesBridgeUpdateFromContactForm($crmPdo, $companyId, $userId, $contactId, $formData);
        if ($userId > 0) {
            try {
                $own = $crmPdo->prepare('UPDATE crm_contacts SET created_by = ? WHERE company_id = ? AND id = ?');
                $own->execute([$userId, $companyId, $contactId]);
                $contact['created_by'] = $userId;
            } catch (Throwable $e) {
                /* keep prior owner */
            }
        }
        return [
            'contact' => $contact,
            'created' => false,
            'promoted' => !$hadCustomer,
            'message' => $hadCustomer ? 'Customer updated.' : 'Added to My Customers.',
        ];
    }

    $contact = crmSalesBridgeCreateFromContactForm($crmPdo, $companyId, $userId, $formData);
    return [
        'contact' => $contact,
        'created' => true,
        'promoted' => false,
        'message' => 'Added to My Customers.',
    ];
}

/**
 * @return array<string, mixed>|null
 */
function crmMarketGetLead(string $leadId, int $companyId = 0): ?array
{
    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO) || $leadId === '') {
        return null;
    }

    $companyId = max(0, $companyId);
    if ($companyId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$leadId, $companyId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$leadId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) && preg_match('/^\d+-(.+)$/', $leadId, $m)) {
        if ($companyId > 0) {
            $stmt->execute([$m[1], $companyId]);
        } else {
            $stmt->execute([$m[1]]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'category' => (string) ($row['category'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'email' => $row['email'] !== null ? (string) $row['email'] : '',
        'phone' => $row['phone'] !== null ? (string) $row['phone'] : '',
        'website' => $row['website'] !== null ? (string) $row['website'] : '',
        'score' => (int) ($row['score'] ?? 0),
        'level' => (string) ($row['level'] ?? ''),
        'source' => (string) ($row['source'] ?? ''),
        'keyword' => (string) ($row['keyword'] ?? ''),
        'found_at' => (string) ($row['found_at'] ?? ''),
        'channel' => (string) ($row['channel'] ?? ''),
    ];
}

/**
 * Import a Client Market lead into CRM.
 * Default status is prospect (Prospects desk). Pass status=lead to add to My Customers.
 *
 * @return array{contact:array<string,mixed>,created:bool,message:string,promoted:bool}
 */
function crmMarketImportLead(
    PDO $crmPdo,
    int $companyId,
    int $userId,
    string $leadId,
    string $status = 'prospect'
): array {
    require_once __DIR__ . '/crm-engine.php';
    crmEngineEnsureSchema($crmPdo);

    $status = crmEngineNormalizeStatus($status);
    if (!in_array($status, ['prospect', 'lead', 'customer'], true)) {
        $status = 'prospect';
    }

    $lead = crmMarketGetLead($leadId, $companyId);
    if ($lead === null) {
        throw new InvalidArgumentException('Market lead not found.');
    }

    $canonicalId = (string) ($lead['id'] ?? $leadId);
    $existingIds = crmMarketImportedIds($crmPdo, $companyId);
    if (in_array($canonicalId, $existingIds, true) || in_array($leadId, $existingIds, true)) {
        $stmt = $crmPdo->prepare("
            SELECT * FROM crm_contacts
            WHERE company_id = ? AND (notes LIKE ? OR notes LIKE ?)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$companyId, '%market_id:' . $canonicalId . '%', '%market_id:' . $leadId . '%']);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            $currentStatus = strtolower(trim((string) ($existing['status'] ?? '')));
            // Promote a prospect into My Customers when the user explicitly adds them.
            if ($status === 'lead' && $currentStatus === 'prospect') {
                $contactId = (int) ($existing['id'] ?? 0);
                if ($contactId > 0) {
                    $contact = crmEngineUpdateContact($crmPdo, $companyId, $contactId, [
                        'status' => 'lead',
                        'name' => (string) ($existing['name'] ?? ''),
                        'organization' => (string) ($existing['organization'] ?? ''),
                        'email' => (string) ($existing['email'] ?? ''),
                        'phone' => (string) ($existing['phone'] ?? ''),
                        'source' => (string) ($existing['source'] ?? ''),
                        'notes' => (string) ($existing['notes'] ?? ''),
                        'customer_id' => (int) ($existing['customer_id'] ?? 0),
                    ]);
                    // Ensure the promoting sales user owns the My Customers row.
                    if ($userId > 0) {
                        try {
                            $own = $crmPdo->prepare('UPDATE crm_contacts SET created_by = ? WHERE company_id = ? AND id = ?');
                            $own->execute([$userId, $companyId, $contactId]);
                            $contact['created_by'] = $userId;
                        } catch (Throwable $e) {
                            /* keep prior owner */
                        }
                    }
                    return [
                        'contact' => $contact,
                        'created' => false,
                        'promoted' => true,
                        'message' => 'Added to My Customers.',
                    ];
                }
            }
            $alreadyMsg = $currentStatus === 'prospect'
                ? 'Already in Prospects.'
                : 'Already in My Customers.';
            return [
                'contact' => $existing,
                'created' => false,
                'promoted' => false,
                'message' => $alreadyMsg,
            ];
        }
    }

    $displayName = trim((string) ($lead['name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string) ($lead['username'] ?? ''));
    }
    if ($displayName === '') {
        throw new InvalidArgumentException('Lead has no name.');
    }

    $username = trim((string) ($lead['username'] ?? ''));
    $category = trim((string) ($lead['category'] ?? ''));
    $location = trim((string) ($lead['location'] ?? ''));
    $sourceLabel = trim((string) ($lead['source'] ?? 'Client Market'));
    $notes = implode("\n", array_filter([
        'market_id:' . $canonicalId,
        $username !== '' ? '@' . $username : '',
        $category !== '' ? 'Category: ' . $category : '',
        $location !== '' ? 'Location: ' . $location : '',
        'Score: ' . (int) ($lead['score'] ?? 0) . ' (' . (string) ($lead['level'] ?? '') . ')',
        !empty($lead['website']) ? 'Website: ' . $lead['website'] : '',
        'Imported from CRM Market / Client Market',
    ]));

    $contact = crmEngineCreateContact($crmPdo, $companyId, $userId, [
        'name' => $displayName,
        'organization' => $displayName,
        'email' => trim((string) ($lead['email'] ?? '')),
        'phone' => trim((string) ($lead['phone'] ?? '')),
        'status' => $status,
        'source' => 'CRM Market: ' . ($sourceLabel !== '' ? $sourceLabel : 'Client Market'),
        'notes' => $notes,
    ]);

    $createdMsg = $status === 'prospect'
        ? 'Added to Prospects.'
        : 'Added to My Customers.';

    return [
        'contact' => $contact,
        'created' => true,
        'promoted' => false,
        'message' => $createdMsg,
    ];
}

/**
 * Canonical search-lab.json path (Settings save + Search read).
 */
function crmMarketSearchLabConfigPath(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'client Market'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'search-lab.json';
}

/**
 * Legacy path that used to take priority and could shadow Settings saves.
 *
 * @return list<string>
 */
function crmMarketSearchLabConfigLegacyPaths(): array
{
    return [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'search-lab.json',
    ];
}

/**
 * @return array{host:string,key:string,limit:int,lat:float,lng:float,language:string,region:string}
 */
function crmMarketSearchLabConfig(): array
{
    $fallback = [
        'host' => 'local-business-search.p.rapidapi.com',
        'key' => '',
        'limit' => 50,
        'lat' => -6.369,
        'lng' => 34.889,
        'language' => 'en',
        'region' => 'tz',
    ];
    $files = array_merge(
        [crmMarketSearchLabConfigPath()],
        crmMarketSearchLabConfigLegacyPaths()
    );
    $decoded = null;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($json) && trim((string) ($json['key'] ?? '')) !== '') {
            $decoded = $json;
            break;
        }
        if (is_array($json) && $decoded === null) {
            $decoded = $json;
        }
    }
    if (!is_array($decoded)) {
        return $fallback;
    }
    return array_merge($fallback, [
        'host' => (string) ($decoded['host'] ?? $fallback['host']),
        'key' => (string) ($decoded['key'] ?? ''),
        'limit' => (int) ($decoded['limit'] ?? $fallback['limit']),
        'lat' => (float) ($decoded['lat'] ?? $fallback['lat']),
        'lng' => (float) ($decoded['lng'] ?? $fallback['lng']),
        'language' => (string) ($decoded['language'] ?? 'en'),
        'region' => (string) ($decoded['region'] ?? 'tz'),
    ]);
}

/**
 * @return array{lat:float,lng:float,code:string}|null
 */
function crmMarketCountryMeta(string $location): ?array
{
    static $centers = [
        'tanzania' => [-6.369, 34.889, 'tz'],
        'kenya' => [-0.024, 37.906, 'ke'],
        'uganda' => [1.373, 32.29, 'ug'],
        'rwanda' => [-1.94, 29.874, 'rw'],
        'south africa' => [-30.56, 22.938, 'za'],
        'united arab emirates' => [23.424, 53.848, 'ae'],
        'india' => [20.594, 78.963, 'in'],
        'united kingdom' => [55.378, -3.436, 'gb'],
        'united states' => [37.09, -95.713, 'us'],
        'china' => [35.862, 104.195, 'cn'],
    ];
    $key = strtolower(trim($location));
    if (!isset($centers[$key])) {
        return null;
    }
    [$lat, $lng, $code] = $centers[$key];
    return ['lat' => (float) $lat, 'lng' => (float) $lng, 'code' => $code];
}

/**
 * @return list<array<string, mixed>>
 */
function crmMarketNormalizeSearchPayload(mixed $payload): array
{
    if (!is_array($payload)) {
        return [];
    }
    $list = null;
    foreach (['data', 'results', 'businesses', 'items', 'suggestions', 'places'] as $k) {
        if (isset($payload[$k]) && is_array($payload[$k])) {
            $list = $payload[$k];
            break;
        }
    }
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $types = [];
        if (isset($item['types']) && is_array($item['types'])) {
            $types = array_map('strval', $item['types']);
        }
        $name = trim((string) ($item['name'] ?? $item['title'] ?? $item['description'] ?? ''));
        if ($name === '' || strcasecmp($name, 'Unknown') === 0) {
            continue;
        }
        $out[] = [
            'id' => (string) ($item['business_id'] ?? $item['google_id'] ?? $item['place_id'] ?? $item['id'] ?? $i),
            'name' => $name,
            'phone' => $item['phone_number'] ?? $item['phone'] ?? '',
            'address' => (string) ($item['full_address'] ?? $item['address'] ?? $item['formatted_address'] ?? ''),
            'website' => (string) ($item['website'] ?? $item['site'] ?? ''),
            'email' => (string) ($item['email'] ?? ''),
            'rating' => isset($item['rating']) ? (float) $item['rating'] : null,
            'type' => (string) ($item['type'] ?? $item['category'] ?? ($types[0] ?? '')),
            'city' => (string) ($item['city'] ?? $item['locality'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @return array{ok:bool,code:int,payload:mixed,error:string}
 */
function crmMarketRapidGet(string $path, array $query): array
{
    $cfg = crmMarketSearchLabConfig();
    if (trim($cfg['key']) === '') {
        return ['ok' => false, 'code' => 0, 'payload' => null, 'error' => 'Search API key is missing in CRM Market settings.'];
    }
    $host = preg_replace('/^https?:\/\//', '', $cfg['host']) ?: 'local-business-search.p.rapidapi.com';
    $url = 'https://' . $host . '/' . ltrim($path, '/') . '?' . http_build_query($query);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'code' => 0, 'payload' => null, 'error' => 'Could not start search request.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-rapidapi-host: ' . $host,
            'x-rapidapi-key: ' . $cfg['key'],
        ],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno) {
        return ['ok' => false, 'code' => $code, 'payload' => null, 'error' => 'Search request failed.'];
    }
    $payload = json_decode((string) $raw, true);
    if ($code >= 400) {
        $msg = is_array($payload) ? (string) ($payload['message'] ?? $payload['error'] ?? 'Search failed') : 'Search failed';
        return ['ok' => false, 'code' => $code, 'payload' => $payload, 'error' => $msg];
    }
    return ['ok' => true, 'code' => $code, 'payload' => $payload, 'error' => ''];
}

/**
 * @return array{ok:bool,rows:list<array<string,mixed>>,error:string}
 */
function crmMarketRapidSearch(string $query, string $location): array
{
    $cfg = crmMarketSearchLabConfig();
    $meta = crmMarketCountryMeta($location);
    $lat = $meta['lat'] ?? $cfg['lat'];
    $lng = $meta['lng'] ?? $cfg['lng'];
    $region = $meta['code'] ?? $cfg['region'];
    $queryParams = [
        'query' => $query,
        'region' => $region,
        'language' => $cfg['language'],
        'lat' => $lat,
        'lng' => $lng,
        'coordinates' => $lat . ',' . $lng,
        'limit' => (string) min(100, max(20, (int) $cfg['limit'])),
    ];
    $search = crmMarketRapidGet('search', $queryParams);
    if ($search['ok']) {
        return ['ok' => true, 'rows' => crmMarketNormalizeSearchPayload($search['payload']), 'error' => ''];
    }
    $auto = crmMarketRapidGet('autocomplete', [
        'query' => $query,
        'region' => $region,
        'language' => $cfg['language'],
        'coordinates' => $lat . ',' . $lng,
    ]);
    if ($auto['ok']) {
        return ['ok' => true, 'rows' => crmMarketNormalizeSearchPayload($auto['payload']), 'error' => ''];
    }
    return ['ok' => false, 'rows' => [], 'error' => $search['error'] !== '' ? $search['error'] : $auto['error']];
}

/**
 * @return array{ok:bool,suggestions:list<array<string,mixed>>,error:string}
 */
function crmMarketRapidAutocomplete(string $query, string $location): array
{
    $query = trim($query);
    if ($query === '') {
        return ['ok' => true, 'suggestions' => [], 'error' => ''];
    }
    $cfg = crmMarketSearchLabConfig();
    $meta = crmMarketCountryMeta($location);
    $lat = $meta['lat'] ?? $cfg['lat'];
    $lng = $meta['lng'] ?? $cfg['lng'];
    $region = $meta['code'] ?? $cfg['region'];
    $auto = crmMarketRapidGet('autocomplete', [
        'query' => $query,
        'region' => $region,
        'language' => $cfg['language'],
        'coordinates' => $lat . ',' . $lng,
    ]);
    if (!$auto['ok']) {
        return ['ok' => false, 'suggestions' => [], 'error' => $auto['error']];
    }
    $rows = crmMarketNormalizeSearchPayload($auto['payload']);
    $suggestions = [];
    foreach ($rows as $row) {
        $label = trim((string) ($row['name'] ?? ''));
        if ($label === '') {
            continue;
        }
        $suggestions[] = [
            'label' => $label,
            'type' => (string) ($row['type'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
        ];
    }
    if (!$suggestions && is_array($auto['payload'])) {
        $payload = $auto['payload'];
        $list = $payload['data'] ?? $payload['suggestions'] ?? $payload['results'] ?? [];
        if (is_array($list)) {
            foreach ($list as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $suggestions[] = ['label' => trim($item), 'type' => '', 'city' => ''];
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['query'] ?? $item['text'] ?? $item['name'] ?? $item['title'] ?? $item['description'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $suggestions[] = [
                    'label' => $label,
                    'type' => (string) ($item['type'] ?? $item['category'] ?? ''),
                    'city' => (string) ($item['city'] ?? ''),
                ];
            }
        }
    }
    return ['ok' => true, 'suggestions' => array_slice($suggestions, 0, 12), 'error' => ''];
}

/**
 * @param array<string, mixed> $row
 * @return array{lead:array<string,mixed>,inserted:bool}
 */
function crmMarketSaveSearchRow(array $row, string $keyword, string $location, int $companyId = 0): array
{
    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Client Market database is not available.');
    }
    $companyId = max(0, $companyId);
    $rawId = (string) ($row['id'] ?? uniqid('r', true));
    $id = 'search-' . ($companyId > 0 ? $companyId . '-' : '') . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $rawId);
    $username = 's' . ($companyId > 0 ? $companyId . '-' : '-') . strtolower(preg_replace('/[^a-z0-9]+/', '-', (string) ($row['id'] ?: $row['name'])));
    $username = substr($username, 0, 60) ?: ('s-' . substr(sha1($id), 0, 12));
    $name = trim((string) ($row['name'] ?? ''));
    $email = trim((string) ($row['email'] ?? '')) ?: null;
    $phone = trim((string) ($row['phone'] ?? '')) ?: null;
    $website = trim((string) ($row['website'] ?? '')) ?: null;
    $category = trim((string) ($row['type'] ?? '')) ?: 'Search lead';
    $loc = trim((string) ($row['city'] ?? $row['address'] ?? $location));
    $score = $email ? 70 : 50;
    $foundAt = date('Y-m-d H:i:s');

    $exists = $pdo->prepare('SELECT id FROM customers WHERE company_id = ? AND channel = ? AND username = ? LIMIT 1');
    $exists->execute([$companyId, 'search', $username]);
    $already = (string) ($exists->fetchColumn() ?: '');
    if ($already !== '') {
        $get = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND company_id = ? LIMIT 1');
        $get->execute([$already, $companyId]);
        $saved = $get->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['lead' => crmMarketFormatLeadRow($saved), 'inserted' => false];
    }

    $pdo->prepare('
        INSERT INTO customers (
            id, username, name, category, location, email, phone, website,
            score, level, source, keyword, found_at, channel, company_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ')->execute([
        $id,
        $username,
        $name,
        $category,
        $loc !== '' ? $loc : null,
        $email,
        $phone,
        $website,
        $score,
        $score >= 70 ? 'hot' : 'warm',
        'Search',
        $keyword,
        $foundAt,
        'search',
        $companyId,
    ]);

    return ['lead' => crmMarketFormatLeadRow([
        'id' => $id,
        'username' => $username,
        'name' => $name,
        'category' => $category,
        'location' => $loc,
        'email' => $email,
        'phone' => $phone,
        'website' => $website,
        'score' => $score,
        'level' => $score >= 70 ? 'hot' : 'warm',
        'source' => 'Search',
        'keyword' => $keyword,
        'found_at' => $foundAt,
        'channel' => 'search',
        'emailed_at' => null,
        'assigned_to' => null,
        'assigned_user_name' => '',
        'company_id' => $companyId,
    ]), 'inserted' => true];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function crmMarketFormatLeadRow(array $row): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'username' => (string) ($row['username'] ?? ''),
        'name' => (string) ($row['name'] ?? 'Unknown'),
        'category' => (string) ($row['category'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'website' => (string) ($row['website'] ?? ''),
        'score' => (int) ($row['score'] ?? 0),
        'level' => (string) ($row['level'] ?? ''),
        'source' => (string) ($row['source'] ?? ''),
        'keyword' => (string) ($row['keyword'] ?? ''),
        'found_at' => (string) ($row['found_at'] ?? ''),
        'channel' => (string) ($row['channel'] ?? ''),
        'emailed_at' => $row['emailed_at'] !== null ? (string) $row['emailed_at'] : '',
        'assigned_to' => isset($row['assigned_to']) && $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
        'assigned_user_name' => trim((string) ($row['assigned_user_name'] ?? $row['assignedToName'] ?? '')) ?: 'Unassigned',
        'assignedTo' => isset($row['assigned_to']) && $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
        'assignedToName' => trim((string) ($row['assigned_user_name'] ?? $row['assignedToName'] ?? '')) ?: 'Unassigned',
    ];
}

/**
 * @return array{results:list<array<string,mixed>>,inserted:int,skipped:int}
 */
function crmMarketRunSearch(string $q, string $location, ?PDO $erpPdo = null, int $companyId = 0): array
{
    $q = trim($q);
    $location = trim($location) ?: 'Tanzania';
    $query = trim($q . ' ' . $location);
    if ($query === '') {
        throw new InvalidArgumentException('Enter a search term.');
    }
    $companyId = max(0, $companyId);
    if ($companyId <= 0) {
        throw new InvalidArgumentException('Company context is required for Market search.');
    }
    $pdoForScope = crmMarketPdo(true);
    if ($pdoForScope instanceof PDO) {
        crmMarketBackfillLegacyCompanyScope($pdoForScope);
    }
    $found = crmMarketRapidSearch($query, $location);
    if (!$found['ok']) {
        throw new InvalidArgumentException($found['error'] !== '' ? $found['error'] : 'Search failed.');
    }
    $inserted = 0;
    $skipped = 0;
    $importedCreated = 0;
    $importedSkipped = 0;
    $salesUsers = [];
    $results = [];
    foreach ($found['rows'] as $row) {
        $saved = crmMarketSaveSearchRow($row, $query, $location, $companyId);
        $results[] = $saved['lead'];
        if ($saved['inserted']) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    $salesUsers = crmMarketCollectSalesUsers($erpPdo);
    if ($salesUsers) {
        $results = crmMarketAssignLeadsEvenly($results, $salesUsers);
    } else {
        crmMarketAllocateLeadsRoundRobin($companyId);
    }

    if ($erpPdo instanceof PDO && $companyId > 0) {
        $imp = crmMarketImportAssignedLeads($erpPdo, $companyId, $results);
        $importedCreated = $imp['created'];
        $importedSkipped = $imp['skipped'];
        $importedMap = array_fill_keys($imp['imported_ids'], true);
        foreach ($results as &$lead) {
            $lead['imported'] = isset($importedMap[(string) ($lead['id'] ?? '')]);
        }
        unset($lead);
    }

    $viewerId = (int) ($_SESSION['user_id'] ?? 0);
    foreach ($results as &$lead) {
        $lead['assigned_to_me'] = $viewerId > 0 && (int) ($lead['assigned_to'] ?? 0) === $viewerId;
        $lead['imported'] = !empty($lead['imported']);
    }
    unset($lead);

    crmMarketRecordSearchHistory($query, $location, '', count($results), $inserted, $skipped, $results, $companyId);
    return [
        'results' => $results,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'sales_count' => count($salesUsers),
        'imported' => $importedCreated,
        'import_skipped' => $importedSkipped,
    ];
}

function crmMarketRecordSearchHistory(
    string $query,
    string $location,
    string $category,
    int $resultCount,
    int $insertedCount,
    int $skippedCount,
    array $rows = [],
    int $companyId = 0
): void {
    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO)) {
        return;
    }
    $companyId = max(0, $companyId);
    $id = 'hist-' . ($companyId > 0 ? $companyId . '-' : '') . dechex(time()) . '-' . bin2hex(random_bytes(3));
    try {
        $pdo->prepare('
            INSERT INTO search_history
              (id, search_text, location, category, result_count, inserted_count, skipped_count, created_at, company_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ')->execute([
            $id,
            substr($query !== '' ? $query : '(empty)', 0, 255),
            $location !== '' ? substr($location, 0, 191) : null,
            $category !== '' ? substr($category, 0, 191) : null,
            $resultCount,
            $insertedCount,
            $skippedCount,
            $companyId,
        ]);
    } catch (Throwable $e) {
        return;
    }
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            $pdo->prepare('
                INSERT INTO search_results
                  (id, history_id, sort_order, name, type, city, address, phone, website, email, rating, assigned_to, assigned_user_name, company_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                substr((string) (($row['id'] ?? '') !== '' ? $row['id'] : ($i . '-r')), 0, 191),
                $id,
                (int) $i,
                substr((string) ($row['name'] ?? 'Unknown'), 0, 255),
                substr((string) ($row['type'] ?? $row['category'] ?? ''), 0, 191) ?: null,
                substr((string) ($row['city'] ?? $row['location'] ?? ''), 0, 191) ?: null,
                (string) ($row['address'] ?? $row['location'] ?? ''),
                substr((string) ($row['phone'] ?? ''), 0, 64) ?: null,
                substr((string) ($row['website'] ?? ''), 0, 255) ?: null,
                substr((string) ($row['email'] ?? ''), 0, 191) ?: null,
                isset($row['rating']) && $row['rating'] !== '' && $row['rating'] !== null ? (float) $row['rating'] : null,
                isset($row['assigned_to']) || isset($row['assignedTo'])
                    ? (int) ($row['assigned_to'] ?? $row['assignedTo'])
                    : null,
                trim((string) ($row['assigned_user_name'] ?? $row['assignedToName'] ?? '')) ?: null,
                $companyId,
            ]);
        } catch (Throwable $e) {
            /* skip one row */
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function crmMarketListSearchHistory(int $limit = 200, int $companyId = 0): array
{
    $pdo = crmMarketPdo(true);
    $out = [];
    if ($pdo instanceof PDO) {
        try {
            crmMarketBackfillLegacyCompanyScope($pdo);
            $limit = max(1, min(500, $limit));
            $companyId = max(0, $companyId);
            if ($companyId > 0) {
                $stmt = $pdo->prepare("
                    SELECT id, search_text AS q, location, category,
                           result_count, inserted_count, skipped_count, created_at
                    FROM search_history
                    WHERE company_id = ?
                    ORDER BY created_at DESC
                    LIMIT {$limit}
                ");
                $stmt->execute([$companyId]);
            } else {
                $stmt = $pdo->query("
                    SELECT id, search_text AS q, location, category,
                           result_count, inserted_count, skipped_count, created_at
                    FROM search_history
                    ORDER BY created_at DESC
                    LIMIT {$limit}
                ");
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'query' => (string) ($row['q'] ?? ''),
                    'location' => (string) ($row['location'] ?? ''),
                    'category' => (string) ($row['category'] ?? ''),
                    'resultCount' => (int) ($row['result_count'] ?? 0),
                    'insertedCount' => (int) ($row['inserted_count'] ?? 0),
                    'skippedCount' => (int) ($row['skipped_count'] ?? 0),
                    'createdAt' => (string) ($row['created_at'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $out = [];
        }
    }
    if ($out || $companyId > 0) {
        // Do not fall back to shared JSON history when company scoping is required.
        return $out;
    }

    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'client Market'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'search-history.json';
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return [];
    }
    $records = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $records[] = [
            'id' => (string) ($row['id'] ?? ''),
            'query' => (string) ($row['query'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'resultCount' => (int) ($row['resultCount'] ?? 0),
            'insertedCount' => (int) ($row['insertedCount'] ?? 0),
            'skippedCount' => (int) ($row['skippedCount'] ?? 0),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ];
    }
    return $records;
}

/**
 * Attach how many companies from each saved search are assigned to $userId.
 * Uses the same result source + filter as opening a saved search (New Leads detail).
 *
 * @param list<array<string, mixed>> $records
 * @return list<array<string, mixed>>
 */
function crmMarketAttachHistoryAssignedCounts(array $records, int $userId, int $companyId = 0): array
{
    if ($records === []) {
        return $records;
    }
    if ($userId <= 0) {
        foreach ($records as &$record) {
            $record['assignedCount'] = 0;
            $record['totalAssignedCount'] = 0;
            $record['viewed'] = true;
        }
        unset($record);
        return $records;
    }

    $viewedMap = array_fill_keys(crmMarketListHistoryViewedIds($userId), true);

    foreach ($records as &$record) {
        $historyId = trim((string) ($record['id'] ?? ''));
        if ($historyId === '') {
            $record['assignedCount'] = 0;
            $record['totalAssignedCount'] = 0;
            $record['viewed'] = true;
            continue;
        }
        try {
            $rows = crmMarketListSearchHistoryResults($historyId, $companyId);
            $record['assignedCount'] = count(crmMarketFilterAssignedToUser($rows, $userId));
            $totalAssigned = 0;
            foreach ($rows as $row) {
                $aid = (int) ($row['assigned_to'] ?? $row['assignedTo'] ?? 0);
                if ($aid > 0) {
                    $totalAssigned++;
                }
            }
            $record['totalAssignedCount'] = $totalAssigned;
        } catch (Throwable $e) {
            $record['assignedCount'] = 0;
            $record['totalAssignedCount'] = 0;
        }
        $record['viewed'] = isset($viewedMap[$historyId]);
    }
    unset($record);
    return $records;
}

/**
 * @return list<string>
 */
function crmMarketListHistoryViewedIds(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT history_id FROM search_history_views WHERE user_id = ?');
        $stmt->execute([$userId]);
        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $hid = trim((string) ($row['history_id'] ?? ''));
            if ($hid !== '') {
                $ids[] = $hid;
            }
        }
        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

function crmMarketMarkHistoryViewed(int $userId, string $historyId): bool
{
    $historyId = trim($historyId);
    if ($userId <= 0 || $historyId === '') {
        return false;
    }
    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO)) {
        return false;
    }
    try {
        // Ensure table exists on older installs.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS search_history_views (
              user_id INT NOT NULL,
              history_id VARCHAR(191) NOT NULL,
              viewed_at DATETIME NOT NULL,
              PRIMARY KEY (user_id, history_id),
              KEY idx_search_history_views_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $stmt = $pdo->prepare('
            INSERT INTO search_history_views (user_id, history_id, viewed_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE viewed_at = VALUES(viewed_at)
        ');
        $stmt->execute([$userId, substr($historyId, 0, 191)]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function crmMarketDeleteSearchHistory(string $historyId, int $companyId = 0): bool
{
    $historyId = trim($historyId);
    if ($historyId === '') {
        throw new InvalidArgumentException('Saved search id is required.');
    }

    $pdo = crmMarketPdo(true);
    $companyId = max(0, $companyId);
    if ($pdo instanceof PDO) {
        try {
            if ($companyId > 0) {
                $check = $pdo->prepare('SELECT id FROM search_history WHERE id = ? AND company_id = ? LIMIT 1');
                $check->execute([$historyId, $companyId]);
                if (!$check->fetchColumn()) {
                    return false;
                }
            }
            $pdo->prepare('DELETE FROM search_results WHERE history_id = ?')->execute([$historyId]);
            $stmt = $pdo->prepare(
                $companyId > 0
                    ? 'DELETE FROM search_history WHERE id = ? AND company_id = ?'
                    : 'DELETE FROM search_history WHERE id = ?'
            );
            $stmt->execute($companyId > 0 ? [$historyId, $companyId] : [$historyId]);
            if ($stmt->rowCount() > 0) {
                return true;
            }
            if ($companyId > 0) {
                return false;
            }
        } catch (Throwable $e) {
            /* fall through to JSON file */
        }
    }

    if ($companyId > 0) {
        return false;
    }

    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'client Market'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'search-history.json';
    if (!is_file($file)) {
        return false;
    }
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return false;
    }
    $kept = [];
    $removed = false;
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['id'] ?? '') === $historyId) {
            $removed = true;
            continue;
        }
        $kept[] = $row;
    }
    if (!$removed) {
        return false;
    }
    file_put_contents($file, json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return true;
}

function crmMarketPdfText(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if (is_string($converted)) {
            return $converted;
        }
    }
    return preg_replace('/[^\x20-\x7E]/', '?', $text) ?: '';
}

function crmMarketPdfClip(string $text, int $max = 42): string
{
    $text = crmMarketPdfText($text);
    if (strlen($text) <= $max) {
        return $text;
    }
    return rtrim(substr($text, 0, max(1, $max - 1))) . '.';
}

function crmMarketLoadFpdf(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $fpdfPath = dirname(__DIR__, 3) . '/store-management-system/labels/fpdf.php';
    if (!is_file($fpdfPath)) {
        throw new RuntimeException('PDF engine is not available.');
    }
    require_once $fpdfPath;
    $loaded = true;
}

function crmMarketOutputSearchHistoryPdf(string $historyId, int $companyId = 0): void
{
    $historyId = trim($historyId);
    if ($historyId === '') {
        throw new InvalidArgumentException('Saved search id is required.');
    }

    crmMarketLoadFpdf();
    $companyId = max(0, $companyId);
    $rows = crmMarketListSearchHistoryResults($historyId, $companyId);
    $onlyUserId = (int) ($_GET['mine'] ?? 0) > 0 ? (int) ($_SESSION['user_id'] ?? 0) : 0;
    if ($onlyUserId > 0) {
        $rows = crmMarketFilterAssignedToUser($rows, $onlyUserId);
    }
    $meta = null;
    foreach (crmMarketListSearchHistory(500, $companyId) as $record) {
        if ((string) ($record['id'] ?? '') === $historyId) {
            $meta = $record;
            break;
        }
    }
    $query = trim((string) ($meta['query'] ?? 'Saved search'));
    $location = trim((string) ($meta['location'] ?? ''));
    $when = trim((string) ($meta['createdAt'] ?? ''));
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($query !== '' ? $query : 'saved-search')) ?: 'saved-search';
    $safeName = substr($safeName, 0, 48);

    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(10, 12, 10);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetTitle(crmMarketPdfText($query));

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 8, crmMarketPdfText($onlyUserId > 0 ? 'CRM Market — New Leads' : 'CRM Market — Saved search'), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(0, 6, crmMarketPdfText($query . ($location !== '' ? '  ·  ' . $location : '')), 0, 1, 'L');
    if ($when !== '') {
        $pdf->Cell(0, 5, crmMarketPdfText('Saved ' . $when . '  ·  ' . count($rows) . ' companies'), 0, 1, 'L');
    } else {
        $pdf->Cell(0, 5, crmMarketPdfText(count($rows) . ' companies'), 0, 1, 'L');
    }
    $pdf->Ln(3);

    $headers = ['No', 'Name', 'Type', 'City', 'Phone', 'Assigned to', 'Website', 'Email'];
    $widths = [12, 52, 32, 28, 30, 36, 48, 39];
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(10, 90, 168);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(226, 232, 240);
    foreach ($headers as $i => $header) {
        $pdf->Cell($widths[$i], 8, crmMarketPdfText($header), 1, 0, 'L', true);
    }
    $pdf->Ln();

    if ($rows === []) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->Cell(array_sum($widths), 9, crmMarketPdfText('No companies stored for this search.'), 1, 1, 'C', true);
    } else {
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(30, 41, 59);
        $fill = false;
        foreach ($rows as $i => $row) {
            if ($pdf->GetY() > 190) {
                $pdf->AddPage();
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetFillColor(10, 90, 168);
                $pdf->SetTextColor(255, 255, 255);
                foreach ($headers as $hi => $header) {
                    $pdf->Cell($widths[$hi], 8, crmMarketPdfText($header), 1, 0, 'L', true);
                }
                $pdf->Ln();
                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(30, 41, 59);
            }
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
            $cells = [
                (string) ($i + 1),
                crmMarketPdfClip((string) ($row['name'] ?? ''), 34),
                crmMarketPdfClip((string) ($row['type'] ?? $row['category'] ?? ''), 22),
                crmMarketPdfClip((string) ($row['city'] ?? $row['location'] ?? ''), 20),
                crmMarketPdfClip((string) ($row['phone'] ?? ''), 20),
                crmMarketPdfClip((string) ($row['assignedToName'] ?? $row['assigned_user_name'] ?? ''), 24),
                crmMarketPdfClip((string) ($row['website'] ?? ''), 32),
                crmMarketPdfClip((string) ($row['email'] ?? ''), 26),
            ];
            foreach ($cells as $ci => $cell) {
                $pdf->Cell($widths[$ci], 7, $cell, 1, 0, 'L', true);
            }
            $pdf->Ln();
            $fill = !$fill;
        }
    }

    $pdf->Output('D', $safeName . '.pdf');
    exit;
}

/**
 * @return list<array<string, mixed>>
 */
function crmMarketListSearchHistoryResults(string $historyId, int $companyId = 0): array
{
    $historyId = trim($historyId);
    if ($historyId === '') {
        return [];
    }
    $pdo = crmMarketPdo(true);
    $companyId = max(0, $companyId);
    if ($pdo instanceof PDO) {
        try {
            if ($companyId > 0) {
                $owns = $pdo->prepare('SELECT id FROM search_history WHERE id = ? AND company_id = ? LIMIT 1');
                $owns->execute([$historyId, $companyId]);
                if (!$owns->fetchColumn()) {
                    return [];
                }
            }
            $stmt = $pdo->prepare('
                SELECT id, name, type, city, address, phone, website, email, rating,
                       assigned_to, assigned_user_name
                FROM search_results
                WHERE history_id = ?
                ORDER BY sort_order ASC
            ');
            $stmt->execute([$historyId]);
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''),
                    'city' => (string) ($row['city'] ?? ''),
                    'address' => (string) ($row['address'] ?? ''),
                    'phone' => (string) ($row['phone'] ?? ''),
                    'website' => (string) ($row['website'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
                    'assignedTo' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
                    'assignedToName' => (string) ($row['assigned_user_name'] ?? ''),
                ];
            }
            if ($rows) {
                return $rows;
            }
            if ($companyId <= 0) {
                $fileRows = crmMarketSearchResultsFromFile($historyId);
                if ($fileRows) {
                    return $fileRows;
                }
            }
            $meta = $pdo->prepare(
                $companyId > 0
                    ? 'SELECT search_text, location FROM search_history WHERE id = ? AND company_id = ? LIMIT 1'
                    : 'SELECT search_text, location FROM search_history WHERE id = ? LIMIT 1'
            );
            $meta->execute($companyId > 0 ? [$historyId, $companyId] : [$historyId]);
            $hist = $meta->fetch(PDO::FETCH_ASSOC) ?: [];
            $keyword = trim((string) ($hist['search_text'] ?? ''));
            $location = trim((string) ($hist['location'] ?? ''));
            if ($keyword !== '') {
                return crmMarketLeadsForSavedSearch($keyword, $location, $companyId);
            }
        } catch (Throwable $e) {
            return [];
        }
    }
    return [];
}

/**
 * @return list<array<string, mixed>>
 */
function crmMarketSearchResultsFromFile(string $historyId): array
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $historyId) ?: '';
    if ($safe === '') {
        return [];
    }
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'client Market'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'search-results' . DIRECTORY_SEPARATOR . $safe . '.json';
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? $row['category'] ?? ''),
            'city' => (string) ($row['city'] ?? $row['location'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'website' => (string) ($row['website'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'rating' => isset($row['rating']) && $row['rating'] !== '' && $row['rating'] !== null ? (float) $row['rating'] : null,
            'assignedTo' => isset($row['assignedTo']) ? (int) $row['assignedTo'] : ($row['assigned_to'] ?? null),
            'assignedToName' => (string) ($row['assignedToName'] ?? $row['assigned_user_name'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Open a past search using leads saved under that keyword (search_results snapshots may be missing).
 *
 * @return list<array<string, mixed>>
 */
function crmMarketLeadsForSavedSearch(string $keyword, string $location = '', int $companyId = 0): array
{
    $pdo = crmMarketPdo(true);
    if (!($pdo instanceof PDO) || trim($keyword) === '') {
        return [];
    }
    $keyword = trim($keyword);
    $location = trim($location);
    $companyId = max(0, $companyId);
    $terms = array_values(array_unique(array_filter([
        $keyword,
        $location !== ''
            ? trim((string) preg_replace('/\s+' . preg_quote($location, '/') . '\s*$/i', '', $keyword))
            : '',
    ], static fn(string $v): bool => $v !== '')));

    $clauses = [];
    $params = [];
    if ($companyId > 0) {
        $clauses[] = 'company_id = ?';
        $params[] = $companyId;
    }
    $keywordClauses = [];
    foreach ($terms as $term) {
        $keywordClauses[] = 'keyword = ?';
        $params[] = $term;
        $keywordClauses[] = 'keyword LIKE ?';
        $params[] = '%' . $term . '%';
    }
    if ($keywordClauses) {
        $clauses[] = '(' . implode(' OR ', $keywordClauses) . ')';
    }
    $sql = '
        SELECT id, name, category, location, email, phone, website, assigned_to, assigned_user_name
        FROM customers
        WHERE ' . implode(' AND ', $clauses) . '
        ORDER BY found_at DESC
        LIMIT 500
    ';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $seen = [];
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['category'] ?? ''),
                'city' => (string) ($row['location'] ?? ''),
                'address' => (string) ($row['location'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'website' => (string) ($row['website'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'rating' => null,
                'assignedTo' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
                'assignedToName' => (string) ($row['assigned_user_name'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function crmMarketFrontendDataDir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'client Market'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data';
}

function crmMarketMessageTemplatePath(): string
{
    $data = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'client Market'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($data)) {
        @mkdir($data, 0775, true);
    }
    return $data . DIRECTORY_SEPARATOR . 'message-template.json';
}

/**
 * @return array{subject:string,html:string,logoUrl:?string,sendMode:string}
 */
function crmMarketDefaultMessageTemplate(): array
{
    return [
        'subject' => 'Construction materials for your next project',
        'html' => '<p>Hello {{BusinessName}},</p>
<p>We supply construction materials and can support your work in <strong>{{Category}}</strong> in {{Location}}.</p>
<p>Cement, steel, roofing, tiles and other building materials — delivered to keep your site moving.</p>
<p style="margin-top:20px">Best regards,<br/><strong>Ultimate General Trading</strong><br/>Construction Materials Sales Team</p>',
        'logoUrl' => null,
        'sendMode' => 'manual',
    ];
}

/**
 * @return array{subject:string,html:string,logoUrl:?string,sendMode:string}
 */
function crmMarketGetMessageTemplate(): array
{
    $fallback = crmMarketDefaultMessageTemplate();
    $file = crmMarketMessageTemplatePath();
    if (!is_file($file)) {
        return $fallback;
    }
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return $fallback;
    }
    $subject = trim((string) ($decoded['subject'] ?? $fallback['subject']));
    $html = trim((string) ($decoded['html'] ?? $fallback['html']));
    $sendMode = strtolower((string) ($decoded['sendMode'] ?? 'manual')) === 'automatic' ? 'automatic' : 'manual';
    $logo = $decoded['logoUrl'] ?? $decoded['logo_url'] ?? null;
    return [
        'subject' => $subject !== '' ? $subject : $fallback['subject'],
        'html' => $html !== '' ? $html : $fallback['html'],
        'logoUrl' => is_string($logo) && $logo !== '' ? $logo : null,
        'sendMode' => $sendMode,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{subject:string,html:string,logoUrl:?string,sendMode:string}
 */
function crmMarketSaveMessageTemplate(array $input): array
{
    $current = crmMarketGetMessageTemplate();
    $next = [
        'subject' => trim((string) ($input['subject'] ?? $current['subject'])),
        'html' => (string) ($input['html'] ?? $current['html']),
        'logoUrl' => array_key_exists('logoUrl', $input)
            ? (is_string($input['logoUrl']) && $input['logoUrl'] !== '' ? $input['logoUrl'] : null)
            : $current['logoUrl'],
        'sendMode' => strtolower((string) ($input['sendMode'] ?? $current['sendMode'])) === 'automatic'
            ? 'automatic'
            : 'manual',
    ];
    if ($next['subject'] === '') {
        $next['subject'] = $current['subject'];
    }
    if (trim($next['html']) === '') {
        $next['html'] = $current['html'];
    }
    @file_put_contents(crmMarketMessageTemplatePath(), json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $next;
}

/**
 * @return array<string, mixed>
 */
function crmMarketGetSearchSettingsPublic(): array
{
    $cfg = crmMarketSearchLabConfig();
    $file = crmMarketSearchLabConfigPath();
    $extra = [];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }
    $key = (string) ($cfg['key'] ?? '');
    $masked = $key === '' ? '' : (str_repeat('•', max(0, strlen($key) - 4)) . substr($key, -4));
    return [
        'host' => (string) ($cfg['host'] ?? ''),
        'limit' => (int) ($cfg['limit'] ?? 50),
        'lat' => (float) ($cfg['lat'] ?? 0),
        'lng' => (float) ($cfg['lng'] ?? 0),
        'language' => (string) ($cfg['language'] ?? 'en'),
        'region' => (string) ($cfg['region'] ?? 'tz'),
        'mode' => (string) ($extra['mode'] ?? 'manual'),
        'hasKey' => $key !== '',
        'keyMasked' => $masked,
    ];
}

/**
 * Accept a bare RapidAPI key or extract one from a pasted curl / header snippet.
 */
function crmMarketNormalizeApiKey(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/x-rapidapi-key\s*[:=]\s*[\'"]?([A-Za-z0-9_-]+)/i', $raw, $m)) {
        return trim((string) $m[1]);
    }

    // Single-line key: ignore accidental whitespace/newlines from paste.
    if (preg_match('/^[A-Za-z0-9_-]{20,}$/', preg_replace('/\s+/', '', $raw) ?: '')) {
        return preg_replace('/\s+/', '', $raw) ?: '';
    }

    // Fallback: first RapidAPI-looking token in the blob (…msh…jsn…).
    if (preg_match('/([A-Za-z0-9_-]*msh[A-Za-z0-9_-]*jsn[A-Za-z0-9_-]+)/i', $raw, $m)) {
        return trim((string) $m[1]);
    }

    return $raw;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function crmMarketSaveSearchSettings(array $input): array
{
    $file = crmMarketSearchLabConfigPath();
    $current = [];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }
    // Keep host/limit/region/language as stored defaults; Settings UI only updates the API key.
    $next = array_merge([
        'host' => 'local-business-search.p.rapidapi.com',
        'limit' => 50,
        'lat' => -6.369,
        'lng' => 34.889,
        'language' => 'en',
        'region' => 'tz',
        'mode' => 'manual',
        'key' => '',
    ], $current);
    $newKey = crmMarketNormalizeApiKey((string) ($input['key'] ?? $input['apiKey'] ?? ''));
    if ($newKey !== '') {
        $next['key'] = $newKey;
    }
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents($file, $json);
    // Keep legacy path in sync so an old file cannot shadow the saved token.
    foreach (crmMarketSearchLabConfigLegacyPaths() as $legacy) {
        $legacyDir = dirname($legacy);
        if (!is_dir($legacyDir)) {
            @mkdir($legacyDir, 0775, true);
        }
        @file_put_contents($legacy, $json);
    }
    return crmMarketGetSearchSettingsPublic();
}

/**
 * Probe RapidAPI with the saved key (or an unsaved override) via a tiny autocomplete call.
 *
 * @return array{ok:bool,message:string,code:int,normalized_key?:string}
 */
function crmMarketTestSearchApi(?string $keyOverride = null): array
{
    $cfg = crmMarketSearchLabConfig();
    $key = crmMarketNormalizeApiKey((string) (
        $keyOverride !== null && trim((string) $keyOverride) !== ''
            ? $keyOverride
            : ($cfg['key'] ?? '')
    ));
    if ($key === '') {
        return ['ok' => false, 'message' => 'Paste an API token first.', 'code' => 0];
    }

    $host = preg_replace('/^https?:\/\//', '', (string) ($cfg['host'] ?? '')) ?: 'local-business-search.p.rapidapi.com';
    $query = http_build_query([
        'query' => 'hotel',
        'region' => (string) ($cfg['region'] ?? 'tz') ?: 'tz',
        'language' => (string) ($cfg['language'] ?? 'en') ?: 'en',
        'coordinates' => ((float) ($cfg['lat'] ?? -6.369)) . ',' . ((float) ($cfg['lng'] ?? 34.889)),
    ]);
    $url = 'https://' . $host . '/autocomplete?' . $query;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'message' => 'Could not start API test request.', 'code' => 0];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-rapidapi-host: ' . $host,
            'x-rapidapi-key: ' . $key,
        ],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno) {
        return ['ok' => false, 'message' => 'Could not reach the search API. Check your connection.', 'code' => $code];
    }
    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'message' => 'API token was rejected (invalid or unauthorized).', 'code' => $code];
    }
    if ($code === 429) {
        // Key is accepted; plan quota is exhausted. Allow saving so searches work after reset/upgrade.
        return [
            'ok' => true,
            'message' => 'Token is valid, but this month\'s RapidAPI BASIC quota is used up. Search will stay blocked until the quota resets or you upgrade the plan.',
            'code' => $code,
            'normalized_key' => $key,
            'quota_exceeded' => true,
        ];
    }
    if ($code >= 400) {
        $payload = json_decode((string) $raw, true);
        $msg = is_array($payload) ? (string) ($payload['message'] ?? $payload['error'] ?? '') : '';
        return [
            'ok' => false,
            'message' => $msg !== '' ? $msg : ('API test failed (HTTP ' . $code . ').'),
            'code' => $code,
        ];
    }

    return [
        'ok' => true,
        'message' => 'API token works. Search is ready to use.',
        'code' => $code,
        'normalized_key' => $key,
    ];
}

/**
 * Conversion stats for scraped Market leads → CRM customers → quotes / invoices.
 *
 * Attribution is by customer: contacts with market_id in notes and a linked sales customer.
 * Quote/invoice rows are not tagged individually.
 *
 * @return array<string, mixed>
 */
function crmMarketAttributionStats(PDO $crmPdo, int $companyId, int $userId, bool $mine = true): array
{
    require_once __DIR__ . '/crm-sales-bridge.php';
    crmSalesBridgeLoadDeps();

    $currency = 'TZS';
    $empty = [
        'mine' => $mine,
        'leads_assigned' => 0,
        'in_crm' => 0,
        'quotes_count' => 0,
        'quotes_total' => 0.0,
        'quotes_total_formatted' => '-',
        'invoices_count' => 0,
        'invoices_total' => 0.0,
        'invoices_total_formatted' => '-',
        'pipeline_total' => 0.0,
        'pipeline_total_formatted' => '-',
        'currency' => $currency,
        'recent' => [],
        'leads' => [],
        'customers' => [],
        'quotes' => [],
        'invoices' => [],
    ];

    if ($companyId <= 0) {
        return $empty;
    }

    $leadsList = [];
    $leadsAssigned = 0;
    try {
        if ($mine && $userId > 0) {
            $leadsList = crmMarketListLeadsForUser($userId, 5000, '', $companyId);
        } else {
            $leadsList = crmMarketListLeads(5000, '', null, $companyId);
        }
        $leadsAssigned = count($leadsList);
    } catch (Throwable $e) {
        $leadsList = [];
        $leadsAssigned = 0;
    }

    $sql = "
        SELECT id, customer_id, organization, name, created_by, source
        FROM crm_contacts
        WHERE company_id = ?
          AND notes LIKE '%market_id:%'
          AND COALESCE(customer_id, 0) > 0
          AND status <> 'prospect'
    ";
    $params = [$companyId];
    if ($mine && $userId > 0) {
        $sql .= ' AND created_by = ?';
        $params[] = $userId;
    }
    $sql .= ' ORDER BY id DESC';

    $contactByCustomer = [];
    try {
        $stmt = $crmPdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($customerId <= 0) {
                continue;
            }
            // Prefer the newest contact row per customer.
            if (!isset($contactByCustomer[$customerId])) {
                $contactByCustomer[$customerId] = [
                    'contact_id' => (int) ($row['id'] ?? 0),
                    'customer_id' => $customerId,
                    'company' => trim((string) ($row['organization'] ?? ''))
                        ?: trim((string) ($row['name'] ?? ''))
                        ?: ('Customer #' . $customerId),
                    'source' => trim((string) ($row['source'] ?? '')),
                ];
            }
        }
    } catch (Throwable $e) {
        return array_merge($empty, [
            'leads_assigned' => $leadsAssigned,
            'leads' => $leadsList,
        ]);
    }

    $customers = array_values($contactByCustomer);
    $inCrm = count($customers);
    $customerIds = array_keys($contactByCustomer);
    if ($customerIds === []) {
        return array_merge($empty, [
            'leads_assigned' => $leadsAssigned,
            'leads' => $leadsList,
            'in_crm' => 0,
            'customers' => [],
        ]);
    }

    $salesDb = customersDeskSalesDb();
    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $module = 'sales';

    $quotes = [];
    $quotesCount = 0;
    $quotesTotal = 0.0;
    $invoices = [];
    $invoicesCount = 0;
    $invoicesTotal = 0.0;
    $recent = [];

    try {
        $hasOrders = !function_exists('tableExists') || tableExists('sales_orders', $salesDb);
        if ($hasOrders) {
            $sqlQ = "
                SELECT so.id, so.customer_id, so.order_number, so.quote_date, so.created_at,
                       so.total_amount, so.status, so.currency, u.full_name AS salesperson
                FROM sales_orders so
                LEFT JOIN users u ON so.created_by = u.id
                WHERE so.customer_id IN ({$placeholders})
                  AND LOWER(TRIM(COALESCE(so.status, ''))) NOT IN ('cancelled', 'canceled')
            ";
            $paramsQ = $customerIds;
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($sqlQ, $paramsQ, 'sales_orders', 'so');
            }
            $sqlQ .= ' ORDER BY COALESCE(so.quote_date, so.created_at) DESC, so.id DESC';
            $stmtQ = $salesDb->prepare($sqlQ);
            $stmtQ->execute($paramsQ);
            foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $customerId = (int) ($row['customer_id'] ?? 0);
                $amount = (float) ($row['total_amount'] ?? 0);
                $rowCurrency = trim((string) ($row['currency'] ?? ''));
                if ($rowCurrency === '') {
                    $rowCurrency = $currency;
                }
                $statusKey = strtolower(trim((string) ($row['status'] ?? '')));
                $converted = in_array($statusKey, ['invoiced', 'paid'], true);
                $quotesCount++;
                $quotesTotal += $amount;
                $date = trim((string) ($row['quote_date'] ?? ''));
                if ($date === '') {
                    $date = trim((string) ($row['created_at'] ?? ''));
                }
                $orderId = (int) ($row['id'] ?? 0);
                $meta = $contactByCustomer[$customerId] ?? null;
                $linkedInvoiceId = 0;
                $linkedInvoiceNumber = '';
                if ($converted && $orderId > 0) {
                    try {
                        $invStmt = $salesDb->prepare(
                            'SELECT id, invoice_number FROM invoices WHERE order_id = ? ORDER BY id DESC LIMIT 1'
                        );
                        $invStmt->execute([$orderId]);
                        $invRow = $invStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($invRow) {
                            $linkedInvoiceId = (int) ($invRow['id'] ?? 0);
                            $linkedInvoiceNumber = trim((string) ($invRow['invoice_number'] ?? ''));
                        }
                    } catch (Throwable $e) {
                        /* ignore */
                    }
                }
                $item = [
                    'kind' => 'quote',
                    'id' => $orderId,
                    'number' => (string) ($row['order_number'] ?? ''),
                    'date' => $date,
                    'status' => (string) ($row['status'] ?? ''),
                    'converted' => $converted,
                    'invoice_id' => $linkedInvoiceId,
                    'invoice_number' => $linkedInvoiceNumber,
                    'salesperson' => (string) ($row['salesperson'] ?? ''),
                    'amount' => $amount,
                    'amount_formatted' => crmSalesBridgeFormatInvoiceAmount($amount, $rowCurrency),
                    'customer_id' => $customerId,
                    'contact_id' => $meta ? (int) $meta['contact_id'] : 0,
                    'company' => $meta ? (string) $meta['company'] : ('Customer #' . $customerId),
                    'view_url' => function_exists('sales_module_url') && $orderId > 0
                        ? sales_module_url('orders/view.php', ['id' => $orderId, 'module' => $module])
                        : '',
                    'download_url' => function_exists('sales_module_url') && $orderId > 0
                        ? sales_module_url('orders/print.php', ['id' => $orderId, 'download' => 1, 'module' => $module])
                        : '',
                ];
                $quotes[] = $item;
                $recent[] = $item;
            }
        }
    } catch (Throwable $e) {
        // keep quote totals at zero
    }

    try {
        $hasInvoices = !function_exists('tableExists') || tableExists('invoices', $salesDb);
        if ($hasInvoices) {
            $hasInvoiceCreatedBy = function_exists('columnExists') && columnExists('invoices', 'created_by', $salesDb);
            $hasInvoiceOrderId = function_exists('columnExists') && columnExists('invoices', 'order_id', $salesDb);
            $salespersonSelect = $hasInvoiceCreatedBy ? 'u.full_name AS salesperson' : "'' AS salesperson";
            $orderIdSelect = $hasInvoiceOrderId ? 'i.order_id' : '0 AS order_id';
            $userJoin = $hasInvoiceCreatedBy ? 'LEFT JOIN users u ON i.created_by = u.id' : '';
            $sqlI = "
                SELECT i.id, i.customer_id, i.invoice_number, i.invoice_date, i.created_at,
                       i.total_amount, i.status, i.currency, {$orderIdSelect}, {$salespersonSelect}
                FROM invoices i
                {$userJoin}
                WHERE i.customer_id IN ({$placeholders})
                  AND LOWER(TRIM(COALESCE(i.status, ''))) <> 'cancelled'
            ";
            $paramsI = $customerIds;
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($sqlI, $paramsI, 'invoices', 'i');
            }
            $sqlI .= ' ORDER BY COALESCE(i.invoice_date, i.created_at) DESC, i.id DESC';
            $stmtI = $salesDb->prepare($sqlI);
            $stmtI->execute($paramsI);
            foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $customerId = (int) ($row['customer_id'] ?? 0);
                $amount = (float) ($row['total_amount'] ?? 0);
                $rowCurrency = trim((string) ($row['currency'] ?? ''));
                if ($rowCurrency === '') {
                    $rowCurrency = $currency;
                }
                $invoicesCount++;
                $invoicesTotal += $amount;
                $date = trim((string) ($row['invoice_date'] ?? ''));
                if ($date === '') {
                    $date = trim((string) ($row['created_at'] ?? ''));
                }
                $invoiceId = (int) ($row['id'] ?? 0);
                $fromQuoteId = (int) ($row['order_id'] ?? 0);
                $meta = $contactByCustomer[$customerId] ?? null;
                $item = [
                    'kind' => 'invoice',
                    'id' => $invoiceId,
                    'number' => (string) ($row['invoice_number'] ?? ''),
                    'date' => $date,
                    'status' => (string) ($row['status'] ?? ''),
                    'from_quote' => $fromQuoteId > 0,
                    'order_id' => $fromQuoteId,
                    'salesperson' => (string) ($row['salesperson'] ?? ''),
                    'amount' => $amount,
                    'amount_formatted' => crmSalesBridgeFormatInvoiceAmount($amount, $rowCurrency),
                    'customer_id' => $customerId,
                    'contact_id' => $meta ? (int) $meta['contact_id'] : 0,
                    'company' => $meta ? (string) $meta['company'] : ('Customer #' . $customerId),
                    'view_url' => function_exists('sales_module_url') && $invoiceId > 0
                        ? sales_module_url('invoices/view.php', ['id' => $invoiceId, 'module' => $module])
                        : '',
                    'download_url' => function_exists('sales_module_url') && $invoiceId > 0
                        ? sales_module_url('invoices/print.php', ['id' => $invoiceId, 'download' => 1, 'module' => $module])
                        : '',
                ];
                $invoices[] = $item;
                $recent[] = $item;
            }
        }
    } catch (Throwable $e) {
        // keep invoice totals at zero
    }

    usort($recent, static function (array $a, array $b): int {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });
    $recent = array_slice($recent, 0, 10);

    return [
        'mine' => $mine,
        'leads_assigned' => $leadsAssigned,
        'in_crm' => $inCrm,
        'quotes_count' => $quotesCount,
        'quotes_total' => $quotesTotal,
        'quotes_total_formatted' => crmSalesBridgeFormatInvoiceAmount($quotesTotal, $currency),
        'invoices_count' => $invoicesCount,
        'invoices_total' => $invoicesTotal,
        'invoices_total_formatted' => crmSalesBridgeFormatInvoiceAmount($invoicesTotal, $currency),
        'pipeline_total' => $quotesTotal,
        'pipeline_total_formatted' => crmSalesBridgeFormatInvoiceAmount($quotesTotal, $currency),
        'currency' => $currency,
        'recent' => $recent,
        'leads' => $leadsList,
        'customers' => $customers,
        'quotes' => $quotes,
        'invoices' => $invoices,
    ];
}
