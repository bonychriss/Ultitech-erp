<?php

declare(strict_types=1);

if (!defined('ERP_REMEMBER_ME_DAYS')) {
    define('ERP_REMEMBER_ME_DAYS', 30);
}

function erpRememberMeCookiePath(): string
{
    if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '' && APP_BASE_PATH !== '/') {
        return rtrim((string) APP_BASE_PATH, '/') . '/';
    }

    return '/';
}

function erpRememberMeCookieOptions(int $lifetime, bool $httpOnly = true): array
{
    if (PHP_VERSION_ID >= 70300) {
        return [
            'expires' => $lifetime > 0 ? time() + $lifetime : time() - 3600,
            'path' => erpRememberMeCookiePath(),
            'domain' => '',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ];
    }

    return [];
}

function ensureRememberMeSchema(): bool
{
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
    if (!($usePdo instanceof PDO)) {
        return false;
    }

    static $ready = false;
    if ($ready) {
        return true;
    }

    try {
        $usePdo->exec("
            CREATE TABLE IF NOT EXISTS user_remember_tokens (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                company_slug VARCHAR(160) NOT NULL,
                login_hint VARCHAR(190) NULL,
                selector VARCHAR(32) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                UNIQUE KEY uq_user_remember_selector (selector),
                KEY idx_user_remember_user (user_id, company_slug),
                KEY idx_user_remember_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ready = true;

        return true;
    } catch (Throwable $e) {
        error_log('ensureRememberMeSchema: ' . $e->getMessage());

        return false;
    }
}

function erpRememberMeLoginHintCookieName(): string
{
    return 'erp_login_user';
}

function erpRememberMeTokenCookieName(): string
{
    return 'erp_remember';
}

function setRememberMeLoginHint(string $loginHint): void
{
    $loginHint = trim($loginHint);
    $lifetime = 86400 * 365;
    $opts = erpRememberMeCookieOptions($lifetime, true);
    if ($opts !== []) {
        setcookie(erpRememberMeLoginHintCookieName(), $loginHint, $opts);

        return;
    }
    setcookie(
        erpRememberMeLoginHintCookieName(),
        $loginHint,
        time() + $lifetime,
        erpRememberMeCookiePath(),
        '',
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        true
    );
}

function clearRememberMeLoginHint(): void
{
    $opts = erpRememberMeCookieOptions(0, true);
    if ($opts !== []) {
        setcookie(erpRememberMeLoginHintCookieName(), '', $opts);

        return;
    }
    setcookie(
        erpRememberMeLoginHintCookieName(),
        '',
        time() - 3600,
        erpRememberMeCookiePath(),
        '',
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        true
    );
}

function getRememberMeLoginHint(): string
{
    return trim((string) ($_COOKIE[erpRememberMeLoginHintCookieName()] ?? ''));
}

function issueRememberMeToken(int $userId, string $companySlug, string $loginHint = ''): void
{
    if ($userId <= 0) {
        return;
    }

    $companySlug = strtolower(trim($companySlug));
    if ($companySlug === '') {
        return;
    }

    if (!ensureRememberMeSchema()) {
        return;
    }

    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
    if (!($usePdo instanceof PDO)) {
        return;
    }

    clearRememberMeToken();

    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $hash = password_hash($validator, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable('now'))
        ->modify('+' . (int) ERP_REMEMBER_ME_DAYS . ' days')
        ->format('Y-m-d H:i:s');

    $stmt = $usePdo->prepare(
        'INSERT INTO user_remember_tokens (user_id, company_slug, login_hint, selector, token_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $companySlug,
        trim($loginHint) !== '' ? trim($loginHint) : null,
        $selector,
        $hash,
        $expiresAt,
    ]);

    $cookieValue = $selector . ':' . $validator;
    $lifetime = 86400 * (int) ERP_REMEMBER_ME_DAYS;
    $opts = erpRememberMeCookieOptions($lifetime, true);
    if ($opts !== []) {
        setcookie(erpRememberMeTokenCookieName(), $cookieValue, $opts);
    } else {
        setcookie(
            erpRememberMeTokenCookieName(),
            $cookieValue,
            time() + $lifetime,
            erpRememberMeCookiePath(),
            '',
            !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            true
        );
    }

    if (trim($loginHint) !== '') {
        setRememberMeLoginHint($loginHint);
    }
}

function clearRememberMeToken(?string $selector = null): void
{
    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;

    if ($selector === null || $selector === '') {
        $raw = trim((string) ($_COOKIE[erpRememberMeTokenCookieName()] ?? ''));
        if ($raw !== '' && strpos($raw, ':') !== false) {
            $selector = trim((string) strtok($raw, ':'));
        }
    }

    if ($selector !== null && $selector !== '' && ensureRememberMeSchema() && ($usePdo instanceof PDO)) {
        try {
            $usePdo->prepare('DELETE FROM user_remember_tokens WHERE selector = ?')->execute([$selector]);
        } catch (Throwable $e) {
        }
    }

    $opts = erpRememberMeCookieOptions(0, true);
    if ($opts !== []) {
        setcookie(erpRememberMeTokenCookieName(), '', $opts);
    } else {
        setcookie(
            erpRememberMeTokenCookieName(),
            '',
            time() - 3600,
            erpRememberMeCookiePath(),
            '',
            !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            true
        );
    }
}

function loginUserById(int $userId, string $companySlug): bool
{
    $userId = (int) $userId;
    $companySlug = strtolower(trim($companySlug));
    if ($userId <= 0 || $companySlug === '') {
        return false;
    }

    if (!function_exists('findCompanyBySlug') || !function_exists('connectToTenantDatabase')) {
        return false;
    }

    $selectedCompany = findCompanyBySlug($companySlug);
    if (!$selectedCompany || strtolower((string) ($selectedCompany['status'] ?? 'inactive')) !== 'active') {
        return false;
    }

    global $pdo, $control_pdo;
    $tenantDb = trim((string) ($selectedCompany['db_name'] ?? ''));
    $tenantHost = trim((string) ($selectedCompany['db_host'] ?? ''));
    $tenantUser = trim((string) ($selectedCompany['db_user'] ?? ''));
    $tenantPass = array_key_exists('db_pass', (array) $selectedCompany)
        ? (trim((string) ($selectedCompany['db_pass'] ?? '')) !== '' ? (string) $selectedCompany['db_pass'] : null)
        : null;

    $authPdo = $pdo;
    if ($tenantDb !== '') {
        $effectiveTenant = resolveEffectiveTenantDbConnection($tenantDb, $tenantHost, $tenantUser, $tenantPass);
        $tenantPdo = connectToTenantDatabase(
            $effectiveTenant['db_name'],
            $effectiveTenant['host'],
            $effectiveTenant['user'],
            $effectiveTenant['pass']
        );
        if ($tenantPdo instanceof PDO) {
            $authPdo = $tenantPdo;
        }
    }

    if (!($authPdo instanceof PDO)) {
        return false;
    }

    $whereParts = ['id = ?'];
    $params = [$userId];
    if (columnExists('users', 'is_active', $authPdo)) {
        $whereParts[] = 'is_active = 1';
    }
    if (columnExists('users', 'status', $authPdo)) {
        $whereParts[] = "(status = 'active' OR status = '')";
    }
    if (columnExists('users', 'approval_status', $authPdo)) {
        $whereParts[] = "(approval_status = 'approved' OR approval_status = 'active' OR approval_status = '')";
    }

    $emailSelect = columnExists('users', 'email', $authPdo) ? ', email' : '';
    $sql = 'SELECT id, username, full_name, role, department' . $emailSelect
        . ' FROM users WHERE ' . implode(' AND ', $whereParts) . ' LIMIT 1';
    $stmt = $authPdo->prepare($sql);
    $stmt->execute($params);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    @session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) ($user['username'] ?? '');
    $_SESSION['full_name'] = (string) ($user['full_name'] ?? '');
    $_SESSION['role'] = (string) ($user['role'] ?? '');
    $_SESSION['department'] = (string) ($user['department'] ?? '');
    if (!empty($user['email']) && function_exists('normalizeLoginEmail')) {
        $_SESSION['email'] = normalizeLoginEmail((string) $user['email']);
    }

    $_SESSION['company_id'] = (int) ($selectedCompany['id'] ?? 0);
    $_SESSION['company_name'] = (string) ($selectedCompany['company_name'] ?? '');
    $_SESSION['company_slug'] = (string) ($selectedCompany['company_slug'] ?? $companySlug);

    if (function_exists('applyWinningCompanySession')) {
        applyWinningCompanySession($companySlug, $control_pdo ?? null);
    }

    return true;
}

function attemptRememberMeLogin(): bool
{
    if (!empty($_SESSION['user_id'])) {
        return true;
    }

    $raw = trim((string) ($_COOKIE[erpRememberMeTokenCookieName()] ?? ''));
    if ($raw === '' || strpos($raw, ':') === false) {
        return false;
    }

    [$selector, $validator] = array_pad(explode(':', $raw, 2), 2, '');
    $selector = trim($selector);
    $validator = trim($validator);
    if ($selector === '' || $validator === '') {
        clearRememberMeToken($selector);

        return false;
    }

    if (!ensureRememberMeSchema()) {
        return false;
    }

    global $control_pdo, $pdo;
    $usePdo = ($control_pdo ?? null) instanceof PDO ? $control_pdo : $pdo;
    if (!($usePdo instanceof PDO)) {
        return false;
    }

    try {
        $stmt = $usePdo->prepare(
            'SELECT id, user_id, company_slug, login_hint, token_hash, expires_at
             FROM user_remember_tokens
             WHERE selector = ?
             LIMIT 1'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }

    if (!$row) {
        clearRememberMeToken($selector);

        return false;
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if ($expiresAt !== false && $expiresAt < time()) {
        clearRememberMeToken($selector);

        return false;
    }

    if (!password_verify($validator, (string) ($row['token_hash'] ?? ''))) {
        clearRememberMeToken($selector);

        return false;
    }

    $userId = (int) ($row['user_id'] ?? 0);
    $companySlug = strtolower(trim((string) ($row['company_slug'] ?? '')));
    if ($userId <= 0 || $companySlug === '') {
        clearRememberMeToken($selector);

        return false;
    }

    if (!loginUserById($userId, $companySlug)) {
        clearRememberMeToken($selector);

        return false;
    }

    try {
        $usePdo->prepare('UPDATE user_remember_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int) ($row['id'] ?? 0)]);
    } catch (Throwable $e) {
    }

    $loginHint = trim((string) ($row['login_hint'] ?? ''));
    if ($loginHint !== '') {
        setRememberMeLoginHint($loginHint);
    }

    issueRememberMeToken($userId, $companySlug, $loginHint);

    return true;
}
