<?php
/**
 * Internal Transfer  shared backend helpers for React API + shell.
 */
declare(strict_types=1);

function tfBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__) . '/config/database.php';
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    if (function_exists('ensureBalancesSchema')) {
        ensureBalancesSchema();
    }

    return $pdo;
}

function tfRequireAccess(): void
{
    tfBootstrap();
    requireLogin();
}

function tfDeskShellScriptSuffix(): string
{
    return '/transfer.php';
}

function tfDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $suffix = tfDeskShellScriptSuffix();
    if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
        return rtrim(dirname($script), '/') . '/' . $relativePath;
    }

    if ($script !== '' && preg_match('#/modules/balances(?:/transfer(?:\.php)?)?$#', $script) === 1) {
        $base = preg_replace('#/modules/balances(?:/transfer(?:\.php)?)?$#', '/modules/balances', $script);
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . $relativePath;
        }
    }

    if (function_exists('app_url')) {
        return app_url('modules/balances/' . $relativePath);
    }

    return $relativePath;
}

function tfPreserveQueryKeys(array $base = []): array
{
    $out = $base;
    foreach (['module', 'company_slug'] as $key) {
        if (!empty($_GET[$key])) {
            $out[$key] = (string) $_GET[$key];
        }
    }
    return $out;
}

function tfBuildQuery(array $extra = []): string
{
    $qs = tfPreserveQueryKeys($_GET ?: []);
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($qs[$key]);
        } else {
            $qs[$key] = $value;
        }
    }
    return $qs === [] ? '' : ('?' . http_build_query($qs));
}

/**
 * @return list<array{id:int,name:string,type:string,currency:string,balance:float,bucket:string}>
 */
function tfFetchAccounts(PDO $pdo): array
{
    if (function_exists('balancesFetchAccountsWithLiveBalance')) {
        $rows = balancesFetchAccountsWithLiveBalance($pdo, true);
        $out = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? 'bank');
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'type' => $type,
                'currency' => (string) ($row['currency'] ?? 'TZS'),
                'balance' => (float) ($row['live_balance'] ?? $row['current_balance'] ?? 0),
                'bucket' => function_exists('balancesAccountLiquidityBucket')
                    ? balancesAccountLiquidityBucket($type)
                    : 'bank',
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return array_values(array_filter($out, static fn(array $a): bool => $a['id'] > 0 && $a['name'] !== ''));
    }

    $stmt = $pdo->query(
        "SELECT id, name, type, currency, current_balance
         FROM financial_accounts
         WHERE status = 'active'
         ORDER BY name"
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $out = [];
    foreach ($rows as $row) {
        $type = (string) ($row['type'] ?? 'bank');
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'type' => $type,
            'currency' => (string) ($row['currency'] ?? 'TZS'),
            'balance' => (float) ($row['current_balance'] ?? 0),
            'bucket' => function_exists('balancesAccountLiquidityBucket')
                ? balancesAccountLiquidityBucket($type)
                : 'bank',
        ];
    }
    return $out;
}

function tfResolveMethod(string $fromBucket, string $toBucket): string
{
    if (function_exists('balancesTransferMethodLabel')) {
        return balancesTransferMethodLabel($fromBucket, $toBucket);
    }
    $labels = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile' => 'Mobile Money'];
    return ($labels[$fromBucket] ?? 'Bank') . ' to ' . ($labels[$toBucket] ?? 'Bank');
}

function tfBuildInitPayload(PDO $pdo): array
{
    $accounts = tfFetchAccounts($pdo);
    $flashSuccess = trim((string) ($_SESSION['success'] ?? $_SESSION['bal_lottie_success'] ?? ''));
    if ($flashSuccess !== '') {
        unset($_SESSION['success'], $_SESSION['bal_lottie_success']);
    }

    return [
        'accounts' => $accounts,
        'defaults' => [
            'transferDate' => date('Y-m-d'),
            'referenceNo' => 'ITR-' . date('Ymd-His'),
            'currency' => 'TZS',
            'exchangeRate' => '1.00',
        ],
        'historyUrl' => 'transactions.php' . tfBuildQuery(),
        'transferUrl' => 'transfer.php' . tfBuildQuery(),
        'flashSuccess' => $flashSuccess,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{success:bool,message:string,historyUrl?:string}
 */
function tfCreateTransfer(PDO $pdo, array $input): array
{
    $fromAccount = (int) ($input['from_account'] ?? $input['fromAccount'] ?? 0);
    $toAccount = (int) ($input['to_account'] ?? $input['toAccount'] ?? 0);
    $amount = (float) ($input['amount'] ?? 0);
    $date = trim((string) ($input['transfer_date'] ?? $input['transferDate'] ?? date('Y-m-d')));
    $description = trim((string) ($input['description'] ?? ''));
    $referenceNo = trim((string) ($input['reference_no'] ?? $input['referenceNo'] ?? ''));

    if ($referenceNo === '') {
        $referenceNo = 'ITR-' . date('Ymd-His');
    }
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    if (function_exists('clean_input')) {
        $description = clean_input($description);
        $referenceNo = clean_input($referenceNo);
        $date = clean_input($date);
    }

    if ($fromAccount <= 0 || $toAccount <= 0) {
        throw new InvalidArgumentException('Please select both source and destination accounts.');
    }
    if ($fromAccount === $toAccount) {
        throw new InvalidArgumentException('Source and destination accounts cannot be the same.');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than zero.');
    }

    $accounts = tfFetchAccounts($pdo);
    $map = [];
    foreach ($accounts as $acc) {
        $map[$acc['id']] = $acc;
    }
    if (!isset($map[$fromAccount], $map[$toAccount])) {
        throw new InvalidArgumentException('One or both accounts are invalid or inactive.');
    }

    $from = $map[$fromAccount];
    $to = $map[$toAccount];
    $transferMethod = tfResolveMethod((string) $from['bucket'], (string) $to['bucket']);
    $narration = trim('Internal transfer [' . $referenceNo . '] ' . $description . ' | Method: ' . $transferMethod);
    $outDesc = 'Transfer to ' . $to['name'] . ' - ' . $narration;
    $inDesc = 'Transfer from ' . $from['name'] . ' - ' . $narration;

    try {
        $pdo->beginTransaction();

        if (function_exists('balancesRecordTransaction')) {
            $okOut = balancesRecordTransaction(
                $pdo,
                $fromAccount,
                'debit',
                $amount,
                $outDesc,
                'transfer_out',
                null,
                $date
            );
            $okIn = balancesRecordTransaction(
                $pdo,
                $toAccount,
                'credit',
                $amount,
                $inDesc,
                'transfer_in',
                null,
                $date
            );
            if (!$okOut || !$okIn) {
                throw new RuntimeException('Could not record transfer transactions.');
            }
        } else {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $stmtOut = $pdo->prepare(
                "INSERT INTO account_transactions
                (account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by)
                VALUES (?, ?, 'debit', ?, 'transfer_out', NULL, ?, ?)"
            );
            $stmtOut->execute([$fromAccount, $date, $amount, $outDesc, $userId]);

            $stmtIn = $pdo->prepare(
                "INSERT INTO account_transactions
                (account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by)
                VALUES (?, ?, 'credit', ?, 'transfer_in', NULL, ?, ?)"
            );
            $stmtIn->execute([$toAccount, $date, $amount, $inDesc, $userId]);
        }

        $pdo->commit();

        if (function_exists('recalculateBalance')) {
            recalculateBalance($fromAccount);
            recalculateBalance($toAccount);
        } elseif (function_exists('balancesRecalculateAccount')) {
            balancesRecalculateAccount($pdo, $fromAccount);
            balancesRecalculateAccount($pdo, $toAccount);
        }

        return [
            'success' => true,
            'message' => 'Transfer created successfully.',
            'historyUrl' => 'transactions.php' . tfBuildQuery(),
            'transferUrl' => 'transfer.php' . tfBuildQuery(),
            'transferMethod' => $transferMethod,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
