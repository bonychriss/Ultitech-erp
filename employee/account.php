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
    elseif (isset($_POST['remove_profile_photo'])) {
        /* handled above */
    } else {
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
$homeUrl = $profileBackUrl;
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$employeeHeaderSubtitle = '<span class="ps-crumbs"><a href="' . $esc($profileBackUrl) . '">Home</a> <span class="ps-crumb-sep">/</span> <a href="#profile-photo">Account</a> <span class="ps-crumb-sep">/</span> <span class="ps-crumb-current">Profile Settings</span></span>';
$psNameParts = preg_split('/\s+/', trim((string) ($_SESSION['full_name'] ?? 'User'))) ?: ['U'];
$psInitials = strtoupper(substr((string) ($psNameParts[0] ?? 'U'), 0, 1) . (count($psNameParts) > 1 ? substr((string) end($psNameParts), 0, 1) : ''));
$psNotificationsUrl = function_exists('company_url') ? company_url('notifications.php') : '../notifications.php';
$employeeHeaderRightHtml = '<div class="ps-head-tools">'
    . '<label class="ps-head-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i>'
    . '<input type="search" id="psHeadSearch" placeholder="Search anything..." autocomplete="off" aria-label="Search profile settings"></label>'
    . '<a href="' . $esc($psNotificationsUrl) . '" class="ps-head-bell" title="Notifications" aria-label="Notifications"><i class="far fa-bell"></i><span class="ps-head-dot"></span></a>'
    . '<span class="ps-head-avatar" title="' . $esc($_SESSION['full_name'] ?? '') . '">' . $esc($psInitials) . '</span>'
    . '</div>';
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
        $lastLoginLabel = date(date('Y', $lastLoginTs) === date('Y') ? 'M j, g:i A' : 'M j, Y', $lastLoginTs);
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
        body { font-family: var(--erp-font-family, 'Poppins', sans-serif); background: #f4f6fb; color: #1e293b; }
        .main-content-wrapper { padding: 0 1.25rem 2.5rem; }
        .page-shell { padding-left: 0; max-width: none; }
        .editor-shell { max-width: 1120px; margin: 0 auto; padding: 0; }

        .ps-hero {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            padding: 1.35rem 1.5rem; margin-bottom: 0.35rem;
            border-radius: 1.35rem; color: #fff; position: relative; overflow: hidden;
            background:
                radial-gradient(ellipse 70% 120% at 90% 20%, rgba(196, 181, 253, 0.35), transparent 55%),
                radial-gradient(ellipse 50% 80% at 10% 120%, rgba(167, 139, 250, 0.28), transparent 50%),
                linear-gradient(115deg, #3b0764 0%, #5b21b6 42%, #6d28d9 100%);
        }
        .ps-hero::after {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 200'%3E%3Cpath d='M0 140 C160 80 280 180 420 110 C560 40 680 150 800 80 L800 200 L0 200 Z' fill='rgba(255,255,255,0.06)'/%3E%3C/svg%3E") bottom / cover no-repeat;
        }
        .ps-hero-main { display: flex; align-items: center; gap: 1.15rem; min-width: 0; position: relative; z-index: 1; }
        .account-avatar-wrap { position: relative; width: 96px; height: 96px; flex-shrink: 0; }
        .account-avatar {
            width: 96px; height: 96px; border-radius: 50%; background: #ede9fe; overflow: hidden;
            border: 4px solid rgba(255,255,255,0.9); box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);
            display: flex; align-items: center; justify-content: center;
        }
        .account-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .account-avatar-fallback { font-size: 2rem; font-weight: 800; color: #5b21b6; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
        .account-avatar-btn {
            position: absolute; bottom: 2px; right: 2px; width: 28px; height: 28px; border-radius: 50%;
            background: #7c3aed; border: 2px solid #fff; display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.18); font-size: 11px;
        }
        .ps-hero-copy { min-width: 0; }
        .ps-hero-copy h2 { margin: 0; font-size: 1.45rem; font-weight: 800; letter-spacing: -0.02em; }
        .ps-hero-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin: 0.35rem 0 0.45rem; }
        .ps-hero-handle { color: rgba(255,255,255,0.82); font-size: 0.88rem; font-weight: 600; }
        .profile-badge {
            display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700;
            background: rgba(255,255,255,0.18); color: #fff;
        }
        .profile-badge-muted { background: #f1f5f9; color: #475569; }
        .profile-badge-success { background: #bbf7d0; color: #166534; }
        .ps-hero-email { margin: 0 0 0.45rem; font-size: 0.84rem; color: rgba(255,255,255,0.78); display: flex; align-items: center; gap: 0.4rem; }
        .ps-hero-meta { display: flex; flex-wrap: wrap; gap: 0.85rem 1.15rem; font-size: 0.78rem; color: rgba(255,255,255,0.78); }
        .ps-hero-meta span { display: inline-flex; align-items: center; gap: 0.4rem; }
        .ps-logout {
            position: relative; z-index: 1; flex-shrink: 0;
            display: inline-flex; align-items: center; gap: 0.4rem;
            min-height: 2.2rem; padding: 0 0.95rem; border-radius: 0.7rem;
            background: rgba(255,255,255,0.12); color: #fff !important; text-decoration: none !important;
            border: 1px solid rgba(255,255,255,0.22); font-size: 0.82rem; font-weight: 700;
        }
        .ps-logout:hover { background: rgba(255,255,255,0.2); color: #fff !important; }

        .section-nav { margin: 0.15rem 0 1rem; }
        .section-nav ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.15rem 0.35rem; }
        .section-nav li + li { margin-top: 0; }
        .section-nav a {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.7rem 0.9rem 0.65rem; border-radius: 0;
            color: #64748b; font-size: 0.88rem; font-weight: 600; text-decoration: none;
            border-bottom: 2px solid transparent;
        }
        .section-nav a:hover { color: #0f172a; background: transparent; }
        .section-nav a.is-active { background: transparent; color: #7c3aed; border-bottom-color: #7c3aed; font-weight: 700; }

        .ps-body { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 1rem; align-items: start; }
        .editor-main { min-width: 0; display: flex; flex-direction: column; gap: 1rem; }
        .editor-section, .acct-aside-card, .ps-status-card {
            padding: 1.15rem 1.25rem 1.25rem; margin: 0; scroll-margin-top: 96px;
            background: #fff; border-radius: 1.15rem; border: 1px solid #eef2f7;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
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
        .form-input:focus { border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.12); }
        .ps-icon-field { position: relative; }
        .ps-icon-field i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.8rem; }
        .ps-icon-field .form-input { padding-left: 2.15rem; }
        .ps-details-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.85rem; }
        .ps-details-grid .form-label { padding-top: 0; margin-bottom: 0.35rem; display: block; }
        .ps-photo-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .ps-photo-preview { width: 56px; height: 56px; border-radius: 50%; overflow: hidden; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ps-photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .ps-upload-btn {
            display: inline-flex; align-items: center; gap: 0.4rem; min-height: 2.4rem; padding: 0 1rem;
            border-radius: 0.75rem; background: #7c3aed; color: #fff; font-size: 0.82rem; font-weight: 700; cursor: pointer;
        }
        .ps-remove-btn {
            display: inline-flex; align-items: center; gap: 0.35rem; min-height: 2.4rem; padding: 0 0.9rem;
            border-radius: 0.75rem; background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0;
            font-size: 0.82rem; font-weight: 700; cursor: pointer;
        }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; margin-bottom: 0; }
        .inline-field-form { display: flex; gap: 12px; align-items: center; max-width: 640px; }
        .inline-field-form .form-input { flex: 1; min-width: 0; }
        .host-port-grid { display: grid; grid-template-columns: 120px 1fr; gap: 12px; }
        .btn-save {
            background: #7c3aed; color: white; padding: 12px 28px; border-radius: 0.75rem;
            font-weight: 600; font-size: 14px; border: none; cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22); transition: all 0.2s;
        }
        .btn-save:hover { background: #6d28d9; }
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
        .profile-photo-row { display: flex; }
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

        .acct-aside { display: flex; flex-direction: column; gap: 1rem; }
        .acct-aside-card {
            background: #f5f3ff; border-color: #ede9fe; text-align: left;
        }
        .acct-aside-card h3 {
            font-size: 0.92rem; font-weight: 800; color: #5b21b6; margin: 0 0 0.4rem;
            display: flex; align-items: center; gap: 0.45rem;
        }
        .acct-aside-card p { font-size: 0.78rem; color: #6b7280; line-height: 1.5; margin: 0; }
        .ps-status-card h3 {
            font-size: 0.95rem; font-weight: 800; color: #0f172a; margin: 0 0 0.85rem;
            display: flex; align-items: center; gap: 0.45rem;
        }
        .ps-status-row {
            display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
            padding: 0.45rem 0; font-size: 0.82rem; color: #64748b;
        }
        .ps-status-row span { display: inline-flex; align-items: center; gap: 0.4rem; color: #0f172a; font-weight: 600; }
        .ps-status-row .profile-badge { background: #f3e8ff; color: #7c3aed; }
        .ps-status-row .profile-badge-success { background: #dcfce7; color: #166534; }

        /* ---- Profile Settings mock ---- */
        body.ps-page, body.ps-page.dashboard, body.ps-page .layout-main-wrapper,
        body.ps-page .layout-main-wrapper > .flex-grow-1, body.ps-page .employee-header { background: #f8fafc !important; }
        body.ps-page #native-sidebar .mb-auto { margin-bottom: 0 !important; }
        body.ps-page .employee-header { padding-top: 1rem !important; }
        @media (min-width: 993px) {
            body.ps-page .employee-header { position: relative !important; }
            body.ps-page .employee-header .header-right { position: absolute !important; top: 1.1rem; right: 1.25rem; }
        }
        body.ps-page #native-sidebar .nav-link.active {
            background: #efedfc !important; color: #4338ca !important; border-radius: 10px; font-weight: 600;
        }
        body.ps-page #native-sidebar .nav-link.active i { color: #4338ca !important; }
        body.ps-page .employee-header-page-title { font-size: 26px !important; font-weight: 700 !important; color: #111827 !important; }
        .ps-crumbs { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; }
        .ps-crumbs a { color: #5b54c9; text-decoration: none; }
        .ps-crumbs a:hover { text-decoration: underline; }
        .ps-crumb-sep { color: #9ca3af; }
        .ps-crumb-current { color: #4b5563; }
        .ps-head-tools { display: flex; align-items: center; gap: 14px; }
        .ps-head-search {
            display: flex; align-items: center; gap: 8px; width: 172px; height: 40px; margin: 0;
            padding: 0 12px; background: #fff; border: 1px solid #e6e8f0; border-radius: 10px; color: #6b7280;
        }
        .ps-head-search i { font-size: 13px; }
        .ps-head-search input { border: 0; outline: 0; background: transparent; width: 100%; font-size: 12px; color: #111827; }
        .ps-head-search input::placeholder { color: #6b7280; }
        .ps-head-bell {
            position: relative; width: 36px; height: 36px; border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center; color: #374151 !important; text-decoration: none !important; font-size: 17px;
        }
        .ps-head-dot { position: absolute; top: 6px; right: 7px; width: 7px; height: 7px; border-radius: 50%; background: #ef4444; border: 1.5px solid #f8fafc; }
        .ps-head-avatar {
            width: 38px; height: 38px; border-radius: 50%; background: #ebe8fb; color: #3730a3;
            display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700;
        }

        .editor-shell { max-width: none; }
        .ps-hero {
            min-height: 124px; padding: 1.1rem 1.4rem 1.1rem 1.9rem; margin: 0.9rem 0 0;
            border-radius: 12px 12px 0 0;
            background:
                radial-gradient(ellipse 45% 140% at 78% 10%, rgba(129, 120, 214, 0.38), transparent 60%),
                radial-gradient(ellipse 40% 120% at 100% 100%, rgba(99, 88, 196, 0.45), transparent 60%),
                linear-gradient(100deg, #2b2462 0%, #352d78 45%, #3f3690 100%);
        }
        .ps-hero::after {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 200' preserveAspectRatio='none'%3E%3Cpath d='M330 200 C420 120 520 40 640 60 C720 72 770 40 800 20 L800 200 Z' fill='rgba(255,255,255,0.05)'/%3E%3Cpath d='M260 200 C380 150 470 70 600 90 C700 105 760 70 800 55' fill='none' stroke='rgba(255,255,255,0.10)' stroke-width='2'/%3E%3C/svg%3E") right bottom / 100% 100% no-repeat;
        }
        .ps-hero-main { gap: 1.6rem; }
        .account-avatar-wrap, .account-avatar { width: 104px; height: 104px; }
        .account-avatar { border: 4px solid #fff; box-shadow: 0 10px 26px rgba(15, 23, 42, 0.28); }
        .account-avatar-btn {
            bottom: 2px; right: 0; width: 30px; height: 30px; background: #fff; color: #5b45d6;
            border: 0; font-size: 12px; box-shadow: 0 3px 10px rgba(15, 23, 42, 0.25);
        }
        .ps-hero-copy h2 { font-size: 1.55rem; font-weight: 700; letter-spacing: -0.01em; }
        .ps-hero-chips { gap: 0.55rem; margin: 0.3rem 0 0.55rem; }
        .ps-hero-handle { color: rgba(255,255,255,0.9); font-size: 0.9rem; font-weight: 500; margin-right: 0.2rem; }
        .ps-hero .profile-badge { padding: 3px 13px; font-size: 11px; font-weight: 600; background: #5b45d6; color: #fff; }
        .ps-hero .profile-badge-success { background: rgba(34, 197, 94, 0.08); color: #86efac; border: 1px solid #4ade80; }
        .ps-hero-email { font-size: 0.82rem; color: rgba(255,255,255,0.82); margin-bottom: 0.6rem; }
        .ps-hero-meta { font-size: 0.74rem; gap: 1.1rem; }
        .ps-logout {
            align-self: flex-start; margin-top: 0.4rem; min-height: 2.35rem; padding: 0 1.1rem; border-radius: 9px;
            background: transparent; border: 1px solid rgba(255,255,255,0.55); font-weight: 600; font-size: 0.8rem;
        }
        .ps-logout:hover { background: rgba(255,255,255,0.1); }

        .section-nav {
            margin: 0 0 1rem; padding: 0 0 0 1rem; background: #fff;
            border: 1px solid #eceef5; border-top: 0; border-radius: 0 0 12px 12px;
        }
        .section-nav ul { gap: 0 1.6rem; }
        .section-nav a { padding: 0.95rem 0.6rem 0.85rem; font-size: 0.9rem; font-weight: 500; color: #374151; }
        .section-nav a i { font-size: 1rem; color: #4b5563; }
        .section-nav a.is-active { color: #5b45d6; border-bottom: 2px solid #5b45d6; font-weight: 600; }
        .section-nav a.is-active i { color: #5b45d6; }

        .ps-body { grid-template-columns: minmax(0, 1fr) 265px; gap: 0.75rem; }
        .editor-main { gap: 0.75rem; }
        .editor-section, .acct-aside-card, .ps-status-card {
            border-radius: 10px; border-color: #eceef5; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03); padding: 0.95rem 0.75rem 1rem;
        }
        .editor-section[hidden] { display: none !important; }

        .editor-section.ps-sig-wrap {
            background: transparent; border: 0; box-shadow: none; padding: 0;
            display: flex; flex-direction: column; gap: 0.75rem;
        }
        .ps-sig-card {
            background: #fff; border: 1px solid #eceef5; border-radius: 10px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03); padding: 0.95rem 0.95rem 1rem;
        }
        .ps-sig-card[hidden] { display: none !important; }

        .swal2-popup.ps-del-popup { border-radius: 14px; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18); }
        .swal2-html-container.ps-del-body { margin: 0; padding: 0; overflow: visible; }
        .ps-del-icon { width: 44px; height: 44px; margin: 0 auto 0.55rem; color: #dc2626; }
        .ps-del-icon svg { width: 100%; height: 100%; }
        .ps-del-title { margin: 0 0 0.45rem; font-size: 1.3rem; font-weight: 700; color: #111827; }
        .ps-del-text { margin: 0; font-size: 0.88rem; color: #111827; }
        .swal2-actions.ps-del-actions { margin: 1.15rem 0 0; gap: 0.75rem; width: 100%; flex-wrap: nowrap; }
        .swal2-popup .swal2-actions button.ps-del-btn { flex: 1 1 0; min-height: 2.6rem; padding: 0 1rem; border: 0 !important; border-radius: 8px !important; font-size: 0.85rem; font-weight: 600; cursor: pointer; margin: 0; }
        .swal2-popup .swal2-actions button.ps-del-btn--no { background: #e5e7eb !important; color: #111827 !important; }
        .swal2-popup .swal2-actions button.ps-del-btn--no:hover { background: #d9dce1 !important; }
        .swal2-popup .swal2-actions button.ps-del-btn--yes { background: #ef2b2b !important; color: #fff !important; box-shadow: 0 6px 16px rgba(239, 43, 43, 0.45); }
        .swal2-popup .swal2-actions button.ps-del-btn--yes:hover { background: #dc2626 !important; }
        .swal2-popup .swal2-actions button.ps-del-btn:focus,
        .swal2-popup .swal2-actions button.ps-del-btn:focus-visible { outline: none !important; box-shadow: none; }
        .swal2-popup .swal2-actions button.ps-del-btn--yes:focus { box-shadow: 0 6px 16px rgba(239, 43, 43, 0.45); }
        html[data-theme="dark"] .swal2-popup.ps-del-popup { background: #1e293b; }
        html[data-theme="dark"] .ps-del-title, html[data-theme="dark"] .ps-del-text { color: #f8fafc; }
        html[data-theme="dark"] .swal2-popup .swal2-actions button.ps-del-btn--no { background: #334155 !important; color: #f8fafc !important; }
        .ps-sig-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 0.9rem; }
        .ps-sig-status {
            display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.35rem 0.8rem;
            border-radius: 999px; font-size: 0.72rem; font-weight: 500;
        }
        .ps-sig-status-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .ps-sig-status.is-active { background: #dcfce7; color: #15803d; }
        .ps-sig-status.is-missing { background: #fef9c3; color: #a16207; }
        .ps-sig-wrap .sig-tools { gap: 1.1rem; margin-bottom: 0.75rem; }
        .ps-sig-wrap .sig-tool-group { gap: 0.6rem; }
        .ps-sig-wrap .sig-tool-label { font-size: 0.68rem; font-weight: 600; color: #6b7280; letter-spacing: 0.04em; margin-right: 0.35rem; }
        .sig-tool-divider { width: 1px; height: 24px; background: #e5e7eb; }
        .ps-sig-wrap .pen-size-btn, .ps-sig-wrap .color-btn { width: 32px; height: 32px; border-color: #e5e7eb; border-radius: 7px; }
        .ps-sig-wrap .pen-size-btn.active, .ps-sig-wrap .color-btn.active { border-color: #a78bfa; background: #f1effd; color: #5b45d6; }
        .ps-sig-wrap .pen-size-btn { color: #111827; }
        .ps-sig-wrap .canvas-container { border: 1.5px dashed #c9c3ee; border-radius: 10px; }
        .ps-sig-wrap #sigCanvas { height: 125px; }
        .ps-sig-wrap .canvas-placeholder {
            display: flex; flex-direction: column; align-items: center; gap: 0.4rem;
            font-size: 0.82rem; color: #9ca3af; opacity: 1;
        }
        .ps-sig-wrap .canvas-placeholder i { font-size: 1rem; }
        .ps-sig-wrap .canvas-container.has-content .canvas-placeholder { opacity: 0; }
        .ps-sig-wrap .canvas-container.is-highlight { border-color: #5b45d6; box-shadow: 0 0 0 4px rgba(91, 69, 214, 0.12); }
        .ps-sig-wrap .sig-actions { display: flex; align-items: center; gap: 0.6rem; margin-top: 0.75rem; }
        .ps-sig-btn {
            display: inline-flex; align-items: center; gap: 0.45rem; min-height: 2.1rem; padding: 0 0.95rem;
            border: 1px solid #e5e7eb; border-radius: 7px; background: #fff; color: #374151;
            font-size: 0.78rem; font-weight: 500; cursor: pointer;
        }
        .ps-sig-btn:hover:not(:disabled) { background: #f9fafb; }
        .ps-sig-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .ps-sig-btn--danger { border-color: #fca5a5; color: #dc2626; }
        .ps-sig-btn--danger:hover:not(:disabled) { background: #fef2f2; }
        .ps-sig-btn--accent { border-color: #c4b5fd; color: #5b45d6; }
        .ps-sig-btn--accent:hover { background: #f5f3ff; }
        .ps-sig-btn.ps-sig-btn--plain,
        html[data-theme="dark"] .ps-sig-btn.ps-sig-btn--plain { border: 0; background: transparent; padding: 0 0.35rem; }
        .ps-sig-btn.ps-sig-btn--plain:hover:not(:disabled) { background: transparent; text-decoration: underline; }
        html[data-theme="dark"] .ps-sig-btn--accent.ps-sig-btn--plain { color: #a78bfa; }
        html[data-theme="dark"] .ps-sig-btn--danger.ps-sig-btn--plain { color: #f87171; }
        .ps-sig-save {
            margin-left: auto; display: inline-flex; align-items: center; gap: 0.5rem; min-height: 2.25rem; padding: 0 1.1rem;
            border: 0; border-radius: 7px; background: #5b45d6; color: #fff; font-size: 0.8rem; font-weight: 500; cursor: pointer;
        }
        .ps-sig-save:hover:not(:disabled) { background: #4c38c4; }
        .ps-sig-save:disabled { opacity: 0.6; cursor: not-allowed; }
        .ps-sig-current-head { margin-bottom: 0.8rem; }
        .ps-sig-current { display: flex; align-items: center; gap: 1rem; }
        .ps-sig-current-preview {
            flex: 1; min-width: 0; height: 64px; border: 1.5px dashed #e5e7eb; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; padding: 0.35rem;
        }
        .ps-sig-current-preview img { max-height: 100%; max-width: 100%; object-fit: contain; }
        .ps-sig-current-empty { font-size: 0.76rem; color: #9ca3af; }
        .ps-sig-current-actions { display: flex; gap: 0.6rem; flex-shrink: 0; }
        @media (max-width: 640px) {
            .sig-tool-divider { display: none; }
            .ps-sig-current { flex-direction: column; align-items: stretch; }
            .ps-sig-current-actions { justify-content: flex-end; }
        }
        html[data-theme="dark"] .ps-sig-card { background: #1e293b; border-color: #334155; }
        html[data-theme="dark"] .ps-sig-btn, html[data-theme="dark"] .ps-sig-wrap .pen-size-btn,
        html[data-theme="dark"] .ps-sig-wrap .color-btn { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        html[data-theme="dark"] .ps-sig-current-preview { border-color: #334155; background: #fff; }
        .ps-sec-head { display: flex; align-items: flex-start; gap: 0.75rem; }
        .ps-sec-icon {
            width: 32px; height: 32px; border-radius: 8px; background: #f1f0fb; color: #4b5563;
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.9rem;
        }
        .section-header.ps-sec-head { margin-bottom: 1rem; }
        .section-title { font-size: 0.95rem; font-weight: 600; color: #111827; margin-bottom: 2px; }
        .section-subtitle { font-size: 0.74rem; color: #6b7280; }
        .form-label { font-weight: 500; color: #374151; }
        .form-input { border-color: #e2e5ee; border-radius: 6px; padding: 9px 12px; font-size: 13px; }
        .ps-icon-field i { color: #6b7280; }

        .ps-photo-actions { gap: 0.95rem; padding-left: 1.6rem; }
        .ps-photo-preview-wrap { position: relative; margin: 0; }
        .ps-photo-preview { width: 44px; height: 44px; }
        .ps-photo-cam {
            position: absolute; right: -5px; bottom: -3px; width: 18px; height: 18px; border-radius: 50%;
            background: #5b45d6; color: #fff; border: 2px solid #fff; display: flex; align-items: center;
            justify-content: center; font-size: 8px; cursor: pointer; margin: 0;
        }
        .ps-upload-btn { min-height: 2.15rem; padding: 0 1.05rem; border-radius: 4px; background: #5b45d6; font-size: 0.74rem; font-weight: 500; margin: 0 0 0 1.4rem; }
        .ps-upload-btn:hover { background: #4c38c4; }
        .ps-remove-btn { min-height: 2.15rem; padding: 0 0.9rem; border-radius: 4px; background: #f3f4f6; color: #374151; border-color: #e5e7eb; font-size: 0.74rem; font-weight: 500; }

        #personal-details .inline-field-form .btn-save-inline { display: none; }
        #personal-details .inline-field-form.is-dirty .btn-save-inline { display: inline-flex; }
        #personal-details .inline-field-form { max-width: none; }

        .acct-aside-card {
            display: flex; gap: 0.85rem; align-items: flex-start; padding: 1rem;
            background: linear-gradient(120deg, #ecebfb 0%, #f4f3fd 100%); border-color: #e7e5f8;
        }
        .acct-aside-icon {
            width: 36px; height: 36px; border-radius: 9px; background: #dcd8f8; color: #5b45d6; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center; font-size: 0.95rem; margin-top: 0.15rem;
        }
        .acct-aside-card h3 { font-size: 0.8rem; font-weight: 600; color: #111827; margin: 0 0 0.3rem; }
        .acct-aside-card p { font-size: 0.72rem; color: #6b7280; }
        .ps-status-card { padding: 1rem; }
        .ps-status-card h3 { font-size: 0.85rem; font-weight: 600; color: #111827; margin-bottom: 0.8rem; }
        .ps-status-card h3 i { color: #5b45d6; font-size: 0.8rem; }
        .ps-status-row {
            display: grid; grid-template-columns: 92px 1fr; justify-content: start; align-items: center; white-space: nowrap;
            padding: 0.32rem 0; font-size: 0.74rem; color: #374151;
        }
        .ps-status-row .ps-status-label { color: #374151; font-weight: 400; }
        .ps-status-row > span:last-child { justify-self: start; }
        .ps-status-value { display: inline-flex; align-items: center; gap: 0.45rem; color: #111827; font-weight: 400 !important; }
        .ps-status-value i { color: #6b7280; }
        .ps-status-row .profile-badge { background: #ece9fb; color: #5b45d6; font-weight: 500; padding: 2px 9px; }
        .ps-status-row .profile-badge-success { background: #dcfce7; color: #15803d; }

        html[data-theme="dark"] body.ps-page, html[data-theme="dark"] body.ps-page .layout-main-wrapper,
        html[data-theme="dark"] body.ps-page .layout-main-wrapper > .flex-grow-1,
        html[data-theme="dark"] body.ps-page .employee-header { background: #0f172a !important; }
        html[data-theme="dark"] .section-nav { background: #1e293b; border-color: #334155; }
        html[data-theme="dark"] .section-nav a, html[data-theme="dark"] .section-nav a i { color: #cbd5e1; }
        html[data-theme="dark"] .section-nav a.is-active, html[data-theme="dark"] .section-nav a.is-active i { color: #a78bfa; border-bottom-color: #a78bfa; }
        html[data-theme="dark"] .ps-sec-icon { background: #334155; color: #e2e8f0; }
        html[data-theme="dark"] .acct-aside-card h3, html[data-theme="dark"] .ps-status-value { color: #f8fafc; }
        html[data-theme="dark"] .ps-status-row, html[data-theme="dark"] .ps-status-row .ps-status-label { color: #cbd5e1; }
        html[data-theme="dark"] .ps-head-search, html[data-theme="dark"] .ps-head-avatar { background: #1e293b; border-color: #334155; color: #e2e8f0; }
        html[data-theme="dark"] .ps-head-bell { color: #e2e8f0 !important; }
        html[data-theme="dark"] body.ps-page .employee-header-page-title { color: #f8fafc !important; }

        @media (max-width: 992px) {
            .ps-head-search { display: none; }
            .section-nav ul { gap: 0 0.4rem; }
            .ps-upload-btn { margin-left: 0; }
            .ps-photo-actions { padding-left: 0; }
        }

        html[data-theme="dark"] body { background: #0f172a; }
        html[data-theme="dark"] .editor-section,
        html[data-theme="dark"] .ps-status-card { background: #1e293b; border-color: #334155; }
        html[data-theme="dark"] .acct-aside-card { background: #2e1064; border-color: #4c1d95; }
        html[data-theme="dark"] .section-title, html[data-theme="dark"] .ps-status-card h3 { color: #f8fafc; }
        html[data-theme="dark"] .form-input { background: #0f172a; border-color: #334155; color: #e2e8f0; }

        @media (max-width: 992px) {
            .main-content-wrapper { padding: 0 0.85rem 2.5rem !important; }
            .ps-body { grid-template-columns: 1fr; }
            .ps-hero { flex-direction: column; align-items: flex-start; }
            .ps-details-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 18px; }
            .form-label { padding-top: 0; font-size: 13px; }
            .inline-field-form { flex-direction: column; align-items: stretch; }
            .host-port-grid { grid-template-columns: 1fr; }
            .btn-save { width: 100%; padding: 12px 24px; }
        }
	</style>
</head>
<body class="dashboard ps-page">
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
            <section class="ps-hero">
                <div class="ps-hero-main">
                    <div class="account-avatar-wrap">
                        <div class="account-avatar">
                            <?php if ($profilePhotoUrl !== ''): ?>
                                <img src="<?= $esc($profilePhotoUrl) ?>" alt="Profile photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <span class="account-avatar-fallback" style="display:none;"><?= $esc($initial) ?></span>
                            <?php else: ?>
                                <span class="account-avatar-fallback"><?= $esc($initial) ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="post" enctype="multipart/form-data">
                            <label class="account-avatar-btn" title="Upload photo">
                                <i class="fas fa-camera"></i>
                                <input type="file" name="profile_photo" accept="image/*" hidden onchange="this.form.submit()">
                            </label>
                        </form>
                    </div>
                    <div class="ps-hero-copy">
                        <h2><?= $esc($user['full_name'] ?? '') ?></h2>
                        <div class="ps-hero-chips">
                            <span class="ps-hero-handle">@<?= $esc($user['username'] ?? '') ?></span>
                            <span class="profile-badge"><?= $esc($roleLabel) ?></span>
                            <span class="profile-badge profile-badge-success"><?= $isActiveUser ? 'Active' : 'Inactive' ?></span>
                        </div>
                        <p class="ps-hero-email"><?= $esc($user['email'] ?? '') ?></p>
                        <div class="ps-hero-meta">
                            <span><i class="far fa-calendar"></i> Joined <?= $esc($joinedLabel) ?></span>
                            <span><i class="fas fa-pen"></i> <?= $currentSig ? 'Signature on file' : 'Signature missing' ?></span>
                        </div>
                    </div>
                </div>
                <a href="<?= $esc($logoutUrl) ?>" class="ps-logout"><i class="fas fa-arrow-right-from-bracket"></i> Log out</a>
            </section>

            <nav class="section-nav" aria-label="Profile sections">
                <ul>
                    <li><a href="#profile-photo" class="is-active"><i class="far fa-user"></i> Profile</a></li>
                    <li><a href="#personal-details"><i class="fas fa-table-cells-large"></i> Details</a></li>
                    <li><a href="#change-password"><i class="fas fa-shield-halved"></i> Security</a></li>
                    <li><a href="#digital-signature"><i class="fas fa-pen"></i> Signature</a></li>
                    <li><a href="#personal-email"><i class="far fa-envelope"></i> Email</a></li>
                </ul>
            </nav>

            <div class="ps-body">
                    <div class="editor-main">
		            <?php require __DIR__ . '/includes/account-settings-view.inc.php'; ?>
			<!-- Digital Signature -->
			<section class="editor-section ps-sig-wrap" id="digital-signature">
                <div class="ps-sig-card" id="psSigDrawCard"<?= $currentSig ? ' hidden' : '' ?>>
                    <div class="ps-sig-head">
                        <div class="ps-sec-head">
                            <span class="ps-sec-icon"><i class="fas fa-pen"></i></span>
                            <div>
                                <h2 class="section-title"><?= $currentSig ? 'Replace Signature' : 'Digital Signature' ?></h2>
                                <p class="section-subtitle"><?= $currentSig
                                    ? 'Draw a new signature. Saving it replaces your current one.'
                                    : 'Draw your signature below. It will appear on vouchers and approvals where your name is used.' ?></p>
                            </div>
                        </div>
                        <?php if ($currentSig): ?>
                            <span class="ps-sig-status is-active"><span class="ps-sig-status-dot"></span>Signature Active</span>
                        <?php else: ?>
                            <span class="ps-sig-status is-missing"><span class="ps-sig-status-dot"></span>Signature Missing</span>
                        <?php endif; ?>
                    </div>
                    <div class="sig-tools">
                        <div class="sig-tool-group">
                            <span class="sig-tool-label">Pen size</span>
                            <button type="button" class="pen-size-btn active" data-size="2" onclick="setPenSize(2)" title="Thin"><div class="dot"></div></button>
                            <button type="button" class="pen-size-btn" data-size="4" onclick="setPenSize(4)" title="Medium"><div class="dot"></div></button>
                            <button type="button" class="pen-size-btn" data-size="6" onclick="setPenSize(6)" title="Thick"><div class="dot"></div></button>
                        </div>
                        <span class="sig-tool-divider" aria-hidden="true"></span>
                        <div class="sig-tool-group">
                            <span class="sig-tool-label">Ink color</span>
                            <button type="button" class="color-btn active" data-color="#000000" onclick="setPenColor('#000000')" title="Black"><div class="color-circle" style="background:#000;"></div></button>
                            <button type="button" class="color-btn" data-color="#1e40af" onclick="setPenColor('#1e40af')" title="Blue"><div class="color-circle" style="background:#1e40af;"></div></button>
                            <button type="button" class="color-btn" data-color="#7c3aed" onclick="setPenColor('#7c3aed')" title="Purple"><div class="color-circle" style="background:#7c3aed;"></div></button>
                        </div>
                    </div>
                    <div class="canvas-container" id="canvasContainer">
                        <canvas id="sigCanvas" height="125"></canvas>
                        <div class="canvas-placeholder"><i class="fas fa-pencil"></i><span>Sign here</span></div>
                    </div>
                    <div class="sig-actions">
                        <button type="button" class="ps-sig-btn" onclick="undoStroke()" id="undoBtn" disabled><i class="fas fa-rotate-left"></i> Undo</button>
                        <button type="button" class="ps-sig-btn" onclick="redoStroke()" id="redoBtn" disabled><i class="fas fa-rotate-right"></i> Redo</button>
                        <button type="button" class="ps-sig-btn ps-sig-btn--danger" onclick="clearCanvas()"><i class="fas fa-trash"></i> Clear</button>
                        <?php if ($currentSig): ?>
                            <button type="button" class="ps-sig-btn" onclick="psCancelReplaceSignature()">Cancel</button>
                        <?php endif; ?>
                        <button type="button" class="ps-sig-save" onclick="saveSignature()" id="saveBtn" disabled><i class="fas fa-floppy-disk"></i> Save signature</button>
                    </div>
                </div>

                <?php if ($currentSig): ?>
                <div class="ps-sig-card">
                    <div class="ps-sec-head ps-sig-current-head">
                        <span class="ps-sec-icon"><i class="far fa-image"></i></span>
                        <div>
                            <h2 class="section-title">Current Signature</h2>
                            <p class="section-subtitle">This is your currently saved signature.</p>
                        </div>
                    </div>
                    <div class="ps-sig-current">
                        <div class="ps-sig-current-preview">
                            <img src="<?= $esc(function_exists('mediaUrlFromPath') ? mediaUrlFromPath($currentSig, false) : ('../' . ltrim((string) $currentSig, '/'))) ?>" alt="Current signature">
                        </div>
                            <div class="ps-sig-current-actions">
                                <button type="button" class="ps-sig-btn ps-sig-btn--accent ps-sig-btn--plain" onclick="psReplaceSignature()"><i class="fas fa-rotate"></i> Replace</button>
                                <form method="post" id="psDeleteSigForm" style="margin:0;">
                                    <input type="hidden" name="delete_signature" value="1">
                                    <button type="button" class="ps-sig-btn ps-sig-btn--danger ps-sig-btn--plain" onclick="psConfirmDeleteSignature()"><i class="fas fa-trash"></i> Remove</button>
                                </form>
                            </div>
                    </div>
                </div>
                <?php endif; ?>
			</section>

            <!-- Personal Email Settings -->
			<section class="editor-section" id="personal-email">
                <div class="section-header ps-sec-head">
                    <span class="ps-sec-icon"><i class="far fa-envelope"></i></span>
                    <div>
                        <h2 class="section-title">Personal Email Account</h2>
                        <p class="section-subtitle">Configure your personal IMAP/SMTP credentials for the email module.</p>
                    </div>
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
                    <div class="editor-footer-actions">
                        <button type="button" class="btn-save" style="background: #f1f5f9; color: #475569; box-shadow: none; border: 1px solid #cbd5e1;" id="testEmailBtn" onclick="testEmailConnection()">
                            <i class="fas fa-plug me-2"></i> Test Connection
                        </button>
                        <button type="submit" form="emailSettingsForm" class="btn-save" id="saveEmailBtn">
                            <i class="fas fa-save me-2"></i> Save Email Settings
                        </button>
                    </div>
                </form>
            </section>
                    </div>

                <aside class="acct-aside">
                    <div class="ps-status-card">
                        <h3><i class="fas fa-chart-line"></i> Account Status</h3>
                        <div class="ps-status-row"><span class="ps-status-label">Role</span><span class="profile-badge"><?= $esc($roleLabel) ?></span></div>
                        <div class="ps-status-row"><span class="ps-status-label">Status</span><span class="profile-badge profile-badge-success"><?= $isActiveUser ? 'Active' : 'Inactive' ?></span></div>
                        <div class="ps-status-row"><span class="ps-status-label">Joined</span><span class="ps-status-value"><i class="far fa-calendar"></i> <?= $esc($joinedLabel) ?></span></div>
                        <div class="ps-status-row"><span class="ps-status-label">Last login</span><span class="ps-status-value"><i class="far fa-clock"></i> <?= $esc($lastLoginLabel) ?></span></div>
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

function psReplaceSignature() {
	const card = document.getElementById('psSigDrawCard');
	if (card) card.hidden = false;
	resizeCanvas();
	container.scrollIntoView({ behavior: 'smooth', block: 'center' });
	container.classList.add('is-highlight');
	setTimeout(() => container.classList.remove('is-highlight'), 1600);
}

function psConfirmDeleteSignature() {
	const form = document.getElementById('psDeleteSigForm');
	if (!form) return;
	if (typeof Swal === 'undefined') {
		if (confirm('Delete your signature? This cannot be undone.')) form.submit();
		return;
	}
	Swal.fire({
		html:
			'<div class="ps-del-icon" aria-hidden="true">'
			+ '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			+ '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
			+ '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>'
			+ '<h2 class="ps-del-title">Delete Signature</h2>'
			+ '<p class="ps-del-text">You\'re going to delete your &ldquo;Signature&rdquo;</p>',
		showCancelButton: true,
		confirmButtonText: 'Yes, Delete!',
		cancelButtonText: 'No, keep it.',
		reverseButtons: true,
		focusCancel: true,
		buttonsStyling: false,
		width: 340,
		padding: '1.4rem 1.25rem 1.25rem',
		customClass: {
			popup: 'ps-del-popup',
			htmlContainer: 'ps-del-body',
			actions: 'ps-del-actions',
			confirmButton: 'ps-del-btn ps-del-btn--yes',
			cancelButton: 'ps-del-btn ps-del-btn--no'
		}
	}).then((result) => {
		if (result.isConfirmed) form.submit();
	});
}

function psCancelReplaceSignature() {
	strokes = [];
	redoStack = [];
	redrawCanvas();
	updateButtons();
	updatePlaceholder();
	const card = document.getElementById('psSigDrawCard');
	if (card) card.hidden = true;
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

var psShowTab = (function () {
    var navLinks = document.querySelectorAll('.section-nav a[href^="#"]');
    var sections = Array.prototype.slice.call(document.querySelectorAll('.editor-section[id]'));
    if (!navLinks.length || !sections.length) return function () {};
    var storageKey = 'psActiveTab';

    function show(id, updateHash) {
        if (!sections.some(function (s) { return s.id === id; })) id = sections[0].id;
        sections.forEach(function (section) {
            section.hidden = section.id !== id;
        });
        navLinks.forEach(function (link) {
            var on = link.getAttribute('href') === '#' + id;
            link.classList.toggle('is-active', on);
            link.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        try { sessionStorage.setItem(storageKey, id); } catch (e) {}
        if (updateHash && history.replaceState) history.replaceState(null, '', '#' + id);
        if (id === 'digital-signature' && typeof resizeCanvas === 'function') {
            requestAnimationFrame(resizeCanvas);
        }
    }

    navLinks.forEach(function (link) {
        link.setAttribute('role', 'tab');
        link.addEventListener('click', function (e) {
            e.preventDefault();
            show(link.getAttribute('href').slice(1), true);
        });
    });

    var initial = (location.hash || '').slice(1);
    if (!initial) {
        try { initial = sessionStorage.getItem(storageKey) || ''; } catch (e) { initial = ''; }
    }
    show(initial, false);
    if (location.hash) {
        window.addEventListener('load', function () { window.scrollTo(0, 0); });
    }
    window.addEventListener('hashchange', function () { show(location.hash.slice(1), false); });

    return show;
})();

(function () {
    document.querySelectorAll('#personal-details .inline-field-form').forEach(function (form) {
        var input = form.querySelector('input');
        if (!input) return;
        input.addEventListener('input', function () {
            form.classList.toggle('is-dirty', input.value !== input.defaultValue);
        });
    });

    var search = document.getElementById('psHeadSearch');
    if (!search) return;
    search.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var q = search.value.trim().toLowerCase();
        if (!q) return;
        var hit = Array.prototype.find.call(document.querySelectorAll('.editor-section[id]'), function (section) {
            return section.textContent.toLowerCase().indexOf(q) !== -1;
        });
        if (hit) psShowTab(hit.id, true);
    });
})();
</script>
</body>
</html>

