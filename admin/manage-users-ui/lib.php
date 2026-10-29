<?php

declare(strict_types=1);

require_once __DIR__ . '/service.inc.php';
require_once dirname(__DIR__, 2) . '/includes/welcome-characters.php';

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

function manageUsersWelcomeCharacterUrl(string $key): string
{
    $path = welcomeCharacterImagePath($key);

    return $path === '' ? '' : manageUsersAbsoluteUrl($path);
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
 * Turn a site image URL (or data: URI) into an inline CID attachment.
 * Mail clients load remote images through their own proxies, so localhost / intranet URLs never render.
 * SVG is skipped because Gmail and Outlook do not display it.
 *
 * @return array{cid:string,name:string,type:string,path?:string,content?:string}|null
 */
function manageUsersEmailInlineImage(string $url, string $cid, int $maxHeight = 0): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    if (preg_match('#^data:(image/(png|jpe?g|gif|webp));base64,(.+)$#is', $url, $m)) {
        $bytes = base64_decode($m[3], true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $ext = strtolower($m[2]) === 'jpg' ? 'jpeg' : strtolower($m[2]);

        return ['cid' => $cid, 'name' => $cid . '.' . $ext, 'type' => strtolower($m[1]), 'content' => $bytes];
    }

    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('#^https?://#i', $url)) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ownHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '' || $host !== $ownHost) {
            return null;
        }
    }
    $rel = '/' . ltrim(rawurldecode($path), '/');
    $base = function_exists('app_url') ? rtrim((string) parse_url((string) app_url('/'), PHP_URL_PATH), '/') : '';
    if ($base !== '' && str_starts_with($rel, $base . '/')) {
        $rel = substr($rel, strlen($base));
    }
    if (str_contains($rel, '..')) {
        return null;
    }
    $file = dirname(__DIR__, 2) . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext]) || !is_file($file)) {
        return null;
    }

    $part = ['cid' => $cid, 'name' => basename($file), 'type' => $allowed[$ext], 'path' => $file];
    if ($maxHeight > 0 && filesize($file) > 150 * 1024) {
        $small = manageUsersEmailShrinkImage($file, $maxHeight);
        if ($small !== null) {
            unset($part['path']);
            $part['name'] = pathinfo($file, PATHINFO_FILENAME) . '.png';
            $part['type'] = 'image/png';
            $part['content'] = $small;
        }
    }

    return $part;
}

/**
 * Downscale an image to a PNG of at most $maxHeight px tall (keeps transparency). Null when GD can't read it.
 */
function manageUsersEmailShrinkImage(string $file, int $maxHeight): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $bytes = @file_get_contents($file);
    $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($h <= $maxHeight) {
        imagedestroy($src);

        return null;
    }
    $nh = $maxHeight;
    $nw = max(1, (int) round($w * $nh / $h));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagepng($dst, null, 9);
    $out = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);

    return $out !== '' ? $out : null;
}

/**
 * Google-account-style email: table layout + inline styles (email clients strip <style>, flexbox and SVG).
 *
 * @param array{first:string,email:string,department:string,company:string,loginUrl:string,logo:string,tagline?:string,character?:string} $d
 */
function manageUsersWelcomeEmailHtml(array $d): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $font = "font-family:'Google Sans',Roboto,'Segoe UI',Helvetica,Arial,sans-serif;";
    $company = trim($d['company']);
    $url = $e($d['loginUrl']);
    $initial = strtoupper(substr($d['first'] !== '' ? $d['first'] : $d['email'], 0, 1));

    $brand = $d['logo'] !== ''
        ? '<img src="' . $e($d['logo']) . '" alt="' . $e($company) . '" height="40" style="display:block;margin:0 auto;height:40px;width:auto;border:0;">'
        : '<div style="' . $font . 'font-size:20px;font-weight:500;color:#202124;">' . $e($company !== '' ? $company : 'Welcome') . '</div>';

    $deptText = $d['department'] !== ''
        ? ' under the <b>' . $e($d['department']) . ' Department</b>'
        : '';
    $p = 'margin:0 0 14px;' . $font . 'font-size:14px;line-height:22px;color:#3c4043;';
    $hero = array_key_exists('heroSrc', $d)
        ? (string) $d['heroSrc']
        : manageUsersWelcomeCharacterUrl((string) ($d['character'] ?? manageUsersWelcomeCharacter()));

    $chip = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:12px 0 0;"><tr>'
        . '<td style="padding:2px 12px 2px 2px;border:1px solid #dadce0;border-radius:16px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td><div style="width:20px;height:20px;line-height:20px;border-radius:50%;background:#7c3aed;color:#ffffff;text-align:center;' . $font . 'font-size:11px;font-weight:500;">' . $e($initial) . '</div></td>'
        . '<td style="padding-left:8px;' . $font . 'font-size:14px;color:#3c4043;">' . $e($d['email']) . '</td>'
        . '</tr></table></td></tr></table>';

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>Welcome</title>'
        . '<style>@media only screen and (max-width:560px){'
        . '.wm-col{display:block!important;width:100%!important;max-width:100%!important;}'
        . '.wm-art{text-align:center!important;padding:0 0 8px!important;}'
        . '.wm-art img{margin:0 auto!important;width:150px!important;}'
        . '.wm-copy{padding:0!important;}'
        . '.wm-h1{font-size:26px!important;line-height:32px!important;}'
        . '}</style></head>'
        . '<body style="margin:0;padding:0;background:#ffffff;' . $font . 'color:#202124;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your account is ready. Sign in to get started.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;"><tr><td align="center" style="padding:40px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">'

        . '<tr><td style="border:1px solid #dadce0;border-radius:8px;padding:28px 28px 32px;">'

        // Logo
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="left" style="padding-bottom:20px;">'
        . str_replace('margin:0 auto;', 'margin:0;', $brand)
        . '</td></tr></table>'

        // Illustration left, copy right
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . ($hero !== ''
            ? '<td class="wm-col wm-art" width="200" valign="middle" style="width:200px;padding-right:16px;">'
                . '<img src="' . $e($hero) . '" alt="" width="190" style="display:block;width:190px;height:auto;border:0;"></td>'
            : '')
        . '<td class="wm-col wm-copy" valign="middle" style="padding-left:12px;text-align:left;">'
        . '<div class="wm-h1" style="' . $font . 'font-size:30px;line-height:36px;font-weight:700;color:#202124;letter-spacing:-0.01em;">Welcome aboard, ' . $e($d['first']) . '!</div>'
        . $chip
        . '<p style="' . $p . 'margin-top:18px;">Welcome to <b>' . $e($company !== '' ? $company : 'the team') . '!</b> '
        . 'Your account has been successfully created' . $deptText . '.</p>'
        . '<p style="' . $p . '">Your administrator will provide your password. Once received, sign in using your '
        . 'registered email address to access your account and get started.</p>'
        . '<div style="padding-top:8px;">'
        . '<a href="' . $url . '" target="_blank" style="' . $font . 'display:inline-block;background:#7c3aed;color:#ffffff;font-size:14px;font-weight:600;line-height:16px;text-decoration:none;padding:12px 26px;border-radius:999px;">Sign in to your account</a>'
        . '</div>'
        . '</td></tr></table>'

        . '<div style="' . $font . 'font-size:12px;line-height:16px;color:#5f6368;padding-top:28px;margin-top:24px;border-top:1px solid #f1f3f4;text-align:left;">'
        . 'Please change your password after you sign in for the first time. If you were not expecting this email, you can ignore it.'
        . '</div>'

        . '</td></tr>'

        // Footer
        . '<tr><td style="' . $font . 'font-size:11px;line-height:18px;color:rgba(0,0,0,0.54);text-align:center;padding-top:20px;">'
        . 'You received this email because an account was created for you.<br>'
        . 'If the button does not work, open: <a href="' . $url . '" style="color:rgba(0,0,0,0.54);">' . $url . '</a>'
        . ($company !== '' ? '<div style="margin-top:12px;">&copy; ' . date('Y') . ' ' . $e($company) . '</div>' : '')
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

    $attachments = [];
    $logoUrl = function_exists('getCompanyLogoUrl') ? (string) getCompanyLogoUrl() : '';
    $logoPart = manageUsersEmailInlineImage($logoUrl, 'company-logo', 120);
    if ($logoPart !== null) {
        $attachments[] = $logoPart;
        $logo = 'cid:company-logo';
    } else {
        $logo = preg_match('#^https?://#i', $logoUrl) ? $logoUrl : '';
    }

    $heroSrc = '';
    $heroPart = manageUsersEmailInlineImage(welcomeCharacterImagePath(manageUsersWelcomeCharacter()), 'welcome-hero');
    if ($heroPart !== null) {
        $attachments[] = $heroPart;
        $heroSrc = 'cid:welcome-hero';
    }

    $body = manageUsersWelcomeEmailHtml([
        'first' => $first,
        'email' => $email,
        'department' => $department,
        'company' => $company,
        'loginUrl' => $loginUrl,
        'logo' => $logo,
        'heroSrc' => $heroSrc,
        'tagline' => manageUsersCompanyTagline(),
    ]);

    try {
        return (bool) @sendEmail($email, $subject, $body, true, $attachments);
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
