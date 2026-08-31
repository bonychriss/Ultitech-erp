<?php
/**
 * Sales Report - AI narrative generation (department quarterly format).
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-data.php';

function salesReportsAiSectionInstruction(string $section): string
{
    return match ($section) {
        'executive_summary' => 'Write 2-3 paragraphs like a formal department quarterly report. Include team size, individual targets if available, combined departmental target, total sales, and percentage achievement. Use exact figures only.',
        'individual_sales_performance' => 'Write 1-2 paragraphs introducing the period comparison table. Mention overall departmental growth or decline between the previous and current periods using exact figures.',
        'quotation_analysis' => 'Write 1-2 paragraphs summarizing team quotation submission and conversion performance, then 1 paragraph analyzing individual strengths using the quotation data.',
        'top_client_contribution' => 'Write a short intro sentence then expand on the top clients listed. Mention repeat business and strategic relationships. Use bullet list format for client highlights.',
        'key_achievements' => 'Write a bullet list (ul/li) of 4-6 key achievements for the sales department this period. Use exact figures where relevant.',
        'challenges' => 'Write a bullet list (ul/li) of 3-5 challenges faced (delivery delays, competition, approval delays, etc.). Be specific but do not invent order details not in the data.',
        'delayed_revenue' => 'Write 1-2 paragraphs about revenue delayed or outstanding, referencing the delayed orders data. Mention combined value if available.',
        'action_plan' => 'Write a bullet list (ul/li) of 6-9 actionable recommendations for the next period (client visits, follow-ups, stock, KPI monitoring, supplier coordination, etc.).',
        'conclusion' => 'Write 1-2 closing paragraphs summarizing performance, challenges, and outlook for the next quarter. Include exact total sales and achievement percentage.',
        default => 'Write 2-4 paragraphs of formal business narrative using exact figures from the snapshot.',
    };
}

function salesReportsGenerateAiText(PDO $pdo, array $report, string $section, string $instruction = ''): array
{
    require_once __DIR__ . '/report-engine.php';
    if (reportEngineReportDomain($report) !== 'sales') {
        require_once __DIR__ . '/report-domain-ai.php';

        return reportEngineGenerateAiText($pdo, $report, $section, $instruction);
    }

    $snapshot = salesReportsAiSnapshot($report, $pdo);
    $rulesText = salesReportsAiRulesFallback($section, $snapshot);

    $aiPath = dirname(__DIR__, 3) . '/includes/ai_helpers.php';
    if (!is_file($aiPath)) {
        return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
    }
    require_once $aiPath;

    if (!function_exists('ai_fetch_settings_row') || !function_exists('ai_openai_request')) {
        return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
    }

    try {
        $settings = ai_fetch_settings_row();
        if (!$settings || !(int) ($settings['is_enabled'] ?? 0)) {
            return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
        }

        $sectionLabel = salesReportsSectionCatalog()[$section] ?? ucfirst(str_replace('_', ' ', $section));
        $dataJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $sectionGuide = salesReportsAiSectionInstruction($section);

        $system = 'You are a professional business report writer for a Sales and Marketing department quarterly report. '
            . 'Match the tone and structure of formal management sales reports. '
            . 'CRITICAL: Use ONLY numeric figures from the data snapshot. Do NOT invent numbers, clients, or orders. '
            . 'This is a department-level group report covering all sales personnel. '
            . 'Return plain HTML only (p, ul, li tags). No markdown, no code blocks, no section headings.';

        $user = "Write content for the \"{$sectionLabel}\" section.\n\n"
            . "Report period: {$snapshot['cover_period']} {$snapshot['period']}\n"
            . "Department: {$snapshot['department']}\n"
            . "Prepared by: {$snapshot['prepared_by']}\n"
            . "Currency: {$snapshot['currency']}\n\n"
            . "Section guidance: {$sectionGuide}\n\n"
            . "ERP Data Snapshot:\n{$dataJson}\n\n";
        if ($instruction !== '') {
            $user .= "Additional instruction: {$instruction}\n\n";
        }

        $result = ai_openai_request([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        $text = trim((string) ($result['content'] ?? ''));
        if ($text === '') {
            return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
        }

        $text = preg_replace('/^```(?:html)?\s*|\s*```$/s', '', $text) ?? $text;

        return ['success' => true, 'source' => 'ai', 'text' => $text];
    } catch (Throwable $e) {
        error_log('salesReportsGenerateAiText: ' . $e->getMessage());
        return ['success' => true, 'source' => 'rules', 'text' => $rulesText];
    }
}

function salesReportsAiRulesFallback(string $section, array $snapshot): string
{
    $s = $snapshot['summary'] ?? [];
    $t = $snapshot['team'] ?? [];
    $cur = htmlspecialchars($snapshot['currency'] ?? 'TZS');
    $period = htmlspecialchars($snapshot['period'] ?? '');
    $coverPeriod = htmlspecialchars($snapshot['cover_period'] ?? '');
    $total = number_format((float) ($s['total_revenue'] ?? 0), 0, '.', ',');
    $teamActual = number_format((float) ($t['team_actual'] ?? 0), 0, '.', ',');
    $teamTarget = number_format((float) ($t['team_target'] ?? 0), 0, '.', ',');
    $repCount = (int) ($t['rep_count'] ?? 0);
    $ach = $t['achievement_pct'] !== null ? number_format((float) $t['achievement_pct'], 2) . '%' : 'N/A';
    $growth = $snapshot['previous_period']['growth_pct'] ?? null;
    $growthText = $growth !== null ? number_format((float) $growth, 2) . '%' : 'N/A';

    return match ($section) {
        'executive_summary' => "<p>This report presents the sales performance of the Sales Department for {$coverPeriod}. "
            . "The department consists of {$repCount} sales personnel with a combined departmental target of {$cur} {$teamTarget}. "
            . "During the reporting period, the department generated total sales of {$cur} {$teamActual}, achieving {$ach} of the target.</p>",
        'individual_sales_performance' => "<p>A comparative analysis between the previous and current reporting periods shows "
            . "overall departmental growth of {$growthText}, with individual performance varying by customer order cycles and market conditions.</p>",
        'quotation_analysis' => '<p>Quotation activity and conversion rates for the period are summarized in the table below. '
            . 'Review follow-up practices to improve closure rates where conversion is below target.</p>',
        'top_client_contribution' => '<p>Key repeat clients during this period contributed significantly to departmental revenue. '
            . 'See the client list below for major accounts.</p>',
        'key_achievements' => '<ul><li>Retained major clients during the period</li><li>Maintained active customer engagement</li>'
            . '<li>Secured repeat orders from key accounts</li><li>Improved internal coordination on sales fulfillment</li></ul>',
        'challenges' => '<ul><li>Delayed customer order delivery affecting service timelines</li>'
            . '<li>Slow customer quotation approval impacting conversion</li>'
            . '<li>High market competition on pricing and delivery terms</li></ul>',
        'delayed_revenue' => '<p>Some orders with significant revenue potential were not fully completed within the period due to operational and coordination challenges, impacting overall quarterly performance.</p>',
        'action_plan' => '<ul><li>Increase regular client visits and relationship building</li>'
            . '<li>Strengthen follow-up on quotations and pending orders</li>'
            . '<li>Improve pricing competitiveness while maintaining profitability</li>'
            . '<li>Maintain adequate stock for fast-moving items</li>'
            . '<li>Conduct regular KPI reviews against sales targets</li></ul>',
        'conclusion' => "<p>The Sales Department recorded performance during the {$coverPeriod} period, achieving {$cur} {$teamActual} ({$ach} of target). "
            . 'With improved communication, stronger follow-up, and continued teamwork, the department is positioned for better results in the next period.</p>',
        default => "<p>During {$period}, total department sales reached {$cur} {$total}. Team achievement stands at {$ach} against a target of {$cur} {$teamTarget}.</p>",
    };
}

function salesReportsGenerateFullReport(PDO $pdo, array $report): array
{
    $sections = salesReportsDepartmentSectionKeys();
    $output = [];
    foreach ($sections as $sec) {
        if ($sec === 'cover' || $sec === 'salesperson_appendix') {
            continue;
        }
        $output[$sec] = salesReportsGenerateAiText($pdo, $report, $sec);
    }
    return $output;
}
