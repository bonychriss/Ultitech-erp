<?php
/**
 * Rebuild revenue-entries-lib.php from revenue_entries.php procedural block.
 */
$root = dirname(__DIR__, 3);
$src = file_get_contents($root . '/revenue_entries.php');

if (!preg_match('/function ren_bootstrap\(.*?\n\}/s', $src, $m)) {
    fwrite(STDERR, "ren_bootstrap not found\n");
    exit(1);
}
$helpers = $m[0];

preg_match_all('/function (ren_[a-z_]+)\(/', $src, $fnMatches);
$helperNames = array_unique($fnMatches[1] ?? []);
$helperBlocks = [];
foreach ($helperNames as $name) {
    if ($name === 'ren_bootstrap') {
        continue;
    }
    if (preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n\}/s', $src, $fm)) {
        $helperBlocks[] = $fm[0];
    }
}

$procStart = strpos($src, 'ren_bootstrap($revPdo);');
$procEnd = strpos($src, '$drl = $dateFrom');
if ($procStart === false || $procEnd === false) {
    fwrite(STDERR, "proc block not found\n");
    exit(1);
}
$proc = substr($src, $procStart, $procEnd - $procStart);
$proc = str_replace('$_GET', '$get', $proc);
$proc = str_replace('$renShowErrorPage(', 'throw new RuntimeException(', $proc);

$header = <<<'PHP'
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

PHP;

$fetchFn = "function revenue_entries_fetch(PDO \$revPdo, array \$get): array\n{\n"
    . "    require_once dirname(__DIR__, 3) . '/includes/functions.php';\n"
    . "    " . str_replace("\n", "\n    ", trim($proc)) . "\n\n"
    . "    \$showingFrom = \$total_records === 0 ? 0 : \$offset + 1;\n"
    . "    \$showingTo = min(\$offset + count(\$entries), \$total_records);\n"
    . "    \$enriched = [];\n"
    . "    foreach (\$entries as \$row) {\n"
    . "        \$enriched[] = revenue_entries_enrich_row(\$row);\n"
    . "    }\n\n"
    . "    return [\n"
    . "        'entries' => \$enriched,\n"
    . "        'total' => \$total_records,\n"
    . "        'page' => \$page,\n"
    . "        'per_page' => \$perPage,\n"
    . "        'total_pages' => \$totalPages,\n"
    . "        'showing_from' => \$showingFrom,\n"
    . "        'showing_to' => \$showingTo,\n"
    . "        'page_numbers' => ren_pages(\$page, \$totalPages),\n"
    . "        'kpi' => \$kpi,\n"
    . "        'kpi_prev' => \$kpiPrev,\n"
    . "        'month' => ['revenue' => \$monthRev, 'count' => \$monthCnt, 'prev_revenue' => \$prevMonthRev, 'prev_count' => \$prevMonthCnt, 'trend_tone' => \$monthTrendTone],\n"
    . "        'kpi_trace' => \$kpiTrace,\n"
    . "        'filters' => [\n"
    . "            'tab' => \$tab, 'search' => \$search, 'date_from' => \$dateFrom, 'date_to' => \$dateTo,\n"
    . "            'customer_id' => \$fCustomerId, 'type' => \$fType, 'status' => \$fStatus, 'payment' => \$fPayment,\n"
    . "            'sort' => \$sort, 'dir' => strtolower(\$dir), 'kpi' => \$kpiView,\n"
    . "            'default_month_filter' => \$renDefaultMonthFilter,\n"
    . "        ],\n"
    . "    ];\n"
    . "}\n\n";

$enrichFn = <<<'PHP'
function revenue_entries_enrich_row(array $row): array
{
    $meta = ren_status_meta($row);
    $type = ren_type_label($row);
    $customerDisplay = ren_cust_name($row);
    $je = $row['resolved_journal_entry_id'] ?? $row['journal_entry_id'] ?? null;
    $jn = trim((string) ($row['resolved_journal_name'] ?? ''));
    $jnShow = $jn !== '' && $jn !== '-' ? $jn : ren_journal_fallback_name($row);

    return array_merge($row, [
        'type_label' => $type,
        'description' => ren_description($row),
        'status_label' => $meta[0],
        'status_class' => $meta[1],
        'customer_display' => $customerDisplay,
        'customer_code_display' => ren_cust_code($row['resolved_customer_code'] ?? null, (int) ($row['resolved_customer_id'] ?? 0)),
        'customer_initials' => ren_cust_initials($customerDisplay),
        'customer_avatar_tone' => ren_cust_avatar_tone($customerDisplay),
        'journal_display' => $je ? ($jnShow . ' (#' . (int) $je . ')') : $jnShow,
        'payment_icon' => ren_pay_icon((string) ($row['payment_mode'] ?? '')),
        'payment_icon_class' => ren_pay_icon_class((string) ($row['payment_mode'] ?? '')),
        'can_edit' => (function_exists('isAdmin') && isAdmin()) || (($row['approval_status'] ?? '') === 'Pending'),
        'is_admin' => function_exists('isAdmin') && isAdmin(),
    ]);
}

function revenue_entries_init_meta(PDO $pdo): array
{
    ren_bootstrap($pdo);
    try {
        ensureRevenueSourceInvoiceSchema($pdo);
    } catch (Throwable $e) {
    }

    $customers = [];
    try {
        $customers = $pdo->query('SELECT id, customer_code, company_name FROM customers WHERE TRIM(COALESCE(company_name,\'\'))<>\'\' ORDER BY company_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    $paymentModes = [];
    try {
        $paymentModes = $pdo->query("SELECT DISTINCT payment_mode FROM revenue_entries WHERE TRIM(COALESCE(payment_mode,''))<>'' ORDER BY payment_mode")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
    }

    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');

    return [
        'customers' => array_map(static function ($row) {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['customer_code'] ?? ''),
                'name' => (string) ($row['company_name'] ?? ''),
            ];
        }, $customers),
        'payment_modes' => array_values(array_map('strval', $paymentModes)),
        'tabs' => [
            ['value' => 'all', 'label' => 'All'],
            ['value' => 'invoices', 'label' => 'Invoices'],
            ['value' => 'payments', 'label' => 'Payments'],
            ['value' => 'credit_notes', 'label' => 'Credit notes'],
            ['value' => 'adjustments', 'label' => 'Adjustments'],
        ],
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
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'default_month_filter' => true,
        ],
        'is_admin' => function_exists('isAdmin') && isAdmin(),
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
    ];
}

function revenue_entries_export_csv(PDO $revPdo, array $get): void
{
    $get['export'] = 'csv';
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

PHP;

$body = $header . $helpers . "\n" . implode("\n\n", $helperBlocks) . "\n\n" . $fetchFn . $enrichFn;
$outPath = dirname(__DIR__) . '/includes/revenue-entries-lib.php';
file_put_contents($outPath, $body);
echo "Wrote {$outPath} (" . strlen($body) . " bytes)\n";
