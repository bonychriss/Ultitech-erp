<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../modules/email/includes/email_bootstrap.php';
requireLogin();

$userId = $_SESSION['user_id'];
$feedback = '';

// Handle profile photo removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_profile_photo'])) {
    try {
        $stmt = $pdo->prepare('SELECT profile_photo FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $oldPhoto = (string) ($stmt->fetchColumn() ?: '');
        $stmt = $pdo->prepare('UPDATE users SET profile_photo = NULL WHERE id = ?');
        $stmt->execute([$userId]);
        if ($oldPhoto !== '') {
            $abs = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $oldPhoto), '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $feedback = 'Profile photo removed successfully.';
    } catch (Throwable $e) {
        $feedback = 'Error: Failed to remove profile photo.';
    }
}

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
$joinedLabel = date('M Y', strtotime((string) ($user['created_at'] ?? 'now')));
$lastLoginTs = strtotime((string) ($user['updated_at'] ?? ''));
if ($lastLoginTs) {
    $lastLoginDay = date('Y-m-d', $lastLoginTs);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($lastLoginDay === $today) {
        $lastLoginLabel = 'Today, ' . date('g:i A', $lastLoginTs);
    } elseif ($lastLoginDay === $yesterday) {
        $lastLoginLabel = 'Yesterday, ' . date('g:i A', $lastLoginTs);
    } else {
        $lastLoginLabel = date('M j, Y g:i A', $lastLoginTs);
    }
} else {
    $lastLoginLabel = '—';
}
$roleLabel = ucfirst((string) ($user['role'] ?? 'user'));
$isActiveUser = !isset($user['is_active']) || (int) $user['is_active'] === 1;
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
        body { font-family: var(--erp-font-family, 'Poppins', sans-serif); background: #e8eef4; color: #1e293b; }
        .main-content-wrapper { padding: 0 0 3rem; }
        .page-shell { padding-left: 0; max-width: none; }
        .editor-shell { max-width: 1120px; margin: 0 auto; padding: 0 1.5rem; }

        .acct-banner {
            height: 210px; margin: 0; border-radius: 0; position: relative; overflow: hidden;
            background:
                linear-gradient(180deg, rgba(232, 238, 244, 0) 62%, #e8eef4 100%),
                radial-gradient(ellipse 90% 70% at 18% 100%, #cbd5e1 0%, transparent 55%),
                radial-gradient(ellipse 70% 80% at 52% 110%, #94a3b8 0%, transparent 50%),
                radial-gradient(ellipse 80% 75% at 88% 105%, #64748b 0%, transparent 52%),
                linear-gradient(180deg, #93c5fd 0%, #7dd3fc 28%, #bae6fd 52%, #e0f2fe 78%, #e8eef4 100%);
        }

        .acct-profile-layout {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr) 220px;
            gap: 1.5rem;
            align-items: start;
            margin-top: -96px;
            padding-bottom: 2.5rem;
        }

        .acct-profile-col {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: sticky;
            top: 88px;
        }

        .acct-profile-card,
        .acct-aside-card,
        .editor-section {
            background: #fff;
            border-radius: 1.5rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.1);
            border: none;
        }

        .acct-profile-card {
            padding: 1.5rem 1.25rem 1.25rem;
            text-align: center;
        }
        .account-avatar-wrap { position: relative; width: 128px; height: 128px; margin: 0 auto 0.95rem; }
        .account-avatar {
            width: 128px; height: 128px; border-radius: 50%; background: #f1f5f9; overflow: hidden;
            border: 5px solid #fff; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12); display: flex; align-items: center; justify-content: center;
        }
        .account-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .account-avatar-fallback { font-size: 2.5rem; font-weight: 700; color: #64748b; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
        .account-avatar-btn {
            position: absolute; bottom: 4px; right: 4px; width: 34px; height: 34px; border-radius: 50%;
            background: #fff; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #1da1f2; box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .account-profile-name { font-size: 1.2rem; font-weight: 800; color: #0f172a; margin: 0 0 2px; }
        .account-profile-user { font-size: 0.9rem; color: #64748b; margin: 0 0 8px; }
        .account-profile-email { font-size: 0.82rem; color: #1da1f2; margin: 0 0 12px; word-break: break-word; }
        .account-profile-badges { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; margin-bottom: 10px; }
        .profile-badge {
            display: inline-flex; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600;
            background: #e8f5fe; color: #1da1f2;
        }
        .profile-badge-muted { background: #f1f5f9; color: #475569; }
        .profile-badge-success { background: #dcfce7; color: #166534; }
        .acct-profile-meta-list {
            list-style: none; margin: 0.35rem 0 0; padding: 0; text-align: left; font-size: 0.8rem; color: #64748b;
        }
        .acct-profile-meta-list li {
            display: flex; align-items: center; gap: 0.55rem; padding: 0.32rem 0;
        }
        .acct-profile-meta-list i { width: 1rem; text-align: center; color: #94a3b8; }
        .acct-profile-meta-list a { color: #1da1f2; text-decoration: none; font-weight: 600; }
        .acct-profile-actions { display: flex; flex-direction: column; gap: 0.55rem; }
        .acct-profile-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
            width: 100%; min-height: 2.55rem; border-radius: 999px; font-size: 0.84rem; font-weight: 700;
            text-decoration: none; border: none; cursor: pointer; transition: background 0.15s ease, color 0.15s ease;
        }
        .acct-profile-btn--primary { background: #1da1f2; color: #fff; box-shadow: 0 8px 18px rgba(29, 161, 242, 0.28); }
        .acct-profile-btn--primary:hover { background: #1a91da; color: #fff; }
        .acct-profile-btn--ghost { background: #1da1f2; color: #fff; box-shadow: 0 8px 18px rgba(29, 161, 242, 0.22); }
        .acct-profile-btn--ghost:hover { background: #1a91da; color: #fff; }

        .acct-feed { min-width: 0; display: flex; flex-direction: column; gap: 0.9rem; padding-top: 108px; }
        .acct-feed-toolbar {
            display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
            padding: 0 0.15rem;
        }
        .section-nav {
            display: flex; flex-wrap: wrap; gap: 0.15rem 1.15rem; align-items: center;
            background: transparent; border: none; box-shadow: none; padding: 0;
        }
        .section-nav ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.15rem 1.15rem; }
        .section-nav li + li { margin-top: 0; }
        .section-nav a {
            display: inline-block; padding: 0.2rem 0 0.45rem; border-radius: 0;
            color: #64748b; font-size: 0.92rem; font-weight: 700; text-decoration: none;
            border-bottom: 3px solid transparent; transition: color 0.15s ease, border-color 0.15s ease;
        }
        .section-nav a:hover { color: #0f172a; background: transparent; }
        .section-nav a.is-active { background: transparent; color: #1da1f2; border-bottom-color: #1da1f2; font-weight: 800; }
        .acct-following-pill {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 2.1rem; padding: 0 1.1rem; border-radius: 999px;
            background: #1da1f2; color: #fff; font-size: 0.8rem; font-weight: 700; text-decoration: none;
            box-shadow: 0 6px 14px rgba(29, 161, 242, 0.25);
        }
        .acct-following-pill:hover { background: #1a91da; color: #fff; }

        .editor-main { min-width: 0; display: flex; flex-direction: column; gap: 0.9rem; }
        .editor-section {
            padding: 1.2rem 1.3rem 1.35rem; margin: 0;
            scroll-margin-top: 100px;
        }
        .editor-section:last-of-type { margin-bottom: 0; border-bottom: none; }
        .section-header { margin-bottom: 1.1rem; }
        .section-title { font-size: 1.05rem; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
        .section-subtitle { font-size: 0.78rem; color: #94a3b8; margin: 0; }
        .form-row { display: grid; grid-template-columns: 160px 1fr; align-items: start; margin-bottom: 20px; }
        .form-row:last-child { margin-bottom: 0; }
        .form-label { font-size: 13px; font-weight: 600; color: #334155; padding-top: 12px; margin: 0; }
        .form-input {
            width: 100%; padding: 11px 14px; border: 1px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
        }
        .form-input:focus { border-color: #1da1f2; box-shadow: 0 0 0 4px rgba(29, 161, 242, 0.15); }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; margin-bottom: 0; }
        .inline-field-form { display: flex; gap: 12px; align-items: center; max-width: 640px; }
        .inline-field-form .form-input { flex: 1; min-width: 0; }
        .host-port-grid { display: grid; grid-template-columns: 120px 1fr; gap: 12px; }
        .btn-save {
            background: #1da1f2; color: white; padding: 12px 28px; border-radius: 999px;
            font-weight: 600; font-size: 14px; border: none; cursor: pointer;
            box-shadow: 0 4px 12px rgba(29, 161, 242, 0.22); transition: all 0.2s;
        }
        .btn-save:hover { background: #1a91da; }
        .btn-save-inline { padding: 10px 18px; border-radius: 999px; font-size: 13px; white-space: nowrap; flex-shrink: 0; }
        .btn-cancel { border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s; cursor: pointer; }
        .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
        .btn-secondary-lite {
            border: 1px solid #e2e8f0; background: #fff; color: #475569; padding: 8px 14px;
            border-radius: 999px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-secondary-lite:hover { background: #f8fafc; }
        .btn-secondary-lite:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-danger-lite { border-color: #fecaca; color: #dc2626; }
        .btn-danger-lite:hover { background: #fef2f2; }
        .profile-photo-row { display: none; }
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
            gap: 12px; flex-wrap: wrap; margin: 0; padding: 0.25rem 0 0.5rem;
        }

        .acct-aside { padding-top: 108px; }
        .acct-aside-card { padding: 1.2rem 1.1rem 1.35rem; text-align: center; position: sticky; top: 88px; }
        .acct-aside-illus {
            width: 100%; height: 120px; border-radius: 1.1rem; margin-bottom: 0.95rem;
            background:
                radial-gradient(circle at 30% 40%, #93c5fd 0%, transparent 50%),
                radial-gradient(circle at 70% 60%, #7dd3fc 0%, transparent 45%),
                linear-gradient(160deg, #e0f2fe, #eff6ff);
            display: flex; align-items: center; justify-content: center; color: #1da1f2; font-size: 2rem;
        }
        .acct-aside-card h3 { font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem; }
        .acct-aside-card p { font-size: 0.8rem; color: #64748b; line-height: 1.5; margin: 0; }

        @media (max-width: 1100px) {
            .acct-profile-layout { grid-template-columns: 240px minmax(0, 1fr); }
            .acct-aside { display: none; }
        }
        @media (max-width: 992px) {
            .main-content-wrapper { padding: 0 0 2.5rem !important; }
            .acct-banner { height: 140px; }
            .acct-profile-layout {
                grid-template-columns: 1fr; gap: 1rem; margin-top: -56px; padding: 0 0.85rem 1.5rem;
            }
            .editor-shell { padding: 0; }
            .acct-profile-col, .acct-aside-card { position: static; }
            .acct-feed, .acct-aside { padding-top: 0; }
            .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 18px; }
            .form-label { padding-top: 0; font-size: 13px; }
            .inline-field-form { flex-direction: column; align-items: stretch; }
            .host-port-grid { grid-template-columns: 1fr; }
            .btn-save { width: 100%; padding: 12px 24px; }
            .acct-feed-toolbar { flex-direction: column; align-items: stretch; }
            .acct-following-pill { align-self: flex-end; }
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

        <div class="acct-banner" aria-hidden="true"></div>
        <div class="page-shell editor-shell">
            <div class="acct-profile-layout">
                <div class="acct-profile-col">
                    <aside class="acct-profile-card">
                        <div class="account-avatar-wrap">
                            <div class="account-avatar">
                                <?php if ($profilePhotoUrl !== ''): ?>
                                    <img src="<?= $esc($profilePhotoUrl) ?>" alt="Profile photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <span class="account-avatar-fallback" style="display:none;"><?= $esc($initial) ?></span>
                                <?php else: ?>
                                    <span class="account-avatar-fallback"><?= $esc($initial) ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="account-avatar-upload">
                                <label class="account-avatar-btn" title="Upload photo">
                                    <i class="fas fa-camera"></i>
                                    <input type="file" name="profile_photo" accept="image/*" hidden onchange="this.form.submit()">
                                </label>
                            </form>
                        </div>
                        <h2 class="account-profile-name"><?= $esc($user['full_name'] ?? '') ?></h2>
                        <p class="account-profile-user">@<?= $esc($user['username'] ?? '') ?></p>
                        <p class="account-profile-email"><?= $esc($user['email'] ?? '') ?></p>
                        <div class="account-profile-badges">
                            <span class="profile-badge"><?= $esc(ucfirst((string) ($user['role'] ?? 'user'))) ?></span>
                            <?php if (!empty($user['department'])): ?>
                                <span class="profile-badge profile-badge-muted"><?= $esc($user['department']) ?></span>
                            <?php endif; ?>
                            <span class="profile-badge profile-badge-success">Active</span>
                        </div>
                        <ul class="acct-profile-meta-list">
                            <?php if (!empty($user['whatsapp_number'])): ?>
                                <li><i class="fas fa-phone"></i><span><?= $esc($user['whatsapp_number']) ?></span></li>
                            <?php endif; ?>
                            <li><i class="fas fa-calendar-alt"></i><span>Joined <?= $esc(date('M Y', strtotime((string) ($user['created_at'] ?? 'now')))) ?></span></li>
                            <li>
                                <i class="fas fa-signature"></i>
                                <span><?= $currentSig ? 'Signature on file' : 'Signature missing' ?></span>
                            </li>
                        </ul>
                    </aside>
                    <div class="acct-profile-actions">
                        <a href="#personal-details" class="acct-profile-btn acct-profile-btn--primary">Edit profile</a>
                        <a href="<?= $esc($profileBackUrl) ?>" class="acct-profile-btn acct-profile-btn--ghost">Back to modules</a>
                    </div>
                </div>

                <div class="acct-feed">
                    <div class="acct-feed-toolbar">
                        <nav class="section-nav" aria-label="Profile sections">
                            <ul>
                                <li><a href="#profile-photo" class="is-active">Photo</a></li>
                                <li><a href="#personal-details">Details</a></li>
                                <li><a href="#change-password">Security</a></li>
                                <li><a href="#digital-signature">Signature</a></li>
                                <li><a href="#personal-email">Email</a></li>
                            </ul>
                        </nav>
                        <a href="<?= $esc($logoutUrl) ?>" class="acct-following-pill">Log out</a>
                    </div>

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

                <aside class="acct-aside">
                    <div class="acct-aside-card">
                        <div class="acct-aside-illus" aria-hidden="true"><i class="fas fa-user-shield"></i></div>
                        <h3>Keep your account ready</h3>
                        <p>Update your photo, contact details, and digital signature so vouchers and approvals stay accurate across Accounting.</p>
                    </div>
                </aside>
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

