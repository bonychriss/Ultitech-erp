<?php
define('ERP_SKIP_SYSTEM_FONT_OB', true);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/modules/email/includes/email_bootstrap.php';
require_once __DIR__ . '/my-account-ui/lib.php';

if (isLoggedIn()) {
    $profileUrl = function_exists('user_profile_settings_url')
        ? user_profile_settings_url()
        : app_url('/employee/account.php');
    if (!headers_sent()) {
        header('Location: ' . $profileUrl, true, 302);
        exit;
    }
}

$controlDb = $GLOBALS['control_pdo'] ?? $GLOBALS['pdo'] ?? null;
$isAuthed = isLoggedIn();
$displayName = $isAuthed ? (string) ($_SESSION['full_name'] ?? 'My Account') : 'Guest Account';
$sessionCompanyId = $isAuthed ? (int) (currentCompanyId() ?: 0) : 0;

$selectedCompany = null;
$payments = [];
$companiesReady = false;

if ($isAuthed && $controlDb instanceof PDO && $sessionCompanyId > 0 && function_exists('tableExists') && tableExists('companies', $controlDb)) {
    try {
        $sql = 'SELECT id, company_name, company_slug, db_name, status, updated_at FROM companies WHERE id = ? LIMIT 1';
        $stmtCompany = $controlDb->prepare($sql);
        $stmtCompany->execute([$sessionCompanyId]);
        $selectedCompany = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: null;
        $companiesReady = !empty($selectedCompany);
    } catch (Throwable $e) {
        $selectedCompany = null;
        $companiesReady = false;
    }
}

$accountRowName = (string) ($selectedCompany['company_name'] ?? $displayName);
$accountTitle = strtoupper($accountRowName);
$accountSlug = strtolower(trim((string) ($selectedCompany['company_slug'] ?? '')));
$accountUrl = $accountSlug !== '' ? app_url('/' . $accountSlug . '/login') : app_url('/login.php');
$enterWorkspaceUrl = $isAuthed
    ? ($accountSlug !== '' ? company_url('select-module', $accountSlug) : app_url('/select-module.php'))
    : app_url('/login.php?next=my-account.php');
$accountStatus = strtolower(trim((string) ($selectedCompany['status'] ?? 'active'))) ?: 'active';
$accountUpdated = !empty($selectedCompany['updated_at']) ? date('d F Y', strtotime((string) $selectedCompany['updated_at'])) : date('d F Y');

if ($isAuthed && $controlDb instanceof PDO && function_exists('tableExists') && tableExists('payment_vouchers', $controlDb)) {
    try {
        $hasDateCreated = columnExists('payment_vouchers', 'date_created', $controlDb);
        $hasCreatedAt = columnExists('payment_vouchers', 'created_at', $controlDb);
        $hasVoucherNo = columnExists('payment_vouchers', 'voucher_no', $controlDb);
        $hasAmount = columnExists('payment_vouchers', 'total_amount', $controlDb);
        $hasStatus = columnExists('payment_vouchers', 'status', $controlDb);
        $hasCompanyId = columnExists('payment_vouchers', 'company_id', $controlDb);

        $selectDate = $hasDateCreated ? 'date_created' : ($hasCreatedAt ? 'created_at' : 'NULL');
        $selectVoucher = $hasVoucherNo ? 'voucher_no' : 'CONCAT("PV-", id)';
        $selectAmount = $hasAmount ? 'total_amount' : '0';
        $selectStatus = $hasStatus ? 'status' : "'posted'";

        $sql = "SELECT id, {$selectVoucher} AS voucher_no, {$selectDate} AS payment_date, {$selectAmount} AS amount, {$selectStatus} AS payment_status FROM payment_vouchers";
        $params = [];
        if ($hasCompanyId && $sessionCompanyId > 0) {
            if (function_exists('companyScopeSql')) {
                list($scopeFrag, $scopeParams) = companyScopeSql('payment_vouchers', '', $controlDb);
                if ($scopeFrag !== '') {
                    $sql .= ' WHERE 1=1' . $scopeFrag;
                    $params = $scopeParams;
                }
            } else {
                $sql .= ' WHERE company_id = ?';
                $params[] = $sessionCompanyId;
            }
        } elseif (!(defined('IS_TENANT_DB') && IS_TENANT_DB)) {
            $payments = [];
            $sql = '';
        }
        if ($sql !== '') {
            $sql .= ' ORDER BY id DESC LIMIT 12';
            $stmt = $controlDb->prepare($sql);
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $payments = [];
    }
}

$paymentRows = [];
foreach ($payments as $payment) {
    $paymentDateRaw = (string) ($payment['payment_date'] ?? '');
    $paymentId = (int) ($payment['id'] ?? 0);
    $paymentRows[] = [
        'id' => $paymentId,
        'voucher_no' => (string) ($payment['voucher_no'] ?? ''),
        'payment_date' => $paymentDateRaw,
        'payment_date_label' => $paymentDateRaw !== '' ? date('d F Y', strtotime($paymentDateRaw)) : '-',
        'amount' => isset($payment['amount']) ? (float) $payment['amount'] : 0.0,
        'payment_status' => (string) ($payment['payment_status'] ?? ''),
        'receipt_url' => $paymentId > 0 ? app_url('/employee/view-voucher.php?id=' . $paymentId) : '',
    ];
}

$userEmailSettings = [
    'imap_host' => '', 'imap_port' => '993', 'imap_user' => '', 'imap_pass' => '', 'imap_ssl' => 'ssl',
    'smtp_host' => '', 'smtp_port' => '465', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_ssl' => 'ssl',
];
if ($isAuthed) {
    try {
        $emailPdo = email_module_pdo();
        if ($emailPdo) {
            $stmt = $emailPdo->prepare('SELECT * FROM module_email_user_settings WHERE user_id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $userEmailSettings = array_merge($userEmailSettings, $row);
            }
        }
    } catch (Throwable $e) {
    }
}

myAccountUiRenderReactShell([
    'isAuthed' => $isAuthed,
    'homeUrl' => app_url('/'),
    'selfUrl' => app_url('/my-account.php'),
    'loginUrl' => app_url('/login.php'),
    'logoutUrl' => app_url('/logout.php'),
    'registerUrl' => app_url('/register.php'),
    'enterWorkspaceUrl' => $enterWorkspaceUrl,
    'viewAllPaymentsUrl' => app_url('/all-vouchers.php'),
    'saveEmailUrl' => app_url('/api/save_user_email_settings.php'),
    'upgradeUrl' => '#',
    'accountTitle' => $accountTitle,
    'accountName' => $accountRowName,
    'accountUrl' => $accountUrl,
    'accountUpdated' => $accountUpdated,
    'accountStatus' => $accountStatus,
    'companiesReady' => $companiesReady,
    'payments' => $paymentRows,
    'emailSettings' => [
        'imap_host' => (string) ($userEmailSettings['imap_host'] ?? ''),
        'imap_port' => (string) ($userEmailSettings['imap_port'] ?? '993'),
        'imap_user' => (string) ($userEmailSettings['imap_user'] ?? ''),
        'imap_pass' => (string) ($userEmailSettings['imap_pass'] ?? ''),
        'imap_ssl' => (string) ($userEmailSettings['imap_ssl'] ?? 'ssl'),
        'smtp_host' => (string) ($userEmailSettings['smtp_host'] ?? ''),
        'smtp_port' => (string) ($userEmailSettings['smtp_port'] ?? '465'),
        'smtp_user' => (string) ($userEmailSettings['smtp_user'] ?? ''),
        'smtp_pass' => (string) ($userEmailSettings['smtp_pass'] ?? ''),
        'smtp_ssl' => (string) ($userEmailSettings['smtp_ssl'] ?? 'ssl'),
    ],
    'year' => (int) date('Y'),
]);
