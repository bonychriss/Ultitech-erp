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

function manageUsersCompanyLabel(): string
{
    $name = trim((string) ($_SESSION['company_name'] ?? ''));
    if ($name === '' && defined('COMPANY_NAME')) {
        $name = trim((string) COMPANY_NAME);
    }

    return $name;
}

function manageUsersCompanyTagline(): string
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('company_tagline', 'tagline') AND setting_value <> '' LIMIT 1");
        $stmt->execute();

        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function manageUsersFirstName(string $fullName): string
{
    $fullName = trim($fullName);
    $parts = preg_split('/\s+/', $fullName) ?: [];

    return $parts[0] ?? $fullName;
}

/**
 * Titles must match notificationsUiAccountNoticeKind() so the Account icon is used.
 */
function manageUsersNotifyPasswordChanged(int $userId): void
{
    if ($userId <= 0 || !function_exists('createSystemNotification')) {
        return;
    }
    try {
        createSystemNotification(
            $userId,
            'Your password was changed',
            'An administrator reset your login password on ' . date('d M Y \a\t H:i')
                . '. If you did not request this, contact your administrator right away.',
            function_exists('company_url') ? company_url('employee/account.php') : null,
            'info'
        );
    } catch (Throwable $e) {
        error_log('manageUsersNotifyPasswordChanged: ' . $e->getMessage());
    }
}

/**
 * @param list<int> $userIds
 */
function manageUsersNotifyPasswordChangedMany(array $userIds): void
{
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        manageUsersNotifyPasswordChanged($uid);
    }
}

function manageUsersNotifyNewEmployee(PDO $pdo, int $newUserId, string $fullName, string $department, bool $hasCompanyId, int $companyId): void
{
    if ($newUserId <= 0 || !function_exists('createSystemNotification')) {
        return;
    }
    $company = manageUsersCompanyLabel();
    $first = manageUsersFirstName($fullName);
    $dept = trim($department);

    try {
        createSystemNotification(
            $newUserId,
            'Welcome aboard, ' . $first . '!',
            'We are so glad to have you' . ($company !== '' ? ' at ' . $company : ' on the team')
                . '. Take a moment to complete your profile and add a photo so your colleagues can get to know you.',
            function_exists('company_url') ? company_url('employee/account.php') : null,
            'success'
        );
    } catch (Throwable $e) {
        error_log('manageUsersNotifyNewEmployee welcome: ' . $e->getMessage());
    }

    try {
        $sql = 'SELECT id FROM users WHERE id <> ? AND (is_active = 1 OR is_active IS NULL)';
        $params = [$newUserId];
        if ($hasCompanyId) {
            $sql .= ' AND company_id = ?';
            $params[] = $companyId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        error_log('manageUsersNotifyNewEmployee recipients: ' . $e->getMessage());
        $ids = [];
    }

    $title = 'New team member: ' . trim($fullName);
    $message = trim($fullName) . ' has joined' . ($dept !== '' ? ' the ' . $dept . ' department' : ' the team')
        . '. Say hello and help them feel welcome!';
    foreach ($ids as $uid) {
        try {
            createSystemNotification($uid, $title, $message, null, 'info');
        } catch (Throwable $e) {
            error_log('manageUsersNotifyNewEmployee broadcast: ' . $e->getMessage());
        }
    }
}

function manageUsersAbsoluteUrl(string $url): string
{
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return ($https ? 'https://' : 'http://') . $host . '/' . ltrim($url, '/');
}

/**
 * Table-based, inline-styled markup: email clients strip <style>, flexbox and SVG.
 *
 * @param array{first:string,email:string,department:string,company:string,loginUrl:string,logo:string,tagline?:string} $d
 */
function manageUsersWelcomeEmailHtml(array $d): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $company = trim($d['company']);
    $url = $e($d['loginUrl']);
    $tile = static fn (string $emoji, string $bg): string =>
        '<td width="40" valign="middle" style="width:40px;"><div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:10px;background:' . $bg . ';font-size:18px;">' . $emoji . '</div></td>';

    $brand = $d['logo'] !== ''
        ? '<img src="' . $e($d['logo']) . '" alt="' . $e($company) . '" height="44" style="display:block;height:44px;width:auto;border:0;">'
        : '<span style="' . $font . 'font-size:18px;font-weight:800;color:#1f2937;">' . $e($company !== '' ? $company : 'Welcome') . '</span>';

    $deptLine = $d['department'] !== ''
        ? 'Your account has been created for you in the <strong style="color:#6d28d9;">' . $e($d['department']) . '</strong> department.'
        : 'Your account has been created and is ready to use.';

    $feature = static fn (string $emoji, string $bg, string $title, string $text): string =>
        '<td valign="top" width="33%" style="padding:0 6px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td valign="top" style="padding-right:10px;"><div style="width:34px;height:34px;line-height:34px;text-align:center;border-radius:9px;background:' . $bg . ';font-size:16px;">' . $emoji . '</div></td>'
        . '<td valign="top" style="' . $font . '"><div style="font-size:13px;font-weight:700;color:#1f2937;">' . $title . '</div>'
        . '<div style="font-size:12px;line-height:1.45;color:#6b7280;margin-top:2px;">' . $text . '</div></td>'
        . '</tr></table></td>';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>Welcome aboard</title></head>'
        . '<body style="margin:0;padding:0;background:#f3f0ff;' . $font . 'color:#1f2937;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your ' . $e($company !== '' ? $company : '') . ' account is ready. Sign in to get started.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f0ff;background-image:linear-gradient(180deg,#ede9fe 0%,#f8f7ff 60%,#f3f0ff 100%);"><tr><td align="center" style="padding:32px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #ece8fb;border-radius:22px;box-shadow:0 18px 50px rgba(76,29,149,0.10);overflow:hidden;">'

        // Header: logo + tagline over a soft lavender wash
        . '<tr><td style="padding:26px 32px 0;background:#ffffff;background-image:radial-gradient(120% 90% at 100% 0%,#ede9fe 0%,rgba(237,233,254,0) 60%);">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td valign="middle">' . $brand . '</td>'
        . (trim($d['tagline'] ?? '') !== ''
            ? '<td valign="middle" align="right" style="' . $font . 'font-size:12px;line-height:1.5;color:#6b7280;max-width:180px;">' . $e(trim($d['tagline']))
                . '<div style="width:34px;height:2px;background:#8b5cf6;border-radius:2px;margin:6px 0 0 auto;"></div></td>'
            : '')
        . '</tr></table></td></tr>'

        // Badge + headline
        . '<tr><td align="center" style="padding:26px 32px 0;">'
        . '<span style="display:inline-block;padding:6px 14px;border-radius:999px;background:#ede9fe;color:#5b21b6;font-size:11px;font-weight:700;letter-spacing:.08em;' . $font . '">&#128100;&nbsp; NEW TEAM MEMBER</span>'
        . '<h1 style="margin:16px 0 0;' . $font . 'font-size:32px;line-height:1.2;font-weight:800;color:#111827;letter-spacing:-0.02em;">Welcome aboard, <span style="color:#7c3aed;">' . $e($d['first']) . '!</span> &#127881;</h1>'
        . '<p style="margin:14px 0 0;' . $font . 'font-size:15px;line-height:1.65;color:#4b5563;">We are so glad to have you on the team!<br>' . $deptLine . '<br>Use the button below to sign in and get started.</p>'
        . '</td></tr>'

        // Credentials card
        . '<tr><td style="padding:24px 32px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf9ff;border:1px solid #ece8fb;border-radius:16px;">'
        . '<tr><td style="padding:16px 18px;border-bottom:1px solid #ece8fb;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . $tile('&#9993;&#65039;', '#ede9fe')
        . '<td valign="middle" style="padding-left:14px;' . $font . '"><div style="font-size:12px;color:#6b7280;">Login email</div>'
        . '<div style="font-size:15px;font-weight:700;color:#111827;margin-top:2px;word-break:break-all;">' . $e($d['email']) . '</div></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="padding:16px 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . $tile('&#128274;', '#ede9fe')
        . '<td valign="middle" style="padding-left:14px;' . $font . '"><div style="font-size:12px;color:#6b7280;">Password</div>'
        . '<div style="font-size:15px;color:#111827;margin-top:2px;">Provided by your administrator</div></td>'
        . '</tr></table></td></tr>'
        . '</table></td></tr>'

        // CTA button
        . '<tr><td align="center" style="padding:22px 32px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="border-radius:14px;background:#7c3aed;background-image:linear-gradient(90deg,#7c3aed 0%,#8b5cf6 100%);box-shadow:0 10px 24px rgba(124,58,237,0.30);">'
        . '<a href="' . $url . '" target="_blank" style="display:block;padding:16px 24px;' . $font . 'font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:14px;">&#10140;&nbsp; Log in to your account &nbsp;&rarr;</a>'
        . '</td></tr></table></td></tr>'

        // "or" divider
        . '<tr><td style="padding:18px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="45%" style="border-top:1px solid #e5e7eb;font-size:0;line-height:0;">&nbsp;</td>'
        . '<td align="center" style="padding:0 10px;' . $font . 'font-size:12px;color:#9ca3af;">or</td>'
        . '<td width="45%" style="border-top:1px solid #e5e7eb;font-size:0;line-height:0;">&nbsp;</td>'
        . '</tr></table></td></tr>'

        // Direct link
        . '<tr><td style="padding:18px 32px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#faf9ff;border:1px solid #ece8fb;border-radius:14px;"><tr><td style="padding:14px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $tile('&#128279;', '#ede9fe')
        . '<td valign="middle" style="padding-left:14px;' . $font . '"><div style="font-size:12px;color:#6b7280;">Direct link</div>'
        . '<a href="' . $url . '" target="_blank" style="font-size:14px;color:#6d28d9;text-decoration:none;word-break:break-all;">' . $url . '</a></td>'
        . '</tr></table></td></tr></table></td></tr>'

        // Security note
        . '<tr><td style="padding:14px 32px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ff;border:1px solid #e4dcfd;border-radius:14px;"><tr><td style="padding:14px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $tile('&#8505;&#65039;', '#ffffff')
        . '<td valign="middle" style="padding-left:14px;' . $font . '"><div style="font-size:14px;font-weight:700;color:#5b21b6;">Not expecting this email?</div>'
        . '<div style="font-size:13px;line-height:1.5;color:#4b5563;margin-top:2px;">If you did not expect this email, you can safely ignore it or contact your administrator for assistance. Please change your password after your first login.</div></td>'
        . '</tr></table></td></tr></table></td></tr>'

        // Feature row
        . '<tr><td style="padding:26px 26px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . $feature('&#128101;', '#ede9fe', 'Connect with your team', 'Get to know your colleagues and managers.')
        . $feature('&#128221;', '#e0f2fe', 'Complete your profile', 'Add a photo and update your details.')
        . $feature('&#129513;', '#dcfce7', 'Explore the system', 'Access the modules and tools you need.')
        . '</tr></table></td></tr>'

        // Signature footer
        . '<tr><td align="center" style="padding:24px 32px 28px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="30%" style="border-top:1px solid #ece8fb;font-size:0;line-height:0;">&nbsp;</td>'
        . '<td align="center" style="padding:0 12px;font-family:\'Brush Script MT\',\'Segoe Script\',cursive;font-size:22px;color:#7c3aed;white-space:nowrap;">Welcome to the team!</td>'
        . '<td width="30%" style="border-top:1px solid #ece8fb;font-size:0;line-height:0;">&nbsp;</td>'
        . '</tr></table>'
        . ($company !== '' ? '<div style="margin-top:14px;' . $font . 'font-size:11px;color:#9ca3af;">&copy; ' . date('Y') . ' ' . $e($company) . '</div>' : '')
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';
}

/**
 * Email the new employee a link to the company login page. Never includes the password.
 */
function manageUsersSendLoginLinkEmail(string $email, string $fullName, string $department): bool
{
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!function_exists('sendEmail')) {
        $mailer = dirname(__DIR__, 2) . '/includes/mailer.php';
        if (is_file($mailer)) {
            require_once $mailer;
        }
    }
    if (!function_exists('sendEmail')) {
        return false;
    }

    $company = manageUsersCompanyLabel();
    $loginUrl = manageUsersAbsoluteUrl(function_exists('company_url') ? company_url('login') : 'login.php');
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $first = manageUsersFirstName($fullName);
    $where = $company !== '' ? $company : 'the team';

    $subject = 'Welcome to ' . $where . ' - your account is ready';
    $logo = function_exists('getCompanyLogoUrl') ? manageUsersAbsoluteUrl((string) getCompanyLogoUrl()) : '';
    $body = manageUsersWelcomeEmailHtml([
        'first' => $first,
        'email' => $email,
        'department' => $department,
        'company' => $company,
        'loginUrl' => $loginUrl,
        'logo' => $logo,
        'tagline' => manageUsersCompanyTagline(),
    ]);

    try {
        return (bool) @sendEmail($email, $subject, $body, true);
    } catch (Throwable $ex) {
        error_log('manageUsersSendLoginLinkEmail: ' . $ex->getMessage());

        return false;
    }
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
 * Department labels usable as access roles (primary + extras).
 *
 * @param list<string> $departments
 * @return list<string>
 */
function manageUsersAccessRoleOptions(array $departments = []): array
{
    $base = $departments !== []
        ? $departments
        : ['General', 'Procurement', 'IT', 'Finance', 'Sales', 'Driver', 'Warehouse', 'Management'];
    $extras = ['Store', 'Inventory'];
    $out = [];
    foreach (array_merge($base, $extras) as $item) {
        $name = trim((string) $item);
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name);
        if (isset($out[$key])) {
            continue;
        }
        $out[$key] = $name;
    }
    if (!isset($out['warehouse'])) {
        $out['warehouse'] = 'Warehouse';
    }
    return array_values($out);
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
            'extra_roles' => function_exists('parseUserExtraRoles')
                ? parseUserExtraRoles($u['extra_roles'] ?? null)
                : [],
            'access_roles' => function_exists('userAccessRolesFromParts')
                ? userAccessRolesFromParts((string) ($u['department'] ?? ''), $u['extra_roles'] ?? null)
                : array_values(array_filter([(string) ($u['department'] ?? '')])),
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
        'accessRoleOptions' => $state['accessRoleOptions'] ?? manageUsersAccessRoleOptions($state['departments'] ?? []),
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
