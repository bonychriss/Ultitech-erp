<?php
$mailConfigFile = dirname(__DIR__) . '/config_mail.php';
if (is_file($mailConfigFile)) {
    require_once $mailConfigFile;
}
require_once __DIR__ . '/SimpleSMTP.php';

/**
 * Read a system_settings value from the current tenant PDO when available.
 */
function mailer_get_setting(string $key): string
{
    static $cache = null;
    global $pdo;

    if ($cache === null) {
        $cache = [];
        try {
            if ($pdo instanceof PDO) {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'email_%'");
                if ($stmt) {
                    $cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
                }
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }

    return trim((string) ($cache[$key] ?? ''));
}

/**
 * Resolve From email/name for system mail by module.
 *
 * @return array{email:string,name:string}
 */
function resolveSystemMailFrom(?string $module = null): array
{
    $module = strtolower(trim((string) $module));
    $map = [
        'payroll' => ['email_from_payroll', 'email_from_payroll_name', 'email_use_system_payroll'],
        'sales' => ['email_from_sales', 'email_from_sales_name', 'email_use_system_sales'],
        'purchases' => ['email_from_purchases', 'email_from_purchases_name', 'email_use_system_purchases'],
        'purchase' => ['email_from_purchases', 'email_from_purchases_name', 'email_use_system_purchases'],
        'expenses' => ['email_from_expenses', 'email_from_expenses_name', 'email_use_system_expenses'],
        'expense' => ['email_from_expenses', 'email_from_expenses_name', 'email_use_system_expenses'],
        'crm' => ['email_from_crm', 'email_from_crm_name', 'email_use_system_crm'],
    ];

    $systemEmail = mailer_get_setting('email_system_from_email');
    $systemName = mailer_get_setting('email_system_from_name');

    $email = '';
    $name = '';
    if ($module !== '' && isset($map[$module])) {
        $useKey = $map[$module][2];
        $useSystem = mailer_get_setting($useKey);
        // Default: use system mailbox when the flag was never saved.
        $preferSystem = ($useSystem === '' || $useSystem === '1');

        if ($preferSystem && $systemEmail !== '') {
            $email = $systemEmail;
            $name = $systemName;
        } else {
            $email = mailer_get_setting($map[$module][0]);
            $name = mailer_get_setting($map[$module][1]);
        }
    }

    if ($email === '') {
        $email = $systemEmail;
    }
    if ($name === '') {
        $name = $systemName;
    }

    if ($email === '') {
        $email = mailer_get_setting('email_smtp_user');
    }
    if ($email === '' && defined('SMTP_FROM_EMAIL')) {
        $email = (string) SMTP_FROM_EMAIL;
    }
    if ($email === '' && defined('SMTP_USER')) {
        $email = (string) SMTP_USER;
    }

    if ($name === '') {
        if (defined('COMPANY_NAME') && COMPANY_NAME !== '') {
            $name = (string) COMPANY_NAME;
        } elseif (defined('SMTP_FROM_NAME')) {
            $name = (string) SMTP_FROM_NAME;
        } else {
            $name = 'System';
        }
    }

    return [
        'email' => $email,
        'name' => $name,
    ];
}

/**
 * Resolve SMTP transport settings (company DB first, then config_mail.php).
 *
 * @return array{host:string,port:int,user:string,pass:string,secure:string}
 */
function resolveSystemMailTransport(): array
{
    $host = mailer_get_setting('email_smtp_host');
    $port = mailer_get_setting('email_smtp_port');
    $user = mailer_get_setting('email_smtp_user');
    $pass = mailer_get_setting('email_smtp_pass');
    $secure = mailer_get_setting('email_smtp_secure');

    if ($host === '' && defined('SMTP_HOST')) {
        $host = (string) SMTP_HOST;
    }
    if ($port === '' && defined('SMTP_PORT')) {
        $port = (string) SMTP_PORT;
    }
    if ($user === '' && defined('SMTP_USER')) {
        $user = (string) SMTP_USER;
    }
    if ($pass === '' && defined('SMTP_PASS')) {
        $pass = (string) SMTP_PASS;
    }
    if ($secure === '' && defined('SMTP_SECURE')) {
        $secure = (string) SMTP_SECURE;
    } elseif ($secure === '' && defined('SMTP_SECURE')) {
        $secure = (string) SMTP_SECURE;
    }
    if ($port === '') {
        $port = '465';
    }
    if ($secure === '') {
        $secure = 'ssl';
    }

    return [
        'host' => $host,
        'port' => (int) $port,
        'user' => $user,
        'pass' => $pass,
        'secure' => $secure,
    ];
}

/**
 * @param array<int, mixed> $attachments
 */
function sendEmail($to, $subject, $body, $isHtml = true, $attachments = [], $module = null)
{
    try {
        $transport = resolveSystemMailTransport();
        $from = resolveSystemMailFrom(is_string($module) ? $module : null);

        $mail = new SimpleSMTP(
            $transport['host'],
            $transport['port'],
            $transport['user'],
            $transport['pass'],
            $transport['secure']
        );

        return $mail->send(
            $from['email'],
            $from['name'],
            $to,
            $subject,
            $body,
            $isHtml,
            $attachments
        );
    } catch (Exception $e) {
        $errorMsg = date('Y-m-d H:i:s') . ' - Mailer Error: ' . $e->getMessage() . "\n";
        file_put_contents(__DIR__ . '/../debug_mail_error.txt', $errorMsg, FILE_APPEND);
        error_log('Mailer Error: ' . $e->getMessage());
        return false;
    }
}
