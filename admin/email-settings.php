<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/email/includes/email_bootstrap.php';
requireAdmin();
ensureSystemSettingsSchema();

$settingsPdo = email_settings_pdo();
if (!($settingsPdo instanceof PDO)) {
    $settingsPdo = $pdo;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['active_module'] = 'settings';

$settingsHubUrl = function_exists('company_url')
    ? company_url('admin/settings.php?module=settings')
    : app_url('/admin/settings.php?module=settings');
$emailSettingsUrl = function_exists('company_url')
    ? company_url('admin/email-settings.php?module=settings')
    : app_url('/admin/email-settings.php?module=settings');

$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

// Handle SMTP Settings Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_smtp_settings'])) {
    $settings = [
        'email_smtp_host' => trim($_POST['email_smtp_host'] ?? ''),
        'email_smtp_port' => trim($_POST['email_smtp_port'] ?? '465'),
        'email_smtp_user' => trim($_POST['email_smtp_user'] ?? ''),
        'email_smtp_pass' => $_POST['email_smtp_pass'] ?? '',
        'email_smtp_secure' => $_POST['email_smtp_secure'] ?? 'ssl',
        'email_imap_host' => trim($_POST['email_imap_host'] ?? ''),
        'email_imap_port' => trim($_POST['email_imap_port'] ?? '993'),
        'email_imap_user' => trim($_POST['email_imap_user'] ?? ''),
        'email_imap_pass' => $_POST['email_imap_pass'] ?? '',
        'email_imap_ssl' => $_POST['email_imap_ssl'] ?? 'ssl',
        'email_bridge_ultimate_url' => rtrim(trim($_POST['email_bridge_ultimate_url'] ?? ''), '/'),
        'email_bridge_roadmaster_url' => rtrim(trim($_POST['email_bridge_roadmaster_url'] ?? ''), '/'),
        'email_system_from_email' => trim((string) ($_POST['email_system_from_email'] ?? '')),
        'email_system_from_name' => trim((string) ($_POST['email_system_from_name'] ?? '')),
        'email_system_sync_smtp' => isset($_POST['email_system_sync_smtp']) ? '1' : '0',
        'email_use_system_payroll' => isset($_POST['email_use_system_payroll']) ? '1' : '0',
        'email_use_system_sales' => isset($_POST['email_use_system_sales']) ? '1' : '0',
        'email_use_system_purchases' => isset($_POST['email_use_system_purchases']) ? '1' : '0',
        'email_use_system_expenses' => isset($_POST['email_use_system_expenses']) ? '1' : '0',
        'email_use_system_crm' => isset($_POST['email_use_system_crm']) ? '1' : '0',
        'email_from_payroll' => trim((string) ($_POST['email_from_payroll'] ?? '')),
        'email_from_payroll_name' => trim((string) ($_POST['email_from_payroll_name'] ?? '')),
        'email_from_sales' => trim((string) ($_POST['email_from_sales'] ?? '')),
        'email_from_sales_name' => trim((string) ($_POST['email_from_sales_name'] ?? '')),
        'email_from_purchases' => trim((string) ($_POST['email_from_purchases'] ?? '')),
        'email_from_purchases_name' => trim((string) ($_POST['email_from_purchases_name'] ?? '')),
        'email_from_expenses' => trim((string) ($_POST['email_from_expenses'] ?? '')),
        'email_from_expenses_name' => trim((string) ($_POST['email_from_expenses_name'] ?? '')),
        'email_from_crm' => trim((string) ($_POST['email_from_crm'] ?? '')),
        'email_from_crm_name' => trim((string) ($_POST['email_from_crm_name'] ?? '')),
    ];

    // Keep existing passwords when left blank.
    $existingEmailSettings = [];
    try {
        $exAll = $settingsPdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_%'");
        $existingEmailSettings = $exAll ? $exAll->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    } catch (Throwable $e) {
        $existingEmailSettings = [];
    }

    $smtpPassPosted = (string) ($_POST['email_smtp_pass'] ?? '');
    if ($smtpPassPosted === '') {
        unset($settings['email_smtp_pass']);
    }
    $imapPassPosted = (string) ($_POST['email_imap_pass'] ?? '');
    if ($imapPassPosted === '') {
        unset($settings['email_imap_pass']);
    }

    $systemMailboxPass = (string) ($_POST['email_system_mailbox_pass'] ?? '');
    if ($systemMailboxPass !== '') {
        $settings['email_system_mailbox_pass'] = $systemMailboxPass;
    }

    // Optional: push the system cPanel mailbox into SMTP login so modules can send with it.
    if (isset($_POST['email_system_sync_smtp']) && $settings['email_system_from_email'] !== '') {
        $settings['email_smtp_user'] = $settings['email_system_from_email'];
        if ($systemMailboxPass !== '') {
            $settings['email_smtp_pass'] = $systemMailboxPass;
        } elseif (!empty($existingEmailSettings['email_system_mailbox_pass'])) {
            $settings['email_smtp_pass'] = (string) $existingEmailSettings['email_system_mailbox_pass'];
        }
        if (trim((string) ($settings['email_smtp_host'] ?? '')) === '') {
            $domain = substr(strrchr($settings['email_system_from_email'], '@') ?: '', 1);
            if ($domain !== '') {
                $settings['email_smtp_host'] = 'mail.' . $domain;
            }
        }
        if (trim((string) ($settings['email_smtp_port'] ?? '')) === '') {
            $settings['email_smtp_port'] = '465';
        }
        if (trim((string) ($settings['email_smtp_secure'] ?? '')) === '') {
            $settings['email_smtp_secure'] = 'ssl';
        }
    }

    $ultimateKey = trim((string) ($_POST['email_bridge_ultimate_api_key'] ?? ''));
    if ($ultimateKey !== '') {
        $settings['email_bridge_ultimate_api_key'] = $ultimateKey;
    }
    $roadmasterKey = trim((string) ($_POST['email_bridge_roadmaster_api_key'] ?? ''));
    if ($roadmasterKey !== '') {
        $settings['email_bridge_roadmaster_api_key'] = $roadmasterKey;
    }

    // Auto-enable when URL is set (and API key already stored or newly provided).
    $existing = [];
    try {
        $ex = $settingsPdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_bridge_%'");
        $existing = $ex ? $ex->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    } catch (Throwable $e) {
        $existing = [];
    }
    $ultKeyFinal = $ultimateKey !== '' ? $ultimateKey : trim((string) ($existing['email_bridge_ultimate_api_key'] ?? ''));
    $rmKeyFinal = $roadmasterKey !== '' ? $roadmasterKey : trim((string) ($existing['email_bridge_roadmaster_api_key'] ?? ''));
    $settings['email_bridge_ultimate_enabled'] = (
        isset($_POST['email_bridge_ultimate_enabled'])
        || ($settings['email_bridge_ultimate_url'] !== '' && $ultKeyFinal !== '')
    ) ? '1' : '0';
    $settings['email_bridge_roadmaster_enabled'] = (
        isset($_POST['email_bridge_roadmaster_enabled'])
        || ($settings['email_bridge_roadmaster_url'] !== '' && $rmKeyFinal !== '')
    ) ? '1' : '0';
    // Allow explicit uncheck only when URL cleared.
    if (!isset($_POST['email_bridge_ultimate_enabled']) && $settings['email_bridge_ultimate_url'] === '') {
        $settings['email_bridge_ultimate_enabled'] = '0';
    }
    if (!isset($_POST['email_bridge_roadmaster_enabled']) && $settings['email_bridge_roadmaster_url'] === '') {
        $settings['email_bridge_roadmaster_enabled'] = '0';
    }

    foreach ($settings as $key => $val) {
        $stmt = $settingsPdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $val]);
    }

    $_SESSION['flash_message'] = 'All email settings updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $emailSettingsUrl);
    exit();
}

// Fetch current settings from the company tenant database
$stmt = $settingsPdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_%'");
$current = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$mailConfigPath = __DIR__ . '/../config_mail.php';
if (file_exists($mailConfigPath)) {
    require_once $mailConfigPath;
}

$smtpHost = trim((string) ($current['email_smtp_host'] ?? ''));
$smtpPort = trim((string) ($current['email_smtp_port'] ?? ''));
$smtpUser = trim((string) ($current['email_smtp_user'] ?? ''));
$smtpPass = (string) ($current['email_smtp_pass'] ?? '');
$smtpSecure = trim((string) ($current['email_smtp_secure'] ?? ''));

$imapHost = trim((string) ($current['email_imap_host'] ?? ''));
$imapPort = trim((string) ($current['email_imap_port'] ?? ''));
$imapUser = trim((string) ($current['email_imap_user'] ?? ''));
$imapPass = (string) ($current['email_imap_pass'] ?? '');
$imapSSL = trim((string) ($current['email_imap_ssl'] ?? ''));

if ($smtpHost === '' && defined('SMTP_HOST')) {
    $smtpHost = (string) SMTP_HOST;
}
if ($smtpPort === '' && defined('SMTP_PORT')) {
    $smtpPort = (string) SMTP_PORT;
}
if ($smtpUser === '' && defined('SMTP_USER')) {
    $smtpUser = (string) SMTP_USER;
}
if ($smtpPass === '' && defined('SMTP_PASS')) {
    $smtpPass = (string) SMTP_PASS;
}
if ($smtpSecure === '' && defined('SMTP_SECURE')) {
    $smtpSecure = (string) SMTP_SECURE;
}
if ($imapHost === '' && $smtpHost !== '') {
    $imapHost = $smtpHost;
}
if ($imapPort === '') {
    $imapPort = '993';
}
if ($imapUser === '' && $smtpUser !== '') {
    $imapUser = $smtpUser;
}
if ($imapPass === '' && $smtpPass !== '') {
    $imapPass = $smtpPass;
}
if ($imapSSL === '') {
    $imapSSL = 'ssl';
}
if ($smtpPort === '') {
    $smtpPort = '465';
}
if ($smtpSecure === '') {
    $smtpSecure = 'ssl';
}

$bridgeUltimateEnabled = trim((string) ($current['email_bridge_ultimate_enabled'] ?? '0')) === '1';
$bridgeUltimateUrl = trim((string) ($current['email_bridge_ultimate_url'] ?? ''));
$bridgeUltimateKey = trim((string) ($current['email_bridge_ultimate_api_key'] ?? ''));
$bridgeRoadmasterEnabled = trim((string) ($current['email_bridge_roadmaster_enabled'] ?? '0')) === '1';
$bridgeRoadmasterUrl = trim((string) ($current['email_bridge_roadmaster_url'] ?? ''));
$bridgeRoadmasterKey = trim((string) ($current['email_bridge_roadmaster_api_key'] ?? ''));

$systemFromEmail = trim((string) ($current['email_system_from_email'] ?? ''));
$systemFromName = trim((string) ($current['email_system_from_name'] ?? ''));
$systemMailboxPassSet = trim((string) ($current['email_system_mailbox_pass'] ?? '')) !== '';
$systemSyncSmtp = !array_key_exists('email_system_sync_smtp', $current)
    || trim((string) $current['email_system_sync_smtp']) === '1';
if ($systemFromEmail === '' && defined('SMTP_FROM_EMAIL')) {
    $systemFromEmail = (string) SMTP_FROM_EMAIL;
}
if ($systemFromEmail === '' && $smtpUser !== '') {
    $systemFromEmail = $smtpUser;
}
if ($systemFromName === '' && defined('SMTP_FROM_NAME')) {
    $systemFromName = (string) SMTP_FROM_NAME;
}

$useSystemPayroll = trim((string) ($current['email_use_system_payroll'] ?? '1')) === '1';
$useSystemSales = trim((string) ($current['email_use_system_sales'] ?? '1')) === '1';
$useSystemPurchases = trim((string) ($current['email_use_system_purchases'] ?? '1')) === '1';
$useSystemExpenses = trim((string) ($current['email_use_system_expenses'] ?? '1')) === '1';
$useSystemCrm = trim((string) ($current['email_use_system_crm'] ?? '1')) === '1';

$fromPayroll = trim((string) ($current['email_from_payroll'] ?? ''));
$fromPayrollName = trim((string) ($current['email_from_payroll_name'] ?? ''));
$fromSales = trim((string) ($current['email_from_sales'] ?? ''));
$fromSalesName = trim((string) ($current['email_from_sales_name'] ?? ''));
$fromPurchases = trim((string) ($current['email_from_purchases'] ?? ''));
$fromPurchasesName = trim((string) ($current['email_from_purchases_name'] ?? ''));
$fromExpenses = trim((string) ($current['email_from_expenses'] ?? ''));
$fromExpensesName = trim((string) ($current['email_from_expenses_name'] ?? ''));
$fromCrm = trim((string) ($current['email_from_crm'] ?? ''));
$fromCrmName = trim((string) ($current['email_from_crm_name'] ?? ''));

$flashMsg = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
if ($flashMsg !== null) {
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$selectArrow = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";
$testApiUrl = function_exists('company_url')
    ? company_url('admin/api/test_mail_config.php?module=settings')
    : (function_exists('app_url') ? app_url('admin/api/test_mail_config.php?module=settings') : 'api/test_mail_config.php?module=settings');

$appHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'your ERP server'));
$mailDomainHint = 'ultimate.co.tz';
if ($smtpHost !== '') {
    $mailDomainHint = preg_replace('/^mail\./', '', $smtpHost);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Configuration | <?= $esc(COMPANY_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= $esc(function_exists('app_url') ? app_url('/assets/css/style.css') : '../assets/css/style.css') ?>">
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
        .editor-section { padding-bottom: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
        .editor-section:last-of-type { margin-bottom: 1.5rem; }
        .section-header { margin-bottom: 1.25rem; }
        .section-title { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .section-subtitle { font-size: 12px; color: #94a3b8; margin: 0; }
        .form-row { display: grid; grid-template-columns: 210px 1fr; align-items: start; margin-bottom: 24px; }
        .form-row:last-child { margin-bottom: 0; }
        .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 12px; }
        .form-label span { color: #ef4444; margin-left: 2px; }
        .form-input {
            width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff;
        }
        .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .help-text { font-size: 12px; color: #94a3b8; margin-top: 6px; line-height: 1.5; }
        .host-port-grid { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
        .password-wrap { position: relative; }
        .password-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #94a3b8; background: none; border: none; padding: 0;
        }
        .password-toggle:hover { color: #64748b; }
        .password-wrap .form-input { padding-right: 42px; }
        .btn-test {
            border: 1px solid #e2e8f0; background: #fff; color: #475569; padding: 10px 16px;
            border-radius: 10px; font-size: 13px; font-weight: 600; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
        }
        .btn-test:hover { background: #f8fafc; border-color: #cbd5e1; color: #1e293b; }
        .btn-test.success { border-color: #22c55e; color: #15803d; background: #f0fdf4; }
        .test-status { font-size: 12px; font-weight: 600; margin-left: 8px; }
        .tips-list { list-style: none; margin: 0; padding: 0; }
        .tips-list li {
            display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: #475569;
            padding: 10px 0; border-bottom: 1px solid #f1f5f9;
        }
        .tips-list li:last-child { border-bottom: none; padding-bottom: 0; }
        .tips-list li i { color: #7c3aed; margin-top: 2px; flex-shrink: 0; }
        .btn-save {
            background: #7c3aed !important; color: white !important; padding: 14px 48px;
            border-radius: 12px; font-weight: 600; font-size: 15px; border: none;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
        }
        .btn-save:hover { background: #6d28d9 !important; }
        .btn-cancel { border: 1px solid #d8b4fe; color: #7c3aed; background: #faf5ff; transition: all 0.2s; }
        .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
        .alert-flash { margin-bottom: 1.5rem; border-radius: 12px; padding: 12px 16px; font-size: 14px; font-weight: 500; }
        .alert-flash.success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }
        .alert-flash.error { border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; }
        .info-banner {
            margin-bottom: 1.5rem; border-radius: 12px; border: 1px solid #bfdbfe;
            background: #eff6ff; color: #1e3a8a; padding: 14px 16px; font-size: 13px; line-height: 1.55;
        }
        .info-banner strong { color: #1d4ed8; }
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
            .host-port-grid { grid-template-columns: 1fr; }
            .btn-save { width: 100%; padding: 14px 24px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">Email Configuration</h1>
                <p class="text-sm text-slate-400 mt-1 mb-0">Configure outgoing (SMTP) and incoming (IMAP) mail server settings.</p>
            </div>
            <a href="<?= $esc($settingsHubUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Settings
            </a>
        </div>

        <?php if ($flashMsg !== null): ?>
            <div class="alert-flash <?= $flashType === 'error' ? 'error' : 'success' ?>"><?= $esc($flashMsg) ?></div>
        <?php endif; ?>

        <div class="info-banner">
            <strong>Cross-domain mail is supported.</strong>
            Your ERP runs on <strong><?= $esc($appHost) ?></strong> but can use cPanel mail from
            <strong><?= $esc($mailDomainHint) ?></strong> (for example <code>mail.ultimate.co.tz</code>).
            The test connects from the ERP server to your mail server — not from your computer.
            Use cPanel values: host <strong>mail.<?= $esc($mailDomainHint) ?></strong>, SMTP port <strong>465 + SSL</strong>, IMAP port <strong>993 + SSL</strong>, and your full email address as the username.
        </div>

        <form method="post" id="settingsForm">
            <input type="hidden" name="update_smtp_settings" value="1">

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#smtp" class="is-active">Outgoing (SMTP)</a></li>
                        <li><a href="#system-mail">System mailing</a></li>
                        <li><a href="#imap">Incoming (IMAP)</a></li>
                        <li><a href="#bridges">Remote Bridges</a></li>
                        <li><a href="#tips">Tips</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="smtp">
                        <div class="section-header">
                            <h2 class="section-title">Outgoing Mail (SMTP)</h2>
                            <p class="section-subtitle">Settings used when the system sends emails.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_smtp_host">Server <span>*</span></label>
                            <div>
                                <div class="host-port-grid">
                                    <input type="text" name="email_smtp_host" id="email_smtp_host" class="form-input"
                                           placeholder="mail.ultimate.co.tz" value="<?= $esc($smtpHost) ?>" required>
                                    <input type="text" name="email_smtp_port" id="email_smtp_port" class="form-input"
                                           placeholder="465" value="<?= $esc($smtpPort) ?>" required>
                                </div>
                                <p class="help-text">cPanel outgoing server, usually <strong>mail.yourdomain.com</strong> on port <strong>465</strong> with <strong>SSL</strong>.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_smtp_user">Username <span>*</span></label>
                            <div>
                                <input type="text" name="email_smtp_user" id="email_smtp_user" class="form-input"
                                       placeholder="mail@example.tz" value="<?= $esc($smtpUser) ?>" required>
                                <p class="help-text">Use your full email address as the username.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="smtpPass">Password <span>*</span></label>
                            <div>
                                <div class="password-wrap">
                                    <input type="password" name="email_smtp_pass" id="smtpPass" class="form-input"
                                           placeholder="••••••••••••" value="<?= $esc($smtpPass) ?>" required>
                                    <button type="button" class="password-toggle" onclick="togglePass('smtpPass', this)" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_smtp_secure">Security</label>
                            <div>
                                <select name="email_smtp_secure" id="email_smtp_secure" class="form-input appearance-none pr-10" style="<?= $selectArrow ?>">
                                    <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL (Use with Port 465)</option>
                                    <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>TLS (Use with Port 587)</option>
                                    <option value="none" <?= $smtpSecure === 'none' ? 'selected' : '' ?>>None (Not Recommended)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Connection Test</label>
                            <div>
                                <button type="button" onclick="testConnection('smtp')" id="btnTestSMTP" class="btn-test">
                                    <i class="fas fa-flask"></i> Test SMTP Connection
                                </button>
                                <span id="smtpStatus" class="test-status"></span>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="system-mail">
                        <div class="section-header">
                            <h2 class="section-title">System mailing</h2>
                            <p class="section-subtitle">
                                Enter your cPanel system mailbox (for example <strong>systeminfo@ultitech.io</strong>),
                                then choose which modules should send with it.
                            </p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_system_from_email">System mailbox</label>
                            <div>
                                <input type="email" name="email_system_from_email" id="email_system_from_email" class="form-input"
                                       placeholder="systeminfo@ultitech.io" value="<?= $esc($systemFromEmail) ?>">
                                <p class="help-text">Full cPanel email address used as the system sender.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_system_mailbox_pass">Mailbox password</label>
                            <div>
                                <div class="password-wrap">
                                    <input type="password" name="email_system_mailbox_pass" id="email_system_mailbox_pass" class="form-input"
                                           placeholder="<?= $systemMailboxPassSet ? 'Saved — leave blank to keep' : 'cPanel mailbox password' ?>"
                                           autocomplete="new-password">
                                    <button type="button" class="password-toggle" onclick="togglePass('email_system_mailbox_pass', this)" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <p class="help-text">
                                    <?= $systemMailboxPassSet ? 'Password is saved. Leave blank unless you want to change it.' : 'Paste the cPanel password for this mailbox.' ?>
                                </p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_system_from_name">From name</label>
                            <div>
                                <input type="text" name="email_system_from_name" id="email_system_from_name" class="form-input"
                                       placeholder="Ultitech System" value="<?= $esc($systemFromName) ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">SMTP login</label>
                            <div>
                                <label style="display:flex;align-items:flex-start;gap:0.55rem;font-size:14px;color:#1e293b;cursor:pointer;">
                                    <input type="checkbox" name="email_system_sync_smtp" value="1" <?= $systemSyncSmtp ? 'checked' : '' ?> style="margin-top:3px;">
                                    <span>
                                        Also use this mailbox as the SMTP username/password
                                        <span class="help-text" style="display:block;margin-top:4px;">
                                            Recommended. Copies the mailbox into Outgoing (SMTP) so payroll/sales/purchases can actually send.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Assign to modules</label>
                            <div>
                                <p class="help-text" style="margin-top:0;margin-bottom:10px;">Tick the modules that should send using the system mailbox above.</p>
                                <div style="display:grid;gap:0.75rem;">
                                    <label style="display:flex;align-items:center;gap:0.55rem;font-size:14px;color:#1e293b;">
                                        <input type="checkbox" name="email_use_system_payroll" value="1" <?= $useSystemPayroll ? 'checked' : '' ?>>
                                        <span><strong>Payroll</strong> — payslips and payroll notices</span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.55rem;font-size:14px;color:#1e293b;">
                                        <input type="checkbox" name="email_use_system_sales" value="1" <?= $useSystemSales ? 'checked' : '' ?>>
                                        <span><strong>Sales</strong> — invoices, quotations, documents</span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.55rem;font-size:14px;color:#1e293b;">
                                        <input type="checkbox" name="email_use_system_purchases" value="1" <?= $useSystemPurchases ? 'checked' : '' ?>>
                                        <span><strong>Purchases</strong> — purchase orders and supplier mail</span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.55rem;font-size:14px;color:#1e293b;">
                                        <input type="checkbox" name="email_use_system_expenses" value="1" <?= $useSystemExpenses ? 'checked' : '' ?>>
                                        <span><strong>Expenses</strong> — expense alerts and approvals</span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:0.55rem;font-size:14px;color:#1e293b;">
                                        <input type="checkbox" name="email_use_system_crm" value="1" <?= $useSystemCrm ? 'checked' : '' ?>>
                                        <span><strong>CRM</strong> — follow-ups and system customer messages</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Custom overrides</label>
                            <div>
                                <p class="help-text" style="margin-top:0;margin-bottom:10px;">Optional. Only used when a module above is <strong>not</strong> ticked for the system mailbox.</p>
                                <div style="display:grid;gap:0.75rem;">
                                    <div class="host-port-grid" style="grid-template-columns: 110px 1.2fr 1fr;">
                                        <span style="padding-top:12px;font-size:13px;color:#64748b;">Payroll</span>
                                        <input type="email" name="email_from_payroll" class="form-input" placeholder="custom@..." value="<?= $esc($fromPayroll) ?>">
                                        <input type="text" name="email_from_payroll_name" class="form-input" placeholder="From name" value="<?= $esc($fromPayrollName) ?>">
                                    </div>
                                    <div class="host-port-grid" style="grid-template-columns: 110px 1.2fr 1fr;">
                                        <span style="padding-top:12px;font-size:13px;color:#64748b;">Sales</span>
                                        <input type="email" name="email_from_sales" class="form-input" placeholder="custom@..." value="<?= $esc($fromSales) ?>">
                                        <input type="text" name="email_from_sales_name" class="form-input" placeholder="From name" value="<?= $esc($fromSalesName) ?>">
                                    </div>
                                    <div class="host-port-grid" style="grid-template-columns: 110px 1.2fr 1fr;">
                                        <span style="padding-top:12px;font-size:13px;color:#64748b;">Purchases</span>
                                        <input type="email" name="email_from_purchases" class="form-input" placeholder="custom@..." value="<?= $esc($fromPurchases) ?>">
                                        <input type="text" name="email_from_purchases_name" class="form-input" placeholder="From name" value="<?= $esc($fromPurchasesName) ?>">
                                    </div>
                                    <div class="host-port-grid" style="grid-template-columns: 110px 1.2fr 1fr;">
                                        <span style="padding-top:12px;font-size:13px;color:#64748b;">Expenses</span>
                                        <input type="email" name="email_from_expenses" class="form-input" placeholder="custom@..." value="<?= $esc($fromExpenses) ?>">
                                        <input type="text" name="email_from_expenses_name" class="form-input" placeholder="From name" value="<?= $esc($fromExpensesName) ?>">
                                    </div>
                                    <div class="host-port-grid" style="grid-template-columns: 110px 1.2fr 1fr;">
                                        <span style="padding-top:12px;font-size:13px;color:#64748b;">CRM</span>
                                        <input type="email" name="email_from_crm" class="form-input" placeholder="custom@..." value="<?= $esc($fromCrm) ?>">
                                        <input type="text" name="email_from_crm_name" class="form-input" placeholder="From name" value="<?= $esc($fromCrmName) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="imap">
                        <div class="section-header">
                            <h2 class="section-title">Incoming Mail (IMAP)</h2>
                            <p class="section-subtitle">Settings used when the system reads incoming mail.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_imap_host">Server <span>*</span></label>
                            <div>
                                <div class="host-port-grid">
                                    <input type="text" name="email_imap_host" id="email_imap_host" class="form-input"
                                           placeholder="mail.example.tz" value="<?= $esc($imapHost) ?>" required>
                                    <input type="text" name="email_imap_port" id="email_imap_port" class="form-input"
                                           placeholder="993" value="<?= $esc($imapPort) ?>" required>
                                </div>
                                <p class="help-text">Host and port for your incoming mail server.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_imap_user">Username <span>*</span></label>
                            <div>
                                <input type="text" name="email_imap_user" id="email_imap_user" class="form-input"
                                       placeholder="mail@example.tz" value="<?= $esc($imapUser) ?>" required>
                                <p class="help-text">Use your full email address as the username.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="imapPass">Password <span>*</span></label>
                            <div>
                                <div class="password-wrap">
                                    <input type="password" name="email_imap_pass" id="imapPass" class="form-input"
                                           placeholder="••••••••••••" value="<?= $esc($imapPass) ?>" required>
                                    <button type="button" class="password-toggle" onclick="togglePass('imapPass', this)" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label" for="email_imap_ssl">Security</label>
                            <div>
                                <select name="email_imap_ssl" id="email_imap_ssl" class="form-input appearance-none pr-10" style="<?= $selectArrow ?>">
                                    <option value="ssl" <?= $imapSSL === 'ssl' ? 'selected' : '' ?>>SSL (Recommended for 993)</option>
                                    <option value="tls" <?= $imapSSL === 'tls' ? 'selected' : '' ?>>TLS (Use with 143)</option>
                                    <option value="notls" <?= $imapSSL === 'notls' ? 'selected' : '' ?>>None (Not Recommended)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Connection Test</label>
                            <div>
                                <button type="button" onclick="testConnection('imap')" id="btnTestIMAP" class="btn-test">
                                    <i class="fas fa-flask"></i> Test IMAP Connection
                                </button>
                                <span id="imapStatus" class="test-status"></span>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="bridges">
                        <div class="section-header">
                            <h2 class="section-title">Remote Mail Bridges</h2>
                            <p class="section-subtitle">Pull mail from Ultimate / Roadmaster cPanel bridges over HTTPS APIs into this mail module.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Ultimate</label>
                            <div>
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700 mb-3">
                                    <input type="checkbox" name="email_bridge_ultimate_enabled" value="1" <?= $bridgeUltimateEnabled ? 'checked' : '' ?>>
                                    Enable Ultimate bridge sync
                                </label>
                                <input type="url" name="email_bridge_ultimate_url" class="form-input mb-3"
                                       placeholder="https://ultimate.co.tz/staff/mail-bridge"
                                       value="<?= $esc($bridgeUltimateUrl) ?>">
                                <div class="password-wrap">
                                    <input type="password" name="email_bridge_ultimate_api_key" id="bridgeUltimateKey" class="form-input"
                                           placeholder="<?= $bridgeUltimateKey !== '' ? '•••• leave blank to keep current key' : 'API key from ultimate config.php' ?>"
                                           value="">
                                    <button type="button" class="password-toggle" onclick="togglePass('bridgeUltimateKey', this)" aria-label="Show API key">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <p class="help-text">Deploy <code>mail-bridges/ultimate</code> on Ultimate cPanel. API key must match that bridge <code>config.php</code>.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Roadmaster</label>
                            <div>
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700 mb-3">
                                    <input type="checkbox" name="email_bridge_roadmaster_enabled" value="1" <?= $bridgeRoadmasterEnabled ? 'checked' : '' ?>>
                                    Enable Roadmaster bridge sync
                                </label>
                                <input type="url" name="email_bridge_roadmaster_url" class="form-input mb-3"
                                       placeholder="https://roadmasterspares.com/mail-bridge"
                                       value="<?= $esc($bridgeRoadmasterUrl) ?>">
                                <div class="password-wrap">
                                    <input type="password" name="email_bridge_roadmaster_api_key" id="bridgeRoadmasterKey" class="form-input"
                                           placeholder="<?= $bridgeRoadmasterKey !== '' ? '•••• leave blank to keep current key' : 'API key from roadmaster config.php' ?>"
                                           value="">
                                    <button type="button" class="password-toggle" onclick="togglePass('bridgeRoadmasterKey', this)" aria-label="Show API key">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <p class="help-text">Deploy <code>mail-bridges/roadmaster</code> on Roadmaster cPanel. Leave API key blank when saving if you do not want to change it.</p>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="tips">
                        <div class="section-header">
                            <h2 class="section-title">Configuration Tips</h2>
                            <p class="section-subtitle">Common settings for cPanel and similar hosting providers.</p>
                        </div>

                        <ul class="tips-list">
                            <li><i class="fas fa-check-circle"></i> Prefer <strong>Remote Bridges</strong> when each brand keeps mail on its own cPanel — Ultitech only needs HTTPS, not open IMAP ports.</li>
                            <li><i class="fas fa-check-circle"></i> ERP on <strong>ultitech.io</strong> + mail on <strong>ultimate.co.tz cPanel</strong> works — use <strong>mail.ultimate.co.tz</strong> as the server host.</li>
                            <li><i class="fas fa-check-circle"></i> Outgoing (SMTP): Port <strong>465</strong> with <strong>SSL</strong> (cPanel default).</li>
                            <li><i class="fas fa-check-circle"></i> Incoming (IMAP): Port <strong>993</strong> with <strong>SSL</strong>.</li>
                            <li><i class="fas fa-check-circle"></i> Username: your full mailbox address (e.g. <strong>sales@ultimate.co.tz</strong>).</li>
                            <li><i class="fas fa-check-circle"></i> If the test times out on ultitech.io, your host may block outbound mail ports — contact StackCP support to allow SMTP/IMAP to mail.ultimate.co.tz.</li>
                        </ul>
                    </section>

                    <div class="flex justify-start gap-4 mb-20 flex-wrap">
                        <button type="button" onclick="location.href='<?= $esc($settingsHubUrl) ?>'" class="btn-cancel px-8 py-3 rounded-xl font-bold">Cancel</button>
                        <button type="submit" class="btn-save">Save Configuration</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function togglePass(id, btn) {
    const input = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function smtpSecurityHint(port, secure) {
    port = parseInt(port, 10);
    if (port === 465 && secure !== 'ssl') {
        return 'Port 465 requires SSL. Change Security to SSL, or use Port 587 with TLS.';
    }
    if (port === 587 && secure === 'ssl') {
        return 'Port 587 requires TLS, not SSL. Change Security to TLS, or use Port 465 with SSL.';
    }
    if (port === 465 && secure === 'tls') {
        return 'Port 465 uses SSL, not TLS. Change Security to SSL.';
    }
    return '';
}

function testConnection(type) {
    const btn = type === 'smtp' ? document.getElementById('btnTestSMTP') : document.getElementById('btnTestIMAP');
    const status = type === 'smtp' ? document.getElementById('smtpStatus') : document.getElementById('imapStatus');
    const icon = btn.querySelector('i');
    const controller = new AbortController();
    let finished = false;
    const timeoutId = setTimeout(function () {
        if (!finished) {
            controller.abort();
        }
    }, 45000);

    if (type === 'smtp') {
        const hint = smtpSecurityHint(
            document.getElementById('email_smtp_port').value,
            document.getElementById('email_smtp_secure').value
        );
        if (hint) {
            status.textContent = ' ✗ Invalid port/security';
            status.style.color = '#dc2626';
            Swal.fire({ icon: 'warning', title: 'Check SMTP Settings', text: hint });
            return;
        }
    }

    btn.disabled = true;
    icon.classList.add('fa-spin');
    status.textContent = ' testing...';
    status.style.color = '#94a3b8';

    const formData = new FormData(document.getElementById('settingsForm'));
    formData.append('test_type', type);

    fetch(<?= json_encode($testApiUrl) ?>, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        signal: controller.signal
    })
    .then(async res => {
        const data = await res.json().catch(() => null);
        if (!res.ok) {
            throw new Error((data && data.message) ? data.message : ('HTTP Error: ' + res.status));
        }
        if (!data) {
            throw new Error('Invalid response from testing service.');
        }
        return data;
    })
    .then(data => {
        finished = true;
        btn.disabled = false;
        icon.classList.remove('fa-spin');
        if (data.status === 'success') {
            btn.classList.add('success');
            status.textContent = ' ✓ ' + (type === 'smtp' ? 'SMTP' : 'IMAP') + ' connection successful';
            status.style.color = '#16a34a';
            Swal.fire({ icon: 'success', title: 'Connection Successful', text: data.message });
        } else {
            btn.classList.remove('success');
            status.textContent = ' ✗ Connection failed';
            status.style.color = '#dc2626';
            Swal.fire({ icon: 'error', title: 'Connection Failed', text: data.message });
        }
    })
    .catch(err => {
        if (finished) return;
        finished = true;
        btn.disabled = false;
        icon.classList.remove('fa-spin');
        status.textContent = ' ✗ Test failed';
        status.style.color = '#dc2626';
        console.error('Test Connection Error:', err);
        const message = err.name === 'AbortError'
            ? 'The test timed out after 45 seconds. For cPanel mail use Host mail.yourdomain.com, Port 465, Security SSL, and your full email as username.'
            : (err.message || 'Could not connect to the testing service.');
        Swal.fire({
            icon: 'error',
            title: err.name === 'AbortError' ? 'Test Timed Out' : 'Communication Error',
            text: message
        });
    })
    .finally(function () {
        clearTimeout(timeoutId);
    });
}

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
