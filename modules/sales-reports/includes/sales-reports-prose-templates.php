<?php
/**
 * Exact PDF prose templates with ERP placeholder substitution.
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-data.php';

function salesReportsFormatPeriodFull(string $start, string $end): string
{
    if ($start === '' || $end === '') {
        return '';
    }
    $s = strtotime($start);
    $e = strtotime($end);
    if (!$s || !$e) {
        return '';
    }
    if (date('F Y', $s) === date('F Y', $e)) {
        return date('F Y', $s);
    }
    if (date('Y', $s) === date('Y', $e)) {
        return date('F', $s) . ' to ' . date('F Y', $e);
    }

    return date('F Y', $s) . ' to ' . date('F Y', $e);
}

function salesReportsFormatMoneyPlain(float $amount): string
{
    return number_format($amount, 0, '.', ',');
}

function salesReportsBuildTemplateContext(PDO $pdo, array $report): array
{
    $filters = salesReportsFiltersFromReport($report);
    $previous = salesReportsPreviousPeriodFilters($filters);
    $labels = salesReportsPeriodPairLabels($filters, $previous);
    $drill = smart_report_sales_drilldown($pdo, $filters);
    $team = smart_report_sales_team_performance_data($pdo, $filters);
    $teamPrev = smart_report_sales_team_performance_data($pdo, $previous);
    $quotation = salesReportsDataQuotationAnalysis($pdo, $filters);
    $quarterComparison = salesReportsDataQuarterComparison($pdo, $filters);
    $delayed = salesReportsDataDelayedOrders($pdo, $filters);

    $reps = array_values(array_filter($team['reps'] ?? [], static fn($r) => !empty($r['counts_toward_target'])));
    $repCount = count($reps);
    $teamTarget = (float) ($team['team_target'] ?? 0);
    $teamActual = (float) ($team['team_actual'] ?? 0);
    $qSnap = $quarterComparison['snapshot'] ?? [];
    $teamPrevActual = (float) ($qSnap['total_previous'] ?? ($teamPrev['team_actual'] ?? 0));
    $teamActualForComparison = (float) ($qSnap['total_current'] ?? $teamActual);
    $achievementPct = $team['achievement_pct'] !== null ? (float) $team['achievement_pct'] : null;
    $growthPct = $qSnap['total_variance_pct'] ?? null;

    $individualTarget = 0.0;
    $sumRepTargets = 0.0;
    if ($repCount > 0) {
        $targets = array_map(static fn($r) => (float) ($r['target'] ?? 0), $reps);
        $sumRepTargets = array_sum($targets);
        $nonZero = array_filter($targets, static fn($t) => $t > 0);
        if ($nonZero !== []) {
            $individualTarget = array_sum($nonZero) / count($nonZero);
        } elseif ($teamTarget > 0) {
            $individualTarget = $teamTarget / $repCount;
        }
    }

    $displayTeamTarget = $sumRepTargets > 0 ? $sumRepTargets : $teamTarget;
    if ($displayTeamTarget > 0) {
        $achievementPct = round(($teamActual / $displayTeamTarget) * 100, 2);
    }
    $quotSnap = $quotation['snapshot'] ?? [];
    $qRows = $quotSnap['rows'] ?? [];

    $cur = salesReportsCurrency();

    return [
        'currency' => $cur,
        'period_full' => salesReportsFormatPeriodFull($report['start_date'] ?? '', $report['end_date'] ?? ''),
        'cover_period' => salesReportsFormatCoverPeriod($report['start_date'] ?? '', $report['end_date'] ?? ''),
        'cover_year' => salesReportsFormatCoverYear($report['start_date'] ?? '', $report['end_date'] ?? ''),
        'prev_label' => $labels['previous'],
        'cur_label' => $labels['current'],
        'rep_count' => $repCount,
        'individual_target' => $individualTarget,
        'team_target' => $displayTeamTarget,
        'team_actual' => $teamActual,
        'team_prev_actual' => $teamPrevActual,
        'team_comparison_actual' => $teamActualForComparison,
        'achievement_pct' => $achievementPct,
        'growth_pct' => $growthPct,
        'total_quotes' => (int) ($quotSnap['total_sent'] ?? 0),
        'converted_orders' => (int) ($quotSnap['total_converted'] ?? 0),
        'conversion_rate' => (float) ($quotSnap['team_conversion_rate'] ?? 0),
        'quotation_rows' => $qRows,
        'top_customers' => array_slice($drill['top_customers'] ?? [], 0, 4),
        'delayed_rows' => $delayed['snapshot']['rows'] ?? [],
        'delayed_total' => (float) ($delayed['snapshot']['total_delayed'] ?? 0),
        'quarter_comparison_html' => $quarterComparison['html'] ?? '',
        'quotation_table_html' => $quotation['html'] ?? '',
        'rep_monthly_html' => salesReportsDataRepMonthlyOverview($pdo, $filters)['html'] ?? '',
    ];
}

function salesReportsRenderProseTemplate(PDO $pdo, array $report, string $section): string
{
    $ctx = salesReportsBuildTemplateContext($pdo, $report);
    $cur = $ctx['currency'];

    return match ($section) {
        'executive_summary' => salesReportsTplExecutiveSummary($ctx, $cur),
        'individual_sales_performance' => salesReportsTplIndividualPerformance($ctx, $cur),
        'quotation_analysis' => salesReportsTplQuotationAnalysis($ctx, $cur),
        'top_client_contribution' => salesReportsTplTopClients($ctx),
        'key_achievements' => salesReportsTplKeyAchievements(),
        'challenges' => salesReportsTplChallenges(),
        'delayed_revenue' => salesReportsTplDelayedRevenue($ctx, $cur),
        'action_plan' => salesReportsTplActionPlan(),
        'conclusion' => salesReportsTplConclusion($ctx, $cur),
        'salesperson_appendix' => (string) ($ctx['rep_monthly_html'] ?? ''),
        default => '',
    };
}

function salesReportsTplExecutiveSummary(array $ctx, string $cur): string
{
    $ach = $ctx['achievement_pct'] !== null ? number_format($ctx['achievement_pct'], 2) : 'N/A';
    $targetNote = ($ctx['achievement_pct'] ?? 0) >= 100
        ? 'Having achieved the departmental target, the team maintained strong client engagement and continued to secure business opportunities.'
        : 'Despite missing the target, the team maintained strong client engagement and continued to secure business opportunities.';

    return '<p>This report presents the sales performance of the Sales Department for '
        . htmlspecialchars($ctx['period_full']) . '. The department consists of '
        . (int) $ctx['rep_count'] . ' sales personnel, each assigned an individual quarterly sales target of '
        . $cur . ' ' . salesReportsFormatMoneyPlain((float) $ctx['individual_target']) . ', resulting in a combined departmental target of '
        . $cur . ' ' . salesReportsFormatMoneyPlain((float) $ctx['team_target']) . '. During the reporting period, the Sales Department generated total sales of '
        . $cur . ' ' . salesReportsFormatMoneyPlain((float) $ctx['team_actual']) . ', achieving '
        . $ach . '% of the departmental target. ' . $targetNote . '</p>';
}

function salesReportsTplIndividualPerformance(array $ctx, string $cur): string
{
    $growth = $ctx['growth_pct'] !== null ? number_format(abs((float) $ctx['growth_pct']), 2) : '0.00';
    $direction = ((float) ($ctx['growth_pct'] ?? 0)) >= 0 ? 'increased' : 'decreased';
    $trendWord = ((float) ($ctx['growth_pct'] ?? 0)) >= 0 ? 'growth' : 'decline';
    $prevLabel = htmlspecialchars($ctx['prev_label']);
    $curLabel = htmlspecialchars($ctx['cur_label']);

    $html = '<p>A comparative analysis between the '
        . $prevLabel . ' and ' . $curLabel . ' was conducted to assess individual sales performance trends and overall departmental progress. The analysis shows that the department&rsquo;s total sales ' . $direction . ' from '
        . $cur . ' ' . salesReportsFormatMoneyPlain((float) $ctx['team_prev_actual']) . ' in ' . $prevLabel . ' to '
        . $cur . ' ' . salesReportsFormatMoneyPlain((float) ($ctx['team_comparison_actual'] ?? $ctx['team_actual'])) . ' in ' . $curLabel . ', representing an overall ' . $trendWord . ' of '
        . $growth . '%.</p>'
        . '<p>While some sales personnel recorded significant improvement in ' . $curLabel . ', individual performance varied due to factors such as customer order cycles, order value, and market conditions.</p>'
        . '<p><strong>Quarter-to-Quarter Performance Benchmark:</strong></p>'
        . ($ctx['quarter_comparison_html'] ?? '');

    return $html;
}

function salesReportsTplQuotationAnalysis(array $ctx, string $cur): string
{
    $rate = number_format((float) $ctx['conversion_rate'], 2);
    $html = '<p>During the reporting period, the Sales Department submitted a total of '
        . number_format((int) $ctx['total_quotes']) . ' quotations, out of which '
        . number_format((int) $ctx['converted_orders']) . ' were successfully converted into confirmed orders, resulting in a quotation conversion rate of '
        . $rate . '%. This indicates moderate conversion efficiency and highlights opportunities to further improve follow-up, pricing strategy, and customer engagement to increase sales closure rates.</p>'
        . ($ctx['quotation_table_html'] ?? '')
        . salesReportsTplQuotationCommentary($ctx['quotation_rows']);

    return $html;
}

function salesReportsTplQuotationCommentary(array $rows): string
{
    if ($rows === []) {
        return '<p>The quotation analysis reflects varying strengths among team members in both conversion efficiency and sales value contribution.</p>';
    }

    $bySent = $rows;
    usort($bySent, static fn($a, $b) => ($b['quotations_sent'] ?? 0) <=> ($a['quotations_sent'] ?? 0));
    $byRate = $rows;
    usort($byRate, static fn($a, $b) => ($b['conversion_rate'] ?? 0) <=> ($a['conversion_rate'] ?? 0));

    $topSent = (string) ($bySent[0]['name'] ?? '');
    $topRate = (string) ($byRate[0]['name'] ?? '');
    $lowRate = (string) ($byRate[count($byRate) - 1]['name'] ?? '');

    $parts = ['<p>The quotation analysis reflects varying strengths among team members in both conversion efficiency and sales value contribution.'];
    if ($topSent !== '') {
        $parts[] = htmlspecialchars($topSent) . ' recorded the highest number of quotations submitted and converted, demonstrating strong follow-up and customer engagement.';
    }
    if ($topRate !== '' && $topRate !== $topSent) {
        $parts[] = htmlspecialchars($topRate) . ' achieved the highest conversion rate, showing strong efficiency in closing sales opportunities.';
    }
    if ($lowRate !== '' && count($rows) > 2) {
        $parts[] = htmlspecialchars($lowRate) . ' showed growth potential with opportunities to improve lead generation and conversion.';
    }
    $parts[] = '</p>';

    return implode(' ', $parts);
}

function salesReportsTplTopClients(array $ctx): string
{
    $clients = $ctx['top_customers'] ?? [];
    $descriptions = [
        'remained one of the major contributors, particularly through continued PPE and bulk orders.',
        'continued as a strategic repeat client with consistent order frequency.',
        'contributed through recurring supply orders during the quarter.',
        'emerged as a promising new client during the quarter, with potential for future business growth and long-term strategic partnership.',
    ];

    $html = '<p>Key repeat clients during this quarter included:</p><ul>';
    if ($clients === []) {
        $html .= '<li>No major client data recorded for this period.</li>';
    } else {
        foreach ($clients as $i => $c) {
            $name = htmlspecialchars((string) ($c['company_name'] ?? $c['customer_name'] ?? ''));
            $desc = $descriptions[$i] ?? 'contributed to overall departmental sales during the quarter.';
            $html .= '<li><strong>' . $name . '</strong> &mdash; ' . $desc . '</li>';
        }
    }
    $html .= '</ul>';

    return $html;
}

function salesReportsTplDelayedRevenue(array $ctx, string $cur): string
{
    $curLabel = htmlspecialchars($ctx['cur_label']);
    $total = salesReportsFormatMoneyPlain((float) $ctx['delayed_total']);
    $rows = $ctx['delayed_rows'] ?? [];

    $html = '<p>During the reporting period, several customer orders with significant revenue potential, with amounting to a combined value of '
        . $cur . ' ' . $total . '/= were not completed within ' . $curLabel . ' due to operational and coordination challenges. This consequently impacted the department&rsquo;s overall quarterly sales performance.</p>';

    if ($rows === []) {
        return $html;
    }

    $shown = array_slice($rows, 0, 5);
    foreach ($shown as $idx => $row) {
        $rep = htmlspecialchars((string) ($row['rep_name'] ?? 'Sales personnel'));
        $customer = htmlspecialchars((string) ($row['customer_name'] ?? 'Customer'));
        $amount = salesReportsFormatMoneyPlain((float) ($row['balance_due'] ?? $row['total_amount'] ?? 0));
        $invoice = htmlspecialchars((string) ($row['invoice_number'] ?? ''));

        if ($idx === 0) {
            $html .= '<p><strong>' . $rep . ' &ndash; ' . $customer . ' Order</strong><br>'
                . 'A promising order from ' . $customer . ' under ' . $rep . '&rsquo;s portfolio, valued at '
                . $cur . ' ' . $amount . '/= remained undelivered during the quarter due to delays and miscommunication, resulting in late completion. Had the process proceeded as planned, the order could have been successfully delivered and recognized within '
                . $curLabel . ' sales.</p>';
        } else {
            $html .= '<p><strong>' . $rep . ' &ndash; ' . $customer . '</strong><br>'
                . 'An order under ' . $rep . ' valued at ' . $cur . ' ' . $amount . '/='
                . ($invoice !== '' ? ' (' . $invoice . ')' : '')
                . ' experienced delays that affected completion within the quarter. Under normal circumstances, the full order would have been completed within the quarter.</p>';
        }
    }

    return $html;
}

function salesReportsTplConclusion(array $ctx, string $cur): string
{
    $ach = $ctx['achievement_pct'] !== null ? number_format($ctx['achievement_pct'], 2) : 'N/A';
    $period = htmlspecialchars($ctx['period_full']);

    return '<p>The Sales Department recorded strong performance during the ' . $period . ' period, achieving '
        . $cur . ' ' . salesReportsFormatMoneyPlain((float) $ctx['team_actual']) . ', equivalent to '
        . $ach . '% of the quarterly target. Despite challenges such as market competition, delayed quotation approvals, and occasional delivery delays, the team maintained good customer relationships and secured valuable business. With improved communication, stronger follow-up, and continued teamwork, the department is well-positioned to achieve better results in the next quarter.</p>';
}

function salesReportsProseTemplateSections(): array
{
    return [
        'executive_summary',
        'individual_sales_performance',
        'quotation_analysis',
        'top_client_contribution',
        'key_achievements',
        'challenges',
        'delayed_revenue',
        'action_plan',
        'conclusion',
        'salesperson_appendix',
    ];
}
