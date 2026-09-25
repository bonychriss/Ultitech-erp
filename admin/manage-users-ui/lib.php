<?php

declare(strict_types=1);

require_once __DIR__ . '/service.inc.php';

function manageUsersUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (preg_match('#^(.*?)/[A-Za-z0-9-]+/admin$#', $dir, $m)) {
            return rtrim($m[1] . '/admin', '/') ?: '/admin';
        }
        if (substr($dir, -6) === '/admin') {
            return $dir;
        }
    }
    return '/admin';
}

function manageUsersUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return manageUsersUiWebBasePath() . '/manage-users-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function manageUsersUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => manageUsersUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function manageUsersUiShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous">',
        '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>',
    ];
    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 2) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }
    return implode("\n    ", $parts);
}

function manageUsersUiRequireAdmin(): void
{
    requireAdmin();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'voucher';
    }
    $_SESSION['active_module'] = (string) $_GET['module'];
}

function manageUsersUiPdo(): PDO
{
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    throw new RuntimeException('Database connection unavailable.');
}

function manageUsersFlashPasswordOnce($userName, $plainPassword): void
{
    $_SESSION['manage_users_pw_flash'] = [
        'name' => $userName,
        'password' => $plainPassword,
        'at' => time(),
    ];
}

/**
 * @return array<string, mixed>|false
 */
function manageUsersFetchUser($pdo, $userId, $hasCompanyId, $companyId)
{
    if ($userId <= 0) {
        return false;
    }
    if ($hasCompanyId) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$userId, $companyId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : false;
}

function manageUsersIsSystemAdminRow($user): bool
{
    return strtolower(trim((string) ($user['username'] ?? ''))) === 'admin'
        || strtolower(trim((string) ($user['email'] ?? ''))) === 'admin@ultimatetrading.com';
}

/**
 * @return int[]
 */
function manageUsersBulkResetEligibleIds($pdo, $hasCompanyId, $companyId, $currentUserId, $includeSelf, $includeSystemAdmin): array
{
    $selectCols = ['id', 'username'];
    if (function_exists('columnExists') && columnExists('users', 'email', $pdo)) {
        $selectCols[] = 'email';
    }
    $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM users';
    if ($hasCompanyId && $companyId > 0) {
        $sql .= ' WHERE company_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->query($sql);
    }
    if (!$stmt) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (!$includeSelf && $id === $currentUserId) {
            continue;
        }
        if (!$includeSystemAdmin && manageUsersIsSystemAdminRow($row)) {
            continue;
        }
        $ids[] = $id;
    }

    return $ids;
}

/**
 * @return array<string,mixed>
 */
function manageUsersUiGetPayload(): array
{
    $state = manageUsersUi_collect_state();
    $users = [];
    foreach ($state['users'] as $u) {
        if (!is_array($u)) {
            continue;
        }
        $users[] = [
            'id' => (int) ($u['id'] ?? 0),
            'username' => (string) ($u['username'] ?? ''),
            'full_name' => (string) ($u['full_name'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'department' => (string) ($u['department'] ?? ''),
            'role' => (string) ($u['role'] ?? ''),
            'is_active' => (int) ($u['is_active'] ?? 0),
            'voucher_count' => (int) ($u['voucher_count'] ?? 0),
            'approved_amount' => (float) ($u['approved_amount'] ?? 0),
            'created_at' => (string) ($u['created_at'] ?? ''),
            'is_system_admin' => manageUsersIsSystemAdminRow($u),
        ];
    }

    return [
        'formAction' => (string) ($state['formAction'] ?? 'manage-users.php'),
        'currentUserId' => (int) ($state['currentUserId'] ?? 0),
        'success' => (string) ($state['success'] ?? ''),
        'error' => (string) ($state['error'] ?? ''),
        'users' => $users,
        'stats' => [
            'total' => count($users),
            'activeAdmins' => (int) ($state['activeAdmins'] ?? 0),
            'activeEmployees' => (int) ($state['activeEmployees'] ?? 0),
            'departments' => count($state['deptStats'] ?? []),
        ],
        'bulkResetEligibleCount' => (int) ($state['bulkResetEligibleCount'] ?? 0),
        'bulkHasSystemAdmin' => !empty($state['bulkHasSystemAdmin']),
        'departments' => $state['departments'] ?? [],
        'pwFlash' => $state['pwFlash'],
        'bulkPwFlash' => $state['bulkPwFlash'],
    ];
}

/**
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function manageUsersUiWindowCfg(array $extra = []): array
{
    return array_merge([
        'module' => (string) ($_GET['module'] ?? 'voucher'),
        'engine' => 'erp-laravel Domains/Admin + admin/manage-users-ui',
        'initial' => manageUsersUiGetPayload(),
    ], $extra);
}
