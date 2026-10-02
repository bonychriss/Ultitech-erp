<?php
/**
 * Department sales report format (matches management quarterly PDF layout).
 */

declare(strict_types=1);

function salesReportsDepartmentSectionKeys(): array
{
    return [
        'cover',
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

function salesReportsFormatCoverPeriod(string $start, string $end): string
{
    if ($start === '' || $end === '') {
        return '';
    }
    $s = strtotime($start);
    $e = strtotime($end);
    if (!$s || !$e) {
        return '';
    }
    $startMonth = strtoupper(date('F', $s));
    $endMonth = strtoupper(date('F', $e));
    if (date('Y-m', $s) === date('Y-m', $e)) {
        return $startMonth;
    }

    return $startMonth . '-' . $endMonth;
}

function salesReportsFormatCoverYear(string $start, string $end): string
{
    if ($end !== '') {
        return date('Y', strtotime($end));
    }
    if ($start !== '') {
        return date('Y', strtotime($start));
    }

    return date('Y');
}

function salesReportsDepartmentLabel(array $meta): string
{
    $dept = trim((string) ($meta['department'] ?? ''));
    if ($dept === '' || preg_match('/^sales$/i', $dept)) {
        return 'SALES AND MARKETING DEPARTMENT';
    }
    if (stripos($dept, 'marketing') === false && stripos($dept, 'sales') !== false) {
        return 'SALES AND MARKETING DEPARTMENT';
    }

    return strtoupper($dept);
}

function salesReportsCompanyLogoUrl(): string
{
    if (!function_exists('getCompanyLogoUrl')) {
        $functions = dirname(__DIR__, 3) . '/includes/functions.php';
        if (is_file($functions)) {
            require_once $functions;
        }
    }

    return function_exists('getCompanyLogoUrl') ? trim(getCompanyLogoUrl()) : '';
}

function salesReportsCompanyLogoSrcForExport(): string
{
    $url = salesReportsCompanyLogoUrl();
    if ($url === '') {
        return '';
    }

    $root = dirname(__DIR__, 3);
    if (is_file($url)) {
        return str_replace('\\', '/', $url);
    }

    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    if (!is_string($path) || $path === '') {
        return $url;
    }

    $candidates = [];
    if ($path[0] === '/') {
        $appPath = '';
        if (function_exists('app_url')) {
            $appPath = rtrim((string) (parse_url(app_url(''), PHP_URL_PATH) ?? ''), '/');
        }
        if ($appPath !== '' && str_starts_with($path, $appPath)) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim(substr($path, strlen($appPath)), '/'));
        }
        $candidates[] = $root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    } else {
        $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    foreach ($candidates as $disk) {
        if (is_file($disk)) {
            return str_replace('\\', '/', $disk);
        }
    }

    return $url;
}

function salesReportsCompanyLogoHtml(string $maxHeight = '72px', string $position = 'center'): string
{
    $src = salesReportsCompanyLogoSrcForExport();
    if ($src === '') {
        return '';
    }

    $wrapStyle = match ($position) {
        'top-right' => 'position:absolute;top:0;right:0;text-align:right;margin:0;z-index:1;',
        default => 'margin:0 auto 28px;text-align:center;',
    };

    return '<div class="sr-company-logo sr-company-logo--' . htmlspecialchars($position, ENT_QUOTES, 'UTF-8') . '" style="' . $wrapStyle . '">'
        . '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-height:'
        . htmlspecialchars($maxHeight, ENT_QUOTES, 'UTF-8') . ';max-width:220px;height:auto;width:auto;" />'
        . '</div>';
}

function salesReportsPreparedByLine(array $meta, string $style = ''): string
{
    $prepared = trim((string) ($meta['prepared_by'] ?? ''));
    if ($prepared === '') {
        return '';
    }

    $styleAttr = $style !== '' ? ' style="' . $style . '"' : '';

    return '<p' . $styleAttr . '>Prepared by: <strong>'
        . htmlspecialchars($prepared, ENT_QUOTES, 'UTF-8') . '</strong></p>';
}

function salesReportsBuildDepartmentCoverHtml(array $meta): string
{
    if (!function_exists('reportEngineBuildCoverHtml')) {
        require_once __DIR__ . '/report-engine.php';
    }

    $domain = function_exists('reportEngineReportDomain') ? reportEngineReportDomain($meta) : 'sales';

    return reportEngineBuildCoverHtml($domain, $meta);
}

function salesReportsFindDivEnd(string $html, int $start): ?int
{
    $length = strlen($html);
    $depth = 0;
    $i = $start;
    while ($i < $length && preg_match('/<\/?div\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $i)) {
        $tag = $match[0][0];
        $at = $match[0][1];
        $i = $at + strlen($tag);
        if (preg_match('/^<div\b/i', $tag)) {
            $depth++;
            continue;
        }
        $depth--;
        if ($depth === 0) {
            return $i;
        }
    }

    return null;
}

function salesReportsRemoveCoverBlocks(string $html): string
{
    $offset = 0;
    while (($pos = stripos($html, 'sr-cover-page', $offset)) !== false) {
        $start = strripos(substr($html, 0, $pos), '<div');
        if ($start === false) {
            break;
        }
        $end = salesReportsFindDivEnd($html, $start);
        if ($end === null) {
            break;
        }
        $html = substr($html, 0, $start) . substr($html, $end);
        $offset = $start;
    }

    return $html;
}

function salesReportsEnsureCoverHtml(array $meta, string $html): string
{
    $cover = salesReportsBuildDepartmentCoverHtml($meta);

    return $cover . ltrim(salesReportsRemoveCoverBlocks($html));
}

function salesReportsApplyCoverToSections(array $meta, array $sections): array
{
    $coverHtml = salesReportsBuildDepartmentCoverHtml($meta);
    $updated = false;
    foreach ($sections as &$section) {
        if (($section['key'] ?? '') === 'cover') {
            $section['content'] = $coverHtml;
            $section['visible'] = true;
            $updated = true;
            break;
        }
    }
    unset($section);
    if (!$updated) {
        array_unshift($sections, [
            'id' => 'cover',
            'key' => 'cover',
            'title' => 'Cover Page',
            'order' => 0,
            'visible' => true,
            'content' => $coverHtml,
        ]);
    }

    return $sections;
}

function salesReportsRefreshCoverInHtml(string $html, array $meta): string
{
    if (!str_contains($html, 'sr-cover-page')) {
        return $html;
    }

    return salesReportsEnsureCoverHtml($meta, $html);
}

function salesReportsSectionHeading(string $title): string
{
    return '<h2 style="text-transform:uppercase; letter-spacing:0.04em; font-size:13pt; margin-top:28px;">'
        . htmlspecialchars(strtoupper($title), ENT_QUOTES, 'UTF-8') . '</h2>';
}

function salesReportsPreviousPeriodFilters(array $filters): array
{
    $start = strtotime((string) ($filters['start_date'] ?? ''));
    $end = strtotime((string) ($filters['end_date'] ?? ''));
    if (!$start || !$end || $end < $start) {
        return $filters;
    }
    $days = (int) (($end - $start) / 86400) + 1;
    $prevEnd = strtotime('-1 day', $start);
    $prevStart = strtotime('-' . ($days - 1) . ' days', $prevEnd);

    return [
        'start_date' => date('Y-m-d', $prevStart),
        'end_date' => date('Y-m-d', $prevEnd),
    ];
}

function salesReportsPeriodPairLabels(array $current, array $previous): array
{
    $cur = salesReportsFormatCoverPeriod($current['start_date'] ?? '', $current['end_date'] ?? '');
    $prev = salesReportsFormatCoverPeriod($previous['start_date'] ?? '', $previous['end_date'] ?? '');

    return [
        'current' => $cur !== '' ? $cur : 'Current',
        'previous' => $prev !== '' ? $prev : 'Previous',
    ];
}

function salesReportsCurrentQuarterDates(): array
{
    $month = (int) date('n');
    $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3 + 1);
    $start = date('Y-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
    $endMonth = $quarterStartMonth + 2;
    $end = date('Y-m-t', strtotime(date('Y') . '-' . str_pad((string) $endMonth, 2, '0', STR_PAD_LEFT) . '-01'));

    return ['start_date' => $start, 'end_date' => $end];
}

function salesReportsCurrentMonthDates(): array
{
    return [
        'start_date' => date('Y-m-01'),
        'end_date' => date('Y-m-t'),
    ];
}

function salesReportsCurrentYearDates(): array
{
    return [
        'start_date' => date('Y-01-01'),
        'end_date' => date('Y-12-31'),
    ];
}

/**
 * @return array{report_name:string,report_type:string,template_key:string,start_date:string,end_date:string,period_label:string}
 */
function salesReportsPeriodDefaults(string $period, array $user = [], ?string $startDate = null, ?string $endDate = null): array
{
    $period = strtolower(trim($period));
    if ($period === 'monthly') {
        $templateKey = 'monthly';
        $reportType = 'monthly';
        if ($startDate && $endDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
            && strtotime($endDate) >= strtotime($startDate)) {
            $dates = ['start_date' => $startDate, 'end_date' => $endDate];
        } else {
            $dates = salesReportsCurrentMonthDates();
        }
    } elseif ($period === 'annual') {
        $dates = salesReportsCurrentYearDates();
        $templateKey = 'annual';
        $reportType = 'annual';
    } else {
        $period = 'quarterly';
        if ($startDate && $endDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
            && strtotime($endDate) >= strtotime($startDate)) {
            $dates = ['start_date' => $startDate, 'end_date' => $endDate];
        } else {
            $dates = salesReportsCurrentQuarterDates();
        }
        $templateKey = 'department_quarterly';
        $reportType = 'quarterly';
    }

    $periodLabel = salesReportsFormatCoverPeriod($dates['start_date'], $dates['end_date']);
    $year = salesReportsFormatCoverYear($dates['start_date'], $dates['end_date']);

    return [
        'report_name' => trim($periodLabel . ' Sales Report ' . $year),
        'report_type' => $reportType,
        'template_key' => $templateKey,
        'start_date' => $dates['start_date'],
        'end_date' => $dates['end_date'],
        'period_label' => $periodLabel,
        'prepared_by' => '',
        'department' => 'Sales',
    ];
}

function salesReportsPeriodOptions(array $user = []): array
{
    $options = [
        [
            'key' => 'monthly',
            'label' => 'Monthly Report',
            'description' => 'Pick your own start and end dates for the monthly report.',
            'icon' => 'bi-calendar-month',
        ],
        [
            'key' => 'quarterly',
            'label' => 'Quarterly Report',
            'description' => 'Choose which quarter this department sales report should cover.',
            'icon' => 'bi-calendar3',
        ],
        [
            'key' => 'annual',
            'label' => 'Annual Report',
            'description' => 'Full-year sales summary for the current calendar year.',
            'icon' => 'bi-calendar4-range',
        ],
    ];

    foreach ($options as &$option) {
        $defaults = salesReportsPeriodDefaults((string) $option['key'], $user);
        $option['period_label'] = $defaults['period_label'];
        $option['date_range'] = salesReportsFormatPeriod($defaults['start_date'], $defaults['end_date']);
        $option['defaults'] = $defaults;
    }
    unset($option);

    return $options;
}

function salesReportsVarianceTrend(float $variancePct): string
{
    if ($variancePct >= 50) {
        return 'Significant Increase';
    }
    if ($variancePct > 5) {
        return 'Increase';
    }
    if ($variancePct < -5) {
        return 'Decrease';
    }

    return 'Stable';
}

function salesReportsTplKeyAchievements(): string
{
    return '<ul>'
        . '<li>Retained major clients</li>'
        . '<li>Increased engagement</li>'
        . '<li>Secured repeat orders</li>'
        . '<li>Improved coordination</li>'
        . '</ul>';
}

function salesReportsTplChallenges(): string
{
    return '<ul>'
        . '<li><strong>Delayed Customer Order Delivery</strong> &mdash; The department experienced delays in customer order fulfillment due to miscommunication and misunderstandings between internal teams, suppliers, and customers regarding order delivery timelines, and stock availability. These delays occasionally affected customer satisfaction, order completion efficiency, and overall service delivery.</li>'
        . '<li><strong>Delay in Customer Quotation Approval</strong> &mdash; Slow approval and decision-making by customers delayed order confirmations, affecting sales conversion and overall revenue growth.</li>'
        . '<li><strong>High Market Competition</strong> &mdash; The sales department faced intense competition from other suppliers within the PPE and general supplies market. Competitors offering lower prices, flexible payment terms, and faster delivery timelines created pricing pressure and reduced the company&rsquo;s ability to convert some potential orders into confirmed sales.</li>'
        . '</ul>';
}

function salesReportsTplActionPlan(): string
{
    return '<ul>'
        . '<li><strong>Increase Client Visits</strong> &mdash; Conduct more regular customer visits to strengthen relationships, identify new opportunities, and improve business engagement.</li>'
        . '<li><strong>Improve Follow-ups</strong> &mdash; Strengthen follow-up on quotations, pending orders, and customer feedback to increase conversion rates and improve service delivery.</li>'
        . '<li><strong>Strengthen Pricing Strategy</strong> &mdash; Improve pricing competitiveness through better supplier negotiations and market analysis while maintaining profitability.</li>'
        . '<li><strong>Maintain Adequate Stock</strong> &mdash; Ensure fast-moving PPE and general supply items remain available to minimize delays in order fulfillment.</li>'
        . '<li><strong>Enhance Customer Retention</strong> &mdash; Focus on maintaining strong relationships with existing clients to encourage repeat business and long-term partnerships.</li>'
        . '<li><strong>Strengthen KPI Monitoring and Performance Review</strong> &mdash; Conduct regular weekly or monthly reviews of individual sales performance against existing targets to identify gaps early and implement corrective actions promptly.</li>'
        . '<li><strong>Strengthen Supplier Coordination</strong> &mdash; Improve communication and follow-up with suppliers to ensure timely order processing, shipment tracking, and delivery to minimize supply delays.</li>'
        . '<li><strong>Enhance Pre-Production Quality Confirmation</strong> &mdash; Ensure product samples, material specifications, and client approvals are confirmed before mass production to reduce the risk of quality-related disputes and order interruptions.</li>'
        . '<li><strong>Improve Order Requirement Verification</strong> &mdash; Establish a stricter order confirmation process to ensure all critical customer requirements, including sizes, specifications, and delivery expectations, are received before production begins.</li>'
        . '</ul>';
}
