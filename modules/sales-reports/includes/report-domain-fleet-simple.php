<?php
/**
 * Short rider report for Delivery and Logistics.
 * Layout follows the Q2 rider sample. Counts and amounts come from delivery orders.
 */

declare(strict_types=1);

function reportDomainFleetSimpleH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function reportDomainFleetSimplePeriodPhrase(array $report): string
{
    $start = strtotime((string) ($report['start_date'] ?? ''));
    $end = strtotime((string) ($report['end_date'] ?? ''));
    if (!$start || !$end) {
        return 'the selected period';
    }
    if (date('Y-m', $start) === date('Y-m', $end)) {
        return date('F Y', $end);
    }
    if (date('Y', $start) === date('Y', $end)) {
        return date('F', $start) . ' to ' . date('F Y', $end);
    }

    return date('F Y', $start) . ' to ' . date('F Y', $end);
}

function reportDomainFleetSimpleMonths(array $report): array
{
    $start = strtotime((string) ($report['start_date'] ?? ''));
    $end = strtotime((string) ($report['end_date'] ?? ''));
    if (!$start || !$end) {
        return [];
    }
    $cursor = strtotime(date('Y-m-01', $start));
    $last = strtotime(date('Y-m-01', $end));
    $months = [];
    while ($cursor !== false && $cursor <= $last) {
        $months[] = [
            'key' => date('Y-m', $cursor),
            'label' => date('F', $cursor),
            'routes' => 0,
            'amount' => 0.0,
        ];
        $cursor = strtotime('+1 month', $cursor);
    }

    return $months;
}

function reportDomainFleetSimplePdo(PDO $pdo): ?PDO
{
    if (tableExists('delivery_orders', $pdo)) {
        return $pdo;
    }
    $salesFile = dirname(__DIR__, 2) . '/sales/functions.php';
    if (is_file($salesFile)) {
        require_once $salesFile;
    }
    if (function_exists('sales_pdo')) {
        $sales = sales_pdo();
        if ($sales instanceof PDO && tableExists('delivery_orders', $sales)) {
            return $sales;
        }
    }

    return null;
}

function reportDomainFleetSimpleData(PDO $pdo, array $report): array
{
    $months = reportDomainFleetSimpleMonths($report);
    $riders = [];
    $db = reportDomainFleetSimplePdo($pdo);
    $start = (string) ($report['start_date'] ?? '');
    $end = (string) ($report['end_date'] ?? '');
    if (!$db || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        return ['months' => $months, 'riders' => [], 'ght_routes' => 0, 'ght_amount' => 0.0, 'ultimate_routes' => 0];
    }

    $done = "LOWER(TRIM(COALESCE(status,''))) IN ('delivered','completed','closed')";
    try {
        $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS routes, COALESCE(SUM(route_cost), 0) AS amount
                FROM delivery_orders
                WHERE DATE(created_at) BETWEEN ? AND ?
                  AND {$done}
                  AND LOWER(COALESCE(client_name, '')) LIKE '%ght%'
                GROUP BY ym";
        $st = $db->prepare($sql);
        $st->execute([$start, $end]);
        $byMonth = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byMonth[(string) $row['ym']] = $row;
        }
        foreach ($months as &$month) {
            if (!isset($byMonth[$month['key']])) {
                continue;
            }
            $month['routes'] = (int) $byMonth[$month['key']]['routes'];
            $month['amount'] = (float) $byMonth[$month['key']]['amount'];
        }
        unset($month);

        $nameSql = tableExists('users', $db)
            ? "COALESCE(NULLIF(TRIM(u.full_name), ''), 'Unassigned')"
            : "'Unassigned'";
        $join = tableExists('users', $db) ? 'LEFT JOIN users u ON u.id = o.requested_driver_id' : '';
        $sql = "SELECT {$nameSql} AS rider_name, COUNT(*) AS routes
                FROM delivery_orders o
                {$join}
                WHERE DATE(o.created_at) BETWEEN ? AND ?
                  AND LOWER(TRIM(COALESCE(o.status,''))) IN ('delivered','completed','closed')
                  AND LOWER(COALESCE(o.client_name, '')) NOT LIKE '%ght%'
                GROUP BY rider_name
                HAVING routes > 0
                ORDER BY routes DESC, rider_name ASC";
        $st = $db->prepare($sql);
        $st->execute([$start, $end]);
        $riders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('reportDomainFleetSimpleData: ' . $e->getMessage());
    }

    $ghtRoutes = 0;
    $ghtAmount = 0.0;
    foreach ($months as $month) {
        $ghtRoutes += (int) $month['routes'];
        $ghtAmount += (float) $month['amount'];
    }
    $ultimateRoutes = 0;
    foreach ($riders as $rider) {
        $ultimateRoutes += (int) ($rider['routes'] ?? 0);
    }

    return [
        'months' => $months,
        'riders' => $riders,
        'ght_routes' => $ghtRoutes,
        'ght_amount' => $ghtAmount,
        'ultimate_routes' => $ultimateRoutes,
    ];
}

function reportDomainFleetSimpleTable(array $headers, array $rows, array $rightColumns = []): string
{
    $html = '<table class="sr-data-table" border="1" cellpadding="5" style="border-collapse:collapse;width:100%;">'
        . '<thead><tr style="background:#4361ee;color:#fff;">';
    foreach ($headers as $header) {
        $html .= '<th>' . reportDomainFleetSimpleH($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
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

function reportDomainFleetSimpleBody(PDO $pdo, array $report, string $key): string
{
    $period = reportDomainFleetSimplePeriodPhrase($report);
    $data = reportDomainFleetSimpleData($pdo, $report);
    $ghtRoutes = (int) $data['ght_routes'];
    $ultimateRoutes = (int) $data['ultimate_routes'];
    $grand = $ghtRoutes + $ultimateRoutes;
    $ghtAmount = number_format((float) $data['ght_amount'], 0);

    return match ($key) {
        'fleet_introduction' => '<p>This report provides an overview of riders&rsquo; performance for the period from '
            . reportDomainFleetSimpleH($period)
            . '. The report evaluates delivery activities carried out under GHT and Ultimate General Trading Company Limited, highlighting completed routes, performance achievements, challenges encountered, and overall contribution to business operations.</p>'
            . '<p>During this period, riders played an important role in ensuring timely delivery of goods, documents, and other company-related items to clients and different destinations. Their work contributed to maintaining customer satisfaction and supporting daily operations.</p>',
        'fleet_performance' => reportDomainFleetSimplePerformanceHtml($data),
        'fleet_overall' => '<p>Combining both GHT and Ultimate operations:</p><ul>'
            . '<li>Total GHT Routes: ' . number_format($ghtRoutes) . '</li>'
            . '<li>Total Ultimate Routes: ' . number_format($ultimateRoutes) . '</li>'
            . '<li>Grand Total Routes Completed: ' . number_format($grand) . '</li>'
            . '</ul>'
            . '<p>The total number of completed routes reflects the delivery operations recorded for this period.</p>',
        'fleet_achievements' => '<p>During this period, the riders achieved the following:</p><ul>'
            . '<li>Successfully completed ' . number_format($grand) . ' delivery routes recorded across GHT and Ultimate operations.</li>'
            . '<li>Ensured timely delivery of goods and documents to clients and partners.</li>'
            . '<li>Supported smooth business operations by maintaining consistent delivery schedules.</li>'
            . '<li>Helped improve customer satisfaction through reliable and responsive service.</li>'
            . '</ul>',
        'fleet_challenges' => '<p>Despite the achievements, several challenges affected rider performance:</p><ul>'
            . '<li>Heavy traffic in some areas caused delays in reaching clients on time.</li>'
            . '<li>Some clients required urgent deliveries with limited preparation time.</li>'
            . '<li>Frequent small deliveries under GHT consumed considerable working hours.</li>'
            . '<li>Long-distance routes increased fuel consumption and delivery time.</li>'
            . '<li>Weather conditions occasionally affected movement and delivery schedules.</li>'
            . '</ul>',
        'fleet_conclusion' => '<p>In conclusion, the riders contributed to delivery operations during ' . reportDomainFleetSimpleH($period)
            . '. A total of ' . number_format($grand) . ' routes were completed in the system, consisting of '
            . number_format($ghtRoutes) . ' GHT routes'
            . ((float) $data['ght_amount'] > 0 ? ' amounting to TZS ' . $ghtAmount : '')
            . ' and ' . number_format($ultimateRoutes) . ' Ultimate routes.</p>'
            . '<p>Despite operational challenges such as traffic, urgent requests, and long-distance travel, continuous improvement in planning and coordination will further enhance productivity in the coming period.</p>',
        default => '<p></p>',
    };
}

function reportDomainFleetSimplePerformanceHtml(array $data): string
{
    $monthRows = [];
    $analysisRows = [];
    $prev = null;
    foreach ($data['months'] as $month) {
        $routes = (int) $month['routes'];
        $amount = (float) $month['amount'];
        $monthRows[] = [
            reportDomainFleetSimpleH((string) $month['label']),
            reportDomainFleetSimpleH(number_format($routes)),
            reportDomainFleetSimpleH(number_format($amount, 0)),
        ];
        if ($prev === null) {
            $change = 'First month';
        } elseif ($routes < (int) $prev['routes']) {
            $change = 'Decrease from ' . $prev['label'];
        } elseif ($routes > (int) $prev['routes']) {
            $change = 'Increase from ' . $prev['label'];
        } else {
            $change = 'No change';
        }
        $analysisRows[] = [
            reportDomainFleetSimpleH((string) $month['label']),
            reportDomainFleetSimpleH(number_format($routes)),
            reportDomainFleetSimpleH(number_format($amount, 0)),
            reportDomainFleetSimpleH($change),
        ];
        $prev = $month;
    }
    $monthRows[] = [
        '<strong>Total</strong>',
        '<strong>' . reportDomainFleetSimpleH(number_format((int) $data['ght_routes'])) . '</strong>',
        '<strong>' . reportDomainFleetSimpleH(number_format((float) $data['ght_amount'], 0)) . '</strong>',
    ];

    $riderRows = [];
    foreach ($data['riders'] as $rider) {
        $riderRows[] = [
            reportDomainFleetSimpleH((string) ($rider['rider_name'] ?? 'Unassigned')),
            reportDomainFleetSimpleH(number_format((int) ($rider['routes'] ?? 0))),
        ];
    }
    if ($riderRows === []) {
        $riderRows[] = ['No completed routes were recorded for Ultimate in this period.', ''];
    }
    $riderRows[] = [
        '<strong>Total</strong>',
        '<strong>' . reportDomainFleetSimpleH(number_format((int) $data['ultimate_routes'])) . '</strong>',
    ];

    $analysis = reportDomainFleetSimpleTable(
        ['Month', 'Routes completed', 'Amount (TZS)', 'Compared with previous month'],
        $analysisRows,
        [1, 2]
    );
    $analysis .= '<p>Overall, GHT completed ' . number_format((int) $data['ght_routes']) . ' routes';
    if ((float) $data['ght_amount'] > 0) {
        $analysis .= ', generating a total amount of TZS ' . number_format((float) $data['ght_amount'], 0);
    }
    $analysis .= ' during this period.</p>';

    return '<p><strong>A. GHT Deliveries Performance</strong></p>'
        . '<p>The table below summarizes delivery activities performed under GHT during the reporting period.</p>'
        . reportDomainFleetSimpleTable(['Month', 'Routes Completed', 'Amount (TZS)'], $monthRows, [1, 2])
        . '<p><strong>Analysis</strong></p>'
        . $analysis
        . '<p><strong>B. Ultimate Deliveries Performance by Rider</strong></p>'
        . '<p>The following table shows route distribution among riders under Ultimate operations.</p>'
        . reportDomainFleetSimpleTable(['Rider', 'Routes Completed'], $riderRows, [1]);
}
