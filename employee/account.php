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
$psSyncCompanyIndex = static function () use ($userId): void {
                    $cid = (int) (currentCompanyId() ?? ($_SESSION['company_id'] ?? 0));
                    if ($cid > 0 && function_exists('syncUserCompanyIndex')) {
                        syncUserCompanyIndex($cid, $userId);
                    }
};
/** @return true|string true on success, otherwise an error message */
$psUpdateFullName = static function (string $newFullName) use ($pdo, $userId) {
    if (strlen($newFullName) < 2) {
        return 'Full Name must be at least 2 characters.';
    }
    $oldFullName = (string) ($_SESSION['full_name'] ?? '');
    $stmt = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?');
    if (!$stmt->execute([$newFullName, $userId])) {
        return 'Failed to update name.';
    }
                $_SESSION['full_name'] = $newFullName;
    // payment_vouchers stores these names as legacy text, not user ids
    foreach (['applicant', 'prepared_by', 'checked_by', 'department_manager', 'general_manager'] as $field) {
                    try {
                        $colCheck = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE '$field'");
                        if ($colCheck->fetch()) {
                            $upd = $pdo->prepare("UPDATE payment_vouchers SET $field = ? WHERE $field = ?");
                            $upd->execute([$newFullName, $oldFullName]);
                        }
                    } catch (Exception $e) { }
                }
    return true;
};
$psUpdateUsername = static function (string $newUsername) use ($pdo, $userId, $psSyncCompanyIndex) {
    if (strlen($newUsername) < 3) {
        return 'Username must be at least 3 characters.';
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $stmt->execute([$newUsername, $userId]);
            if ($stmt->fetch()) {
        return 'Username already taken.';
    }
    $stmt = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');
    if (!$stmt->execute([$newUsername, $userId])) {
        return 'Failed to update username.';
    }
                    $_SESSION['username'] = $newUsername;
    $psSyncCompanyIndex();
    return true;
};
$psUpdateEmail = static function (string $newEmail) use ($pdo, $userId, $psSyncCompanyIndex) {
    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $stmt->execute([$newEmail, $userId]);
    if ($stmt->fetch()) {
        return 'Email already in use.';
    }
    $stmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
    if (!$stmt->execute([$newEmail, $userId])) {
        return 'Failed to update email.';
    }
    $_SESSION['email'] = $newEmail;
    $psSyncCompanyIndex();
    return true;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['profile_photo'])) {
	if (isset($_POST['delete_signature'])) {
		try {
			deleteUserSignature($userId);
			$feedback = 'Signature deleted successfully.';
		} catch (Exception $e) {
			$feedback = 'Error: ' . $e->getMessage();
		}
	}
    elseif (isset($_POST['action']) && $_POST['action'] === 'save_personal_details') {
        $cur = $pdo->prepare('SELECT full_name, username, email, whatsapp_number FROM users WHERE id = ? LIMIT 1');
        $cur->execute([$userId]);
        $cur = $cur->fetch() ?: [];
        $errors = [];
        $changed = 0;

        $newFullName = trim((string) ($_POST['new_full_name'] ?? ''));
        if ($newFullName !== (string) ($cur['full_name'] ?? '')) {
            $r = $psUpdateFullName($newFullName);
            $r === true ? $changed++ : $errors[] = $r;
        }
        $newUsername = trim((string) ($_POST['new_username'] ?? ''));
        if ($newUsername !== (string) ($cur['username'] ?? '')) {
            $r = $psUpdateUsername($newUsername);
            $r === true ? $changed++ : $errors[] = $r;
        }
        $newEmail = function_exists('normalizeLoginEmail')
            ? normalizeLoginEmail($_POST['new_email'] ?? '')
            : strtolower(trim((string) ($_POST['new_email'] ?? '')));
        if ($newEmail !== strtolower((string) ($cur['email'] ?? ''))) {
            $r = $psUpdateEmail($newEmail);
            $r === true ? $changed++ : $errors[] = $r;
        }
        $waNumber = trim((string) ($_POST['whatsapp_number'] ?? ''));
        if ($waNumber !== (string) ($cur['whatsapp_number'] ?? '')) {
            $stmt = $pdo->prepare('UPDATE users SET whatsapp_number = ? WHERE id = ?');
            $stmt->execute([$waNumber, $userId]);
            $changed++;
        }

        if ($errors) {
            $feedback = 'Error: ' . implode(' ', $errors);
        } else {
            $feedback = $changed ? 'Personal details saved successfully.' : 'No changes to save.';
        }
    }
    // Change password while logged in (current password not required)
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $pwStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $pwStmt->execute([$userId]);
        if ((string) $pwStmt->fetchColumn() !== '') {
            $feedback = 'Error: You already have a password. Use "Forgot password" to reset it by email.';
        } elseif ($newPassword !== $confirmPassword) {
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
$pwStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$userId]);
$userHasPassword = (string) $pwStmt->fetchColumn() !== '';

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
$psAiAgentUrl = function_exists('company_url') ? company_url('modules/ai-agent/index.php') : '../modules/ai-agent/index.php';
$psPersonalizationUrl = 'personalization/index.php?module=personalization';
$psAiAgentApiUrl = function_exists('company_url') ? company_url('modules/ai-agent/api.php') : '../modules/ai-agent/api.php';
$psFontApiUrl = 'personalization/system-font-ui/api/index.php';
$psFontConfig = null;
$psFontLib = __DIR__ . '/personalization/system-font-ui/lib.php';
if (is_file($psFontLib)) {
    require_once $psFontLib;
    if (function_exists('systemFontUiBuildInitialConfig')) {
        try {
            $psFontConfig = systemFontUiBuildInitialConfig($userId);
        } catch (Throwable $e) {
            $psFontConfig = null;
        }
    }
}
$psCurrentTheme = (string) ($_SESSION['theme'] ?? 'theme-minimal');
if (isset($_GET['set_theme']) && is_string($_GET['set_theme']) && $_GET['set_theme'] !== '') {
    $psCurrentTheme = (string) $_GET['set_theme'];
}
$psSidebarThemes = [
    'theme-minimal' => ['Minimal', '#ffffff', '#334155', true],
    'default' => ['Trust Blue', '#0d6efd', '#ffffff', false],
    'theme-deep-ocean' => ['Deep Ocean', '#001f2f', '#ffffff', false],
    'theme-clean-white' => ['Clean White', '#f8f9fa', '#212529', true],
    'theme-night-mode' => ['Night Mode', '#212529', '#ffffff', false],
    'theme-royal-purple' => ['Royal Purple', 'linear-gradient(180deg, #4a148c, #311b92)', '#ffffff', false],
    'theme-professional' => ['Professional', '#1e40af', '#ffffff', false],
    'theme-dark-mode-v2' => ['Dark Mode V2', '#0f172a', '#ffffff', false],
    'theme-cool' => ['Cool', '#ecfeff', '#155e75', true],
];
$psThemeUrl = static function (string $theme): string {
    $params = $_GET;
    $params['set_theme'] = $theme;
    return '?' . http_build_query($params);
};
$employeeHeaderTitle = null;
$employeeHeaderSubtitle = null;
$employeeHeaderRightHtml = null;
$homeUrl = $profileBackUrl;
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$hideHeaderCompanyBranding = true;
$hideHeaderThemeAndNotifications = true;
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

$psBiometricApiUrl = function_exists('company_url')
    ? company_url('attendance/api/action.php')
    : (function_exists('app_url') ? app_url('/attendance/api/action.php') : '../attendance/api/action.php');
$psBiometricStatus = [
    'required' => false,
    'enrolled' => false,
    'count' => 0,
    'max' => 2,
    'devices' => [],
    'library_ok' => false,
    'secure_ok' => false,
    'rp_id' => '',
];
try {
    if (function_exists('ensureAttendanceClockModuleSchema')) {
        ensureAttendanceClockModuleSchema();
    }
    $waClass = __DIR__ . '/../attendance/classes/AttendanceWebAuthn.php';
    if (is_file($waClass)) {
        require_once $waClass;
    }
    if (class_exists('AttendanceWebAuthn') && isset($pdo) && $pdo instanceof PDO) {
        $psBiometricStatus = (new AttendanceWebAuthn($pdo))->statusForUser((int) $userId);
    }
} catch (Throwable $e) {
    // Profile still loads without biometric panel data.
}
$psBiometricJsUrl = function_exists('app_url')
    ? app_url('/assets/js/profile-biometric.js')
    : '../assets/js/profile-biometric.js';
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Settings - <?= $esc($user['full_name']) ?></title>
	<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
	<link rel="stylesheet" href="<?= $esc(function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css') ?>">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
	<?php if (function_exists('renderSystemFontHeadMarkup')) { renderSystemFontHeadMarkup(); } ?>
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
	<link rel="stylesheet" href="<?= $esc(app_url('/assets/css/profile-shell.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/profile-shell.css') ?>">
</head>
<body class="dashboard ps-page ps-page--profile">
<script>
(function () {
    try {
        if (window.matchMedia('(max-width: 768px)').matches) {
            document.body.setAttribute('data-ps-view', location.hash ? 'detail' : 'menu');
        } else {
            document.body.setAttribute('data-ps-view', 'desktop');
        }
    } catch (e) {}
})();
</script>
<?php
$hideSidebar = true;
$hideErpBreadcrumbs = true;
require_once __DIR__ . '/../includes/header_employee.php';
?>

<div class="main-content-wrapper">
		<?php if (!empty($feedback)): ?>
            <?php
            $psToastType = stripos($feedback, 'error') === 0 ? 'error' : (stripos($feedback, 'no changes') === 0 ? 'info' : 'success');
            $psToastText = $psToastType === 'error' ? trim(substr($feedback, 6)) : $feedback;
            ?>
            <div class="ps-toast ps-toast--<?= $psToastType ?>" id="psToast" role="status" aria-live="polite">
                <i class="fas <?= $psToastType === 'error' ? 'fa-circle-exclamation' : ($psToastType === 'info' ? 'fa-circle-info' : 'fa-circle-check') ?>" aria-hidden="true"></i>
                <span><?= $esc($psToastText) ?></span>
                <button type="button" class="ps-toast-close" aria-label="Dismiss" onclick="this.parentNode.remove()">&times;</button>
            </div>
            <script>
                (function () {
                    var t = document.getElementById('psToast');
                    if (!t) return;
                    requestAnimationFrame(function () { t.classList.add('is-in'); });
                    setTimeout(function () {
                        t.classList.remove('is-in');
                        setTimeout(function () { t.remove(); }, 250);
                    }, 3000);
                })();
            </script>
        <?php endif; ?>

        <div class="page-shell editor-shell">
            <div class="ps-settings-rail">
                <a href="<?= $esc($profileBackUrl) ?>" class="ps-rail-back" aria-label="Back" title="Back">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 6 9 12l6 6"/>
                    </svg>
                </a>
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
                            </div>
                            <p class="ps-hero-email"><?= $esc($user['email'] ?? '') ?></p>
                        </div>
                    </div>
                </section>

                <div class="ps-rail-search">
                    <label class="ps-head-search" for="psHeadSearch">
                        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" id="psHeadSearch" placeholder="Find a setting..." autocomplete="off" aria-label="Filter settings">
                    </label>
                </div>

                <?php
                $psIgIcon = static function (string $paths): string {
                    return '<i class="ps-nav-svg" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg></i>';
                };
                ?>
                <nav class="section-nav" aria-label="Settings">
                    <div class="ps-nav-scroll" id="psNavScroll">
                        <ul id="psNavList">
                            <li><a href="#profile-photo" class="is-active" data-ps-keywords="photo avatar image profile name username email whatsapp contact details account"><?= $psIgIcon('<circle cx="12" cy="8" r="3.25"/><path d="M5.5 19.25c.9-3.1 3.2-4.75 6.5-4.75s5.6 1.65 6.5 4.75"/>') ?><span class="ps-nav-text"><strong>Profile</strong></span></a></li>
                            <li class="ps-nav-sep" aria-hidden="true"></li>
                            <li><a href="#change-password" data-ps-keywords="password security biometric face id unlock phone smartphone fingerprint passkey"><?= $psIgIcon('<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8.5a4 4 0 0 1 8 0V11"/><circle cx="12" cy="15.5" r="1.15"/>') ?><span class="ps-nav-text"><strong>Security</strong></span></a></li>
                            <li><a href="#digital-signature" data-ps-keywords="signature vouchers approvals draw"><?= $psIgIcon('<path d="M4 19.5h7.5"/><path d="M14.2 4.8l4.5 4.5"/><path d="M6.5 17.2 16.7 7a2.1 2.1 0 0 1 3 3L9.5 20.2 5 21.5z"/>') ?><span class="ps-nav-text"><strong>Signature</strong></span></a></li>
                            <li><a href="#personal-email" data-ps-keywords="email imap smtp mailbox"><?= $psIgIcon('<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m5.5 8 6.5 5 6.5-5"/>') ?><span class="ps-nav-text"><strong>Email</strong></span></a></li>
                            <li class="ps-nav-sep" aria-hidden="true"></li>
                            <li><a href="#ps-pref-ai" data-ps-keywords="ai agent briefing insights"><?= $psIgIcon('<path d="M12 3.5 13.7 8.3 18.5 10 13.7 11.7 12 16.5 10.3 11.7 5.5 10 10.3 8.3z"/><path d="M18.2 14.2 19 16.5 21.3 17.3 19 18.1 18.2 20.4 17.4 18.1 15.1 17.3 17.4 16.5z"/>') ?><span class="ps-nav-text"><strong>AI Agent</strong></span></a></li>
                            <li><a href="#ps-pref-appearance" data-ps-keywords="appearance theme font dark light"><?= $psIgIcon('<circle cx="12" cy="12" r="8.25"/><path d="M12 3.75v16.5A8.25 8.25 0 0 0 12 3.75z"/>') ?><span class="ps-nav-text"><strong>Appearance</strong></span></a></li>
                            <li><a href="#ps-pref-accessibility" data-ps-keywords="accessibility reading contrast size"><?= $psIgIcon('<circle cx="12" cy="5.25" r="1.75"/><path d="M8 9.25h8"/><path d="M12 9.25v5.5"/><path d="m8.5 20.25 3.5-5.5 3.5 5.5"/>') ?><span class="ps-nav-text"><strong>Accessibility</strong></span></a></li>
                        </ul>
                    </div>
                    <button type="button" class="ps-nav-more" id="psNavMore" hidden aria-controls="psNavScroll">
                        <span>View more settings</span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                </nav>

                <div class="ps-rail-foot">
                    <a href="<?= $esc($logoutUrl) ?>" class="ps-logout"><i class="fas fa-arrow-right-from-bracket"></i> Log out</a>
                </div>
            </div>

            <div class="ps-mobile-back" id="psMobileBack" hidden>
                <button type="button" class="ps-mobile-back-btn" id="psMobileBackBtn" aria-label="Back to settings">
                    <i class="fas fa-chevron-left" aria-hidden="true"></i>
                </button>
                <span class="ps-mobile-back-title" id="psMobileBackTitle">Settings</span>
            </div>
                
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
                                <h2 class="section-title">Signature</h2>
                                <p class="section-subtitle"><?= $currentSig
                                    ? 'Draw a new signature to replace your current one.'
                                    : 'Draw your signature for vouchers and approvals.' ?></p>
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
                        <button type="button" class="ps-sig-btn ps-sig-btn--plain" onclick="undoStroke()" id="undoBtn" disabled><i class="fas fa-rotate-left"></i> Undo</button>
                        <button type="button" class="ps-sig-btn ps-sig-btn--plain" onclick="redoStroke()" id="redoBtn" disabled><i class="fas fa-rotate-right"></i> Redo</button>
                        <button type="button" class="ps-sig-btn ps-sig-btn--danger ps-sig-btn--plain" onclick="clearCanvas()"><i class="fas fa-trash"></i> Clear</button>
                        <?php if ($currentSig): ?>
                            <button type="button" class="ps-sig-btn" onclick="psCancelReplaceSignature()">Cancel</button>
                        <?php endif; ?>
                        <button type="button" class="ps-sig-save" onclick="saveSignature()" id="saveBtn" disabled><i class="fas fa-floppy-disk"></i> Save signature</button>
                        </div>
                    </div>

                        <?php if ($currentSig): ?>
                <div class="ps-sig-card">
                    <div class="ps-sec-head ps-sig-current-head">
                        <span class="ps-sec-icon"><i class="fas fa-file-signature"></i></span>
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
					<h2 class="section-title">Email</h2>
                    <p class="section-subtitle">Personal IMAP/SMTP credentials for the email module.</p>
				</div>
				</div>
                <?php $psEmailComingSoon = true; ?>
                <?php if ($psEmailComingSoon): ?>
                <div class="ps-coming-soon">
                    <span class="ps-coming-soon-icon"><i class="far fa-clock"></i></span>
                    <div>
                        <strong>Coming soon</strong>
                        <span>Connecting your personal mailbox will be available in a future update.</span>
                    </div>
                </div>
                <?php else: ?>
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
                <?php endif; ?>
            </section>

            <section class="editor-section ps-pref" id="ps-pref-ai" hidden>
                <div class="section-header ps-sec-head">
                    <span class="ps-sec-icon"><i class="fas fa-robot"></i></span>
                    <div>
                        <h2 class="section-title">AI Agent</h2>
                        <p class="section-subtitle">Smart alerts and insights for your account.</p>
                    </div>
                </div>
                <div class="ps-soon">
                    <span class="ps-soon-icon"><i class="fas fa-wand-magic-sparkles"></i></span>
                    <h3>Coming soon</h3>
                    <p>Your personal AI briefing on receivables, approvals, stock and purchasing will appear here.</p>
                </div>
            </section>

            <section class="editor-section ps-pref ps-appearance-stack" id="ps-pref-appearance" hidden>
                <?php
                $psFontCfg = is_array($psFontConfig ?? null) ? $psFontConfig : [];
                $psFontSelected = (string) ($psFontCfg['selectedKey'] ?? '');
                $psCompanyFont = is_array($psFontCfg['companyFont'] ?? null)
                    ? $psFontCfg['companyFont']
                    : ['label' => 'Company default', 'stack' => 'inherit'];
                $psEffectiveFont = is_array($psFontCfg['effectiveFont'] ?? null) ? $psFontCfg['effectiveFont'] : [];
                $psEffectiveStack = (string) ($psEffectiveFont['stack'] ?? ($psCompanyFont['stack'] ?? 'inherit'));
                $psEffectiveLabel = (string) ($psEffectiveFont['label'] ?? 'Company default');
                ?>

                <div class="ps-profile-card ps-profile-card--appearance ps-profile-card--mode">
                    <div class="section-header ps-sec-head">
                        <span class="ps-sec-icon"><i class="fas fa-circle-half-stroke"></i></span>
                        <div>
                            <h2 class="section-title">Mode</h2>
                            <p class="section-subtitle">Light, dark, or match your device.</p>
                        </div>
                    </div>
                    <div class="ps-mode-grid" id="psModeGrid">
                        <button type="button" class="ps-mode-card" data-mode="light">
                            <span class="ps-mode-phone ps-mode-preview--light" aria-hidden="true">
                                <span class="ps-mode-phone-notch"></span>
                                <span class="ps-mode-phone-screen">
                                    <span class="ps-mode-phone-bar"></span>
                                    <span class="ps-mode-phone-row"></span>
                                    <span class="ps-mode-phone-row ps-mode-phone-row--short"></span>
                                    <span class="ps-mode-phone-row"></span>
                                </span>
                            </span>
                            <span class="ps-mode-label">
                                <span class="ps-mode-radio" aria-hidden="true"></span>
                                Light
                            </span>
                        </button>
                        <button type="button" class="ps-mode-card" data-mode="dark">
                            <span class="ps-mode-phone ps-mode-preview--dark" aria-hidden="true">
                                <span class="ps-mode-phone-notch"></span>
                                <span class="ps-mode-phone-screen">
                                    <span class="ps-mode-phone-bar"></span>
                                    <span class="ps-mode-phone-row"></span>
                                    <span class="ps-mode-phone-row ps-mode-phone-row--short"></span>
                                    <span class="ps-mode-phone-row"></span>
                                </span>
                            </span>
                            <span class="ps-mode-label">
                                <span class="ps-mode-radio" aria-hidden="true"></span>
                                Dark
                            </span>
                        </button>
                        <button type="button" class="ps-mode-card" data-mode="system">
                            <span class="ps-mode-phone ps-mode-preview--system" aria-hidden="true">
                                <span class="ps-mode-phone-notch"></span>
                                <span class="ps-mode-phone-screen">
                                    <span class="ps-mode-phone-half ps-mode-phone-half--light">
                                        <span class="ps-mode-phone-bar"></span>
                                        <span class="ps-mode-phone-row"></span>
                                        <span class="ps-mode-phone-row ps-mode-phone-row--short"></span>
                                    </span>
                                    <span class="ps-mode-phone-half ps-mode-phone-half--dark">
                                        <span class="ps-mode-phone-bar"></span>
                                        <span class="ps-mode-phone-row"></span>
                                        <span class="ps-mode-phone-row ps-mode-phone-row--short"></span>
                                    </span>
                                </span>
                            </span>
                            <span class="ps-mode-label">
                                <span class="ps-mode-radio" aria-hidden="true"></span>
                                System
                            </span>
                        </button>
                    </div>
                    <p class="ps-pref-note ps-mode-hint">System follows your device light or dark setting.</p>
                </div>

                <div class="ps-profile-card ps-profile-card--appearance ps-profile-card--font">
                    <div class="section-header ps-sec-head">
                        <span class="ps-sec-icon"><i class="fas fa-font"></i></span>
                        <div>
                            <h2 class="section-title">Font</h2>
                            <p class="section-subtitle">Preview and choose your reading style.</p>
                        </div>
                    </div>
                    <?php if ($psFontConfig === null): ?>
                    <p class="ps-pref-empty">Font settings are not available right now.</p>
                    <?php else: ?>
                    <div class="ps-font-samsung" id="psFontSamsung">
                        <div class="ps-font-preview-card" id="psFontPreviewCard">
                            <p class="ps-font-preview-text" id="psFontPreview" style="font-family: <?= $esc($psEffectiveStack) ?>;">
                                While resolving a conflict with a colleague, remember to keep a positive attitude and focus on finding a solution that works for everyone.
                            </p>
                            <div class="ps-font-loading" id="psFontLoading" hidden aria-live="polite">
                                <span class="ps-font-loading-ring" aria-hidden="true"></span>
                                <span class="ps-font-loading-copy">
                                    <strong id="psFontLoadingTitle">Applying font</strong>
                                    <small id="psFontLoadingSub">Please wait…</small>
                                </span>
                            </div>
                        </div>
                        <div class="ps-font-panel">
                            <div class="ps-font-grid" id="psFontGrid" data-api="<?= $esc($psFontApiUrl) ?>" data-selected="<?= $esc($psFontSelected) ?>">
                                <button type="button" class="ps-font-card<?= $psFontSelected === '' ? ' is-selected' : '' ?>" data-font="" data-label="Company default" style="--ps-font: <?= $esc($psCompanyFont['stack'] ?? 'inherit') ?>;">
                                    <span class="ps-font-sample" title="<?= $esc($psCompanyFont['label'] ?? '') ?>">Company default</span>
                                    <i class="fas fa-check ps-font-check"></i>
                                </button>
                                <?php foreach (($psFontCfg['fonts'] ?? []) as $psFont): ?>
                                <button type="button" class="ps-font-card<?= $psFontSelected === (string) $psFont['id'] ? ' is-selected' : '' ?>" data-font="<?= $esc($psFont['id']) ?>" data-label="<?= $esc($psFont['label']) ?>" data-google="<?= $esc($psFont['google'] ?? '') ?>" data-local="<?= $esc($psFont['localCss'] ?? '') ?>" style="--ps-font: <?= $esc($psFont['stack'] ?? 'inherit') ?>;">
                                    <span class="ps-font-sample"><?= $esc($psFont['label']) ?></span>
                                    <i class="fas fa-check ps-font-check"></i>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <div class="ps-pref-actions ps-font-note-row">
                                <span class="ps-pref-note" id="psFontNote">Current: <?= $esc($psEffectiveLabel) ?><?= empty($psFontCfg['isPersonalChoice']) ? ' (company default)' : ' (your choice)' ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="ps-profile-card ps-profile-card--appearance ps-profile-card--theme">
                    <div class="section-header ps-sec-head">
                        <span class="ps-sec-icon"><i class="fas fa-palette"></i></span>
                        <div>
                            <h2 class="section-title">Sidebar theme</h2>
                            <p class="section-subtitle">Used in light mode across the app.</p>
                        </div>
                    </div>
                    <div class="ps-theme-grid">
                        <?php foreach ($psSidebarThemes as $psThemeKey => [$psThemeLabel, $psThemeBg, $psThemeFg, $psThemeBorder]): ?>
                        <a href="<?= $esc($psThemeUrl($psThemeKey)) ?>" class="ps-theme-card<?= $psCurrentTheme === $psThemeKey ? ' is-selected' : '' ?>" data-theme-key="<?= $esc($psThemeKey) ?>">
                            <span class="ps-theme-swatch<?= $psThemeBorder ? ' has-border' : '' ?>" style="background: <?= $esc($psThemeBg) ?>; color: <?= $esc($psThemeFg) ?>;"><i class="fas fa-bars"></i></span>
                            <span class="ps-theme-label"><?= $esc($psThemeLabel) ?></span>
                            <i class="fas fa-circle-check ps-theme-check"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <?php
            $psA11yCompany = function_exists('erp_get_accessibility_prefs')
                ? erp_get_accessibility_prefs()
                : (function_exists('erp_accessibility_defaults') ? erp_accessibility_defaults() : [
                    'textScale' => '100',
                    'highContrast' => false,
                    'reduceMotion' => false,
                    'underlineLinks' => false,
                    'largeTargets' => false,
                ]);
            $psA11yCompanyJson = json_encode($psA11yCompany, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (!is_string($psA11yCompanyJson)) {
                $psA11yCompanyJson = '{}';
            }
            ?>
            <section class="editor-section ps-pref" id="ps-pref-accessibility" hidden
                data-a11y-company="<?= $esc($psA11yCompanyJson) ?>">
                <div class="section-header ps-sec-head">
                    <span class="ps-sec-icon"><i class="fas fa-universal-access"></i></span>
                    <div>
                        <h2 class="section-title">Accessibility</h2>
                        <p class="section-subtitle">Reading and interaction preferences on this device.</p>
                    </div>
                </div>

                <p class="subsection-label">Text size</p>
                <div class="ps-a11y-slider" id="psA11yScale">
                    <p class="ps-a11y-slider-sample" id="psA11yPreview">The quick brown fox jumps over the lazy dog.</p>
                    <div class="ps-a11y-slider-row">
                        <span class="ps-a11y-slider-end ps-a11y-slider-end--sm" aria-hidden="true">A</span>
                        <input type="range" id="psA11yRange" class="ps-a11y-range" min="100" max="200" step="1" value="100" aria-label="Text size">
                        <span class="ps-a11y-slider-end ps-a11y-slider-end--lg" aria-hidden="true">A</span>
                    </div>
                </div>

                <p class="subsection-label" style="margin-top:1.15rem;">Display &amp; interaction</p>
                <div class="ps-a11y-toggles" id="psA11yToggles">
                    <label class="ps-a11y-toggle">
                        <span class="ps-a11y-toggle-copy">
                            <strong>High contrast</strong>
                            <small>Stronger borders and clearer text</small>
                        </span>
                        <span class="ps-switch">
                            <input type="checkbox" data-a11y-key="highContrast" aria-label="High contrast">
                            <span class="ps-switch-track" aria-hidden="true"><span class="ps-switch-thumb"></span></span>
                        </span>
                    </label>
                    <label class="ps-a11y-toggle">
                        <span class="ps-a11y-toggle-copy">
                            <strong>Reduce motion</strong>
                            <small>Turn off animations and transitions</small>
                        </span>
                        <span class="ps-switch">
                            <input type="checkbox" data-a11y-key="reduceMotion" aria-label="Reduce motion">
                            <span class="ps-switch-track" aria-hidden="true"><span class="ps-switch-thumb"></span></span>
                        </span>
                    </label>
                    <label class="ps-a11y-toggle">
                        <span class="ps-a11y-toggle-copy">
                            <strong>Underline links</strong>
                            <small>Mark links by more than colour alone</small>
                        </span>
                        <span class="ps-switch">
                            <input type="checkbox" data-a11y-key="underlineLinks" aria-label="Underline links">
                            <span class="ps-switch-track" aria-hidden="true"><span class="ps-switch-thumb"></span></span>
                        </span>
                    </label>
                    <label class="ps-a11y-toggle">
                        <span class="ps-a11y-toggle-copy">
                            <strong>Larger tap targets</strong>
                            <small>Bigger buttons and controls</small>
                        </span>
                        <span class="ps-switch">
                            <input type="checkbox" data-a11y-key="largeTargets" aria-label="Larger tap targets">
                            <span class="ps-switch-track" aria-hidden="true"><span class="ps-switch-thumb"></span></span>
                        </span>
                    </label>
                </div>

                <div class="ps-pref-actions" style="margin-top:1.1rem;">
                    <button type="button" class="ps-pref-btn ps-pref-btn--ghost" id="psA11yReset">Reset to company default</button>
                    <span class="ps-pref-note" id="psA11yNote">Changes apply instantly on this device.</span>
                </div>
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
	psConfirmPopup({
		title: 'Clear Signature',
		text: 'You\'re going to clear your &ldquo;Drawing&rdquo;',
		confirmText: 'Yes, Clear!',
		fallback: 'Clear your signature?'
	}, () => {
	ctx.clearRect(0, 0, canvas.width, canvas.height);
	strokes = [];
	redoStack = [];
	updateButtons();
	updatePlaceholder();
	});
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
	psConfirmPopup({
		title: 'Delete Signature',
		text: 'You\'re going to delete your &ldquo;Signature&rdquo;',
		confirmText: 'Yes, Delete!',
		fallback: 'Delete your signature? This cannot be undone.'
	}, () => form.submit());
}

function psConfirmPopup(opts, onConfirm) {
	if (typeof Swal === 'undefined') {
		if (confirm(opts.fallback)) onConfirm();
		return;
	}
	Swal.fire({
		html:
			'<div class="ps-del-icon" aria-hidden="true">'
			+ '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			+ '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
			+ '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>'
			+ '<h2 class="ps-del-title">' + opts.title + '</h2>'
			+ '<p class="ps-del-text">' + opts.text + '</p>',
		showCancelButton: true,
		confirmButtonText: opts.confirmText,
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
		if (result.isConfirmed) onConfirm();
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
    var viewKey = 'psMobileView';
    var backBar = document.getElementById('psMobileBack');
    var backBtn = document.getElementById('psMobileBackBtn');
    var backTitle = document.getElementById('psMobileBackTitle');

    function isMobileLayout() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    function titleFor(id) {
        var link = document.querySelector('.section-nav a[href="#' + id + '"]');
        if (link) {
            var strong = link.querySelector('.ps-nav-text strong');
            if (strong && strong.textContent) return strong.textContent.trim();
            return (link.textContent || id).replace(/\s+/g, ' ').trim();
        }
        return 'Settings';
    }

    function setMobileView(view) {
        var next = view === 'detail' ? 'detail' : 'menu';
        document.body.setAttribute('data-ps-view', next);
        if (backBar) backBar.hidden = !(isMobileLayout() && next === 'detail');
        try { sessionStorage.setItem(viewKey, next); } catch (e) {}
        if (isMobileLayout()) {
            window.scrollTo(0, 0);
        }
    }

    function show(id, updateHash, forceDetail) {
        if (id === 'personal-details') id = 'profile-photo';
        if (id === 'attendance-biometric') id = 'change-password';
        if (!sections.some(function (s) { return s.id === id; })) id = sections[0].id;
        sections.forEach(function (section) {
            section.hidden = section.id !== id;
        });
        navLinks.forEach(function (link) {
            var on = link.getAttribute('href') === '#' + id;
            link.classList.toggle('is-active', on);
            link.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.body.setAttribute('data-ps-tab', id);
        if (backTitle) backTitle.textContent = titleFor(id);
        document.dispatchEvent(new CustomEvent('ps:tab', { detail: { id: id } }));
        try { sessionStorage.setItem(storageKey, id); } catch (e) {}
        if (updateHash && history.replaceState) history.replaceState(null, '', '#' + id);
        if (isMobileLayout()) {
            if (forceDetail === false) setMobileView('menu');
            else setMobileView('detail');
        } else {
            document.body.setAttribute('data-ps-view', 'desktop');
            if (backBar) backBar.hidden = true;
        }
        if (id === 'digital-signature' && typeof resizeCanvas === 'function') {
            requestAnimationFrame(resizeCanvas);
        }
    }

    navLinks.forEach(function (link) {
        link.setAttribute('role', 'tab');
        link.addEventListener('click', function (e) {
            e.preventDefault();
            show(link.getAttribute('href').slice(1), true, true);
        });
    });

    if (backBtn) {
        backBtn.addEventListener('click', function () {
            setMobileView('menu');
            if (history.replaceState) history.replaceState(null, '', location.pathname + location.search);
            try { sessionStorage.setItem(viewKey, 'menu'); } catch (e) {}
        });
    }

    var initial = (location.hash || '').slice(1);
    if (!initial) {
        try { initial = sessionStorage.getItem(storageKey) || ''; } catch (e) { initial = ''; }
    }
    if (initial === 'ps-pref-personalization') initial = 'ps-pref-appearance';
    var startInDetail = !!location.hash;
    if (!startInDetail && isMobileLayout()) {
        try { startInDetail = sessionStorage.getItem(viewKey) === 'detail' && !!initial; } catch (e) {}
    }
    show(initial, false, startInDetail);
    if (!startInDetail && isMobileLayout()) setMobileView('menu');
    if (location.hash) {
        window.addEventListener('load', function () { window.scrollTo(0, 0); });
    }
    window.addEventListener('hashchange', function () {
        var id = location.hash.slice(1);
        if (id === 'ps-pref-personalization') id = 'ps-pref-appearance';
        if (!id && isMobileLayout()) {
            setMobileView('menu');
            return;
        }
        show(id, false, true);
    });
    window.addEventListener('resize', function () {
        if (!isMobileLayout()) {
            document.body.setAttribute('data-ps-view', 'desktop');
            if (backBar) backBar.hidden = true;
        } else if (!document.body.getAttribute('data-ps-view') || document.body.getAttribute('data-ps-view') === 'desktop') {
            setMobileView(location.hash ? 'detail' : 'menu');
        }
    });

    return show;
})();

function psOnTab(id, fn) {
    var ran = false;
    function maybe(tabId) {
        if (tabId === id && !ran) { ran = true; fn(); }
    }
    document.addEventListener('ps:tab', function (e) { maybe(e.detail && e.detail.id); });
    maybe(document.body.getAttribute('data-ps-tab'));
}

(function () {
    var body = document.getElementById('psAiBody');
    if (!body) return;
    var api = body.getAttribute('data-api');

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function money(amount, currency) {
        var n = Number(amount || 0);
        return (currency || 'TZS') + ' ' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
    }
    function card(icon, tone, label, value, sub, url) {
        var tag = url ? 'a' : 'div';
        return '<' + tag + ' class="ps-ai-card ps-ai-card--' + tone + '"' + (url ? ' href="' + esc(url) + '"' : '') + '>'
            + '<span class="ps-ai-card-icon"><i class="fas ' + icon + '"></i></span>'
            + '<span class="ps-ai-card-text"><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong>'
            + (sub ? '<em>' + esc(sub) + '</em>' : '') + '</span></' + tag + '>';
    }
    function render(b) {
        var r = b.receivables || {}, a = b.approvals || {}, s = b.stock || {}, p = b.procurement || {}, an = b.anomalies || {};
        var urls = b.urls || {};
        var cards = [];
        if (r.available) cards.push(card('fa-file-invoice-dollar', 'red', 'Overdue invoices', String(r.overdue_count || 0), money(r.overdue_amount, b.currency), urls.invoices));
        if (a.available) cards.push(card('fa-clipboard-check', 'amber', 'Pending approvals', String(a.pending_count || 0), 'Payment vouchers', a.url || urls.vouchers));
        if (s.available) cards.push(card('fa-boxes-stacked', 'blue', 'Low stock items', String(s.low_stock_count || 0), 'At or below reorder level', s.url || urls.stock));
        if (p.available) cards.push(card('fa-truck-ramp-box', 'violet', 'Stalled purchases', String(p.stalled_count || 0), 'Waiting too long', p.url || urls.procurement));
        if (an.available) cards.push(card('fa-triangle-exclamation', 'slate', 'Balance anomalies', String(an.count || 0), 'Invoices to check', an.url || urls.invoices));
        var n = b.narrative || {};
        var total = Number(b.total_attention_items || 0);
        body.innerHTML =
            '<div class="ps-ai-hero">'
            + '<div><p class="ps-ai-greet">' + esc(b.greeting || 'Hello') + (b.user_name ? ', ' + esc(b.user_name) : '') + '</p>'
            + '<p class="ps-ai-date">' + esc(b.date_label || '') + (b.company_name ? ' · ' + esc(b.company_name) : '') + '</p></div>'
            + '<span class="ps-ai-badge' + (total > 0 ? ' is-alert' : '') + '">' + (total > 0 ? total + ' item' + (total === 1 ? '' : 's') + ' need attention' : 'All clear') + '</span>'
            + '</div>'
            + (cards.length ? '<div class="ps-ai-grid">' + cards.join('') + '</div>' : '')
            + (n.facts || n.analysis ? '<div class="ps-ai-note">'
                + (n.facts ? '<p>' + esc(n.facts) + '</p>' : '')
                + (n.analysis ? '<p class="ps-ai-analysis">' + esc(n.analysis) + '</p>' : '')
                + '</div>' : '');
    }
    function load() {
        body.innerHTML = '<div class="ps-pref-loading"><i class="fas fa-circle-notch fa-spin"></i> Preparing your briefing…</div>';
        fetch(api + (api.indexOf('?') === -1 ? '?' : '&') + 'action=briefing', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.briefing) throw new Error((data && data.error) || 'No briefing');
                render(data.briefing);
            })
            .catch(function () {
                body.innerHTML = '<p class="ps-pref-empty">I could not load the AI Agent briefing right now. Try Refresh in a moment.</p>';
            });
    }
    psOnTab('ps-pref-ai', load);
    var refresh = document.getElementById('psAiRefresh');
    if (refresh) refresh.addEventListener('click', load);
})();

function psNotify(message, type, durationMs) {
    type = type === 'error' ? 'error' : (type === 'info' ? 'info' : 'success');
    var existing = document.getElementById('psToast');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'ps-toast ps-toast--' + type;
    toast.id = 'psToast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    var icon = type === 'error' ? 'fa-circle-exclamation' : (type === 'info' ? 'fa-circle-info' : 'fa-circle-check');
    toast.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i><span></span><button type="button" class="ps-toast-close" aria-label="Dismiss">&times;</button>';
    toast.querySelector('span').textContent = message || '';
    toast.querySelector('.ps-toast-close').addEventListener('click', function () { toast.remove(); });
    document.body.appendChild(toast);
    requestAnimationFrame(function () { toast.classList.add('is-in'); });
    var hold = Number(durationMs);
    if (!Number.isFinite(hold) || hold <= 0) {
        hold = type === 'error' ? 5500 : 3000;
    }
    setTimeout(function () {
        toast.classList.remove('is-in');
        setTimeout(function () { if (toast.parentNode) toast.remove(); }, 250);
    }, hold);
}

(function () {
    var grid = document.getElementById('psFontGrid');
    if (!grid) return;
    var wrap = document.getElementById('psFontSamsung');
    var previewCard = document.getElementById('psFontPreviewCard');
    var loading = document.getElementById('psFontLoading');
    var loadingTitle = document.getElementById('psFontLoadingTitle');
    var loadingSub = document.getElementById('psFontLoadingSub');
    var note = document.getElementById('psFontNote');
    var preview = document.getElementById('psFontPreview');
    var original = grid.getAttribute('data-selected') || '';
    var busy = false;

    function applyPreview(btn) {
        if (!preview || !btn) return;
        var stack = '';
        try { stack = getComputedStyle(btn).getPropertyValue('--ps-font').trim(); } catch (e) {}
        if (!stack) stack = 'inherit';
        preview.style.fontFamily = stack;
        if (previewCard) {
            previewCard.classList.remove('is-swapping');
            void previewCard.offsetWidth;
            previewCard.classList.add('is-swapping');
        }
    }

    function setLoading(on, label) {
        if (wrap) wrap.classList.toggle('is-loading', !!on);
        if (previewCard) previewCard.classList.toggle('is-loading', !!on);
        if (loading) loading.hidden = !on;
        if (on) {
            if (loadingTitle) loadingTitle.textContent = 'Applying ' + (label || 'font');
            if (loadingSub) loadingSub.textContent = 'Updating your look…';
            if (note) note.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Saving ' + (label || 'font') + '…';
        }
    }

    function scrollSelectedIntoView() {
        var selected = grid.querySelector('.ps-font-card.is-selected');
        if (!selected || typeof selected.scrollIntoView !== 'function') return;
        try { selected.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' }); } catch (e) {
            selected.scrollIntoView(false);
        }
    }

    try {
        var saved = sessionStorage.getItem('psFontSaved');
        if (saved) {
            sessionStorage.removeItem('psFontSaved');
            psNotify(saved, 'success');
        }
    } catch (err) {}

    psOnTab('ps-pref-appearance', function () {
        grid.querySelectorAll('[data-google],[data-local]').forEach(function (btn) {
            ['data-local', 'data-google'].forEach(function (attr) {
                var href = btn.getAttribute(attr);
                if (!href || document.querySelector('link[href="' + href + '"]')) return;
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                document.head.appendChild(link);
            });
        });
        applyPreview(grid.querySelector('.ps-font-card.is-selected'));
        setTimeout(scrollSelectedIntoView, 50);
    });

    grid.addEventListener('click', function (e) {
        var btn = e.target.closest('.ps-font-card');
        if (!btn || busy) return;
        var chosen = btn.getAttribute('data-font') || '';
        if (chosen === original) {
            applyPreview(btn);
            return;
        }
        var cards = grid.querySelectorAll('.ps-font-card');
        var previous = grid.querySelector('.ps-font-card.is-selected');
        var label = btn.getAttribute('data-label') || (btn.querySelector('.ps-font-sample') || btn).textContent.trim();
        busy = true;
        grid.style.pointerEvents = 'none';
        cards.forEach(function (c) {
            c.classList.toggle('is-selected', c === btn);
            c.classList.toggle('is-applying', c === btn);
        });
        applyPreview(btn);
        scrollSelectedIntoView();
        setLoading(true, label);
        fetch(grid.getAttribute('data-api'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ userFont: chosen === '' ? 'company' : chosen })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.ok) throw new Error((data && data.error) || 'Save failed');
                if (loadingSub) loadingSub.textContent = 'Almost done…';
                if (note) note.innerHTML = '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Applying ' + label + '…';
                try { sessionStorage.setItem('psFontSaved', 'Font changed to ' + label); } catch (err) {}
                window.location.reload();
            })
            .catch(function (err) {
                busy = false;
                grid.style.pointerEvents = '';
                cards.forEach(function (c) {
                    c.classList.toggle('is-selected', c === previous);
                    c.classList.remove('is-applying');
                });
                applyPreview(previous);
                setLoading(false);
                if (note) note.textContent = 'Current font unchanged.';
                psNotify(err.message || 'Could not save your font. Please try again.', 'error');
            });
    });
})();

(function () {
    var grid = document.getElementById('psModeGrid');
    if (!grid) return;
    var busy = false;
    function preference() {
        if (window.erpThemeToggle && typeof window.erpThemeToggle.getPreference === 'function') {
            return window.erpThemeToggle.getPreference();
        }
        try {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'system' || stored === 'light') return stored;
        } catch (err) {}
        var pref = document.documentElement.getAttribute('data-theme-pref');
        if (pref === 'dark' || pref === 'system' || pref === 'light') return pref;
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }
    function applyMode(mode, opts) {
        opts = opts || {};
        if (window.erpThemeToggle && typeof window.erpThemeToggle.setTheme === 'function') {
            window.erpThemeToggle.setTheme(mode, { toast: false });
            return;
        }
        var resolved = mode;
        if (mode === 'system') {
            try {
                resolved = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            } catch (err) { resolved = 'light'; }
        }
        try { localStorage.setItem('theme', mode); } catch (err) {}
        document.documentElement.setAttribute('data-theme-pref', mode);
        document.documentElement.setAttribute('data-theme', resolved === 'dark' ? 'dark' : 'light');
    }
    function sync() {
        var mode = preference();
        grid.querySelectorAll('.ps-mode-card').forEach(function (c) {
            c.classList.toggle('is-selected', c.getAttribute('data-mode') === mode);
        });
    }
    grid.addEventListener('click', function (e) {
        var btn = e.target.closest('.ps-mode-card');
        if (!btn || busy) return;
        var mode = btn.getAttribute('data-mode') || '';
        if (!mode || mode === preference()) return;
        busy = true;
        grid.querySelectorAll('.ps-mode-card').forEach(function (c) {
            c.classList.toggle('is-selected', c === btn);
            c.classList.toggle('is-applying', c === btn);
        });
        applyMode(mode);
        var label = mode === 'system' ? 'System' : (mode === 'dark' ? 'Dark' : 'Light');
        var msg = mode === 'system'
            ? 'Matching your device theme'
            : ('Switched to ' + label + ' mode');
        setTimeout(function () {
            sync();
            grid.querySelectorAll('.ps-mode-card').forEach(function (c) { c.classList.remove('is-applying'); });
            busy = false;
            if (typeof psNotify === 'function') psNotify(msg, 'success');
        }, 220);
    });
    new MutationObserver(sync).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme', 'data-theme-pref']
    });
    window.addEventListener('themeChanged', sync);
    sync();
})();

(function () {
    var section = document.getElementById('ps-pref-accessibility');
    if (!section) return;
    var range = document.getElementById('psA11yRange');
    var preview = document.getElementById('psA11yPreview');
    var toggles = document.getElementById('psA11yToggles');
    var note = document.getElementById('psA11yNote');
    var resetBtn = document.getElementById('psA11yReset');
    var storageKey = 'erp-a11y';
    var minScale = 100;
    var maxScale = 200;
    var company = {};
    try { company = JSON.parse(section.getAttribute('data-a11y-company') || '{}') || {}; } catch (e) { company = {}; }

    function clampScale(value) {
        var n = parseInt(value, 10);
        if (!isFinite(n)) n = 100;
        if (n < minScale) n = minScale;
        if (n > maxScale) n = maxScale;
        return String(n);
    }

    function defaults() {
        return {
            textScale: clampScale(company.textScale || '100'),
            highContrast: !!company.highContrast,
            reduceMotion: !!company.reduceMotion,
            underlineLinks: !!company.underlineLinks,
            largeTargets: !!company.largeTargets,
            deliveryNoteQr: company.deliveryNoteQr !== false
        };
    }

    function readStored() {
        var base = defaults();
        try {
            var o = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (o && typeof o === 'object') {
                Object.keys(o).forEach(function (k) { base[k] = o[k]; });
            }
        } catch (e) {}
        base.textScale = clampScale(base.textScale);
        return base;
    }

    function apply(prefs) {
        var root = document.documentElement;
        var scale = clampScale(prefs.textScale || '100');
        root.setAttribute('data-a11y-text', scale);
        root.style.setProperty('--erp-a11y-text-scale', scale + '%');
        root.setAttribute('data-a11y-contrast', prefs.highContrast ? '1' : '0');
        root.setAttribute('data-a11y-motion', prefs.reduceMotion ? '1' : '0');
        root.setAttribute('data-a11y-underline', prefs.underlineLinks ? '1' : '0');
        root.setAttribute('data-a11y-tap', prefs.largeTargets ? '1' : '0');
    }

    function save(prefs, quiet) {
        try { localStorage.setItem(storageKey, JSON.stringify(prefs)); } catch (e) {}
        apply(prefs);
        if (note) note.textContent = 'Saved for this device.';
        if (!quiet && typeof showToast === 'function') showToast('Accessibility preferences updated', 'success');
    }

    function syncUi(prefs) {
        var scale = clampScale(prefs.textScale);
        var n = parseInt(scale, 10);
        if (range) {
            range.value = scale;
            range.style.setProperty('--ps-a11y-pct', (((n - minScale) / (maxScale - minScale)) * 100) + '%');
        }
        if (preview) {
            preview.style.fontSize = (0.95 * (n / 100)) + 'rem';
        }
        if (toggles) {
            toggles.querySelectorAll('input[data-a11y-key]').forEach(function (input) {
                var key = input.getAttribute('data-a11y-key');
                input.checked = !!prefs[key];
            });
        }
    }

    var state = readStored();
    apply(state);
    syncUi(state);

    if (range) {
        range.addEventListener('input', function () {
            state = readStored();
            state.textScale = clampScale(range.value);
            apply(state);
            syncUi(state);
        });
        range.addEventListener('change', function () {
            state = readStored();
            state.textScale = clampScale(range.value);
            save(state, true);
            syncUi(state);
        });
    }
    if (toggles) {
        toggles.addEventListener('change', function (e) {
            var input = e.target.closest('input[data-a11y-key]');
            if (!input) return;
            state = readStored();
            state[input.getAttribute('data-a11y-key')] = !!input.checked;
            save(state);
            syncUi(state);
        });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            try { localStorage.removeItem(storageKey); } catch (e) {}
            state = defaults();
            apply(state);
            syncUi(state);
            if (note) note.textContent = 'Reset to company default.';
            if (typeof showToast === 'function') showToast('Accessibility reset to company default', 'success');
        });
    }
})();

(function () {
    var detailsForm = document.getElementById('psDetailsForm');
    if (detailsForm) {
        var detailsSave = detailsForm.querySelector('.ps-details-save');
        var detailsInputs = detailsForm.querySelectorAll('input:not([type="hidden"])');
        var syncDetailsSave = function () {
            var dirty = Array.prototype.some.call(detailsInputs, function (i) { return i.value !== i.defaultValue; });
            if (detailsSave) detailsSave.disabled = !dirty;
        };
        detailsInputs.forEach(function (i) { i.addEventListener('input', syncDetailsSave); });
        syncDetailsSave();
    }

    var search = document.getElementById('psHeadSearch');
    var navScroll = document.getElementById('psNavScroll');
    var navMore = document.getElementById('psNavMore');
    var navRoot = document.querySelector('.section-nav');
    var navItems = Array.prototype.slice.call(document.querySelectorAll('.section-nav li'));

    var syncNavMore = function () {
        if (!navScroll || !navMore || !navRoot) return;
        var overflow = navScroll.scrollHeight > navScroll.clientHeight + 4;
        var atEnd = navScroll.scrollTop + navScroll.clientHeight >= navScroll.scrollHeight - 6;
        navMore.hidden = !overflow || atEnd;
        navRoot.classList.toggle('is-scrolled-end', !overflow || atEnd);
        navMore.setAttribute('aria-hidden', navMore.hidden ? 'true' : 'false');
    };

    if (navScroll) {
        navScroll.addEventListener('scroll', syncNavMore, { passive: true });
        window.addEventListener('resize', syncNavMore);
        if (typeof ResizeObserver !== 'undefined') {
            try { new ResizeObserver(syncNavMore).observe(navScroll); } catch (e) {}
        }
        requestAnimationFrame(syncNavMore);
    }
    if (navMore && navScroll) {
        navMore.addEventListener('click', function () {
            navScroll.scrollBy({ top: Math.max(120, navScroll.clientHeight * 0.7), behavior: 'smooth' });
        });
    }

    if (!search) return;
    var filterNav = function () {
        var q = search.value.trim().toLowerCase();
        navItems.forEach(function (li) {
            if (li.classList.contains('ps-nav-sep')) {
                li.classList.toggle('is-filtered-out', !!q);
                return;
            }
            var link = li.querySelector('a[href^="#"]');
            if (!link) return;
            var hay = (
                (link.textContent || '') + ' ' +
                (link.getAttribute('data-ps-keywords') || '')
            ).toLowerCase();
            li.classList.toggle('is-filtered-out', !!(q && hay.indexOf(q) === -1));
        });
        requestAnimationFrame(syncNavMore);
    };
    search.addEventListener('input', filterNav);
    search.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var q = search.value.trim().toLowerCase();
        if (!q) return;
        var visible = Array.prototype.find.call(document.querySelectorAll('.section-nav a[href^="#"]'), function (link) {
            var li = link.closest('li');
            return li && !li.classList.contains('is-filtered-out');
        });
        if (visible) {
            psShowTab(visible.getAttribute('href').slice(1), true);
            return;
        }
        var hit = Array.prototype.find.call(document.querySelectorAll('.editor-section[id]'), function (section) {
            return section.textContent.toLowerCase().indexOf(q) !== -1;
        });
        if (hit) psShowTab(hit.id, true);
    });
})();
</script>
<script>
window.__PROFILE_BIOMETRIC__ = <?= json_encode([
    'apiUrl' => $psBiometricApiUrl,
    'biometric' => $psBiometricStatus,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= $esc($psBiometricJsUrl) ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/profile-biometric.js') ?>" defer></script>
</body>
</html>

