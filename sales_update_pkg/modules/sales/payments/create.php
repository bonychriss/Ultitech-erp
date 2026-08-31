<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

if (is_file(__DIR__ . '/../../../modules/balances/functions.php')) {
    require_once __DIR__ . '/../../../modules/balances/functions.php';
}
if (is_file(__DIR__ . '/../../../includes/revenue_ledger.php')) {
    require_once __DIR__ . '/../../../includes/revenue_ledger.php';
}
if (is_file(__DIR__ . '/../../../includes/bot_exchange_rates.php')) {
    require_once __DIR__ . '/../../../includes/bot_exchange_rates.php';
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
if (!($salesDb instanceof PDO)) {
    http_response_code(500);
    die('Sales database connection is not available.');
}
global $pdo;
$pdo = $salesDb;
$GLOBALS['pdo'] = $salesDb;

$company_id = (int) (currentCompanyId() ?? 0);
$invoice_id = isset($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
$error = isset($error) ? $error : '';

if ($invoice_id <= 0) {
    http_response_code(400);
    die('Invoice ID missing.');
}

/**
 * @param array<string,mixed> $invoice
 * @param array<int,string>   $invCols
 */
function sales_payment_invoice_balance($invoice, $invCols)
{
    if (in_array('balance_due', $invCols, true) && array_key_exists('balance_due', $invoice)) {
        return max(0.0, (float) $invoice['balance_due']);
    }
    $total = (float) ($invoice['total_amount'] ?? 0);
    $paid = in_array('amount_paid', $invCols, true) ? (float) ($invoice['amount_paid'] ?? 0) : 0.0;

    return max(0.0, $total - $paid);
}

function sales_payment_ensure_vfd_column(PDO $pdo): void
{
    $cols = $pdo->query('SHOW COLUMNS FROM sales_payments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('vfd_receipt', $cols, true)) {
        $pdo->exec('ALTER TABLE sales_payments ADD COLUMN vfd_receipt VARCHAR(500) NULL DEFAULT NULL AFTER notes');
    }
}

function sales_payment_store_vfd_receipt(): string
{
    if (!isset($_FILES['vfd_receipt']) || (int) ($_FILES['vfd_receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int) $_FILES['vfd_receipt']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('VFD receipt upload failed. Please try again.');
    }
    if ((int) $_FILES['vfd_receipt']['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('VFD receipt must be 5MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) $_FILES['vfd_receipt']['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('VFD receipt must be PDF, JPG, PNG, or WEBP.');
    }
    $root = dirname(__DIR__, 3);
    $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'payments' . DIRECTORY_SEPARATOR . 'vfd' . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create upload folder for VFD receipt.');
    }
    $fileName = 'VFD_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . $fileName;
    if (!move_uploaded_file($_FILES['vfd_receipt']['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save VFD receipt.');
    }

    return 'uploads/sales/payments/vfd/' . $fileName;
}

$invCols = [];
try {
    $invCols = $salesDb->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    error_log('payment create invoice columns: ' . $e->getMessage());
}

$invoice = null;
try {
    $sql = 'SELECT i.*, c.company_name
        FROM invoices i
        INNER JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ?';
    $invoiceParams = [$invoice_id];
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($sql, $invoiceParams, 'invoices', 'i');
    }
    $stmt = $salesDb->prepare($sql);
    $stmt->execute($invoiceParams);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('payment create invoice load id=' . $invoice_id . ': ' . $e->getMessage());
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        http_response_code(500);
        die('Invoice query failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

$invoiceBalanceDue = sales_payment_invoice_balance($invoice, $invCols);

$balancesPdo = function_exists('balances_resolve_pdo') ? balances_resolve_pdo() : null;
$accounts = function_exists('balancesFetchDepositAccounts')
    ? balancesFetchDepositAccounts($balancesPdo)
    : [];

// Handle Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['payment_date'];
    $account_id = (int)$_POST['account_id']; // Deposit Account
    $reference = $_POST['reference'];
    $notes = $_POST['notes'];
    $currentBalanceDue = $invoiceBalanceDue;

    if ($amount <= 0) {
        $error = "Payment amount must be greater than zero.";
    } elseif ($currentBalanceDue <= 0.001) {
        $error = "This invoice is already fully paid. No balance remains.";
    } elseif ($amount > ($currentBalanceDue + 0.001)) {
        $error = "Payment amount cannot exceed the invoice balance due (remaining: " . number_format($currentBalanceDue, 2) . ").";
    }

    $account = null;
    $method = 'Unknown';
    if ($account_id > 0) {
        foreach ($accounts as $acc) {
            if ((int) ($acc['id'] ?? 0) === $account_id) {
                $account = $acc;
                break;
            }
        }
        if (!$account) {
            $error = 'Please select a valid deposit account from the Balances module.';
        }
    } elseif (empty($error)) {
        $error = 'Deposit account is required.';
    }
    $method = $account ? ucfirst((string) $account['type']) . ' - ' . (string) $account['name'] : 'Unknown';

    try {
        if (!empty($error)) {
            throw new RuntimeException($error);
        }
        // Ensure payment table exists before opening transaction.
        // MySQL DDL can cause implicit commit and break transaction state.
        $salesDb->exec("CREATE TABLE IF NOT EXISTS sales_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            invoice_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_date DATE NOT NULL,
            payment_method VARCHAR(50),
            reference VARCHAR(100),
            notes TEXT,
            vfd_receipt VARCHAR(500) NULL,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        sales_payment_ensure_vfd_column($salesDb);

        $vfdReceiptPath = sales_payment_store_vfd_receipt();

        $salesDb->beginTransaction();

        $payCols = $salesDb->query('SHOW COLUMNS FROM sales_payments')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $payRow = [
            'company_id' => $company_id,
            'invoice_id' => $invoice_id,
            'amount' => $amount,
            'payment_date' => $date,
            'payment_method' => $method,
            'reference' => $reference,
            'notes' => $notes,
            'vfd_receipt' => $vfdReceiptPath !== '' ? $vfdReceiptPath : null,
            'created_by' => $_SESSION['user_id'] ?? null,
        ];
        $insertCols = [];
        $insertVals = [];
        foreach ($payRow as $col => $val) {
            if (!in_array($col, $payCols, true)) {
                continue;
            }
            $insertCols[] = $col;
            $insertVals[] = $val;
        }
        if ($insertCols === []) {
            throw new RuntimeException('Payment table schema is not compatible.');
        }
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmtFull = $salesDb->prepare(
            'INSERT INTO sales_payments (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
        );
        $stmtFull->execute($insertVals);

        $newAmountPaid = (in_array('amount_paid', $invCols, true) ? (float) ($invoice['amount_paid'] ?? 0) : 0.0) + $amount;
        $newBalance = (float) ($invoice['total_amount'] ?? 0) - $newAmountPaid;
        $status = ($newBalance <= 0.01) ? 'paid' : 'partial';

        $updSets = [];
        $updParams = [];
        if (in_array('amount_paid', $invCols, true)) {
            $updSets[] = 'amount_paid = ?';
            $updParams[] = $newAmountPaid;
        }
        if (in_array('status', $invCols, true)) {
            $updSets[] = 'status = ?';
            $updParams[] = $status;
        }
        if ($updSets !== []) {
            $updSql = 'UPDATE invoices SET ' . implode(', ', $updSets) . ' WHERE id = ?';
            $updParams[] = $invoice_id;
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($updSql, $updParams, 'invoices');
            }
            $salesDb->prepare($updSql)->execute($updParams);
        }

        if ($status === 'paid' && !empty($invoice['order_id']) && in_array('order_id', $invCols, true)) {
            $ordSql = "UPDATE sales_orders SET status = 'paid' WHERE id = ?";
            $ordParams = [(int) $invoice['order_id']];
            if (function_exists('salesAppendCompanyScope')) {
                salesAppendCompanyScope($ordSql, $ordParams, 'sales_orders');
            }
            $salesDb->prepare($ordSql)->execute($ordParams);
        }

        if ($account_id > 0 && function_exists('recordTransaction')) {
            if ($balancesPdo instanceof PDO) {
                $GLOBALS['pdo'] = $balancesPdo;
            }
            $posted = recordTransaction(
                $account_id,
                'credit',
                $amount,
                "Payment for Invoice #{$invoice['invoice_number']} ($reference)",
                'invoice_payment',
                $invoice_id,
                null,
                $company_id
            );
            if (!$posted) {
                throw new RuntimeException('Failed to post payment into account transactions.');
            }
        }

        $salesDb->commit();

        try {
            if (function_exists('syncInvoiceToRevenueLedger')) {
                syncInvoiceToRevenueLedger($salesDb, (int) $invoice_id, (int) ($_SESSION['user_id'] ?? 0) ?: null);
            }
        } catch (Throwable $syncError) {
            error_log('Invoice ledger sync failed after payment commit: ' . $syncError->getMessage());
        }

        $_SESSION['sales_payment_lottie_success'] = [
            'title' => 'Payment recorded',
            'message' => 'Payment registered successfully.',
            'invoice_id' => (int) $invoice_id,
            'amount' => (float) $amount,
            'currency' => trim((string) ($_POST['currency'] ?? ($invoice['currency'] ?? 'TZS'))),
        ];
        header("Location: ../invoices/view.php?id=" . $invoice_id);
        exit;

    } catch (Exception $e) {
        if ($salesDb->inTransaction()) {
            $salesDb->rollBack();
        }
        $error = 'Error saving payment: ' . $e->getMessage();
    }
}

$currency = !empty($invoice['currency']) ? (string) $invoice['currency'] : 'TZS';
$exchangeRate = 1.00;
$exchangeRateMeta = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && function_exists('bot_get_exchange_rate')) {
    $initialCurrency = strtoupper(trim($currency));
    $botRateInfo = bot_get_exchange_rate($initialCurrency);
    if ($botRateInfo !== null) {
        $exchangeRate = (float) $botRateInfo['rate'];
        $exchangeRateMeta = $botRateInfo;
    }
}
$sessionSuccess = '';

$backUrl = '../invoices/view.php?id=' . $invoice_id;
$balancesAccountsUrl = '../../balances/accounts.php?module=balances';
$formValues = [
    'currency' => trim((string) ($_POST['currency'] ?? $currency)),
    'exchange_rate' => trim((string) ($_POST['exchange_rate'] ?? number_format($exchangeRate, 4, '.', ''))),
    'payment_date' => trim((string) ($_POST['payment_date'] ?? date('Y-m-d'))),
    'amount' => trim((string) ($_POST['amount'] ?? (string) $invoiceBalanceDue)),
    'account_id' => (int) ($_POST['account_id'] ?? 0),
    'reference' => trim((string) ($_POST['reference'] ?? '')),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$currencyOptions = [
    'TZS' => ['name' => 'Tanzanian Shilling', 'flag' => 'tz'],
    'USD' => ['name' => 'US Dollar', 'flag' => 'us'],
    'EUR' => ['name' => 'Euro', 'flag' => 'eu'],
    'GBP' => ['name' => 'British Pound', 'flag' => 'gb'],
    'KES' => ['name' => 'Kenyan Shilling', 'flag' => 'ke'],
    'UGX' => ['name' => 'Ugandan Shilling', 'flag' => 'ug'],
    'RWF' => ['name' => 'Rwandan Franc', 'flag' => 'rw'],
    'ZAR' => ['name' => 'South African Rand', 'flag' => 'za'],
    'AED' => ['name' => 'UAE Dirham', 'flag' => 'ae'],
    'SAR' => ['name' => 'Saudi Riyal', 'flag' => 'sa'],
    'INR' => ['name' => 'Indian Rupee', 'flag' => 'in'],
    'CNY' => ['name' => 'Chinese Yuan', 'flag' => 'cn'],
    'JPY' => ['name' => 'Japanese Yen', 'flag' => 'jp'],
    'CHF' => ['name' => 'Swiss Franc', 'flag' => 'ch'],
    'CAD' => ['name' => 'Canadian Dollar', 'flag' => 'ca'],
    'AUD' => ['name' => 'Australian Dollar', 'flag' => 'au'],
    'NGN' => ['name' => 'Nigerian Naira', 'flag' => 'ng'],
];
$currencyFlagUrl = static function (string $flagCode): string {
    return 'https://flagcdn.com/w40/' . strtolower($flagCode) . '.png';
};
$selectedCurrency = strtoupper(trim($formValues['currency']));
if (!isset($currencyOptions[$selectedCurrency])) {
    $selectedCurrency = isset($currencyOptions[strtoupper($currency)]) ? strtoupper($currency) : 'TZS';
}
$selectedCurrencyMeta = $currencyOptions[$selectedCurrency] ?? ['name' => $selectedCurrency, 'flag' => 'un'];
$formValues['currency'] = $selectedCurrency;

$exchangeRateHint = 'Bank of Tanzania (BOT) mean rate per 1 unit vs TZS. Updates when you change currency.';
if ($selectedCurrency === 'TZS') {
    $exchangeRateHint = 'TZS is the base currency (rate 1.00).';
} elseif ($exchangeRateMeta !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $srcLabel = !empty($exchangeRateMeta['via_ai']) ? 'BOT (AI)' : 'BOT';
    $asOf = !empty($exchangeRateMeta['as_of']) ? ' as of ' . $exchangeRateMeta['as_of'] : '';
    $exchangeRateHint = sprintf(
        '%s mean rate: %s TZS per 1 %s (%s%s). You may adjust before saving.',
        $srcLabel,
        number_format((float) $exchangeRateMeta['rate'], 4, '.', ''),
        $selectedCurrency,
        $srcLabel,
        $asOf
    );
}

$selectedAccountId = (int) $formValues['account_id'];
$accountPickerIconMap = [
    'cash' => 'fa-money-bill-wave',
    'bank' => 'fa-university',
    'mobile' => 'fa-mobile-alt',
];
$depositAccountPickerOptions = [];
foreach ($accounts as $acc) {
    $accBalance = isset($acc['live_balance'])
        ? (float) $acc['live_balance']
        : (float) ($acc['current_balance'] ?? $acc['opening_balance'] ?? 0);
    $accCurrency = (string) ($acc['currency'] ?? $currency);
    $accImageUrl = '';
    if (!empty($acc['account_image']) && function_exists('balancesAccountImageUrl')) {
        $accImageUrl = balancesAccountImageUrl((string) $acc['account_image']);
    }
    $typeRaw = strtolower((string) ($acc['type'] ?? ''));
    $bucket = function_exists('balancesAccountLiquidityBucket')
        ? balancesAccountLiquidityBucket($typeRaw)
        : 'bank';
    $depositAccountPickerOptions[] = [
        'id' => (int) ($acc['id'] ?? 0),
        'name' => (string) ($acc['name'] ?? ''),
        'type' => ucfirst((string) ($acc['type'] ?? '')),
        'currency' => $accCurrency,
        'balance_fmt' => number_format($accBalance, 2),
        'image_url' => $accImageUrl,
        'icon' => $accountPickerIconMap[$bucket] ?? $accountPickerIconMap['bank'],
    ];
}
$selectedDepositAccount = null;
foreach ($depositAccountPickerOptions as $pickerOpt) {
    if ($pickerOpt['id'] === $selectedAccountId) {
        $selectedDepositAccount = $pickerOpt;
        break;
    }
}
$depositAccountMetaLabel = static function (array $opt): string {
    return trim((string) ($opt['type'] ?? ''))
        . ' | '
        . trim((string) ($opt['currency'] ?? ''))
        . ' '
        . trim((string) ($opt['balance_fmt'] ?? '0.00'));
};
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Payment | <?= $esc($invoice['invoice_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        .main-content-wrapper { padding: 2rem; }
        .page-shell { padding-left: 4rem; }
        .editor-shell { max-width: 1140px; margin: 0 auto; }
        .editor-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .editor-layout {
            display: grid;
            grid-template-columns: 180px minmax(0, 1fr);
            gap: 2rem;
            align-items: start;
        }
        .section-nav { position: sticky; top: 96px; align-self: start; }
        .section-nav ul { list-style: none; margin: 0; padding: 0; }
        .section-nav li + li { margin-top: 0.5rem; }
        .section-nav a {
            display: block;
            padding: 0.45rem 0.75rem;
            border-radius: 8px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .section-nav a:hover { background: #eff6ff; color: #2563eb; }
        .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
        .editor-main { min-width: 0; }
        .editor-section {
            padding-bottom: 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .editor-section:last-of-type { margin-bottom: 1.5rem; }
        .section-header { margin-bottom: 1.25rem; }
        .form-row {
            display: grid;
            grid-template-columns: 210px 1fr;
            align-items: start;
            margin-bottom: 24px;
        }
        .form-row:last-child { margin-bottom: 0; }
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            padding-top: 12px;
        }
        .form-label span { color: #ef4444; margin-left: 2px; }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            outline: none;
            transition: all 0.2s;
            background: #fff;
        }
        .form-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .form-input-readonly {
            background: #f8fafc;
            font-weight: 600;
            color: #334155;
            border-style: dashed;
        }
        .form-input-readonly.highlight {
            color: #2563eb;
            font-family: monospace;
        }
        .form-input-readonly.balance {
            color: #16a34a;
            font-weight: 700;
            font-size: 15px;
        }
        .form-input-price {
            color: #16a34a !important;
            font-weight: 600;
        }
        .help-text {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
            line-height: 1.5;
            font-weight: 400;
        }
        .currency-picker { position: relative; max-width: 100%; }
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
            gap: 12px;
            padding: 10px 40px 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            background: #fff url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E') no-repeat right 12px center;
            background-size: 1.25rem;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
        }
        .currency-picker-trigger:hover { border-color: #cbd5e1; }
        .currency-picker-trigger:focus,
        .currency-picker.is-open .currency-picker-trigger {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        .currency-flag {
            width: 28px;
            height: 20px;
            object-fit: cover;
            border-radius: 3px;
            box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08);
            flex-shrink: 0;
        }
        .currency-picker-label { min-width: 0; flex: 1; }
        .currency-picker-label .code {
            font-weight: 600;
            color: #0f172a;
            margin-right: 6px;
        }
        .currency-picker-label .name { color: #64748b; }
        .currency-picker-menu {
            position: absolute;
            z-index: 50;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            max-height: 320px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            padding: 6px;
        }
        .currency-picker-menu[hidden] { display: none; }
        .currency-picker-option {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            color: #1e293b;
        }
        .currency-picker-option:hover,
        .currency-picker-option.is-selected {
            background: #f3e8ff;
        }
        .currency-picker-option .code {
            font-weight: 600;
            min-width: 42px;
            color: #0f172a;
        }
        .currency-picker-option .name {
            color: #64748b;
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .account-picker { position: relative; max-width: 100%; }
        .account-picker-native {
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
        .account-picker-trigger {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 40px 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            background: #fff url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E') no-repeat right 12px center;
            background-size: 1.25rem;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
        }
        .account-picker-trigger:hover { border-color: #cbd5e1; }
        .account-picker-trigger:focus,
        .account-picker.is-open .account-picker-trigger {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        .account-picker-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .account-picker-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .account-picker-avatar.has-image i { display: none; }
        .account-picker-label { min-width: 0; flex: 1; }
        .account-picker-label .name {
            display: block;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .account-picker-label .meta {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .account-picker-menu {
            position: absolute;
            z-index: 50;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            max-height: 320px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            padding: 6px;
        }
        .account-picker-menu[hidden] { display: none; }
        .account-picker-option {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            color: #1e293b;
        }
        .account-picker-option:hover,
        .account-picker-option.is-selected { background: #f3e8ff; }
        .account-picker-text { min-width: 0; flex: 1; }
        .account-picker-text .name {
            display: block;
            font-weight: 600;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .account-picker-text .meta {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .section-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin: 0;
        }
        .btn-save {
            background: #7c3aed;
            color: white;
            padding: 14px 48px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
            border: none;
            cursor: pointer;
        }
        .btn-save:hover { background: #6d28d9; }
        .btn-cancel {
            border: 1px solid #d8b4fe;
            color: #7c3aed;
            background: #faf5ff;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-cancel:hover { background: #f3e8ff; color: #6d28d9; }
        .save-info-wrap {
            position: relative;
            display: inline-flex;
            flex-shrink: 0;
        }
        .save-info-btn {
            width: 40px;
            height: 40px;
            min-width: 40px;
            min-height: 40px;
            padding: 0 !important;
            border-radius: 50% !important;
            border: none;
            background: #ede9fe;
            color: #7c3aed;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, box-shadow 0.2s;
            flex-shrink: 0;
            font-size: 1.05rem;
            line-height: 1;
            box-sizing: border-box;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.15);
        }
        .save-info-btn:hover,
        .save-info-btn.is-open {
            background: #ddd6fe;
            color: #6d28d9;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
        }
        .save-info-pop {
            display: none;
            position: absolute;
            left: calc(100% + 12px);
            bottom: 50%;
            transform: translateY(50%);
            width: min(320px, calc(100vw - 48px));
            background: #fff;
            border: 1px solid #e9d5ff;
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
            z-index: 30;
            text-align: left;
        }
        .save-info-pop::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 50%;
            transform: translateY(-50%) rotate(45deg);
            width: 12px;
            height: 12px;
            background: #fff;
            border-left: 1px solid #e9d5ff;
            border-bottom: 1px solid #e9d5ff;
        }
        .save-info-pop.is-open { display: block; }
        .save-info-pop strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .save-info-pop ul {
            margin: 0;
            padding-left: 1.1rem;
            color: #475569;
            font-size: 13px;
            line-height: 1.55;
        }
        .save-info-pop li { margin-bottom: 4px; }
        .save-info-pop li:last-child { margin-bottom: 0; }
        @media (max-width: 640px) {
            .save-info-pop {
                left: auto;
                right: 0;
                bottom: calc(100% + 12px);
                transform: none;
            }
            .save-info-pop::before {
                left: auto;
                right: 14px;
                top: auto;
                bottom: -7px;
                transform: rotate(-45deg);
                border-left: none;
                border-bottom: none;
                border-right: 1px solid #e9d5ff;
                border-top: 1px solid #e9d5ff;
            }
        }
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
            .btn-save { width: 100%; padding: 14px 24px; }
            html body .main-content, html body .content-wrapper, html body main,
            html body.dashboard .main-content, html body .header, html body .admin-header, html body .employee-header {
                margin-left: 0 !important; width: 100% !important;
                padding-left: 0 !important; padding-right: 0 !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../../../includes/header_employee.php'; ?>
    <div class="main-content-wrapper">
        <div class="page-shell editor-shell">
            <div class="editor-topbar">
                <div>
                    <h1 class="text-xl font-semibold text-slate-800">Record Invoice Payment</h1>
                    <p class="text-sm text-slate-500 mt-1">Invoice <?= $esc($invoice['invoice_number']) ?> &middot; <?= $esc($invoice['company_name']) ?></p>
                </div>
                <a href="<?= $esc($backUrl) ?>" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2">
                    <i class="fas fa-arrow-left text-xs"></i> Back to Invoice
                </a>
            </div>

            <?php if (!empty($error)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                <?= $esc($error) ?>
            </div>
            <?php endif; ?>
            <?php if ($sessionSuccess !== ''): ?>
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-700">
                <i class="fas fa-check-circle mr-1"></i> <?= $esc($sessionSuccess) ?>
            </div>
            <?php endif; ?>

            <form id="paymentForm" method="POST" enctype="multipart/form-data">
                <div class="editor-layout">
                    <aside class="section-nav">
                        <ul>
                            <li><a href="#payment-details" class="is-active">Payment</a></li>
                            <li><a href="#additional-info">Additional</a></li>
                            <li><a href="#vfd-receipt">VFD Receipt</a></li>
                        </ul>
                    </aside>

                    <div class="editor-main">
                        <section class="editor-section" id="payment-details">
                            <div class="section-header">
                                <h2 class="section-title">Payment Details</h2>
                                <p class="section-subtitle">Enter amount, date, currency, and deposit account.</p>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Currency <span>*</span></label>
                                <div>
                                    <div class="currency-picker" id="currency_picker">
                                        <select name="currency" id="currency" required class="currency-picker-native" tabindex="-1" aria-hidden="true">
                                            <?php foreach ($currencyOptions as $code => $meta): ?>
                                                <option value="<?= $esc($code) ?>"<?= $selectedCurrency === $code ? ' selected' : '' ?>><?= $esc($code . ' - ' . $meta['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="currency-picker-trigger" id="currency_trigger" aria-haspopup="listbox" aria-expanded="false">
                                            <img src="<?= $esc($currencyFlagUrl($selectedCurrencyMeta['flag'])) ?>" alt="" class="currency-flag" id="currency_trigger_flag" width="28" height="20">
                                            <span class="currency-picker-label">
                                                <span class="code" id="currency_trigger_code"><?= $esc($selectedCurrency) ?></span>
                                                <span class="name" id="currency_trigger_name"><?= $esc($selectedCurrencyMeta['name']) ?></span>
                                            </span>
                                        </button>
                                        <div class="currency-picker-menu" id="currency_menu" role="listbox" hidden>
                                            <?php foreach ($currencyOptions as $code => $meta): ?>
                                                <button type="button"
                                                        class="currency-picker-option<?= $code === $selectedCurrency ? ' is-selected' : '' ?>"
                                                        role="option"
                                                        data-value="<?= $esc($code) ?>"
                                                        data-flag="<?= $esc($meta['flag']) ?>"
                                                        data-name="<?= $esc($meta['name']) ?>"
                                                        aria-selected="<?= $code === $selectedCurrency ? 'true' : 'false' ?>">
                                                    <img src="<?= $esc($currencyFlagUrl($meta['flag'])) ?>" alt="" class="currency-flag" width="28" height="20" loading="lazy">
                                                    <span class="code"><?= $esc($code) ?></span>
                                                    <span class="name"><?= $esc($meta['name']) ?></span>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="help-text">Currency used for this payment transaction.</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Exchange Rate</label>
                                <div>
                                    <input id="exchange_rate" name="exchange_rate" type="number" step="0.0001" min="0" class="form-input" value="<?= $esc($formValues['exchange_rate']) ?>">
                                    <div class="help-text" id="exchange_rate_hint"><?= $esc($exchangeRateHint) ?></div>
                                </div>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Payment Date <span>*</span></label>
                                <div>
                                    <input type="date" name="payment_date" class="form-input" value="<?= $esc($formValues['payment_date']) ?>" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Amount <span>*</span></label>
                                <div>
                                    <input id="payment_amount" type="number" step="0.01" name="amount" class="form-input form-input-price" value="<?= $esc($formValues['amount']) ?>" max="<?= $esc((string) $invoiceBalanceDue) ?>" required>
                                    <div class="help-text">Enter the payment amount (max <?= $esc($currency . ' ' . number_format($invoiceBalanceDue, 2)) ?>).</div>
                                </div>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Deposit Account <span>*</span></label>
                                <div>
                                    <?php if (empty($accounts)): ?>
                                    <div class="help-text">
                                        No active deposit accounts found in Balances.
                                        <a href="<?= $esc($balancesAccountsUrl) ?>" class="text-purple-600 hover:underline">Create an account</a>
                                    </div>
                                    <?php else: ?>
                                    <div class="account-picker" id="account_picker">
                                        <select id="account_id" name="account_id" class="account-picker-native" required tabindex="-1" aria-hidden="true">
                                            <option value="">Select deposit account</option>
                                            <?php foreach ($depositAccountPickerOptions as $pickerOpt): ?>
                                                <option
                                                    value="<?= (int) $pickerOpt['id'] ?>"
                                                    data-name="<?= $esc($pickerOpt['name']) ?>"
                                                    data-type="<?= $esc($pickerOpt['type']) ?>"
                                                    data-currency="<?= $esc($pickerOpt['currency']) ?>"
                                                    data-balance="<?= $esc($pickerOpt['balance_fmt']) ?>"
                                                    data-image="<?= $esc($pickerOpt['image_url']) ?>"
                                                    data-icon="<?= $esc($pickerOpt['icon']) ?>"
                                                    <?= $selectedAccountId === (int) $pickerOpt['id'] ? ' selected' : '' ?>
                                                ><?= $esc($pickerOpt['name'] . ' (' . $pickerOpt['type'] . ')') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="account-picker-trigger" id="account_trigger" aria-haspopup="listbox" aria-expanded="false">
                                            <span class="account-picker-avatar<?= $selectedDepositAccount && $selectedDepositAccount['image_url'] !== '' ? ' has-image' : '' ?>" id="account_trigger_avatar">
                                                <img id="account_trigger_image" alt=""<?= ($selectedDepositAccount && $selectedDepositAccount['image_url'] !== '') ? ' src="' . $esc($selectedDepositAccount['image_url']) . '"' : ' hidden' ?>>
                                                <i class="fas <?= $esc(($selectedDepositAccount ?? [])['icon'] ?? 'fa-university') ?>" id="account_trigger_icon" aria-hidden="true"></i>
                                            </span>
                                            <span class="account-picker-label">
                                                <span class="name" id="account_trigger_name"><?= $selectedDepositAccount ? $esc($selectedDepositAccount['name']) : 'Select deposit account' ?></span>
                                                <span class="meta" id="account_trigger_meta"><?= $selectedDepositAccount ? $esc($depositAccountMetaLabel($selectedDepositAccount)) : '' ?></span>
                                            </span>
                                        </button>
                                        <div class="account-picker-menu" id="account_menu" role="listbox" hidden>
                                            <?php foreach ($depositAccountPickerOptions as $pickerOpt): ?>
                                                <button type="button"
                                                        class="account-picker-option<?= $selectedAccountId === (int) $pickerOpt['id'] ? ' is-selected' : '' ?>"
                                                        role="option"
                                                        data-value="<?= (int) $pickerOpt['id'] ?>"
                                                        data-name="<?= $esc($pickerOpt['name']) ?>"
                                                        data-type="<?= $esc($pickerOpt['type']) ?>"
                                                        data-currency="<?= $esc($pickerOpt['currency']) ?>"
                                                        data-balance="<?= $esc($pickerOpt['balance_fmt']) ?>"
                                                        data-image="<?= $esc($pickerOpt['image_url']) ?>"
                                                        data-icon="<?= $esc($pickerOpt['icon']) ?>"
                                                        aria-selected="<?= $selectedAccountId === (int) $pickerOpt['id'] ? 'true' : 'false' ?>">
                                                    <span class="account-picker-avatar<?= $pickerOpt['image_url'] !== '' ? ' has-image' : '' ?>">
                                                        <?php if ($pickerOpt['image_url'] !== ''): ?>
                                                            <img src="<?= $esc($pickerOpt['image_url']) ?>" alt="" loading="lazy">
                                                        <?php endif; ?>
                                                        <i class="fas <?= $esc($pickerOpt['icon']) ?>" aria-hidden="true"></i>
                                                    </span>
                                                    <span class="account-picker-text">
                                                        <span class="name"><?= $esc($pickerOpt['name']) ?></span>
                                                        <span class="meta"><?= $esc($depositAccountMetaLabel($pickerOpt)) ?></span>
                                                    </span>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="help-text">Only active cash, bank, and mobile accounts from the Balances module are listed.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>

                        <section class="editor-section" id="additional-info">
                            <div class="section-header">
                                <h2 class="section-title">Reference &amp; Notes</h2>
                                <p class="section-subtitle">Optional reference and internal notes for this payment.</p>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Reference</label>
                                <div>
                                    <input type="text" name="reference" class="form-input" placeholder="e.g. CHEQ-1234, TRF-5678" value="<?= $esc($formValues['reference']) ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <label class="form-label">Notes</label>
                                <div>
                                    <textarea name="notes" rows="4" placeholder="Any additional notes about this payment..." class="form-input min-h-[120px]"><?= $esc($formValues['notes']) ?></textarea>
                                </div>
                            </div>
                        </section>

                        <section class="editor-section" id="vfd-receipt">
                            <div class="section-header">
                                <h2 class="section-title">VFD Receipt</h2>
                                <p class="section-subtitle">Attach the fiscal device receipt for this payment (optional).</p>
                            </div>
                            <div class="form-row">
                                <label class="form-label">VFD Receipt</label>
                                <div>
                                    <input type="file" name="vfd_receipt" class="form-input" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
                                    <div class="help-text">PDF, JPG, PNG, or WEBP ? max 5MB. Upload the verified fiscal receipt from your VFD device.</div>
                                </div>
                            </div>
                        </section>

                        <div class="flex justify-start items-center gap-4 mb-20">
                            <button type="button" onclick="location.href='<?= $esc($backUrl) ?>'" class="btn-cancel px-8 py-3 rounded-xl font-bold">Cancel</button>
                            <button type="submit" class="btn-save">Save Payment</button>
                            <div class="save-info-wrap">
                                <button type="button" id="paymentSaveInfoBtn" class="save-info-btn" title="What happens when you save" aria-label="What happens when you save" aria-expanded="false" aria-controls="paymentSaveInfoPop">
                                    <i class="fas fa-info" aria-hidden="true"></i>
                                </button>
                                <div id="paymentSaveInfoPop" class="save-info-pop" role="dialog" aria-labelledby="paymentSaveInfoTitle" hidden>
                                    <strong id="paymentSaveInfoTitle">What happens when you save</strong>
                                    <ul>
                                        <li>Saves payment into sales payments</li>
                                        <li>Updates invoice amount paid and status</li>
                                        <li>Marks linked sales order as paid when fully settled</li>
                                        <li>Records a credit transaction into the selected financial account</li>
                                        <li>Syncs invoice to revenue ledger</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var accountSelect = document.getElementById('account_id');
            if (!accountSelect) return;

            var picker = document.getElementById('account_picker');
            var trigger = document.getElementById('account_trigger');
            var menu = document.getElementById('account_menu');
            var triggerAvatar = document.getElementById('account_trigger_avatar');
            var triggerImage = document.getElementById('account_trigger_image');
            var triggerIcon = document.getElementById('account_trigger_icon');
            var triggerName = document.getElementById('account_trigger_name');
            var triggerMeta = document.getElementById('account_trigger_meta');
            var defaultCurrency = <?= json_encode($currency) ?>;

            function readDataFromOption(opt) {
                if (!opt || !opt.value) return null;
                return {
                    value: opt.value,
                    name: opt.getAttribute('data-name') || '?',
                    type: opt.getAttribute('data-type') || '?',
                    currency: opt.getAttribute('data-currency') || defaultCurrency,
                    balance: opt.getAttribute('data-balance') || '0.00',
                    image: opt.getAttribute('data-image') || '',
                    icon: opt.getAttribute('data-icon') || 'fa-university'
                };
            }

            function readDataFromButton(btn) {
                if (!btn) return null;
                return {
                    value: btn.getAttribute('data-value'),
                    name: btn.getAttribute('data-name') || '?',
                    type: btn.getAttribute('data-type') || '?',
                    currency: btn.getAttribute('data-currency') || defaultCurrency,
                    balance: btn.getAttribute('data-balance') || '0.00',
                    image: btn.getAttribute('data-image') || '',
                    icon: btn.getAttribute('data-icon') || 'fa-university'
                };
            }

            function formatAccountMeta(data) {
                return data.type + ' | ' + data.currency + ' ' + data.balance;
            }

            function updateAccountUi(data) {
                if (!data || !data.value) {
                    if (triggerName) triggerName.textContent = 'Select deposit account';
                    if (triggerMeta) triggerMeta.textContent = '';
                    if (triggerImage) {
                        triggerImage.hidden = true;
                        triggerImage.removeAttribute('src');
                    }
                    if (triggerAvatar) triggerAvatar.classList.remove('has-image');
                    if (triggerIcon) triggerIcon.className = 'fas fa-university';
                    return;
                }

                if (triggerName) triggerName.textContent = data.name;
                if (triggerMeta) triggerMeta.textContent = formatAccountMeta(data);
                if (triggerIcon) triggerIcon.className = 'fas ' + data.icon;
                if (data.image && triggerImage) {
                    triggerImage.src = data.image;
                    triggerImage.hidden = false;
                    if (triggerAvatar) triggerAvatar.classList.add('has-image');
                } else {
                    if (triggerImage) {
                        triggerImage.hidden = true;
                        triggerImage.removeAttribute('src');
                    }
                    if (triggerAvatar) triggerAvatar.classList.remove('has-image');
                }
            }

            function syncMenuSelection(value) {
                if (!menu) return;
                menu.querySelectorAll('.account-picker-option').forEach(function (btn) {
                    var selected = btn.getAttribute('data-value') === value;
                    btn.classList.toggle('is-selected', selected);
                    btn.setAttribute('aria-selected', selected ? 'true' : 'false');
                });
            }

            function refreshSelectedAccount() {
                var opt = accountSelect.options[accountSelect.selectedIndex];
                var data = readDataFromOption(opt);
                updateAccountUi(data);
                syncMenuSelection(data ? data.value : '');
            }

            function setAccount(value, sourceBtn, silent) {
                accountSelect.value = value || '';
                var data = sourceBtn ? readDataFromButton(sourceBtn) : readDataFromOption(accountSelect.options[accountSelect.selectedIndex]);
                updateAccountUi(data);
                syncMenuSelection(value || '');
                if (!silent) {
                    accountSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            function closeAccountMenu() {
                if (!menu || !picker || !trigger) return;
                menu.hidden = true;
                picker.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }

            function openAccountMenu() {
                if (!menu || !picker || !trigger) return;
                menu.hidden = false;
                picker.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
            }

            if (picker && trigger && menu) {
                trigger.addEventListener('click', function () {
                    if (menu.hidden) openAccountMenu();
                    else closeAccountMenu();
                });

                menu.querySelectorAll('.account-picker-option').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        setAccount(btn.getAttribute('data-value'), btn, false);
                        closeAccountMenu();
                    });
                });

                document.addEventListener('click', function (e) {
                    if (!picker.contains(e.target)) closeAccountMenu();
                });

                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') closeAccountMenu();
                });
            }

            accountSelect.addEventListener('change', refreshSelectedAccount);
            refreshSelectedAccount();

            document.querySelectorAll('.section-nav a').forEach(function (link) {
                link.addEventListener('click', function () {
                    document.querySelectorAll('.section-nav a').forEach(function (a) { a.classList.remove('is-active'); });
                    link.classList.add('is-active');
                });
            });
        })();

        (function () {
            var picker = document.getElementById('currency_picker');
            var select = document.getElementById('currency');
            var trigger = document.getElementById('currency_trigger');
            var menu = document.getElementById('currency_menu');
            var rateEl = document.getElementById('exchange_rate');
            var rateHintEl = document.getElementById('exchange_rate_hint');
            if (!picker || !select || !trigger || !menu) return;

            var flagBase = 'https://flagcdn.com/w40/';
            var rateApiUrl = 'exchange_rate.php';
            var rateFetchToken = 0;

            function formatRateHint(data) {
                if (!data || !data.ok) {
                    return (data && data.error) ? data.error : 'Could not load BOT rate. Enter manually.';
                }
                var src = data.via_ai ? 'BOT (AI)' : (data.source || 'BOT');
                var asOf = data.as_of ? (' as of ' + data.as_of) : '';
                return src + ' mean rate: ' + parseFloat(data.rate).toFixed(4) + ' TZS per 1 ' + data.currency + ' (' + src + asOf + '). You may adjust before saving.';
            }

            function syncExchangeRateField() {
                if (!rateEl) return;
                var isTzs = select.value === 'TZS';
                if (isTzs) {
                    rateEl.value = '1.0000';
                    rateEl.readOnly = true;
                    rateEl.classList.add('form-input-readonly');
                    if (rateHintEl) {
                        rateHintEl.textContent = 'TZS is the base currency (rate 1.00).';
                    }
                } else {
                    rateEl.readOnly = false;
                    rateEl.classList.remove('form-input-readonly');
                }
            }

            function fetchBotExchangeRate(code) {
                if (!rateEl || !code) return;
                if (code === 'TZS') {
                    syncExchangeRateField();
                    return;
                }
                var token = ++rateFetchToken;
                rateEl.classList.add('is-loading');
                if (rateHintEl) {
                    rateHintEl.textContent = 'Loading Bank of Tanzania exchange rate?';
                }
                fetch(rateApiUrl + '?currency=' + encodeURIComponent(code), { credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json().catch(function () {
                            return { ok: false, error: 'Invalid response from server.' };
                        });
                    })
                    .then(function (data) {
                        if (token !== rateFetchToken) return;
                        if (data.ok && data.rate) {
                            rateEl.value = parseFloat(data.rate).toFixed(4);
                        }
                        if (rateHintEl) {
                            rateHintEl.textContent = formatRateHint(data);
                        }
                    })
                    .catch(function () {
                        if (token !== rateFetchToken) return;
                        if (rateHintEl) {
                            rateHintEl.textContent = 'Could not fetch BOT rate. Enter manually.';
                        }
                    })
                    .finally(function () {
                        if (token === rateFetchToken) {
                            rateEl.classList.remove('is-loading');
                        }
                    });
            }

            function setCurrency(code, flag, name, silent) {
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
                if (!silent) {
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
                syncExchangeRateField();
            }

            function closeMenu() {
                menu.hidden = true;
                picker.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }

            function openMenu() {
                menu.hidden = false;
                picker.classList.add('is-open');
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
                        opt.getAttribute('data-name'),
                        false
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

            select.addEventListener('change', function () {
                syncExchangeRateField();
                fetchBotExchangeRate(select.value);
            });

            var initial = menu.querySelector('.currency-picker-option.is-selected');
            if (initial) {
                setCurrency(
                    initial.getAttribute('data-value'),
                    initial.getAttribute('data-flag'),
                    initial.getAttribute('data-name'),
                    true
                );
            } else {
                syncExchangeRateField();
            }
        })();

        (function () {
            var infoBtn = document.getElementById('paymentSaveInfoBtn');
            var infoPop = document.getElementById('paymentSaveInfoPop');
            if (!infoBtn || !infoPop) return;

            function closeInfoPop() {
                infoPop.classList.remove('is-open');
                infoPop.hidden = true;
                infoBtn.classList.remove('is-open');
                infoBtn.setAttribute('aria-expanded', 'false');
            }

            function openInfoPop() {
                infoPop.classList.add('is-open');
                infoPop.hidden = false;
                infoBtn.classList.add('is-open');
                infoBtn.setAttribute('aria-expanded', 'true');
            }

            infoBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (infoPop.classList.contains('is-open')) {
                    closeInfoPop();
                } else {
                    openInfoPop();
                }
            });

            document.addEventListener('click', function (e) {
                if (!infoPop.classList.contains('is-open')) return;
                if (infoBtn.contains(e.target) || infoPop.contains(e.target)) return;
                closeInfoPop();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeInfoPop();
            });
        })();
    </script>
</body>
</html>
