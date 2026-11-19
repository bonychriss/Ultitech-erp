<?php
declare(strict_types=1);

/**
 * New Accounting module — hosted inside ERP (sidebar + chrome).
 * Classic hub remains available for rollback until cutover.
 *
 * URL: /{company}/accounting/ultitech-bridge
 */
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ultitech_accounting_bridge.php';
requireLogin();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

if (!(function_exists('isAdmin') && isAdmin()) && !(function_exists('isFinance') && isFinance())) {
    $_SESSION['error'] = 'Access denied.';
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $dest = $slug !== '' && function_exists('company_url')
        ? company_url('select-module', $slug)
        : app_url('/select-module.php');
    header('Location: ' . $dest);
    exit;
}

$mode = ultitech_accounting_mode();
if ($mode === 'off') {
    $_SESSION['error'] = 'New Accounting is turned off. Set ULTITECH_ACCOUNTING_MODE=parallel (or primary) to enable.';
    header('Location: ' . ultitech_accounting_classic_path());
    exit;
}

$_GET['module'] = 'accounting';
$_SESSION['active_module'] = 'accounting';

$slug = trim((string) ($_SESSION['company_slug'] ?? (function_exists('getRequestedCompanySlug') ? getRequestedCompanySlug() : '')));
$companyId = (int) (function_exists('currentCompanyId') ? currentCompanyId() : ($_SESSION['company_id'] ?? 0));
$companyName = trim((string) ($_SESSION['company_name'] ?? ''));
if ($companyName === '' && function_exists('getCurrentCompany')) {
    $row = getCurrentCompany();
    if (is_array($row)) {
        $companyName = trim((string) ($row['company_name'] ?? $row['name'] ?? ''));
    }
}

$erpDb = '';
try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $erpDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $erpDb = defined('DATA_DB_NAME') ? (string) DATA_DB_NAME : '';
}

$classicUrl = ultitech_accounting_classic_path($slug);
$logoUrl = '';
if (function_exists('getCompanyLogoUrl')) {
    try {
        $logoUrl = trim((string) getCompanyLogoUrl($companyId > 0 ? $companyId : null));
    } catch (Throwable $e) {
        $logoUrl = '';
    }
}

$now = time();
$claims = [
    'erp_user_id' => $userId,
    'username' => (string) ($_SESSION['username'] ?? ''),
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'email' => (string) ($_SESSION['email'] ?? ''),
    'role' => (string) ($_SESSION['role'] ?? ''),
    'erp_company_id' => $companyId,
    'company_slug' => $slug,
    'company_name' => $companyName !== '' ? $companyName : $slug,
    'company_logo_url' => $logoUrl,
    'erp_db' => $erpDb,
    'back_url' => $classicUrl,
    'iat' => $now,
    'exp' => $now + 180,
    'nonce' => bin2hex(random_bytes(8)),
];

$exchange = ultitech_accounting_exchange_session($claims);
if (! ($exchange['ok'] ?? false)) {
    http_response_code(503);
    $err = htmlspecialchars((string) ($exchange['error'] ?? 'Unknown bridge error'), ENT_QUOTES, 'UTF-8');
    $classic = htmlspecialchars($classicUrl, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><body style="font-family:Poppins,sans-serif;padding:2rem;max-width:40rem;background:#f8fafc;color:#0f172a">';
    echo '<h1 style="font-weight:500;font-size:1.35rem">New Accounting unavailable</h1>';
    echo '<p style="color:#64748b">' . $err . '</p>';
    echo '<p>Check that New Accounting is deployed (<code>ultitech-accounting/backend</code>) and <code>ULTITECH_ACCOUNTING_BRIDGE_SECRET</code> matches on ERP and Accounting. Classic Accounting still works.</p>';
    echo '<p><a href="' . $classic . '" style="color:#5c59f0">Open classic Accounting</a></p>';
    echo '</body></html>';
    exit;
}

/** @var array<string,mixed> $data */
$data = $exchange['data'] ?? [];
$embed = is_array($data['embed'] ?? null) ? $data['embed'] : [];

$resolvedCompanyId = (int) ($data['company_id'] ?? 0);
if ($resolvedCompanyId <= 0 && is_array($data['companies'] ?? null)) {
    foreach ($data['companies'] as $row) {
        if (! empty($row['is_default']) && ! empty($row['id'])) {
            $resolvedCompanyId = (int) $row['id'];
            break;
        }
    }
    if ($resolvedCompanyId <= 0 && ! empty($data['companies'][0]['id'])) {
        $resolvedCompanyId = (int) $data['companies'][0]['id'];
    }
}

$proxyApiBase = function_exists('ultitech_accounting_browser_api_base')
    ? ultitech_accounting_browser_api_base()
    : null;
$upstreamApiBase = ultitech_accounting_api_base() . '/api/v1';

$institutions = [];
try {
    $balancesFn = dirname(__DIR__) . '/modules/balances/functions.php';
    if (! function_exists('balances_institutions') && is_file($balancesFn)) {
        require_once $balancesFn;
    }
    if (function_exists('balances_institutions')) {
        global $pdo;
        $rawInstitutions = balances_institutions($pdo instanceof PDO ? $pdo : null);
        foreach ($rawInstitutions as $inst) {
            if (! is_array($inst)) {
                continue;
            }
            $logoPath = trim((string) ($inst['logo'] ?? ''));
            $logoUrl = '';
            if ($logoPath !== '' && function_exists('balancesAccountImageUrl')) {
                $logoUrl = balancesAccountImageUrl($logoPath);
            } elseif ($logoPath !== '' && function_exists('app_url')) {
                $logoUrl = app_url('/' . ltrim($logoPath, '/'));
            }
            $institutions[] = [
                'slug' => (string) ($inst['slug'] ?? ''),
                'name' => (string) ($inst['name'] ?? ''),
                'type' => (string) ($inst['type'] ?? 'bank'),
                'aliases' => array_values(array_map('strval', is_array($inst['aliases'] ?? null) ? $inst['aliases'] : [])),
                'logo_url' => $logoUrl,
            ];
        }
    }
} catch (Throwable $e) {
    $institutions = [];
}

$GLOBALS['ULTITECH_ACCOUNTING_BOOT'] = [
    'hostedInErp' => true,
    // Same-origin proxy first so the browser never depends on CORS/Authorization quirks.
    'apiBase' => is_string($proxyApiBase) && $proxyApiBase !== '' ? $proxyApiBase : $upstreamApiBase,
    'proxyApiBase' => $proxyApiBase,
    'upstreamApiBase' => $upstreamApiBase,
    'token' => (string) ($data['token'] ?? ''),
    'user' => $data['user'] ?? null,
    'companies' => $data['companies'] ?? [],
    'companyId' => $resolvedCompanyId > 0 ? $resolvedCompanyId : null,
    'company_id' => $resolvedCompanyId > 0 ? $resolvedCompanyId : null,
    'bookId' => isset($data['book_id']) ? (int) $data['book_id'] : null,
    'classicAccountingUrl' => $classicUrl,
    'prefetch' => is_array($data['prefetch'] ?? null) ? $data['prefetch'] : [
        'bank_accounts' => [],
        'cash_accounts' => [],
        'accounts' => [],
    ],
    'institutions' => $institutions,
    'embed' => [
        'mode' => 'erp',
        'back_url' => $classicUrl,
        'erp_company_id' => $embed['erp_company_id'] ?? $companyId,
        'erp_company_slug' => $embed['erp_company_slug'] ?? $slug,
        'erp_company_name' => $embed['erp_company_name'] ?? $companyName,
        'erp_db' => $embed['erp_db'] ?? $erpDb,
        'company_logo_url' => $embed['company_logo_url'] ?? $logoUrl,
        'locked_company' => true,
    ],
];

$GLOBALS['ERP_ACCOUNTING_CONTEXT'] = [
    'user_id' => $userId,
    'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    'company_id' => $companyId,
    'company_slug' => $slug,
    'back_url' => $classicUrl,
    'classic_accounting_url' => $classicUrl,
    'app_root' => rtrim((string) (function_exists('app_url') ? app_url('/') : '/public_html'), '/'),
    'db_name' => $erpDb,
    'is_admin' => function_exists('isAdmin') && isAdmin(),
    'is_finance' => function_exists('isFinance') && isFinance(),
];
$GLOBALS['ERP_CONTEXT'] = $GLOBALS['ERP_ACCOUNTING_CONTEXT'];
$GLOBALS['ERP_CONTEXT']['module'] = 'accounting';
$GLOBALS['ERP_ROUTE'] = '/accounting/ultitech';
$GLOBALS['ERP_ACCOUNTING_ROUTE'] = '/accounting/ultitech';

$laravelRoot = dirname(__DIR__) . '/erp-laravel';
$laravelAutoload = $laravelRoot . '/vendor/autoload.php';
if (! is_file($laravelAutoload)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem"><h1>erp-laravel required</h1></body></html>';
    exit;
}

require $laravelRoot . '/bootstrap/erp-bridge.php';
exit;
