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
 * Active company display name for outbound From headers.
 */
function mailer_active_company_name(): string
{
    $pick = static function ($value): string {
        $name = trim((string) $value);
        if ($name === '') {
            return '';
        }
        // Ignore mailbox/system labels that are not a company brand.
        $key = strtolower(preg_replace('/\s+/', '', $name) ?? $name);
        if (in_array($key, ['system', 'systeminfo', 'systeminformation', 'smtp', 'mailer', 'noreply', 'no-reply'], true)) {
            return '';
        }
        return $name;
    };

    if (defined('COMPANY_NAME')) {
        $name = $pick(COMPANY_NAME);
        if ($name !== '') {
            return $name;
        }
    }

    $sessionName = $pick($_SESSION['company_name'] ?? '');
    if ($sessionName !== '') {
        return $sessionName;
    }

    if (function_exists('getCurrentCompany')) {
        try {
            $company = getCurrentCompany();
            if (is_array($company)) {
                foreach (['company_name', 'legal_name', 'name'] as $key) {
                    $name = $pick($company[$key] ?? '');
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    if (function_exists('getCompanySetting')) {
        foreach (['company_name', 'legal_name'] as $key) {
            $name = $pick(getCompanySetting($key, ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    // Direct companies-table lookup by session/request company id or slug.
    global $pdo, $control_pdo;
    $meta = ($control_pdo instanceof PDO) ? $control_pdo : (($pdo instanceof PDO) ? $pdo : null);
    if ($meta instanceof PDO && function_exists('tableExists') && tableExists('companies', $meta)) {
        $cid = 0;
        try {
            $cid = (int) (function_exists('currentCompanyId') ? (currentCompanyId() ?? 0) : 0);
        } catch (Throwable $e) {
            $cid = 0;
        }
        if ($cid <= 0) {
            $cid = (int) ($_SESSION['company_id'] ?? $_GET['company_id'] ?? 0);
        }
        $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? $_GET['company_slug'] ?? '')));
        if ($slug === '' && function_exists('getRequestedCompanySlug')) {
            $slug = strtolower(trim((string) getRequestedCompanySlug()));
        }

        try {
            $row = null;
            if ($cid > 0) {
                $stmt = $meta->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
                $stmt->execute([$cid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$row && $slug !== '') {
                if (function_exists('columnExists') && columnExists('companies', 'company_slug', $meta)) {
                    $stmt = $meta->prepare('SELECT * FROM companies WHERE company_slug = ? LIMIT 1');
                    $stmt->execute([$slug]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } elseif (function_exists('columnExists') && columnExists('companies', 'slug', $meta)) {
                    $stmt = $meta->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
                    $stmt->execute([$slug]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
            if (is_array($row)) {
                foreach (['company_name', 'legal_name', 'name'] as $key) {
                    $name = $pick($row[$key] ?? '');
                    if ($name !== '') {
                        return $name;
                    }
                }
            }
        } catch (Throwable $e) {
            // continue
        }

        // Letterhead defaults by slug as last branded fallback.
        if ($slug !== '') {
            if (!function_exists('letterheadResolveForCompany')) {
                $letterheadFile = __DIR__ . '/letterhead.php';
                if (is_file($letterheadFile)) {
                    require_once $letterheadFile;
                }
            }
            if (function_exists('letterheadResolveForCompany')) {
                try {
                    $letterhead = letterheadResolveForCompany($slug, null);
                    $name = $pick($letterhead['defaults']['companyName'] ?? '');
                    if ($name !== '') {
                        return $name;
                    }
                } catch (Throwable $e) {
                    // continue
                }
            }
        }
    }

    return '';
}

/**
 * Resolve From email/name for system mail by module.
 *
 * From email stays the configured mailbox. From display name prefers the
 * active company name (e.g. ULTIMATE GENERAL TRADING) when available.
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
    $companyName = mailer_active_company_name();

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
            // Explicitly off: do not fall back to system From for this module.
            if ($useSystem === '0') {
                if ($email === '') {
                    $email = mailer_get_setting('email_smtp_user');
                }
                if ($email === '' && defined('SMTP_FROM_EMAIL')) {
                    $email = (string) SMTP_FROM_EMAIL;
                }
                if ($companyName !== '') {
                    $name = $companyName;
                } elseif ($name === '') {
                    if (defined('SMTP_FROM_NAME')) {
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

    // Prefer branded company display name over mailbox label (e.g. "system info").
    if ($companyName !== '') {
        $name = $companyName;
    } elseif ($name === '') {
        if (defined('SMTP_FROM_NAME')) {
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
    $GLOBALS['MAILER_LAST_ERROR'] = '';
    try {
        $transport = resolveSystemMailTransport();
        if (trim((string) ($transport['host'] ?? '')) === '') {
            throw new Exception('SMTP host is not configured.');
        }
        if (trim((string) ($transport['user'] ?? '')) === '') {
            throw new Exception('SMTP username is not configured.');
        }
        if ((string) ($transport['pass'] ?? '') === '') {
            throw new Exception('SMTP password is not configured.');
        }

        $from = resolveSystemMailFrom(is_string($module) ? $module : null);
        if (trim((string) ($from['email'] ?? '')) === '') {
            throw new Exception('System From email is not configured.');
        }
        $brandName = mailer_active_company_name();
        if ($brandName !== '') {
            $from['name'] = $brandName;
        }

        $mail = new SimpleSMTP(
            $transport['host'],
            $transport['port'],
            $transport['user'],
            $transport['pass'],
            $transport['secure']
        );

        $hasAttachments = is_array($attachments) && $attachments !== [];
        $mail->setTimeouts(
            $hasAttachments ? 20 : 15,
            $hasAttachments ? 120 : 60
        );

        $ok = $mail->send(
            $from['email'],
            $from['name'],
            $to,
            $subject,
            $body,
            $isHtml,
            $attachments,
            isset($GLOBALS['MAILER_TEXT_BODY']) ? (string) $GLOBALS['MAILER_TEXT_BODY'] : null
        );
        if (!$ok) {
            $GLOBALS['MAILER_LAST_ERROR'] = 'SMTP send returned failure.';
            $GLOBALS['MAILER_LAST_QUEUE_ID'] = '';
            $GLOBALS['MAILER_LAST_RESPONSE'] = '';
            return false;
        }
        $GLOBALS['MAILER_LAST_QUEUE_ID'] = method_exists($mail, 'getLastQueueId') ? $mail->getLastQueueId() : '';
        $GLOBALS['MAILER_LAST_RESPONSE'] = method_exists($mail, 'getLastResponse') ? $mail->getLastResponse() : '';
        return true;
    } catch (Exception $e) {
        $GLOBALS['MAILER_LAST_ERROR'] = $e->getMessage();
        $errorMsg = date('Y-m-d H:i:s') . ' - Mailer Error: ' . $e->getMessage() . "\n";
        file_put_contents(__DIR__ . '/../debug_mail_error.txt', $errorMsg, FILE_APPEND);
        error_log('Mailer Error: ' . $e->getMessage());
        return false;
    }
}

function mailer_last_error(): string
{
    return trim((string) ($GLOBALS['MAILER_LAST_ERROR'] ?? ''));
}

function mailer_last_queue_id(): string
{
    return trim((string) ($GLOBALS['MAILER_LAST_QUEUE_ID'] ?? ''));
}

function mailer_last_response(): string
{
    return trim((string) ($GLOBALS['MAILER_LAST_RESPONSE'] ?? ''));
}
