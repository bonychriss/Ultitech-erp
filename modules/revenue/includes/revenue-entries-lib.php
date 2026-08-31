<?php

declare(strict_types=1);

function revenue_entries_resolve_pdo(): PDO
{
    if (!function_exists('revenue_resolve_pdo')) {
        require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
    }
    $pdo = revenue_resolve_pdo();
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }
    global $pdo;
    $GLOBALS['pdo'] = $pdo;

    return $pdo;
}

function ren_bootstrap(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `revenue_entries` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `voucher_number` varchar(50) DEFAULT NULL,
          `entry_date` date DEFAULT NULL,
          `customer_name` varchar(255) DEFAULT NULL,
          `narration` text DEFAULT NULL,
          `payment_mode` varchar(50) DEFAULT NULL,
          `amount_exclusive` decimal(15,2) DEFAULT 0.00,
          `vat_amount` decimal(15,2) DEFAULT 0.00,
          `amount_total` decimal(15,2) DEFAULT 0.00,
          `total_paid` decimal(15,2) DEFAULT 0.00,
          `payment_status` varchar(20) DEFAULT 'Unpaid',
          `approval_status` varchar(20) DEFAULT 'Pending',
          `attachment` varchar(255) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `approved_by` int(11) DEFAULT NULL,
          `approved_at` datetime DEFAULT NULL,
          `account_id` int(11) DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($cols && !in_array('journal_entry_id', $cols, true)) {
            $pdo->exec('ALTER TABLE revenue_entries ADD COLUMN journal_entry_id INT NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
    }
}

function ren_type_label(array $row): string
{
    $n = strtolower((string) ($row['narration'] ?? ''));
    if (strpos($n, 'credit') !== false) {
        return 'Credit Note';
    }
    if (!empty($row['source_invoice_id']) || !empty($row['linked_invoice_number'])) {
        return 'Sales';
    }

    return 'Other';
}

function ren_type_class(array $row): string
{
    $label = ren_type_label($row);
    if ($label === 'Sales') {
        return 'sales';
    }
    if ($label === 'Credit Note') {
        return 'credit';
    }

    return 'other';
}

function ren_description(array $row): string
{
    $inv = trim((string) ($row['linked_invoice_number'] ?? ''));
    if ($inv !== '') {
        return 'Sales Invoice ' . $inv;
    }
    $nar = trim((string) ($row['narration'] ?? ''));

    return $nar !== '' ? $nar : '-';
}

function ren_status_meta(array $row): array
{
    if (($row['payment_status'] ?? '') === 'Paid') {
        return ['Paid', 'paid'];
    }
    $total = (float) ($row['amount_total'] ?? 0);
    $paid = (float) ($row['total_paid'] ?? 0);
    $bal = max(0.0, $total - $paid);
    if ($paid > 0.009 && $bal > 0.009) {
        return ['Partial', 'partial'];
    }
    $isInvoice = !empty($row['source_invoice_id']) || !empty($row['linked_invoice_number']);
    if (!$isInvoice && ($row['approval_status'] ?? '') === 'Pending') {
        return ['Pending', 'pending'];
    }

    return ['Unpaid', 'unpaid'];
}

function ren_cust_code($code, int $cid): string
{
    $c = trim((string) $code);
    if ($c !== '') {
        return $c;
    }
    if ($cid > 0) {
        return 'CUST-' . str_pad((string) $cid, 5, '0', STR_PAD_LEFT);
    }

    return '';
}

function ren_cust_name(array $row): string
{
    $co = trim((string) ($row['resolved_company_name'] ?? ''));
    if ($co !== '') {
        return $co;
    }

    return trim((string) ($row['customer_name'] ?? ''));
}

function ren_cust_initials(string $displayName): string
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $displayName);
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $dp) {
        if ($dp !== '') {
            $initials .= strtoupper(substr($dp, 0, 1));
        }
    }
    if ($initials === '' && isset($parts[0]) && strlen($parts[0]) > 1) {
        $initials = strtoupper(substr($parts[0], 0, 2));
    }

    return substr($initials ?: '?', 0, 2);
}

function ren_cust_avatar_tone(string $displayName): int
{
    $displayName = trim($displayName);

    return $displayName === '' ? 0 : (int) (abs(crc32($displayName)) % 10);
}

function ren_pages(int $cur, int $last): array
{
    if ($last <= 1) {
        return $last === 1 ? [1] : [];
    }
    $pages = [1];
    $L = max(2, $cur - 1);
    $R = min($last - 1, $cur + 1);
    if ($L > 2) {
        $pages[] = '...';
    }
    for ($i = $L; $i <= $R; $i++) {
        $pages[] = $i;
    }
    if ($R < $last - 1) {
        $pages[] = '...';
    }
    $pages[] = $last;

    return $pages;
}

function ren_can_pay(array $row): bool
{
    if (($row['approval_status'] ?? '') === 'Pending' && empty($row['source_invoice_id']) && empty($row['linked_invoice_number'])) {
        return false;
    }
    $total = (float) ($row['amount_total'] ?? 0);
    $paid = (float) ($row['total_paid'] ?? 0);

    return ($total - $paid) > 0.009;
}

function ren_row_amount_paid(array $row): float
{
    return (float) ($row['total_paid'] ?? 0);
}

function ren_row_balance_due(array $row): float
{
    $total = (float) ($row['amount_total'] ?? 0);
    $paid = ren_row_amount_paid($row);

    return max(0.0, $total - $paid);
}

function ren_can_edit(array $row): bool
{
    if (!empty($row['source_invoice_id']) && (int) $row['source_invoice_id'] > 0) {
        return false;
    }
    if (!empty($row['linked_invoice_number'])) {
        return false;
    }

    return (function_exists('isAdmin') && isAdmin()) || (($row['approval_status'] ?? '') === 'Pending');
}

function revenue_entries_enrich_row(array $row): array
{
    $meta = ren_status_meta($row);
    $customerDisplay = ren_cust_name($row);

    return array_merge($row, [
        'type_label' => ren_type_label($row),
        'type_class' => ren_type_class($row),
        'description' => ren_description($row),
        'status_label' => $meta[0],
        'status_class' => $meta[1],
        'customer_display' => $customerDisplay,
        'customer_code_display' => ren_cust_code($row['resolved_customer_code'] ?? null, (int) ($row['resolved_customer_id'] ?? 0)),
        'customer_initials' => ren_cust_initials($customerDisplay),
        'customer_avatar_tone' => ren_cust_avatar_tone($customerDisplay),
        'journal_display' => (string) ($row['journal_entry_id'] ?? '') !== '' ? 'Journal #' . (int) $row['journal_entry_id'] : '-',
        'can_edit' => ren_can_edit($row),
        'can_pay' => ren_can_pay($row),
        'amount_paid' => ren_row_amount_paid($row),
        'balance_due' => ren_row_balance_due($row),
    ]);
}

function revenue_entries_normalize_customer(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name) ?? '';
    foreach ([' limited', ' ltd', ' company', ' co.', ' t) ltd', ' (t) ltd', ' engineers'] as $suffix) {
        if ($suffix !== '' && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }
    }

    return trim($name);
}

function revenue_entries_row_fingerprint(array $row): string
{
    $date = (string) ($row['entry_date'] ?? '');
    $total = number_format((float) ($row['amount_total'] ?? 0), 2, '.', '');
    $customer = revenue_entries_normalize_customer((string) ($row['customer_display'] ?? $row['customer_name'] ?? ''));

    return $date . '|' . $total . '|' . $customer;
}

/**
 * Drop manual "Other" rows that duplicate a synced sales-invoice entry.
 *
 * @param array<int, array<string, mixed>> $entries
 * @return array<int, array<string, mixed>>
 */
function revenue_entries_dedupe_pairs(array $entries): array
{
    $invoiceFingerprints = [];
    foreach ($entries as $row) {
        if (!empty($row['source_invoice_id']) || !empty($row['linked_invoice_number'])) {
            $invoiceFingerprints[revenue_entries_row_fingerprint($row)] = true;
        }
    }
    if (!$invoiceFingerprints) {
        return $entries;
    }

    return array_values(array_filter($entries, static function (array $row) use ($invoiceFingerprints): bool {
        if (!empty($row['source_invoice_id']) || !empty($row['linked_invoice_number'])) {
            return true;
        }

        return !isset($invoiceFingerprints[revenue_entries_row_fingerprint($row)]);
    }));
}

/**
 * @return array<string, mixed>
 */
function revenue_entries_parse_filters(array $get): array
{
    $tab = strtolower(preg_replace('/[^a-z_]/', '', $get['tab'] ?? 'all'));
    if (!in_array($tab, ['all', 'invoices', 'payments', 'credit_notes', 'adjustments'], true)) {
        $tab = 'all';
    }
    $search = trim((string) ($get['search'] ?? ''));
    $showAllDates = isset($get['all']) && (string) $get['all'] === '1';
    $monthParam = trim((string) ($get['month'] ?? ''));
    $dateFrom = trim((string) ($get['date_from'] ?? ''));
    $dateTo = trim((string) ($get['date_to'] ?? ''));
    $defaultMonthFilter = false;
    if ($dateFrom === '' && $dateTo === '' && !$showAllDates && $monthParam !== '') {
        if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $dateFrom = $monthParam . '-01';
            $dateTo = date('Y-m-t', strtotime($dateFrom));
            $defaultMonthFilter = true;
        }
    }

    $sort = strtolower(preg_replace('/[^a-z_]/', '', $get['sort'] ?? 'date'));
    if (!in_array($sort, ['date', 'amount'], true)) {
        $sort = 'date';
    }
    $dir = strtolower((string) ($get['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    return [
        'tab' => $tab,
        'search' => $search,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'default_month_filter' => $defaultMonthFilter,
        'customer_id' => (int) ($get['customer_id'] ?? 0),
        'type' => trim((string) ($get['type'] ?? '')),
        'status' => strtolower(trim((string) ($get['status'] ?? ''))),
        'payment' => trim((string) ($get['payment'] ?? '')),
        'sort' => $sort,
        'dir' => $dir,
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return array{0:array<int,string>,1:array<string,mixed>}
 */
function revenue_entries_build_where(array $filters, PDO $pdo, bool $hasSourceInvoiceCol): array
{
    $conds = ['1=1'];
    $params = [];

    switch ($filters['tab']) {
        case 'invoices':
            if ($hasSourceInvoiceCol) {
                $conds[] = 're.source_invoice_id IS NOT NULL';
            }
            break;
        case 'payments':
            $conds[] = 're.total_paid > 0';
            break;
        case 'credit_notes':
            $conds[] = "(LOWER(COALESCE(re.narration,'')) LIKE '%credit note%' OR LOWER(COALESCE(re.narration,'')) LIKE '%credit%')";
            break;
        case 'adjustments':
            $conds[] = "(re.voucher_number LIKE '%ADJ%' OR LOWER(COALESCE(re.narration,'')) LIKE '%adjust%')";
            break;
    }
    if ($filters['date_from'] !== '') {
        $conds[] = 're.entry_date >= :df';
        $params[':df'] = $filters['date_from'];
    }
    if ($filters['date_to'] !== '') {
        $conds[] = 're.entry_date <= :dt';
        $params[':dt'] = $filters['date_to'];
    }
    if ($filters['customer_id'] > 0) {
        $conds[] = 'cust.id = :custid';
        $params[':custid'] = $filters['customer_id'];
    }
    if ($filters['payment'] !== '') {
        $conds[] = 're.payment_mode = :pm';
        $params[':pm'] = $filters['payment'];
    }
    if ($hasSourceInvoiceCol) {
        if ($filters['type'] === 'sales') {
            $conds[] = 're.source_invoice_id IS NOT NULL';
        } elseif ($filters['type'] === 'other') {
            $conds[] = 're.source_invoice_id IS NULL';
        }
    }
    $status = $filters['status'];
    if ($status === 'pending' || $status === 'draft') {
        $conds[] = "re.approval_status = 'Pending'";
    } elseif ($status === 'paid') {
        $conds[] = "re.payment_status = 'Paid'";
    } elseif ($status === 'unpaid') {
        $conds[] = "re.payment_status <> 'Paid' AND re.approval_status <> 'Pending'";
    } elseif ($status === 'partial') {
        $conds[] = 're.total_paid > 0 AND re.total_paid < re.amount_total';
    } elseif ($status === 'uploaded') {
        $conds[] = "(re.attachment IS NOT NULL AND re.attachment <> '' AND re.payment_status <> 'Paid')";
    } elseif ($status === 'posted') {
        $conds[] = "re.approval_status = 'Ratified' AND re.payment_status <> 'Paid' AND (re.total_paid IS NULL OR re.total_paid < 0.01)";
    }
    if ($filters['search'] !== '') {
        $conds[] = '(re.voucher_number LIKE :sq OR re.customer_name LIKE :sq OR re.narration LIKE :sq OR i.invoice_number LIKE :sq OR COALESCE(cust.company_name,\'\') LIKE :sq OR COALESCE(cust.customer_code,\'\') LIKE :sq OR COALESCE(re.payment_mode,\'\') LIKE :sq)';
        $params[':sq'] = '%' . $filters['search'] . '%';
    }

    return [$conds, $params];
}

/**
 * @return array<string, mixed>
 */
function revenue_entries_fetch(PDO $revPdo, array $get): array
{
    ren_bootstrap($revPdo);
    try {
        ensureRevenueSourceInvoiceSchema($revPdo);
    } catch (Throwable $e) {
    }
    try {
        if (!function_exists('backfillInvoicesToRevenueEntries')) {
            require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
        }
        if (function_exists('backfillInvoicesToRevenueEntries')) {
            backfillInvoicesToRevenueEntries($revPdo, 5000);
        }
    } catch (Throwable $e) {
    }

    $filters = revenue_entries_parse_filters($get);
    $hasSourceInvoiceCol = false;
    try {
        $reCols = $revPdo->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasSourceInvoiceCol = in_array('source_invoice_id', $reCols, true);
    } catch (Throwable $e) {
    }
    $hasInvoices = function_exists('tableExists') && tableExists('invoices', $revPdo);
    $hasCustomers = function_exists('tableExists') && tableExists('customers', $revPdo);

    if ($hasInvoices && $hasSourceInvoiceCol) {
        $sqlJoin = 'FROM revenue_entries re LEFT JOIN invoices i ON i.id = re.source_invoice_id';
        $sqlJoin .= $hasCustomers
            ? ' LEFT JOIN customers cust ON cust.id = i.customer_id'
            : " LEFT JOIN (SELECT CAST(NULL AS UNSIGNED) AS id, CAST(NULL AS CHAR(64)) AS customer_code, CAST(NULL AS CHAR(255)) AS company_name) cust ON 1=0";
    } else {
        $sqlJoin = "FROM revenue_entries re
            LEFT JOIN (SELECT CAST(0 AS UNSIGNED) AS id, CAST(NULL AS CHAR(64)) AS invoice_number, CAST(0 AS UNSIGNED) AS customer_id) i ON 1=0
            LEFT JOIN (SELECT CAST(0 AS UNSIGNED) AS id, CAST(NULL AS CHAR(64)) AS customer_code, CAST(NULL AS CHAR(255)) AS company_name) cust ON 1=0";
    }

    [$conds, $params] = revenue_entries_build_where($filters, $revPdo, $hasSourceInvoiceCol);
    $whereSql = implode(' AND ', $conds);

    $kpi = ['sum_net' => 0.0, 'sum_vat' => 0.0, 'sum_total' => 0.0, 'outstanding' => 0.0];
    $st = $revPdo->prepare("SELECT COALESCE(SUM(re.amount_exclusive),0) sn,COALESCE(SUM(re.vat_amount),0) sv,COALESCE(SUM(re.amount_total),0) st,
        COALESCE(SUM(CASE WHEN re.approval_status<>'Pending' THEN GREATEST(0,re.amount_total-re.total_paid) ELSE 0 END),0) so $sqlJoin WHERE $whereSql");
    $st->execute($params);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $kpi = [
            'sum_net' => (float) $r['sn'],
            'sum_vat' => (float) $r['sv'],
            'sum_total' => (float) $r['st'],
            'outstanding' => (float) $r['so'],
        ];
    }

    $baseConds = array_values(array_filter($conds, static fn ($c) => $c !== 're.entry_date >= :df' && $c !== 're.entry_date <= :dt'));
    $baseParams = $params;
    unset($baseParams[':df'], $baseParams[':dt']);
    $baseWhere = implode(' AND ', $baseConds);

    $kpiPrev = ['sum_net' => 0.0, 'sum_vat' => 0.0, 'sum_total' => 0.0, 'outstanding' => 0.0];
    $pp = $baseParams;
    $pp[':pms'] = date('Y-m-01', strtotime('first day of last month'));
    $pp[':pme'] = date('Y-m-t', strtotime('last day of last month'));
    $pWhere = $baseWhere . ' AND re.entry_date >= :pms AND re.entry_date <= :pme';
    $st = $revPdo->prepare("SELECT COALESCE(SUM(re.amount_exclusive),0) sn,COALESCE(SUM(re.vat_amount),0) sv,COALESCE(SUM(re.amount_total),0) st,
        COALESCE(SUM(CASE WHEN re.approval_status<>'Pending' THEN GREATEST(0,re.amount_total-re.total_paid) ELSE 0 END),0) so $sqlJoin WHERE $pWhere");
    $st->execute($pp);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $kpiPrev = [
            'sum_net' => (float) $r['sn'],
            'sum_vat' => (float) $r['sv'],
            'sum_total' => (float) $r['st'],
            'outstanding' => (float) $r['so'],
        ];
    }

    $monthWhere = $baseWhere . ' AND YEAR(re.entry_date)=YEAR(CURDATE()) AND MONTH(re.entry_date)=MONTH(CURDATE())';
    $monthRev = 0.0;
    $monthCnt = 0;
    $st = $revPdo->prepare("SELECT COALESCE(SUM(re.amount_total),0) mtot,COUNT(*) mcnt $sqlJoin WHERE $monthWhere");
    $st->execute($baseParams);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $monthRev = (float) $r['mtot'];
        $monthCnt = (int) $r['mcnt'];
    }

    $prevMonthWhere = $baseWhere . ' AND YEAR(re.entry_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND MONTH(re.entry_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))';
    $prevMonthRev = 0.0;
    $st = $revPdo->prepare("SELECT COALESCE(SUM(re.amount_total),0) mtot $sqlJoin WHERE $prevMonthWhere");
    $st->execute($baseParams);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $prevMonthRev = (float) $r['mtot'];
    }

    $sort = $filters['sort'];
    $dir = $filters['dir'];
    $orderSql = $sort === 'amount' ? "re.amount_total $dir, re.id DESC" : "re.entry_date $dir, re.id DESC";
    $listSelect = 'SELECT re.*, i.invoice_number AS linked_invoice_number, cust.id AS resolved_customer_id, cust.customer_code AS resolved_customer_code, cust.company_name AS resolved_company_name';

    $countStmt = $revPdo->prepare("SELECT COUNT(*) $sqlJoin WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $listStmt = $revPdo->prepare($listSelect . " $sqlJoin WHERE $whereSql ORDER BY $orderSql LIMIT 5000");
    $listStmt->execute($params);
    $entries = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $enriched = array_map('revenue_entries_enrich_row', $entries);
    $enriched = revenue_entries_dedupe_pairs($enriched);
    $visibleTotal = count($enriched);

    if (!function_exists('revenue_invoice_kpi_fetch')) {
        require_once __DIR__ . '/revenue-invoices-kpi-lib.php';
    }
    $invoiceKpi = revenue_invoice_kpi_fetch($revPdo, $filters);

    return [
        'entries' => $enriched,
        'total' => $visibleTotal,
        'showing_from' => $visibleTotal === 0 ? 0 : 1,
        'showing_to' => $visibleTotal,
        'kpi' => $kpi,
        'kpi_prev' => $kpiPrev,
        'invoice_kpi' => $invoiceKpi,
        'month' => [
            'revenue' => $monthRev,
            'count' => $monthCnt,
            'prev_revenue' => $prevMonthRev,
            'trend_tone' => ($prevMonthRev > 0.0001 && $monthRev < $prevMonthRev - 0.0001) ? 'orange' : 'green',
        ],
        'filters' => $filters,
    ];
}

/**
 * @return array<string, mixed>
 */
function revenue_entries_init_meta(PDO $pdo): array
{
    ren_bootstrap($pdo);
    try {
        ensureRevenueSourceInvoiceSchema($pdo);
    } catch (Throwable $e) {
    }

    $customers = [];
    try {
        $customers = $pdo->query("SELECT id, customer_code, company_name FROM customers WHERE TRIM(COALESCE(company_name,''))<>'' ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    $paymentModes = [];
    try {
        $paymentModes = $pdo->query("SELECT DISTINCT payment_mode FROM revenue_entries WHERE TRIM(COALESCE(payment_mode,''))<>'' ORDER BY payment_mode")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }

    return [
        'customers' => array_map(static function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['customer_code'] ?? ''),
                'name' => (string) ($row['company_name'] ?? ''),
            ];
        }, $customers),
        'payment_modes' => array_values(array_map('strval', $paymentModes)),
        'status_options' => [
            ['value' => '', 'label' => 'All'],
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'pending', 'label' => 'Pending'],
            ['value' => 'posted', 'label' => 'Posted'],
            ['value' => 'paid', 'label' => 'Paid'],
            ['value' => 'partial', 'label' => 'Partial'],
            ['value' => 'unpaid', 'label' => 'Unpaid'],
            ['value' => 'uploaded', 'label' => 'Uploaded'],
        ],
        'type_options' => [
            ['value' => '', 'label' => 'All'],
            ['value' => 'sales', 'label' => 'Sales'],
            ['value' => 'other', 'label' => 'Other'],
        ],
        'default_filters' => [
            'date_from' => '',
            'date_to' => '',
            'default_month_filter' => false,
        ],
        'is_admin' => function_exists('isAdmin') && isAdmin(),
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
    ];
}

function revenue_entries_export_csv(PDO $revPdo, array $get): void
{
    $data = revenue_entries_fetch($revPdo, $get);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=revenue_entries_' . date('Y-m-d') . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Voucher ID', 'Date', 'Customer', 'Code', 'Description', 'Type', 'Net', 'VAT', 'Total', 'Status', 'Payment', 'Journal']);
    foreach ($data['entries'] as $row) {
        fputcsv($out, [
            $row['voucher_number'] ?? '',
            $row['entry_date'] ?? '',
            $row['customer_display'] ?? '',
            $row['customer_code_display'] ?? '',
            $row['description'] ?? '',
            $row['type_label'] ?? '',
            $row['amount_exclusive'] ?? 0,
            $row['vat_amount'] ?? 0,
            $row['amount_total'] ?? 0,
            $row['status_label'] ?? '',
            $row['payment_mode'] ?? '',
            $row['journal_display'] ?? '',
        ]);
    }
    fclose($out);
}
