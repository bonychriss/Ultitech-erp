<?php
// BALANCES_COA_CREATE_BUILD
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/balances-guard.php';

$coaBackUrl = balances_guard_accounts_url();
$formQuery = [];
if (!empty($_GET['module'])) {
    $formQuery['module'] = (string) $_GET['module'];
}
if (!empty($_GET['parent_id'])) {
    $formQuery['parent_id'] = (int) $_GET['parent_id'];
}
$coaRetryUrl = 'coa_create.php' . ($formQuery !== [] ? '?' . http_build_query($formQuery) : '');

balances_bootstrap_or_error('coa_create.php', [
    'title' => 'Page unavailable',
    'back_url' => $coaBackUrl,
    'retry_url' => $coaRetryUrl,
    'back_label' => 'Back to accounts',
    'retry_label' => 'Try again',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (function_exists('getRequestedCompanySlug') && function_exists('applyWinningCompanySession')) {
    $bootSlug = strtolower(trim((string) getRequestedCompanySlug()));
    if ($bootSlug === '' && !empty($_SESSION['company_slug'])) {
        $bootSlug = strtolower(trim((string) $_SESSION['company_slug']));
    }
    if ($bootSlug !== '') {
        applyWinningCompanySession($bootSlug);
    }
}

global $pdo;
if (!($pdo instanceof PDO)) {
    balances_render_error_page('Database connection failed.', [
        'title' => 'Page unavailable',
        'back_url' => $coaBackUrl,
        'retry_url' => $coaRetryUrl,
        'log_context' => 'coa_create',
    ]);
}

if (!function_exists('coa_ensure_account_image_column')) {
    function coa_ensure_account_image_column(PDO $pdo): void
    {
        if (function_exists('columnExists') && !columnExists('financial_accounts', 'account_image', $pdo)) {
            $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN account_image VARCHAR(500) NULL DEFAULT NULL AFTER status');
        }
    }
}

if (!function_exists('coa_ensure_opening_date_column')) {
    function coa_ensure_opening_date_column(PDO $pdo): void
    {
        if (function_exists('columnExists') && !columnExists('financial_accounts', 'opening_date', $pdo)) {
            $pdo->exec('ALTER TABLE financial_accounts ADD COLUMN opening_date DATE NULL DEFAULT NULL AFTER opening_balance');
        }
    }
}

if (!function_exists('coa_db_type_to_category_label')) {
    function coa_db_type_to_category_label($dbType)
    {
        if (function_exists('coa_account_type_to_category_label')) {
            return coa_account_type_to_category_label((string) $dbType);
        }
        $t = strtolower(trim((string) $dbType));
        $map = [
            'asset' => 'Asset', 'cash' => 'Asset', 'bank' => 'Asset', 'mobile' => 'Asset',
            'liability' => 'Liability', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expense',
        ];

        return $map[$t] ?? 'Asset';
    }
}

if (!function_exists('coa_store_account_image')) {
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

try {
    if (function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }
} catch (Throwable $e) {
    error_log('coa_create parent_id column: ' . $e->getMessage());
}

$parentId = (int) ($_GET['parent_id'] ?? $_POST['parent_id'] ?? 0);
$parentAccount = null;
$parentAccountLabel = '';
$isSubAccount = false;

if ($parentId > 0) {
    try {
        $parentStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
        $parentStmt->execute([$parentId]);
        $parentAccount = $parentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('coa_create parent lookup: ' . $e->getMessage());
        $parentAccount = null;
    }
    if (!$parentAccount) {
        $_SESSION['error'] = 'Parent account not found.';
        redirect('accounts.php');
    }
    if ((int) ($parentAccount['parent_id'] ?? 0) > 0) {
        $_SESSION['error'] = 'Sub-accounts can only be added under a main account.';
        redirect('accounts.php');
    }
    [$parentCode, $parentNameOnly] = coa_parse_account_name_parts($parentAccount['name'] ?? '');
    $parentAccountLabel = ($parentCode !== '' ? $parentCode . ' - ' : '') . $parentNameOnly;
    $isSubAccount = true;
}

$page_title = $isSubAccount ? 'Add Sub-account' : 'Add New Account';
$accountsBackUrl = 'accounts.php';
if (!empty($_GET['module'])) {
    $accountsBackUrl .= '?module=' . rawurlencode((string) $_GET['module']);
}
$formQuery = [];
if (!empty($_GET['module'])) {
    $formQuery['module'] = (string) $_GET['module'];
}
if ($parentId > 0) {
    $formQuery['parent_id'] = $parentId;
}
$formAction = 'coa_create.php' . ($formQuery !== [] ? '?' . http_build_query($formQuery) : '');

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
$accountName = trim((string) ($_POST['account_name'] ?? ''));
$selectedCategory = trim((string) ($_POST['category'] ?? ''));
if ($isSubAccount && $parentAccount && $selectedCategory === '') {
    $selectedCategory = coa_db_type_to_category_label((string) ($parentAccount['type'] ?? ''));
}
if ($isSubAccount && $parentAccount && !isset($categoryOptions[$selectedCategory])) {
    $selectedCategory = coa_db_type_to_category_label((string) ($parentAccount['type'] ?? ''));
}
$currency = strtoupper(trim((string) ($_POST['currency'] ?? ($isSubAccount && $parentAccount ? ($parentAccount['currency'] ?? 'TZS') : 'TZS'))));
if (!isset($currencyCatalog[$currency])) {
    $currency = 'TZS';
}
$selectedCurrencyMeta = $currencyCatalog[$currency];
$currencyFlagUrl = static function (string $flagCode): string {
    return 'https://flagcdn.com/w40/' . strtolower($flagCode) . '.png';
};
$isFormPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$openingBalancePost = $_POST['opening_balance'] ?? null;
$openingBalance = ($openingBalancePost === null || $openingBalancePost === '') ? 0.0 : (float) $openingBalancePost;
$openingBalanceDisplay = $isFormPost ? trim((string) $openingBalancePost) : '';
$openingDate = trim((string) ($_POST['opening_date'] ?? ''));
if (!$isFormPost && $openingDate === '') {
    $openingDate = date('Y-m-d');
}
if ($openingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $openingDate)) {
    $openingDate = '';
}

$showPaymentWalletType = $isSubAccount
    && is_array($parentAccount)
    && function_exists('balances_parent_is_payment_wallet_group')
    && balances_parent_is_payment_wallet_group($parentAccount);
$paymentWalletType = trim((string) ($_POST['payment_wallet_type'] ?? ''));
if ($showPaymentWalletType && $paymentWalletType === '') {
    $paymentWalletType = balances_infer_payment_wallet_type($parentAccount);
}

// Petty Cash category subs (Fuel, Transport, …) only need a name — float lives on the parent.
$isPettyCashCategorySub = false;
if ($isSubAccount && is_array($parentAccount)) {
    $parentNameHay = strtolower((string) ($parentAccount['name'] ?? '') . ' ' . $parentAccountLabel);
    $parentType = strtolower((string) ($parentAccount['type'] ?? ''));
    $isPettyCashCategorySub = str_contains($parentNameHay, 'petty cash')
        || ($parentType === 'cash' && str_contains($parentNameHay, 'petty'));
}
if ($isPettyCashCategorySub) {
    $showPaymentWalletType = false;
    $paymentWalletType = 'cash';
    $openingBalance = 0.0;
    $openingBalanceDisplay = '';
    if ($openingDate === '') {
        $openingDate = date('Y-m-d');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($parentId > 0 && !$parentAccount) {
        $_SESSION['error'] = 'Parent account not found.';
    } elseif ($accountName === '') {
        $_SESSION['error'] = 'Account name is required.';
    } elseif (!isset($categoryOptions[$selectedCategory])) {
        $_SESSION['error'] = 'Please select an account category.';
    } else {
        try {
            if ($parentId > 0) {
                $parentCheck = $pdo->prepare('SELECT id, parent_id FROM financial_accounts WHERE id = ? LIMIT 1');
                $parentCheck->execute([$parentId]);
                $parentRow = $parentCheck->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$parentRow) {
                    throw new RuntimeException('Parent account not found.');
                }
                if ((int) ($parentRow['parent_id'] ?? 0) > 0) {
                    throw new RuntimeException('Sub-accounts can only be added under a main account.');
                }
            }

            $meta = $categoryOptions[$selectedCategory];
            $accountType = $meta['account_type'];
            $balanceSide = (string) ($meta['normal_balance'] ?? coa_normal_balance_side_for_account_type($accountType));
            if (coa_find_account_by_name_and_balance_side($pdo, $accountName, $balanceSide) !== null) {
                throw new RuntimeException(coa_duplicate_account_message($accountName, $balanceSide));
            }

            $accountCode = coa_compute_next_account_code($pdo, $accountType);
            $accountCategory = $meta['account_category'];
            $reportingGroup = $meta['reporting_group'];
            $financialStatement = $meta['financial_statement'];

            if (function_exists('coa_ensure_account_category')) {
                coa_ensure_account_category($pdo, $accountCategory, $accountType, $reportingGroup, $financialStatement);
            }

            // Store wallet type (cash/bank/mobile) or chart-of-accounts type on the account row.
            $normalizedType = $accountType;
            if ($parentId > 0 && is_array($parentAccount) && function_exists('balances_parent_is_payment_wallet_group')) {
                if (balances_parent_is_payment_wallet_group($parentAccount)) {
                    $walletType = balances_resolve_sub_account_wallet_type(
                        $parentAccount,
                        trim((string) ($_POST['payment_wallet_type'] ?? '')) ?: null
                    );
                    if ($walletType === null || balances_normalize_payment_wallet_type($walletType) === null) {
                        throw new RuntimeException('Please select a payment type (Cash, Bank, or Mobile money).');
                    }
                    $normalizedType = $walletType;
                }
            }

            coa_ensure_account_image_column($pdo);
            coa_ensure_opening_date_column($pdo);
            coa_ensure_parent_id_column($pdo);
            $accountImagePath = coa_store_account_image();

            $insertRow = [
                'name' => $accountCode . ' - ' . $accountName,
                'type' => $normalizedType,
                'currency' => $currency,
                'opening_balance' => $openingBalance,
                'opening_date' => $openingDate !== '' ? $openingDate : null,
                'current_balance' => $openingBalance,
                'status' => 'active',
                'account_image' => $accountImagePath !== '' ? $accountImagePath : null,
                'parent_id' => $parentId > 0 ? $parentId : null,
            ];
            if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope($pdo)) {
                $parentCompanyId = is_array($parentAccount) ? (int) ($parentAccount['company_id'] ?? 0) : 0;
                $sessionCompanyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
                if ($parentCompanyId > 0) {
                    $insertRow['company_id'] = $parentCompanyId;
                } elseif ($sessionCompanyId > 0) {
                    $insertRow['company_id'] = $sessionCompanyId;
                }
            }
            $faCols = $pdo->query('SHOW COLUMNS FROM financial_accounts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $insertCols = [];
            $insertVals = [];
            foreach ($insertRow as $col => $val) {
                if (!in_array($col, $faCols, true)) {
                    continue;
                }
                $insertCols[] = $col;
                $insertVals[] = $val;
            }
            if ($insertCols === []) {
                throw new RuntimeException('Financial accounts table schema is not compatible.');
            }
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $stmt = $pdo->prepare(
                'INSERT INTO financial_accounts (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute($insertVals);

            $newAccountId = (int) $pdo->lastInsertId();
            if ($newAccountId > 0 && function_exists('balances_link_account_to_gl')) {
                balances_link_account_to_gl($pdo, $newAccountId);
            }

            $_SESSION['bal_lottie_success'] = $parentId > 0
                ? 'Your sub-account has been added under the parent account.'
                : 'Your new account has been added and is ready to use.';
            $redirectUrl = $accountsBackUrl;
            if ($parentId > 0) {
                $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . 'selected=' . $parentId;
            }
            redirect($redirectUrl);
        } catch (Throwable $e) {
            $_SESSION['error'] = 'Could not create account. ' . $e->getMessage();
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

try {
    include __DIR__ . '/includes/header.php';
} catch (Throwable $e) {
    error_log('coa_create header: ' . $e->getMessage());
    balances_render_error_page('Header could not load: ' . $e->getMessage(), [
        'title' => 'Page unavailable',
        'back_url' => $accountsBackUrl,
        'retry_url' => $formAction,
        'log_context' => 'coa_create header',
    ]);
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
    .coa-simple-modal.coa-simple-modal--compact {
        max-width: 480px;
        min-height: 0;
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
    .coa-parent-banner {
        margin-bottom: 20px;
        padding: 12px 14px;
        border-radius: 8px;
        background: #f5f3ff;
        border: 1px solid #ddd6fe;
        font-size: 13px;
        color: #5b21b6;
        line-height: 1.45;
    }
    .coa-parent-banner strong {
        font-weight: 600;
        color: #4c1d95;
    }
    .coa-field-locked .coa-select,
    .coa-field-locked .currency-picker-trigger {
        background-color: #f9fafb;
        color: #6b7280;
        cursor: not-allowed;
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
    .coa-identity-grid.coa-identity-grid--name-only {
        display: block;
        grid-template-columns: none;
    }
    .coa-identity-grid.coa-identity-grid--name-only .coa-input {
        width: 100%;
        min-height: 48px;
        font-size: 15px;
    }
    .coa-simple-modal--compact .coa-simple-body {
        padding-bottom: 8px;
    }
    .coa-simple-modal--compact .coa-parent-banner {
        margin-bottom: 1.25rem;
    }
    .coa-simple-modal--compact .coa-actions {
        margin-top: 1.5rem;
    }
    .coa-parent-banner-hint {
        margin-top: 6px;
        font-size: 13px;
        font-weight: 400;
        color: #4b5563;
        line-height: 1.4;
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
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 11px 22px;
        border-radius: 9999px !important;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        border: 1px solid transparent;
        min-height: 2.5rem;
    }
    .coa-btn-cancel {
        background: #fff;
        border-color: #d1d5db;
        color: #374151;
        border-radius: 9999px !important;
    }
    .coa-btn-cancel:hover { background: #f9fafb; }
    .coa-btn-save {
        background: #7c3aed;
        color: #fff;
        border-color: #7c3aed;
        border-radius: 9999px !important;
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
    <div class="coa-simple-modal<?= $isPettyCashCategorySub ? ' coa-simple-modal--compact' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="coaSimpleTitle">
        <div class="coa-simple-head">
            <h1 id="coaSimpleTitle"><?= $isSubAccount ? 'Add Sub-account' : 'Add New Account' ?></h1>
            <a href="<?= $esc($accountsBackUrl) ?>" class="coa-simple-close" aria-label="Close">&times;</a>
        </div>

        <form method="post" action="<?= $esc($formAction) ?>" enctype="multipart/form-data">
            <?php if ($parentId > 0): ?>
                <input type="hidden" name="parent_id" value="<?= (int) $parentId ?>">
            <?php endif; ?>
            <div class="coa-simple-body">
                <?php if ($sessionError !== ''): ?>
                    <div class="coa-simple-alert"><?= $esc($sessionError) ?></div>
                <?php endif; ?>

                <?php if ($isSubAccount && $parentAccountLabel !== ''): ?>
                    <div class="coa-parent-banner">
                        Adding sub-account under <strong><?= $esc($parentAccountLabel) ?></strong>
                        <?php if ($isPettyCashCategorySub): ?>
                            <div class="coa-parent-banner-hint">
                                Enter a category name only (e.g. Fuel, Transport, Water). Opening balance stays on Petty Cash.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="coa-field coa-name-block">
                    <div class="coa-field-top">
                        <label for="account_name">Name <span class="req">*</span></label>
                        <span class="coa-char-count"><span id="nameCount">0</span>/100</span>
                    </div>
                    <div class="coa-identity-grid<?= $isPettyCashCategorySub ? ' coa-identity-grid--name-only' : '' ?>">
                        <?php if (!$isPettyCashCategorySub): ?>
                        <label class="coa-image-picker" for="account_image" title="Upload account image">
                            <input type="file"
                                   name="account_image"
                                   id="account_image"
                                   accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
                            <span class="coa-image-thumb" id="accountImageThumb">
                                <img id="accountImagePreview" alt="">
                                <i class="fas fa-camera" aria-hidden="true"></i>
                            </span>
                        </label>
                        <?php endif; ?>
                        <input type="text"
                               class="coa-input"
                               id="account_name"
                               name="account_name"
                               maxlength="100"
                               required
                               autocomplete="off"
                               placeholder="<?= $isPettyCashCategorySub ? 'e.g. Fuel, Transport, Water' : 'Enter account name' ?>"
                               value="<?= $esc($accountName) ?>">
                    </div>
                    <?php if (!$isPettyCashCategorySub): ?>
                    <div class="coa-image-hint">Optional image &middot; JPG, PNG, WEBP, GIF &middot; max 2MB</div>
                    <?php endif; ?>
                </div>

                <?php if ($showPaymentWalletType): ?>
                <div class="coa-field">
                    <label for="payment_wallet_type">Payment type <span class="req">*</span></label>
                    <select class="coa-select" id="payment_wallet_type" name="payment_wallet_type" required>
                        <?php foreach (balances_payment_wallet_types() as $walletSlug => $walletLabel): ?>
                            <option value="<?= $esc($walletSlug) ?>"<?= $paymentWalletType === $walletSlug ? ' selected' : '' ?>>
                                <?= $esc($walletLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="coa-image-hint">Used on expenses and transfers as cash, bank, or mobile money.</div>
                </div>
                <?php elseif ($isPettyCashCategorySub): ?>
                    <input type="hidden" name="payment_wallet_type" value="cash">
                <?php endif; ?>

                <?php if ($isPettyCashCategorySub): ?>
                    <input type="hidden" name="category" value="<?= $esc($selectedCategory) ?>">
                    <input type="hidden" name="currency" value="<?= $esc($currency) ?>">
                    <input type="hidden" name="opening_balance" value="0">
                    <input type="hidden" name="opening_date" value="<?= $esc($openingDate !== '' ? $openingDate : date('Y-m-d')) ?>">
                <?php else: ?>
                <div class="coa-field<?= $isSubAccount ? ' coa-field-locked' : '' ?>">
                    <label for="category">Category <span class="req">*</span></label>
                    <?php if ($isSubAccount): ?>
                        <input type="hidden" name="category" value="<?= $esc($selectedCategory) ?>">
                    <?php endif; ?>
                    <select class="coa-select" id="category" name="<?= $isSubAccount ? '' : 'category' ?>"<?= $isSubAccount ? ' disabled' : '' ?> required>
                        <?php if (!$isSubAccount): ?>
                            <option value="" disabled<?= $selectedCategory === '' ? ' selected' : '' ?>>Select account category</option>
                        <?php endif; ?>
                        <?php foreach (array_keys($categoryOptions) as $categoryLabel): ?>
                            <option value="<?= $esc($categoryLabel) ?>"<?= $selectedCategory === $categoryLabel ? ' selected' : '' ?>>
                                <?= $esc($categoryLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="coa-field-row<?= $isSubAccount ? ' coa-field-locked' : '' ?>">
                    <div class="coa-field">
                        <label for="currency">Currency</label>
                        <div class="currency-picker" id="currency_picker">
                            <?php if ($isSubAccount): ?>
                                <input type="hidden" name="currency" value="<?= $esc($currency) ?>">
                            <?php endif; ?>
                            <select name="<?= $isSubAccount ? '' : 'currency' ?>" id="currency" class="currency-picker-native" tabindex="-1" aria-hidden="true"<?= $isSubAccount ? ' disabled' : '' ?>>
                                <?php foreach ($currencyCatalog as $code => $meta): ?>
                                    <option value="<?= $esc($code) ?>"<?= $currency === $code ? ' selected' : '' ?>>
                                        <?= $esc($code . ' - ' . $meta['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="currency-picker-trigger" id="currency_trigger" aria-haspopup="listbox" aria-expanded="false"<?= $isSubAccount ? ' disabled' : '' ?>>
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
                        <input type="number"
                               class="coa-input"
                               id="opening_balance"
                               name="opening_balance"
                               step="0.01"
                               min="0"
                               placeholder="0.00"
                               value="<?= $esc($openingBalanceDisplay) ?>">
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
                <?php endif; ?>

                <div class="coa-actions">
                    <a href="<?= $esc($accountsBackUrl) ?>" class="coa-btn coa-btn-cancel">Cancel</a>
                    <button type="submit" class="coa-btn coa-btn-save"><?= $isSubAccount ? 'Save Sub-account' : 'Save Account' ?></button>
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
    var imageObjectUrl = '';

    if (imageInput && imagePreview && imageThumb) {
        function clearPreview() {
            if (imageObjectUrl) {
                URL.revokeObjectURL(imageObjectUrl);
                imageObjectUrl = '';
            }
            imagePreview.removeAttribute('src');
            imageThumb.classList.remove('has-image');
        }

        imageInput.addEventListener('change', function () {
            clearPreview();
            var file = imageInput.files && imageInput.files[0] ? imageInput.files[0] : null;
            if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
            imageObjectUrl = URL.createObjectURL(file);
            imagePreview.src = imageObjectUrl;
            imageThumb.classList.add('has-image');
        });
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
