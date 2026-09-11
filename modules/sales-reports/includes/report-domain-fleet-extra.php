<?php

declare(strict_types=1);

/**
 * Fleet / driver report helpers used alongside report-domain-fleet.php.
 * Period comparison, status breakdowns, overdue orders, and data-backed prose.
 * Uses only delivery_trips, delivery_orders, delivery_notes, and users.
 */

function reportDomainFleetTripDateExpr(PDO $pdo, string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

    $hasStart = columnExists('delivery_trips', 'start_time', $pdo);
    $hasCreated = columnExists('delivery_trips', 'created_at', $pdo);

    if ($hasStart && $hasCreated) {
        return "COALESCE(NULLIF({$prefix}start_time, ''), {$prefix}created_at)";
    }
    if ($hasStart) {
        return "{$prefix}start_time";
    }
    if ($hasCreated) {
        return "{$prefix}created_at";
    }

    return "{$prefix}created_at";
}

function reportDomainFleetPreviousPeriod(array $filters): array
{
    $start = strtotime((string) ($filters['start_date'] ?? date('Y-m-01')));
    $end = strtotime((string) ($filters['end_date'] ?? date('Y-m-d')));
    if (!$start || !$end) {
        return [
            'start_date' => date('Y-m-01', strtotime('first day of last month')),
            'end_date' => date('Y-m-t', strtotime('last day of last month')),
        ];
    }
    $days = (int) floor(($end - $start) / 86400) + 1;
    $prevEnd = $start - 86400;
    $prevStart = $prevEnd - (($days - 1) * 86400);

    return [
        'start_date' => date('Y-m-d', $prevStart),
        'end_date' => date('Y-m-d', $prevEnd),
    ];
}

function reportDomainFleetPctChange(float $current, float $previous): ?float
{
    if (abs($previous) < 0.00001) {
        return $current == 0.0 ? 0.0 : null;
    }

    return (($current - $previous) / abs($previous)) * 100.0;
}

function reportDomainFleetChangeLabel(float $current, float $previous, bool $isPct = false): string
{
    $diff = $current - $previous;
    $sign = $diff > 0 ? '+' : '';

    if ($isPct) {
        return $sign . number_format($diff, 1) . ' pp';
    }

    $pct = reportDomainFleetPctChange($current, $previous);
    $pctPart = $pct === null ? 'n/a' : ($sign . number_format($pct, 1) . '%');

    return $sign . number_format($diff, 0) . ' (' . $pctPart . ')';
}

function reportDomainFleetPeriodComparisonRows(
    array $kpis,
    array $prevKpis,
    array $filters,
    array $prevFilters
): array {
    $curPeriod = salesReportsFormatPeriod(
        (string) ($filters['start_date'] ?? ''),
        (string) ($filters['end_date'] ?? '')
    );
    $prevPeriod = salesReportsFormatPeriod(
        (string) ($prevFilters['start_date'] ?? ''),
        (string) ($prevFilters['end_date'] ?? '')
    );

    $metrics = [
        ['label' => 'Total trips', 'key' => 'total_trips', 'pct' => false],
        ['label' => 'Completed trips', 'key' => 'completed_trips', 'pct' => false],
        ['label' => 'Total deliveries', 'key' => 'total_deliveries', 'pct' => false],
        ['label' => 'Completed deliveries', 'key' => 'completed_deliveries', 'pct' => false],
        ['label' => 'Completion rate', 'key' => 'completion_rate_pct', 'pct' => true],
        ['label' => 'On-time rate', 'key' => 'on_time_rate_pct', 'pct' => true, 'nullable' => true],
        ['label' => 'Active drivers', 'key' => 'drivers_active', 'pct' => false],
        ['label' => 'Vehicles used', 'key' => 'vehicles_used', 'pct' => false],
        ['label' => 'Open orders', 'key' => 'open_orders', 'pct' => false],
        ['label' => 'Overdue orders', 'key' => 'overdue_orders', 'pct' => false],
        ['label' => 'Delivery notes', 'key' => 'delivery_notes_count', 'pct' => false],
    ];

    $rows = [];
    foreach ($metrics as $m) {
        if (!empty($m['nullable']) && !array_key_exists($m['key'], $kpis) && !array_key_exists($m['key'], $prevKpis)) {
            continue;
        }
        if (!empty($m['nullable']) && ($kpis[$m['key']] ?? null) === null && ($prevKpis[$m['key']] ?? null) === null) {
            continue;
        }

        $curRaw = $kpis[$m['key']] ?? null;
        $prevRaw = $prevKpis[$m['key']] ?? null;
        if (!empty($m['nullable']) && $curRaw === null && $prevRaw === null) {
            continue;
        }

        $cur = (float) ($curRaw ?? 0);
        $prev = (float) ($prevRaw ?? 0);

        if (!empty($m['pct'])) {
            $curDisplay = $curRaw === null ? 'N/A' : number_format($cur, 1) . '%';
            $prevDisplay = $prevRaw === null ? 'N/A' : number_format($prev, 1) . '%';
        } else {
            $curDisplay = number_format($cur, 0);
            $prevDisplay = number_format($prev, 0);
        }

        $rows[] = [
            'metric' => $m['label'],
            'current_display' => $curDisplay,
            'previous_display' => $prevDisplay,
            'change' => reportDomainFleetChangeLabel($cur, $prev, !empty($m['pct'])),
            'current_period' => $curPeriod,
            'previous_period' => $prevPeriod,
            'current' => $cur,
            'previous' => $prev,
        ];
    }

    return $rows;
}

function reportDomainFleetTripStatusBreakdown(PDO $pdo, array $filters): array
{
    if (!tableExists('delivery_trips', $pdo)) {
        return [];
    }

    try {
        $dateExpr = reportDomainFleetTripDateExpr($pdo);
        $sql = "SELECT COALESCE(NULLIF(TRIM(status), ''), 'unknown') AS status,
                       COUNT(*) AS count
                FROM delivery_trips
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        reportEngineApplySqlFilters($sql, $params, $filters, [
            'driver_id' => 'driver_id',
            'trip_status' => 'status',
            'vehicle' => 'vehicle_id',
        ]);
        $sql .= ' GROUP BY status ORDER BY count DESC, status ASC';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) ($r['count'] ?? 0);
    }

    $out = [];
    foreach ($rows as $r) {
        $count = (int) ($r['count'] ?? 0);
        $out[] = [
            'status' => (string) ($r['status'] ?? 'unknown'),
            'count' => $count,
            'pct' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
        ];
    }

    return $out;
}

function reportDomainFleetOrderStatusBreakdown(PDO $pdo, array $filters): array
{
    if (!tableExists('delivery_orders', $pdo)) {
        return [];
    }

    try {
        $dateCol = columnExists('delivery_orders', 'created_at', $pdo) ? 'created_at' : 'updated_at';
        $sql = "SELECT COALESCE(NULLIF(TRIM(status), ''), 'unknown') AS status,
                       COUNT(*) AS count
                FROM delivery_orders
                WHERE DATE({$dateCol}) BETWEEN ? AND ?";
        $params = [$filters['start_date'], $filters['end_date']];
        if (!empty($filters['driver_id']) && columnExists('delivery_orders', 'requested_driver_id', $pdo)) {
            $sql .= ' AND requested_driver_id = ?';
            $params[] = (int) $filters['driver_id'];
        }
        $sql .= ' GROUP BY status ORDER BY count DESC, status ASC';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) ($r['count'] ?? 0);
    }

    $out = [];
    foreach ($rows as $r) {
        $count = (int) ($r['count'] ?? 0);
        $out[] = [
            'status' => (string) ($r['status'] ?? 'unknown'),
            'count' => $count,
            'pct' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
        ];
    }

    return $out;
}

function reportDomainFleetOverdueOrders(PDO $pdo, array $filters, int $limit = 20): array
{
    if (!tableExists('delivery_orders', $pdo) || !columnExists('delivery_orders', 'delivery_deadline', $pdo)) {
        return [];
    }

    try {
        $closed = "'delivered','completed','closed','rejected'";
        $driverJoin = '';
        $driverSelect = "'' AS driver_name";
        if (tableExists('users', $pdo) && columnExists('delivery_orders', 'requested_driver_id', $pdo)) {
            $driverJoin = 'LEFT JOIN users u ON u.id = o.requested_driver_id';
            $driverSelect = 'COALESCE(u.full_name, \'\') AS driver_name';
        }

        $dateCol = columnExists('delivery_orders', 'created_at', $pdo) ? 'o.created_at' : 'o.delivery_deadline';

        $sql = "SELECT o.id,
                       o.client_name,
                       o.status,
                       o.delivery_deadline,
                       {$driverSelect}
                FROM delivery_orders o
                {$driverJoin}
                WHERE LOWER(TRIM(COALESCE(o.status, ''))) NOT IN ({$closed})
                  AND o.delivery_deadline IS NOT NULL
                  AND TRIM(o.delivery_deadline) <> ''
                  AND o.delivery_deadline < NOW()
                  AND (
                        DATE({$dateCol}) BETWEEN ? AND ?
                        OR DATE(o.delivery_deadline) BETWEEN ? AND ?
                      )";
        $params = [
            $filters['start_date'],
            $filters['end_date'],
            $filters['start_date'],
            $filters['end_date'],
        ];

        if (!empty($filters['driver_id']) && columnExists('delivery_orders', 'requested_driver_id', $pdo)) {
            $sql .= ' AND o.requested_driver_id = ?';
            $params[] = (int) $filters['driver_id'];
        }

        $sql .= ' ORDER BY o.delivery_deadline ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function reportDomainFleetOverdueOrdersCount(PDO $pdo, array $filters): int
{
    return count(reportDomainFleetOverdueOrders($pdo, $filters, 0));
}

function reportDomainFleetDeliveryNotesCount(PDO $pdo, array $filters): int
{
    if (!tableExists('delivery_notes', $pdo)) {
        return 0;
    }

    try {
        $hasDeliveryDate = columnExists('delivery_notes', 'delivery_date', $pdo);
        $hasCreated = columnExists('delivery_notes', 'created_at', $pdo);
        if ($hasDeliveryDate && $hasCreated) {
            $dateExpr = 'COALESCE(delivery_date, created_at)';
        } elseif ($hasDeliveryDate) {
            $dateExpr = 'delivery_date';
        } elseif ($hasCreated) {
            $dateExpr = 'created_at';
        } else {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM delivery_notes
                WHERE DATE({$dateExpr}) BETWEEN ? AND ?";
        $st = $pdo->prepare($sql);
        $st->execute([$filters['start_date'], $filters['end_date']]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function reportDomainFleetKeyActivities(PDO $pdo, array $filters, array $kpis): array
{
    $activities = [];
    $period = salesReportsFormatPeriod(
        (string) ($filters['start_date'] ?? ''),
        (string) ($filters['end_date'] ?? '')
    );

    $trips = (int) ($kpis['total_trips'] ?? 0);
    $completedTrips = (int) ($kpis['completed_trips'] ?? 0);
    if ($trips > 0) {
        $activities[] = [
            'activity' => 'Trips recorded',
            'detail' => number_format($trips) . ' trip(s) ('
                . number_format($completedTrips) . ' completed) during ' . $period . '.',
            'metric' => $trips,
        ];
    }

    $deliveries = (int) ($kpis['total_deliveries'] ?? 0);
    $completedDeliveries = (int) ($kpis['completed_deliveries'] ?? 0);
    if ($deliveries > 0) {
        $activities[] = [
            'activity' => 'Deliveries processed',
            'detail' => number_format($deliveries) . ' delivery order(s) ('
                . number_format($completedDeliveries) . ' completed) during ' . $period . '.',
            'metric' => $deliveries,
        ];
    }

    $notes = (int) ($kpis['delivery_notes_count'] ?? 0);
    if ($notes <= 0 && tableExists('delivery_notes', $pdo)) {
        try {
            $notes = reportDomainFleetDeliveryNotesCount($pdo, $filters);
        } catch (Throwable $e) {
            $notes = 0;
        }
    }
    if ($notes > 0) {
        $activities[] = [
            'activity' => 'Delivery notes issued',
            'detail' => number_format($notes) . ' delivery note(s) dated in ' . $period . '.',
            'metric' => $notes,
        ];
    }

    $overdue = (int) ($kpis['overdue_orders'] ?? 0);
    if ($overdue > 0) {
        $activities[] = [
            'activity' => 'Overdue delivery orders',
            'detail' => number_format($overdue) . ' open order(s) past their delivery deadline.',
            'metric' => $overdue,
        ];
    }

    $open = (int) ($kpis['open_orders'] ?? 0);
    if ($open > 0) {
        $activities[] = [
            'activity' => 'Open delivery orders',
            'detail' => number_format($open) . ' order(s) still open (not delivered, completed, closed, or rejected).',
            'metric' => $open,
        ];
    }

    return $activities;
}

function reportDomainFleetChallengesFromDataHtml(array $kpis, array $exceptions, array $overdue): string
{
    $items = [];

    $totalTrips = (int) ($kpis['total_trips'] ?? 0);
    $completedTrips = (int) ($kpis['completed_trips'] ?? 0);
    $openTrips = max(0, $totalTrips - $completedTrips);
    if ($openTrips > 0) {
        $items[] = '<li><strong>Open trips:</strong> '
            . number_format($openTrips) . ' of ' . number_format($totalTrips)
            . ' trip(s) are not marked completed.</li>';
    }

    $overdueCount = (int) ($kpis['overdue_orders'] ?? 0);
    if ($overdueCount <= 0 && is_array($overdue)) {
        $overdueCount = count($overdue);
    }
    if ($overdueCount > 0) {
        $items[] = '<li><strong>Overdue orders:</strong> '
            . number_format($overdueCount) . ' open order(s) are past their delivery deadline.</li>';
        if (is_array($overdue) && $overdue !== []) {
            $examples = [];
            foreach (array_slice($overdue, 0, 3) as $row) {
                $client = htmlspecialchars((string) ($row['client_name'] ?? 'Order'), ENT_QUOTES, 'UTF-8');
                $deadline = htmlspecialchars(substr((string) ($row['delivery_deadline'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8');
                $examples[] = $client . ($deadline !== '' ? ' (deadline ' . $deadline . ')' : '');
            }
            $examples = array_filter($examples);
            if ($examples !== []) {
                $items[] = '<li><strong>Overdue examples:</strong> ' . implode('; ', $examples) . '.</li>';
            }
        }
    }

    $openOrders = (int) ($kpis['open_orders'] ?? 0);
    if ($openOrders > 0) {
        $items[] = '<li><strong>Open orders:</strong> '
            . number_format($openOrders) . ' delivery order(s) remain open.</li>';
    }

    $pending = (int) ($kpis['pending_orders'] ?? $kpis['pending_count'] ?? 0);
    if ($pending > 0) {
        $items[] = '<li><strong>Pending orders:</strong> '
            . number_format($pending) . ' order(s) are still pending.</li>';
    }

    $rejected = (int) ($kpis['rejected_orders'] ?? $kpis['rejected_count'] ?? 0);
    if ($rejected > 0) {
        $items[] = '<li><strong>Rejected orders:</strong> '
            . number_format($rejected) . ' order(s) were rejected in the period.</li>';
    }

    if (is_array($exceptions)) {
        $skipTypes = ['open_trips', 'overdue_orders', 'delivery_completion'];
        foreach ($exceptions as $ex) {
            $type = (string) ($ex['type'] ?? '');
            if (in_array($type, $skipTypes, true)) {
                continue;
            }
            $msg = htmlspecialchars((string) ($ex['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($msg !== '') {
                $label = $type === 'on_time_performance' ? 'On-time performance' : 'Exception';
                $items[] = '<li><strong>' . $label . ':</strong> ' . $msg . '</li>';
            }
        }
    }

    $items = array_values(array_unique($items));

    if ($items === []) {
        return '<p>No open trips, overdue orders, pending, or rejected counts were flagged from ERP data for this period.</p>';
    }

    return '<p>Operational challenges identified from fleet ERP data:</p><ul>'
        . implode('', $items) . '</ul>';
}

function reportDomainFleetActivitiesProseHtml(array $activities): string
{
    if ($activities === []) {
        return '<p>No trip, delivery, delivery-note, open-order, or overdue activity counts were available to summarize for this period.</p>';
    }

    return '<p>Key fleet activities for the period are summarized below from ERP trip, delivery, and delivery-note counts.</p>';
}

function reportDomainFleetComparisonProseHtml(
    array $kpis,
    array $prevKpis,
    array $filters,
    array $prevFilters
): string {
    $curPeriod = htmlspecialchars(
        salesReportsFormatPeriod(
            (string) ($filters['start_date'] ?? ''),
            (string) ($filters['end_date'] ?? '')
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    $prevPeriod = htmlspecialchars(
        salesReportsFormatPeriod(
            (string) ($prevFilters['start_date'] ?? ''),
            (string) ($prevFilters['end_date'] ?? '')
        ),
        ENT_QUOTES,
        'UTF-8'
    );

    $tripChange = reportDomainFleetChangeLabel(
        (float) ($kpis['total_trips'] ?? 0),
        (float) ($prevKpis['total_trips'] ?? 0)
    );
    $deliveryChange = reportDomainFleetChangeLabel(
        (float) ($kpis['total_deliveries'] ?? 0),
        (float) ($prevKpis['total_deliveries'] ?? 0)
    );
    $completedChange = reportDomainFleetChangeLabel(
        (float) ($kpis['completed_deliveries'] ?? 0),
        (float) ($prevKpis['completed_deliveries'] ?? 0)
    );

    $parts = [
        'trips changed by ' . htmlspecialchars($tripChange, ENT_QUOTES, 'UTF-8'),
        'deliveries by ' . htmlspecialchars($deliveryChange, ENT_QUOTES, 'UTF-8'),
        'completed deliveries by ' . htmlspecialchars($completedChange, ENT_QUOTES, 'UTF-8'),
    ];

    if (($kpis['on_time_rate_pct'] ?? null) !== null || ($prevKpis['on_time_rate_pct'] ?? null) !== null) {
        $onTimeChange = reportDomainFleetChangeLabel(
            (float) ($kpis['on_time_rate_pct'] ?? 0),
            (float) ($prevKpis['on_time_rate_pct'] ?? 0),
            true
        );
        $parts[] = 'on-time rate by ' . htmlspecialchars($onTimeChange, ENT_QUOTES, 'UTF-8');
    }

    return '<p>Comparing ' . $curPeriod . ' with the prior period (' . $prevPeriod . '): '
        . implode(', ', $parts)
        . '. Detailed metric comparisons are available in the period comparison table.</p>';
}

function reportDomainFleetStatusProseHtml(
    ?array $tripBreakdown,
    ?array $orderBreakdown,
    array $filters = []
): string {
    $period = htmlspecialchars(
        salesReportsFormatPeriod(
            (string) ($filters['start_date'] ?? ''),
            (string) ($filters['end_date'] ?? '')
        ),
        ENT_QUOTES,
        'UTF-8'
    );
    if ($period === '') {
        $period = 'the selected period';
    }

    $formatTop = static function (array $rows, int $limit = 3): string {
        if ($rows === []) {
            return '';
        }
        $bits = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $status = htmlspecialchars(
                reportDomainFleetStatusLabel((string) ($row['status'] ?? 'unknown')),
                ENT_QUOTES,
                'UTF-8'
            );
            $count = number_format((int) ($row['count'] ?? 0));
            $pct = isset($row['pct']) ? ' (' . number_format((float) $row['pct'], 1) . '%)' : '';
            $bits[] = $status . ': ' . $count . $pct;
        }

        return implode('; ', $bits);
    };

    $html = '';
    $showTrips = $tripBreakdown !== null;
    $showOrders = $orderBreakdown !== null;

    if (!$showTrips && !$showOrders) {
        return '<p>No trip or delivery-order status counts were available for ' . $period . '.</p>';
    }

    if ($showTrips) {
        if ($tripBreakdown === []) {
            $html .= '<p>No trip status records were found for ' . $period . '.</p>';
        } else {
            $html .= '<p>Trip status breakdown for ' . $period . ': '
                . $formatTop($tripBreakdown) . '.</p>';
        }
    }

    if ($showOrders) {
        if ($orderBreakdown === []) {
            $html .= '<p>No delivery-order status records were found for ' . $period . '.</p>';
        } else {
            $html .= '<p>Delivery order status breakdown for ' . $period . ': '
                . $formatTop($orderBreakdown) . '.</p>';
        }
    }

    return $html;
}

function reportDomainFleetStatusLabel(string $status): string
{
    $raw = strtolower(trim(str_replace(['-', ' '], '_', $status)));
    $map = [
        'in_transit' => 'In transit',
        'completed' => 'Completed',
        'planned' => 'Planned',
        'scheduled' => 'Scheduled',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected',
        'pending' => 'Pending',
        'request_pending' => 'Request pending',
        'requested' => 'Requested',
        'closed' => 'Closed',
        'done' => 'Done',
        'unknown' => 'Unknown',
    ];

    if (isset($map[$raw])) {
        return $map[$raw];
    }

    return ucwords(str_replace('_', ' ', $status));
}
