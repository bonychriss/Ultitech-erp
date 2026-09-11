<?php

declare(strict_types=1);

require_once __DIR__ . '/report-domain-fleet-extra.php';

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
    $prevFilters = reportDomainFleetPreviousPeriod($filters);
    $prevKpis = reportDomainFleetKpis($pdo, $prevFilters, true);
    $overdue = reportDomainFleetOverdueOrders($pdo, $filters);

    return [
        'kpis' => $kpis,
        'prev_kpis' => $prevKpis,
        'prev_filters' => $prevFilters,
        'driver_performance' => reportDomainFleetDriverStats($pdo, $filters),
        'monthly_trips' => reportDomainFleetMonthlyTrips($pdo, $filters),
        'vehicle_utilization' => reportDomainFleetVehicleStats($pdo, $filters),
        'trip_status_breakdown' => reportDomainFleetTripStatusBreakdown($pdo, $filters),
        'order_status_breakdown' => reportDomainFleetOrderStatusBreakdown($pdo, $filters),
        'period_comparison' => reportDomainFleetPeriodComparisonRows($kpis, $prevKpis, $filters, $prevFilters),
        'overdue_orders' => $overdue,
        'key_activities' => reportDomainFleetKeyActivities($pdo, $filters, $kpis),
        'exceptions' => reportDomainFleetExceptions($pdo, $filters, $kpis),
        'data_quality' => reportEngineDataQualityNotes($kpis['data_quality_notes'] ?? []),
        'sections_available' => reportDomainFleetAvailableSections($kpis),
    ];
}

function reportDomainFleetAvailableSections(array $kpis): array
{
    return reportEngineDefaultSections('fleet');
}

function reportDomainFleetKpis(PDO $pdo, array $filters, bool $lite = false): array
{
    $empty = [
        'total_trips' => 0,
        'completed_trips' => 0,
        'planned_trips' => 0,
        'in_transit_trips' => 0,
        'open_trips' => 0,
        'total_deliveries' => 0,
        'completed_deliveries' => 0,
        'open_orders' => 0,
        'pending_orders' => 0,
        'rejected_orders' => 0,
        'overdue_orders' => 0,
        'drivers_active' => 0,
        'vehicles_used' => 0,
        'total_route_cost' => 0.0,
        'total_mileage' => 0.0,
        'avg_mileage_per_trip' => 0.0,
        'completion_rate_pct' => null,
        'on_time_rate_pct' => null,
        'on_time_sample_size' => 0,
        'avg_customer_rating' => null,
        'delivery_notes_count' => 0,
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
    $plannedTrips = 0;
    $inTransitTrips = 0;
    $drivers = 0;
    $vehicles = 0;
    $mileage = 0.0;

    if (tableExists('delivery_trips', $pdo)) {
        $dateExpr = reportDomainFleetTripDateExpr($pdo);
        $sql = "SELECT COUNT(*) AS trips,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('completed','closed','done') THEN 1 ELSE 0 END) AS completed,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('planned','scheduled') THEN 1 ELSE 0 END) AS planned,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('in_transit','in transit','active','started') THEN 1 ELSE 0 END) AS in_transit,
                       COUNT(DISTINCT NULLIF(driver_id, 0)) AS drivers,
                       COUNT(DISTINCT CASE
                            WHEN vehicle_id IS NOT NULL AND TRIM(vehicle_id) <> '' THEN vehicle_id
                       END) AS vehicles,
                       COALESCE(SUM(GREATEST(COALESCE(end_odometer,0) - COALESCE(start_odometer,0), 0)), 0) AS mileage
                FROM delivery_trips
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
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
            $plannedTrips = (int) ($row['planned'] ?? 0);
            $inTransitTrips = (int) ($row['in_transit'] ?? 0);
            $drivers = (int) ($row['drivers'] ?? 0);
            $vehicles = (int) ($row['vehicles'] ?? 0);
            $mileage = (float) ($row['mileage'] ?? 0);
        } catch (Throwable $e) {
            $notes[] = 'Could not query delivery trips.';
        }
    }

    $deliveries = 0;
    $completedDeliveries = 0;
    $openOrders = 0;
    $pendingOrders = 0;
    $rejectedOrders = 0;
    $routeCost = 0.0;
    $onTime = null;
    $onTimeSample = 0;
    $avgRating = null;

    if (tableExists('delivery_orders', $pdo)) {
        $dateCol = columnExists('delivery_orders', 'created_at', $pdo) ? 'created_at' : 'updated_at';
        $hasDeadline = columnExists('delivery_orders', 'delivery_deadline', $pdo);
        $hasCompletion = columnExists('delivery_orders', 'completion_time', $pdo);
        $hasRating = columnExists('delivery_orders', 'customer_rating', $pdo);

        $onTimeSelect = '0 AS on_time_cnt, 0 AS on_time_sample';
        if ($hasDeadline && $hasCompletion) {
            $onTimeSelect = "SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(status,''))) IN ('delivered','completed','closed')
                     AND completion_time IS NOT NULL AND TRIM(completion_time) <> ''
                     AND delivery_deadline IS NOT NULL AND TRIM(delivery_deadline) <> ''
                     AND completion_time <= delivery_deadline THEN 1 ELSE 0 END) AS on_time_cnt,
                SUM(CASE
                    WHEN LOWER(TRIM(COALESCE(status,''))) IN ('delivered','completed','closed')
                     AND completion_time IS NOT NULL AND TRIM(completion_time) <> ''
                     AND delivery_deadline IS NOT NULL AND TRIM(delivery_deadline) <> '' THEN 1 ELSE 0 END) AS on_time_sample";
        }

        $ratingSelect = $hasRating
            ? 'AVG(CASE WHEN customer_rating IS NOT NULL AND customer_rating > 0 THEN customer_rating END) AS avg_rating'
            : 'NULL AS avg_rating';

        $sql = "SELECT COUNT(*) AS cnt,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('delivered','completed','closed') THEN 1 ELSE 0 END) AS completed_cnt,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) NOT IN ('delivered','completed','closed','rejected') THEN 1 ELSE 0 END) AS open_cnt,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) IN ('pending','request_pending','requested') THEN 1 ELSE 0 END) AS pending_cnt,
                       SUM(CASE WHEN LOWER(TRIM(COALESCE(status,''))) = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt,
                       COALESCE(SUM(route_cost), 0) AS route_cost,
                       {$onTimeSelect},
                       {$ratingSelect}
                FROM delivery_orders
                WHERE DATE({$dateCol}) BETWEEN ? AND ?";
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
            $openOrders = (int) ($row['open_cnt'] ?? 0);
            $pendingOrders = (int) ($row['pending_cnt'] ?? 0);
            $rejectedOrders = (int) ($row['rejected_cnt'] ?? 0);
            $routeCost = (float) ($row['route_cost'] ?? 0);
            $onTimeSample = (int) ($row['on_time_sample'] ?? 0);
            if ($onTimeSample > 0) {
                $onTime = round(((int) ($row['on_time_cnt'] ?? 0) / $onTimeSample) * 100, 1);
            }
            if ($row['avg_rating'] !== null && $row['avg_rating'] !== '') {
                $avgRating = round((float) $row['avg_rating'], 2);
            }
        } catch (Throwable $e) {
            $notes[] = 'Could not query delivery orders.';
        }
    }

    $completionRate = $deliveries > 0
        ? round(($completedDeliveries / $deliveries) * 100, 1)
        : null;

    $overdueCount = 0;
    $notesCount = 0;
    if (!$lite) {
        $overdueCount = reportDomainFleetOverdueOrdersCount($pdo, $filters);
        $notesCount = reportDomainFleetDeliveryNotesCount($pdo, $filters);
    }

    if ($trips > 0 && $vehicles === 0) {
        $notes[] = 'Vehicle IDs are missing on trip records in this period, so vehicle utilization cannot be reported.';
    }
    if ($trips > 0 && $mileage <= 0) {
        $notes[] = 'Odometer readings are missing or zero for trips in this period, so distance traveled cannot be reported.';
    }
    if ($deliveries > 0 && $routeCost <= 0) {
        $notes[] = 'Route cost is missing or zero on delivery orders in this period, so route cost cannot be reported.';
    }
    if ($completedDeliveries > 0 && $onTimeSample === 0) {
        $notes[] = 'On-time rate needs both completion_time and delivery_deadline on completed deliveries; insufficient pairs were available.';
    }

    $kpisPartial = [
        'total_trips' => $trips,
        'completed_trips' => $completedTrips,
        'total_deliveries' => $deliveries,
        'completed_deliveries' => $completedDeliveries,
        'completion_rate_pct' => $completionRate,
        'on_time_rate_pct' => $onTime,
    ];
    $exceptions = $lite ? [] : reportDomainFleetExceptions($pdo, $filters, $kpisPartial);

    return [
        'total_trips' => $trips,
        'completed_trips' => $completedTrips,
        'planned_trips' => $plannedTrips,
        'in_transit_trips' => $inTransitTrips,
        'open_trips' => max(0, $trips - $completedTrips),
        'total_deliveries' => $deliveries,
        'completed_deliveries' => $completedDeliveries,
        'open_orders' => $openOrders,
        'pending_orders' => $pendingOrders,
        'rejected_orders' => $rejectedOrders,
        'overdue_orders' => $overdueCount,
        'drivers_active' => $drivers,
        'vehicles_used' => $vehicles,
        'total_route_cost' => $routeCost,
        'total_mileage' => $mileage,
        'avg_mileage_per_trip' => $trips > 0 ? round($mileage / $trips, 1) : 0.0,
        'completion_rate_pct' => $completionRate,
        'on_time_rate_pct' => $onTime,
        'on_time_sample_size' => $onTimeSample,
        'avg_customer_rating' => $avgRating,
        'delivery_notes_count' => $notesCount,
        'exceptions_count' => count($exceptions),
        'data_quality_notes' => $notes,
    ];
}

function reportDomainFleetDriverStats(PDO $pdo, array $filters): array
{
    if (!tableExists('delivery_trips', $pdo) || !tableExists('users', $pdo)) {
        return [];
    }
    $dateExpr = reportDomainFleetTripDateExpr($pdo, 't');
    $sql = "SELECT u.full_name AS driver_name,
                   COUNT(t.id) AS trip_count,
                   SUM(CASE WHEN LOWER(TRIM(COALESCE(t.status,''))) IN ('completed','closed','done') THEN 1 ELSE 0 END) AS completed,
                   COALESCE(SUM(GREATEST(COALESCE(t.end_odometer,0) - COALESCE(t.start_odometer,0), 0)), 0) AS mileage
            FROM delivery_trips t
            LEFT JOIN users u ON u.id = t.driver_id
            WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
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
    $dateExpr = reportDomainFleetTripDateExpr($pdo);
    $sql = "SELECT vehicle_id, COUNT(*) AS trip_count,
                   COALESCE(SUM(GREATEST(COALESCE(end_odometer,0) - COALESCE(start_odometer,0), 0)), 0) AS mileage
            FROM delivery_trips
            WHERE DATE({$dateExpr}) BETWEEN ? AND ? AND vehicle_id IS NOT NULL AND TRIM(vehicle_id) != ''";
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
    $dateExpr = reportDomainFleetTripDateExpr($pdo);
    $sql = "SELECT DATE_FORMAT({$dateExpr}, '%Y-%m') AS ym, COUNT(*) AS count
            FROM delivery_trips
            WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
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
    $completion = $kpis['completion_rate_pct'] ?? null;
    if ($completion === null && ($kpis['total_deliveries'] ?? 0) > 0) {
        $completion = round(
            ((int) ($kpis['completed_deliveries'] ?? 0) / (int) $kpis['total_deliveries']) * 100,
            1
        );
    }
    if ($completion !== null && (float) $completion < 80.0 && ($kpis['total_deliveries'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'delivery_completion',
            'message' => 'Delivery completion rate is below 80% for the selected period ('
                . number_format((float) $completion, 1) . '%).',
            'severity' => 'high',
        ];
    }
    if (($kpis['overdue_orders'] ?? 0) > 0) {
        $exceptions[] = [
            'type' => 'overdue_orders',
            'message' => (int) $kpis['overdue_orders'] . ' open order(s) are past their delivery deadline.',
            'severity' => 'high',
        ];
    }
    if (($kpis['on_time_rate_pct'] ?? null) !== null
        && (float) $kpis['on_time_rate_pct'] < 80.0
        && (int) ($kpis['on_time_sample_size'] ?? 0) > 0
    ) {
        $exceptions[] = [
            'type' => 'on_time_performance',
            'message' => 'On-time delivery rate is '
                . number_format((float) $kpis['on_time_rate_pct'], 1)
                . '% based on ' . (int) $kpis['on_time_sample_size']
                . ' completed order(s) with deadline and completion timestamps.',
            'severity' => 'medium',
        ];
    }

    return $exceptions;
}

function reportDomainFleetErpMenu(): array
{
    return [
        'Summary' => [
            'fleet_summary' => 'Fleet Summary KPIs',
            'period_comparison' => 'Period Comparison',
            'monthly_trips' => 'Monthly Trip Trend',
            'key_activities' => 'Key Fleet Activities',
        ],
        'Analysis' => [
            'driver_performance' => 'Driver Performance',
            'trip_status_breakdown' => 'Trip Status Breakdown',
            'order_status_breakdown' => 'Delivery Order Status',
            'vehicle_utilization' => 'Vehicle Utilization',
            'overdue_orders' => 'Overdue Delivery Orders',
            'delivery_list' => 'Delivery Orders',
        ],
    ];
}

function reportDomainFleetFetch(PDO $pdo, string $source, array $filters): array
{
    $snapshot = reportDomainFleetSnapshot($pdo, $filters);
    $kpis = $snapshot['kpis'] ?? [];

    return match ($source) {
        'fleet_summary', 'fleet_overview_table' => [
            'html' => reportDomainFleetOverviewTableHtml($kpis, $filters),
            'snapshot' => $kpis,
        ],
        'period_comparison' => [
            'html' => reportDomainFleetPeriodComparisonTableHtml($snapshot['period_comparison'] ?? []),
            'snapshot' => $snapshot['period_comparison'] ?? [],
        ],
        'monthly_trips' => [
            'html' => reportEngineMonthlyTrendTable($snapshot['monthly_trips'] ?? [], 'Trips'),
            'snapshot' => $snapshot['monthly_trips'] ?? [],
        ],
        'key_activities' => [
            'html' => reportDomainFleetKeyActivitiesTableHtml($snapshot['key_activities'] ?? []),
            'snapshot' => $snapshot['key_activities'] ?? [],
        ],
        'driver_performance' => [
            'html' => reportEngineRenderDataTable(
                ['Driver', 'Trips', 'Completed', 'Mileage (km)'],
                array_map(static function ($r) {
                    $mileage = (float) ($r['mileage'] ?? 0);

                    return [
                        (string) ($r['driver_name'] ?? 'Unassigned'),
                        number_format((int) ($r['trip_count'] ?? 0)),
                        number_format((int) ($r['completed'] ?? 0)),
                        $mileage > 0 ? number_format($mileage, 1) : '—',
                    ];
                }, $snapshot['driver_performance'] ?? [])
            ),
            'snapshot' => $snapshot['driver_performance'] ?? [],
        ],
        'trip_status_breakdown' => [
            'html' => reportEngineRenderDataTable(
                ['Trip Status', 'Count', '% of Trips'],
                array_map(static fn($r) => [
                    reportDomainFleetStatusLabel((string) ($r['status'] ?? '')),
                    number_format((int) ($r['count'] ?? 0)),
                    number_format((float) ($r['pct'] ?? 0), 1) . '%',
                ], $snapshot['trip_status_breakdown'] ?? [])
            ),
            'snapshot' => $snapshot['trip_status_breakdown'] ?? [],
        ],
        'order_status_breakdown' => [
            'html' => reportEngineRenderDataTable(
                ['Order Status', 'Count', '% of Orders'],
                array_map(static fn($r) => [
                    reportDomainFleetStatusLabel((string) ($r['status'] ?? '')),
                    number_format((int) ($r['count'] ?? 0)),
                    number_format((float) ($r['pct'] ?? 0), 1) . '%',
                ], $snapshot['order_status_breakdown'] ?? [])
            ),
            'snapshot' => $snapshot['order_status_breakdown'] ?? [],
        ],
        'vehicle_utilization' => [
            'html' => ($snapshot['vehicle_utilization'] ?? []) === []
                ? '<p class="sr-muted">No vehicle IDs were recorded on trips in this period, so utilization by vehicle cannot be shown.</p>'
                : reportEngineRenderDataTable(
                    ['Vehicle', 'Trips', 'Mileage (km)'],
                    array_map(static fn($r) => [
                        (string) ($r['vehicle_id'] ?? ''),
                        number_format((int) ($r['trip_count'] ?? 0)),
                        number_format((float) ($r['mileage'] ?? 0), 1),
                    ], $snapshot['vehicle_utilization'] ?? [])
                ),
            'snapshot' => $snapshot['vehicle_utilization'] ?? [],
        ],
        'overdue_orders' => [
            'html' => reportDomainFleetOverdueOrdersTableHtml($snapshot['overdue_orders'] ?? []),
            'snapshot' => $snapshot['overdue_orders'] ?? [],
        ],
        'delivery_list' => [
            'html' => reportDomainFleetDeliveryListHtml($pdo, $filters),
            'snapshot' => [],
        ],
        default => ['html' => '<p>Unknown fleet data source.</p>', 'snapshot' => []],
    };
}

function reportDomainFleetPeriodComparisonTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No period comparison metrics available.</p>';
    }
    $cur = (string) ($rows[0]['current_period'] ?? 'Current');
    $prev = (string) ($rows[0]['previous_period'] ?? 'Previous');

    return reportEngineRenderDataTable(
        ['Metric', $cur, $prev, 'Change'],
        array_map(static fn($r) => [
            (string) ($r['metric'] ?? ''),
            (string) ($r['current_display'] ?? ''),
            (string) ($r['previous_display'] ?? ''),
            (string) ($r['change'] ?? ''),
        ], $rows)
    );
}

function reportDomainFleetKeyActivitiesTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No key fleet activities were recorded for this period.</p>';
    }

    return reportEngineRenderDataTable(
        ['Activity', 'Detail'],
        array_map(static fn($r) => [
            (string) ($r['activity'] ?? ''),
            (string) ($r['detail'] ?? ''),
        ], $rows)
    );
}

function reportDomainFleetOverdueOrdersTableHtml(array $rows): string
{
    if ($rows === []) {
        return '<p class="sr-muted">No overdue open delivery orders were found.</p>';
    }

    return reportEngineRenderDataTable(
        ['Order ID', 'Client', 'Driver', 'Deadline', 'Status'],
        array_map(static fn($r) => [
            (string) ($r['id'] ?? ''),
            (string) ($r['client_name'] ?? ''),
            (string) ($r['driver_name'] ?? ''),
            substr((string) ($r['delivery_deadline'] ?? ''), 0, 16),
            (string) ($r['status'] ?? ''),
        ], $rows)
    );
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
    $completion = ($kpis['completion_rate_pct'] ?? null) !== null
        ? number_format((float) $kpis['completion_rate_pct'], 1) . '%'
        : 'N/A';
    $onTime = ($kpis['on_time_rate_pct'] ?? null) !== null
        ? number_format((float) $kpis['on_time_rate_pct'], 1) . '%'
            . ' (n=' . number_format((int) ($kpis['on_time_sample_size'] ?? 0)) . ')'
        : 'N/A';
    $rating = ($kpis['avg_customer_rating'] ?? null) !== null
        ? number_format((float) $kpis['avg_customer_rating'], 2) . ' / 5'
        : 'N/A';

    $rows = [
        ['Total / Completed Trips', number_format((int) ($kpis['total_trips'] ?? 0)) . ' / ' . number_format((int) ($kpis['completed_trips'] ?? 0))],
        ['Planned / In Transit Trips', number_format((int) ($kpis['planned_trips'] ?? 0)) . ' / ' . number_format((int) ($kpis['in_transit_trips'] ?? 0))],
        ['Total / Completed Deliveries', number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' / ' . number_format((int) ($kpis['completed_deliveries'] ?? 0))],
        ['Delivery Completion Rate', $completion],
        ['On-Time Delivery Rate', $onTime],
        ['Open / Pending / Rejected Orders', number_format((int) ($kpis['open_orders'] ?? 0))
            . ' / ' . number_format((int) ($kpis['pending_orders'] ?? 0))
            . ' / ' . number_format((int) ($kpis['rejected_orders'] ?? 0))],
        ['Overdue Open Orders', number_format((int) ($kpis['overdue_orders'] ?? 0))],
        ['Delivery Notes (period)', number_format((int) ($kpis['delivery_notes_count'] ?? 0))],
        ['Active Drivers', number_format((int) ($kpis['drivers_active'] ?? 0))],
        ['Vehicles Utilized', ((int) ($kpis['vehicles_used'] ?? 0)) > 0
            ? number_format((int) $kpis['vehicles_used'])
            : 'Not recorded'],
        ['Total Distance Traveled', ((float) ($kpis['total_mileage'] ?? 0)) > 0
            ? number_format((float) $kpis['total_mileage'], 1) . ' km'
            : 'Not recorded'],
        ['Total Route Cost', ((float) ($kpis['total_route_cost'] ?? 0)) > 0
            ? salesReportsFormatMoney((float) $kpis['total_route_cost'])
            : 'Not recorded'],
        ['Average Customer Rating', $rating],
    ];

    $html = reportEngineRenderDataTable(
        ['Performance Metric', 'Operational Value (' . $monthLabel . ')'],
        $rows
    );

    $notes = array_values(array_filter($kpis['data_quality_notes'] ?? []));
    if ($notes !== []) {
        $html .= '<p><strong>Data notes:</strong></p><ul>';
        foreach ($notes as $note) {
            $html .= '<li>' . htmlspecialchars((string) $note, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';
    }

    return $html;
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
    $exceptions = reportDomainFleetExceptions($pdo, $filters, $kpis);
    $overdue = reportDomainFleetOverdueOrders($pdo, $filters);
    $prevFilters = reportDomainFleetPreviousPeriod($filters);
    $prevKpis = reportDomainFleetKpis($pdo, $prevFilters, true);
    $tripBreakdown = reportDomainFleetTripStatusBreakdown($pdo, $filters);
    $orderBreakdown = reportDomainFleetOrderStatusBreakdown($pdo, $filters);
    $activities = reportDomainFleetKeyActivities($pdo, $filters, $kpis);

    return match ($sectionKey) {
        'executive_summary' => reportDomainFleetExecutiveSummary($periodPhrase, $idle, $kpis),
        'fleet_overview' => $idle
            ? '<p>No fleet trips or deliveries were recorded during ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '. See the KPI table for full metrics.</p>'
            : '<p>Fleet operations for ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . ' recorded '
                . number_format((int) ($kpis['total_trips'] ?? 0)) . ' trips and '
                . number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' deliveries. Refer to the KPI table for detailed performance metrics.</p>',
        'operational_challenges', 'challenges_issues', 'exceptions_risks' => reportDomainFleetChallengesFromDataHtml($kpis, $exceptions, $overdue),
        'key_fleet_activities' => reportDomainFleetActivitiesProseHtml($activities),
        'period_comparison' => reportDomainFleetComparisonProseHtml($kpis, $prevKpis, $filters, $prevFilters),
        'trip_status_analysis' => reportDomainFleetStatusProseHtml($tripBreakdown, null, $filters),
        'delivery_status_analysis' => reportDomainFleetStatusProseHtml(null, $orderBreakdown, $filters),
        'fleet_status_analysis' => reportDomainFleetStatusProseHtml($tripBreakdown, $orderBreakdown, $filters),
        'key_findings' => reportDomainFleetKeyFindings($idle, $monthLabel, $kpis, $exceptions),
        'recommendations' => reportDomainFleetRecommendations($idle, $kpis, $exceptions),
        'action_plan' => reportDomainFleetActionPlan($idle, $kpis),
        'conclusion' => reportDomainFleetConclusion($idle, $monthLabel, $kpis),
        default => '<p></p>',
    };
}

function reportDomainFleetExecutiveSummary(string $periodPhrase, bool $idle, array $kpis): string
{
    if ($idle) {
        return '<p>During the reporting period from ' . htmlspecialchars($periodPhrase, ENT_QUOTES, 'UTF-8')
            . ', fleet operations recorded zero trips and zero delivery orders in the ERP. '
            . 'This report documents the empty operating period so management can confirm whether activity was expected '
            . 'and whether trip/order capture is complete.</p>';
    }

    $trips = number_format((int) ($kpis['total_trips'] ?? 0));
    $deliveries = number_format((int) ($kpis['total_deliveries'] ?? 0));
    $drivers = number_format((int) ($kpis['drivers_active'] ?? 0));
    $completedTrips = number_format((int) ($kpis['completed_trips'] ?? 0));
    $completion = ($kpis['completion_rate_pct'] ?? null) !== null
        ? number_format((float) $kpis['completion_rate_pct'], 1) . '%'
        : 'N/A';
    $onTime = ($kpis['on_time_rate_pct'] ?? null) !== null
        ? number_format((float) $kpis['on_time_rate_pct'], 1) . '%'
        : 'N/A';

    $vehicleNote = ((int) ($kpis['vehicles_used'] ?? 0)) > 0
        ? number_format((int) $kpis['vehicles_used']) . ' vehicle(s) with recorded IDs'
        : 'no vehicle IDs recorded on trips';

    return '<p>During the reporting period from ' . htmlspecialchars($periodPhrase, ENT_QUOTES, 'UTF-8')
        . ', the fleet recorded ' . $trips . ' trips (' . $completedTrips . ' completed) and '
        . $deliveries . ' delivery orders, involving ' . $drivers . ' active drivers and '
        . $vehicleNote . '. Delivery completion rate was ' . htmlspecialchars($completion, ENT_QUOTES, 'UTF-8')
        . ' and on-time rate was ' . htmlspecialchars($onTime, ENT_QUOTES, 'UTF-8')
        . '. Detailed KPIs, status breakdowns, and period comparison follow.</p>';
}

function reportDomainFleetKeyFindings(bool $idle, string $monthLabel, array $kpis, array $exceptions = []): string
{
    $month = htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8');
    if ($idle) {
        return '<ul>'
            . '<li><strong>No operational volume:</strong> Zero trips and zero delivery orders were recorded for ' . $month . '.</li>'
            . '<li><strong>Data confirmation needed:</strong> Confirm whether fleet work occurred outside the ERP or whether scheduling/capture gaps explain the empty period.</li>'
            . '</ul>';
    }

    $items = [];
    $items[] = '<li><strong>Operational volume:</strong> '
        . number_format((int) ($kpis['total_trips'] ?? 0)) . ' trips and '
        . number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' deliveries recorded for ' . $month . '.</li>';

    $completion = ($kpis['completion_rate_pct'] ?? null) !== null
        ? number_format((float) $kpis['completion_rate_pct'], 1) . '%'
        : 'N/A';
    $items[] = '<li><strong>Completion performance:</strong> Delivery completion rate stood at '
        . htmlspecialchars($completion, ENT_QUOTES, 'UTF-8') . ' ('
        . number_format((int) ($kpis['completed_deliveries'] ?? 0)) . ' of '
        . number_format((int) ($kpis['total_deliveries'] ?? 0)) . ').</li>';

    if (($kpis['on_time_rate_pct'] ?? null) !== null) {
        $items[] = '<li><strong>On-time performance:</strong> '
            . number_format((float) $kpis['on_time_rate_pct'], 1) . '% on-time across '
            . number_format((int) ($kpis['on_time_sample_size'] ?? 0))
            . ' completed order(s) with both deadline and completion timestamps.</li>';
    }

    $items[] = '<li><strong>Resource deployment:</strong> '
        . number_format((int) ($kpis['drivers_active'] ?? 0)) . ' drivers active'
        . (((int) ($kpis['vehicles_used'] ?? 0) > 0)
            ? '; ' . number_format((int) $kpis['vehicles_used']) . ' vehicles with IDs recorded.'
            : '; vehicle IDs were not recorded on trips.') . '</li>';

    $openBits = [];
    if ((int) ($kpis['open_trips'] ?? 0) > 0) {
        $openBits[] = number_format((int) $kpis['open_trips']) . ' open trip(s)';
    }
    if ((int) ($kpis['open_orders'] ?? 0) > 0) {
        $openBits[] = number_format((int) $kpis['open_orders']) . ' open order(s)';
    }
    if ((int) ($kpis['overdue_orders'] ?? 0) > 0) {
        $openBits[] = number_format((int) $kpis['overdue_orders']) . ' overdue order(s)';
    }
    if ((int) ($kpis['rejected_orders'] ?? 0) > 0) {
        $openBits[] = number_format((int) $kpis['rejected_orders']) . ' rejected order(s)';
    }
    if ($openBits !== []) {
        $items[] = '<li><strong>Open work &amp; exceptions:</strong> ' . implode('; ', $openBits) . '.</li>';
    }

    if ((float) ($kpis['total_mileage'] ?? 0) <= 0 && (int) ($kpis['total_trips'] ?? 0) > 0) {
        $items[] = '<li><strong>Mileage capture:</strong> Trip odometer values are missing or zero, so distance KPIs cannot be interpreted for this period.</li>';
    }

    return '<ul>' . implode('', $items) . '</ul>';
}

function reportDomainFleetRecommendations(bool $idle, array $kpis = [], array $exceptions = []): string
{
    if ($idle) {
        return '<ul>'
            . '<li><strong>Confirm planned activity:</strong> Verify whether deliveries were scheduled for the period and why no trips or orders appear in the ERP.</li>'
            . '<li><strong>Validate data capture:</strong> Ensure dispatch creates delivery_trips and delivery_orders when work is assigned.</li>'
            . '<li><strong>Restore reporting baseline:</strong> Re-run this report after the next active operating week to establish a usable KPI baseline.</li>'
            . '</ul>';
    }

    $items = [];
    if ((int) ($kpis['open_trips'] ?? 0) > 0) {
        $items[] = '<li><strong>Close open trips:</strong> Update status for '
            . number_format((int) $kpis['open_trips']) . ' trip(s) still not marked completed.</li>';
    }
    if ((int) ($kpis['overdue_orders'] ?? 0) > 0) {
        $items[] = '<li><strong>Clear overdue orders:</strong> Prioritize '
            . number_format((int) $kpis['overdue_orders']) . ' open order(s) past delivery deadline.</li>';
    }
    if (($kpis['completion_rate_pct'] ?? null) !== null && (float) $kpis['completion_rate_pct'] < 80) {
        $items[] = '<li><strong>Improve completion:</strong> Investigate incomplete or pending delivery orders dragging completion below 80%.</li>';
    }
    if (($kpis['on_time_rate_pct'] ?? null) !== null && (float) $kpis['on_time_rate_pct'] < 80) {
        $items[] = '<li><strong>Improve on-time delivery:</strong> Review late completions against delivery deadlines and adjust dispatch timing.</li>';
    }
    if ((int) ($kpis['vehicles_used'] ?? 0) === 0 && (int) ($kpis['total_trips'] ?? 0) > 0) {
        $items[] = '<li><strong>Capture vehicle IDs:</strong> Require vehicle assignment on every trip so utilization reporting becomes usable.</li>';
    }
    if ((float) ($kpis['total_mileage'] ?? 0) <= 0 && (int) ($kpis['total_trips'] ?? 0) > 0) {
        $items[] = '<li><strong>Capture odometer readings:</strong> Record start/end odometer on trips to enable mileage KPIs.</li>';
    }
    if ((float) ($kpis['total_route_cost'] ?? 0) <= 0 && (int) ($kpis['total_deliveries'] ?? 0) > 0) {
        $items[] = '<li><strong>Capture route costs:</strong> Enter route cost on delivery orders when available for cost analysis.</li>';
    }
    if ($items === []) {
        $items[] = '<li><strong>Sustain performance:</strong> Maintain current trip and delivery completion discipline and review driver performance weekly.</li>';
        $items[] = '<li><strong>Keep status current:</strong> Continue updating trip and order statuses promptly so the next period comparison stays accurate.</li>';
    }

    return '<ul>' . implode('', $items) . '</ul>';
}

function reportDomainFleetActionPlan(bool $idle, array $kpis = []): string
{
    if ($idle) {
        return '<p><strong>Phase 1: Confirm (Days 1&ndash;2)</strong></p>'
            . '<ul><li>Confirm with logistics whether any fleet activity was expected in the period.</li>'
            . '<li>Check whether trips/orders exist outside the selected date filters.</li></ul>'
            . '<p><strong>Phase 2: Fix capture (Days 3&ndash;5)</strong></p>'
            . '<ul><li>Ensure new dispatches create delivery_trips and delivery_orders with driver, status, and dates.</li>'
            . '<li>Backfill any missing recent activity if operations occurred but were not recorded.</li></ul>'
            . '<p><strong>Phase 3: Re-baseline (Days 6+)</strong></p>'
            . '<ul><li>Generate the next fleet report after an active operating week.</li>'
            . '<li>Use that report as the new KPI baseline for period comparison.</li></ul>';
    }

    $openFocusParts = [];
    if ((int) ($kpis['open_trips'] ?? 0) > 0) {
        $openFocusParts[] = number_format((int) $kpis['open_trips']) . ' open trip(s)';
    }
    if ((int) ($kpis['overdue_orders'] ?? 0) > 0) {
        $openFocusParts[] = number_format((int) $kpis['overdue_orders']) . ' overdue order(s)';
    }
    if ((int) ($kpis['open_orders'] ?? 0) > 0) {
        $openFocusParts[] = number_format((int) $kpis['open_orders']) . ' open delivery order(s)';
    }
    $openFocus = $openFocusParts !== []
        ? '<li>Assign owners to clear ' . implode(' and ', $openFocusParts) . '.</li>'
        : '<li>Confirm all active trips and delivery orders have current statuses.</li>';

    return '<p><strong>Phase 1: Review (Days 1&ndash;3)</strong></p>'
        . '<ul><li>Review KPI table, status breakdowns, and driver performance with the logistics lead.</li>'
        . $openFocus . '</ul>'
        . '<p><strong>Phase 2: Correct (Days 4&ndash;7)</strong></p>'
        . '<ul><li>Resolve open/overdue items and incomplete delivery statuses.</li>'
        . '<li>Close data gaps for vehicle IDs, odometer readings, and route costs where missing.</li></ul>'
        . '<p><strong>Phase 3: Improve (Days 8+)</strong></p>'
        . '<ul><li>Track weekly trip and delivery targets against this period baseline.</li>'
        . '<li>Report progress in the next fleet review using the period comparison section.</li></ul>';
}

function reportDomainFleetConclusion(bool $idle, string $monthLabel, array $kpis): string
{
    if ($idle) {
        return '<p>The ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8')
            . ' fleet review found no trips or delivery orders in the ERP for the selected period. '
            . 'Management should confirm whether this reflects actual idle operations or a capture gap, then restore a measurable reporting baseline.</p>';
    }

    $bits = [
        number_format((int) ($kpis['total_trips'] ?? 0)) . ' trips',
        number_format((int) ($kpis['total_deliveries'] ?? 0)) . ' deliveries',
    ];
    if (($kpis['completion_rate_pct'] ?? null) !== null) {
        $bits[] = number_format((float) $kpis['completion_rate_pct'], 1) . '% delivery completion';
    }
    if (($kpis['on_time_rate_pct'] ?? null) !== null) {
        $bits[] = number_format((float) $kpis['on_time_rate_pct'], 1) . '% on-time';
    }

    return '<p>The ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8')
        . ' fleet review recorded ' . implode(', ', $bits) . '. '
        . 'Management should use the KPI table, status breakdowns, and recommendations above to sustain performance and close any open operational gaps.</p>';
}