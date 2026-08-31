<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../modules/email/includes/email_bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$feedback = '';

// Handle profile photo upload
if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['profile_photo']['tmp_name']);
    
    if (in_array($mime, $allowed)) {
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $userId . '_' . time() . '.' . $ext;
        $targetDir = __DIR__ . '/../assets/uploads/profiles/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetDir . $filename)) {
            // Update DB
            $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            $stmt->execute(['assets/uploads/profiles/' . $filename, $userId]);
            $feedback = 'Profile photo updated successfully.';
        } else {
            $feedback = 'Error: Failed to move uploaded file.';
        }
    } else {
        $feedback = 'Error: Invalid file type. Allowed: JPG, PNG, GIF, WEBP.';
    }
}

// Handle signature save (canvas or upload)
// Handle signature save (canvas or upload)
if (function_exists('ensureUserSignatureColumn')) {
    ensureUserSignatureColumn();
}
if (function_exists('ensureProfilePhotoColumn')) {
    ensureProfilePhotoColumn();
}
if (function_exists('ensureWhatsAppColumn')) {
    ensureWhatsAppColumn();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['profile_photo'])) {
	if (isset($_POST['delete_signature'])) {
		try {
			deleteUserSignature($userId);
			$feedback = 'Signature deleted successfully.';
		} catch (Exception $e) {
			$feedback = 'Error: ' . $e->getMessage();
		}
	} // Handle WhatsApp Number Update
    elseif (isset($_POST['whatsapp_number'])) {
        $wa_number = trim($_POST['whatsapp_number']);
        $stmt = $pdo->prepare('UPDATE users SET whatsapp_number = ? WHERE id = ?');
        $stmt->execute([$wa_number, $userId]);
        $feedback = 'WhatsApp number updated successfully.';
    }
    // Handle Email Update
    elseif (isset($_POST['new_email'])) {
        $newEmail = function_exists('normalizeLoginEmail')
            ? normalizeLoginEmail($_POST['new_email'] ?? '')
            : strtolower(trim((string) ($_POST['new_email'] ?? '')));
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $feedback = 'Error: Enter a valid email address.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$newEmail, $userId]);
            if ($stmt->fetch()) {
                $feedback = 'Error: Email already in use.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
                if ($stmt->execute([$newEmail, $userId])) {
                    $_SESSION['email'] = $newEmail;
                    $cid = (int) (currentCompanyId() ?? ($_SESSION['company_id'] ?? 0));
                    if ($cid > 0 && function_exists('syncUserCompanyIndex')) {
                        syncUserCompanyIndex($cid, $userId);
                    }
                    $feedback = 'Email updated successfully.';
                } else {
                    $feedback = 'Error: Failed to update email.';
                }
            }
        }
    }
    // Handle Full Name Update
    elseif (isset($_POST['new_full_name'])) {
        $newFullName = trim($_POST['new_full_name']);
        if (strlen($newFullName) >= 2) {
            $oldFullName = $_SESSION['full_name'];
            $stmt = $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?");
            if ($stmt->execute([$newFullName, $userId])) {
                $_SESSION['full_name'] = $newFullName;
                
                // Propagate name change to payment_vouchers (legacy text fields)
                $voucherFields = ['applicant', 'prepared_by', 'checked_by', 'department_manager', 'general_manager'];
                foreach ($voucherFields as $field) {
                    try {
                        $colCheck = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE '$field'");
                        if ($colCheck->fetch()) {
                            $upd = $pdo->prepare("UPDATE payment_vouchers SET $field = ? WHERE $field = ?");
                            $upd->execute([$newFullName, $oldFullName]);
                        }
                    } catch (Exception $e) { }
                }
                $feedback = 'Full Name updated successfully.';
            } else {
                $feedback = 'Error: Failed to update name.';
            }
        } else {
            $feedback = 'Error: Full Name must be at least 2 characters.';
        }
    }
    // Handle Username Update
    elseif (isset($_POST['new_username'])) {
        $newUsername = trim($_POST['new_username']);
        if (strlen($newUsername) >= 3) {
            // Check uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$newUsername, $userId]);
            
            if ($stmt->fetch()) {
                $feedback = 'Error: Username already taken.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                if ($stmt->execute([$newUsername, $userId])) {
                    $_SESSION['username'] = $newUsername;
                    $cid = (int) (currentCompanyId() ?? ($_SESSION['company_id'] ?? 0));
                    if ($cid > 0) {
                        syncUserCompanyIndex($cid, $userId);
                    }
                    $feedback = 'Username updated successfully.';
                } else {
                    $feedback = 'Error: Failed to update username.';
                }
            }
        } else {
            $feedback = 'Error: Username must be at least 3 characters.';
        }
    }
    // Change password while logged in (current password not required)
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if ($newPassword !== $confirmPassword) {
            $feedback = 'Error: New passwords do not match.';
        } else {
            $strength = function_exists('evaluatePasswordStrength')
                ? evaluatePasswordStrength($newPassword)
                : array('acceptable' => strlen($newPassword) >= 8);
            if (empty($strength['acceptable'])) {
                $feedback = 'Error: Password is too weak. Use at least 8 characters with uppercase, lowercase, and a number (or symbol).';
            } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            if ($stmt->execute([$newHash, $userId])) {
                unset($_SESSION['password_reset_otp']);
                $feedback = 'Password updated successfully.';
            } else {
                $feedback = 'Error: Database update failed.';
            }
            }
        }
    }
 else {
		$result = handleUserSignatureUpload($userId);
		$feedback = $result['ok'] ? 'Signature saved successfully.' : ('Error: ' . $result['error']);
	}
}
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));

// Fetch current user details
$stmt = $pdo->prepare("SELECT id, username, full_name, email, role, department, is_active, created_at, updated_at, profile_photo, whatsapp_number FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
	header('Location: dashboard.php');
	exit();
}

// Fetch Personal Email Settings
$userEmailSettings = [
    'imap_host' => '', 'imap_port' => '993', 'imap_user' => '', 'imap_pass' => '', 'imap_ssl' => 'ssl',
    'smtp_host' => '', 'smtp_port' => '465', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_ssl' => 'ssl'
];
try {
    $emailPdo = email_module_pdo();
    if ($emailPdo) {
        $stmtEmail = $emailPdo->prepare("SELECT * FROM module_email_user_settings WHERE user_id = ?");
        $stmtEmail->execute([$userId]);
        $emailRow = $stmtEmail->fetch(PDO::FETCH_ASSOC);
        if ($emailRow) {
            $userEmailSettings = array_merge($userEmailSettings, $emailRow);
        }
    }
} catch (Throwable $e) {}

$personalEmailDefaults = [
    'imap_host' => 'mail.example.com',
    'imap_port' => '993',
    'imap_user' => 'user@example.com',
    'imap_pass' => '',
    'imap_ssl' => 'ssl',
    'smtp_host' => 'mail.example.com',
    'smtp_port' => '465',
    'smtp_user' => 'user@example.com',
    'smtp_pass' => '',
    'smtp_ssl' => 'ssl',
];

foreach ($personalEmailDefaults as $settingKey => $defaultValue) {
    if (trim((string) ($userEmailSettings[$settingKey] ?? '')) === '' && $defaultValue !== '') {
        $userEmailSettings[$settingKey] = $defaultValue;
    }
}

if (trim((string) ($userEmailSettings['imap_port'] ?? '')) === '') {
    $userEmailSettings['imap_port'] = '993';
}
if (trim((string) ($userEmailSettings['smtp_port'] ?? '')) === '') {
    $userEmailSettings['smtp_port'] = '465';
}
if (trim((string) ($userEmailSettings['imap_ssl'] ?? '')) === '') {
    $userEmailSettings['imap_ssl'] = 'ssl';
}
if (trim((string) ($userEmailSettings['smtp_ssl'] ?? '')) === '') {
    $userEmailSettings['smtp_ssl'] = 'ssl';
}

$currentSig = getUserSignaturePathById($userId);
$profileBackUrl = function_exists('company_url')
    ? company_url(isAdmin() ? 'admin/dashboard.php' : 'select-module.php')
    : (isAdmin() ? '../admin/dashboard.php' : '../select-module.php');
$profilePhotoUrl = '';
if (!empty($user['profile_photo']) && function_exists('mediaUrlFromPath')) {
    $profilePhotoUrl = mediaUrlFromPath($user['profile_photo'], false);
} elseif (!empty($user['profile_photo'])) {
    $profilePhotoUrl = '../' . ltrim((string) $user['profile_photo'], '/');
}
$logoutUrl = function_exists('company_url') ? company_url('logout.php') : '../logout.php';
$employeeHeaderTitle = 'Profile Settings';
$employeeHeaderSubtitle = '';
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$selectArrow = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";
$saveEmailApiUrl = function_exists('company_url')
    ? company_url('api/save_user_email_settings.php')
    : (function_exists('app_url') ? app_url('api/save_user_email_settings.php') : '../api/save_user_email_settings.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Profile Settings - <?= $esc($user['full_name']) ?></title>
	<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
	<link rel="stylesheet" href="<?= $esc(function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css') ?>">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
	<?php if (function_exists('renderSystemFontHeadMarkup')) { renderSystemFontHeadMarkup(); } ?>
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
	<style>
        body { font-family: var(--erp-font-family, 'Poppins', sans-serif); background: #f8fafc; color: #1e293b; }
        .main-content-wrapper { padding: 2rem; }
        .page-shell { padding-left: 4rem; }
        .editor-shell { max-width: 1140px; margin: 0 auto; }
        .editor-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #e5e7eb;
        }
        .editor-layout { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 2rem; align-items: start; }
        .section-nav { position: sticky; top: 96px; align-self: start; }
        .section-nav ul { list-style: none; margin: 0; padding: 0; }
        .section-nav li + li { margin-top: 0.5rem; }
        .section-nav a {
            display: block; padding: 0.45rem 0.75rem; border-radius: 8px;
            color: #64748b; font-size: 13px; font-weight: 500; text-decoration: none; transition: all 0.2s ease;
        }
        .section-nav a:hover { background: #eff6ff; color: #2563eb; }
        .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
        .editor-main { min-width: 0; }
        .editor-section { padding-bottom: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; scroll-margin-top: 90px; }
        .editor-section:last-of-type { margin-bottom: 1.5rem; border-bottom: none; }
        .section-header { margin-bottom: 1.25rem; }
        .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0; }
        .form-row { display: grid; grid-template-columns: 210px 1fr; align-items: start; margin-bottom: 24px; }
        .form-row:last-child { margin-bottom: 0; }
        .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; margin: 0; }
        .form-input {
            width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
        }
        .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; margin-bottom: 0; }
        .inline-field-form { display: flex; gap: 12px; align-items: center; max-width: 640px; }
        .inline-field-form .form-input { flex: 1; min-width: 0; }
        .host-port-grid { display: grid; grid-template-columns: 120px 1fr; gap: 12px; }
        .btn-save {
            background: #7c3aed; color: white; padding: 14px 48px; border-radius: 12px;
            font-weight: 600; font-size: 15px; border: none; cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22); transition: all 0.2s;
        }
        .btn-save:hover { background: #6d28d9; }
        .btn-save-inline { padding: 10px 20px; border-radius: 10px; font-size: 14px; white-space: nowrap; flex-shrink: 0; }
        .btn-cancel { border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s; cursor: pointer; }
        .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
        .btn-secondary-lite {
            border: 1px solid #e2e8f0; background: #fff; color: #475569; padding: 8px 14px;
            border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-secondary-lite:hover { background: #f8fafc; }
        .btn-secondary-lite:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-danger-lite { border-color: #fecaca; color: #dc2626; }
        .btn-danger-lite:hover { background: #fef2f2; }
        .profile-photo-row { display: flex; gap: 24px; align-items: center; flex-wrap: wrap; }
        .account-avatar-wrap { position: relative; flex-shrink: 0; width: 120px; height: 120px; }
        .account-avatar {
            width: 120px; height: 120px; border-radius: 50%; background: #f1f5f9; overflow: hidden;
            border: 4px solid #fff; box-shadow: 0 0 0 1px #e2e8f0; display: flex; align-items: center; justify-content: center;
        }
        .account-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .account-avatar-fallback { font-size: 2.5rem; font-weight: 700; color: #64748b; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
        .account-avatar-btn {
            position: absolute; bottom: 0; right: 0; width: 36px; height: 36px; border-radius: 50%;
            background: #fff; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #7c3aed; box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .account-profile-name { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
        .account-profile-user { font-size: 14px; color: #64748b; margin: 0 0 4px; }
        .account-profile-email { font-size: 14px; color: #94a3b8; margin: 0 0 12px; }
        .account-profile-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; }
        .profile-badge {
            display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;
            background: #f3e8ff; color: #7c3aed;
        }
        .profile-badge-muted { background: #f1f5f9; color: #475569; }
        .profile-badge-success { background: #dcfce7; color: #166534; }
        .status-pill {
            display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;
        }
        .status-pill.is-active { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-pill.is-missing { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
        .password-actions-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding-top: 4px; }
        .account-forgot-link { font-size: 13px; color: #64748b; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .account-forgot-link:hover { color: #7c3aed; }
        .sig-tools { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
        .sig-tool-group { display: flex; align-items: center; gap: 8px; }
        .sig-tool-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 700; }
        .sig-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .canvas-container {
            position: relative; background: #fff; border: 2px dashed #d1d5db; border-radius: 12px;
            overflow: hidden; transition: border-color 0.2s;
        }
        .canvas-container:hover { border-color: #9ca3af; }
        #sigCanvas { display: block; width: 100%; height: 250px; cursor: crosshair; touch-action: none; }
        .canvas-placeholder {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            color: #9ca3af; font-size: 16px; pointer-events: none; opacity: 0.5;
        }
        .canvas-container.has-content .canvas-placeholder { opacity: 0; }
        .pen-size-btn, .color-btn {
            width: 32px; height: 32px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0;
        }
        .pen-size-btn.active, .color-btn.active { border-color: #7c3aed; background: #f3e8ff; color: #7c3aed; }
        .pen-size-btn .dot { background: currentColor; border-radius: 50%; }
        .pen-size-btn[data-size="2"] .dot { width: 4px; height: 4px; }
        .pen-size-btn[data-size="4"] .dot { width: 8px; height: 8px; }
        .pen-size-btn[data-size="6"] .dot { width: 12px; height: 12px; }
        .color-btn .color-circle { width: 20px; height: 20px; border-radius: 50%; border: 1px solid rgba(0,0,0,0.1); }
        .signature-preview-box {
            border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; padding: 16px; min-height: 180px;
        }
        .signature-preview-box img { max-height: 100px; max-width: 100%; }
        .signature-empty {
            border: 1px dashed #e2e8f0; border-radius: 12px; background: #f8fafc; padding: 32px 16px;
            text-align: center; color: #94a3b8; font-size: 13px;
        }
        .account-pw-field { position: relative; max-width: 640px; }
        .account-pw-field .account-pw-input { padding-right: 48px; }
        .account-pw-toggle {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 40px; height: 40px;
            border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; border-radius: 8px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; padding: 0;
        }
        .account-pw-toggle.is-visible { color: #7c3aed; border-color: rgba(124, 58, 237, 0.45); background: rgba(124, 58, 237, 0.12); }
        .account-pw-toggle svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; pointer-events: none; }
        .account-pw-toggle svg.account-pw-toggle-svg--hide { display: none; }
        .account-pw-toggle.is-visible svg.account-pw-toggle-svg--show { display: none; }
        .account-pw-toggle.is-visible svg.account-pw-toggle-svg--hide { display: block; }
        .account-pw-strength { margin-top: 12px; max-width: 640px; }
        .account-pw-strength-top { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 8px; }
        .account-pw-strength-bar { height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .account-pw-strength-fill { display: block; height: 100%; width: 0%; background: #ef4444; transition: all 0.25s ease; }
        .account-pw-strength-fill.is-fair { background: #f59e0b; }
        .account-pw-strength-fill.is-good { background: #8b5cf6; }
        .account-pw-strength-fill.is-strong { background: linear-gradient(90deg, #6f45ff, #7c3aed); }
        .account-pw-rules { list-style: none; margin: 12px 0 0; padding: 0; font-size: 12px; color: #64748b; }
        .account-pw-rules li { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .account-pw-rules li i { font-size: 6px; color: #cbd5e1; }
        .account-pw-rules li.is-met { color: #0f172a; font-weight: 500; }
        .account-pw-rules li.is-met i { color: #7c3aed; }
        .account-pw-match.is-ok { color: #16a34a; font-weight: 600; }
        .account-pw-match.is-bad { color: #dc2626; font-weight: 600; }
        .subsection-label { font-size: 14px; font-weight: 700; color: #334155; margin: 0 0 16px; }
        .editor-footer-actions {
            display: flex; justify-content: flex-end; align-items: center;
            gap: 16px; flex-wrap: wrap; margin-bottom: 5rem; padding-top: 8px;
        }
        @media (max-width: 992px) {
            .main-content-wrapper { padding: 1rem !important; }
            .page-shell { padding-left: 0; }
            .editor-topbar { flex-direction: column; align-items: flex-start; }
            .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
            .section-nav { position: static; }
            .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
            .section-nav li + li { margin-top: 0; }
            .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
            .form-label { padding-top: 0; font-size: 13px; }
            .inline-field-form { flex-direction: column; align-items: stretch; }
            .host-port-grid { grid-template-columns: 1fr; }
            .btn-save { width: 100%; padding: 14px 24px; }
        }
	</style>
</head>
<body class="dashboard">
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

<div class="main-content-wrapper">
		<?php if (!empty($feedback)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer)
                            toast.addEventListener('mouseleave', Swal.resumeTimer)
                        }
                    });

                    Toast.fire({
                        icon: '<?= strpos(strtolower($feedback), "success") !== false ? "success" : "error" ?>',
                        title: <?= json_encode($feedback) ?>
                    });
                });
            </script>
        <?php endif; ?>

        <div class="page-shell editor-shell">
            <div class="editor-topbar">
                <div>
                    <h1 class="text-xl font-semibold text-slate-800">Profile Settings</h1>
                    <p class="text-sm text-slate-400 mt-1 mb-0">Manage your profile, security, signature, and personal email.</p>
                </div>
                <a href="<?= $esc($profileBackUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-arrow-left text-xs"></i> Back to Modules
                </a>
            </div>
            
            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#profile-photo" class="is-active">Profile Photo</a></li>
                        <li><a href="#personal-details">Personal Details</a></li>
                        <li><a href="#change-password">Security &amp; Password</a></li>
                        <li><a href="#digital-signature">Digital Signature</a></li>
                        <li><a href="#personal-email">Personal Email</a></li>
                    </ul>
                </aside>
                
                <div class="editor-main">
		            <?php require __DIR__ . '/includes/account-settings-view.inc.php'; ?>

			<!-- Digital Signature -->
			<section class="editor-section" id="digital-signature">
                <div class="section-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div>
                        <h2 class="section-title">Digital Signature</h2>
                        <p class="section-subtitle">Draw your signature below. It will appear on vouchers where your name is used.</p>
                    </div>
                    <?php if ($currentSig): ?>
                        <span class="status-pill is-active">Signature Active</span>
                    <?php else: ?>
                        <span class="status-pill is-missing">Signature Missing</span>
                    <?php endif; ?>
                </div>
                <div class="form-row">
                    <label class="form-label">Draw Signature</label>
                    <div>
                        <div class="sig-tools">
                            <div class="sig-tool-group">
                                <span class="sig-tool-label">Size</span>
                                <button type="button" class="pen-size-btn active" data-size="2" onclick="setPenSize(2)" title="Thin"><div class="dot"></div></button>
                                <button type="button" class="pen-size-btn" data-size="4" onclick="setPenSize(4)" title="Medium"><div class="dot"></div></button>
                                <button type="button" class="pen-size-btn" data-size="6" onclick="setPenSize(6)" title="Thick"><div class="dot"></div></button>
                            </div>
                            <div class="sig-tool-group">
                                <span class="sig-tool-label">Color</span>
                                <button type="button" class="color-btn active" data-color="#000000" onclick="setPenColor('#000000')" title="Black"><div class="color-circle" style="background:#000;"></div></button>
                                <button type="button" class="color-btn" data-color="#1e40af" onclick="setPenColor('#1e40af')" title="Blue"><div class="color-circle" style="background:#1e40af;"></div></button>
                                <button type="button" class="color-btn" data-color="#7c3aed" onclick="setPenColor('#7c3aed')" title="Purple"><div class="color-circle" style="background:#7c3aed;"></div></button>
                            </div>
                        </div>
                        <div class="canvas-container" id="canvasContainer">
                            <canvas id="sigCanvas" height="250"></canvas>
                            <div class="canvas-placeholder">Sign here</div>
                        </div>
                        <div class="sig-actions">
                            <button type="button" class="btn-secondary-lite" onclick="undoStroke()" id="undoBtn" disabled><i class="fas fa-undo"></i> Undo</button>
                            <button type="button" class="btn-secondary-lite" onclick="redoStroke()" id="redoBtn" disabled><i class="fas fa-redo"></i> Redo</button>
                            <button type="button" class="btn-secondary-lite btn-danger-lite" onclick="clearCanvas()"><i class="fas fa-trash"></i> Clear</button>
                            <button type="button" class="btn-save btn-save-inline" onclick="saveSignature()" id="saveBtn" disabled><i class="fas fa-save"></i> Save Signature</button>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <label class="form-label">Current Signature</label>
                    <div>
                        <?php if ($currentSig): ?>
                            <div class="signature-preview-box">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
                                    <strong style="font-size:12px;text-transform:uppercase;color:#64748b;">Preview</strong>
                                    <form method="post" onsubmit="return confirm('Delete your signature? This cannot be undone.');" style="margin:0;">
                                        <button type="submit" name="delete_signature" value="1" class="btn-secondary-lite btn-danger-lite"><i class="fas fa-trash"></i> Remove</button>
                                    </form>
                                </div>
                                <div style="text-align:center;padding:12px;background:#fff;border-radius:8px;border:1px solid #e2e8f0;">
                                    <img src="<?= $esc(function_exists('mediaUrlFromPath') ? mediaUrlFromPath($currentSig, false) : ('../' . ltrim((string) $currentSig, '/'))) ?>" alt="Current signature">
                                </div>
                                <p class="help-text"><i class="fas fa-check-circle" style="color:#16a34a;"></i> Used on all new vouchers.</p>
                            </div>
                        <?php else: ?>
                            <div class="signature-empty">
                                <i class="fas fa-signature" style="font-size:28px;opacity:0.35;display:block;margin-bottom:8px;"></i>
                                No active signature yet. Draw and save one above.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
			</section>

            <!-- Personal Email Settings -->
			<section class="editor-section" id="personal-email">
                <div class="section-header">
					<h2 class="section-title">Personal Email Account</h2>
                    <p class="section-subtitle">Configure your personal IMAP/SMTP credentials for the email module.</p>
				</div>
                <form id="emailSettingsForm" onsubmit="saveEmailSettings(event)">
                    <p class="subsection-label">Incoming Server (IMAP)</p>
                    <div class="form-row">
                        <label class="form-label" for="imap_host">IMAP Host</label>
                        <div>
                            <input type="text" name="imap_host" id="imap_host" class="form-input" placeholder="mail.example.com" value="<?= $esc($userEmailSettings['imap_host']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="imap_port">IMAP Port</label>
                        <div>
                            <div class="host-port-grid">
                                <input type="number" name="imap_port" id="imap_port" class="form-input" placeholder="993" value="<?= $esc($userEmailSettings['imap_port']) ?>" required>
                                <select name="imap_ssl" class="form-input appearance-none pr-10" style="<?= $selectArrow ?>">
                                    <option value="ssl" <?= $userEmailSettings['imap_ssl'] === 'ssl' ? 'selected' : '' ?>>SSL (Recommended)</option>
                                    <option value="tls" <?= $userEmailSettings['imap_ssl'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="" <?= $userEmailSettings['imap_ssl'] === '' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="imap_user">Email / Username</label>
                        <div>
                            <input type="email" name="imap_user" id="imap_user" class="form-input" placeholder="user@example.com" value="<?= $esc($userEmailSettings['imap_user']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="imap_pass">Email Password</label>
                        <div>
                            <input type="password" name="imap_pass" id="imap_pass" class="form-input" placeholder="Your mailbox password" value="<?= $esc($userEmailSettings['imap_pass']) ?>" required>
                        </div>
                    </div>

                    <p class="subsection-label" style="margin-top:8px;">Outgoing Server (SMTP)</p>
                    <div class="form-row">
                        <label class="form-label" for="smtp_host">SMTP Host</label>
                        <div>
                            <input type="text" name="smtp_host" id="smtp_host" class="form-input" placeholder="mail.example.com" value="<?= $esc($userEmailSettings['smtp_host']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="smtp_port">SMTP Port</label>
                        <div>
                            <div class="host-port-grid">
                                <input type="number" name="smtp_port" id="smtp_port" class="form-input" placeholder="465" value="<?= $esc($userEmailSettings['smtp_port']) ?>" required>
                                <select name="smtp_ssl" class="form-input appearance-none pr-10" style="<?= $selectArrow ?>">
                                    <option value="ssl" <?= $userEmailSettings['smtp_ssl'] === 'ssl' ? 'selected' : '' ?>>SSL (Recommended)</option>
                                    <option value="tls" <?= $userEmailSettings['smtp_ssl'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="" <?= $userEmailSettings['smtp_ssl'] === '' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                            <p class="help-text">Use port 465 with SSL for most cPanel mail servers.</p>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="smtp_user">SMTP Username</label>
                        <div>
                            <input type="text" name="smtp_user" id="smtp_user" class="form-input" placeholder="user@example.com" value="<?= $esc($userEmailSettings['smtp_user']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label class="form-label" for="smtp_pass">SMTP Password</label>
                        <div>
                            <input type="password" name="smtp_pass" id="smtp_pass" class="form-input" placeholder="Your mailbox password" value="<?= $esc($userEmailSettings['smtp_pass']) ?>" required>
                        </div>
                    </div>
                </form>
            </section>

                    <div class="editor-footer-actions">
                        <button type="button" class="btn-save" style="background: #f1f5f9; color: #475569; box-shadow: none; border: 1px solid #cbd5e1;" id="testEmailBtn" onclick="testEmailConnection()">
                            <i class="fas fa-plug me-2"></i> Test Connection
                        </button>
                        <button type="submit" form="emailSettingsForm" class="btn-save" id="saveEmailBtn">
                            <i class="fas fa-save me-2"></i> Save Email Settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
</div>

<script>
    function testEmailConnection() {
        const btn = document.getElementById('testEmailBtn');
        const icon = btn.querySelector('i');
        const form = document.getElementById('emailSettingsForm');
        
        if (!form.reportValidity()) return;
        
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Testing...';
        
        fetch('<?= app_url('api/test_user_email_connection.php') ?>', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            if (data.status === 'success') {
                Swal.fire({ icon: 'success', title: 'Connection Successful', text: data.message });
            } else {
                Swal.fire({ icon: 'error', title: 'Connection Failed', html: data.message.replace(/\n/g, '<br>') });
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            Swal.fire('Error', 'Network connection failed during test.', 'error');
        });
    }

    function saveEmailSettings(e) {
        e.preventDefault();
        const btn = document.getElementById('saveEmailBtn');
        const icon = btn.querySelector('i');
        const form = document.getElementById('emailSettingsForm');
        
        btn.disabled = true;
        icon.className = 'fas fa-spinner fa-spin me-2';
        
        fetch('<?= app_url('api/save_user_email_settings.php') ?>', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            icon.className = 'fas fa-save me-2';
            if (data.status === 'success') {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Email settings saved successfully!', showConfirmButton: false, timer: 3000 });
            } else {
                Swal.fire('Error', data.message || 'Failed to save settings', 'error');
            }
        })
        .catch(err => {
            btn.disabled = false;
            icon.className = 'fas fa-save me-2';
            Swal.fire('Error', 'Network connection failed', 'error');
        });
    }

(function () {
    var newPw = document.getElementById('accountNewPassword');
    var confirmPw = document.getElementById('accountConfirmPassword');
    var form = document.querySelector('.account-password-form');
    var submitBtn = document.getElementById('accountPwSubmitBtn');
    if (!newPw || !form) return;

    var fill = document.getElementById('accountPwStrengthFill');
    var label = document.getElementById('accountPwStrengthLabel');
    var scoreEl = document.getElementById('accountPwStrengthScore');
    var bar = document.getElementById('accountPwStrengthBar');
    var matchEl = document.getElementById('accountPwMatch');
    var rules = document.getElementById('accountPwRules');

    function evaluate(pw) {
        pw = pw || '';
        var checks = {
            length: pw.length >= 8,
            mixed_case: /[a-z]/.test(pw) && /[A-Z]/.test(pw),
            digit: /\d/.test(pw),
            special: /[^a-zA-Z0-9]/.test(pw)
        };
        var score = 0;
        if (checks.length) score++;
        if (pw.length >= 12) score++;
        if (checks.mixed_case) score++;
        if (checks.digit) score++;
        if (checks.special) score++;
        var labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
        var level = Math.max(0, Math.min(5, score));
        return {
            checks: checks,
            score: level,
            label: labels[level],
            percent: Math.round((level / 5) * 100),
            acceptable: checks.length && (checks.mixed_case || checks.digit)
        };
    }

    function updateStrength() {
        var r = evaluate(newPw.value);
        if (!newPw.value) {
            label.textContent = 'Enter a password';
            scoreEl.textContent = '';
            fill.style.width = '0%';
            fill.className = 'account-pw-strength-fill';
            if (bar) bar.setAttribute('aria-valuenow', '0');
        } else {
            label.textContent = 'Password strength';
            scoreEl.textContent = r.label;
            fill.style.width = r.percent + '%';
            fill.className = 'account-pw-strength-fill'
                + (r.score >= 4 ? ' is-strong' : r.score >= 3 ? ' is-good' : r.score >= 2 ? ' is-fair' : '');
            if (bar) bar.setAttribute('aria-valuenow', String(r.percent));
        }
        if (rules) {
            rules.querySelectorAll('li[data-rule]').forEach(function (li) {
                var key = li.getAttribute('data-rule');
                var met = r.checks[key];
                li.classList.toggle('is-met', !!met);
            });
        }
        updateSubmitState();
    }

    function updateMatch() {
        if (!matchEl || !confirmPw) return;
        if (!confirmPw.value && !newPw.value) {
            matchEl.textContent = '';
            matchEl.className = 'account-pw-match help-text mb-0';
            return;
        }
        if (newPw.value === confirmPw.value) {
            matchEl.textContent = 'Passwords match';
            matchEl.className = 'account-pw-match help-text mb-0 is-ok';
        } else {
            matchEl.textContent = 'Passwords do not match';
            matchEl.className = 'account-pw-match help-text mb-0 is-bad';
        }
        updateSubmitState();
    }

    function updateSubmitState() {
        if (!submitBtn) return;
        var r = evaluate(newPw.value);
        var match = confirmPw && newPw.value === confirmPw.value && confirmPw.value.length > 0;
        submitBtn.disabled = !(r.acceptable && match);
    }

    newPw.addEventListener('input', function () {
        updateStrength();
        updateMatch();
    });
    if (confirmPw) {
        confirmPw.addEventListener('input', updateMatch);
    }

    form.addEventListener('submit', function (e) {
        var r = evaluate(newPw.value);
        if (!r.acceptable) {
            e.preventDefault();
            alert('Please choose a stronger password (at least 8 characters with letters and a number).');
            return;
        }
        if (!confirmPw || newPw.value !== confirmPw.value) {
            e.preventDefault();
            alert('Passwords do not match.');
        }
    });

    updateStrength();
    updateMatch();

    document.querySelectorAll('.account-pw-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-target');
            var input = id ? document.getElementById(id) : null;
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-visible', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('title', show ? 'Hide password' : 'Show password');
        });
    });
})();

const canvas = document.getElementById('sigCanvas');
const ctx = canvas.getContext('2d');
const container = document.getElementById('canvasContainer');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const saveBtn = document.getElementById('saveBtn');

let drawing = false;
let currentStroke = [];
let strokes = [];
let redoStack = [];
let penSize = 2;
let penColor = '#000000';

// Set canvas size to match display size
function resizeCanvas() {
	const rect = canvas.getBoundingClientRect();
	canvas.width = rect.width;
	canvas.height = rect.height;
	redrawCanvas();
}

// Initialize
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

// Drawing functions
function getPos(e) {
	const rect = canvas.getBoundingClientRect();
	const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
	const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
	return { x, y };
}

function startDrawing(e) {
	drawing = true;
	currentStroke = [{
		...getPos(e),
		size: penSize,
		color: penColor
	}];
	e.preventDefault();
}

function draw(e) {
	if (!drawing) return;
	
	const pos = getPos(e);
	const lastPos = currentStroke[currentStroke.length - 1];
	
	ctx.strokeStyle = penColor;
	ctx.lineWidth = penSize;
	ctx.lineCap = 'round';
	ctx.lineJoin = 'round';
	
	ctx.beginPath();
	ctx.moveTo(lastPos.x, lastPos.y);
	ctx.lineTo(pos.x, pos.y);
	ctx.stroke();
	
	currentStroke.push({ ...pos, size: penSize, color: penColor });
	updatePlaceholder();
	e.preventDefault();
}

function stopDrawing() {
	if (drawing && currentStroke.length > 1) {
		strokes.push(currentStroke);
		redoStack = [];
		updateButtons();
	}
	drawing = false;
	currentStroke = [];
}

// Event listeners
canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseleave', stopDrawing);
canvas.addEventListener('touchstart', startDrawing, { passive: false });
canvas.addEventListener('touchmove', draw, { passive: false });
canvas.addEventListener('touchend', stopDrawing);

// Tool functions
function setPenSize(size) {
	penSize = size;
	document.querySelectorAll('.pen-size-btn').forEach(btn => {
		btn.classList.toggle('active', btn.dataset.size == size);
	});
}

function setPenColor(color) {
	penColor = color;
	document.querySelectorAll('.color-btn').forEach(btn => {
		btn.classList.toggle('active', btn.dataset.color === color);
	});
}

function clearCanvas() {
	if (strokes.length === 0) return;
	if (!confirm('Clear your signature?')) return;
	
	ctx.clearRect(0, 0, canvas.width, canvas.height);
	strokes = [];
	redoStack = [];
	updateButtons();
	updatePlaceholder();
}

function undoStroke() {
	if (strokes.length === 0) return;
	redoStack.push(strokes.pop());
	redrawCanvas();
	updateButtons();
}

function redoStroke() {
	if (redoStack.length === 0) return;
	strokes.push(redoStack.pop());
	redrawCanvas();
	updateButtons();
}

function redrawCanvas() {
	ctx.clearRect(0, 0, canvas.width, canvas.height);
	
	strokes.forEach(stroke => {
		if (stroke.length < 2) return;
		
		ctx.strokeStyle = stroke[0].color;
		ctx.lineWidth = stroke[0].size;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		
		ctx.beginPath();
		ctx.moveTo(stroke[0].x, stroke[0].y);
		
		for (let i = 1; i < stroke.length; i++) {
			ctx.lineTo(stroke[i].x, stroke[i].y);
		}
		ctx.stroke();
	});
	
	updatePlaceholder();
}

function updateButtons() {
	undoBtn.disabled = strokes.length === 0;
	redoBtn.disabled = redoStack.length === 0;
	saveBtn.disabled = strokes.length === 0;
}

function updatePlaceholder() {
	container.classList.toggle('has-content', strokes.length > 0);
}

function saveSignature() {
	if (strokes.length === 0) {
		showToast('Please draw your signature first', 'error');
		return;
	}
	
	const data = canvas.toDataURL('image/png');
	const form = document.createElement('form');
	form.method = 'POST';
	const input = document.createElement('input');
	input.type = 'hidden';
	input.name = 'signatureData';
	input.value = data;
	form.appendChild(input);
	document.body.appendChild(form);
	
	showToast('Saving signature...', 'success');
	form.submit();
}

function showToast(message, type = 'success') {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    Toast.fire({
        icon: type,
        title: message
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
	if (e.ctrlKey || e.metaKey) {
		if (e.key === 'z') {
			e.preventDefault();
			undoStroke();
		} else if (e.key === 'y') {
			e.preventDefault();
			redoStroke();
		}
	}
});

// Show feedback is handled by top of file script

(function () {
    var navLinks = document.querySelectorAll('.section-nav a[href^="#"]');
    var sections = document.querySelectorAll('.editor-section[id]');
    if (!navLinks.length || !sections.length) return;

    function setActiveNav(id) {
        navLinks.forEach(function (link) {
            link.classList.toggle('is-active', link.getAttribute('href') === '#' + id);
        });
    }

    navLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            var target = document.querySelector(link.getAttribute('href'));
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setActiveNav(target.id);
        });
    });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) setActiveNav(entry.target.id);
            });
        }, { rootMargin: '-20% 0px -60% 0px', threshold: 0 });
        sections.forEach(function (section) { observer.observe(section); });
    }
})();
</script>
</body>
</html>

