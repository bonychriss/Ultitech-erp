<?php
/**
 * Transaction Ledger  shared backend helpers for React API + shell.
 */
declare(strict_types=1);

function tlBootstrap(): PDO
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

    return $pdo;
}

function tlRequireAccess(): void
{
    tlBootstrap();
    requireLogin();
}

function tlDeskShellScriptSuffix(): string
{
    return '/transactions.php';
}

function tlDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $suffix = tlDeskShellScriptSuffix();
    if ($script !== '' && substr($script, -strlen($suffix)) === $suffix) {
        return rtrim(dirname($script), '/') . '/' . $relativePath;
    }

    // Ultimate/company rewrite may omit ".php" (/transactions) or alter SCRIPT_NAME.
    if ($script !== '' && preg_match('#/modules/balances(?:/transactions(?:\.php)?)?$#', $script) === 1) {
        $base = preg_replace('#/modules/balances(?:/transactions(?:\.php)?)?$#', '/modules/balances', $script);
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/') . '/' . $relativePath;
        }
    }

    if (function_exists('app_url')) {
        return app_url('modules/balances/' . $relativePath);
    }

    return $relativePath;
}

function tlPreserveQueryKeys(array $base = []): array
{
    $out = $base;
    foreach (['module', 'company_slug'] as $key) {
        if (!empty($_GET[$key])) {
            $out[$key] = (string) $_GET[$key];
        }
    }
    return $out;
}

function tlBuildQuery(array $extra = []): string
{
    $qs = tlPreserveQueryKeys($_GET ?: []);
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($qs[$key]);
        } else {
            $qs[$key] = $value;
        }
    }
    return $qs === [] ? '' : ('?' . http_build_query($qs));
}

function tlFormatAmount(float $amount, bool $withPrefix = true): string
{
    $formatted = number_format($amount, 2, '.', ',');
    return $withPrefix ? 'TZS ' . $formatted : $formatted;
}

/**
 * @return array{q: string, date_from: string, date_to: string, type: string, amount_min: string, amount_max: string}
 */
function tlDefaultFilters(): array
{
    return [
        'q' => '',
        'date_from' => '',
        'date_to' => '',
        'type' => '',
        'amount_min' => '',
        'amount_max' => '',
    ];
}

function tlNormalizeAmountFilter(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $lower = strtolower($raw);
    $multiplier = 1.0;
    if (preg_match('/^([\d.,]+)\s*(k|m|million|billion|b)?$/i', $lower, $m) === 1) {
        $num = (float) str_replace(',', '', $m[1]);
        $suffix = strtolower((string) ($m[2] ?? ''));
        if ($suffix === 'k') {
            $multiplier = 1000.0;
        } elseif ($suffix === 'm' || $suffix === 'million') {
            $multiplier = 1000000.0;
        } elseif ($suffix === 'b' || $suffix === 'billion') {
            $multiplier = 1000000000.0;
        }
        return $num > 0 ? (string) (int) round($num * $multiplier) : '';
    }
    $digits = preg_replace('/[^\d.]/', '', $raw) ?? '';
    if ($digits === '' || !is_numeric($digits)) {
        return '';
    }
    return (string) (int) round((float) $digits);
}

/**
 * @param array<string, mixed> $input
 * @return array{q: string, date_from: string, date_to: string, type: string, amount_min: string, amount_max: string}
 */
function tlParseFilters(array $input): array
{
    $filters = tlDefaultFilters();
    $filters['q'] = trim((string) ($input['q'] ?? $input['search'] ?? ''));
    $filters['date_from'] = trim((string) ($input['date_from'] ?? ''));
    $filters['date_to'] = trim((string) ($input['date_to'] ?? ''));
    $type = strtolower(trim((string) ($input['type'] ?? '')));
    $filters['type'] = in_array($type, ['credit', 'debit', 'transfer'], true) ? $type : '';
    $filters['amount_min'] = tlNormalizeAmountFilter((string) ($input['amount_min'] ?? ''));
    $filters['amount_max'] = tlNormalizeAmountFilter((string) ($input['amount_max'] ?? ''));

    if ($filters['date_from'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $filters['date_from'] = '';
    }
    if ($filters['date_to'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $filters['date_to'] = '';
    }

    return $filters;
}

function tlSearchClauses(): array
{
    return [
        'a.name LIKE ?',
        'IFNULL(t.description, "") LIKE ?',
        'IFNULL(t.reference_type, "") LIKE ?',
        'REPLACE(IFNULL(t.reference_type, ""), "_", " ") LIKE ?',
        'CAST(IFNULL(t.reference_id, "") AS CHAR) LIKE ?',
        'IFNULL(u.full_name, "") LIKE ?',
        'IFNULL(u.full_name, "System") LIKE ?',
        't.type LIKE ?',
        'CASE WHEN IFNULL(t.reference_type, "") LIKE "%transfer%" THEN "Transfer" WHEN t.type = "credit" THEN "Credit" ELSE "Debit" END LIKE ?',
        'CAST(t.amount AS CHAR) LIKE ?',
        'REPLACE(FORMAT(t.amount, 2), ",", "") LIKE ?',
        'CONCAT(CASE WHEN t.type = "credit" THEN "+ " ELSE "- " END, "TZS ", FORMAT(t.amount, 2)) LIKE ?',
        'DATE_FORMAT(t.transaction_date, "%b %d, %Y %H:%i") LIKE ?',
        'DATE_FORMAT(t.transaction_date, "%Y-%m-%d %H:%i") LIKE ?',
        'DATE_FORMAT(t.transaction_date, "%d %b %Y") LIKE ?',
        'DATE_FORMAT(t.transaction_date, "%b %d, %Y") LIKE ?',
    ];
}

/**
 * @param array{q?: string, date_from?: string, date_to?: string, type?: string, amount_min?: string, amount_max?: string}|string $filtersOrQ
 * @return array{where: string, params: list<mixed>}
 */
function tlBuildSearch($filtersOrQ): array
{
    $filters = is_array($filtersOrQ)
        ? tlParseFilters($filtersOrQ)
        : tlParseFilters(['q' => (string) $filtersOrQ]);

    $parts = [];
    $params = [];

    $searchQ = $filters['q'];
    if ($searchQ !== '') {
        $clauses = tlSearchClauses();
        $searchLike = '%' . $searchQ . '%';
        $amountDigits = preg_replace('/\D/', '', $searchQ) ?? '';
        $amountLike = $amountDigits !== '' ? '%' . $amountDigits . '%' : $searchLike;
        $textParams = array_fill(0, count($clauses), $searchLike);
        $textParams[10] = $amountLike;
        $parts[] = '(' . implode(' OR ', $clauses) . ')';
        foreach ($textParams as $param) {
            $params[] = $param;
        }
    }

    if ($filters['date_from'] !== '') {
        $parts[] = 'DATE(t.transaction_date) >= ?';
        $params[] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $parts[] = 'DATE(t.transaction_date) <= ?';
        $params[] = $filters['date_to'];
    }

    if ($filters['type'] === 'transfer') {
        $parts[] = 'IFNULL(t.reference_type, "") LIKE ?';
        $params[] = '%transfer%';
    } elseif ($filters['type'] === 'credit' || $filters['type'] === 'debit') {
        $parts[] = 't.type = ?';
        $params[] = $filters['type'];
        $parts[] = 'IFNULL(t.reference_type, "") NOT LIKE ?';
        $params[] = '%transfer%';
    }

    if ($filters['amount_min'] !== '') {
        $parts[] = 't.amount >= ?';
        $params[] = (float) $filters['amount_min'];
    }
    if ($filters['amount_max'] !== '') {
        $parts[] = 't.amount <= ?';
        $params[] = (float) $filters['amount_max'];
    }

    if ($parts === []) {
        return ['where' => '', 'params' => []];
    }

    return [
        'where' => ' WHERE ' . implode(' AND ', $parts),
        'params' => $params,
    ];
}

/**
 * @param array{q?: string, date_from?: string, date_to?: string, type?: string, amount_min?: string, amount_max?: string}|string $filtersOrQ
 */
function tlPeriodLabel($filtersOrQ): string
{
    $filters = is_array($filtersOrQ)
        ? tlParseFilters($filtersOrQ)
        : tlParseFilters(['q' => (string) $filtersOrQ]);

    $bits = [];
    if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
        $from = $filters['date_from'] !== '' ? $filters['date_from'] : '...';
        $to = $filters['date_to'] !== '' ? $filters['date_to'] : '...';
        $bits[] = $from . ' to ' . $to;
    }
    if ($filters['type'] !== '') {
        $bits[] = ucfirst($filters['type']);
    }
    if ($filters['amount_min'] !== '' || $filters['amount_max'] !== '') {
        $min = $filters['amount_min'] !== '' ? tlFormatAmount((float) $filters['amount_min'], false) : '...';
        $max = $filters['amount_max'] !== '' ? tlFormatAmount((float) $filters['amount_max'], false) : '...';
        $bits[] = $min . ' - ' . $max;
    }
    if ($filters['q'] !== '') {
        $bits[] = 'Search: "' . $filters['q'] . '"';
    }

    return $bits === [] ? 'All transactions' : implode('  ', $bits);
}

/**
 * @param array{q?: string, date_from?: string, date_to?: string, type?: string, amount_min?: string, amount_max?: string}|string $filtersOrQ
 */
function tlFetchStats(PDO $pdo, $filtersOrQ): array
{
    $search = tlBuildSearch($filtersOrQ);
    $sql = "
        SELECT
            COUNT(*) AS total_entries,
            COALESCE(SUM(CASE WHEN t.type = 'credit' THEN t.amount ELSE 0 END), 0) AS total_inflows,
            COALESCE(SUM(CASE WHEN t.type = 'debit' THEN t.amount ELSE 0 END), 0) AS total_outflows,
            COALESCE(SUM(CASE WHEN t.type = 'credit' THEN 1 ELSE 0 END), 0) AS credit_count,
            COALESCE(SUM(CASE WHEN t.type = 'debit' THEN 1 ELSE 0 END), 0) AS debit_count,
            COALESCE(SUM(CASE WHEN COALESCE(t.reference_type, '') LIKE '%transfer%' THEN 1 ELSE 0 END), 0) AS transfer_count
        FROM account_transactions t
        JOIN financial_accounts a ON t.account_id = a.id
        LEFT JOIN users u ON t.created_by = u.id
        {$search['where']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($search['params']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalEntries = (int) ($row['total_entries'] ?? 0);
    $totalInflows = (float) ($row['total_inflows'] ?? 0);
    $totalOutflows = (float) ($row['total_outflows'] ?? 0);

    return [
        'totalEntries' => $totalEntries,
        'totalInflows' => $totalInflows,
        'totalOutflows' => $totalOutflows,
        'creditCount' => (int) ($row['credit_count'] ?? 0),
        'debitCount' => (int) ($row['debit_count'] ?? 0),
        'transferCount' => (int) ($row['transfer_count'] ?? 0),
        'netMovement' => $totalInflows - $totalOutflows,
        'periodLabel' => tlPeriodLabel($filtersOrQ),
    ];
}

function tlNormalizePagination(array $stats, string $perPageRaw, int $page): array
{
    $totalEntries = (int) ($stats['totalEntries'] ?? 0);
    $viewAll = is_string($perPageRaw) && strtolower($perPageRaw) === 'all';

    if ($viewAll) {
        return [
            'viewAll' => true,
            'page' => 1,
            'perPage' => max(1, $totalEntries),
            'totalPages' => 1,
        ];
    }

    $perPage = max(5, min(100, (int) $perPageRaw));
    $totalPages = $totalEntries > 0 ? (int) ceil($totalEntries / $perPage) : 1;
    $page = max(1, min($page, $totalPages));

    return [
        'viewAll' => false,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => $totalPages,
    ];
}

function tlFetchTransactions(PDO $pdo, $filtersOrQ, int $perPage, int $offset): array
{
    $search = tlBuildSearch($filtersOrQ);
    $sql = "SELECT t.*, a.name AS account_name, u.full_name AS user_name
        FROM account_transactions t
        JOIN financial_accounts a ON t.account_id = a.id
        LEFT JOIN users u ON t.created_by = u.id
        {$search['where']}
        ORDER BY t.transaction_date DESC
        LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($search['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tlMapTransactionRow(array $tx): array
{
    $isCredit = ($tx['type'] ?? '') === 'credit';
    $refType = (string) ($tx['reference_type'] ?? '');
    $typeClass = $isCredit ? 'credit' : 'debit';
    $typeLabel = $isCredit ? 'Credit' : 'Debit';
    if (strpos($refType, 'transfer') !== false) {
        $typeClass = 'transfer';
        $typeLabel = 'Transfer';
    }

    $amount = (float) ($tx['amount'] ?? 0);
    $sign = $isCredit ? '+' : '-';
    $referenceLabel = '';
    if ($refType !== '') {
        $referenceLabel = ucfirst(str_replace('_', ' ', $refType));
        if (!empty($tx['reference_id'])) {
            $referenceLabel .= ' #' . (int) $tx['reference_id'];
        }
    }

    return [
        'id' => (int) ($tx['id'] ?? 0),
        'transactionDate' => (string) ($tx['transaction_date'] ?? ''),
        'accountName' => (string) ($tx['account_name'] ?? ''),
        'description' => (string) ($tx['description'] ?? ''),
        'referenceType' => $refType,
        'referenceLabel' => $referenceLabel,
        'referenceId' => isset($tx['reference_id']) ? (int) $tx['reference_id'] : null,
        'userName' => (string) ($tx['user_name'] ?? 'System'),
        'amount' => $amount,
        'type' => $isCredit ? 'credit' : 'debit',
        'typeLabel' => $typeLabel,
        'typeClass' => $typeClass,
        'amountDisplay' => $sign . ' ' . tlFormatAmount($amount),
        'viewUrl' => 'view-transaction.php' . tlBuildQuery(['id' => (int) ($tx['id'] ?? 0)]),
    ];
}

function tlBuildInsights(array $stats, string $searchQ): array
{
    $totalEntries = (int) ($stats['totalEntries'] ?? 0);
    $netMovement = (float) ($stats['netMovement'] ?? 0);
    $transferCount = (int) ($stats['transferCount'] ?? 0);

    $highlights = [];
    $suggestions = [];

    if ($totalEntries > 0) {
        $highlights[] = number_format($totalEntries) . ' transaction' . ($totalEntries === 1 ? '' : 's') . ' in the ledger.';
        if ($netMovement > 0) {
            $highlights[] = 'Net inflow of ' . tlFormatAmount($netMovement) . ' across all entries.';
        } elseif ($netMovement < 0) {
            $suggestions[] = 'Net outflow of ' . tlFormatAmount(abs($netMovement)) . '. Review debits and large withdrawals.';
        }
        if ($transferCount > 0) {
            $highlights[] = number_format($transferCount) . ' internal transfer' . ($transferCount === 1 ? '' : 's') . ' recorded.';
        }
    } elseif ($searchQ !== '') {
        $suggestions[] = 'No entries match ' . $searchQ . '. Try another keyword or clear the search.';
    } else {
        $suggestions[] = 'No transactions recorded yet. Post a transfer or record a payment to build your ledger.';
    }

    $visible = array_map(static fn(string $text): array => [
        'label' => 'Summary',
        'class' => 'highlight',
        'text' => $text,
    ], $highlights);

    $hidden = array_map(static fn(string $text): array => [
        'label' => 'Suggestion',
        'class' => 'tip',
        'text' => $text,
    ], $suggestions);

    $visibleSlice = array_slice($visible, 0, 4);
    $hiddenSlice = array_merge(array_slice($visible, 4), $hidden);

    return [
        'visible' => $visibleSlice,
        'hidden' => $hiddenSlice,
        'hiddenCount' => count($hiddenSlice),
        'aiConnected' => tlAiIsConnected(),
    ];
}

function tlBuildInitPayload(PDO $pdo, $filtersOrQ = ''): array
{
    $filters = is_array($filtersOrQ) ? tlParseFilters($filtersOrQ) : tlParseFilters(['q' => (string) $filtersOrQ]);
    $stats = tlFetchStats($pdo, $filters);
    $companyDisplay = $_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company');

    return [
        'summary' => $stats,
        'insights' => tlBuildInsights($stats, $filters['q']),
        'transferUrl' => 'transfer.php' . tlBuildQuery(),
        'companyName' => (string) $companyDisplay,
        'dateLabel' => date('l, d M Y'),
        'aiConnected' => tlAiIsConnected(),
        'filters' => $filters,
    ];
}

function tlBuildListPayload(PDO $pdo, $filtersOrQ, string $perPageRaw, int $page): array
{
    $filters = is_array($filtersOrQ) ? tlParseFilters($filtersOrQ) : tlParseFilters(['q' => (string) $filtersOrQ]);
    $stats = tlFetchStats($pdo, $filters);
    $pagination = tlNormalizePagination($stats, $perPageRaw, $page);
    $offset = ($pagination['page'] - 1) * $pagination['perPage'];
    $rows = tlFetchTransactions($pdo, $filters, $pagination['perPage'], $offset);
    $totalEntries = (int) ($stats['totalEntries'] ?? 0);
    $showingFrom = $totalEntries > 0 ? $offset + 1 : 0;
    $showingTo = $totalEntries > 0 ? min($offset + count($rows), $totalEntries) : 0;

    return [
        'summary' => $stats,
        'transactions' => array_map('tlMapTransactionRow', $rows),
        'pagination' => [
            'page' => $pagination['page'],
            'perPage' => $pagination['perPage'],
            'totalPages' => $pagination['totalPages'],
            'viewAll' => $pagination['viewAll'],
            'showingFrom' => $showingFrom,
            'showingTo' => $showingTo,
            'totalEntries' => $totalEntries,
        ],
        'filters' => $filters,
    ];
}

/**
 * @return array{date_from: string, date_to: string, label: string, q: string}|null
 */
function tlExtractRelativeDateRange(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $tz = new DateTimeZone(date_default_timezone_get() ?: 'Africa/Dar_es_Salaam');
    $now = new DateTimeImmutable('now', $tz);
    $lower = strtolower($query);

    $patterns = [
        [
            'regex' => '/\btoday\b/i',
            'from' => $now->format('Y-m-d'),
            'to' => $now->format('Y-m-d'),
            'label' => 'today',
        ],
        [
            'regex' => '/\byesterday\b/i',
            'from' => $now->modify('-1 day')->format('Y-m-d'),
            'to' => $now->modify('-1 day')->format('Y-m-d'),
            'label' => 'yesterday',
        ],
        [
            'regex' => '/\bthis\s+week\b/i',
            'from' => $now->modify('monday this week')->format('Y-m-d'),
            'to' => $now->format('Y-m-d'),
            'label' => 'this week',
        ],
        [
            'regex' => '/\blast\s+week\b/i',
            'from' => $now->modify('monday last week')->format('Y-m-d'),
            'to' => $now->modify('sunday last week')->format('Y-m-d'),
            'label' => 'last week',
        ],
        [
            'regex' => '/\b(this\s+month|current\s+month)\b/i',
            'from' => $now->format('Y-m-01'),
            'to' => $now->format('Y-m-d'),
            'label' => 'this month',
        ],
        [
            'regex' => '/\blast\s+month\b/i',
            'from' => $now->modify('first day of last month')->format('Y-m-d'),
            'to' => $now->modify('last day of last month')->format('Y-m-d'),
            'label' => 'last month',
        ],
        [
            'regex' => '/\bthis\s+year\b/i',
            'from' => $now->format('Y-01-01'),
            'to' => $now->format('Y-m-d'),
            'label' => 'this year',
        ],
        [
            'regex' => '/\blast\s+year\b/i',
            'from' => $now->modify('first day of january last year')->format('Y-m-d'),
            'to' => $now->modify('last day of december last year')->format('Y-m-d'),
            'label' => 'last year',
        ],
        [
            'regex' => '/^\s*month\s*$/i',
            'from' => $now->format('Y-m-01'),
            'to' => $now->format('Y-m-d'),
            'label' => 'this month',
        ],
    ];

    foreach ($patterns as $spec) {
        if (preg_match($spec['regex'], $lower) !== 1) {
            continue;
        }
        $cleaned = trim(preg_replace($spec['regex'], ' ', $query) ?? $query);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
        $cleaned = trim(preg_replace(
            '/\b(show|me|transactions?|entries|credits?|debits?|transfers?|from|in|for|during|of|over|above|under|below)\b/i',
            ' ',
            $cleaned
        ) ?? $cleaned);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);

        return [
            'date_from' => $spec['from'],
            'date_to' => $spec['to'],
            'label' => $spec['label'],
            'q' => $cleaned,
        ];
    }

    if (preg_match('/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b(?:\s+(\d{4}))?/i', $lower, $m) === 1) {
        $monthName = $m[1];
        $year = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (int) $now->format('Y');
        $monthStart = DateTimeImmutable::createFromFormat(
            'Y-M-d',
            sprintf('%04d-%s-01', $year, ucfirst(strtolower(substr($monthName, 0, 3)))),
            $tz
        );
        if ($monthStart instanceof DateTimeImmutable) {
            $from = $monthStart->format('Y-m-01');
            $to = $monthStart->modify('last day of this month')->format('Y-m-d');
            $cleaned = trim(preg_replace('/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b(?:\s+\d{4})?/i', ' ', $query) ?? $query);
            $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
            return [
                'date_from' => $from,
                'date_to' => $to,
                'label' => $monthStart->format('F Y'),
                'q' => $cleaned,
            ];
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $filters
 * @return array{filters: array{q: string, date_from: string, date_to: string, type: string, amount_min: string, amount_max: string}, note: string}
 */
function tlApplyRelativeDatesFromQuery(array $filters, string $originalQuery = ''): array
{
    $filters = tlParseFilters($filters);
    $source = trim($originalQuery !== '' ? $originalQuery : $filters['q']);
    $relative = tlExtractRelativeDateRange($source);
    if ($relative === null) {
        return ['filters' => $filters, 'note' => ''];
    }

    $filters['date_from'] = $relative['date_from'];
    $filters['date_to'] = $relative['date_to'];

    if ($filters['q'] === '' || preg_match('/^\s*(this|last|current)?\s*(month|week|year|today|yesterday)\s*$/i', $filters['q'])) {
        $filters['q'] = $relative['q'];
    } elseif ($relative['q'] !== '' && stripos($filters['q'], $relative['q']) === false) {
        if (!preg_match('/\b(month|week|year|today|yesterday)\b/i', $relative['q'])) {
            $filters['q'] = $relative['q'];
        }
    }

    $filters['q'] = trim(preg_replace('/\b(this|last|current)\s+(month|week|year)\b|\b(month|today|yesterday)\b/i', ' ', $filters['q']) ?? $filters['q']);
    $filters['q'] = trim(preg_replace('/\s+/', ' ', $filters['q']) ?? $filters['q']);

    return [
        'filters' => $filters,
        'note' => 'Filtered to ' . $relative['label'] . ' (' . $relative['date_from'] . ' to ' . $relative['date_to'] . ').',
    ];
}

function tlAiHelpersBootstrap(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $helpers = dirname(__DIR__, 3) . '/includes/ai_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
    }
    // balances_ai_is_connected lives in balances/functions.php (loaded via module bootstrap).
    $balancesFunctions = dirname(__DIR__) . '/functions.php';
    if (is_file($balancesFunctions)) {
        require_once $balancesFunctions;
    }
    $loaded = true;
}

function tlAiIsConnected(): bool
{
    tlAiHelpersBootstrap();
    if (function_exists('balances_ai_is_connected')) {
        return balances_ai_is_connected();
    }
    if (!function_exists('ai_settings_for_api')) {
        return false;
    }
    $settings = ai_settings_for_api();
    if (empty($settings['configured']) || empty($settings['is_enabled'])) {
        return false;
    }
    try {
        if (function_exists('ai_get_decrypted_api_key')) {
            ai_get_decrypted_api_key();
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<string>
 */
function tlFetchAccountNameOptions(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT name FROM financial_accounts WHERE name IS NOT NULL AND name <> "" ORDER BY name ASC LIMIT 80');
    if (!$stmt) {
        return [];
    }
    $names = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * @param list<string> $accountOptions
 * @return array{filters: array{q: string, date_from: string, date_to: string, type: string, amount_min: string, amount_max: string}, explanation: string}
 */
function tlAiInterpretSearch(string $query, array $accountOptions = []): array
{
    $query = trim($query);
    if ($query === '') {
        throw new InvalidArgumentException('Search query is required.');
    }

    tlAiHelpersBootstrap();
    if (!function_exists('ai_openai_request')) {
        throw new RuntimeException('AI helpers are not available.');
    }
    if (!tlAiIsConnected()) {
        throw new RuntimeException('AI search is not connected. Enable OpenAI in system settings.');
    }

    $today = date('Y-m-d');
    $accounts = array_slice(array_values(array_filter(array_map('strval', $accountOptions))), 0, 80);
    $accountsJson = json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $system = <<<SYS
You convert natural-language queries for a financial transaction ledger into JSON filters.
Return ONLY valid JSON with this exact shape:
{"q":"","date_from":"","date_to":"","type":"","amount_min":"","amount_max":"","explanation":""}

Rules:
- q: free-text keywords for account name, description, reference, or user. Prefer a short distinctive keyword.
- date_from / date_to: YYYY-MM-DD or empty. Today is {$today}.
- For relative periods use exact dates: "this month"/"month" => first day of month to today; "last month" => full previous month; "this week"/"last week"/"today"/"yesterday" similarly.
- Only set dates when the user clearly asks for a period.
- type: one of "", "credit", "debit", "transfer". Use transfer for internal transfers. Credits are inflows, debits are outflows.
- amount_min / amount_max: plain numbers only. Convert 5M or 5 million to 5000000. Leave empty unless amount bounds are stated.
- explanation: one short sentence describing the interpreted filters.
- Do not invent account names. If unsure, put a keyword in q.
SYS;

    $user = "Known accounts: {$accountsJson}\n\nUser query: {$query}";

    $result = ai_openai_request([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ]);

    $content = trim((string) ($result['content'] ?? ''));
    if ($content === '') {
        throw new RuntimeException('AI returned an empty response.');
    }

    if (preg_match('/\{[\s\S]*\}/', $content, $m) === 1) {
        $content = $m[0];
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return [
            'filters' => tlParseFilters(['q' => $query]),
            'explanation' => 'Could not parse AI filters; used the query as text search.',
        ];
    }

    $filters = tlParseFilters($decoded);
    if (
        $filters['q'] === ''
        && $filters['date_from'] === ''
        && $filters['date_to'] === ''
        && $filters['type'] === ''
        && $filters['amount_min'] === ''
        && $filters['amount_max'] === ''
    ) {
        $filters['q'] = $query;
    }

    $explanation = trim((string) ($decoded['explanation'] ?? ''));
    if ($explanation === '') {
        $explanation = 'Applied AI-interpreted ledger filters.';
    }

    return [
        'filters' => $filters,
        'explanation' => $explanation,
    ];
}

/**
 * @return array{filters: array{q: string, date_from: string, date_to: string, type: string, amount_min: string, amount_max: string}, explanation: string, summary: array, transactions: list<array>, pagination: array, count: int}
 */
function tlRunAiSearch(PDO $pdo, string $query, string $perPageRaw = 'all'): array
{
    $accountOptions = tlFetchAccountNameOptions($pdo);
    $interpreted = tlAiInterpretSearch($query, $accountOptions);
    $filters = $interpreted['filters'];
    $relativePack = tlApplyRelativeDatesFromQuery($filters, $query);
    $filters = $relativePack['filters'];
    $explanation = (string) $interpreted['explanation'];
    if ($relativePack['note'] !== '') {
        $explanation = rtrim($explanation, '.') . '. ' . $relativePack['note'];
    }

    $list = tlBuildListPayload($pdo, $filters, $perPageRaw, 1);
    $count = (int) ($list['pagination']['totalEntries'] ?? 0);

    if ($count === 0) {
        $fallback = tlParseFilters(['q' => $query]);
        $fallbackPack = tlApplyRelativeDatesFromQuery($fallback, $query);
        $fallback = $fallbackPack['filters'];
        $fallbackList = tlBuildListPayload($pdo, $fallback, $perPageRaw, 1);
        $fallbackCount = (int) ($fallbackList['pagination']['totalEntries'] ?? 0);
        if ($fallbackCount > 0) {
            $filters = $fallback;
            $list = $fallbackList;
            $count = $fallbackCount;
            $explanation = ($fallbackPack['note'] !== '' ? $fallbackPack['note'] : 'Showing matches for your query.')
                . ' (' . $count . ' found)';
        } else {
            $tokens = preg_split('/\s+/', $query) ?: [];
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if (strlen($token) < 3) {
                    continue;
                }
                if (preg_match('/^(credit|debit|transfer|credits|debits|transfers|inflow|outflow|over|from|last|month|week|year|this|current|today|yesterday|and|the|for|in|during|above|under|below)$/i', $token)) {
                    continue;
                }
                $tokenFilters = tlParseFilters(['q' => $token]);
                $tokenPack = tlApplyRelativeDatesFromQuery($tokenFilters, $query);
                $tokenFilters = $tokenPack['filters'];
                $tokenFilters['q'] = $token;
                $tokenList = tlBuildListPayload($pdo, $tokenFilters, $perPageRaw, 1);
                $tokenCount = (int) ($tokenList['pagination']['totalEntries'] ?? 0);
                if ($tokenCount > 0) {
                    $filters = $tokenFilters;
                    $list = $tokenList;
                    $count = $tokenCount;
                    $explanation = 'Matched keyword "' . $token . '"'
                        . ($tokenPack['note'] !== '' ? '. ' . $tokenPack['note'] : '')
                        . ' (' . $count . ' found)';
                    break;
                }
            }
        }
    }

    return [
        'filters' => $filters,
        'explanation' => $explanation,
        'summary' => $list['summary'],
        'transactions' => $list['transactions'],
        'pagination' => $list['pagination'],
        'count' => $count,
    ];
}
