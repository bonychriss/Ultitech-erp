<?php

declare(strict_types=1);

function reportDomainFleetDriverOptions(PDO $pdo): array
{
    if (!tableExists('users', $pdo)) {
        return [];
    }
    $sql = "SELECT DISTINCT u.id, u.full_name
            FROM users u
            INNER JOIN delivery_trips t ON t.driver_id = u.id
            WHERE u.is_active = 1
            ORDER BY u.full_name ASC LIMIT 100";
    if (!tableExists('delivery_trips', $pdo)) {
        $sql = "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC LIMIT 100";
    }
    $params = [];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return reportDomainEmployeeOptions($pdo);
    }

    return array_map(static fn($r) => ['value' => (string) (int) $r['id'], 'label' => (string) ($r['full_name'] ?? 'Driver')], $rows);
}

function reportDomainFleetVehicleOptions(PDO $pdo): array
{
    if (!tableExists('delivery_trips', $pdo)) {
        return [];
    }
    try {
        $st = $pdo->query(
            "SELECT DISTINCT vehicle_id AS label FROM delivery_trips
             WHERE vehicle_id IS NOT NULL AND TRIM(vehicle_id) != ''
             ORDER BY vehicle_id ASC LIMIT 100"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    return array_map(static fn($r) => [
        'value' => (string) ($r['label'] ?? ''),
        'label' => (string) ($r['label'] ?? 'Vehicle'),
    ], $rows);
}

function reportDomainFleetSnapshot(PDO $pdo, array $filters): array
{
    $kpis = reportDomainFleetKpis($pdo, $filters);

    return [
        'kpis' => $kpis,
        'driver_performance' => reportDomainFleetDriverStats($pdo, $filters),
        'monthly_trips' => reportDomainFleetMonthlyTrips($pdo, $filters),
        'vehicle_utilization' => reportDomainFleetVehicleStats($pdo, $filters),
        'exceptions' => reportDomainFleetExceptions($pdo, $filters, $kpis),
        'data_quality' => reportEngineDataQualityNotes($kpis['data_quality_notes'] ?? []),
        'sections_available' => reportDomainFleetAvailableSections($kpis),
    ];
}

function reportDomainFleetAvailableSections(array $kpis): array
{
    return reportEngineDefaultSections('fleet');
}

function reportDomainFleetKpis(PDO $pdo, array $filters): array
{
    $empty = [
        'total_trips' => 0,
        'completed_trips' => 0,
        'total_deliveries' => 0,
        'completed_deliveries' => 0,
        'drivers_active' => 0,
        'vehicles_used' => 0,
        'total_route_cost' => 0.0,
        'total_mileage' => 0.0,
        'avg_mileage_per_trip' => 0.0,
        'on_time_rate_pct' => null,
        'exceptions_count' => 0,
        'data_quality_notes' => [],
    ];

    if (!tableExists('delivery_trips', $pdo) && !tableExists('delivery_orders', $pdo)) {
        $empty['data_quality_notes'][] = 'No delivery/fleet tables found. Fleet reporting requires delivery_trips and delivery_orders.';

        return $empty;
    }

    $notes = [];
    $trips = 0;
    $completedTrips = 0;
    $drivers = 0;
    $vehicles = 0;
    $mileage = 0.0;

    if (tableExists('delivery_trips', $pdo)) {
        $dateCol = columnExists('delivery_trips', 'start_time', $pdo) ? 'DATE(start_time)' : 'DATE(created_at)';
        $sql = "SELECT COUNT(*) AS trips,
                       SUM(CASE WHEN status IN ('completed','closed','done') THEN 1 ELSE 0 END) AS completed,
                       COUNT(DISTINCT driver_id) AS drivers,
                       COUNT(DISTINCT vehicle_id) AS vehicles,
                       COALESCE(SUM(GREATEST(COALESCE(end_odometer,0) - COALESCE(start_odometer,0), 0)), 0) AS mileage
                FROM delivery_trips
                WHERE {$dateCol} BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        reportEngineApplySqlFilters($sql, $params, $filters, [
            'driver_id' => 'driver_id',
            'trip_status' => 'status',
            'vehicle' => 'vehicle_id',
        ]);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $trips = (int) ($row['trips'] ?? 0);
            $completedTrips = (int) ($row['completed'] ?? 0);
            $drivers = (int) ($row['drivers'] ?? 0);
            $vehicles = (int) ($row['vehicles'] ?? 0);
            $mileage = (float) ($row['mileage'] ?? 0);
        } catch (Throwable $e) {
            $notes[] = 'Could not query delivery trips.';
        }
    }

    $deliveries = 0;
    $completedDeliveries = 0;
    $routeCost = 0.0;
    if (tableExists('delivery_orders', $pdo)) {
        $dateCol = columnExists('delivery_orders', 'created_at', $pdo) ? 'DATE(created_at)' : 'DATE(updated_at)';
        $sql = "SELECT COUNT(*) AS cnt,
                       SUM(CASE WHEN status IN ('delivered','completed','closed') THEN 1 ELSE 0 END) AS completed_cnt,
                       COALESCE(SUM(route_cost), 0) AS route_cost
                FROM delivery_orders
                WHERE {$dateCol} BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['driver_id']) && columnExists('delivery_orders', 'requested_driver_id', $pdo)) {
            $sql .= ' AND requested_driver_id = ?';
            $params[] = (int) $filters['driver_id'];
        }
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $deliveries = (int) ($row['cnt'] ?? 0);
            $completedDeliveries = (int) ($row['completed_cnt'] ?? 0);
            $routeCost = (float) ($row['route_cost'] ?? 0);
        } catch (Throwable $e) {
            $notes[] = 'Could not query delivery orders.';
        }
    }

    $onTime = $deliveries > 0 ? round(($completedDeliveries / $deliveries) * 100, 1) : null;
    $exceptions = reportDomainFleetExceptions($pdo, $filters, []);

    return [
        'total_trips' => $trips,
        'completed_trips' => $completedTrips,
        'total_deliveries' => $deliveries,
        'completed_deliveries' => $completedDeliveries,
        'drivers_active' => $drivers,
        'vehicles_used' => $vehicles,
        'total_route_cost' => $routeCost,
        'total_mileage' => $mileage,
        'avg_mileage_per_trip' => $trips > 0 ? round($mileage / $trips, 1) : 0.0,
        'on_time_rate_pct' => $onTime,
        'exceptions_count' => count($exceptions),
        'data_quality_notes' => $notes,
    ];
}

function reportDomainFleetDriverStats(PDO $pdo, array $filters): array
{
    if (!tableExists('delivery_trips', $pdo) || !tableExists('users', $pdo)) {
        return [];
    }
    $dateCol = columnExists('delivery_trips', 'start_time', $pdo) ? 'DATE(t.start_time)' : 'DATE(t.created_at)';
    $sql = "SELECT u.full_name AS driver_name,
                   COUNT(t.id) AS trip_count,
                   SUM(CASE WHEN t.status IN ('completed','closed','done') THEN 1 ELSE 0 END) AS completed,
                   COALESCE(SUM(GREATEST(COALESCE(t.end_odometer,0) - COALESCE(t.start_odometer,0), 0)), 0) AS mileage
            FROM delivery_trips t
            LEFT JOIN users u ON u.id = t.driver_id
            WHERE {$dateCol} BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineApplySqlFilters($sql, $params, $filters, [
        'driver_id' => 't.driver_id', 'trip_status' => 't.status', 'vehicle' => 't.vehicle_id',
    ]);
    $sql .= ' GROUP BY t.driver_id, driver_name ORDER BY trip_count DESC LIMIT 15';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainFleetVehicleStats(PDO $pdo, array $filters): array
{
    if (!tableExists('delivery_trips', $pdo)) {
        return [];
    }
    $dateCol = columnExists('delivery_trips', 'start_time', $pdo) ? 'DATE(start_time)' : 'DATE(created_at)';
    $sql = "SELECT vehicle_id, COUNT(*) AS trip_count,
                   COALESCE(SUM(GREATEST(COALESCE(end_odometer,0) - COALESCE(start_odometer,0), 0)), 0) AS mileage
            FROM delivery_trips
            WHERE {$dateCol} BETWEEN ? AND ? AND vehicle_id IS NOT NULL AND TRIM(vehicle_id) != ''";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineApplySqlFilters($sql, $params, $filters, [
        'driver_id' => 'driver_id', 'trip_status' => 'status', 'vehicle' => 'vehicle_id',
    ]);
    $sql .= ' GROUP BY vehicle_id ORDER BY trip_count DESC LIMIT 15';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainFleetMonthlyTrips(PDO $pdo, array $filters): array
{
    if (!tableExists('delivery_trips', $pdo)) {
        return [];
    }
    $dateCol = columnExists('delivery_trips', 'start_time', $pdo) ? 'start_time' : 'created_at';
    $sql = "SELECT DATE_FORMAT({$dateCol}, '%Y-%m') AS ym, COUNT(*) AS count
            FROM delivery_trips
            WHERE DATE({$dateCol}) BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    reportEngineApplySqlFilters($sql, $params, $filters, [
        'driver_id' => 'driver_id', 'trip_status' => 'status', 'vehicle' => 'vehicle_id',
    ]);
    $sql .= ' GROUP BY ym ORDER BY ym ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['label'] = date('M Y', strtotime(($r['ym'] ?? date('Y-m')) . '-01'));
            $r['total'] = (int) ($r['count'] ?? 0);
        }
        unset($r);

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainFleetExceptions(PDO $pdo, array $filters, array $kpis): array
{
    $exceptions = [];
    if (($kpis['total_trips'] ?? 0) > 0 && ($kpis['completed_trips'] ?? 0) < ($kpis['total_trips'] ?? 0)) {
        $open = (int) $kpis['total_trips'] - (int) $kpis['completed_trips'];
        $exceptions[] = [
            'type' => 'open_trips',
            'message' => $open . ' trip(s) not marked completed in the period.',
            'severity' => 'medium',
        ];
    }
    if (($kpis['total_deliveries'] ?? 0) > 0 && ($kpis['on_time_rate_pct'] ?? 100) < 80) {
        $exceptions[] = [
            'type' => 'delivery_completion',
            'message' => 'Delivery completion rate is below 80% for the selected period.',
            'severity' => 'high',
        ];
    }

    return $exceptions;
}

function reportDomainFleetErpMenu(): array
{
    return [
        'Summary' => [
            'fleet_summary' => 'Fleet Summary KPIs',
            'monthly_trips' => 'Monthly Trip Trend',
        ],
        'Analysis' => [
            'driver_performance' => 'Driver Performance',
            'vehicle_utilization' => 'Vehicle Utilization',
            'delivery_list' => 'Delivery Orders',
        ],
    ];
}

function reportDomainFleetFetch(PDO $pdo, string $source, array $filters): array
{
    $snapshot = reportDomainFleetSnapshot($pdo, $filters);
    $kpis = $snapshot['kpis'] ?? [];
    $period = salesReportsFormatPeriod($filters['start_date'], $filters['end_date']);

    return match ($source) {
        'fleet_summary', 'fleet_overview_table' => [
            'html' => reportDomainFleetOverviewTableHtml($kpis, $filters),
            'snapshot' => $kpis,
        ],
        'monthly_trips' => [
            'html' => reportEngineMonthlyTrendTable($snapshot['monthly_trips'] ?? [], 'Trips'),
            'snapshot' => $snapshot['monthly_trips'] ?? [],
        ],
        'driver_performance' => [
            'html' => reportEngineRenderDataTable(
                ['Driver', 'Trips', 'Completed', 'Mileage (km)'],
                array_map(static fn($r) => [
                    (string) ($r['driver_name'] ?? 'Unassigned'),
                    number_format((int) ($r['trip_count'] ?? 0)),
                    number_format((int) ($r['completed'] ?? 0)),
                    number_format((float) ($r['mileage'] ?? 0), 1),
                ], $snapshot['driver_performance'] ?? [])
            ),
            'snapshot' => $snapshot['driver_performance'] ?? [],
        ],
        'vehicle_utilization' => [
            'html' => reportEngineRenderDataTable(
                ['Vehicle', 'Trips', 'Mileage (km)'],
                array_map(static fn($r) => [
                    (string) ($r['vehicle_id'] ?? ''),
                    number_format((int) ($r['trip_count'] ?? 0)),
                    number_format((float) ($r['mileage'] ?? 0), 1),
                ], $snapshot['vehicle_utilization'] ?? [])
            ),
            'snapshot' => $snapshot['vehicle_utilization'] ?? [],
        ],
        'delivery_list' => [
            'html' => reportDomainFleetDeliveryListHtml($pdo, $filters),
            'snapshot' => [],
        ],
        default => ['html' => '<p>Unknown fleet data source.</p>', 'snapshot' => []],
    };
}

function reportDomainFleetDeliveryListHtml(PDO $pdo, array $filters): string
{
    if (!tableExists('delivery_orders', $pdo)) {
        return '<p class="sr-muted">Delivery orders not available.</p>';
    }
    $dateCol = columnExists('delivery_orders', 'created_at', $pdo) ? 'created_at' : 'updated_at';
    $sql = "SELECT id, client_name, delivery_address, status, route_cost, {$dateCol} AS order_date
            FROM delivery_orders
            WHERE DATE({$dateCol}) BETWEEN ? AND ?";
    $params = [$filters['start_date'], $filters['end_date']];
    if (!empty($filters['driver_id']) && columnExists('delivery_orders', 'requested_driver_id', $pdo)) {
        $sql .= ' AND requested_driver_id = ?';
        $params[] = (int) $filters['driver_id'];
    }
    $sql .= ' ORDER BY order_date DESC LIMIT 100';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return reportEngineRenderDataTable(
        ['ID', 'Client', 'Address', 'Route Cost', 'Date', 'Status'],
        array_map(static fn($r) => [
            (string) ($r['id'] ?? ''),
            (string) ($r['client_name'] ?? ''),
            substr((string) ($r['delivery_address'] ?? ''), 0, 40),
            salesReportsFormatMoney((float) ($r['route_cost'] ?? 0)),
            substr((string) ($r['order_date'] ?? ''), 0, 10),
            (string) ($r['status'] ?? ''),
        ], $rows)
    );
}

function reportDomainFleetPeriodMonthLabel(array $filters): string
{
    $start = $filters['start_date'] ?? date('Y-m-01');
    $end = $filters['end_date'] ?? date('Y-m-d');
    if (date('Y-m', strtotime($start)) === date('Y-m', strtotime($end))) {
        return date('F Y', strtotime($start));
    }

    return salesReportsFormatPeriod($start, $end);
}

function reportDomainFleetIsIdle(array $kpis): bool
{
    return ((int) ($kpis['total_trips'] ?? 0)) === 0
        && ((int) ($kpis['total_deliveries'] ?? 0)) === 0;
}

function reportDomainFleetOverviewTableHtml(array $kpis, array $filters): string
{
    $monthLabel = reportDomainFleetPeriodMonthLabel($filters);
    $completion = $kpis['on_time_rate_pct'] !== null
        ? number_format((float) $kpis['on_time_rate_pct'], 1) . '%'
        : 'N/A';

    return reportEngineRenderDataTable(
        ['Performance Metric', 'Operational Value (' . $monthLabel . ')'],
        [
            ['Total / Completed Trips', number_format((int) ($kpis['total_trips'] ?? 0)) . ' / ' . number_format((int) ($kpis['completed_trips'] ?? 0))],
            ['Total / Completed Deliveries', number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' / ' . number_format((int) ($kpis['completed_deliveries'] ?? 0))],
            ['Delivery Completion Rate', $completion],
            ['Active Drivers Available', number_format((int) ($kpis['drivers_active'] ?? 0))],
            ['Vehicles Utilized', number_format((int) ($kpis['vehicles_used'] ?? 0))],
            ['Total Distance Traveled', number_format((float) ($kpis['total_mileage'] ?? 0), 1) . ' km'],
            ['Total Route Cost', salesReportsFormatMoney((float) ($kpis['total_route_cost'] ?? 0))],
        ]
    );
}

function reportDomainFleetBlankLine(): string
{
    return str_repeat('_', 70);
}

function reportDomainFleetProseSection(PDO $pdo, array $report, string $sectionKey): string
{
    $filters = reportEngineFiltersFromReport($report);
    $kpis = reportDomainFleetKpis($pdo, $filters);
    $idle = reportDomainFleetIsIdle($kpis);
    $start = date('d F Y', strtotime($filters['start_date']));
    $end = date('d F Y', strtotime($filters['end_date']));
    $monthLabel = reportDomainFleetPeriodMonthLabel($filters);
    $periodPhrase = $start . ' to ' . $end;

    return match ($sectionKey) {
        'executive_summary' => reportDomainFleetExecutiveSummary($periodPhrase, $idle, $kpis),
        'fleet_overview' => $idle
            ? '<p>No fleet trips or deliveries were recorded during ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '. See the KPI table for full metrics.</p>'
            : '<p>Fleet operations for ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . ' completed '
                . number_format((int) ($kpis['total_trips'] ?? 0)) . ' trips and '
                . number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' deliveries. Refer to the KPI table for detailed performance metrics.</p>',
        'operational_challenges' => reportDomainFleetOperationalChallenges($monthLabel),
        'key_findings' => reportDomainFleetKeyFindings($idle, $monthLabel, $kpis),
        'recommendations' => reportDomainFleetRecommendations($idle),
        'action_plan' => reportDomainFleetActionPlan($idle),
        'conclusion' => reportDomainFleetConclusion($idle, $monthLabel, $kpis),
        default => '<p></p>',
    };
}

function reportDomainFleetExecutiveSummary(string $periodPhrase, bool $idle, array $kpis): string
{
    if ($idle) {
        return '<p>During the reporting period from ' . htmlspecialchars($periodPhrase, ENT_QUOTES, 'UTF-8')
            . ', the fleet operations recorded zero activity across all tracked performance metrics. '
            . 'No trips or deliveries occurred. This report highlights the underlying vehicle challenges faced by drivers '
            . 'and provides a simple, outbound action plan to restart fleet activity immediately.</p>';
    }

    $trips = number_format((int) ($kpis['total_trips'] ?? 0));
    $deliveries = number_format((int) ($kpis['total_deliveries'] ?? 0));
    $drivers = number_format((int) ($kpis['drivers_active'] ?? 0));
    $vehicles = number_format((int) ($kpis['vehicles_used'] ?? 0));

    return '<p>During the reporting period from ' . htmlspecialchars($periodPhrase, ENT_QUOTES, 'UTF-8')
        . ', the fleet completed ' . $trips . ' trips and ' . $deliveries . ' deliveries using '
        . $drivers . ' active drivers and ' . $vehicles . ' vehicles. '
        . 'Overall performance is summarized in the KPI table below.</p>';
}

function reportDomainFleetOperationalChallenges(string $monthLabel): string
{
    $blank = reportDomainFleetBlankLine();
    $month = htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8');

    return '<p>Use this section to document specific issues that prevented drivers from completing routes during '
        . $month . ':</p>'
        . '<ul>'
        . '<li><strong>Mechanical &amp; Vehicle Issues:</strong> ' . $blank . '</li>'
        . '<li><strong>Breakdown / Repair Status:</strong> ' . $blank . '</li>'
        . '<li><strong>Fuel or Logistical Constraints:</strong> ' . $blank . '</li>'
        . '<li><strong>Driver Availability / Illness:</strong> ' . $blank . '</li>'
        . '</ul>';
}

function reportDomainFleetKeyFindings(bool $idle, string $monthLabel, array $kpis): string
{
    if ($idle) {
        return '<ul>'
            . '<li><strong>Asset Stagnation:</strong> 100% of the vehicle fleet remained idle, generating zero utility value while retaining fixed maintenance costs.</li>'
            . '<li><strong>Operational Friction:</strong> A lack of client delivery requests paired with unresolved vehicle issues halted daily workflows.</li>'
            . '</ul>';
    }

    $rate = $kpis['on_time_rate_pct'] !== null ? number_format((float) $kpis['on_time_rate_pct'], 1) . '%' : 'N/A';

    return '<ul>'
        . '<li><strong>Operational Volume:</strong> ' . number_format((int) ($kpis['total_trips'] ?? 0)) . ' trips and '
        . number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' deliveries recorded for ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '.</li>'
        . '<li><strong>Completion Performance:</strong> Delivery completion rate stood at ' . htmlspecialchars($rate, ENT_QUOTES, 'UTF-8') . '.</li>'
        . '<li><strong>Resource Deployment:</strong> ' . number_format((int) ($kpis['drivers_active'] ?? 0)) . ' drivers and '
        . number_format((int) ($kpis['vehicles_used'] ?? 0)) . ' vehicles were utilized.</li>'
        . '<li><strong>Cost &amp; Distance:</strong> Total route cost was ' . salesReportsFormatMoney((float) ($kpis['total_route_cost'] ?? 0))
        . ' with ' . number_format((float) ($kpis['total_mileage'] ?? 0), 1) . ' km traveled.</li>'
        . '</ul>';
}

function reportDomainFleetRecommendations(bool $idle): string
{
    if ($idle) {
        return '<ul>'
            . '<li><strong>Clear Vehicle Backlogs:</strong> Immediately fix all grounded vehicles to ensure the fleet is safe and ready to roll.</li>'
            . '<li><strong>Launch Driver Outbound Push:</strong> Pivot driver responsibilities toward local business networking to generate immediate transport orders.</li>'
            . '</ul>';
    }

    return '<ul>'
        . '<li><strong>Optimize Dispatch:</strong> Review routes with low completion rates and reassign underutilized drivers.</li>'
        . '<li><strong>Monitor Costs:</strong> Track route costs and mileage per trip to identify inefficient deployments.</li>'
        . '<li><strong>Address Exceptions:</strong> Clear open trips and incomplete deliveries before the next reporting cycle.</li>'
        . '<li><strong>Maintain Telematics:</strong> Keep GPS and logging systems calibrated to support accurate future reporting.</li>'
        . '</ul>';
}

function reportDomainFleetActionPlan(bool $idle): string
{
    if ($idle) {
        $stepsTable = '<table class="sr-data-table" border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;margin:12pt 0;">'
            . '<thead><tr>'
            . '<th>Step 1: Inspect<br><span style="font-weight:normal;font-size:9pt;">(Get Road-Ready Today)</span></th>'
            . '<th>Step 2: Search<br><span style="font-weight:normal;font-size:9pt;">(Find New Customers)</span></th>'
            . '<th>Step 3: Secure<br><span style="font-weight:normal;font-size:9pt;">(Load &amp; Deliver)</span></th>'
            . '</tr></thead><tbody><tr>'
            . '<td><ul><li>Report all vehicle issues.</li><li>Complete safety checks.</li></ul></td>'
            . '<td><ul><li>Pitch to local shops.</li><li>Ask old clients for work.</li></ul></td>'
            . '<td><ul><li>Book the first delivery.</li><li>Confirm route &amp; drop-off.</li></ul></td>'
            . '</tr></tbody></table>';

        return $stepsTable
            . '<p><strong>Step 1: Get the Fleet Ready</strong></p>'
            . '<ul>'
            . '<li>Report any mechanical faults or vehicle issues to management immediately.</li>'
            . '<li>Clean the vehicles and complete standard pre-trip safety checks.</li>'
            . '</ul>'
            . '<p><strong>Step 2: Search for New Business</strong></p>'
            . '<ul>'
            . '<li>Talk to local shops, wholesale markets, and businesses to offer delivery services.</li>'
            . '<li>Contact previous customers to check if they have upcoming loads or shipments.</li>'
            . '<li>Hand out company contact details during daily community rounds to find new delivery leads.</li>'
            . '</ul>'
            . '<p><strong>Step 3: Secure the Load</strong></p>'
            . '<ul>'
            . '<li>Help new customers book their orders directly into our dispatch system.</li>'
            . '<li>Confirm delivery addresses and timing before heading out on the road.</li>'
            . '</ul>';
    }

    return '<p><strong>Phase 1: Review (Days 1&ndash;3)</strong></p>'
        . '<ul><li>Review KPI table and driver performance with the logistics lead.</li><li>Identify trips or deliveries still open or incomplete.</li></ul>'
        . '<p><strong>Phase 2: Correct (Days 4&ndash;7)</strong></p>'
        . '<ul><li>Assign owners to resolve exceptions and low-completion routes.</li><li>Adjust dispatch schedules based on demand patterns.</li></ul>'
        . '<p><strong>Phase 3: Improve (Days 8+)</strong></p>'
        . '<ul><li>Track weekly trip and delivery targets against this period baseline.</li><li>Report progress in the next fleet review.</li></ul>';
}

function reportDomainFleetConclusion(bool $idle, string $monthLabel, array $kpis): string
{
    if ($idle) {
        return '<p>The complete lack of fleet activity in ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8')
            . ' requires a quick, hands-on shift. By addressing vehicle maintenance immediately and sending drivers out '
            . 'to search for new customers, the fleet can quickly recover lost momentum and secure active delivery revenue.</p>';
    }

    return '<p>The ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8')
        . ' fleet review recorded ' . number_format((int) ($kpis['total_trips'] ?? 0)) . ' trips and '
        . number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' deliveries. '
        . 'Management should use the KPI table and recommendations above to sustain performance and address any open operational gaps.</p>';
}
