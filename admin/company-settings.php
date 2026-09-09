<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
ensureMultiCompanyControlSchema();

// Always use control database for company settings management
if (isset($control_pdo)) {
    $pdo = $control_pdo;
}

$currentRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$isCompanyAdmin = ($currentRole === 'company_admin');
if (!isAdmin() && !isSuperAdmin() && !$isCompanyAdmin) {
    http_response_code(403);
    die('Access denied.');
}

$targetCompanyId = 0;
if (isset($_GET['company_id'])) {
    $targetCompanyId = (int)$_GET['company_id'];
} elseif (isset($_GET['company_slug'])) {
    $slugStmt = $pdo->prepare("SELECT id FROM companies WHERE company_slug = ? LIMIT 1");
    $slugStmt->execute([trim($_GET['company_slug'])]);
    $targetCompanyId = (int)$slugStmt->fetchColumn();
}
if ($targetCompanyId <= 0) {
    $targetCompanyId = (int)(currentCompanyId() ?? 0);
}
if ($targetCompanyId <= 0) {
    die('Company is required.');
}
if (!isSuperAdmin() && $targetCompanyId !== (int) (currentCompanyId() ?? 0)) {
    http_response_code(403);
    die('Company scope mismatch.');
}

$message = '';
$error = '';

function generateCompanyInviteCode(PDO $pdo, int $companyId): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = 'CMP-' . date('y') . '-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE invite_code = ? AND id <> ?");
        $stmt->execute([$code, $companyId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }
    }
    return 'CMP-' . date('ymdHis');
}

function valueOrDefault(array $source, string $key, $default = '')
{
    return array_key_exists($key, $source) ? $source[$key] : $default;
}

function generateEmployeePortalPassword(int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function deriveUsernameFromEmailForCompany(PDO $pdo, string $email, int $companyId): string
{
    $local = strtolower((string) strtok($email, '@'));
    $local = preg_replace('/[^a-z0-9_]/', '', $local) ?? '';
    if ($local === '') {
        $local = 'user';
    }
    $base = substr($local, 0, 40);
    $candidate = $base;
    for ($n = 0; $n < 100; $n++) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(username) = ? AND company_id = ?');
        $check->execute([strtolower($candidate), $companyId]);
        if ((int) $check->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $base . ($n + 1);
    }
    throw new RuntimeException('Could not generate a unique username for this email.');
}

function fullNameFromEmailLocalPart(string $email): string
{
    $local = (string) strtok($email, '@');
    $parts = preg_split('/[._\-+]+/', $local) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if ($parts === []) {
        return ucfirst($local !== '' ? $local : 'Employee');
    }
    return implode(' ', array_map(static function ($p) {
        return mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
    }, $parts));
}

function redirectCompanySettingsTab(string $tab, int $companyId, string $companySlug = '', string $module = ''): void
{
    $params = ['company_id' => $companyId, 'tab' => $tab];
    if ($companySlug !== '') {
        $params['company_slug'] = $companySlug;
    }
    if ($module !== '') {
        $params['module'] = $module;
    }
    header('Location: company-settings.php?' . http_build_query($params));
    exit;
}

/**
 * @return array{userId:int,username:string,plainPassword:string,approvalStatus:string,fullName:string}
 */
function registerCompanyUserByEmail(
    PDO $pdo,
    int $targetCompanyId,
    string $email,
    string $fullName,
    string $role,
    string $department,
    bool $companyAdmin = false
): array {
    $email = normalizeLoginEmail(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }
    if (($emailErr = validateNewUserEmailForIndex($email)) !== null) {
        throw new RuntimeException($emailErr);
    }
    if ($fullName === '') {
        $fullName = fullNameFromEmailLocalPart($email);
    }

    $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(TRIM(email)) = ?');
    $dupStmt->execute([$email]);
    if ((int) $dupStmt->fetchColumn() > 0) {
        throw new RuntimeException('This email is already registered. Use another email or reset the existing account.');
    }

    $username = deriveUsernameFromEmailForCompany($pdo, $email, $targetCompanyId);
    $plainPassword = generateEmployeePortalPassword(12);
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    $approvalStatus = 'approved';
    $isActive = 1;
    if (!$companyAdmin && columnExists('companies', 'require_admin_approval_for_new_users')) {
        $chk = $pdo->prepare('SELECT require_admin_approval_for_new_users FROM companies WHERE id = ? LIMIT 1');
        $chk->execute([$targetCompanyId]);
        if ((int) ($chk->fetchColumn() ?? 1) === 1) {
            $approvalStatus = 'pending';
            $isActive = 0;
        }
    }

    $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $insertCols = ['username', 'password', 'full_name', 'email'];
    $insertVals = [$username, $passwordHash, $fullName, $email];
    if (in_array('role', $cols, true)) {
        $insertCols[] = 'role';
        $insertVals[] = $role;
    }
    if (in_array('department', $cols, true)) {
        $insertCols[] = 'department';
        $insertVals[] = $department;
    }
    if (in_array('company_id', $cols, true)) {
        $insertCols[] = 'company_id';
        $insertVals[] = $targetCompanyId;
    }
    if (in_array('is_active', $cols, true)) {
        $insertCols[] = 'is_active';
        $insertVals[] = $isActive;
    }
    if (in_array('status', $cols, true)) {
        $insertCols[] = 'status';
        $insertVals[] = $isActive === 1 ? 'active' : 'inactive';
    }
    if (in_array('approval_status', $cols, true)) {
        $insertCols[] = 'approval_status';
        $insertVals[] = $approvalStatus;
    }
    if (in_array('created_by', $cols, true)) {
        $insertCols[] = 'created_by';
        $insertVals[] = (int) ($_SESSION['user_id'] ?? 0);
    }
    $sqlInsert = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
    $pdo->prepare($sqlInsert)->execute($insertVals);
    $newUserId = (int) $pdo->lastInsertId();
    if ($newUserId > 0) {
        syncUserCompanyIndex($targetCompanyId, $newUserId);
    }
    if (function_exists('syncLoginPasswordToControlPlane')) {
        syncLoginPasswordToControlPlane([$email], $passwordHash, $targetCompanyId);
    }

    return [
        'userId' => $newUserId,
        'username' => $username,
        'plainPassword' => $plainPassword,
        'approvalStatus' => $approvalStatus,
        'fullName' => $fullName,
    ];
}

function sendCompanyPortalLoginEmail(
    string $toEmail,
    string $fullName,
    string $username,
    string $plainPassword,
    string $companyName,
    string $loginSlug,
    string $accountLabel,
    string $statusNoteHtml = ''
): bool {
    $loginUrl = $loginSlug !== '' && function_exists('company_login_url')
        ? company_login_url($loginSlug)
        : app_url('/login.php');
    if (!preg_match('#^https?://#i', $loginUrl)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $loginUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $loginUrl;
    }

    $subject = 'Your ' . $companyName . ' ' . $accountLabel . ' login details';
    $body = '
        <div style="font-family:Inter,Arial,sans-serif;color:#1e293b;max-width:560px;">
            <h2 style="margin:0 0 12px;">Welcome to ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</h2>
            <p>Hello ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . ',</p>
            <p>Your <strong>' . htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') . '</strong> account has been created. Use the credentials below to sign in:</p>
            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr><td style="padding:8px 0;color:#64748b;">Login URL</td><td style="padding:8px 0;"><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a></td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">Username</td><td style="padding:8px 0;font-weight:600;">' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">Email</td><td style="padding:8px 0;">' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td style="padding:8px 0;color:#64748b;">Password</td><td style="padding:8px 0;font-family:monospace;font-weight:700;">' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '</td></tr>
            </table>
            ' . $statusNoteHtml . '
            <p style="color:#64748b;font-size:13px;">For security, change your password after your first login.</p>
        </div>';

    return sendEmail($toEmail, $subject, $body);
}

function companySettingsRegistrationFlash(
    bool $mailOk,
    string $email,
    string $username,
    string $plainPassword,
    string $userTypeLabel
): void {
    if ($mailOk) {
        $_SESSION['company_settings_flash'] = ucfirst($userTypeLabel) . ' registered. Login details were emailed to ' . $email . '.';
    } else {
        $_SESSION['company_settings_flash'] = ucfirst($userTypeLabel) . ' registered, but the email could not be sent. Share these credentials manually â€” Username: '
            . $username . ' | Password: ' . $plainPassword;
    }
}

function processCompanyLogoUpload(int $companyId): ?string
{
    if (!isset($_FILES['company_logo_file']) || !is_array($_FILES['company_logo_file'])) {
        return null;
    }
    $file = $_FILES['company_logo_file'];
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        throw new RuntimeException($map[$err] ?? 'Logo upload failed. Please try again.');
    }
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !file_exists($tmpPath))) {
        throw new RuntimeException('Invalid logo upload source.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > (5 * 1024 * 1024)) {
        throw new RuntimeException('Logo must be smaller than 5MB.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Allowed logo formats: png, jpg, jpeg, webp, gif, svg.');
    }

    $uploadDir = __DIR__ . '/../assets/images/company_logos/' . $companyId;
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create logo upload folder.');
    }

    $safeName = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $safeName;
    if (!@move_uploaded_file($tmpPath, $destPath)) {
        // Windows/XAMPP fallback paths.
        $moved = @rename($tmpPath, $destPath);
        if (!$moved) {
            $moved = @copy($tmpPath, $destPath);
            if ($moved) {
                @unlink($tmpPath);
            }
        }
        if (!$moved) {
            throw new RuntimeException('Failed to save uploaded logo to destination folder.');
        }
    }

    return '/assets/images/company_logos/' . $companyId . '/' . $safeName;
}

if (!empty($_SESSION['company_settings_flash'])) {
    $message = (string) $_SESSION['company_settings_flash'];
    unset($_SESSION['company_settings_flash']);
}
if (!empty($_SESSION['company_settings_flash_error'])) {
    $error = (string) $_SESSION['company_settings_flash_error'];
    unset($_SESSION['company_settings_flash_error']);
}

$employeeInviteDepartments = ['General', 'Procurement', 'IT', 'Finance', 'Sales', 'Driver', 'Management'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['register_admin_by_email'])) {
            $postSlug = trim((string) ($_GET['company_slug'] ?? ''));
            $postModule = trim((string) ($_GET['module'] ?? ''));
            $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
            $adminFullName = trim((string) ($_POST['admin_full_name'] ?? ''));
            $adminPhone = trim((string) ($_POST['admin_phone'] ?? ''));

            $created = registerCompanyUserByEmail(
                $pdo,
                $targetCompanyId,
                $adminEmail,
                $adminFullName,
                'company_admin',
                'Management',
                true
            );
            if ($adminPhone !== '' && columnExists('users', 'phone') && $created['userId'] > 0) {
                $pdo->prepare('UPDATE users SET phone = ? WHERE id = ? AND company_id = ?')
                    ->execute([$adminPhone, $created['userId'], $targetCompanyId]);
            }

            $companyRow = $pdo->prepare('SELECT company_name, company_slug FROM companies WHERE id = ? LIMIT 1');
            $companyRow->execute([$targetCompanyId]);
            $companyRowData = $companyRow->fetch(PDO::FETCH_ASSOC) ?: [];
            $companyName = trim((string) ($companyRowData['company_name'] ?? 'Your company'));
            $loginSlug = trim((string) ($companyRowData['company_slug'] ?? $postSlug));

            $mailOk = sendCompanyPortalLoginEmail(
                normalizeLoginEmail($adminEmail),
                $created['fullName'],
                $created['username'],
                $created['plainPassword'],
                $companyName,
                $loginSlug,
                'company administrator'
            );
            companySettingsRegistrationFlash($mailOk, normalizeLoginEmail($adminEmail), $created['username'], $created['plainPassword'], 'company admin');
            redirectCompanySettingsTab('employees', $targetCompanyId, $postSlug, $postModule);
        }

        if (isset($_POST['register_employee_by_email'])) {
            $empEmail = trim((string) ($_POST['employee_email'] ?? ''));
            $empFullName = trim((string) ($_POST['employee_full_name'] ?? ''));
            $empDepartment = trim((string) ($_POST['employee_department'] ?? 'General'));
            $postSlug = trim((string) ($_GET['company_slug'] ?? ''));
            $postModule = trim((string) ($_GET['module'] ?? ''));

            if (!in_array($empDepartment, $employeeInviteDepartments, true)) {
                $empDepartment = 'General';
            }

            $created = registerCompanyUserByEmail(
                $pdo,
                $targetCompanyId,
                $empEmail,
                $empFullName,
                'employee',
                $empDepartment,
                false
            );

            $companyRow = $pdo->prepare('SELECT company_name, company_slug FROM companies WHERE id = ? LIMIT 1');
            $companyRow->execute([$targetCompanyId]);
            $companyRowData = $companyRow->fetch(PDO::FETCH_ASSOC) ?: [];
            $companyName = trim((string) ($companyRowData['company_name'] ?? 'Your company'));
            $loginSlug = trim((string) ($companyRowData['company_slug'] ?? $postSlug));

            $statusNote = $created['approvalStatus'] === 'pending'
                ? '<p><strong>Note:</strong> An administrator must approve your account before you can sign in.</p>'
                : '';

            $mailOk = sendCompanyPortalLoginEmail(
                normalizeLoginEmail($empEmail),
                $created['fullName'],
                $created['username'],
                $created['plainPassword'],
                $companyName,
                $loginSlug,
                'employee',
                $statusNote
            );
            companySettingsRegistrationFlash($mailOk, normalizeLoginEmail($empEmail), $created['username'], $created['plainPassword'], 'employee');
            redirectCompanySettingsTab('employees', $targetCompanyId, $postSlug, $postModule);
        }

        if (isset($_POST['save_profile'])) {
            $existingCompanyStmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
            $existingCompanyStmt->execute([$targetCompanyId]);
            $existingCompany = $existingCompanyStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingCompany) {
                throw new RuntimeException('Company not found.');
            }

            $existingSettings = fetchCompanySettingsMap($pdo, $targetCompanyId);

            $status = trim((string) valueOrDefault($_POST, 'status', (string) ($existingCompany['status'] ?? 'active')));
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
            $setupStatus = trim((string) valueOrDefault($_POST, 'setup_status', (string) ($existingCompany['setup_status'] ?? 'pending_setup')));
            if (isset($_POST['complete_setup'])) {
                $setupStatus = 'active';
            }
            if (!in_array($setupStatus, ['pending_setup', 'active', 'suspended'], true)) {
                $setupStatus = 'pending_setup';
            }
            $employeeMode = trim((string) valueOrDefault($_POST, 'employee_registration_mode', (string) ($existingCompany['employee_registration_mode'] ?? 'admin_only')));
            if (!in_array($employeeMode, ['admin_only', 'invite_only', 'open_with_approval'], true)) {
                $employeeMode = 'admin_only';
            }
            $allowSelf = (int) valueOrDefault($_POST, 'allow_employee_self_registration', (string) ((int) ($existingCompany['allow_employee_self_registration'] ?? 0)));
            $requireApproval = (int) valueOrDefault($_POST, 'require_admin_approval_for_new_users', (string) ((int) ($existingCompany['require_admin_approval_for_new_users'] ?? 1)));
            $allowSelf = $allowSelf === 1 ? 1 : 0;
            $requireApproval = $requireApproval === 1 ? 1 : 0;
            if ($employeeMode === 'admin_only') {
                $allowSelf = 0;
                $requireApproval = 1;
            }
            $inviteCodeInput = strtoupper(trim((string) valueOrDefault($_POST, 'invite_code', (string) ($existingCompany['invite_code'] ?? ''))));
            if (isset($_POST['regenerate_invite_code'])) {
                $inviteCodeInput = '';
            }
            $inviteCode = $inviteCodeInput !== '' ? $inviteCodeInput : generateCompanyInviteCode($pdo, $targetCompanyId);

            // Unique checks for domain/subdomain/invite_code
            $domain = trim((string) valueOrDefault($_POST, 'domain', (string) ($existingCompany['domain'] ?? '')));
            $subdomain = trim((string) valueOrDefault($_POST, 'subdomain', (string) ($existingCompany['subdomain'] ?? '')));
            if ($domain !== '') {
                $q = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE domain = ? AND id <> ?");
                $q->execute([$domain, $targetCompanyId]);
                if ((int) $q->fetchColumn() > 0) {
                    throw new RuntimeException('Domain already exists.');
                }
            }
            if ($subdomain !== '') {
                $q = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE subdomain = ? AND id <> ?");
                $q->execute([$subdomain, $targetCompanyId]);
                if ((int) $q->fetchColumn() > 0) {
                    throw new RuntimeException('Subdomain already exists.');
                }
            }
            if ($inviteCode !== '') {
                $q = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE invite_code = ? AND id <> ?");
                $q->execute([$inviteCode, $targetCompanyId]);
                if ((int) $q->fetchColumn() > 0) {
                    throw new RuntimeException('Invite code already exists.');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE companies
                   SET company_name = ?, legal_name = ?, domain = ?, subdomain = ?, email = ?, phone = ?, address = ?, country = ?,
                       timezone = ?, base_currency = ?, status = ?, setup_status = ?, invite_code = ?,
                       employee_registration_mode = ?, allow_employee_self_registration = ?, require_admin_approval_for_new_users = ?,
                       db_name = ?
                 WHERE id = ?
            ");
            $stmt->execute([
                trim((string) valueOrDefault($_POST, 'company_name', (string) ($existingCompany['company_name'] ?? ''))),
                trim((string) valueOrDefault($_POST, 'legal_name', (string) ($existingCompany['legal_name'] ?? ''))),
                ($domain !== '' ? $domain : null),
                ($subdomain !== '' ? $subdomain : null),
                trim((string) valueOrDefault($_POST, 'email', (string) ($existingCompany['email'] ?? ''))),
                trim((string) valueOrDefault($_POST, 'phone', (string) ($existingCompany['phone'] ?? ''))),
                trim((string) valueOrDefault($_POST, 'address', (string) ($existingCompany['address'] ?? ''))),
                trim((string) valueOrDefault($_POST, 'country', (string) ($existingCompany['country'] ?? 'Tanzania'))),
                trim((string) valueOrDefault($_POST, 'timezone', (string) ($existingCompany['timezone'] ?? 'Africa/Dar_es_Salaam'))),
                trim((string) valueOrDefault($_POST, 'base_currency', (string) ($existingCompany['base_currency'] ?? 'TZS'))),
                $status,
                $setupStatus,
                $inviteCode,
                $employeeMode,
                $allowSelf,
                $requireApproval,
                (isSuperAdmin() ? trim((string) valueOrDefault($_POST, 'db_name', (string) ($existingCompany['db_name'] ?? ''))) : ($existingCompany['db_name'] ?? null)),
                $targetCompanyId
            ]);
            $uploadedLogoPath = processCompanyLogoUpload($targetCompanyId);
            $resolvedLogoPath = (string) valueOrDefault($_POST, 'company_logo', (string) ($existingSettings['company_logo'] ?? ''));
            if ($uploadedLogoPath !== null) {
                $resolvedLogoPath = $uploadedLogoPath;
            }

            $profileMap = [
                'company_logo' => $resolvedLogoPath,
                'primary_color' => (string) valueOrDefault($_POST, 'primary_color', (string) ($existingSettings['primary_color'] ?? '#2563eb')),
                'vat_rate' => (string) valueOrDefault($_POST, 'vat_rate', (string) ($existingSettings['vat_rate'] ?? '18')),
                'invoice_prefix' => (string) valueOrDefault($_POST, 'invoice_prefix', (string) ($existingSettings['invoice_prefix'] ?? 'INV')),
                'voucher_prefix' => (string) valueOrDefault($_POST, 'voucher_prefix', (string) ($existingSettings['voucher_prefix'] ?? 'PV')),
                'po_prefix' => (string) valueOrDefault($_POST, 'po_prefix', (string) ($existingSettings['po_prefix'] ?? 'PO')),
                'date_format' => (string) valueOrDefault($_POST, 'date_format', (string) ($existingSettings['date_format'] ?? 'Y-m-d')),
                'financial_year_start' => (string) valueOrDefault($_POST, 'financial_year_start', (string) ($existingSettings['financial_year_start'] ?? '01-01')),
                'users_to_register_count' => (string) max(1, (int) valueOrDefault($_POST, 'users_to_register_count', (string) ($existingSettings['users_to_register_count'] ?? '1'))),
                'approval_workflow_enabled' => ((int) valueOrDefault($_POST, 'approval_workflow_enabled', (string) ((int) ($existingSettings['approval_workflow_enabled'] ?? 0))) === 1) ? '1' : '0',
                'allow_edit_approved_voucher_classification' => ((int) valueOrDefault($_POST, 'allow_edit_approved_voucher_classification', (string) ((int) ($existingSettings['allow_edit_approved_voucher_classification'] ?? 0))) === 1) ? '1' : '0',
                'setup_status' => $setupStatus,
                'company_email' => trim((string) valueOrDefault($_POST, 'email', (string) ($existingCompany['email'] ?? ''))),
                'company_phone' => trim((string) valueOrDefault($_POST, 'phone', (string) ($existingCompany['phone'] ?? ''))),
                'company_address' => trim((string) valueOrDefault($_POST, 'address', (string) ($existingCompany['address'] ?? ''))),
                'country' => trim((string) valueOrDefault($_POST, 'country', (string) ($existingCompany['country'] ?? 'Tanzania'))),
                'employee_registration_mode' => $employeeMode,
                'allow_employee_self_registration' => (string) $allowSelf,
                'require_admin_approval_for_new_users' => (string) $requireApproval,
                'company_invite_code' => $inviteCode,
                'company_tin' => trim((string) valueOrDefault($_POST, 'company_tin', (string) ($existingSettings['company_tin'] ?? ''))),
                'company_vat' => trim((string) valueOrDefault($_POST, 'company_vat', (string) ($existingSettings['company_vat'] ?? ''))),
                'company_location' => trim((string) valueOrDefault($_POST, 'company_location', (string) ($existingSettings['company_location'] ?? ''))),
                'bank_details' => trim((string) valueOrDefault($_POST, 'bank_details', (string) ($existingSettings['bank_details'] ?? ''))),
                'document_footer_message' => trim((string) valueOrDefault($_POST, 'document_footer_message', (string) ($existingSettings['document_footer_message'] ?? ''))),
                'tax_calculation_mode' => in_array((string) valueOrDefault($_POST, 'tax_calculation_mode', (string) ($existingSettings['tax_calculation_mode'] ?? 'exclusive')), ['exclusive', 'inclusive'], true)
                    ? (string) valueOrDefault($_POST, 'tax_calculation_mode', (string) ($existingSettings['tax_calculation_mode'] ?? 'exclusive'))
                    : 'exclusive',
            ];
            foreach ($profileMap as $k => $v) {
                saveCompanySettingValue($pdo, $targetCompanyId, (string) $k, (string) $v);
            }
            $message = isset($_POST['complete_setup']) ? 'Company setup completed.' : 'Company settings saved.';
            if (isset($_POST['complete_setup'])) {
                $_SESSION['active_company_id'] = $targetCompanyId;
                header('Location: ' . app_url('/select-module.php?company_id=' . $targetCompanyId));
                exit();
            }
            $goToStep = (int) ($_POST['go_to_step'] ?? 0);
            if ($goToStep >= 1 && $goToStep <= 7) {
                header('Location: company-settings.php?company_id=' . (int) $targetCompanyId . '&step=' . $goToStep);
                exit();
            }
        }

        if (isset($_POST['save_first_admin'])) {
            $adminFullName = trim((string) ($_POST['admin_full_name'] ?? ''));
            $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
            $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
            $adminPassword = (string) ($_POST['admin_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            $adminPhone = trim((string) ($_POST['admin_phone'] ?? ''));
            if ($adminFullName === '' || $adminEmail === '' || $adminUsername === '') {
                throw new RuntimeException('Admin full name, email and username are required.');
            }
            $adminEmail = normalizeLoginEmail($adminEmail);
            if (($emailErr = validateNewUserEmailForIndex($adminEmail)) !== null && (int) ($_POST['first_admin_id'] ?? 0) <= 0) {
                throw new RuntimeException($emailErr);
            }
            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Admin email is invalid.');
            }
            if ($adminPassword !== '' && $adminPassword !== $confirmPassword) {
                throw new RuntimeException('Admin password and confirm password do not match.');
            }
            if ($adminPassword !== '' && strlen($adminPassword) < 8) {
                throw new RuntimeException('Admin password must be at least 8 characters.');
            }

            $firstAdminId = (int) ($_POST['first_admin_id'] ?? 0);
            if ($firstAdminId > 0) {
                $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id <> ?");
                $dup->execute([$adminUsername, $adminEmail, $firstAdminId]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new RuntimeException('Admin username or email already exists.');
                }
                $sql = "UPDATE users SET full_name = ?, email = ?, username = ?, role = 'company_admin', company_id = ?, is_active = 1, status = 'active', approval_status = 'approved'";
                $params = [$adminFullName, $adminEmail, $adminUsername, $targetCompanyId];
                if (columnExists('users', 'phone')) {
                    $sql .= ", phone = ?";
                    $params[] = $adminPhone !== '' ? $adminPhone : null;
                }
                if ($adminPassword !== '') {
                    $sql .= ", password = ?";
                    $params[] = password_hash($adminPassword, PASSWORD_DEFAULT);
                }
                $sql .= " WHERE id = ? AND company_id = ?";
                $params[] = $firstAdminId;
                $params[] = $targetCompanyId;
                $pdo->prepare($sql)->execute($params);
                syncUserCompanyIndex($targetCompanyId, $firstAdminId);
                $message = 'First company admin updated.';
            } else {
                if ($adminPassword === '' || $confirmPassword === '') {
                    throw new RuntimeException('Password and confirm password are required for new admin.');
                }
                $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                $dup->execute([$adminUsername, $adminEmail]);
                if ((int) $dup->fetchColumn() > 0) {
                    throw new RuntimeException('Admin username or email already exists.');
                }
                $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $insertCols = ['username', 'password', 'full_name', 'email'];
                $insertVals = [$adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT), $adminFullName, $adminEmail];
                if (in_array('phone', $cols, true)) { $insertCols[] = 'phone'; $insertVals[] = ($adminPhone !== '' ? $adminPhone : null); }
                if (in_array('role', $cols, true)) { $insertCols[] = 'role'; $insertVals[] = 'company_admin'; }
                if (in_array('department', $cols, true)) { $insertCols[] = 'department'; $insertVals[] = 'Management'; }
                if (in_array('company_id', $cols, true)) { $insertCols[] = 'company_id'; $insertVals[] = $targetCompanyId; }
                if (in_array('is_active', $cols, true)) { $insertCols[] = 'is_active'; $insertVals[] = 1; }
                if (in_array('status', $cols, true)) { $insertCols[] = 'status'; $insertVals[] = 'active'; }
                if (in_array('approval_status', $cols, true)) { $insertCols[] = 'approval_status'; $insertVals[] = 'approved'; }
                if (in_array('created_by', $cols, true)) { $insertCols[] = 'created_by'; $insertVals[] = (int) ($_SESSION['user_id'] ?? 0); }
                $ph = implode(', ', array_fill(0, count($insertCols), '?'));
                $sqlInsert = "INSERT INTO users (" . implode(', ', $insertCols) . ") VALUES (" . $ph . ")";
                $pdo->prepare($sqlInsert)->execute($insertVals);
                $newAdminId = (int) $pdo->lastInsertId();
                if ($newAdminId > 0) {
                    syncUserCompanyIndex($targetCompanyId, $newAdminId);
                }
                $message = 'First company admin created.';
            }
        }

        if (isset($_POST['save_modules']) && isset($_POST['module_enabled']) && is_array($_POST['module_enabled'])) {
            $stmtModule = $pdo->prepare("INSERT INTO company_modules (company_id, module_key, module_name, enabled, custom_label) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), custom_label = VALUES(custom_label), updated_at = NOW()");
            foreach ($_POST['module_enabled'] as $moduleKey => $enabled) {
                $name = trim((string) ($_POST['module_name'][$moduleKey] ?? $moduleKey));
                $label = trim((string) ($_POST['custom_label'][$moduleKey] ?? ''));
                $stmtModule->execute([$targetCompanyId, $moduleKey, $name, ((int) $enabled === 1 ? 1 : 0), ($label !== '' ? $label : null)]);
            }
            $message = 'Module settings saved.';
        }

        if (isset($_POST['save_sequences'])) {
            foreach (['payment_voucher', 'invoice', 'purchase_order'] as $docType) {
                $key = $docType . '_';
                $prefixPart = trim((string) ($_POST[$key . 'prefix_part'] ?? ''));
                $suffixPart = trim((string) ($_POST[$key . 'suffix_part'] ?? ''));

                if ($prefixPart === '' && $suffixPart === '') {
                    if ($docType === 'payment_voucher') {
                        $prefixPart = 'PV';
                    } elseif ($docType === 'invoice') {
                        $prefixPart = 'INV';
                    } else {
                        $prefixPart = 'PO';
                    }
                }

                $prefixPart = trim($prefixPart, '/');
                $suffixPart = trim($suffixPart, '/');

                $prefixVal = $prefixPart . '/{YEAR}/' . ($suffixPart !== '' ? $suffixPart . '/' : '');

                saveDocumentSequence(
                    $pdo,
                    $targetCompanyId,
                    $docType,
                    $prefixVal,
                    (int) ($_POST[$key . 'next_number'] ?? 1),
                    (int) ($_POST[$key . 'padding'] ?? 3),
                    (int) ($_POST[$key . 'year'] ?? date('Y'))
                );
            }
            $message = 'Document sequences saved.';
        }

        if (isset($_POST['migrate_voucher_prefixes']) && (int) $_POST['migrate_voucher_prefixes'] === 1) {
            if (function_exists('voucher_bootstrap_operational_pdo')) {
                voucher_bootstrap_operational_pdo();
            }
            if (function_exists('migrateAllLegacyPaymentVoucherPrefixes')) {
                $mig = migrateAllLegacyPaymentVoucherPrefixes($pdo, $targetCompanyId);
                if (!empty($mig['ok'])) {
                    $message = ((int) ($mig['total'] ?? 0) > 0)
                        ? 'Renumbered ' . (int) $mig['total'] . ' voucher(s) to ' . ($mig['target'] ?? 'the configured prefix') . '.'
                        : 'No legacy voucher numbers needed renumbering.';
                } else {
                    $error = (string) ($mig['error'] ?? 'Voucher prefix migration failed.');
                }
            } else {
                $error = 'Voucher prefix migration is not available.';
            }
        }
    } catch (Throwable $e) {
        if (isset($_POST['register_employee_by_email']) || isset($_POST['register_admin_by_email'])) {
            $_SESSION['company_settings_flash_error'] = $e->getMessage();
            redirectCompanySettingsTab(
                'employees',
                $targetCompanyId,
                trim((string) ($_GET['company_slug'] ?? '')),
                trim((string) ($_GET['module'] ?? ''))
            );
        }
        $error = 'Update failed: ' . $e->getMessage();
    }
}

$companyStmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
$companyStmt->execute([$targetCompanyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    die('Company not found.');
}
if ((string) ($company['setup_status'] ?? 'pending_setup') === 'pending_setup' && !isset($_GET['step']) && !isset($_GET['tab'])) {
    header('Location: company-settings.php?company_id=' . (int) $targetCompanyId . '&step=1');
    exit();
}

$settings = fetchCompanySettingsMap($pdo, $targetCompanyId);
$companyLogoRaw = trim((string) ($settings['company_logo'] ?? ''));
$companyLogoFilePath = '';
if ($companyLogoRaw !== '') {
    if (str_starts_with($companyLogoRaw, '/')) {
        $companyLogoFilePath = __DIR__ . '/..' . str_replace('/', DIRECTORY_SEPARATOR, $companyLogoRaw);
    } else {
        $companyLogoFilePath = __DIR__ . '/../' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $companyLogoRaw);
    }
}
$companyLogoExists = ($companyLogoFilePath !== '' && file_exists($companyLogoFilePath));
$companyLogoUrl = '';
if ($companyLogoExists) {
    if (preg_match('#^https?://#i', $companyLogoRaw) || str_starts_with($companyLogoRaw, 'data:')) {
        $companyLogoUrl = $companyLogoRaw;
    } else {
        $companyLogoUrl = app_url('/' . ltrim($companyLogoRaw, '/'));
    }
}

$firstAdminStmt = $pdo->prepare("SELECT id, full_name, email, username, phone FROM users WHERE company_id = ? AND role = 'company_admin' ORDER BY id ASC LIMIT 1");
$firstAdminStmt->execute([$targetCompanyId]);
$firstCompanyAdmin = $firstAdminStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$adminsStmt = $pdo->prepare("
    SELECT id, full_name, email, username, phone, is_active, status, role
    FROM users
    WHERE company_id = ?
      AND LOWER(TRIM(role)) IN ('company_admin', 'admin', 'administrator')
    ORDER BY id ASC
");
$adminsStmt->execute([$targetCompanyId]);
$companyAdmins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$companyEmployees = [];
try {
    $employeesStmt = $pdo->prepare("SELECT id, full_name, email, username, department, is_active, approval_status, created_at FROM users WHERE company_id = ? AND role = 'employee' ORDER BY id DESC LIMIT 25");
    $employeesStmt->execute([$targetCompanyId]);
    $companyEmployees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $employeesStmt = $pdo->prepare("SELECT id, full_name, email, username, department, is_active, created_at FROM users WHERE company_id = ? AND role = 'employee' ORDER BY id DESC LIMIT 25");
    $employeesStmt->execute([$targetCompanyId]);
    $companyEmployees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$moduleDefaults = [
    'payment_voucher' => 'Payment Voucher',
    'sales' => 'Sales',
    'stock' => 'Stock',
    'finance' => 'Finance',
    'accounting' => 'Accounting',
    'payroll' => 'Payroll',
    'letter' => 'Letter',
    'attendance' => 'Attendance',
    'revenue' => 'Revenue',
    'logistics' => 'Logistics',
    'company-profile' => 'Company Profile',
    'backup' => 'Backup',
    'crm' => 'CRM',
];
$moduleDescriptions = [
    'payment_voucher' => 'Create and approve payment vouchers.',
    'sales' => 'Manage customers, quotations, and invoices.',
    'stock' => 'Track stock, purchasing, and inventory.',
    'finance' => 'Monitor expenses and finance workflows.',
    'accounting' => 'Journal, trial balance, and reconciliation.',
    'payroll' => 'Manage payslips and salary processing.',
    'letter' => 'Compose official letters on company letterhead.',
    'attendance' => 'Track sign-in/out and attendance reports.',
    'revenue' => 'Handle revenue entries and collections.',
    'logistics' => 'Manage dispatch and delivery operations.',
    'company-profile' => 'Create and generate company profile documents.',
    'backup' => 'Export full company database and file backups.',
    'crm' => 'Track leads, prospects, and customer relationships.',
];
$employeeModeLabels = [
    'admin_only' => 'Admin only',
    'invite_only' => 'Invite only',
    'open_with_approval' => 'Open with approval',
];
$existingModules = [];
$modStmt = $pdo->prepare("SELECT * FROM company_modules WHERE company_id = ?");
$modStmt->execute([$targetCompanyId]);
foreach ($modStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $existingModules[(string) $row['module_key']] = $row;
}

$sequences = fetchDocumentSequencesMap($pdo, $targetCompanyId);

$legacyVoucherPrefixCount = 0;
$currentPvPrefix = '';
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
if (function_exists('getCurrentPaymentVoucherSequencePrefix')) {
    $pvPdoForCount = function_exists('paymentVouchersPdo') ? paymentVouchersPdo($pdo) : null;
    if ($pvPdoForCount instanceof PDO) {
        $currentPvPrefix = getCurrentPaymentVoucherSequencePrefix($pvPdoForCount, $targetCompanyId);
        if ($currentPvPrefix !== '' && function_exists('countLegacyPaymentVoucherPrefixes')) {
            $legacyVoucherPrefixCount = countLegacyPaymentVoucherPrefixes($pvPdoForCount, $targetCompanyId, $currentPvPrefix);
        }
    }
}

$setupStatus = (string) ($company['setup_status'] ?? 'pending_setup');
$isPendingSetup = ($setupStatus === 'pending_setup');
$companySlug = trim((string) ($company['company_slug'] ?? ''));
$linkScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$linkHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$companyAccessLink = $companySlug !== '' ? ($linkScheme . '://' . $linkHost . company_dashboard_url($companySlug)) : '';
$inviteCodeCurrent = trim((string) ($company['invite_code'] ?? ''));
$employeeInvitePath = $companySlug !== ''
    ? company_url('register', $companySlug) . ($inviteCodeCurrent !== '' ? ('?code=' . rawurlencode($inviteCodeCurrent)) : '')
    : app_url('/company/register-employee.php' . ($inviteCodeCurrent !== '' ? ('?code=' . rawurlencode($inviteCodeCurrent)) : ''));
$employeeInviteLink = $linkScheme . '://' . $linkHost . $employeeInvitePath;
$activeTab = (string) ($_GET['tab'] ?? 'profile');
$allowedTabs = ['profile', 'branding', 'finance', 'modules', 'numbering', 'employees', 'security', 'danger'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'profile';
}
$wizardStep = (int) ($_GET['step'] ?? 1);
if ($wizardStep < 1 || $wizardStep > 7) {
    $wizardStep = 1;
}
$wizardSteps = [
    1 => 'Profile',
    2 => 'Branding',
    3 => 'Finance',
    4 => 'Modules',
    5 => 'Numbering',
    6 => 'Employees',
    7 => 'Activate',
];
$settingsQs = static function (array $extra = []) use ($targetCompanyId, $companySlug): string {
    $params = ['company_id' => $targetCompanyId];
    if ($companySlug !== '') {
        $params['company_slug'] = $companySlug;
    }
    $module = trim((string) ($_GET['module'] ?? ''));
    if ($module !== '') {
        $params['module'] = $module;
    }
    $params = array_merge($params, $extra);
    $qs = http_build_query($params);
    return $qs === '' ? '' : ('?' . $qs);
};
$settingsBaseUrl = 'company-settings.php' . $settingsQs();
$settingsHubBackUrl = 'settings.php' . $settingsQs(['module' => trim((string) ($_GET['module'] ?? '')) !== '' ? (string) $_GET['module'] : 'settings']);
$topSaveFormId = null;
if ($isPendingSetup) {
    $topSaveFormId = 'wizard-form-step-' . $wizardStep;
} else {
    $topSaveFormId = 'tab-form-' . $activeTab;
}
$enabledModuleCount = 0;
foreach ($existingModules as $modRow) {
    if ((int) ($modRow['enabled'] ?? 0) === 1) {
        $enabledModuleCount++;
    }
}
$countryOptions = ['Tanzania', 'Kenya', 'Uganda', 'Rwanda', 'Burundi', 'Zambia', 'South Africa', 'Other'];
$timezoneOptions = [
    'Africa/Dar_es_Salaam',
    'Africa/Nairobi',
    'Africa/Kampala',
    'Africa/Johannesburg',
    'Africa/Lusaka',
    'UTC',
];
$currencyOptions = ['TZS', 'USD', 'KES', 'UGX', 'EUR', 'GBP'];
$setupStatusLabel = ucwords(str_replace('_', ' ', $setupStatus));

require_once __DIR__ . '/company-settings-ui/lib.php';

$numberingDocs = [
    'payment_voucher' => [
        'label' => 'Payment Voucher',
        'subtitle' => 'Prefix and counter for payment vouchers.',
        'default_prefix' => 'PV',
    ],
    'invoice' => [
        'label' => 'Invoice',
        'subtitle' => 'Prefix and counter for sales invoices.',
        'default_prefix' => 'INV',
    ],
    'purchase_order' => [
        'label' => 'Purchase Order',
        'subtitle' => 'Prefix and counter for purchase orders.',
        'default_prefix' => 'PO',
    ],
];

$sequencePayload = [];
foreach ($numberingDocs as $docKey => $docMeta) {
    $seq = $sequences[$docKey] ?? [];
    $parts = function_exists('parsePrefixParts') ? parsePrefixParts($seq['prefix'] ?? '') : ['prefix' => '', 'suffix' => ''];
    if (($seq['prefix'] ?? '') === '') {
        $parts['prefix'] = $docMeta['default_prefix'];
    }
    $sequencePayload[] = [
        'key' => $docKey,
        'label' => $docMeta['label'],
        'subtitle' => $docMeta['subtitle'],
        'prefixPart' => (string) ($parts['prefix'] ?? ''),
        'suffixPart' => (string) ($parts['suffix'] ?? ''),
        'nextNumber' => (int) ($seq['next_number'] ?? 1),
        'padding' => (int) ($seq['padding'] ?? 3),
        'year' => (int) ($seq['year'] ?? date('Y')),
    ];
}

$modulesPayload = [];
foreach ($moduleDefaults as $modKey => $modLabel) {
    $mod = $existingModules[$modKey] ?? null;
    $modulesPayload[] = [
        'key' => $modKey,
        'label' => $modLabel,
        'description' => (string) ($moduleDescriptions[$modKey] ?? ''),
        'enabled' => (!$mod || (int) ($mod['enabled'] ?? 1) === 1),
        'customLabel' => (string) ($mod['custom_label'] ?? ''),
    ];
}

$tabs = [
    ['id' => 'profile', 'label' => 'Profile'],
    ['id' => 'branding', 'label' => 'Branding'],
    ['id' => 'finance', 'label' => 'Finance'],
    ['id' => 'modules', 'label' => 'Modules'],
    ['id' => 'numbering', 'label' => 'Numbering'],
    ['id' => 'employees', 'label' => 'Employees'],
    ['id' => 'security', 'label' => 'Security'],
];
if (isSuperAdmin()) {
    $tabs[] = ['id' => 'danger', 'label' => 'Danger'];
}

$companyUsersUrl = 'company-users.php' . $settingsQs([]);
$selfUrl = 'company-settings.php' . $settingsQs(['tab' => $activeTab]);
$formAction = 'company-settings.php' . $settingsQs([]);

$reactCfg = [
    'selfUrl' => $formAction,
    'hubUrl' => $settingsHubBackUrl,
    'companyUsersUrl' => $companyUsersUrl,
    'companyAccessLink' => (string) $companyAccessLink,
    'employeeInviteLink' => (string) $employeeInviteLink,
    'logoUrl' => (string) $companyLogoUrl,
    'message' => (string) $message,
    'error' => (string) $error,
    'isSuperAdmin' => isSuperAdmin(),
    'isPendingSetup' => $isPendingSetup,
    'setupStatusLabel' => (string) $setupStatusLabel,
    'activeTab' => $activeTab,
    'wizardStep' => $wizardStep,
    'tabs' => $tabs,
    'company' => [
        'id' => (int) ($company['id'] ?? 0),
        'company_name' => (string) ($company['company_name'] ?? ''),
        'legal_name' => (string) ($company['legal_name'] ?? ''),
        'email' => (string) ($company['email'] ?? ''),
        'phone' => (string) ($company['phone'] ?? ''),
        'address' => (string) ($company['address'] ?? ''),
        'country' => (string) ($company['country'] ?? 'Tanzania'),
        'timezone' => (string) ($company['timezone'] ?? 'Africa/Dar_es_Salaam'),
        'base_currency' => (string) ($company['base_currency'] ?? 'TZS'),
        'db_name' => (string) ($company['db_name'] ?? ''),
        'status' => (string) ($company['status'] ?? 'active'),
        'setup_status' => (string) ($company['setup_status'] ?? 'pending_setup'),
        'invite_code' => (string) ($company['invite_code'] ?? ''),
        'employee_registration_mode' => (string) ($company['employee_registration_mode'] ?? 'admin_only'),
        'allow_employee_self_registration' => (int) ($company['allow_employee_self_registration'] ?? 0),
        'require_admin_approval_for_new_users' => (int) ($company['require_admin_approval_for_new_users'] ?? 0),
        'company_slug' => (string) ($company['company_slug'] ?? ''),
    ],
    'settings' => [
        'company_logo' => (string) ($settings['company_logo'] ?? ''),
        'primary_color' => (string) ($settings['primary_color'] ?? '#2563eb'),
        'vat_rate' => (string) ($settings['vat_rate'] ?? '18'),
        'date_format' => (string) ($settings['date_format'] ?? 'Y-m-d'),
        'financial_year_start' => (string) ($settings['financial_year_start'] ?? '01-01'),
        'tax_calculation_mode' => (string) ($settings['tax_calculation_mode'] ?? 'exclusive'),
        'company_tin' => (string) ($settings['company_tin'] ?? ''),
        'company_vat' => (string) ($settings['company_vat'] ?? ''),
        'company_location' => (string) ($settings['company_location'] ?? ''),
        'bank_details' => (string) ($settings['bank_details'] ?? ''),
        'document_footer_message' => (string) ($settings['document_footer_message'] ?? ''),
        'approval_workflow_enabled' => (string) ($settings['approval_workflow_enabled'] ?? '0'),
        'allow_edit_approved_voucher_classification' => (string) ($settings['allow_edit_approved_voucher_classification'] ?? '0'),
        'users_to_register_count' => (string) ($settings['users_to_register_count'] ?? '0'),
    ],
    'modules' => $modulesPayload,
    'sequences' => $sequencePayload,
    'admins' => array_map(static function ($u) {
        return [
            'id' => (int) ($u['id'] ?? 0),
            'full_name' => (string) ($u['full_name'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'username' => (string) ($u['username'] ?? ''),
            'role' => (string) ($u['role'] ?? ''),
        ];
    }, $companyAdmins),
    'employees' => array_map(static function ($u) {
        return [
            'id' => (int) ($u['id'] ?? 0),
            'full_name' => (string) ($u['full_name'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'username' => (string) ($u['username'] ?? ''),
            'department' => (string) ($u['department'] ?? ''),
        ];
    }, $companyEmployees),
    'countryOptions' => $countryOptions,
    'timezoneOptions' => $timezoneOptions,
    'currencyOptions' => $currencyOptions,
    'employeeModeLabels' => $employeeModeLabels,
    'departments' => ['General', 'Procurement', 'IT', 'Finance', 'Sales', 'Driver', 'Management'],
    'legacyVoucherPrefixCount' => (int) $legacyVoucherPrefixCount,
    'currentPvPrefix' => (string) $currentPvPrefix,
];

companySettingsRenderReactShell($reactCfg);
