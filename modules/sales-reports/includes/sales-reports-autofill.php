<?php
/**
 * Sales Report  AI + ERP autofill from live system data (group-wise team report).
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-data.php';
require_once __DIR__ . '/sales-reports-prose-templates.php';
require_once __DIR__ . '/sales-reports-ai.php';

function salesReportsSectionAutofillMap(): array
{
    $tpl = static fn() => ['template' => true];

    return [
        'cover' => ['type' => 'cover'],
        'executive_summary' => $tpl(),
        'individual_sales_performance' => $tpl(),
        'quotation_analysis' => $tpl(),
        'top_client_contribution' => $tpl(),
        'key_achievements' => $tpl(),
        'challenges' => $tpl(),
        'delayed_revenue' => $tpl(),
        'action_plan' => $tpl(),
        'conclusion' => $tpl(),
        'salesperson_appendix' => $tpl(),
        'sales_overview' => ['ai' => true, 'erp' => ['sales_summary', 'sales_trend']],
        'sales_performance' => ['ai' => true, 'erp' => ['team_performance', 'chart_team_performance']],
        'sales_by_customer' => ['erp' => ['sales_by_customer', 'chart_by_customer']],
        'sales_by_product' => ['erp' => ['sales_by_product', 'chart_by_product']],
        'sales_by_category' => ['erp' => ['sales_by_category']],
        'salesperson_performance' => ['ai' => true, 'erp' => ['team_performance']],
        'payment_analysis' => ['ai' => true, 'erp' => ['payment_analysis']],
        'outstanding_invoices' => ['erp' => ['outstanding_invoices']],
        'sales_returns' => ['ai' => true],
        'discount_analysis' => ['erp' => ['discounts']],
        'profitability' => ['ai' => true, 'erp' => ['profitability']],
        'management_comments' => ['ai' => true],
    ];
}

function salesReportsDocumentNeedsAutofill(?array $doc, array $sections): bool
{
    $merged = salesReportsUiMergeDocumentHtml($doc, $sections);
    if ($merged === '') {
        return true;
    }

    $needles = [
        '[period]',
        '[quotations]',
        '[amount]',
        'Salesperson monthly invoice breakdown will be inserted',
        'This report presents the sales performance of the Sales Department for [period]',
    ];
    foreach ($needles as $needle) {
        if (stripos($merged, $needle) !== false) {
            return true;
        }
    }
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($merged)));
    if ($plain === '' || strlen($plain) < 80) {
        return true;
    }
    if ($doc && !empty($doc['autofilled_at'])) {
        return false;
    }

    return false;
}

function salesReportsErpBlockHtml(string $source, string $innerHtml, string $mode = 'snapshot'): string
{
    if ($mode === 'snapshot') {
        return (string) $innerHtml;
    }

    return '<div class="sr-erp-block" data-erp-source="' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8')
        . '" data-erp-mode="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8')
        . '" contenteditable="false">' . $innerHtml . '</div>';
}

function salesReportsBuildCoverSection(array $report): string
{
    return salesReportsBuildDepartmentCoverHtml($report);
}

function salesReportsAutofillSections(PDO $pdo, array $report, array $sections): array
{
    $filters = salesReportsFiltersFromReport($report);
    $catalog = salesReportsSectionCatalog();
    $map = salesReportsSectionAutofillMap();
    $filled = [];

    foreach ($sections as $section) {
        if (empty($section['visible'])) {
            $filled[] = $section;
            continue;
        }

        $key = (string) ($section['key'] ?? '');
        $cfg = $map[$key] ?? ['ai' => true];
        $title = $catalog[$key] ?? (string) ($section['title'] ?? ucfirst($key));
        $parts = [];

        if (($cfg['type'] ?? '') === 'cover') {
            $parts[] = salesReportsBuildCoverSection($report);
        } elseif (!empty($cfg['template'])) {
            if ($key !== 'salesperson_appendix' && ($catalog[$key] ?? '') !== '') {
                $parts[] = salesReportsSectionHeading($catalog[$key] ?? $title);
            }
            $parts[] = salesReportsRenderProseTemplate($pdo, $report, $key);
        } else {
            if (($catalog[$key] ?? '') !== '') {
                $parts[] = salesReportsSectionHeading($catalog[$key] ?? $title);
            }

            if (!empty($cfg['ai'])) {
                $ai = salesReportsGenerateAiText($pdo, $report, $key);
                $parts[] = (string) ($ai['text'] ?? '');
            }

            foreach ($cfg['erp'] ?? [] as $source) {
                try {
                    $data = salesReportsFetchErpData($pdo, $source, $filters);
                    $parts[] = salesReportsErpBlockHtml($source, (string) ($data['html'] ?? ''));
                } catch (Throwable $e) {
                    error_log('salesReportsAutofillSections erp ' . $source . ': ' . $e->getMessage());
                }
            }

            if (empty($cfg['ai']) && empty($cfg['erp'])) {
                $ai = salesReportsGenerateAiText($pdo, $report, $key);
                $parts[] = (string) ($ai['text'] ?? '<p></p>');
            }
        }

        $filled[] = array_merge($section, [
            'content' => implode("\n", array_filter($parts)),
        ]);
    }

    return $filled;
}

function salesReportsApplyAutofill(PDO $pdo, int $reportId, bool $force = false): array
{
    $report = salesReportsGet($pdo, $reportId);
    if ($report) {
        require_once __DIR__ . '/report-engine.php';
        if (reportEngineReportDomain($report) !== 'sales') {
            require_once __DIR__ . '/report-domain-autofill.php';

            return reportEngineApplyAutofill($pdo, $reportId, $force);
        }
    }

    $report = salesReportsGet($pdo, $reportId);
    if (!$report) {
        return ['success' => false, 'error' => 'Report not found'];
    }

    $doc = salesReportsGetDocument($pdo, $reportId);
    $sections = json_decode((string) ($doc['sections_json'] ?? '[]'), true) ?: [];

    if ($sections === []) {
        $sections = salesReportsBuildInitialSections($pdo, $reportId, salesReportsDefaultSectionKeys(), $report);
    }

    if (!$force && !salesReportsDocumentNeedsAutofill($doc, $sections)) {
        $contentHtml = salesReportsUiMergeDocumentHtml($doc, $sections);
        return [
            'success' => true,
            'skipped' => true,
            'content_html' => $contentHtml,
            'sections' => $sections,
        ];
    }

    $filled = salesReportsAutofillSections($pdo, $report, $sections);
    $contentHtml = salesReportsRenderSectionsHtml($filled, $report);
    $sectionsJson = salesReportsJsonEncode($filled);

    $userId = salesReportsUserId();
    if ($doc) {
        $pdo->prepare("UPDATE sales_report_documents SET content = ?, content_html = ?, sections_json = ?, updated_by = ?, autofilled_at = NOW() WHERE report_id = ?")
            ->execute([$sectionsJson, $contentHtml, $sectionsJson, $userId ?: null, $reportId]);
    } else {
        $pdo->prepare("INSERT INTO sales_report_documents (report_id, content, content_html, sections_json, version, created_by, updated_by, autofilled_at) VALUES (?,?,?,?,1,?,?,NOW())")
            ->execute([$reportId, $sectionsJson, $contentHtml, $sectionsJson, $userId ?: null, $userId ?: null]);
    }

    salesReportsEnsureAutofilledColumn($pdo);

    return [
        'success' => true,
        'skipped' => false,
        'content_html' => $contentHtml,
        'sections' => $filled,
        'message' => 'Report autofilled with PDF template text and live ERP data.',
    ];
}

// Fallback only when ui-lib.php was not loaded first.
if (!function_exists('salesReportsUiMergeDocumentHtml')) {
    require_once __DIR__ . '/ui-lib.php';
}
