<?php

declare(strict_types=1);

$errorHelpers = dirname(__DIR__, 3) . '/includes/error_page_helpers.php';
if (is_file($errorHelpers)) {
    require_once $errorHelpers;
}

function salesReportsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/modules/sales-reports'), '/') . '/' . $relativePath;
    }
    return '/modules/sales-reports/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function salesReportsUiLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => salesReportsUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function salesReportsFontStylesheetTag(): string
{
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">';
}

function salesReportsUiAnimationUrl(string $filename): string
{
    $path = '/assets/animations/' . rawurlencode($filename);
    if (function_exists('app_url')) {
        return app_url($path);
    }

    return '../../assets/animations/' . rawurlencode($filename);
}

function salesReportsUiDownloadAnimationUrl(): string
{
    $path = '/loading/Book Loader.lottie';
    if (function_exists('app_url')) {
        return app_url($path);
    }

    return '../../loading/Book Loader.lottie';
}

function salesReportsUiBuildConfig(PDO $pdo, array $filters = []): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $statusFilter = trim((string) ($filters['status'] ?? ''));
    $reports = salesReportsList($pdo, ['search' => $search, 'status' => $statusFilter]);

    require_once __DIR__ . '/report-engine.php';

    $formattedReports = array_map(static function (array $r): array {
        $domain = reportEngineNormalizeDomain($r['report_domain'] ?? 'sales');
        $domainMeta = reportEngineDomains()[$domain] ?? null;

        return [
            'id' => (int) $r['id'],
            'report_name' => (string) $r['report_name'],
            'report_domain' => $domain,
            'domain_label' => $domainMeta['label'] ?? 'Sales Report',
            'domain_color' => $domainMeta['color'] ?? '#6366f1',
            'start_date' => (string) $r['start_date'],
            'end_date' => (string) $r['end_date'],
            'status' => (string) $r['status'],
            'status_label' => salesReportsFormatStatus((string) $r['status']),
            'creator_name' => (string) ($r['creator_name'] ?? 'Unknown'),
            'updated_at' => (string) $r['updated_at'],
            'updated_label' => date('d M Y', strtotime((string) $r['updated_at'])),
            'period_label' => salesReportsFormatPeriod((string) $r['start_date'], (string) $r['end_date']),
            'can_edit' => salesReportsCanEditReport($r),
        ];
    }, $reports);

    $statusOptions = array_map(static fn($s) => [
        'value' => $s,
        'label' => salesReportsFormatStatus($s),
    ], salesReportsStatusOptions());

    return [
        'reports' => $formattedReports,
        'filters' => [
            'search' => $search,
            'status' => $statusFilter,
        ],
        'permissions' => [
            'create' => salesReportsCan('create'),
            'delete' => salesReportsCan('delete'),
            'duplicate' => salesReportsCan('create'),
            'export' => salesReportsCan('export'),
        ],
        'statusOptions' => $statusOptions,
        'urls' => [
            'apiBase' => salesReportsUiPublicUrl('api/'),
            'create' => salesReportsUrl('editor.php', ['new' => '1']),
            'analytics' => function_exists('app_url')
                ? app_url('/modules/analytics/index.php?module=analytics')
                : '../analytics/index.php?module=analytics',
            'editor' => salesReportsUrl('editor.php'),
            'export' => salesReportsUrl('api/export.php'),
            'emptyLottie' => salesReportsUiAnimationUrl('nothing.lottie'),
            'errorLottie' => function_exists('error404_lottie_url')
                ? error404_lottie_url()
                : salesReportsUiAnimationUrl('404 Error page not found.lottie'),
            'downloadLottie' => salesReportsUiDownloadAnimationUrl(),
        ],
        'module' => 'analytics',
        'reportDomains' => array_values(reportEngineDomains()),
        'reportPeriodOptions' => salesReportsPeriodOptions([
            'name' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
            'department' => (string) ($_SESSION['department'] ?? 'Sales'),
        ]),
        'businessReportOptions' => reportEngineDomainPeriodOptions([
            'name' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
            'department' => (string) ($_SESSION['department'] ?? ''),
        ]),
    ];
}

function salesReportsUiHtmlFromSections(array $sections): string
{
    usort($sections, static fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    $parts = [];
    foreach ($sections as $sec) {
        if (empty($sec['visible'])) {
            continue;
        }
        $part = trim((string) ($sec['content'] ?? ''));
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return implode("\n", $parts);
}

function salesReportsUiRepairEditorHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (str_contains($html, '&lt;div') || str_contains($html, '&lt;section') || str_contains($html, '&lt;h2')) {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/^<pre[^>]*>(.*)<\/pre>$/is', $html, $m)) {
        $html = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return trim($html);
}

function salesReportsUiIsBrokenEditorHtml(string $html, string $fromSections): bool
{
    $html = trim($html);
    if ($html === '') {
        return true;
    }
    if (str_contains($html, '&lt;div') || str_contains($html, '&lt;section')) {
        return true;
    }
    if (preg_match('/^<div[^>]+style="[^"]*$/i', $html)) {
        return true;
    }
    if (str_contains($html, 'sr-cover-page') && !str_contains($html, '</div>') && strlen($html) < 500) {
        return true;
    }

    if ($fromSections === '') {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if (strlen($plain) < 80) {
            return true;
        }

        return false;
    }

    $sectionHeadings = substr_count(strtolower($fromSections), '<h2');
    $htmlHeadings = substr_count(strtolower($html), '<h2');
    if ($sectionHeadings >= 2 && $htmlHeadings === 0) {
        return true;
    }
    if (strlen($fromSections) > strlen($html) * 1.35 && $sectionHeadings > $htmlHeadings) {
        return true;
    }

    return false;
}

function salesReportsUiMergeDocumentHtml(?array $doc, array $sections): string
{
    $fromSections = salesReportsUiHtmlFromSections($sections);
    $html = salesReportsUiRepairEditorHtml(trim((string) ($doc['content_html'] ?? '')));

    if ($html === '') {
        return $fromSections;
    }
    if (salesReportsUiIsBrokenEditorHtml($html, $fromSections)) {
        return $fromSections !== '' ? $fromSections : $html;
    }

    return $html;
}

function salesReportsUiJsonFlags(): int
{
    return JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
}

/**
 * Lightweight editor bootstrap � document HTML is loaded via editor-init.php API.
 */
function salesReportsUiBuildEditorShellConfig(PDO $pdo, int $reportId): ?array
{
    $full = salesReportsUiBuildEditorConfig($pdo, $reportId);
    if (!$full) {
        return null;
    }

    return [
        'mode' => 'editor',
        'isNew' => false,
        'module' => 'analytics',
        'reportId' => $reportId,
        'loadDocumentViaApi' => true,
        'report' => $full['report'],
        'document' => [
            'needs_autofill' => (bool) ($full['document']['needs_autofill'] ?? false),
        ],
        'urls' => $full['urls'],
        'permissions' => $full['permissions'],
    ];
}

function salesReportsUiBuildEditorConfig(PDO $pdo, int $reportId): ?array
{
    require_once __DIR__ . '/sales-reports-data.php';
    require_once __DIR__ . '/sales-reports-autofill.php';
    require_once __DIR__ . '/report-engine.php';
    require_once __DIR__ . '/report-domain-data.php';

    $report = salesReportsGet($pdo, $reportId);
    if (!$report) {
        return null;
    }

    $domain = reportEngineReportDomain($report);
    $doc = salesReportsGetDocument($pdo, $reportId);
    $sections = json_decode((string) ($doc['sections_json'] ?? '[]'), true) ?: [];
    $contentHtml = salesReportsUiMergeDocumentHtml($doc, $sections);

    $erpMenu = reportEngineErpMenu($domain);
    $sectionCatalog = reportEngineSectionCatalog($domain);

    return [
        'mode' => 'editor',
        'report' => [
            'id' => (int) $report['id'],
            'report_name' => (string) $report['report_name'],
            'report_type' => (string) $report['report_type'],
            'report_domain' => $domain,
            'domain_label' => reportEngineDomainLabel($domain),
            'start_date' => (string) $report['start_date'],
            'end_date' => (string) $report['end_date'],
            'prepared_by' => (string) ($report['prepared_by'] ?? ''),
            'department' => (string) ($report['department'] ?? ''),
            'branch' => (string) ($report['branch'] ?? ''),
            'status' => (string) $report['status'],
            'status_label' => salesReportsFormatStatus((string) $report['status']),
            'description' => (string) ($report['description'] ?? ''),
            'period_label' => salesReportsFormatPeriod((string) $report['start_date'], (string) $report['end_date']),
            'can_edit' => salesReportsCanEditReport($report),
        ],
        'document' => [
            'content_html' => $contentHtml,
            'sections' => $sections,
            'version' => (int) ($doc['version'] ?? $report['current_version'] ?? 1),
            'needs_autofill' => salesReportsDocumentNeedsAutofill($doc, $sections),
            'autofilled_at' => $doc['autofilled_at'] ?? null,
        ],
        'erpMenu' => $erpMenu,
        'sectionCatalog' => $sectionCatalog,
        'statusOptions' => array_map(static fn($s) => [
            'value' => $s,
            'label' => salesReportsFormatStatus($s),
        ], salesReportsStatusOptions()),
        'templates' => array_map(static fn($t) => $t['label'], salesReportsTemplates()),
        'permissions' => [
            'edit' => salesReportsCanEditReport($report),
            'delete' => salesReportsCan('delete'),
            'duplicate' => salesReportsCan('create'),
            'export' => salesReportsCan('export'),
            'restore_version' => salesReportsCan('restore_version'),
        ],
        'urls' => [
            'apiBase' => salesReportsUiPublicUrl('api/'),
            'list' => salesReportsUrl('index.php'),
            'editor' => salesReportsUrl('editor.php'),
            'export' => salesReportsUrl('api/export.php'),
            'errorLottie' => function_exists('error404_lottie_url')
                ? error404_lottie_url()
                : salesReportsUiAnimationUrl('404 Error page not found.lottie'),
            'downloadLottie' => salesReportsUiDownloadAnimationUrl(),
        ],
        'module' => 'analytics',
        'user' => [
            'name' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
            'department' => (string) ($_SESSION['department'] ?? ''),
        ],
    ];
}
