<?php

declare(strict_types=1);

/**
 * Structured KPI / summary reports for the AI assistant rich UI.
 */

function ai_assistant_sanitize_utf8_string(string $value): string
{
    if ($value === '') {
        return $value;
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = str_replace(["\x96", "\x97", "\x9d", "\x85"], '-', $value);
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    return $value;
}

/**
 * @param mixed $data
 * @return mixed
 */
function ai_assistant_sanitize_utf8_recursive($data)
{
    if (is_array($data)) {
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[$key] = ai_assistant_sanitize_utf8_recursive($value);
        }
        return $clean;
    }
    if (is_string($data)) {
        return ai_assistant_sanitize_utf8_string($data);
    }
    return $data;
}

/**
 * @return array{kind:string,period:string,module:string}|null
 */
function ai_assistant_detect_report_intent(string $question): ?array
{
    $q = strtolower(trim($question));
    if ($q === '') {
        return null;
    }

    $wantsReport = (bool) preg_match(
        '/\b(summary|summaries|breakdown|overview|dashboard|kpi|report|stats|statistics|show me|give me)\b/',
        $q
    );
    if (!$wantsReport) {
        return null;
    }

    if (preg_match('/\b(voucher|vouchers|payment|payments|disbursement)\b/', $q)) {
        return ['kind' => 'voucher_summary', 'period' => ai_assistant_parse_period_key($q), 'module' => 'voucher'];
    }
    if (preg_match('/\b(attendance|late|shift|overtime)\b/', $q)) {
        return ['kind' => 'attendance_summary', 'period' => ai_assistant_parse_period_key($q), 'module' => 'attendance'];
    }

    return null;
}

function ai_assistant_parse_period_key(string $question): string
{
    $q = strtolower($question);
    if (preg_match('/\b(today|this day)\b/', $q)) {
        return 'today';
    }
    if (preg_match('/\b(this month|monthly|current month)\b/', $q)) {
        return 'month';
    }
    if (preg_match('/\b(this week|weekly|week)\b/', $q)) {
        return 'week';
    }
    if (preg_match('/\b(last week|previous week)\b/', $q)) {
        return 'last_week';
    }
    return 'week';
}

/**
 * @return array{start:string,end:string,label:string}
 */
function ai_assistant_period_bounds(string $periodKey): array
{
    $now = new DateTimeImmutable('now');
    $rangeSep = ' - ';

    switch ($periodKey) {
        case 'today':
            $start = $now->setTime(0, 0, 0);
            $end = $now->setTime(23, 59, 59);
            $label = $start->format('M d, Y');
            break;
        case 'month':
            $start = $now->modify('first day of this month')->setTime(0, 0, 0);
            $end = $now->setTime(23, 59, 59);
            $label = $start->format('M d') . $rangeSep . $end->format('M d, Y');
            break;
        case 'last_week':
            $start = $now->modify('monday last week')->setTime(0, 0, 0);
            $end = $start->modify('+6 days')->setTime(23, 59, 59);
            $label = $start->format('M d') . $rangeSep . $end->format('M d, Y');
            break;
        case 'week':
        default:
            $start = $now->modify('monday this week')->setTime(0, 0, 0);
            if ($start > $now) {
                $start = $start->modify('-7 days');
            }
            $end = $now->setTime(23, 59, 59);
            $label = $start->format('M d') . $rangeSep . $end->format('M d, Y');
            break;
    }

    return [
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'label' => $label,
    ];
}

function ai_assistant_format_money(float $amount, string $currency = 'TZS'): string
{
    return $currency . ' ' . number_format($amount, 2);
}

function ai_assistant_budget_label(string $raw): string
{
    $map = [
        'operational expenses' => 'Operations',
        'procurement & supplies' => 'Projects & CAPEX',
        'employee costs' => 'HR & Payroll',
        'sales & marketing' => 'Administration',
        'administration' => 'Administration',
        'projects & capex' => 'Projects & CAPEX',
        'projects' => 'Projects & CAPEX',
        'capex' => 'Projects & CAPEX',
    ];
    $key = strtolower(trim($raw));
    if (isset($map[$key])) {
        return $map[$key];
    }
    if ($raw === '') {
        return 'Unclassified';
    }
    return ai_assistant_sanitize_utf8_string($raw);
}

/**
 * @return array<string, mixed>|null
 */
function ai_assistant_build_voucher_summary_report(
    PDO $pdo,
    int $userId,
    int $companyId,
    string $role,
    string $periodKey = 'week'
): ?array {
    if (!function_exists('tableExists') || !tableExists('payment_vouchers', $pdo)) {
        return null;
    }

    $bounds = ai_assistant_period_bounds($periodKey);
    $isPrivileged = in_array(strtolower($role), ['admin', 'administrator', 'superadmin', 'manager', 'department_manager', 'company_admin', 'owner', 'finance'], true);

    $companySql = function_exists('getCompanySql') ? getCompanySql('pv') : '';
    $companyParams = function_exists('getCompanyParam') ? getCompanyParam() : [];

    $where = ['DATE(COALESCE(pv.date_created, pv.created_at)) BETWEEN DATE(?) AND DATE(?)'];
    $params = [$bounds['start'], $bounds['end']];

    if ($companySql !== '') {
        $where[] = ltrim(str_replace('AND ', '', $companySql));
        $params = array_merge($params, $companyParams);
    }
    if (!$isPrivileged) {
        $where[] = 'pv.created_by = ?';
        $params[] = $userId;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $statsSql = "SELECT
            COUNT(*) AS total,
            SUM(pv.total_amount) AS total_value,
            SUM(CASE WHEN pv.status = 'approved' OR IFNULL(pv.is_paid,0) = 1 OR IFNULL(pv.is_posted,0) = 1 THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN pv.status IN ('pending','confirming') AND IFNULL(pv.is_paid,0) = 0 THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN pv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
            MAX(pv.currency) AS primary_currency
        FROM payment_vouchers pv
        $whereClause";
    $stmt = $pdo->prepare($statsSql);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int) ($stats['total'] ?? 0);
    $currency = (string) ($stats['primary_currency'] ?? 'TZS');
    if ($currency === '') {
        $currency = 'TZS';
    }
    $totalValue = (float) ($stats['total_value'] ?? 0);
    $approved = (int) ($stats['approved'] ?? 0);
    $pending = (int) ($stats['pending'] ?? 0);
    $rejected = (int) ($stats['rejected'] ?? 0);

    $tableRows = [];
    if (tableExists('voucher_items', $pdo)) {
        $budgetSql = "SELECT
                COALESCE(NULLIF(TRIM(vi.budget_type), ''), 'Unclassified') AS budget_type,
                COUNT(DISTINCT pv.id) AS voucher_count,
                SUM(vi.amount) AS total_amount
            FROM payment_vouchers pv
            INNER JOIN voucher_items vi ON vi.voucher_id = pv.id
            $whereClause
            GROUP BY budget_type
            ORDER BY total_amount DESC";
        $bStmt = $pdo->prepare($budgetSql);
        $bStmt->execute($params);
        $budgetRows = $bStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $budgetTotal = 0.0;
        foreach ($budgetRows as $row) {
            $budgetTotal += (float) ($row['total_amount'] ?? 0);
        }
        foreach ($budgetRows as $row) {
            $amt = (float) ($row['total_amount'] ?? 0);
            $pct = $budgetTotal > 0 ? round(($amt / $budgetTotal) * 100, 1) : 0;
            $tableRows[] = [
                ai_assistant_budget_label((string) ($row['budget_type'] ?? '')),
                (int) ($row['voucher_count'] ?? 0),
                number_format($amt, 2),
                $pct . '%',
            ];
        }
        $tableFooter = [
            'Total',
            $total,
            number_format($budgetTotal > 0 ? $budgetTotal : $totalValue, 2),
            '100%',
        ];
    } else {
        $tableFooter = ['Total', $total, number_format($totalValue, 2), '100%'];
    }

    $periodTitles = [
        'today' => 'Today',
        'week' => 'This Week',
        'last_week' => 'Last Week',
        'month' => 'This Month',
    ];
    $periodTitle = $periodTitles[$periodKey] ?? 'This Week';

    $intro = $total > 0
        ? "Here is your voucher summary for {$periodTitle} ({$bounds['label']})."
        : "No vouchers were found for {$periodTitle} ({$bounds['label']}).";

    $reportId = 'voucher_summary_' . $periodKey . '_' . date('Ymd');

    $report = [
        'type' => 'kpi_summary',
        'reportId' => $reportId,
        'module' => 'voucher',
        'title' => 'Voucher Summary - ' . $periodTitle,
        'periodLabel' => $bounds['label'],
        'periodKey' => $periodKey,
        'intro' => $intro,
        'cards' => [
            ['key' => 'total', 'label' => 'Total Vouchers', 'value' => (string) $total, 'tone' => 'default'],
            ['key' => 'amount', 'label' => 'Total Amount', 'value' => ai_assistant_format_money($totalValue, $currency), 'tone' => 'default'],
            ['key' => 'approved', 'label' => 'Approved', 'value' => (string) $approved, 'tone' => 'success'],
            ['key' => 'pending', 'label' => 'Pending', 'value' => (string) $pending, 'tone' => 'warning'],
            ['key' => 'rejected', 'label' => 'Rejected', 'value' => (string) $rejected, 'tone' => 'danger'],
        ],
        'table' => [
            'title' => 'Breakdown by Budget Type',
            'columns' => ['Budget Type', 'No. of Vouchers', 'Total Amount (' . $currency . ')', '% of Total'],
            'rows' => $tableRows,
            'footer' => $tableFooter,
        ],
        'actions' => [
            ['id' => 'view_details', 'label' => 'View Details', 'icon' => 'eye', 'type' => 'link'],
            ['id' => 'export_pdf', 'label' => 'Export PDF', 'icon' => 'file', 'type' => 'export', 'format' => 'pdf'],
            ['id' => 'export_excel', 'label' => 'Export Excel', 'icon' => 'sheet', 'type' => 'export', 'format' => 'csv'],
            ['id' => 'export_png', 'label' => 'Share Image', 'icon' => 'share', 'type' => 'export', 'format' => 'png'],
        ],
        'exportPayload' => [
            'kind' => 'voucher_summary',
            'periodKey' => $periodKey,
            'periodStart' => $bounds['start'],
            'periodEnd' => $bounds['end'],
            'currency' => $currency,
            'stats' => [
                'total' => $total,
                'total_value' => $totalValue,
                'approved' => $approved,
                'pending' => $pending,
                'rejected' => $rejected,
            ],
            'budgetRows' => $tableRows,
        ],
    ];

    return ai_assistant_sanitize_utf8_recursive($report);
}

/**
 * @return array<string, mixed>|null
 */
function ai_assistant_build_report_for_intent(
    PDO $pdo,
    int $userId,
    int $companyId,
    string $role,
    array $intent
): ?array {
    if (($intent['kind'] ?? '') === 'voucher_summary') {
        return ai_assistant_build_voucher_summary_report(
            $pdo,
            $userId,
            $companyId,
            $role,
            (string) ($intent['period'] ?? 'week')
        );
    }
    return null;
}

/**
 * @param array<string, mixed> $report
 */
function ai_assistant_report_text_summary(array $report): string
{
    $lines = [(string) ($report['intro'] ?? '')];
    foreach ($report['cards'] ?? [] as $card) {
        if (!is_array($card)) {
            continue;
        }
        $lines[] = sprintf('- %s: %s', (string) ($card['label'] ?? ''), (string) ($card['value'] ?? ''));
    }
    return implode("\n", array_filter($lines));
}
