<?php

declare(strict_types=1);

/**
 * Revenue create form init + entry creation (used by React create API).
 */

function revenue_create_allowed_currencies(): array
{
    return [
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
}

/**
 * @return array<string, mixed>
 */
function revenue_resolve_balances_revenue_parent(PDO $pdo, int $preferredId = 83): array
{
    $empty = ['id' => 0, 'name' => '', 'code' => ''];

    if (!function_exists('tableExists') || !tableExists('financial_accounts', $pdo)) {
        return $empty;
    }

    if (function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }

    $candidates = [];
    if ($preferredId > 0) {
        $candidates[] = $preferredId;
    }

    try {
        $rows = $pdo->query(
            "SELECT id FROM financial_accounts
             WHERE (parent_id IS NULL OR parent_id = 0)
               AND LOWER(COALESCE(type, '')) = 'revenue'
               AND LOWER(COALESCE(status, 'active')) = 'active'
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $rowId) {
            $id = (int) $rowId;
            if ($id > 0 && !in_array($id, $candidates, true)) {
                $candidates[] = $id;
            }
        }
    } catch (Throwable $e) {
        return $empty;
    }

    foreach ($candidates as $candidateId) {
        try {
            $st = $pdo->prepare(
                "SELECT id, name FROM financial_accounts
                 WHERE id = ?
                   AND (parent_id IS NULL OR parent_id = 0)
                   AND LOWER(COALESCE(status, 'active')) = 'active'
                 LIMIT 1"
            );
            $st->execute([$candidateId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }

            $nameRaw = (string) ($row['name'] ?? '');
            $code = '';
            $name = $nameRaw;
            if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
                $code = trim($m[1]);
                $name = trim($m[2]);
            }

            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $name !== '' ? $name : $nameRaw,
                'code' => $code,
            ];
        } catch (Throwable $e) {
            continue;
        }
    }

    return $empty;
}

/**
 * @return list<array{id:int,code:string,name:string,label:string}>
 */
function revenue_fetch_balances_revenue_sub_accounts(PDO $pdo, int $parentId = 0): array
{
    if (!function_exists('balances_fetch_raw_child_accounts')) {
        return [];
    }

    $parent = $parentId > 0
        ? ['id' => $parentId, 'name' => '', 'code' => '']
        : revenue_resolve_balances_revenue_parent($pdo);
    $parentId = (int) ($parent['id'] ?? 0);
    if ($parentId <= 0) {
        return [];
    }

    $rows = balances_fetch_raw_child_accounts($pdo, $parentId, true);
    $options = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $formatted = function_exists('balances_format_account_row_for_ui')
            ? balances_format_account_row_for_ui($row)
            : $row;
        $code = trim((string) ($formatted['code'] ?? ''));
        $name = trim((string) ($formatted['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['name'] ?? ''));
        }
        $label = $code !== '' && $code !== '-' ? "{$code} - {$name}" : $name;

        $options[] = [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'label' => $label !== '' ? $label : ('Account #' . $id),
        ];
    }

    usort($options, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return $options;
}

/**
 * Resolve a financial_accounts row to an erp_accounts.id for journal posting.
 * Group (parent) accounts with sub-accounts are skipped by fa_gl_link_financial_account,
 * so we fall back to fa_gl_infer_erp_account when linking returns nothing.
 */
function revenue_resolve_financial_account_gl_for_posting(PDO $pdo, int $financialAccountId): int
{
    if ($financialAccountId <= 0) {
        return 0;
    }

    if (is_file(dirname(__DIR__, 3) . '/includes/fa_gl_linking.php')) {
        require_once dirname(__DIR__, 3) . '/includes/fa_gl_linking.php';
    }

    if (function_exists('fa_gl_link_financial_account')) {
        $linked = (int) (fa_gl_link_financial_account($pdo, $financialAccountId) ?: 0);
        if ($linked > 0) {
            return $linked;
        }
    }

    if (function_exists('fa_gl_infer_erp_account') && function_exists('fa_gl_fa_select_columns')) {
        try {
            $st = $pdo->prepare(
                'SELECT ' . fa_gl_fa_select_columns($pdo) . ' FROM financial_accounts WHERE id = ? LIMIT 1'
            );
            $st->execute([$financialAccountId]);
            $fa = $st->fetch(PDO::FETCH_ASSOC);
            if ($fa) {
                $inferred = (int) (fa_gl_infer_erp_account($pdo, $fa) ?: 0);
                if ($inferred > 0) {
                    return $inferred;
                }
            }
        } catch (Throwable $e) {
            // try revenue helpers below
        }
    }

    return revenue_resolve_financial_account_gl_via_revenue_helpers($pdo, $financialAccountId);
}

/**
 * Last-resort mapping from Balances financial_accounts to erp_accounts via revenue helpers.
 */
function revenue_resolve_financial_account_gl_via_revenue_helpers(PDO $pdo, int $financialAccountId): int
{
    if ($financialAccountId <= 0 || !function_exists('tableExists') || !tableExists('erp_accounts', $pdo)) {
        return 0;
    }

    require_once dirname(__DIR__, 3) . '/includes/revenue_account_helpers.php';
    revenue_ensure_account_schema($pdo);

    try {
        $st = $pdo->prepare('SELECT id, name, parent_id, type FROM financial_accounts WHERE id = ? LIMIT 1');
        $st->execute([$financialAccountId]);
        $fa = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fa) {
            return 0;
        }

        $display = revenue_parse_financial_account_display_name((string) ($fa['name'] ?? ''));
        if ($display === '') {
            return 0;
        }

        $parentFaId = (int) ($fa['parent_id'] ?? 0);
        if ($parentFaId > 0) {
            $parentSt = $pdo->prepare('SELECT name FROM financial_accounts WHERE id = ? LIMIT 1');
            $parentSt->execute([$parentFaId]);
            $parentDisplay = revenue_parse_financial_account_display_name((string) ($parentSt->fetchColumn() ?: ''));
            if ($parentDisplay === '') {
                $parentDisplay = 'Revenue';
            }

            $categoryId = revenue_find_or_create_category($pdo, $parentDisplay);
            revenue_ensure_child_account($pdo, $categoryId, $display);

            $childSt = $pdo->prepare(
                "SELECT id FROM erp_accounts
                 WHERE type = 'revenue' AND parent_id = ? AND LOWER(name) = LOWER(?)
                 LIMIT 1"
            );
            $childSt->execute([$categoryId, $display]);

            return (int) ($childSt->fetchColumn() ?: 0);
        }

        return revenue_find_or_create_category($pdo, $display);
    } catch (Throwable $e) {
        return 0;
    }
}

function revenue_parse_financial_account_display_name(string $nameRaw): string
{
    $nameRaw = trim($nameRaw);
    if ($nameRaw === '') {
        return '';
    }
    if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $matches)) {
        return trim($matches[2]);
    }

    return $nameRaw;
}

/**
 * @return array{category_id:int,account_id:int,financial_sub_account_id:int,financial_parent_id:int}|null
 */
function revenue_resolve_balances_sub_account_for_posting(PDO $pdo, int $financialSubId, int $preferredParentId = 83): ?array
{
    if ($financialSubId <= 0 || !function_exists('tableExists') || !tableExists('financial_accounts', $pdo)) {
        return null;
    }

    if (is_file(dirname(__DIR__, 3) . '/includes/fa_gl_linking.php')) {
        require_once dirname(__DIR__, 3) . '/includes/fa_gl_linking.php';
    }

    try {
        $st = $pdo->prepare(
            "SELECT id, parent_id, status FROM financial_accounts WHERE id = ? LIMIT 1"
        );
        $st->execute([$financialSubId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    $parentId = (int) ($row['parent_id'] ?? 0);
    if (!$row || $parentId <= 0) {
        return null;
    }

    if (strtolower(trim((string) ($row['status'] ?? 'active'))) !== 'active') {
        return null;
    }

    try {
        $parentSt = $pdo->prepare(
            "SELECT id, type, status FROM financial_accounts
             WHERE id = ?
               AND (parent_id IS NULL OR parent_id = 0)
             LIMIT 1"
        );
        $parentSt->execute([$parentId]);
        $parentRow = $parentSt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!$parentRow || strtolower(trim((string) ($parentRow['type'] ?? ''))) !== 'revenue') {
        return null;
    }

    if (strtolower(trim((string) ($parentRow['status'] ?? 'active'))) !== 'active') {
        return null;
    }

    $accountGlId = revenue_resolve_financial_account_gl_for_posting($pdo, $financialSubId);
    $categoryGlId = revenue_resolve_financial_account_gl_for_posting($pdo, $parentId);

    if ($accountGlId <= 0 || $categoryGlId <= 0) {
        return null;
    }

    return [
        'category_id' => $categoryGlId,
        'account_id' => $accountGlId,
        'financial_sub_account_id' => $financialSubId,
        'financial_parent_id' => $parentId,
    ];
}

/**
 * @return array<string, mixed>
 */
function revenue_build_create_init(PDO $pdo): array
{
    require_once dirname(__DIR__, 3) . '/includes/revenue_account_helpers.php';
    if (is_file(dirname(__DIR__, 3) . '/includes/bot_exchange_rates.php')) {
        require_once dirname(__DIR__, 3) . '/includes/bot_exchange_rates.php';
    }
    if (is_file(dirname(__DIR__, 3) . '/modules/balances/functions.php')) {
        require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';
    }

    revenue_ensure_account_schema($pdo);

    $revenueParent = revenue_resolve_balances_revenue_parent($pdo, 83);
    $subAccounts = revenue_fetch_balances_revenue_sub_accounts($pdo, (int) ($revenueParent['id'] ?? 0));
    $defaultSubAccountId = !empty($subAccounts[0]['id']) ? (int) $subAccounts[0]['id'] : 0;

    $financialAccounts = [];
    try {
        $rows = $pdo->query(
            "SELECT id, name, currency, type FROM financial_accounts WHERE status = 'active' ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $bucket = function_exists('balancesAccountLiquidityBucket')
                ? balancesAccountLiquidityBucket((string) ($row['type'] ?? 'bank'))
                : 'bank';
            $financialAccounts[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'TZS'),
                'type' => (string) ($row['type'] ?? ''),
                'bucket' => $bucket,
            ];
        }
    } catch (Throwable $e) {
        $financialAccounts = [];
    }

    $customers = [];
    try {
        $customerRows = $pdo->query(
            'SELECT customer_name FROM revenue_customers ORDER BY customer_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($customerRows as $row) {
            $name = trim((string) ($row['customer_name'] ?? ''));
            if ($name !== '') {
                $customers[] = $name;
            }
        }
    } catch (Throwable $e) {
        $customers = [];
    }

    $currencyMap = revenue_create_allowed_currencies();
    $currencies = [];
    foreach ($currencyMap as $code => $meta) {
        $currencies[] = [
            'code' => $code,
            'iso' => $code,
            'name' => $meta['name'],
            'flag' => $meta['flag'],
        ];
    }

    $defaultCurrency = 'TZS';
    $defaultExchangeRate = 1.0;
    if (function_exists('bot_get_exchange_rate')) {
        $bot = bot_get_exchange_rate($defaultCurrency);
        if ($bot !== null) {
            $defaultExchangeRate = (float) ($bot['rate'] ?? 1.0);
        }
    }

    $listUrl = function_exists('app_url')
        ? app_url('/revenue_entries.php?module=revenue')
        : '/revenue_entries.php?module=revenue';
    $createCustomerUrl = function_exists('app_url')
        ? app_url('/revenue_customer_create.php?module=revenue')
        : '/revenue_customer_create.php?module=revenue';
    $balancesAccountsUrl = function_exists('app_url')
        ? app_url('/modules/balances/accounts.php?module=balances&selected=' . (int) ($revenueParent['id'] ?? 83))
        : '/modules/balances/accounts.php?module=balances&selected=' . (int) ($revenueParent['id'] ?? 83);

    return [
        'default_currency' => $defaultCurrency,
        'default_exchange_rate' => $defaultExchangeRate,
        'revenue_parent' => $revenueParent,
        'sub_accounts' => $subAccounts,
        'default_sub_account_id' => $defaultSubAccountId,
        'balances_accounts_url' => $balancesAccountsUrl,
        'financial_accounts' => $financialAccounts,
        'customers' => $customers,
        'currencies' => $currencies,
        'payment_modes' => [
            ['value' => 'Cash', 'label' => 'Cash'],
            ['value' => 'Bank', 'label' => 'Bank Transfer'],
            ['value' => 'Mobile', 'label' => 'Mobile Payment'],
            ['value' => 'Account Receivable', 'label' => 'Debt (Receive Later)'],
        ],
        'vat_rates' => [
            ['value' => '18', 'label' => '18%'],
            ['value' => '10', 'label' => '10%'],
            ['value' => '0', 'label' => '0% (Exempt)'],
        ],
        'list_url' => $listUrl,
        'create_customer_url' => $createCustomerUrl,
    ];
}

/**
 * @param array<string, mixed> $post
 * @param array<string, mixed>|null $file
 * @return array<string, mixed>
 */
function revenue_process_create_entry(PDO $pdo, array $post, ?array $file = null): array
{
    require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
    require_once dirname(__DIR__, 3) . '/includes/accounting_service.php';
    require_once dirname(__DIR__, 3) . '/includes/revenue_account_helpers.php';
    require_once dirname(__DIR__, 3) . '/includes/accounting_settings.php';
    require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';
    require_once dirname(__DIR__, 3) . '/includes/invoice_gl_posting.php';

    revenue_ensure_account_schema($pdo);

    $errors = [];
    $entryDate = trim((string) ($post['entry_date'] ?? ''));
    $customerName = trim((string) ($post['customer_name'] ?? ''));
    $narration = trim((string) ($post['narration'] ?? $post['description'] ?? ''));
    $paymentMode = trim((string) ($post['payment_mode'] ?? ''));
    $amountTotalRaw = (float) ($post['amount_exclusive'] ?? 0);
    $taxTreatment = (string) ($post['tax_treatment'] ?? 'Exclusive');
    $vatRateRaw = (float) ($post['vat_rate'] ?? 18);

    if ($entryDate === '') {
        $errors[] = 'Revenue date is required.';
    }
    if ($customerName === '') {
        $errors[] = 'Customer is required.';
    }
    if ($paymentMode === '') {
        $errors[] = 'Payment method is required.';
    }
    if ($amountTotalRaw <= 0) {
        $errors[] = 'Amount must be greater than zero.';
    }

    $amountExclusive = 0.0;
    $vatAmount = 0.0;
    $amountTotal = 0.0;

    if ($taxTreatment === 'Inclusive') {
        $amountTotal = round($amountTotalRaw, 2);
        $vatAmount = round($amountTotal * ($vatRateRaw / (100 + $vatRateRaw)), 2);
        $amountExclusive = round($amountTotal - $vatAmount, 2);
    } elseif ($taxTreatment === 'Exclusive') {
        $amountExclusive = round($amountTotalRaw, 2);
        $vatAmount = round($amountExclusive * ($vatRateRaw / 100), 2);
        $amountTotal = round($amountExclusive + $vatAmount, 2);
    } else {
        $amountExclusive = round($amountTotalRaw, 2);
        $vatAmount = 0.0;
        $amountTotal = $amountExclusive;
    }

    $totalPaid = 0.0;
    $paymentStatus = 'Unpaid';
    $immediatePaymentModes = ['Cash', 'Bank', 'Mobile'];
    if (in_array($paymentMode, $immediatePaymentModes, true)) {
        $totalPaid = $amountTotal;
        $paymentStatus = 'Paid';
    }

    $attachment = '';
    if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $targetDir = dirname(__DIR__, 3) . '/uploads/revenue/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $fileExt = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION);
        $fileName = 'REV_' . time() . '.' . $fileExt;
        $targetFile = $targetDir . $fileName;
        if (move_uploaded_file((string) $file['tmp_name'], $targetFile)) {
            $attachment = 'uploads/revenue/' . $fileName;
        } else {
            $errors[] = 'Could not save the uploaded file.';
        }
    } else {
        $errors[] = 'Attachment is required.';
    }

    $allowedCurrencies = array_keys(revenue_create_allowed_currencies());
    $currency = strtoupper(trim((string) ($post['currency'] ?? 'TZS')));
    if (!in_array($currency, $allowedCurrencies, true)) {
        $currency = 'TZS';
    }

    $exchangeRate = (float) ($post['exchange_rate'] ?? 1);
    if ($currency === 'TZS') {
        $exchangeRate = 1.0;
    } elseif ($exchangeRate <= 0) {
        $errors[] = 'Enter a valid exchange rate greater than zero.';
    }

    $revenueSubAccountId = (int) ($post['revenue_sub_account_id'] ?? 0);
    $revenueCategoryId = (int) ($post['revenue_category_id'] ?? 0);
    $revenueAccountId = (int) ($post['revenue_account_id'] ?? 0);
    $resolvedRevenueAccounts = null;

    if ($revenueSubAccountId > 0) {
        $resolvedRevenueAccounts = revenue_resolve_balances_sub_account_for_posting($pdo, $revenueSubAccountId, 83);
        if ($resolvedRevenueAccounts === null) {
            $errors[] = 'Please select a valid revenue sub-account from Balances.';
        }
    } else {
        $resolvedRevenueAccounts = revenue_resolve_posted_entry_accounts($pdo, $revenueCategoryId, $revenueAccountId);
        if ($resolvedRevenueAccounts === null) {
            $errors[] = 'Please select a valid revenue sub-account.';
        }
    }

    $depositAccountId = !empty($post['account_id']) ? (int) $post['account_id'] : 0;
    if (in_array($paymentMode, $immediatePaymentModes, true)) {
        if ($depositAccountId <= 0) {
            $errors[] = 'Please select a deposit account for this payment method.';
        } else {
            $bucketByMode = [
                'Bank' => 'bank',
                'Cash' => 'cash',
                'Mobile' => 'mobile',
            ];
            $bucketMessages = [
                'Bank' => 'Bank Transfer requires a bank account in Deposit To.',
                'Cash' => 'Cash payment requires a cash account in Deposit To.',
                'Mobile' => 'Mobile payment requires a mobile money account in Deposit To.',
            ];
            $expectedBucket = $bucketByMode[$paymentMode] ?? '';
            $accStmt = $pdo->prepare('SELECT type FROM financial_accounts WHERE id = ? AND status = \'active\' LIMIT 1');
            $accStmt->execute([$depositAccountId]);
            $accType = (string) $accStmt->fetchColumn();
            if ($accType === '' || !function_exists('balancesAccountLiquidityBucket')) {
                $errors[] = 'Please select a valid deposit account.';
            } else {
                $actualBucket = balancesAccountLiquidityBucket($accType);
                if ($expectedBucket === '' || $actualBucket !== $expectedBucket) {
                    $errors[] = $bucketMessages[$paymentMode] ?? 'Please select a valid deposit account for this payment method.';
                }
            }
        }
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    $revenueGlAccountId = (int) $resolvedRevenueAccounts['account_id'];
    $revenueGlCategoryId = (int) $resolvedRevenueAccounts['category_id'];
    $revenueEntryAccountCol = resolveExistingColumn('revenue_entries', 'account_id', ['bank_account_id', 'gl_account_id', 'financial_account_id']);

    try {
        $voucherNumber = generateRevenueVoucherNumber($pdo);
        $pdo->beginTransaction();

        $hasRevenueAccountCol = columnExists('revenue_entries', 'revenue_account_id', $pdo);
        $hasRevenueCategoryCol = columnExists('revenue_entries', 'revenue_category_id', $pdo);
        $hasCurrencyCol = columnExists('revenue_entries', 'currency', $pdo);
        $hasExchangeRateCol = columnExists('revenue_entries', 'exchange_rate', $pdo);

        $insertCols = [
            'voucher_number', 'entry_date', 'customer_name', 'narration', 'payment_mode',
            'amount_exclusive', 'vat_amount', 'amount_total', 'total_paid', 'payment_status',
            'approval_status', 'attachment',
        ];
        $insertVals = [
            $voucherNumber, $entryDate, $customerName, $narration, $paymentMode,
            $amountExclusive, $vatAmount, $amountTotal, $totalPaid, $paymentStatus,
            'Pending', $attachment,
        ];

        if ($revenueEntryAccountCol) {
            $insertCols[] = $revenueEntryAccountCol;
            $insertVals[] = $depositAccountId > 0 ? $depositAccountId : null;
        }
        if ($hasRevenueCategoryCol) {
            $insertCols[] = 'revenue_category_id';
            $insertVals[] = $revenueGlCategoryId;
        }
        if ($hasRevenueAccountCol) {
            $insertCols[] = 'revenue_account_id';
            $insertVals[] = $revenueGlAccountId;
        }
        if ($hasCurrencyCol) {
            $insertCols[] = 'currency';
            $insertVals[] = $currency;
        }
        if ($hasExchangeRateCol) {
            $insertCols[] = 'exchange_rate';
            $insertVals[] = round($exchangeRate, 6);
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmt = $pdo->prepare(
            'INSERT INTO revenue_entries (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
        );
        $stmt->execute($insertVals);
        $entryId = (int) $pdo->lastInsertId();

        invoice_gl_post_revenue_recognition(
            $pdo,
            $entryId,
            $voucherNumber,
            $entryDate,
            $customerName,
            $narration,
            $amountTotal,
            $amountExclusive,
            $vatAmount,
            $revenueGlAccountId ?: null
        );

        if (in_array($paymentMode, $immediatePaymentModes, true) && $depositAccountId > 0) {
            invoice_gl_post_revenue_payment(
                $pdo,
                $entryId,
                $voucherNumber,
                $entryDate,
                $amountTotal,
                $depositAccountId
            );
            $description = "Revenue: $voucherNumber - $customerName ($narration)";
            recordTransaction($depositAccountId, 'credit', $amountTotal, $description, 'revenue_entry', $entryId, $entryDate);
        }

        $pdo->commit();

        $listUrl = function_exists('app_url')
            ? app_url('/revenue_entries.php?module=revenue')
            : '/revenue_entries.php?module=revenue';
        $redirect = $listUrl . '&success=' . rawurlencode('Entry created successfully (' . $voucherNumber . ')');

        return [
            'ok' => true,
            'entry_id' => $entryId,
            'voucher_number' => $voucherNumber,
            'message' => 'Entry created successfully (' . $voucherNumber . ').',
            'redirect' => $redirect,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('revenue_process_create_entry: ' . $e->getMessage());

        return ['ok' => false, 'errors' => ['Could not save entry. ' . $e->getMessage()]];
    }
}
