<?php
/**
 * Report Engine  ERP data dispatcher and shared render helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/sales-reports-lib.php';
require_once __DIR__ . '/report-engine.php';
require_once __DIR__ . '/report-domain-procurement.php';
require_once __DIR__ . '/report-domain-finance.php';
require_once __DIR__ . '/report-domain-fleet.php';
require_once __DIR__ . '/report-domain-store.php';

function reportEngineRefreshLiveBlocks(PDO $pdo, array $report, string $html): string
{
    $domain = reportEngineReportDomain($report);
    $filters = reportEngineFiltersFromReport($report);

    return preg_replace_callback(
        '/<div[^>]*class="[^"]*sr-erp-block[^"]*"[^>]*data-erp-source="([^"]+)"[^>]*data-erp-mode="live"[^>]*>.*?<\/div>/s',
        static function (array $m) use ($pdo, $domain, $filters) {
            $source = $m[1];
            $data = reportEngineFetchErpData($pdo, $domain, $source, $filters);
            return '<div class="sr-erp-block" data-erp-source="' . htmlspecialchars($source, ENT_QUOTES) . '" data-erp-mode="live" contenteditable="false">' . ($data['html'] ?? '') . '</div>';
        },
        $html
    ) ?? $html;
}

function reportEngineFetchErpData(PDO $pdo, string $domain, string $source, array $filters): array
{
    $domain = reportEngineNormalizeDomain($domain);
    if ($domain === 'sales') {
        return salesReportsFetchErpData($pdo, $source, $filters);
    }

    return match ($domain) {
        'procurement' => reportDomainProcurementFetch($pdo, $source, $filters),
        'finance' => reportDomainFinanceFetch($pdo, $source, $filters),
        'fleet' => reportDomainFleetFetch($pdo, $source, $filters),
        'store_warehouse' => reportDomainStoreFetch($pdo, $source, $filters),
        default => ['html' => '<p>Unknown data source.</p>', 'snapshot' => []],
    };
}

function reportEngineBuildSnapshot(PDO $pdo, array $report): array
{
    $domain = reportEngineReportDomain($report);
    $filters = reportEngineFiltersFromReport($report);

    if ($domain === 'sales') {
        return salesReportsAiSnapshot($report, $pdo);
    }

    $base = [
        'report_type' => $domain,
        'report_domain' => $domain,
        'period' => salesReportsFormatPeriod($report['start_date'], $report['end_date']),
        'cover_period' => salesReportsFormatCoverPeriod($report['start_date'], $report['end_date']),
        'report_name' => $report['report_name'] ?? '',
        'department' => $report['department'] ?? reportEngineDomains()[$domain]['department_default'] ?? '',
        'prepared_by' => $report['prepared_by'] ?? '',
        'currency' => salesReportsCurrency(),
        'filters' => $filters,
        'data_quality' => [],
    ];

    return match ($domain) {
        'procurement' => array_merge($base, reportDomainProcurementSnapshot($pdo, $filters)),
        'finance' => array_merge($base, reportDomainFinanceSnapshot($pdo, $filters)),
        'fleet' => array_merge($base, reportDomainFleetSnapshot($pdo, $filters)),
        'store_warehouse' => array_merge($base, reportDomainStoreSnapshot($pdo, $filters)),
        default => $base,
    };
}

function reportEngineErpMenu(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);
    if ($domain === 'sales') {
        return salesReportsErpMenu();
    }

    return match ($domain) {
        'procurement' => reportDomainProcurementErpMenu(),
        'finance' => reportDomainFinanceErpMenu(),
        'fleet' => reportDomainFleetErpMenu(),
        'store_warehouse' => reportDomainStoreErpMenu(),
        default => [],
    };
}

function reportEngineFilterDefinitions(string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);

    return match ($domain) {
        'procurement' => [],
        'finance' => [],
        'fleet' => [],
        'store_warehouse' => [
            ['key' => 'warehouse_id', 'label' => 'Warehouse', 'type' => 'select', 'options_source' => 'warehouses'],
            ['key' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options_source' => 'categories'],
            ['key' => 'stock_status', 'label' => 'Stock Status', 'type' => 'select', 'options' => [
                ['value' => '', 'label' => 'All items'],
                ['value' => 'low', 'label' => 'Low stock'],
                ['value' => 'out', 'label' => 'Out of stock'],
                ['value' => 'ok', 'label' => 'Adequate stock'],
            ]],
        ],
        default => [],
    };
}

function reportEngineFilterOptions(PDO $pdo, string $domain): array
{
    $domain = reportEngineNormalizeDomain($domain);
    $out = ['filters' => reportEngineFilterDefinitions($domain), 'options' => []];

    if ($domain === 'store_warehouse') {
        $out['options']['warehouses'] = reportDomainStoreWarehouseOptions($pdo);
        $out['options']['categories'] = reportDomainStoreCategoryOptions($pdo);
    }

    return $out;
}

function reportDomainEmployeeOptions(PDO $pdo): array
{
    if (!tableExists('users', $pdo)) {
        return [];
    }
    $sql = "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC LIMIT 200";
    $params = [];
    reportEngineAppendScope($sql, $params, 'users', $pdo);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static fn($r) => [
        'value' => (string) (int) $r['id'],
        'label' => (string) ($r['full_name'] ?? 'Employee'),
    ], $rows);
}

function reportEngineRenderKpiTable(array $rows, string $periodLabel = ''): string
{
    $html = '';
    if ($periodLabel !== '') {
        $html .= '<p><strong>Reporting Period:</strong> ' . htmlspecialchars($periodLabel) . '</p>';
    }
    $html .= '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;">';
    foreach ($rows as $row) {
        $html .= '<tr><td><strong>' . htmlspecialchars((string) ($row['label'] ?? '')) . '</strong></td>'
            . '<td>' . htmlspecialchars((string) ($row['value'] ?? '')) . '</td></tr>';
    }
    $html .= '</table>';

    return $html;
}

function reportEngineRenderDataTable(array $headers, array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No records for the selected period and filters.</p>';
    }
    $html = '<table class="sr-data-table" border="1" cellpadding="6" style="border-collapse:collapse;width:100%;"><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . htmlspecialchars($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    return $html;
}

function reportEngineMonthlyTrendTable(array $monthly, string $valueLabel = 'Amount'): string
{
    if ($monthly === []) {
        return '<p class="sr-muted">No monthly trend data for this period.</p>';
    }
    $rows = [];
    foreach ($monthly as $m) {
        $rows[] = [
            (string) ($m['label'] ?? $m['ym'] ?? ''),
            salesReportsFormatMoney((float) ($m['total'] ?? $m['value'] ?? 0)),
            number_format((int) ($m['count'] ?? 0)),
        ];
    }

    return reportEngineRenderDataTable(['Month', $valueLabel, 'Count'], $rows);
}

function reportEngineApplySqlFilters(string &$sql, array &$params, array $filters, array $map): void
{
    foreach ($map as $filterKey => $columnExpr) {
        $val = $filters[$filterKey] ?? '';
        if ($val === '' || $val === 0 || $val === '0') {
            continue;
        }
        if (is_int($columnExpr)) {
            continue;
        }
        $sql .= ' AND ' . $columnExpr . ' = ?';
        $params[] = $val;
    }
}

function reportEngineDataQualityNotes(array $notes): array
{
    return array_values(array_filter(array_map('strval', $notes)));
}
