<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/balances-guard.php';

$coaEditBackUrl = balances_guard_accounts_url();
balances_bootstrap_or_error('coa_edit.php', [
    'back_url' => $coaEditBackUrl,
    'retry_url' => balances_guard_current_url(),
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

global $pdo;
if (!($pdo instanceof PDO)) {
    balances_render_error_page('Database connection failed.', [
        'title' => 'Page unavailable',
        'back_url' => $coaEditBackUrl,
        'retry_url' => balances_guard_current_url(),
        'log_context' => 'coa_edit',
    ]);
}

function coa_db_type_to_ui_type($dbType)
{
    $t = strtolower(trim((string) $dbType));
    $direct = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cash', 'bank', 'mobile'];
    if (in_array($t, $direct, true)) {
        return $t;
    }
    $bankish = ['bank_transfer', 'wire_transfer', 'online_banking', 'cheque', 'standing_order', 'direct_debit'];
    $cashish = ['cod'];
    $mobileish = ['digital_wallet', 'qr_code', 'ussd', 'payment_gateway'];
    if (in_array($t, $bankish, true)) {
        return 'bank';
    }
    if (in_array($t, $cashish, true)) {
        return 'cash';
    }
    if (in_array($t, $mobileish, true)) {
        return 'mobile';
    }
    if (strpos($t, 'expense') !== false) {
        return 'expense';
    }
    if (strpos($t, 'revenue') !== false || strpos($t, 'income') !== false) {
        return 'revenue';
    }
    if (strpos($t, 'liabil') !== false) {
        return 'liability';
    }
    if (strpos($t, 'equity') !== false) {
        return 'equity';
    }

    return 'asset';
}

function coa_type_to_category_label($uiType)
{
    $t = strtolower(trim((string) $uiType));
    $map = [
        'asset' => 'Asset',
        'cash' => 'Asset',
        'bank' => 'Asset',
        'mobile' => 'Asset',
        'liability' => 'Liability',
        'equity' => 'Equity',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
    ];

    return $map[$t] ?? 'Asset';
}

function coa_ensure_account_image_column(PDO $pdo): void
{
    if (function_exists('columnExists') && !columnExists('financial_accounts', 'account_image', $pdo)) {
        $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN account_image VARCHAR(500) NULL DEFAULT NULL AFTER status');
    }
}

function coa_ensure_opening_date_column(PDO $pdo): void
{
    if (function_exists('columnExists') && !columnExists('financial_accounts', 'opening_date', $pdo)) {
        $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN opening_date DATE NULL DEFAULT NULL AFTER opening_balance');
    }
}

function coa_store_account_image(): string
{
    if (!isset($_FILES['account_image']) || (int) ($_FILES['account_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int) $_FILES['account_image']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Account image upload failed. Please try again.');
    }
    if ((int) $_FILES['account_image']['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Account image must be 2MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) $_FILES['account_image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Account image must be JPG, PNG, WEBP, or GIF.');
    }
    $root = dirname(__DIR__, 2);
    $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'balances' . DIRECTORY_SEPARATOR . 'accounts' . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create upload folder for account image.');
    }
    $fileName = 'ACC_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . $fileName;
    if (!move_uploaded_file($_FILES['account_image']['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save account image.');
    }

    return 'uploads/balances/accounts/' . $fileName;
}

function coa_delete_account_image_file(string $storedPath): void
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || strpos($storedPath, '..') !== false) {
        return;
    }
    $root = dirname(__DIR__, 2);
    $diskPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
    if (is_file($diskPath)) {
        @unlink($diskPath);
    }
}

function coa_account_image_public_url(string $storedPath): string
{
    if (function_exists('balancesAccountImageUrl')) {
        return balancesAccountImageUrl($storedPath);
    }
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || strpos($storedPath, '..') !== false) {
        return '';
    }
    if (function_exists('mediaUrlFromPath')) {
        return mediaUrlFromPath($storedPath, true);
    }
    $root = dirname(__DIR__, 2);
    $diskPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($storedPath, '/'));
    if (!is_file($diskPath)) {
        return '';
    }
    $webPath = ltrim($storedPath, '/');

    return function_exists('app_url') ? app_url('/' . $webPath) : '/' . $webPath;
}

/** @var array<string, array{account_type:string,account_category:string,reporting_group:string,financial_statement:string,normal_balance:string}> */
$categoryOptions = [
    'Revenue' => [
        'account_type' => 'revenue',
        'account_category' => 'Revenue',
        'reporting_group' => 'Sales Revenue',
        'financial_statement' => 'Profit & Loss',
        'normal_balance' => 'credit',
    ],
    'Expense' => [
        'account_type' => 'expense',
        'account_category' => 'Operating Expenses',
        'reporting_group' => 'Operating Expenses',
        'financial_statement' => 'Profit & Loss',
        'normal_balance' => 'debit',
    ],
    'Cost of Goods Sold' => [
        'account_type' => 'expense',
        'account_category' => 'Cost of Goods Sold',
        'reporting_group' => 'Cost of Goods Sold',
        'financial_statement' => 'Profit & Loss',
        'normal_balance' => 'debit',
    ],
    'Asset' => [
        'account_type' => 'asset',
        'account_category' => 'Current Assets',
        'reporting_group' => 'Current Assets',
        'financial_statement' => 'Balance Sheet',
        'normal_balance' => 'debit',
    ],
    'Cost of Service' => [
        'account_type' => 'expense',
        'account_category' => 'Operating Expenses',
        'reporting_group' => 'Operating Expenses',
        'financial_statement' => 'Profit & Loss',
        'normal_balance' => 'debit',
    ],
    'Liability' => [
        'account_type' => 'liability',
        'account_category' => 'Current Liabilities',
        'reporting_group' => 'Current Liabilities',
        'financial_statement' => 'Balance Sheet',
        'normal_balance' => 'credit',
    ],
    'Equity' => [
        'account_type' => 'equity',
        'account_category' => 'Equity',
        'reporting_group' => 'Equity',
        'financial_statement' => 'Balance Sheet',
        'normal_balance' => 'credit',
    ],
];

if (!isAdmin() && !isFinance()) {
    $_SESSION['error'] = 'Access denied.';
    redirect('accounts.php');
}

$accountId = (int) ($_GET['id'] ?? $_POST['account_id'] ?? 0);
if ($accountId <= 0) {
    $_SESSION['error'] = 'Account not found.';
    redirect('accounts.php');
}

$existingAccount = null;
try {
    $loadStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
    $loadStmt->execute([$accountId]);
    $existingAccount = $loadStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $existingAccount = null;
}

if (!$existingAccount) {
    $_SESSION['error'] = 'Account not found.';
    redirect('accounts.php');
}

if (!empty($existingAccount['is_system'])) {
    $_SESSION['error'] = 'System accounts are locked and cannot be edited.';
    $backUrl = 'accounts.php';
    if (!empty($_GET['module'])) {
        $backUrl .= '?module=' . rawurlencode((string) $_GET['module']);
    }
    redirect($backUrl);
}

[$parsedCode, $parsedName] = coa_parse_account_name_parts($existingAccount['name'] ?? '');
$existingDbType = strtolower(trim((string) ($existingAccount['type'] ?? 'asset')));
$existingUiType = coa_db_type_to_ui_type($existingDbType);
$existingStatus = strtolower(trim((string) ($existingAccount['status'] ?? 'active')));
$existingOpening = (float) ($existingAccount['opening_balance'] ?? 0);
$existingCurrency = strtoupper(trim((string) ($existingAccount['currency'] ?? 'TZS')));
$existingAccountImage = trim((string) ($existingAccount['account_image'] ?? ''));
$existingAccountImageUrl = coa_account_image_public_url($existingAccountImage);
$existingOpeningDate = '';
if (!empty($existingAccount['opening_date'])) {
    $ts = strtotime((string) $existingAccount['opening_date']);
    if ($ts !== false) {
        $existingOpeningDate = date('Y-m-d', $ts);
    }
}

$page_title = 'Edit Account';
$accountsBackUrl = 'accounts.php';
if (!empty($_GET['module'])) {
    $accountsBackUrl .= '?module=' . rawurlencode((string) $_GET['module']);
}
$editQuery = 'id=' . (int) $accountId;
if (!empty($_GET['module'])) {
    $editQuery .= '&module=' . rawurlencode((string) $_GET['module']);
}
$formAction = 'coa_edit.php?' . $editQuery;

$currencyCatalog = [
    'TZS' => ['name' => 'Tanzanian Shilling', 'flag' => 'tz'],
    'USD' => ['name' => 'US Dollar', 'flag' => 'us'],
    'EUR' => ['name' => 'Euro', 'flag' => 'eu'],
    'GBP' => ['name' => 'British Pound', 'flag' => 'gb'],
    'KES' => ['name' => 'Kenyan Shilling', 'flag' => 'ke'],
    'UGX' => ['name' => 'Ugandan Shilling', 'flag' => 'ug'],
    'RWF' => ['name' => 'Rwandan Franc', 'flag' => 'rw'],
    'ZAR' => ['name' => 'South African Rand', 'flag' => 'za'],
];


$accountCode = trim((string) ($_POST['account_code'] ?? $parsedCode));
$accountName = trim((string) ($_POST['account_name'] ?? $parsedName));
$selectedCategory = trim((string) ($_POST['category'] ?? coa_type_to_category_label($existingUiType)));
if (!isset($categoryOptions[$selectedCategory])) {
    $selectedCategory = coa_type_to_category_label($existingUiType);
}
$currency = strtoupper(trim((string) ($_POST['currency'] ?? $existingCurrency)));
if (!isset($currencyCatalog[$currency])) {
    $currency = isset($currencyCatalog[$existingCurrency]) ? $existingCurrency : 'TZS';
}
$selectedCurrencyMeta = $currencyCatalog[$currency];
$currencyFlagUrl = static function (string $flagCode): string {
    return 'https://flagcdn.com/w40/' . strtolower($flagCode) . '.png';
};
$openingBalanceDisplay = number_format($existingOpening, 2, '.', '');
$openingDate = trim((string) ($_POST['opening_date'] ?? $existingOpeningDate));
if ($openingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openingDate)) {
    $openingDate = $existingOpeningDate;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($accountCode === '') {
        $accountCode = $parsedCode;
    }
    if ($accountName === '') {
        $_SESSION['error'] = 'Account name is required.';
    } elseif (!isset($categoryOptions[$selectedCategory])) {
        $_SESSION['error'] = 'Please select an account category.';
    } else {
        try {
            $meta = $categoryOptions[$selectedCategory];
            $accountType = $meta['account_type'];
            $balanceSide = (string) ($meta['normal_balance'] ?? coa_normal_balance_side_for_account_type($accountType));
            if (coa_find_account_by_name_and_balance_side($pdo, $accountName, $balanceSide, $accountId) !== null) {
                throw new RuntimeException(coa_duplicate_account_message($accountName, $balanceSide));
            }

            $accountCategory = $meta['account_category'];
            $reportingGroup = $meta['reporting_group'];
            $financialStatement = $meta['financial_statement'];

            if (function_exists('coa_ensure_account_category')) {
                coa_ensure_account_category($pdo, $accountCategory, $accountType, $reportingGroup, $financialStatement);
            }

            $normalizedType = $accountType;
            $nameToSave = ($accountCode !== '' ? $accountCode . ' - ' : '') . $accountName;

            coa_ensure_account_image_column($pdo);
            coa_ensure_opening_date_column($pdo);

            $accountImageToSave = $existingAccountImage;
            $removeAccountImage = isset($_POST['remove_account_image']);
            $newAccountImagePath = coa_store_account_image();
            if ($removeAccountImage) {
                if ($existingAccountImage !== '') {
                    coa_delete_account_image_file($existingAccountImage);
                }
                $accountImageToSave = '';
            } elseif ($newAccountImagePath !== '') {
                if ($existingAccountImage !== '' && $existingAccountImage !== $newAccountImagePath) {
                    coa_delete_account_image_file($existingAccountImage);
                }
                $accountImageToSave = $newAccountImagePath;
            }

            $updateRow = [
                'name' => $nameToSave,
                'type' => $normalizedType,
                'currency' => $currency,
                'opening_date' => $openingDate !== '' ? $openingDate : null,
                'status' => $existingStatus === 'inactive' ? 'inactive' : 'active',
                'account_image' => $accountImageToSave !== '' ? $accountImageToSave : null,
            ];
            $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $setParts = [];
            $setVals = [];
            foreach ($updateRow as $col => $val) {
                if (!in_array($col, $faCols, true)) {
                    continue;
                }
                $setParts[] = $col . ' = ?';
                $setVals[] = $val;
            }
            if ($setParts === []) {
                throw new RuntimeException('Financial accounts table schema is not compatible.');
            }
            $setVals[] = $accountId;
            $stmt = $pdo->prepare(
                'UPDATE financial_accounts SET ' . implode(', ', $setParts) . ' WHERE id = ?'
            );
            $stmt->execute($setVals);

            if (function_exists('recalculateBalance')) {
                recalculateBalance($accountId);
            }

            $_SESSION['success'] = 'Account updated successfully.';
            redirect($accountsBackUrl);
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not update account. ' . $e->getMessage();
        }
    }
}

$sessionError = trim((string) ($_SESSION['error'] ?? ''));
if ($sessionError !== '') {
    unset($_SESSION['error']);
}
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$hasExistingImage = $existingAccountImageUrl !== '';

try {
    include __DIR__ . '/includes/header.php';
} catch (Throwable $e) {
    error_log('coa_edit header: ' . $e->getMessage());
    echo '<div style="padding:12px 24px;background:#fef2f2;color:#b91c1c;">Header could not load: '
        . $esc($e->getMessage()) . '</div>';
}
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    .coa-simple-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1040;
        background: rgba(92, 95, 103, 0.72);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
        font-family: 'Inter', system-ui, sans-serif;
    }
    .coa-simple-modal {
        width: 100%;
        max-width: 720px;
        min-height: 480px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
    }
    .coa-simple-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 28px 32px 4px;
    }
    .coa-simple-head h1 {
        margin: 0;
        font-size: 20px;
        font-weight: 700;
        color: #111827;
    }
    .coa-simple-close {
        color: #9ca3af;
        font-size: 24px;
        line-height: 1;
        text-decoration: none;
        padding: 4px;
    }
    .coa-simple-close:hover { color: #4b5563; }
    .coa-simple-body { padding: 24px 32px 36px; }
    .coa-simple-alert {
        margin-bottom: 16px;
        padding: 10px 12px;
        border-radius: 8px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 13px;
    }
    .coa-field { margin-bottom: 24px; }
    .coa-field:last-child { margin-bottom: 0; }
    .coa-field-top {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 8px;
    }
    .coa-field label,
    .coa-field-top label {
        font-size: 14px;
        font-weight: 500;
        color: #111827;
    }
    .coa-field > label { display: block; margin-bottom: 8px; }
    .coa-field .req { color: #ef4444; }
    .coa-char-count { font-size: 12px; color: #9ca3af; font-weight: 400; }
    .coa-name-block { display: block; }
    .coa-identity-grid {
        display: grid;
        grid-template-columns: 56px minmax(0, 1fr);
        gap: 12px;
        align-items: center;
    }
    .coa-image-picker {
        position: relative;
        display: block;
        width: 56px;
        height: 56px;
    }
    .coa-image-picker input[type="file"] {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
        z-index: 2;
    }
    .coa-image-thumb {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: 1px dashed #d1d5db;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        color: #3b82f6;
        font-size: 17px;
        line-height: 1;
        pointer-events: none;
    }
    .coa-image-thumb img {
        display: none;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .coa-image-thumb.has-image {
        border-style: solid;
        border-color: #d1d5db;
    }
    .coa-image-thumb.has-image img {
        display: block;
    }
    .coa-image-thumb.has-image i {
        display: none;
    }
    .coa-image-hint {
        margin-top: 10px;
        font-size: 12px;
        color: #9ca3af;
        line-height: 1.45;
    }
    .coa-image-remove {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        font-size: 12px;
        color: #6b7280;
        cursor: pointer;
    }
    .coa-image-remove input {
        width: 14px;
        height: 14px;
        cursor: pointer;
    }
    .coa-input,
    .coa-select {
        width: 100%;
        padding: 13px 14px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        color: #111827;
        background: #fff;
    }
    .coa-input::placeholder { color: #9ca3af; }
    .coa-input-readonly {
        background: #f9fafb;
        color: #6b7280;
        cursor: not-allowed;
    }
    .coa-input:focus,
    .coa-select:focus {
        outline: none;
        border-color: #a78bfa;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }
    .coa-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='none' stroke='%239ca3af' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='M5 8l5 5 5-5'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        padding-right: 36px;
        cursor: pointer;
    }
    .coa-select:invalid { color: #9ca3af; }
    .coa-select option {
        color: #111827;
        background: #fff;
    }
    .coa-select option:disabled {
        color: #111827;
    }
    .currency-picker { position: relative; }
    .currency-picker-native {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    .currency-picker-trigger {
        width: 100%;
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        gap: 10px;
        padding: 10px 36px 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        color: #111827;
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='none' stroke='%239ca3af' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='M5 8l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 10px center;
        cursor: pointer;
        text-align: left;
    }
    .currency-picker-trigger:focus {
        outline: none;
        border-color: #a78bfa;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }
    .currency-flag {
        width: 24px;
        height: 17px;
        object-fit: cover;
        border-radius: 2px;
        box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08);
        flex-shrink: 0;
    }
    .currency-picker-label {
        display: inline-flex;
        align-items: center;
        flex: 1;
        min-width: 0;
        white-space: nowrap;
    }
    .currency-picker-label .code {
        font-weight: 600;
        color: #111827;
        margin-right: 6px;
        flex-shrink: 0;
    }
    .currency-picker-label .name {
        color: #6b7280;
        font-size: 13px;
        flex-shrink: 0;
    }
    .currency-picker-menu {
        position: absolute;
        z-index: 20;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        max-height: 220px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
        padding: 4px;
    }
    .currency-picker-menu[hidden] { display: none; }
    .currency-picker-option {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border: none;
        background: transparent;
        border-radius: 6px;
        cursor: pointer;
        text-align: left;
        font-size: 14px;
    }
    .currency-picker-option:hover,
    .currency-picker-option.is-selected {
        background: #f3f4f6;
    }
    .currency-picker-option .code {
        font-weight: 600;
        min-width: 38px;
        color: #111827;
    }
    .currency-picker-option .name {
        color: #6b7280;
        font-size: 13px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .coa-field-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1.15fr;
        gap: 20px;
        margin-bottom: 24px;
    }
    .coa-field-row .coa-field {
        margin-bottom: 0;
    }
    @media (max-width: 576px) {
        .coa-field-row {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .coa-field-row .coa-field {
            margin-bottom: 0;
        }
    }
    .coa-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 36px;
        padding-top: 4px;
    }
    .coa-btn {
        padding: 11px 22px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        border: 1px solid transparent;
    }
    .coa-btn-cancel {
        background: #fff;
        border-color: #d1d5db;
        color: #374151;
    }
    .coa-btn-cancel:hover { background: #f9fafb; }
    .coa-btn-save {
        background: #7c3aed;
        color: #fff;
        border-color: #7c3aed;
    }
    .coa-btn-save:hover { background: #6d28d9; border-color: #6d28d9; }
    @media (max-width: 576px) {
        .coa-simple-backdrop { align-items: flex-end; padding: 0; }
        .coa-simple-modal { border-radius: 12px 12px 0 0; }
        .coa-simple-head,
        .coa-simple-body { padding-left: 20px; padding-right: 20px; }
        .coa-actions { flex-direction: column-reverse; }
        .coa-btn { width: 100%; text-align: center; }
    }

    /* Dark theme: modal was white while global dark forced light text (invisible labels). */
    html[data-theme="dark"] .coa-simple-backdrop {
        background: rgba(2, 6, 23, 0.75);
    }
    html[data-theme="dark"] .coa-simple-modal {
        background: #1e293b;
        border: 1px solid #334155;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
        color: #e2e8f0;
    }
    html[data-theme="dark"] .coa-simple-head h1 {
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .coa-simple-close {
        color: #94a3b8;
    }
    html[data-theme="dark"] .coa-simple-close:hover {
        color: #e2e8f0;
    }
    html[data-theme="dark"] .coa-field label,
    html[data-theme="dark"] .coa-field-top label {
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .coa-char-count,
    html[data-theme="dark"] .coa-image-hint,
    html[data-theme="dark"] .coa-parent-banner-hint {
        color: #94a3b8 !important;
    }
    html[data-theme="dark"] .coa-simple-alert {
        background: rgba(127, 29, 29, 0.35);
        color: #fecaca;
        border: 1px solid #991b1b;
    }
    html[data-theme="dark"] .coa-parent-banner {
        background: rgba(124, 58, 237, 0.18);
        border-color: #5b21b6;
        color: #ddd6fe !important;
    }
    html[data-theme="dark"] .coa-parent-banner strong {
        color: #ede9fe !important;
    }
    html[data-theme="dark"] .coa-input,
    html[data-theme="dark"] .coa-select,
    html[data-theme="dark"] .currency-picker-trigger {
        background-color: #0f172a;
        border-color: #475569;
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .coa-select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='M5 8l5 5 5-5'/%3E%3C/svg%3E");
        background-color: #0f172a;
        background-repeat: no-repeat;
        background-position: right 12px center;
    }
    html[data-theme="dark"] .currency-picker-trigger {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='M5 8l5 5 5-5'/%3E%3C/svg%3E");
        background-color: #0f172a;
        background-repeat: no-repeat;
        background-position: right 10px center;
    }
    html[data-theme="dark"] .coa-input::placeholder,
    html[data-theme="dark"] .coa-select:invalid {
        color: #64748b !important;
    }
    html[data-theme="dark"] .coa-input:focus,
    html[data-theme="dark"] .coa-select:focus,
    html[data-theme="dark"] .currency-picker-trigger:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
    }
    html[data-theme="dark"] .coa-select option {
        color: #f8fafc;
        background: #1e293b;
    }
    html[data-theme="dark"] .coa-field-locked .coa-select,
    html[data-theme="dark"] .coa-field-locked .currency-picker-trigger {
        background-color: #0f172a;
        color: #94a3b8 !important;
        opacity: 0.85;
    }
    html[data-theme="dark"] .coa-image-thumb {
        background: #0f172a;
        border-color: #475569;
        color: #93c5fd;
    }
    html[data-theme="dark"] .coa-image-thumb.has-image {
        border-color: #64748b;
    }
    html[data-theme="dark"] .currency-picker-label .code {
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .currency-picker-label .name,
    html[data-theme="dark"] .currency-picker-option .name {
        color: #94a3b8 !important;
    }
    html[data-theme="dark"] .currency-picker-menu {
        background: #0f172a;
        border-color: #475569;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
    }
    html[data-theme="dark"] .currency-picker-option .code {
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .currency-picker-option:hover,
    html[data-theme="dark"] .currency-picker-option.is-selected {
        background: #334155;
    }
    html[data-theme="dark"] .coa-btn-cancel {
        background: #334155;
        border-color: #475569;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] .coa-btn-cancel:hover {
        background: #475569;
        color: #f8fafc !important;
    }
    html[data-theme="dark"] .coa-btn-save,
    html[data-theme="dark"] .coa-btn-save:hover {
        background: #7c3aed;
        border-color: #7c3aed;
        color: #fff !important;
    }
    html[data-theme="dark"] .coa-btn-save:hover {
        background: #6d28d9;
        border-color: #6d28d9;
    }
</style>

<div class="coa-simple-backdrop" role="presentation">
    <div class="coa-simple-modal" role="dialog" aria-modal="true" aria-labelledby="coaSimpleTitle">
        <div class="coa-simple-head">
            <h1 id="coaSimpleTitle">Edit Account</h1>
            <a href="<?= $esc($accountsBackUrl) ?>" class="coa-simple-close" aria-label="Close">&times;</a>
        </div>

        <form method="post" action="<?= $esc($formAction) ?>" enctype="multipart/form-data">
            <input type="hidden" name="account_id" value="<?= (int) $accountId ?>">
            <input type="hidden" name="account_code" value="<?= $esc($accountCode) ?>">
            <div class="coa-simple-body">
                <?php if ($sessionError !== ''): ?>
                    <div class="coa-simple-alert"><?= $esc($sessionError) ?></div>
                <?php endif; ?>

                <div class="coa-field coa-name-block">
                    <div class="coa-field-top">
                        <label for="account_name">Name <span class="req">*</span></label>
                        <span class="coa-char-count"><span id="nameCount">0</span>/100</span>
                    </div>
                    <div class="coa-identity-grid">
                        <label class="coa-image-picker" for="account_image" title="Upload account image">
                            <input type="file"
                                   name="account_image"
                                   id="account_image"
                                   accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
                            <span class="coa-image-thumb<?= $hasExistingImage ? ' has-image' : '' ?>" id="accountImageThumb">
                                <img id="accountImagePreview" alt=""<?= $hasExistingImage ? ' src="' . $esc($existingAccountImageUrl) . '"' : '' ?>>
                                <i class="fas fa-camera" aria-hidden="true"></i>
                            </span>
                        </label>
                        <input type="text"
                               class="coa-input"
                               id="account_name"
                               name="account_name"
                               maxlength="100"
                               required
                               placeholder="Enter account name"
                               value="<?= $esc($accountName) ?>">
                    </div>
                    <div class="coa-image-hint">Optional image &middot; JPG, PNG, WEBP, GIF &middot; max 2MB</div>
                    <?php if ($hasExistingImage): ?>
                        <label class="coa-image-remove" id="coaRemoveImageLabel">
                            <input type="checkbox" name="remove_account_image" id="remove_account_image" value="1">
                            Remove current image
                        </label>
                    <?php endif; ?>
                </div>

                <div class="coa-field">
                    <label for="category">Category <span class="req">*</span></label>
                    <select class="coa-select" id="category" name="category" required>
                        <?php foreach (array_keys($categoryOptions) as $categoryLabel): ?>
                            <option value="<?= $esc($categoryLabel) ?>"<?= $selectedCategory === $categoryLabel ? ' selected' : '' ?>>
                                <?= $esc($categoryLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="coa-field-row">
                    <div class="coa-field">
                        <label for="currency">Currency</label>
                        <div class="currency-picker" id="currency_picker">
                            <select name="currency" id="currency" class="currency-picker-native" tabindex="-1" aria-hidden="true">
                                <?php foreach ($currencyCatalog as $code => $meta): ?>
                                    <option value="<?= $esc($code) ?>"<?= $currency === $code ? ' selected' : '' ?>>
                                        <?= $esc($code . ' - ' . $meta['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="currency-picker-trigger" id="currency_trigger" aria-haspopup="listbox" aria-expanded="false">
                                <img src="<?= $esc($currencyFlagUrl($selectedCurrencyMeta['flag'])) ?>" alt="" class="currency-flag" id="currency_trigger_flag" width="24" height="17">
                                <span class="currency-picker-label">
                                    <span class="code" id="currency_trigger_code"><?= $esc($currency) ?></span>
                                    <span class="name" id="currency_trigger_name"><?= $esc($selectedCurrencyMeta['name']) ?></span>
                                </span>
                            </button>
                            <div class="currency-picker-menu" id="currency_menu" role="listbox" hidden>
                                <?php foreach ($currencyCatalog as $code => $meta): ?>
                                    <button type="button"
                                            class="currency-picker-option<?= $currency === $code ? ' is-selected' : '' ?>"
                                            role="option"
                                            data-value="<?= $esc($code) ?>"
                                            data-flag="<?= $esc($meta['flag']) ?>"
                                            data-name="<?= $esc($meta['name']) ?>"
                                            aria-selected="<?= $currency === $code ? 'true' : 'false' ?>">
                                        <img src="<?= $esc($currencyFlagUrl($meta['flag'])) ?>" alt="" class="currency-flag" width="24" height="17" loading="lazy">
                                        <span class="code"><?= $esc($code) ?></span>
                                        <span class="name"><?= $esc($meta['name']) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="coa-field">
                        <label for="opening_balance">Opening balance</label>
                        <input type="text"
                               class="coa-input coa-input-readonly"
                               id="opening_balance"
                               value="<?= $esc($openingBalanceDisplay) ?>"
                               readonly
                               tabindex="-1"
                               aria-readonly="true"
                               title="Opening balance cannot be changed after the account is created">
                    </div>
                    <div class="coa-field">
                        <label for="opening_date">Opening date</label>
                        <input type="date"
                               class="coa-input"
                               id="opening_date"
                               name="opening_date"
                               value="<?= $esc($openingDate) ?>">
                    </div>
                </div>

                <div class="coa-actions">
                    <a href="<?= $esc($accountsBackUrl) ?>" class="coa-btn coa-btn-cancel">Cancel</a>
                    <button type="submit" class="coa-btn coa-btn-save">Save Account</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var nameInput = document.getElementById('account_name');
    var nameCount = document.getElementById('nameCount');
    if (nameInput && nameCount) {
        function syncCount() {
            nameCount.textContent = String(nameInput.value.length);
        }
        nameInput.addEventListener('input', syncCount);
        syncCount();
    }

    var imageInput = document.getElementById('account_image');
    var imagePreview = document.getElementById('accountImagePreview');
    var imageThumb = document.getElementById('accountImageThumb');
    var removeImageCheckbox = document.getElementById('remove_account_image');
    var existingImageUrl = <?= json_encode($existingAccountImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var imageObjectUrl = '';

    if (imageInput && imagePreview && imageThumb) {
        function clearNewPreview() {
            if (imageObjectUrl) {
                URL.revokeObjectURL(imageObjectUrl);
                imageObjectUrl = '';
            }
        }

        function syncImagePreview() {
            clearNewPreview();
            var removeChecked = !!(removeImageCheckbox && removeImageCheckbox.checked);
            var file = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;
            var hasNewFile = !!(file && file.type && file.type.indexOf('image/') === 0);
            var previewSrc = '';

            if (hasNewFile) {
                imageObjectUrl = URL.createObjectURL(file);
                previewSrc = imageObjectUrl;
            } else if (!removeChecked && existingImageUrl) {
                previewSrc = existingImageUrl;
            }

            if (previewSrc) {
                imagePreview.src = previewSrc;
                imageThumb.classList.add('has-image');
            } else {
                imagePreview.removeAttribute('src');
                imageThumb.classList.remove('has-image');
            }
        }

        imageInput.addEventListener('change', function () {
            if (removeImageCheckbox) {
                removeImageCheckbox.checked = false;
            }
            syncImagePreview();
        });

        if (removeImageCheckbox) {
            removeImageCheckbox.addEventListener('change', function () {
                if (removeImageCheckbox.checked) {
                    imageInput.value = '';
                }
                syncImagePreview();
            });
        }
    }

    (function initCurrencyPicker() {
        var picker = document.getElementById('currency_picker');
        var select = document.getElementById('currency');
        var trigger = document.getElementById('currency_trigger');
        var menu = document.getElementById('currency_menu');
        if (!picker || !select || !trigger || !menu) return;

        var flagBase = 'https://flagcdn.com/w40/';

        function setCurrency(code, flag, name) {
            select.value = code;
            var flagEl = document.getElementById('currency_trigger_flag');
            var codeEl = document.getElementById('currency_trigger_code');
            var nameEl = document.getElementById('currency_trigger_name');
            if (flagEl) flagEl.src = flagBase + String(flag).toLowerCase() + '.png';
            if (codeEl) codeEl.textContent = code;
            if (nameEl) nameEl.textContent = name;
            menu.querySelectorAll('.currency-picker-option').forEach(function (opt) {
                var selected = opt.getAttribute('data-value') === code;
                opt.classList.toggle('is-selected', selected);
                opt.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
        }

        function closeMenu() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }

        function openMenu() {
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }

        trigger.addEventListener('click', function () {
            if (menu.hidden) openMenu();
            else closeMenu();
        });

        menu.querySelectorAll('.currency-picker-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                setCurrency(
                    opt.getAttribute('data-value'),
                    opt.getAttribute('data-flag'),
                    opt.getAttribute('data-name')
                );
                closeMenu();
            });
        });

        document.addEventListener('click', function (e) {
            if (!picker.contains(e.target)) closeMenu();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });
    })();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
