<?php

declare(strict_types=1);

/**
 * Email settings React UI helpers + get/save payload.
 */

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/email/includes/email_bootstrap.php';

function emailSettingsUiWebBasePath(): string
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

function emailSettingsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return emailSettingsUiWebBasePath() . '/email-settings-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function emailSettingsUiLoadReactAssets(): ?array
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
        'assetBase' => emailSettingsUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function emailSettingsUiShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
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

function emailSettingsUiRequireAdmin(): void
{
    requireAdmin();
    ensureSystemSettingsSchema();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'settings';
}

function emailSettingsUiPdo(): PDO
{
    $settingsPdo = function_exists('email_settings_pdo') ? email_settings_pdo() : null;
    if ($settingsPdo instanceof PDO) {
        return $settingsPdo;
    }
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    throw new RuntimeException('Database connection unavailable.');
}

/**
 * @return array<string,string>
 */
function emailSettingsUiLoadRaw(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_%'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        return is_array($rows) ? array_map('strval', $rows) : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array<string,string> $current
 * @return array<string,mixed>
 */
function emailSettingsUiBuildFormState(array $current): array
{
    $mailConfigPath = dirname(__DIR__, 2) . '/config_mail.php';
    if (is_file($mailConfigPath)) {
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
    $imapSsl = trim((string) ($current['email_imap_ssl'] ?? ''));

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
    if ($imapSsl === '') {
        $imapSsl = 'ssl';
    }
    if ($smtpPort === '') {
        $smtpPort = '465';
    }
    if ($smtpSecure === '') {
        $smtpSecure = 'ssl';
    }

    $systemFromEmail = trim((string) ($current['email_system_from_email'] ?? ''));
    $systemFromName = trim((string) ($current['email_system_from_name'] ?? ''));
    if ($systemFromEmail === '' && defined('SMTP_FROM_EMAIL')) {
        $systemFromEmail = (string) SMTP_FROM_EMAIL;
    }
    if ($systemFromEmail === '' && $smtpUser !== '') {
        $systemFromEmail = $smtpUser;
    }
    if ($systemFromName === '' && defined('SMTP_FROM_NAME')) {
        $systemFromName = (string) SMTP_FROM_NAME;
    }

    return [
        'smtp' => [
            'host' => $smtpHost,
            'port' => $smtpPort,
            'user' => $smtpUser,
            'pass' => '',
            'passSet' => $smtpPass !== '',
            'secure' => $smtpSecure,
        ],
        'systemMail' => [
            'fromEmail' => $systemFromEmail,
            'fromName' => $systemFromName,
            'mailboxPass' => '',
            'mailboxPassSet' => trim((string) ($current['email_system_mailbox_pass'] ?? '')) !== '',
            'syncSmtp' => !array_key_exists('email_system_sync_smtp', $current)
                || trim((string) $current['email_system_sync_smtp']) === '1',
            'useSystemPayroll' => trim((string) ($current['email_use_system_payroll'] ?? '1')) === '1',
            'useSystemSales' => trim((string) ($current['email_use_system_sales'] ?? '1')) === '1',
            'useSystemPurchases' => trim((string) ($current['email_use_system_purchases'] ?? '1')) === '1',
            'useSystemExpenses' => trim((string) ($current['email_use_system_expenses'] ?? '1')) === '1',
            'useSystemCrm' => trim((string) ($current['email_use_system_crm'] ?? '1')) === '1',
            'fromPayroll' => trim((string) ($current['email_from_payroll'] ?? '')),
            'fromPayrollName' => trim((string) ($current['email_from_payroll_name'] ?? '')),
            'fromSales' => trim((string) ($current['email_from_sales'] ?? '')),
            'fromSalesName' => trim((string) ($current['email_from_sales_name'] ?? '')),
            'fromPurchases' => trim((string) ($current['email_from_purchases'] ?? '')),
            'fromPurchasesName' => trim((string) ($current['email_from_purchases_name'] ?? '')),
            'fromExpenses' => trim((string) ($current['email_from_expenses'] ?? '')),
            'fromExpensesName' => trim((string) ($current['email_from_expenses_name'] ?? '')),
            'fromCrm' => trim((string) ($current['email_from_crm'] ?? '')),
            'fromCrmName' => trim((string) ($current['email_from_crm_name'] ?? '')),
        ],
        'imap' => [
            'host' => $imapHost,
            'port' => $imapPort,
            'user' => $imapUser,
            'pass' => '',
            'passSet' => $imapPass !== '',
            'ssl' => $imapSsl,
        ],
        'bridges' => [
            'ultimateEnabled' => trim((string) ($current['email_bridge_ultimate_enabled'] ?? '0')) === '1',
            'ultimateUrl' => trim((string) ($current['email_bridge_ultimate_url'] ?? '')),
            'ultimateApiKey' => '',
            'ultimateApiKeySet' => trim((string) ($current['email_bridge_ultimate_api_key'] ?? '')) !== '',
            'roadmasterEnabled' => trim((string) ($current['email_bridge_roadmaster_enabled'] ?? '0')) === '1',
            'roadmasterUrl' => trim((string) ($current['email_bridge_roadmaster_url'] ?? '')),
            'roadmasterApiKey' => '',
            'roadmasterApiKeySet' => trim((string) ($current['email_bridge_roadmaster_api_key'] ?? '')) !== '',
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function emailSettingsUiGetPayload(PDO $pdo): array
{
    $form = emailSettingsUiBuildFormState(emailSettingsUiLoadRaw($pdo));
    $settingsHubUrl = function_exists('company_url')
        ? company_url('admin/settings.php?module=settings')
        : (function_exists('app_url') ? app_url('/admin/settings.php?module=settings') : '../settings.php?module=settings');
    $testApiUrl = function_exists('company_url')
        ? company_url('admin/api/test_mail_config.php?module=settings')
        : (function_exists('app_url') ? app_url('/admin/api/test_mail_config.php?module=settings') : 'api/test_mail_config.php?module=settings');

    return [
        'form' => $form,
        'links' => [
            'settingsHub' => $settingsHubUrl,
            'testApi' => $testApiUrl,
            'self' => function_exists('company_url')
                ? company_url('admin/email-settings.php?module=settings')
                : (function_exists('app_url') ? app_url('/admin/email-settings.php?module=settings') : 'email-settings.php?module=settings'),
        ],
        'meta' => [
            'companySlug' => (string) ($_SESSION['company_slug'] ?? ($_GET['company_slug'] ?? '')),
            'companyName' => defined('COMPANY_NAME') ? (string) COMPANY_NAME : '',
            'appHost' => preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')),
        ],
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{message:string,data:array<string,mixed>}
 */
function emailSettingsUiSave(PDO $pdo, array $payload): array
{
    $smtp = is_array($payload['smtp'] ?? null) ? $payload['smtp'] : [];
    $system = is_array($payload['systemMail'] ?? null) ? $payload['systemMail'] : [];
    $imap = is_array($payload['imap'] ?? null) ? $payload['imap'] : [];
    $bridges = is_array($payload['bridges'] ?? null) ? $payload['bridges'] : [];

    $settings = [
        'email_smtp_host' => trim((string) ($smtp['host'] ?? '')),
        'email_smtp_port' => trim((string) ($smtp['port'] ?? '465')),
        'email_smtp_user' => trim((string) ($smtp['user'] ?? '')),
        'email_smtp_secure' => trim((string) ($smtp['secure'] ?? 'ssl')),
        'email_imap_host' => trim((string) ($imap['host'] ?? '')),
        'email_imap_port' => trim((string) ($imap['port'] ?? '993')),
        'email_imap_user' => trim((string) ($imap['user'] ?? '')),
        'email_imap_ssl' => trim((string) ($imap['ssl'] ?? 'ssl')),
        'email_bridge_ultimate_url' => rtrim(trim((string) ($bridges['ultimateUrl'] ?? '')), '/'),
        'email_bridge_roadmaster_url' => rtrim(trim((string) ($bridges['roadmasterUrl'] ?? '')), '/'),
        'email_system_from_email' => trim((string) ($system['fromEmail'] ?? '')),
        'email_system_from_name' => trim((string) ($system['fromName'] ?? '')),
        'email_system_sync_smtp' => !empty($system['syncSmtp']) ? '1' : '0',
        'email_use_system_payroll' => !empty($system['useSystemPayroll']) ? '1' : '0',
        'email_use_system_sales' => !empty($system['useSystemSales']) ? '1' : '0',
        'email_use_system_purchases' => !empty($system['useSystemPurchases']) ? '1' : '0',
        'email_use_system_expenses' => !empty($system['useSystemExpenses']) ? '1' : '0',
        'email_use_system_crm' => !empty($system['useSystemCrm']) ? '1' : '0',
        'email_from_payroll' => trim((string) ($system['fromPayroll'] ?? '')),
        'email_from_payroll_name' => trim((string) ($system['fromPayrollName'] ?? '')),
        'email_from_sales' => trim((string) ($system['fromSales'] ?? '')),
        'email_from_sales_name' => trim((string) ($system['fromSalesName'] ?? '')),
        'email_from_purchases' => trim((string) ($system['fromPurchases'] ?? '')),
        'email_from_purchases_name' => trim((string) ($system['fromPurchasesName'] ?? '')),
        'email_from_expenses' => trim((string) ($system['fromExpenses'] ?? '')),
        'email_from_expenses_name' => trim((string) ($system['fromExpensesName'] ?? '')),
        'email_from_crm' => trim((string) ($system['fromCrm'] ?? '')),
        'email_from_crm_name' => trim((string) ($system['fromCrmName'] ?? '')),
    ];

    $existing = emailSettingsUiLoadRaw($pdo);

    $smtpPassPosted = (string) ($smtp['pass'] ?? '');
    if ($smtpPassPosted !== '') {
        $settings['email_smtp_pass'] = $smtpPassPosted;
    }
    $imapPassPosted = (string) ($imap['pass'] ?? '');
    if ($imapPassPosted !== '') {
        $settings['email_imap_pass'] = $imapPassPosted;
    }
    $systemMailboxPass = (string) ($system['mailboxPass'] ?? '');
    if ($systemMailboxPass !== '') {
        $settings['email_system_mailbox_pass'] = $systemMailboxPass;
    }

    if (!empty($system['syncSmtp']) && $settings['email_system_from_email'] !== '') {
        $settings['email_smtp_user'] = $settings['email_system_from_email'];
        if ($systemMailboxPass !== '') {
            $settings['email_smtp_pass'] = $systemMailboxPass;
        } elseif (!empty($existing['email_system_mailbox_pass'])) {
            $settings['email_smtp_pass'] = (string) $existing['email_system_mailbox_pass'];
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

    $ultimateKey = trim((string) ($bridges['ultimateApiKey'] ?? ''));
    if ($ultimateKey !== '') {
        $settings['email_bridge_ultimate_api_key'] = $ultimateKey;
    }
    $roadmasterKey = trim((string) ($bridges['roadmasterApiKey'] ?? ''));
    if ($roadmasterKey !== '') {
        $settings['email_bridge_roadmaster_api_key'] = $roadmasterKey;
    }

    $ultKeyFinal = $ultimateKey !== '' ? $ultimateKey : trim((string) ($existing['email_bridge_ultimate_api_key'] ?? ''));
    $rmKeyFinal = $roadmasterKey !== '' ? $roadmasterKey : trim((string) ($existing['email_bridge_roadmaster_api_key'] ?? ''));

    $settings['email_bridge_ultimate_enabled'] = (
        !empty($bridges['ultimateEnabled'])
        || ($settings['email_bridge_ultimate_url'] !== '' && $ultKeyFinal !== '')
    ) ? '1' : '0';
    $settings['email_bridge_roadmaster_enabled'] = (
        !empty($bridges['roadmasterEnabled'])
        || ($settings['email_bridge_roadmaster_url'] !== '' && $rmKeyFinal !== '')
    ) ? '1' : '0';

    if (empty($bridges['ultimateEnabled']) && $settings['email_bridge_ultimate_url'] === '') {
        $settings['email_bridge_ultimate_enabled'] = '0';
    }
    if (empty($bridges['roadmasterEnabled']) && $settings['email_bridge_roadmaster_url'] === '') {
        $settings['email_bridge_roadmaster_enabled'] = '0';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($settings as $key => $val) {
        $stmt->execute([$key, $val]);
    }

    return [
        'message' => 'All email settings updated successfully.',
        'data' => emailSettingsUiGetPayload($pdo),
    ];
}

/**
 * @param array<string,mixed> $cfg
 */
function emailSettingsRenderReactShell(array $cfg = []): void
{
    $assets = emailSettingsUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Email Settings</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Email Configuration</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>admin/email-settings-ui/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Email Configuration';
    $employeeHeaderTitle = 'Email Configuration';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($cfgJson === false) {
        $cfgJson = '{}';
    }

    require __DIR__ . '/react-shell.php';
    exit;
}
