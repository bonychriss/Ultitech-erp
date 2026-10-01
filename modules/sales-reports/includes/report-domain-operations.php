<?php
/**
 * Operations department report.
 * Wording follows the operations monthly sample. The period is filled from the
 * selected range. Outstanding balances are read from invoices, not typed in.
 */

declare(strict_types=1);

function reportDomainOperationsContext(array $meta): array
{
    $start = (string) ($meta['start_date'] ?? '');
    $end = (string) ($meta['end_date'] ?? '');
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    $period = salesReportsFormatCoverPeriod($start, $end);
    $year = salesReportsFormatCoverYear($start, $end);
    $singleMonth = $startTs && $endTs && date('Y-m', $startTs) === date('Y-m', $endTs);
    $endMonth = $endTs ? date('F', $endTs) : $period;

    if ($singleMonth) {
        $during = 'the month of ' . $endMonth;
        $inPeriod = $endMonth;
        $endOf = $endMonth;
    } else {
        $from = $startTs ? date('j F Y', $startTs) : $start;
        $to = $endTs ? date('j F Y', $endTs) : $end;
        $during = 'the period from ' . $from . ' to ' . $to;
        $inPeriod = trim($period . ' ' . $year);
        $endOf = $endTs ? date('F Y', $endTs) : $period;
    }

    return [
        'title' => trim('OVERALL REPORT - ' . $period . ' ' . $year),
        'during' => $during,
        'in_period' => $inPeriod,
        'end_of' => $endOf,
        'end_month' => $endMonth,
        'end_date' => $endTs ? date('Y-m-d', $endTs) : $end,
        'as_of' => $endTs ? date('j F Y', $endTs) : $end,
        'ytd' => 'January to ' . $endMonth,
    ];
}

function reportDomainOperationsReportName(string $start, string $end): string
{
    return reportDomainOperationsContext([
        'start_date' => $start,
        'end_date' => $end,
    ])['title'];
}

function reportDomainOperationsH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function reportDomainOperationsSectionContent(string $key, array $meta, string $title): string
{
    if ($key === 'cover') {
        return reportEngineBuildCoverHtml('operations', $meta);
    }

    $ctx = reportDomainOperationsContext($meta);
    $headingTitle = $key === 'overall_summary' ? $ctx['title'] : $title;
    $heading = salesReportsSectionHeading($headingTitle !== '' ? $headingTitle : 'Section');

    return match ($key) {
        'overall_summary' => $heading
            . '<p>This report provides an overall summary of the activities carried out during '
            . reportDomainOperationsH($ctx['during'])
            . '. The main activities focused on client follow-ups, social media management, and document preparation, with the aim of improving customer communication, increasing engagement, and maintaining accurate and well-organized records.</p>',
        'client_followups' => $heading . '<p>' . reportDomainOperationsH(reportDomainOperationsFollowupsLead($ctx)) . '</p>',
        'documents_preparation' => $heading . reportDomainOperationsDocumentsHtml($ctx),
        'social_media' => $heading
            . '<p>During ' . reportDomainOperationsH($ctx['during'])
            . ', I actively managed the company&rsquo;s digital platforms by promoting products and engaging with customers through TikTok and Instagram. The activities were carried out consistently, three times per week, by posting different products online through social media.</p>',
        'operations_achievements' => $heading
            . '<ul>'
            . '<li>Improved document organization: properly arranged COGS (purchases) and revenue documents from '
            . reportDomainOperationsH($ctx['ytd'])
            . ', with the required supporting documents attached.</li>'
            . '<li>Successfully maintained regular communication with clients through calls, emails, and visits, reminding them to settle their outstanding balances.</li>'
            . '</ul>',
        'operations_challenges' => $heading
            . '<ul>'
            . '<li>Some documents were missing important details or supporting information, which made it difficult to complete and arrange the records properly. In some cases, certain documents were also not available in the system, requiring additional effort to verify and organize the information.</li>'
            . '<li>The digital platforms currently have a low number of followers, which resulted in limited customer responses and interactions with the posted content. This made it challenging to generate the expected engagement and reach more potential customers.</li>'
            . '</ul>',
        'operations_conclusion' => $heading
            . '<p>Overall, the activities carried out in ' . reportDomainOperationsH($ctx['in_period'])
            . ' contributed to better client follow-ups, improved document organization, and consistent social media promotion. Despite some challenges, steady progress was made, and further efforts will be focused on improving documentation and increasing social media engagement.</p>',
        default => $heading . '<p></p>',
    };
}

function reportDomainOperationsFollowupsLead(array $ctx): string
{
    return 'During ' . $ctx['during']
        . ', regular follow-ups were conducted with clients regarding their pending outstanding balances. Clients were reminded to make payments through phone calls and emails, conducted three times per week, and through physical visits. The follow-ups aimed to encourage timely payment, reduce outstanding debts, and maintain good communication with clients.';
}

function reportDomainOperationsDataTable(array $headers, array $rows, string $emptyMessage, array $rightColumns = []): string
{
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;">';
    foreach ($headers as $header) {
        $html .= '<th>' . reportDomainOperationsH($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    if ($rows === []) {
        $html .= '<tr><td colspan="' . count($headers) . '">' . reportDomainOperationsH($emptyMessage) . '</td></tr>';
    }
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach (array_values($row) as $index => $cell) {
            $align = in_array($index, $rightColumns, true) ? ' align="right"' : '';
            $html .= '<td' . $align . '>' . $cell . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function reportDomainOperationsDocumentsHtml(array $ctx): string
{
    $ytd = reportDomainOperationsH($ctx['ytd']);
    $rows = [
        ['COGS (Purchases)', $ytd, 'Payment vouchers, invoices, receipts, and proof of payment', 'Arranged and attached'],
        ['Revenue', $ytd, 'Invoices, EFD receipts, and delivery notes', 'Organized'],
        ['Expenses', $ytd, 'Payment vouchers, invoices, receipts, and proof of payment', 'Record which months were completed'],
    ];
    $tableRows = [];
    foreach ($rows as $row) {
        $tableRows[] = array_map(static fn($cell) => reportDomainOperationsH($cell), $row);
    }

    return '<p>By the end of ' . reportDomainOperationsH($ctx['end_of'])
        . ', various financial documents were prepared, attached, and properly arranged to ensure accurate record keeping.</p>'
        . reportDomainOperationsDataTable(
            ['Document', 'Period covered', 'Supporting documents', 'Status'],
            $tableRows,
            'No document areas recorded for this period.'
        );
}

function reportDomainOperationsFollowupsBody(PDO $pdo, array $report): string
{
    $ctx = reportDomainOperationsContext($report);
    $rows = reportDomainOperationsOutstandingRows($pdo, (string) $ctx['end_date']);
    $tableRows = [];
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float) $row['outstanding'];
    }
    $i = 1;
    foreach (array_slice($rows, 0, 50) as $row) {
        $amount = (float) $row['outstanding'];
        $tableRows[] = [
            (string) $i++,
            reportDomainOperationsH((string) $row['name']),
            reportDomainOperationsH(number_format((int) ($row['invoices'] ?? 0))),
            reportDomainOperationsH(salesReportsFormatMoney($amount)),
        ];
    }
    if ($tableRows !== []) {
        $tableRows[] = [
            '',
            '<strong>Total</strong>',
            '',
            '<strong>' . reportDomainOperationsH(salesReportsFormatMoney($total)) . '</strong>',
        ];
    }

    return '<p>' . reportDomainOperationsH(reportDomainOperationsFollowupsLead($ctx)) . '</p>'
        . '<p>Outstanding balances still recorded on invoices dated up to '
        . reportDomainOperationsH((string) $ctx['as_of']) . ':</p>'
        . reportDomainOperationsDataTable(
            ['#', 'Customer', 'Invoices', 'Outstanding'],
            $tableRows,
            'No outstanding balances were recorded for this period.',
            [2, 3]
        );
}

function reportDomainOperationsOutstandingRows(PDO $pdo, string $endDate): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return [];
    }

    $db = $pdo;
    if (!tableExists('invoices', $db) || !tableExists('customers', $db)) {
        $salesFile = dirname(__DIR__, 2) . '/sales/functions.php';
        if (is_file($salesFile)) {
            require_once $salesFile;
        }
        if (function_exists('sales_pdo')) {
            $sales = sales_pdo();
            if ($sales instanceof PDO && tableExists('invoices', $sales) && tableExists('customers', $sales)) {
                $db = $sales;
            }
        }
    }
    if (!tableExists('invoices', $db) || !tableExists('customers', $db)) {
        return [];
    }

    $paidExpr = '0';
    $params = [];
    if (tableExists('sales_payments', $db)) {
        $paidExpr = '(SELECT COALESCE(SUM(p.amount), 0) FROM sales_payments p WHERE p.invoice_id = i.id AND p.payment_date <= ?)';
        $params[] = $endDate;
    }

    $sql = 'SELECT c.company_name AS name, COUNT(i.id) AS invoices, SUM(i.total_amount) - SUM(' . $paidExpr . ') AS outstanding
            FROM invoices i
            INNER JOIN customers c ON c.id = i.customer_id
            WHERE i.status NOT IN (\'cancelled\', \'canceled\', \'draft\', \'void\', \'voided\', \'deleted\')
              AND DATE(COALESCE(NULLIF(i.invoice_date, \'0000-00-00\'), NULLIF(i.invoice_date, \'0000-00-00 00:00:00\'), i.created_at)) <= ?';
    $params[] = $endDate;
    if (function_exists('salesAppendCompanyScope')) {
        salesAppendCompanyScope($sql, $params, 'invoices', 'i');
    } elseif (function_exists('analytics_append_company_scope')) {
        analytics_append_company_scope($sql, $params, 'invoices', 'i', $db);
    }
    $sql .= ' GROUP BY c.id, c.company_name HAVING outstanding > 0.5 ORDER BY outstanding DESC';

    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('reportDomainOperationsOutstandingRows: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $amount = (float) ($row['outstanding'] ?? 0);
        if ($name === '' || $amount <= 0.5) {
            continue;
        }
        $out[] = [
            'name' => $name,
            'invoices' => (int) ($row['invoices'] ?? 0),
            'outstanding' => $amount,
        ];
    }

    return $out;
}
