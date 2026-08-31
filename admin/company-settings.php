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
        $_SESSION['company_settings_flash'] = ucfirst($userTypeLabel) . ' registered, but the email could not be sent. Share these credentials manually — Username: '
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Company Settings</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body.company-settings-body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .company-settings-wrapper { padding: 2rem; max-width: 100%; }
    .company-settings-inner { max-width: 1000px; margin: 0 auto; }
    .company-page-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
      gap: 12px;
    }
    .company-page-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; }
    .company-page-back {
      color: #94a3b8;
      font-weight: 500;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
    }
    .company-page-back:hover { color: #475569; }
    .company-toolbar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 1.5rem;
    }
    .form-card, .settings-card {
      background: #fff;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      overflow: hidden;
      margin-bottom: 1.5rem;
    }
    .form-header, .settings-card > h2, .settings-card > h5 {
      padding: 1.25rem 2rem;
      border-bottom: 1px solid #f1f5f9;
      margin: 0;
    }
    .settings-card > h2, .settings-card > h5 { font-size: 1.125rem; font-weight: 700; color: #0f172a; }
    .form-body { padding: 2rem; }
    .settings-card > p.profile-card-desc,
    .settings-card > p.settings-muted { padding: 0 2rem; margin-top: -0.5rem; margin-bottom: 0; }
    .settings-card > form { padding: 0 2rem 2rem; }
    .settings-card.access-link-card { padding-bottom: 0; }
    .settings-card.access-link-card .access-link-box { margin: 0 2rem 2rem; }
    .section-title { font-size: 1.125rem; font-weight: 700; color: #0f172a; margin: 0; }
    .form-row {
      display: grid;
      grid-template-columns: 200px 1fr;
      align-items: start;
      gap: 12px;
      margin-bottom: 1.5rem;
    }
    .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; margin: 0; }
    .form-input, .company-settings-page .form-control, .company-settings-page .form-select {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      font-size: 14px;
      color: #1e293b;
      outline: none;
      background: #fff;
    }
    .company-settings-page .form-control:focus, .company-settings-page .form-select:focus {
      border-color: #5c59f0;
      box-shadow: 0 0 0 4px rgba(92, 89, 240, 0.12);
    }
    .help-text, .settings-muted { font-size: 12px; color: #94a3b8; line-height: 1.5; }
    .company-settings-page .btn-save,
    .company-settings-page .btn.btn-save,
    .company-settings-page .btn-save-changes,
    .company-settings-page .btn.btn-save-changes {
      background: linear-gradient(135deg, #6f45ff 0%, #5c59f0 100%) !important;
      color: #fff !important;
      padding: 12px 28px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 14px;
      border: none !important;
      box-shadow: 0 4px 14px rgba(92, 89, 240, 0.35);
      transition: background 0.2s, box-shadow 0.2s, transform 0.15s;
    }
    .company-settings-page .btn-save:hover,
    .company-settings-page .btn.btn-save:hover,
    .company-settings-page .btn-save-changes:hover,
    .company-settings-page .btn.btn-save-changes:hover,
    .company-settings-page .btn-save:focus,
    .company-settings-page .btn.btn-save:focus,
    .company-settings-page .btn-save-changes:focus,
    .company-settings-page .btn.btn-save-changes:focus {
      background: linear-gradient(135deg, #5e38e8 0%, #4a47d9 100%) !important;
      color: #fff !important;
      box-shadow: 0 6px 18px rgba(92, 89, 240, 0.4);
    }
    .company-settings-page .btn-save:active,
    .company-settings-page .btn.btn-save:active,
    .company-settings-page .btn-save-changes:active,
    .company-settings-page .btn.btn-save-changes:active {
      transform: translateY(1px);
    }
    .form-actions { display: flex; justify-content: flex-end; margin-top: 8px; padding-top: 16px; border-top: 1px solid #f1f5f9; }
    .form-row--options .form-label { padding-top: 4px; }
    .finance-options-stack { max-width: 520px; }
    .settings-muted { color: #64748b; }
    .status-badge {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      background: #f1f5f9;
      color: #0f172a;
    }
    .status-badge.pending { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
    .status-badge.active { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .status-badge.suspended { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
    .wizard-steps {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 0;
      margin-bottom: 20px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 16px 18px;
    }
    .wizard-step {
      position: relative;
      text-decoration: none;
      text-align: center;
      color: #94a3b8;
      display: block;
      padding-top: 2px;
    }
    .wizard-step::after {
      content: "";
      position: absolute;
      top: 14px;
      left: calc(50% + 18px);
      width: calc(100% - 36px);
      height: 2px;
      background: #e2e8f0;
    }
    .wizard-step:last-child::after { display: none; }
    .wizard-step-index {
      width: 28px;
      height: 28px;
      border-radius: 999px;
      margin: 0 auto 8px auto;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #64748b;
    }
    .wizard-step-label {
      font-size: 11px;
      font-weight: 600;
      color: #64748b;
    }
    .wizard-step.active .wizard-step-index {
      background: #2563eb;
      border-color: #2563eb;
      color: #fff;
    }
    .wizard-step.active .wizard-step-label { color: #2563eb; }
    .wizard-step.done .wizard-step-index {
      background: #eff6ff;
      border-color: #93c5fd;
      color: #1d4ed8;
    }
    .wizard-step.done::after { background: #93c5fd; }
    .wizard-tip {
      background: #f8fbff;
      border: 1px solid #dbeafe;
      border-radius: 10px;
      padding: 10px 12px;
    }
    .brand-upload-card {
      border: 1px solid #dbe3ef;
      border-radius: 12px;
      background: #f9fbff;
      padding: 14px;
    }
    .brand-logo-box {
      border: 1px solid #dbe3ef;
      border-radius: 10px;
      background: #ffffff;
      padding: 12px;
      min-height: 96px;
    }
    .doc-preview-card {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      background: #ffffff;
      overflow: hidden;
    }
    .doc-preview-top {
      height: 7px;
      background: #2563eb;
    }
    .doc-preview-body {
      padding: 14px 16px;
      font-size: 12px;
      color: #0f172a;
    }
    .doc-line {
      height: 1px;
      background: #dbe3ef;
      margin: 10px 0;
    }
    .doc-field {
      border-bottom: 1px solid #dbe3ef;
      height: 18px;
    }
    .tabs-strip {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 24px;
      padding-bottom: 4px;
    }
    .tab-pill {
      border: 1px solid #e2e8f0;
      border-radius: 999px;
      padding: 9px 18px;
      text-decoration: none;
      color: #475569;
      background: #fff;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.2;
      transition: border-color .15s, color .15s, box-shadow .15s;
    }
    .tab-pill:hover {
      border-color: #cbd5e1;
      color: #334155;
    }
    .tab-pill.active {
      color: #2563eb;
      background: #fff;
      border-color: #2563eb;
      box-shadow: 0 0 0 1px #2563eb;
    }
    .tab-pill.tab-pill-danger { color: #dc2626; }
    .tab-pill.tab-pill-danger.active {
      color: #dc2626;
      border-color: #fca5a5;
      box-shadow: 0 0 0 1px #fca5a5;
    }
    .status-badge.active {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #ecfdf5;
      border-color: #bbf7d0;
      color: #047857;
      font-size: 13px;
      font-weight: 600;
      text-transform: none;
      letter-spacing: 0;
      padding: 7px 14px;
    }
    .status-badge.active::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #22c55e;
      flex-shrink: 0;
    }
    .company-settings-page .btn-outline-secondary {
      border-radius: 10px;
      border-color: #e2e8f0;
      font-weight: 600;
    }
    .access-link-card {
      margin-top: 20px;
    }
    .access-link-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 4px;
    }
    .access-link-desc {
      font-size: 14px;
      color: #64748b;
      margin-bottom: 14px;
    }
    .access-link-box {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 10px 12px 10px 16px;
      overflow: visible;
    }
    .access-link-box input {
      flex: 1;
      border: 0;
      background: transparent;
      padding: 14px 16px;
      font-size: 14px;
      color: #334155;
      min-width: 0;
    }
    .access-link-box input:focus {
      outline: none;
      box-shadow: none;
    }
    .access-link-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-left: auto;
    }
    .access-link-actions .btn {
      border: 1px solid #e2e8f0;
      border-radius: 10px !important;
      background: #fff;
      color: #334155;
      font-weight: 600;
      font-size: 14px;
      padding: 10px 16px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .access-link-actions .btn:hover {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #cbd5e1;
    }
    .settings-form-label {
      font-size: 13px;
      font-weight: 600;
      color: #334155;
      margin-bottom: 6px;
    }
    .module-card {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 16px;
      background: #fff;
      height: 100%;
    }
    .preview-box {
      border: 1px dashed #cbd5e1;
      border-radius: 12px;
      background: #f8fafc;
      min-height: 120px;
      padding: 14px;
    }
    .editor-layout {
      display: grid;
      grid-template-columns: 180px minmax(0, 1fr);
      gap: 2rem;
      align-items: start;
    }
    .section-nav {
      position: sticky;
      top: 96px;
      align-self: start;
    }
    .section-nav ul {
      list-style: none;
      margin: 0;
      padding: 0;
    }
    .section-nav li + li { margin-top: 0.5rem; }
    .section-nav a {
      display: block;
      padding: 0.45rem 0.75rem;
      border-radius: 8px;
      color: #64748b;
      font-size: 13px;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .section-nav a:hover {
      background: #eff6ff;
      color: #2563eb;
    }
    .section-nav a.is-active {
      background: #f3e8ff;
      color: #7c3aed;
      font-weight: 600;
    }
    .editor-main { min-width: 0; }
    .editor-section {
      padding-bottom: 2rem;
      margin-bottom: 2rem;
      border-bottom: 1px solid #e5e7eb;
    }
    .editor-section:last-of-type {
      margin-bottom: 1.5rem;
      border-bottom: 0;
      padding-bottom: 0;
    }
    .section-subtitle {
      font-size: 12px;
      color: #94a3b8;
      margin: 4px 0 0;
    }
    .form-input-readonly {
      background: #f8fafc;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-weight: 700;
      color: #2563eb;
      border-style: dashed;
    }
    .numbering-prefix-row {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 8px;
    }
    .numbering-prefix-row .form-input {
      flex: 1;
      min-width: 80px;
    }
    .numbering-prefix-sep {
      font-size: 13px;
      font-weight: 700;
      color: #94a3b8;
      white-space: nowrap;
      padding: 0 2px;
    }
    .numbering-inline-fields {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
    }
    .legacy-migrate-card {
      background: #fffbeb;
      border-color: #fde68a;
    }
    .legacy-migrate-card .form-header {
      border-bottom-color: #fde68a;
      background: #fffbeb;
    }
    @media (max-width: 992px) {
      .company-settings-wrapper { padding: 1rem !important; }
      .form-row { grid-template-columns: 1fr; gap: 8px; }
      .form-label { padding-top: 0; }
      .form-body { padding: 1.25rem; }
      .form-header, .settings-card > h2, .settings-card > h5 { padding: 1rem 1.25rem; }
      .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
      .section-nav { position: static; }
      .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
      .section-nav li + li { margin-top: 0; }
      .numbering-inline-fields { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="bg-light company-settings-body dashboard">
<?php include __DIR__ . '/../includes/header_employee.php'; ?>
<main class="main-content company-settings-page p-0">
  <div class="company-settings-wrapper">
    <div class="company-settings-inner">
      <div class="company-page-head">
        <h1 class="company-page-title">Company Settings<?= !$isPendingSetup ? ': ' . htmlspecialchars((string) $company['company_name']) : '' ?></h1>
        <?php if (!$isPendingSetup): ?>
          <a href="<?= htmlspecialchars($settingsHubBackUrl) ?>" class="company-page-back">
            <i class="fas fa-arrow-left text-xs" aria-hidden="true"></i> Back to Settings
          </a>
        <?php endif; ?>
      </div>
      <?php if ($isPendingSetup): ?>
        <p class="help-text mb-3">Complete company setup in 7 simple steps. You can save and continue later.</p>
      <?php endif; ?>
      <div class="company-toolbar">
        <span class="status-badge <?= htmlspecialchars($setupStatus) ?>"><?= htmlspecialchars($setupStatusLabel) ?></span>
        <?php if ($topSaveFormId !== null): ?>
          <button type="submit" class="btn btn-save btn-save-changes" form="<?= htmlspecialchars($topSaveFormId) ?>"><?= $isPendingSetup ? 'Save draft' : 'Save changes' ?></button>
        <?php endif; ?>
      </div>
    <?php if ($message !== ''): ?><div class="alert alert-info rounded-xl border-0 shadow-sm"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger rounded-xl border-0 shadow-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($isPendingSetup && !isset($_GET['tab'])): ?>
      <div class="wizard-steps">
        <?php foreach ($wizardSteps as $stepNum => $stepLabel): ?>
          <?php
            $stepClass = $wizardStep === $stepNum ? 'active' : ($wizardStep > $stepNum ? 'done' : '');
            $stepUrl = $settingsBaseUrl . '&step=' . $stepNum;
          ?>
          <a class="wizard-step <?= $stepClass ?>" href="<?= htmlspecialchars($stepUrl) ?>">
            <span class="wizard-step-index"><?= ($wizardStep > $stepNum) ? '✓' : (string) ((int) $stepNum) ?></span>
            <span class="wizard-step-label"><?= htmlspecialchars($stepLabel) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($wizardStep === 1): ?>
        <div class="settings-card">
          <h5>Step 1: Company Profile</h5>
          <p class="settings-muted mb-3">Enter your company basic information.</p>
          <form method="post" class="row g-3" id="wizard-form-step-1">
            <input type="hidden" name="save_profile" value="1">
            <div class="col-md-3"><label class="form-label">Company Name *</label><input name="company_name" class="form-control" value="<?= htmlspecialchars((string) ($company['company_name'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Legal Name</label><input name="legal_name" class="form-control" value="<?= htmlspecialchars((string) ($company['legal_name'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Email *</label><input name="email" class="form-control" value="<?= htmlspecialchars((string) ($company['email'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= htmlspecialchars((string) ($company['phone'] ?? '')) ?>"></div>

            <div class="col-md-3"><label class="form-label">Country *</label><input name="country" class="form-control" value="<?= htmlspecialchars((string) ($company['country'] ?? 'Tanzania')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Address</label><input name="address" class="form-control" value="<?= htmlspecialchars((string) ($company['address'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Location</label><input name="company_location" class="form-control" value="<?= htmlspecialchars((string) ($settings['company_location'] ?? '')) ?>" placeholder="e.g. Dar es Salaam, Tanzania"></div>
            <div class="col-md-3"><label class="form-label">Timezone *</label><input name="timezone" class="form-control" value="<?= htmlspecialchars((string) ($company['timezone'] ?? 'Africa/Dar_es_Salaam')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Base Currency *</label><input name="base_currency" class="form-control" value="<?= htmlspecialchars((string) ($company['base_currency'] ?? 'TZS')) ?>"></div>
            <?php if (isSuperAdmin()): ?>
              <div class="col-md-3"><label class="form-label">Database Name</label><input name="db_name" class="form-control" value="<?= htmlspecialchars((string) ($company['db_name'] ?? '')) ?>" placeholder="e.g. company_db"></div>
            <?php endif; ?>

            <div class="col-12 d-flex justify-content-between">
              <button class="btn btn-outline-secondary" type="button" disabled>Back</button>
              <button class="btn btn-primary" name="go_to_step" value="2">Save &amp; Next</button>
            </div>
          </form>
        </div>
        <div class="settings-card py-2 px-3">
          <div class="settings-muted small">
            <strong>Don't worry!</strong> You can save as draft at any time and continue from where you left off.
          </div>
        </div>
      <?php elseif ($wizardStep === 2): ?>
        <div class="settings-card">
          <h5>Step 2: Branding</h5>
          <p class="settings-muted mb-3">Upload your logo, choose primary color and preview how your documents will look.</p>
          <form method="post" class="row g-3" id="wizard-form-step-2" enctype="multipart/form-data">
            <input type="hidden" name="save_profile" value="1">
            <input type="hidden" name="company_logo" value="<?= htmlspecialchars((string) ($settings['company_logo'] ?? '')) ?>">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Upload Logo</label>
              <div class="settings-muted small mb-2">Recommended size: 300x100px, PNG or JPG.</div>
              <input id="step2LogoInput" type="file" name="company_logo_file" class="d-none" accept=".png,.jpg,.jpeg,.webp,.gif,.svg,image/*">
              <div class="brand-upload-card">
                <div class="brand-logo-box d-flex align-items-center gap-2">
                  <div style="width:56px; height:56px; border:1px solid #e2e8f0; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#fff; overflow:hidden; flex-shrink:0;">
                    <?php if ($companyLogoUrl !== ''): ?>
                      <img class="js-step2-logo-preview" src="<?= htmlspecialchars((string) $companyLogoUrl) ?>" alt="Logo" style="max-width:48px; max-height:48px;">
                    <?php else: ?>
                      <span class="js-step2-logo-fallback settings-muted small">Logo</span>
                      <img class="js-step2-logo-preview" src="" alt="Logo" style="max-width:48px; max-height:48px; display:none;">
                    <?php endif; ?>
                  </div>
                  <div class="fw-semibold lh-sm"><?= htmlspecialchars((string) ($company['company_name'] ?? 'Company')) ?></div>
                </div>
                <div class="mt-2 d-grid">
                  <label for="step2LogoInput" class="btn btn-outline-primary btn-sm">Change Logo</label>
                </div>
                <div id="step2UploadStatus" class="small mt-2 <?= $companyLogoUrl !== '' ? 'text-success' : 'settings-muted' ?>">
                  <?= $companyLogoUrl !== '' ? 'Logo uploaded successfully' : 'No logo uploaded yet' ?>
                </div>
                <?php if (isSuperAdmin()): ?>
                  <div class="small settings-muted mt-1">
                    Path: <?= htmlspecialchars((string) ($settings['company_logo'] ?? '')) ?><?= $companyLogoExists ? ' (found)' : ' (missing)' ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="mt-3">
                <label class="form-label fw-semibold">Primary Color</label>
                <div class="settings-muted small mb-2">Choose the primary color for your system and documents.</div>
                <div class="d-flex gap-2 align-items-center">
                  <input id="step2ColorInput" type="color" name="primary_color" class="form-control form-control-color" style="width:56px; min-width:56px;" value="<?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>">
                  <input id="step2HexValue" type="text" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>" readonly>
                </div>
              </div>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Document Header Preview</label>
              <div class="settings-muted small mb-2">This is how your documents will look with your logo and primary color.</div>
              <div class="doc-preview-card">
                <div id="step2ColorBar" class="doc-preview-top" style="background: <?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>;"></div>
                <div class="doc-preview-body">
                  <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                      <div style="width:44px; height:44px; border:1px solid #93c5fd; border-radius:7px; display:flex; align-items:center; justify-content:center; background:#fff; overflow:hidden;">
                        <?php if ($companyLogoUrl !== ''): ?>
                          <img class="js-step2-logo-preview" src="<?= htmlspecialchars((string) $companyLogoUrl) ?>" alt="Logo" style="max-width:38px; max-height:38px;">
                        <?php else: ?>
                          <span class="js-step2-logo-fallback settings-muted small">Logo</span>
                          <img class="js-step2-logo-preview" src="" alt="Logo" style="max-width:38px; max-height:38px; display:none;">
                        <?php endif; ?>
                      </div>
                      <div class="fw-bold"><?= htmlspecialchars((string) ($company['company_name'] ?? 'Company')) ?></div>
                    </div>
                    <div class="text-end">
                      <div id="step2DocTitle" class="fw-bold" style="color: <?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>;">PAYMENT VOUCHER</div>
                      <div class="settings-muted small">PV/ROAD/<?= date('Y') ?>/001</div>
                    </div>
                  </div>
                  <div class="doc-line"></div>
                  <div class="row g-2 small settings-muted">
                    <div class="col-md-4"><?= htmlspecialchars((string) ($company['address'] ?? 'Address')) ?></div>
                    <div class="col-md-4"><?= htmlspecialchars((string) ($company['phone'] ?? 'Phone')) ?></div>
                    <div class="col-md-4"><?= htmlspecialchars((string) ($company['email'] ?? 'Email')) ?></div>
                  </div>
                  <div class="doc-line"></div>
                  <div class="row g-3">
                    <div class="col-md-7">
                      <div class="small mb-1">Pay To:</div>
                      <div class="doc-field"></div>
                    </div>
                    <div class="col-md-5">
                      <div class="small mb-1">Date:</div>
                      <div class="doc-field"></div>
                    </div>
                    <div class="col-md-7">
                      <div class="small mb-1">The Sum of:</div>
                      <div class="doc-field"></div>
                    </div>
                    <div class="col-md-5">
                      <div class="small mb-1">Currency:</div>
                      <div class="border rounded px-2 py-1"><?= htmlspecialchars((string) ($company['base_currency'] ?? 'TZS')) ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-12 d-flex justify-content-between">
              <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=1') ?>">Back</a>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-primary">Save Step</button>
                <button class="btn btn-primary" name="go_to_step" value="3">Next: Finance</button>
              </div>
            </div>
          </form>
        </div>
        <div class="settings-card py-2 px-3">
          <div class="settings-muted small">
            <strong>Tip:</strong> You can save as draft at any time and continue from where you left off.
          </div>
        </div>
      <?php elseif ($wizardStep === 3): ?>
        <div class="settings-card">
          <h5>Step 3: Tax & Finance Defaults</h5>
          <p class="settings-muted mb-3">Set VAT, formatting, and approval defaults.</p>
          <form method="post" class="row g-3" id="wizard-form-step-3">
            <input type="hidden" name="save_profile" value="1">
            <div class="col-md-3"><label class="form-label">VAT Rate (%)</label><input name="vat_rate" class="form-control" value="<?= htmlspecialchars((string) ($settings['vat_rate'] ?? '18')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Date Format</label><input name="date_format" class="form-control" value="<?= htmlspecialchars((string) ($settings['date_format'] ?? 'Y-m-d')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Base Currency</label><input name="base_currency" class="form-control" value="<?= htmlspecialchars((string) ($company['base_currency'] ?? 'TZS')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Default Financial Year Start (MM-DD)</label><input name="financial_year_start" class="form-control" value="<?= htmlspecialchars((string) ($settings['financial_year_start'] ?? '01-01')) ?>"></div>
            <div class="col-md-4"><label class="form-label">TIN (Taxpayer Identification Number)</label><input name="company_tin" class="form-control" value="<?= htmlspecialchars((string) ($settings['company_tin'] ?? '')) ?>" placeholder="e.g. 156-585-246"></div>
            <div class="col-md-4"><label class="form-label">VRN / VAT Registration Number</label><input name="company_vat" class="form-control" value="<?= htmlspecialchars((string) ($settings['company_vat'] ?? '')) ?>" placeholder="e.g. 40-048025-L"></div>
            <div class="col-md-4"><label class="form-label">Location</label><input name="company_location" class="form-control" value="<?= htmlspecialchars((string) ($settings['company_location'] ?? '')) ?>" placeholder="e.g. Dar es Salaam, Tanzania"></div>
            <div class="col-12">
              <input type="hidden" name="approval_workflow_enabled" value="0">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="approval_workflow_enabled" value="1" <?= (($settings['approval_workflow_enabled'] ?? '0') === '1') ? 'checked' : '' ?>>
                <label class="form-check-label">Approval Workflow Enabled</label>
              </div>
            </div>
            <div class="col-12">
              <input type="hidden" name="allow_edit_approved_voucher_classification" value="0">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="allow_edit_approved_voucher_classification" value="1" <?= (($settings['allow_edit_approved_voucher_classification'] ?? '0') === '1') ? 'checked' : '' ?>>
                <label class="form-check-label">Allow limited edit of approved payment vouchers</label>
              </div>
              <div class="settings-muted small mt-1 ms-4">When enabled, staff may update approved vouchers (including posted) to change <strong>Purpose</strong> (General vs Stock Purchase) and link <strong>Sales Orders / quotations</strong> only. Restricted vouchers remain limited to admin, finance, or the creator.</div>
            </div>
            <div class="col-12 d-flex justify-content-between">
              <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=2') ?>">Back</a>
              <div class="d-flex gap-2">
                <button class="btn btn-primary">Save Step</button>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=4') ?>">Next: Modules</a>
              </div>
            </div>
          </form>
        </div>
      <?php elseif ($wizardStep === 4): ?>
        <div class="settings-card">
          <h5>Step 4: Modules</h5>
          <p class="settings-muted mb-3">Choose which modules this company should use.</p>
          <form method="post" id="wizard-form-step-4">
            <input type="hidden" name="save_modules" value="1">
            <div class="row g-3">
              <?php foreach ($moduleDefaults as $k => $v): $mod = $existingModules[$k] ?? null; $enabled = (!$mod || (int) ($mod['enabled'] ?? 1) === 1); ?>
                <div class="col-md-4">
                  <div class="module-card">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                      <div>
                        <div class="fw-semibold"><?= htmlspecialchars($v) ?></div>
                        <div class="settings-muted small"><?= htmlspecialchars((string) ($moduleDescriptions[$k] ?? '')) ?></div>
                      </div>
                      <div class="form-check form-switch">
                        <input type="hidden" name="module_enabled[<?= htmlspecialchars($k) ?>]" value="0">
                        <input class="form-check-input" type="checkbox" value="1" name="module_enabled[<?= htmlspecialchars($k) ?>]" <?= $enabled ? 'checked' : '' ?>>
                      </div>
                    </div>
                    <input type="hidden" name="module_name[<?= htmlspecialchars($k) ?>]" value="<?= htmlspecialchars($v) ?>">
                    <label class="form-label small mb-1">Custom Label (optional)</label>
                    <input class="form-control form-control-sm" name="custom_label[<?= htmlspecialchars($k) ?>]" value="<?= htmlspecialchars((string) ($mod['custom_label'] ?? '')) ?>">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between mt-3">
              <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=3') ?>">Back</a>
              <div class="d-flex gap-2">
                <button class="btn btn-primary">Save Step</button>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=5') ?>">Next: Numbering</a>
              </div>
            </div>
          </form>
        </div>
      <?php elseif ($wizardStep === 5): ?>
        <div class="settings-card">
          <h5>Step 5: Document Numbering</h5>
          <p class="settings-muted mb-3">Set prefixes and counters for key documents.</p>
          <form method="post" class="row g-3" id="wizard-form-step-5">
            <input type="hidden" name="save_sequences" value="1">
            <?php foreach (['payment_voucher' => 'Payment Voucher', 'invoice' => 'Invoice', 'purchase_order' => 'Purchase Order'] as $docKey => $label): 
              $seq = $sequences[$docKey] ?? []; 
              $parts = function_exists('parsePrefixParts') ? parsePrefixParts($seq['prefix'] ?? '') : ['prefix' => '', 'suffix' => ''];
              if (($seq['prefix'] ?? '') === '') {
                  $parts['prefix'] = strtoupper(substr($label, 0, 2)) . '/ROAD';
              }
            ?>
              <div class="col-md-4">
                <div class="module-card">
                  <h6><?= htmlspecialchars($label) ?></h6>
                  <div class="row g-2 mb-2">
                    <div class="col-5">
                      <label class="form-label mb-1">Prefix</label>
                      <input class="form-control" name="<?= htmlspecialchars($docKey) ?>_prefix_part" value="<?= htmlspecialchars($parts['prefix']) ?>" placeholder="e.g. PA">
                    </div>
                    <div class="col-2 text-center d-flex flex-column justify-content-end pb-2">
                      <span class="text-muted" style="font-size: 0.85rem; font-weight: bold; margin-bottom: 8px;">/{YEAR}/</span>
                    </div>
                    <div class="col-5">
                      <label class="form-label mb-1">Suffix</label>
                      <input class="form-control" name="<?= htmlspecialchars($docKey) ?>_suffix_part" value="<?= htmlspecialchars($parts['suffix']) ?>" placeholder="e.g. RMS">
                    </div>
                  </div>
                  <label class="form-label mt-1">Next Number</label>
                  <input class="form-control" type="number" name="<?= htmlspecialchars($docKey) ?>_next_number" value="<?= (int) ($seq['next_number'] ?? 1) ?>">
                  <label class="form-label mt-2">Padding</label>
                  <input class="form-control" type="number" name="<?= htmlspecialchars($docKey) ?>_padding" value="<?= (int) ($seq['padding'] ?? 3) ?>">
                  <label class="form-label mt-2">Year</label>
                  <input class="form-control" type="number" name="<?= htmlspecialchars($docKey) ?>_year" value="<?= (int) ($seq['year'] ?? date('Y')) ?>">
                </div>
              </div>
            <?php endforeach; ?>
            <div class="col-12 d-flex justify-content-between">
              <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=4') ?>">Back</a>
              <div class="d-flex gap-2">
                <button class="btn btn-primary">Save Step</button>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=6') ?>">Next: Employees</a>
              </div>
            </div>
          </form>
        </div>
      <?php elseif ($wizardStep === 6): ?>
        <div class="settings-card">
          <h5>Step 6: Employee Registration</h5>
          <p class="settings-muted mb-3">Set how many users you plan to register for this company.</p>
          <form method="post" class="row g-3" id="wizard-form-step-6">
            <input type="hidden" name="save_profile" value="1">
            <div class="col-md-6">
              <label class="form-label">Number of Users to Register</label>
              <input type="number" min="1" step="1" name="users_to_register_count" class="form-control" value="<?= htmlspecialchars((string) ($settings['users_to_register_count'] ?? '1')) ?>">
              <div class="form-text">Enter the total number of team members you plan to register.</div>
            </div>
            <div class="col-12 d-flex justify-content-between">
              <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=5') ?>">Back</a>
              <div class="d-flex gap-2">
                <button class="btn btn-primary">Save Step</button>
                <a class="btn btn-outline-primary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=7') ?>">Next: Review</a>
              </div>
            </div>
          </form>
        </div>
      <?php else: ?>
        <div class="settings-card">
          <h5>Step 7: Review & Activate</h5>
          <p class="settings-muted mb-3">Verify setup and activate the company workspace.</p>
          <div class="row g-3 mb-4">
            <div class="col-md-6"><div class="module-card">Company profile complete: <strong><?= !empty($company['company_name']) ? 'Yes' : 'No' ?></strong></div></div>
            <div class="col-md-6"><div class="module-card">Logo uploaded: <strong><?= $companyLogoUrl !== '' ? 'Yes' : 'No' ?></strong></div></div>
            <div class="col-md-6"><div class="module-card">Modules selected: <strong><?php $enabledCount = 0; foreach ($existingModules as $m) { if ((int) ($m['enabled'] ?? 0) === 1) { $enabledCount++; } } echo (int) $enabledCount; ?></strong></div></div>
            <div class="col-md-6"><div class="module-card">Numbering configured: <strong><?= count($sequences) >= 3 ? 'Yes' : 'Partial' ?></strong></div></div>
            <?php $reviewMode = (string) ($company['employee_registration_mode'] ?? 'admin_only'); ?>
            <div class="col-md-6"><div class="module-card">Registration method: <strong><?= htmlspecialchars((string) ($employeeModeLabels[$reviewMode] ?? $reviewMode)) ?></strong></div></div>
            <div class="col-md-6"><div class="module-card">Company invite code: <strong><?= htmlspecialchars((string) ($company['invite_code'] ?? 'Not set')) ?></strong></div></div>
          </div>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <label class="form-label">Company Access Link</label>
              <div class="input-group">
                <input id="companyAccessLinkReview" type="text" class="form-control" readonly value="<?= htmlspecialchars((string) $companyAccessLink) ?>">
                <button type="button" class="btn btn-outline-secondary js-copy-link" data-target="companyAccessLinkReview">Copy Link</button>
                <a class="btn btn-outline-primary" target="_blank" href="<?= htmlspecialchars((string) $companyAccessLink) ?>">Open Link</a>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Employee Registration Link (Secure Invite)</label>
              <div class="input-group">
                <input id="employeeInviteLinkReview" type="text" class="form-control" readonly value="<?= htmlspecialchars((string) $employeeInviteLink) ?>">
                <button type="button" class="btn btn-outline-secondary js-copy-link" data-target="employeeInviteLinkReview">Copy Link</button>
                <a class="btn btn-outline-primary" target="_blank" href="<?= htmlspecialchars((string) $employeeInviteLink) ?>">Open Link</a>
              </div>
              <div class="form-text">Share this link with your team. Regenerating the invite code invalidates the old link.</div>
            </div>
          </div>
          <form method="post" class="d-flex justify-content-between" id="wizard-form-step-7">
            <input type="hidden" name="save_profile" value="1">
            <input type="hidden" name="setup_status" value="active">
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($settingsBaseUrl . '&step=6') ?>">Back</a>
            <button class="btn btn-primary" name="complete_setup" value="1">Complete Setup</button>
          </form>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($activeTab === 'profile'): ?>
        <?php
          $profileCountry = (string) ($company['country'] ?? 'Tanzania');
          $profileTimezone = (string) ($company['timezone'] ?? 'Africa/Dar_es_Salaam');
          $profileCurrency = (string) ($company['base_currency'] ?? 'TZS');
        ?>
        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Profile</h2>
            <p class="help-text mb-0 mt-1">Update core company details used across your workspace.</p>
          </div>
          <div class="form-body">
            <form method="post" id="tab-form-profile">
              <input type="hidden" name="save_profile" value="1">
              <div class="form-row">
                <label class="form-label" for="cs_company_name">Company name</label>
                <div><input id="cs_company_name" name="company_name" class="form-input" value="<?= htmlspecialchars((string) ($company['company_name'] ?? '')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_legal_name">Legal name</label>
                <div><input id="cs_legal_name" name="legal_name" class="form-input" value="<?= htmlspecialchars((string) ($company['legal_name'] ?? '')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_email">Email</label>
                <div><input id="cs_email" type="email" name="email" class="form-input" value="<?= htmlspecialchars((string) ($company['email'] ?? '')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_phone">Phone</label>
                <div><input id="cs_phone" name="phone" class="form-input" value="<?= htmlspecialchars((string) ($company['phone'] ?? '')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_address">Address</label>
                <div><input id="cs_address" name="address" class="form-input" value="<?= htmlspecialchars((string) ($company['address'] ?? '')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_location">Location</label>
                <div><input id="cs_location" name="company_location" class="form-input" value="<?= htmlspecialchars((string) ($settings['company_location'] ?? '')) ?>" placeholder="e.g. Dar es Salaam, Tanzania"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_country">Country</label>
                <div><select id="cs_country" name="country" class="form-input"><?php foreach ($countryOptions as $countryOption): ?><option value="<?= htmlspecialchars($countryOption) ?>"<?= $profileCountry === $countryOption ? ' selected' : '' ?>><?= htmlspecialchars($countryOption) ?></option><?php endforeach; ?><?php if ($profileCountry !== '' && !in_array($profileCountry, $countryOptions, true)): ?><option value="<?= htmlspecialchars($profileCountry) ?>" selected><?= htmlspecialchars($profileCountry) ?></option><?php endif; ?></select></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_timezone">Timezone</label>
                <div><select id="cs_timezone" name="timezone" class="form-input"><?php foreach ($timezoneOptions as $timezoneOption): ?><option value="<?= htmlspecialchars($timezoneOption) ?>"<?= $profileTimezone === $timezoneOption ? ' selected' : '' ?>><?= htmlspecialchars($timezoneOption) ?></option><?php endforeach; ?><?php if ($profileTimezone !== '' && !in_array($profileTimezone, $timezoneOptions, true)): ?><option value="<?= htmlspecialchars($profileTimezone) ?>" selected><?= htmlspecialchars($profileTimezone) ?></option><?php endif; ?></select></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_currency">Base currency</label>
                <div><select id="cs_currency" name="base_currency" class="form-input"><?php foreach ($currencyOptions as $currencyOption): ?><option value="<?= htmlspecialchars($currencyOption) ?>"<?= $profileCurrency === $currencyOption ? ' selected' : '' ?>><?= htmlspecialchars($currencyOption) ?></option><?php endforeach; ?><?php if ($profileCurrency !== '' && !in_array($profileCurrency, $currencyOptions, true)): ?><option value="<?= htmlspecialchars($profileCurrency) ?>" selected><?= htmlspecialchars($profileCurrency) ?></option><?php endif; ?></select></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_db_name">Database name</label>
                <div><?php if (isSuperAdmin()): ?><input id="cs_db_name" name="db_name" class="form-input" value="<?= htmlspecialchars((string) ($company['db_name'] ?? '')) ?>" placeholder="e.g. company_db"><?php else: ?><input id="cs_db_name" class="form-input" value="<?= htmlspecialchars((string) ($company['db_name'] ?? '')) ?>" readonly><?php endif; ?></div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn-save">Save changes</button>
              </div>
            </form>
          </div>
        </div>
        <div class="form-card access-link-card">
          <div class="form-header">
            <h2 class="section-title">Company access link</h2>
            <p class="help-text mb-0 mt-1">Share this link with your team to access the company portal.</p>
          </div>
          <div class="form-body pt-0">
            <div class="access-link-box">
              <input id="companyAccessLinkProfile" type="text" readonly value="<?= htmlspecialchars((string) $companyAccessLink) ?>" aria-label="Company access link">
              <div class="access-link-actions">
                <button type="button" class="btn js-copy-link" data-target="companyAccessLinkProfile"><i class="bi bi-clipboard"></i> Copy</button>
                <a class="btn" target="_blank" rel="noopener" href="<?= htmlspecialchars((string) $companyAccessLink) ?>"><i class="bi bi-box-arrow-up-right"></i> Open</a>
              </div>
            </div>
          </div>
        </div>
      <?php elseif ($activeTab === 'branding'): ?>
        <div class="settings-card">
          <h5>Branding</h5>
          <p class="settings-muted mb-3">Customize your workspace appearance and document headers.</p>
          <form method="post" class="row g-3" id="tab-form-branding" enctype="multipart/form-data">
            <input type="hidden" name="save_profile" value="1">
            <input type="hidden" name="company_logo" value="<?= htmlspecialchars((string) ($settings['company_logo'] ?? '')) ?>">
            
            <div class="col-md-5">
              <label class="form-label fw-semibold">Company Logo</label>
              <div class="brand-upload-card mb-3">
                <div class="brand-logo-box d-flex align-items-center justify-content-center p-3 mb-2" style="background: #f1f5f9; border-style: dashed;">
                  <?php if ($companyLogoUrl !== ''): ?>
                    <img id="brandingTabLogoPreview" src="<?= htmlspecialchars((string) $companyLogoUrl) ?>" alt="Logo" style="max-width: 100%; max-height: 80px; object-fit: contain;">
                  <?php else: ?>
                    <div id="brandingTabLogoFallback" class="text-center py-4">
                      <i class="fas fa-image text-gray-300 mb-2" style="font-size: 2rem;"></i>
                      <div class="settings-muted small">No logo uploaded</div>
                    </div>
                    <img id="brandingTabLogoPreview" src="" alt="Logo" style="max-width: 100%; max-height: 80px; object-fit: contain; display: none;">
                  <?php endif; ?>
                </div>
                <div class="d-grid">
                  <input type="file" name="company_logo_file" id="brandingTabLogoInput" class="form-control form-control-sm" accept="image/*" onchange="previewImage(this, 'brandingTabLogoPreview', 'brandingTabLogoFallback')">
                </div>
                <div class="settings-muted x-small mt-2">Recommended: 300x100px PNG or WebP. Max 5MB.</div>
              </div>

              <label class="form-label fw-semibold">Primary Color</label>
              <div class="d-flex gap-2 align-items-center">
                <input type="color" name="primary_color" class="form-control form-control-color" style="width:70px; min-width:70px;" value="<?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>" oninput="this.nextElementSibling.value=this.value; updateColorPreview(this.value)">
                <input type="text" class="form-control" value="<?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>" readonly>
              </div>
            </div>

            <div class="col-md-7">
              <label class="form-label fw-semibold">Document Preview</label>
              <div class="doc-preview-card shadow-sm" style="max-width: 450px;">
                <div id="brandingDocBar" class="doc-preview-top" style="background: <?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>;"></div>
                <div class="doc-preview-body">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                      <div style="width:40px; height:40px; border-radius:6px; display:flex; align-items:center; justify-content:center; background:#fff; overflow:hidden; border: 1px solid #e2e8f0;">
                        <?php if ($companyLogoUrl !== ''): ?>
                          <img class="branding-doc-logo" src="<?= htmlspecialchars((string) $companyLogoUrl) ?>" alt="Logo" style="max-width:34px; max-height:34px;">
                        <?php else: ?>
                          <i class="fas fa-building text-gray-300"></i>
                        <?php endif; ?>
                      </div>
                      <div class="fw-bold small"><?= htmlspecialchars((string) ($company['company_name'] ?? 'Company Name')) ?></div>
                    </div>
                    <div class="text-end">
                      <div class="fw-bold x-small text-uppercase" style="color: <?= htmlspecialchars((string) ($settings['primary_color'] ?? '#2563eb')) ?>;" id="brandingDocType">Invoice</div>
                      <div class="text-muted" style="font-size: 9px;">INV/2026/001</div>
                    </div>
                  </div>
                  <div style="height: 1px; background: #f1f5f9; margin-bottom: 10px;"></div>
                  <div class="row g-2" style="font-size: 8px; color: #64748b;">
                    <div class="col-4">Plot 123, Street Name</div>
                    <div class="col-4 text-center">+255 000 000 000</div>
                    <div class="col-4 text-end">contact@company.com</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12 mt-4 pt-3 border-top">
              <button type="submit" class="btn btn-save">Save branding settings</button>
            </div>
          </form>
        </div>
        <script>
        function previewImage(input, previewId, fallbackId) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById(previewId);
                    var fallback = document.getElementById(fallbackId);
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    if (fallback) fallback.style.display = 'none';
                    
                    // Also update doc preview logos
                    var docLogos = document.querySelectorAll('.branding-doc-logo');
                    docLogos.forEach(function(img) {
                        img.src = e.target.result;
                    });
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        function updateColorPreview(color) {
            document.getElementById('brandingDocBar').style.background = color;
            document.getElementById('brandingDocType').style.color = color;
        }
        </script>
      <?php elseif ($activeTab === 'finance'): ?>
        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Tax &amp; Finance</h2>
            <p class="help-text mb-0 mt-1">VAT, currency, and voucher workflow options for this company.</p>
          </div>
          <div class="form-body">
            <form method="post" id="tab-form-finance">
              <input type="hidden" name="save_profile" value="1">
              <div class="form-row">
                <label class="form-label" for="cs_vat_rate">VAT rate (%)</label>
                <div><input id="cs_vat_rate" name="vat_rate" class="form-input" value="<?= htmlspecialchars((string) ($settings['vat_rate'] ?? '18')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_date_format">Date format</label>
                <div><input id="cs_date_format" name="date_format" class="form-input" value="<?= htmlspecialchars((string) ($settings['date_format'] ?? 'Y-m-d')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_fin_base_currency">Base currency</label>
                <div><input id="cs_fin_base_currency" name="base_currency" class="form-input" value="<?= htmlspecialchars((string) ($company['base_currency'] ?? 'TZS')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_financial_year_start">Financial year start (MM-DD)</label>
                <div><input id="cs_financial_year_start" name="financial_year_start" class="form-input" value="<?= htmlspecialchars((string) ($settings['financial_year_start'] ?? '01-01')) ?>"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_tax_calculation_mode">Tax mode</label>
                <div>
                  <select id="cs_tax_calculation_mode" name="tax_calculation_mode" class="form-input">
                    <?php $taxCalcMode = (string) ($settings['tax_calculation_mode'] ?? 'exclusive'); ?>
                    <option value="exclusive"<?= $taxCalcMode === 'exclusive' ? ' selected' : '' ?>>Tax exclusive</option>
                    <option value="inclusive"<?= $taxCalcMode === 'inclusive' ? ' selected' : '' ?>>Tax inclusive</option>
                  </select>
                  <p class="help-text mb-0">Controls whether quotations and invoices add tax on top of prices or treat entered prices as tax-inclusive.</p>
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_company_tin">TIN</label>
                <div><input id="cs_company_tin" name="company_tin" class="form-input" value="<?= htmlspecialchars((string) ($settings['company_tin'] ?? '')) ?>" placeholder="e.g. 156-585-246"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_company_vat">VRN / VAT registration</label>
                <div><input id="cs_company_vat" name="company_vat" class="form-input" value="<?= htmlspecialchars((string) ($settings['company_vat'] ?? '')) ?>" placeholder="e.g. 40-048025-L"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_fin_location">Location</label>
                <div><input id="cs_fin_location" name="company_location" class="form-input" value="<?= htmlspecialchars((string) ($settings['company_location'] ?? '')) ?>" placeholder="e.g. Dar es Salaam, Tanzania"></div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_bank_details">Payment details</label>
                <div>
                  <textarea id="cs_bank_details" name="bank_details" class="form-input" rows="5" style="min-height: 120px;" placeholder="e.g. Bank name, account name, account number, branch, mobile payment details"><?= htmlspecialchars((string) ($settings['bank_details'] ?? '')) ?></textarea>
                  <p class="help-text mb-0">Shown on quotations and invoices for customer payments.</p>
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="cs_document_footer_message">Document footer</label>
                <div>
                  <textarea id="cs_document_footer_message" name="document_footer_message" class="form-input" rows="4" style="min-height: 96px;" placeholder="e.g. Thank you for your business. Goods sold are not returnable."><?= htmlspecialchars((string) ($settings['document_footer_message'] ?? '')) ?></textarea>
                  <p class="help-text mb-0">Printed at the bottom of quotations and invoices.</p>
                </div>
              </div>
              <div class="form-row form-row--options">
                <span class="form-label">Workflow</span>
                <div class="finance-options-stack">
                  <input type="hidden" name="approval_workflow_enabled" value="0">
                  <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="cs_approval_workflow" name="approval_workflow_enabled" value="1" <?= (($settings['approval_workflow_enabled'] ?? '0') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cs_approval_workflow">Approval workflow enabled</label>
                  </div>
                  <input type="hidden" name="allow_edit_approved_voucher_classification" value="0">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="cs_allow_edit_voucher" name="allow_edit_approved_voucher_classification" value="1" <?= (($settings['allow_edit_approved_voucher_classification'] ?? '0') === '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cs_allow_edit_voucher">Allow limited edit of approved payment vouchers</label>
                  </div>
                  <p class="help-text mt-2 mb-0">When enabled, staff may update approved vouchers (including posted) to change <strong>Purpose</strong> (General vs Stock Purchase) and link <strong>Sales Orders / quotations</strong> only. Restricted vouchers remain limited to admin, finance, or the creator.</p>
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn-save">Save changes</button>
              </div>
            </form>
          </div>
        </div>
      <?php elseif ($activeTab === 'modules'): ?>
        <div class="settings-card">
          <h5>Modules</h5>
          <form method="post" id="tab-form-modules">
            <input type="hidden" name="save_modules" value="1">
            <div class="row g-3">
              <?php foreach ($moduleDefaults as $k => $v): $mod = $existingModules[$k] ?? null; $enabled = (!$mod || (int) ($mod['enabled'] ?? 1) === 1); ?>
                <div class="col-md-4">
                  <div class="module-card">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                      <div>
                        <div class="fw-semibold"><?= htmlspecialchars($v) ?></div>
                        <div class="settings-muted small"><?= htmlspecialchars((string) ($moduleDescriptions[$k] ?? '')) ?></div>
                      </div>
                      <div class="form-check form-switch">
                        <input type="hidden" name="module_enabled[<?= htmlspecialchars($k) ?>]" value="0">
                        <input class="form-check-input" type="checkbox" value="1" name="module_enabled[<?= htmlspecialchars($k) ?>]" <?= $enabled ? 'checked' : '' ?>>
                      </div>
                    </div>
                    <input type="hidden" name="module_name[<?= htmlspecialchars($k) ?>]" value="<?= htmlspecialchars($v) ?>">
                    <label class="form-label small mb-1">Custom Label (optional)</label>
                    <input class="form-control form-control-sm" name="custom_label[<?= htmlspecialchars($k) ?>]" value="<?= htmlspecialchars((string) ($mod['custom_label'] ?? '')) ?>">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-save mt-3">Save changes</button>
          </form>
        </div>
      <?php elseif ($activeTab === 'numbering'): ?>
        <?php
          $numberingDocs = [
            'payment_voucher' => [
              'label' => 'Payment Voucher',
              'anchor' => 'numbering-pv',
              'subtitle' => 'Prefix and counter for payment vouchers.',
              'default_prefix' => 'PV',
            ],
            'invoice' => [
              'label' => 'Invoice',
              'anchor' => 'numbering-invoice',
              'subtitle' => 'Prefix and counter for sales invoices.',
              'default_prefix' => 'INV',
            ],
            'purchase_order' => [
              'label' => 'Purchase Order',
              'anchor' => 'numbering-po',
              'subtitle' => 'Prefix and counter for purchase orders.',
              'default_prefix' => 'PO',
            ],
          ];
          $buildSequencePreview = static function (string $prefixPart, string $suffixPart, int $nextNum, int $padding, int $year): string {
            $prefixPart = trim($prefixPart, '/');
            $suffixPart = trim($suffixPart, '/');
            $prefixVal = $prefixPart . '/' . $year . '/' . ($suffixPart !== '' ? $suffixPart . '/' : '');
            return $prefixVal . str_pad((string) max(1, $nextNum), max(1, $padding), '0', STR_PAD_LEFT);
          };
        ?>
        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Document Numbering</h2>
            <p class="section-subtitle">Set prefixes and counters for key documents. Numbers follow the pattern <strong>PREFIX/YEAR/SUFFIX/###</strong>.</p>
          </div>
          <div class="form-body">
            <form method="post" id="tab-form-numbering">
              <input type="hidden" name="save_sequences" value="1">
              <div class="editor-layout">
                <aside class="section-nav" aria-label="Document types">
                  <ul>
                    <?php $navFirst = true; foreach ($numberingDocs as $docMeta): ?>
                      <li><a href="#<?= htmlspecialchars($docMeta['anchor']) ?>" class="js-numbering-nav<?= $navFirst ? ' is-active' : '' ?>"><?= htmlspecialchars($docMeta['label']) ?></a></li>
                    <?php $navFirst = false; endforeach; ?>
                  </ul>
                </aside>
                <div class="editor-main">
                  <?php foreach ($numberingDocs as $docKey => $docMeta):
                    $seq = $sequences[$docKey] ?? [];
                    $parts = function_exists('parsePrefixParts') ? parsePrefixParts($seq['prefix'] ?? '') : ['prefix' => '', 'suffix' => ''];
                    if (($seq['prefix'] ?? '') === '') {
                      $parts['prefix'] = $docMeta['default_prefix'];
                    }
                    $nextNum = (int) ($seq['next_number'] ?? 1);
                    $padding = (int) ($seq['padding'] ?? 3);
                    $seqYear = (int) ($seq['year'] ?? date('Y'));
                    $preview = $buildSequencePreview($parts['prefix'], $parts['suffix'], $nextNum, $padding, $seqYear);
                  ?>
                  <section class="editor-section" id="<?= htmlspecialchars($docMeta['anchor']) ?>">
                    <div class="mb-4">
                      <h3 class="section-title"><?= htmlspecialchars($docMeta['label']) ?></h3>
                      <p class="section-subtitle"><?= htmlspecialchars($docMeta['subtitle']) ?></p>
                    </div>
                    <div class="form-row">
                      <label class="form-label" for="<?= htmlspecialchars($docKey) ?>_preview">Next number preview</label>
                      <div>
                        <input type="text" id="<?= htmlspecialchars($docKey) ?>_preview" class="form-input form-input-readonly js-seq-preview" value="<?= htmlspecialchars($preview) ?>" readonly tabindex="-1" data-doc="<?= htmlspecialchars($docKey) ?>">
                        <p class="help-text mb-0">Preview of the next <?= htmlspecialchars(strtolower($docMeta['label'])) ?> number based on the settings below.</p>
                      </div>
                    </div>
                    <div class="form-row">
                      <span class="form-label">Number format</span>
                      <div>
                        <div class="numbering-prefix-row">
                          <input type="text" class="form-input js-seq-prefix" name="<?= htmlspecialchars($docKey) ?>_prefix_part" value="<?= htmlspecialchars($parts['prefix']) ?>" placeholder="e.g. PA" data-doc="<?= htmlspecialchars($docKey) ?>" aria-label="Prefix">
                          <span class="numbering-prefix-sep">/{YEAR}/</span>
                          <input type="text" class="form-input js-seq-suffix" name="<?= htmlspecialchars($docKey) ?>_suffix_part" value="<?= htmlspecialchars($parts['suffix']) ?>" placeholder="e.g. RMS" data-doc="<?= htmlspecialchars($docKey) ?>" aria-label="Suffix">
                        </div>
                        <p class="help-text mb-0">Year is inserted automatically when documents are created.</p>
                      </div>
                    </div>
                    <div class="form-row">
                      <span class="form-label">Sequence settings</span>
                      <div class="numbering-inline-fields">
                        <div>
                          <label class="help-text d-block mb-1" for="<?= htmlspecialchars($docKey) ?>_next_number">Next number</label>
                          <input type="number" min="1" class="form-input js-seq-next" id="<?= htmlspecialchars($docKey) ?>_next_number" name="<?= htmlspecialchars($docKey) ?>_next_number" value="<?= $nextNum ?>" data-doc="<?= htmlspecialchars($docKey) ?>">
                        </div>
                        <div>
                          <label class="help-text d-block mb-1" for="<?= htmlspecialchars($docKey) ?>_padding">Padding</label>
                          <input type="number" min="1" max="10" class="form-input js-seq-padding" id="<?= htmlspecialchars($docKey) ?>_padding" name="<?= htmlspecialchars($docKey) ?>_padding" value="<?= $padding ?>" data-doc="<?= htmlspecialchars($docKey) ?>">
                        </div>
                        <div>
                          <label class="help-text d-block mb-1" for="<?= htmlspecialchars($docKey) ?>_year">Year</label>
                          <input type="number" min="2000" max="2100" class="form-input js-seq-year" id="<?= htmlspecialchars($docKey) ?>_year" name="<?= htmlspecialchars($docKey) ?>_year" value="<?= $seqYear ?>" data-doc="<?= htmlspecialchars($docKey) ?>">
                        </div>
                      </div>
                    </div>
                  </section>
                  <?php endforeach; ?>
                  <div class="form-actions" style="border-top: 0; padding-top: 0; justify-content: flex-start;">
                    <button type="submit" class="btn-save">Save changes</button>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php if ($legacyVoucherPrefixCount > 0 && $currentPvPrefix !== ''): ?>
        <div class="form-card legacy-migrate-card">
          <div class="form-header">
            <h2 class="section-title">Legacy voucher numbers</h2>
            <p class="section-subtitle mb-0"><strong><?= (int) $legacyVoucherPrefixCount ?></strong> existing voucher(s) still use an older prefix (for example <code>PV/RMSL/2026/</code>). Current prefix: <code><?= htmlspecialchars($currentPvPrefix, ENT_QUOTES, 'UTF-8') ?></code>.</p>
          </div>
          <div class="form-body pt-3">
            <p class="help-text mb-3">Renumbering assigns new sequence numbers after the current maximum to avoid duplicates (e.g. RMSL/003 may become UGT/012).</p>
            <form method="post" class="m-0" onsubmit="return confirm('Permanently renumber all legacy vouchers to <?= htmlspecialchars($currentPvPrefix, ENT_QUOTES, 'UTF-8') ?>?');">
              <input type="hidden" name="migrate_voucher_prefixes" value="1">
              <button type="submit" class="btn btn-warning">Renumber legacy vouchers now</button>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <script>
        (function () {
          function padNumber(num, padding) {
            var n = Math.max(1, parseInt(num, 10) || 1);
            var p = Math.max(1, parseInt(padding, 10) || 3);
            return String(n).padStart(p, '0');
          }
          function buildPreview(docKey) {
            var prefix = (document.querySelector('.js-seq-prefix[data-doc="' + docKey + '"]') || {}).value || '';
            var suffix = (document.querySelector('.js-seq-suffix[data-doc="' + docKey + '"]') || {}).value || '';
            var nextNum = (document.querySelector('.js-seq-next[data-doc="' + docKey + '"]') || {}).value || '1';
            var padding = (document.querySelector('.js-seq-padding[data-doc="' + docKey + '"]') || {}).value || '3';
            var year = (document.querySelector('.js-seq-year[data-doc="' + docKey + '"]') || {}).value || String(new Date().getFullYear());
            prefix = prefix.replace(/^\/+|\/+$/g, '');
            suffix = suffix.replace(/^\/+|\/+$/g, '');
            var out = prefix + '/' + year + '/' + (suffix ? suffix + '/' : '') + padNumber(nextNum, padding);
            var preview = document.querySelector('.js-seq-preview[data-doc="' + docKey + '"]');
            if (preview) preview.value = out;
          }
          document.querySelectorAll('.js-seq-prefix, .js-seq-suffix, .js-seq-next, .js-seq-padding, .js-seq-year').forEach(function (el) {
            el.addEventListener('input', function () { buildPreview(el.getAttribute('data-doc')); });
          });
          var navLinks = document.querySelectorAll('.js-numbering-nav');
          var sections = document.querySelectorAll('.editor-section[id^="numbering-"]');
          if (navLinks.length && sections.length) {
            navLinks.forEach(function (link) {
              link.addEventListener('click', function () {
                navLinks.forEach(function (l) { l.classList.remove('is-active'); });
                link.classList.add('is-active');
              });
            });
            if ('IntersectionObserver' in window) {
              var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                  if (!entry.isIntersecting) return;
                  var id = entry.target.getAttribute('id');
                  navLinks.forEach(function (l) {
                    l.classList.toggle('is-active', l.getAttribute('href') === '#' + id);
                  });
                });
              }, { rootMargin: '-30% 0px -55% 0px', threshold: 0 });
              sections.forEach(function (section) { observer.observe(section); });
            }
          }
        })();
        </script>
      <?php elseif ($activeTab === 'employees'): ?>
        <?php $erm = (string) ($company['employee_registration_mode'] ?? 'admin_only'); ?>
        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Register new company admin</h2>
            <p class="help-text mb-0 mt-1">Create a <strong>company administrator</strong> account. A random password is emailed to the address below (active immediately).</p>
          </div>
          <div class="form-body">
            <form method="post" id="tab-form-register-admin">
              <input type="hidden" name="register_admin_by_email" value="1">
              <div class="form-row">
                <label class="form-label" for="admin_email">Email <span class="text-danger">*</span></label>
                <div>
                  <input type="email" id="admin_email" name="admin_email" class="form-input" required placeholder="admin@company.com" autocomplete="off">
                  <p class="help-text">Administrator login details will be sent to this address.</p>
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="admin_full_name">Full name</label>
                <div>
                  <input type="text" id="admin_full_name" name="admin_full_name" class="form-input" placeholder="Optional — derived from email if empty">
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="admin_phone">Phone</label>
                <div>
                  <input type="text" id="admin_phone" name="admin_phone" class="form-input" placeholder="Optional">
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-save">Create admin &amp; email login</button>
              </div>
            </form>
          </div>
        </div>

        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Register new employee</h2>
            <p class="help-text mb-0 mt-1">Enter an email address. A random password is generated and login details are sent to that address.</p>
          </div>
          <div class="form-body">
            <form method="post" id="tab-form-register-employee">
              <input type="hidden" name="register_employee_by_email" value="1">
              <div class="form-row">
                <label class="form-label" for="employee_email">Email <span class="text-danger">*</span></label>
                <div>
                  <input type="email" id="employee_email" name="employee_email" class="form-input" required placeholder="employee@company.com" autocomplete="off">
                  <p class="help-text">Login credentials will be sent to this address.</p>
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="employee_full_name">Full name</label>
                <div>
                  <input type="text" id="employee_full_name" name="employee_full_name" class="form-input" placeholder="Optional — derived from email if empty">
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="employee_department">Department</label>
                <div>
                  <select id="employee_department" name="employee_department" class="form-input">
                    <?php foreach ($employeeInviteDepartments as $deptOption): ?>
                      <option value="<?= htmlspecialchars($deptOption) ?>"<?= $deptOption === 'General' ? ' selected' : '' ?>><?= htmlspecialchars($deptOption) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn-save">Create account &amp; email login</button>
              </div>
            </form>
          </div>
        </div>

        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Registration settings</h2>
            <p class="help-text mb-0 mt-1">Invite codes and self-registration rules for this company.</p>
          </div>
          <div class="form-body">
            <form method="post" id="tab-form-employees">
              <input type="hidden" name="save_profile" value="1">
              <div class="form-row">
                <label class="form-label" for="employee_registration_mode">Registration method</label>
                <div>
                  <select id="employee_registration_mode" name="employee_registration_mode" class="form-input">
                    <option value="admin_only" <?= $erm === 'admin_only' ? 'selected' : '' ?>>Admin only</option>
                    <option value="invite_only" <?= $erm === 'invite_only' ? 'selected' : '' ?>>Invite only</option>
                    <option value="open_with_approval" <?= $erm === 'open_with_approval' ? 'selected' : '' ?>>Open with approval</option>
                  </select>
                </div>
              </div>
              <div class="form-row">
                <label class="form-label" for="invite_code">Company invite code</label>
                <div><input id="invite_code" name="invite_code" class="form-input" value="<?= htmlspecialchars((string) ($company['invite_code'] ?? '')) ?>"></div>
              </div>
              <div class="form-row form-row--options">
                <span class="form-label">Options</span>
                <div class="finance-options-stack">
                  <input type="hidden" name="allow_employee_self_registration" value="0">
                  <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="allow_self_reg" name="allow_employee_self_registration" value="1" <?= ((int) ($company['allow_employee_self_registration'] ?? 0) === 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="allow_self_reg">Allow employees to register themselves</label>
                  </div>
                  <input type="hidden" name="require_admin_approval_for_new_users" value="0">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="require_admin_approval" name="require_admin_approval_for_new_users" value="1" <?= ((int) ($company['require_admin_approval_for_new_users'] ?? 1) === 1) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="require_admin_approval">Require admin approval for new users</label>
                  </div>
                </div>
              </div>
              <div class="form-actions d-flex justify-content-between flex-wrap gap-2">
                <button type="submit" class="btn btn-outline-primary" name="regenerate_invite_code" value="1">Generate new invite code</button>
                <button type="submit" class="btn-save">Save registration settings</button>
              </div>
            </form>
          </div>
        </div>

        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Recent employees</h2>
            <p class="help-text mb-0 mt-1">Accounts created for this company (employee role).</p>
          </div>
          <div class="form-body pt-3">
            <?php if (!$companyEmployees): ?>
              <p class="settings-muted mb-0">No employees registered yet. Use the form above to add one by email.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Email</th>
                      <th>Username</th>
                      <th>Department</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($companyEmployees as $emp): ?>
                      <tr>
                        <td><?= htmlspecialchars((string) ($emp['full_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($emp['email'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($emp['username'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($emp['department'] ?? '')) ?></td>
                        <td>
                          <?php
                            $empApproval = strtolower((string) ($emp['approval_status'] ?? 'approved'));
                            if ($empApproval === 'pending') {
                                echo '<span class="badge bg-warning-subtle text-warning-emphasis">Pending approval</span>';
                            } elseif ((int) ($emp['is_active'] ?? 1) === 1) {
                                echo '<span class="badge bg-success-subtle text-success">Active</span>';
                            } else {
                                echo '<span class="badge bg-secondary">Inactive</span>';
                            }
                          ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <p class="help-text mt-3 mb-0">
                <a href="company-users.php<?= htmlspecialchars($settingsQs()) ?>">Manage all company users</a>
              </p>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-card">
          <div class="form-header">
            <h2 class="section-title">Company admins</h2>
            <p class="help-text mb-0 mt-1">Users with the company administrator role for this tenant.</p>
          </div>
          <div class="form-body pt-3">
            <?php if (!$companyAdmins): ?>
              <p class="settings-muted mb-0">No company admins yet. Use <strong>Register new company admin</strong> above to add one by email.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Email</th>
                      <th>Username</th>
                      <th>Phone</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($companyAdmins as $admin): ?>
                      <tr>
                        <td><?= htmlspecialchars((string) ($admin['full_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($admin['email'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($admin['username'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($admin['phone'] ?? '—')) ?></td>
                        <td>
                          <?php if ((int) ($admin['is_active'] ?? 1) === 1): ?>
                            <span class="badge bg-success-subtle text-success">Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <p class="help-text mt-3 mb-0">
                <a href="company-users.php<?= htmlspecialchars($settingsQs()) ?>">Advanced user management</a>
              </p>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($activeTab === 'security'): ?>
        <div class="settings-card">
          <h5>Security</h5>
          <form id="tab-form-security" method="post"></form>
          <p class="settings-muted">Only super admins can edit any company by ID. Company admins can edit only their own company. Current access checks are enforced server-side.</p>
          <div class="module-card">
            <div class="fw-semibold mb-2">Access Controls</div>
            <ul class="mb-0">
              <li>Company isolation is enforced via `company_id` scope validation.</li>
              <li>Unauthorized company access returns `403` immediately.</li>
              <li>Company admin management is handled via company users page.</li>
            </ul>
          </div>
        </div>
      <?php else: ?>
        <div class="settings-card">
          <h5>Danger Zone</h5>
          <?php if (!isSuperAdmin()): ?>
            <p class="settings-muted mb-0">Danger Zone actions are restricted to Super Admin.</p>
          <?php else: ?>
            <p class="settings-muted">Use with care. Changes below can affect access and setup lifecycle.</p>
            <form method="post" class="row g-3" id="tab-form-danger">
              <input type="hidden" name="save_profile" value="1">
              <div class="col-md-4"><label class="form-label">Company Status</label><select name="status" class="form-select"><option value="active" <?= ($company['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($company['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
              <div class="col-md-4"><label class="form-label">Setup Status</label><select name="setup_status" class="form-select"><option value="active" <?= ($setupStatus === 'active') ? 'selected' : '' ?>>Active</option><option value="pending_setup" <?= ($setupStatus === 'pending_setup') ? 'selected' : '' ?>>Pending Setup</option><option value="suspended" <?= ($setupStatus === 'suspended') ? 'selected' : '' ?>>Suspended</option></select></div>
              <div class="col-12"><button class="btn btn-danger">Save Danger Zone Changes</button></div>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function () {
    var logoInput = document.getElementById('step2LogoInput');
    if (logoInput) {
      logoInput.addEventListener('change', function () {
        var file = logoInput.files && logoInput.files[0] ? logoInput.files[0] : null;
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
          var src = e.target && e.target.result ? e.target.result : '';
          var images = document.querySelectorAll('.js-step2-logo-preview');
          var fallbacks = document.querySelectorAll('.js-step2-logo-fallback');
          for (var i = 0; i < images.length; i++) {
            images[i].src = src;
            images[i].style.display = 'block';
          }
          for (var j = 0; j < fallbacks.length; j++) {
            fallbacks[j].style.display = 'none';
          }
          var status = document.getElementById('step2UploadStatus');
          if (status) {
            status.textContent = 'Logo selected. Click Save Step to keep changes.';
            status.classList.remove('settings-muted');
            status.classList.add('text-success');
          }
        };
        reader.readAsDataURL(file);
      });
    }

    var colorInput = document.getElementById('step2ColorInput');
    if (colorInput) {
      colorInput.addEventListener('input', function () {
        var color = colorInput.value || '#2563eb';
        var hexValue = document.getElementById('step2HexValue');
        var topBar = document.getElementById('step2ColorBar');
        var docTitle = document.getElementById('step2DocTitle');
        if (hexValue) hexValue.value = color;
        if (topBar) topBar.style.background = color;
        if (docTitle) docTitle.style.color = color;
      });
    }

    var copyButtons = document.querySelectorAll('.js-copy-link');
    for (var c = 0; c < copyButtons.length; c++) {
      copyButtons[c].addEventListener('click', function () {
        var targetId = this.getAttribute('data-target') || '';
        var input = targetId ? document.getElementById(targetId) : null;
        if (!input) return;
        try {
          navigator.clipboard.writeText(input.value || '');
        } catch (e) {
          input.select();
          input.setSelectionRange(0, 99999);
          document.execCommand('copy');
        }
        var oldHtml = this.innerHTML;
        this.innerHTML = '<i class="bi bi-check2"></i> Copied';
        var btn = this;
        setTimeout(function () {
          btn.innerHTML = oldHtml;
        }, 1500);
      });
    }
  })();
</script>
</body>
</html>
