<?php

declare(strict_types=1);

function deliveries_module_query(): string
{
    $qs = 'module=deliveries';
    $slug = function_exists('getRequestedCompanySlug') ? strtolower(trim(getRequestedCompanySlug())) : '';
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }
    if ($slug !== '') {
        $qs .= '&company_slug=' . rawurlencode($slug);
    }
    return $qs;
}

function deliveries_module_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $slug = function_exists('getRequestedCompanySlug') ? strtolower(trim(getRequestedCompanySlug())) : '';
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }

    if ($slug !== '' && function_exists('company_url')) {
        $base = company_url($relativePath, $slug);
    } elseif (function_exists('app_url')) {
        $base = app_url('/' . $relativePath);
    } else {
        $base = '/' . $relativePath;
    }

    return $base . (strpos($base, '?') !== false ? '&' : '?') . deliveries_module_query();
}

function deliveries_resolve_public_path(?string $path): string
{
    if ($path === null || trim($path) === '') {
        return '';
    }
    $normalized = ltrim(str_replace('\\', '/', str_replace('../', '', $path)), '/');
    if (function_exists('app_url')) {
        return (string) app_url('/' . $normalized);
    }
    return '/' . $normalized;
}

/** Full shareable URL (scheme + host + path). Avoids broken links like http://public_html/... */
function deliveries_resolve_absolute_url(?string $path): string
{
    if ($path === null || trim($path) === '') {
        return '';
    }
    $relative = deliveries_resolve_public_path($path);
    if ($relative === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . (strpos($relative, '/') === 0 ? $relative : '/' . $relative);
}

/**
 * Delivery list type: invoiced orders are office trips; non-invoiced are dispatch.
 */
function deliveries_order_delivery_type(array $row): string
{
    $invoiceId = (int) ($row['sales_invoice_id'] ?? 0);
    $invoiceRef = trim((string) ($row['invoice_ref'] ?? ''));
    if ($invoiceId > 0 || $invoiceRef !== '') {
        return 'Office Trip';
    }

    return 'Dispatch';
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_dashboard_payload(PDO $pdo, array $query = []): array
{
    $activeTrips = 0;
    $pending = 0;
    $exceptions = 0;
    $trips = [];
    $orders = [];

    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }
    require_once __DIR__ . '/delivery-note-invoice.php';
    if (function_exists('deliveries_ensure_sales_invoice_column')) {
        deliveries_ensure_sales_invoice_column($pdo);
    }

    try {
        $activeTrips = (int) $pdo->query(
            "SELECT COUNT(*) FROM delivery_trips WHERE status IN ('loading', 'in_transit')"
        )->fetchColumn();
    } catch (Throwable $e) {
        $activeTrips = 0;
    }

    try {
        $pending = (int) $pdo->query(
            "SELECT COUNT(*) FROM delivery_orders WHERE status IN ('request_pending', 'accepted', 'pending')"
        )->fetchColumn();
    } catch (Throwable $e) {
        $pending = 0;
    }

    try {
        $exceptions = (int) $pdo->query("
            SELECT COUNT(*) FROM delivery_orders
            WHERE status IN ('returned', 'failed')
               OR id IN (SELECT delivery_order_id FROM delivery_items WHERE status = 'rejected')
        ")->fetchColumn();
    } catch (Throwable $e) {
        try {
            $exceptions = (int) $pdo->query(
                "SELECT COUNT(*) FROM delivery_orders WHERE status IN ('returned', 'failed')"
            )->fetchColumn();
        } catch (Throwable $e2) {
            $exceptions = 0;
        }
    }

    try {
        $stmt = $pdo->query("SELECT t.*,
            (SELECT COUNT(*) FROM delivery_orders WHERE trip_id = t.id) as stop_count,
            (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(o.delivery_address), '') ORDER BY o.id SEPARATOR ', ')
             FROM delivery_orders o WHERE o.trip_id = t.id) as destinations,
            u.full_name as driver_name
            FROM delivery_trips t
            LEFT JOIN users u ON t.driver_id = u.id
            ORDER BY t.created_at DESC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $trips[] = [
                'id' => (int) ($row['id'] ?? 0),
                'trip_ref' => (string) ($row['trip_ref'] ?? ''),
                'driver_name' => (string) ($row['driver_name'] ?? ''),
                'vehicle_id' => (string) ($row['vehicle_id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'stop_count' => (int) ($row['stop_count'] ?? 0),
                'destinations' => (string) ($row['destinations'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $trips = [];
    }

    try {
        $stmtOrders = $pdo->query("
            SELECT o.id, o.client_name, o.client_phone, o.delivery_address, o.invoice_ref,
                   o.sales_invoice_id, o.status, o.created_at, o.package_description, o.receipt_file,
                   dn.note_number AS delivery_number
            FROM delivery_orders o
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            ORDER BY o.created_at DESC
            LIMIT 200
        ");
        $orderRows = $stmtOrders ? $stmtOrders->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($orderRows as $row) {
            $receiptPath = trim((string) ($row['receipt_file'] ?? ''));
            $deliveryNumber = trim((string) ($row['delivery_number'] ?? ''));
            if ($deliveryNumber === '') {
                $deliveryNumber = '#' . (int) ($row['id'] ?? 0);
            }
            $orders[] = [
                'id' => (int) ($row['id'] ?? 0),
                'client_name' => (string) ($row['client_name'] ?? ''),
                'client_phone' => (string) ($row['client_phone'] ?? ''),
                'delivery_address' => (string) ($row['delivery_address'] ?? ''),
                'invoice_ref' => (string) ($row['invoice_ref'] ?? ''),
                'delivery_type' => deliveries_order_delivery_type($row),
                'delivery_number' => $deliveryNumber,
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'description' => (string) ($row['package_description'] ?? ''),
                'receipt_file' => $receiptPath,
                'receipt_name' => $receiptPath !== '' ? basename($receiptPath) : '',
                'receipt_url' => $receiptPath !== '' ? deliveries_resolve_public_path($receiptPath) : '',
            ];
        }
    } catch (Throwable $e) {
        $orders = [];
    }

    $moduleQs = deliveries_module_query();
    $modulesUrl = function_exists('company_url') && function_exists('getRequestedCompanySlug')
        ? company_url('select-module', getRequestedCompanySlug() ?: null) . '?' . $moduleQs
        : (function_exists('app_url') ? (string) app_url('/select-module.php') . '?' . $moduleQs : '/select-module.php?' . $moduleQs);
    $companyName = defined('COMPANY_NAME') ? (string) COMPANY_NAME : '';

    $flash = null;
    if (isset($query['success']) && $query['success'] === 'trip_deleted') {
        $flash = ['type' => 'success', 'message' => 'Trip deleted successfully.'];
    } elseif (!empty($query['error'])) {
        $flash = ['type' => 'error', 'message' => (string) $query['error']];
    }

    $stats = [
        'activeTrips' => $activeTrips,
        'pending' => $pending,
        'exceptions' => $exceptions,
    ];

    $traceOrderRows = [];
    try {
        $stmtTraceOrders = $pdo->query("
            SELECT o.id, o.client_name, o.client_phone, o.delivery_address, o.status, o.created_at,
                   dn.note_number AS delivery_number
            FROM delivery_orders o
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            ORDER BY o.created_at DESC
        ");
        $traceOrderRows = $stmtTraceOrders ? ($stmtTraceOrders->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $traceOrderRows = [];
    }

    $kpiTraces = deliveries_build_dashboard_kpi_traces($trips, $traceOrderRows, $stats);

    return [
        'ok' => true,
        'data' => [
            'stats' => $stats,
            'trips' => $trips,
            'orders' => $orders,
            'kpiTraces' => $kpiTraces,
            'isAdmin' => function_exists('isAdmin') ? isAdmin() : false,
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'companyName' => $companyName,
            'todayLabel' => date('l, d M Y'),
            'urls' => [
                'modules' => $modulesUrl,
                'dashboard' => deliveries_module_url('deliveries/index'),
                'trips' => deliveries_module_url('deliveries/trips.php'),
                'createDelivery' => deliveries_module_url('deliveries/create_delivery.php'),
                'viewTrip' => deliveries_module_url('deliveries/view_trip.php'),
                'orderDetails' => deliveries_module_url('deliveries/order_details.php'),
            ],
            'flash' => $flash,
        ],
    ];
}

/**
 * @param list<string> $statuses Empty = all statuses.
 * @return list<array{id:int,deliveryNumber:string,clientName:string,clientPhone:string,destination:string,status:string,createdAt:string}>
 */
function deliveries_map_my_delivery_trace_items(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        $deliveryNumber = trim((string) ($row['delivery_number'] ?? ''));
        if ($deliveryNumber === '') {
            $deliveryNumber = '#' . (int) ($row['id'] ?? 0);
        }
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'deliveryNumber' => $deliveryNumber,
            'clientName' => (string) ($row['client_name'] ?? ''),
            'clientPhone' => (string) ($row['client_phone'] ?? ''),
            'destination' => (string) ($row['delivery_address'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
        ];
    }
    return $items;
}

/**
 * @param list<array{id:int,deliveryNumber:string,clientName:string,clientPhone:string,destination:string,status:string,createdAt:string}> $allItems
 * @return array<string, array{title:string,headline:string,source:string,method:string,criteria:list<array{label:string,value:string}>,confirmation:string,items:list<array>,footnote:string}>
 */
function deliveries_build_my_deliveries_kpi_traces(array $allItems, array $stats, int $userId, string $userLabel): array
{
    $baseCriteria = [
        ['label' => 'Table', 'value' => 'delivery_orders'],
        ['label' => 'Your account', 'value' => $userLabel !== '' ? $userLabel : ('User #' . $userId)],
        ['label' => 'Scope', 'value' => 'created_by = you OR requested_driver_id = you'],
    ];

    $filterByStatus = static function (array $items, array $statuses): array {
        $allowed = array_flip(array_map('strtolower', $statuses));
        return array_values(array_filter($items, static function (array $item) use ($allowed): bool {
            return isset($allowed[strtolower((string) ($item['status'] ?? ''))]);
        }));
    };

    $countLabel = static function (int $count): string {
        return number_format($count);
    };

    $totalCount = (int) ($stats['total'] ?? count($allItems));
    $pendingItems = $filterByStatus($allItems, ['request_pending', 'accepted', 'pending']);
    $transitItems = $filterByStatus($allItems, ['in_transit', 'loading']);
    $deliveredItems = $filterByStatus($allItems, ['delivered', 'completed']);
    $exceptionItems = $filterByStatus($allItems, ['returned', 'failed']);

    return [
        'total' => [
            'title' => 'Total deliveries',
            'headline' => $countLabel($totalCount),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) of delivery orders linked to your user account.',
            'criteria' => $baseCriteria,
            'confirmation' => $totalCount === 0
                ? 'You have not recorded or been assigned any deliveries yet.'
                : ($totalCount === 1
                    ? '1 delivery is linked to your account as creator or requested driver.'
                    : $countLabel($totalCount) . ' deliveries are linked to your account as creator or requested driver.'),
            'items' => $allItems,
            'footnote' => '',
        ],
        'pending' => [
            'title' => 'Pending',
            'headline' => $countLabel((int) ($stats['pending'] ?? count($pendingItems))),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) where status is request_pending, accepted, or pending.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'request_pending, accepted, pending'],
            ]),
            'confirmation' => 'These deliveries are waiting to be dispatched or accepted.',
            'items' => $pendingItems,
            'footnote' => '',
        ],
        'inTransit' => [
            'title' => 'In transit',
            'headline' => $countLabel((int) ($stats['inTransit'] ?? count($transitItems))),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) where status is in_transit or loading.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'in_transit, loading'],
            ]),
            'confirmation' => 'These deliveries are currently loading or on the way to the client.',
            'items' => $transitItems,
            'footnote' => '',
        ],
        'delivered' => [
            'title' => 'Delivered',
            'headline' => $countLabel((int) ($stats['delivered'] ?? count($deliveredItems))),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) where status is delivered or completed.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'delivered, completed'],
            ]),
            'confirmation' => 'These deliveries were completed and signed off successfully.',
            'items' => $deliveredItems,
            'footnote' => '',
        ],
        'exceptions' => [
            'title' => 'Exceptions',
            'headline' => $countLabel((int) ($stats['exceptions'] ?? count($exceptionItems))),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) where status is returned or failed.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'returned, failed'],
            ]),
            'confirmation' => 'These deliveries could not be completed normally and need follow-up.',
            'items' => $exceptionItems,
            'footnote' => '',
        ],
    ];
}

/**
 * @param list<array{id:int,trip_ref:string,driver_name:string,vehicle_id:string,status:string,stop_count:int,destinations?:string,created_at:string}> $trips
 * @return list<array{id:int,deliveryNumber:string,clientName:string,clientPhone:string,destination:string,status:string,createdAt:string}>
 */
function deliveries_map_trip_trace_items(array $trips): array
{
    $items = [];
    foreach ($trips as $trip) {
        $tripRef = trim((string) ($trip['trip_ref'] ?? ''));
        if ($tripRef === '') {
            $tripRef = 'Trip #' . (int) ($trip['id'] ?? 0);
        }
        $destination = trim((string) ($trip['destinations'] ?? ''));
        if ($destination === '') {
            $destination = '-';
        }
        $items[] = [
            'id' => (int) ($trip['id'] ?? 0),
            'deliveryNumber' => $tripRef,
            'clientName' => (string) ($trip['driver_name'] ?? ''),
            'clientPhone' => '',
            'destination' => $destination,
            'status' => (string) ($trip['status'] ?? ''),
            'createdAt' => (string) ($trip['created_at'] ?? ''),
        ];
    }
    return $items;
}

/**
 * @param list<array{id:int,trip_ref:string,driver_name:string,vehicle_id:string,status:string,stop_count:int,destinations?:string,created_at:string}> $trips
 * @param list<array<string,mixed>> $orderRows
 * @param array{activeTrips:int,pending:int,exceptions:int} $stats
 * @return array<string, array{title:string,headline:string,source:string,method:string,criteria:list<array{label:string,value:string}>,confirmation:string,items:list<array>,footnote:string,itemsTitle?:string}>
 */
function deliveries_build_dashboard_kpi_traces(array $trips, array $orderRows, array $stats): array
{
    $baseCriteria = [
        ['label' => 'Scope', 'value' => 'All company deliveries and trips'],
    ];

    $filterByStatus = static function (array $items, array $statuses): array {
        $allowed = array_flip(array_map('strtolower', $statuses));
        return array_values(array_filter($items, static function (array $item) use ($allowed): bool {
            return isset($allowed[strtolower((string) ($item['status'] ?? ''))]);
        }));
    };

    $countLabel = static function (int $count): string {
        return number_format($count);
    };

    $orderItems = deliveries_map_my_delivery_trace_items($orderRows);
    $tripItems = deliveries_map_trip_trace_items($trips);
    $activeTripItems = $filterByStatus($tripItems, ['loading', 'in_transit']);
    $pendingItems = $filterByStatus($orderItems, ['request_pending', 'accepted', 'pending']);
    $exceptionItems = array_values(array_filter($orderItems, static function (array $item): bool {
        $status = strtolower((string) ($item['status'] ?? ''));
        return in_array($status, ['returned', 'failed', 'rejected'], true);
    }));

    return [
        'active' => [
            'title' => 'Active trips',
            'headline' => $countLabel((int) ($stats['activeTrips'] ?? count($activeTripItems))),
            'source' => 'delivery_trips',
            'method' => 'COUNT(*) where trip status is loading or in_transit.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'loading, in_transit'],
            ]),
            'confirmation' => 'These trips are currently loading or on the way.',
            'items' => $activeTripItems,
            'itemsTitle' => 'Contributing trips',
            'footnote' => '',
        ],
        'pending' => [
            'title' => 'Pending deliveries',
            'headline' => $countLabel((int) ($stats['pending'] ?? count($pendingItems))),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) where status is request_pending, accepted, or pending.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'request_pending, accepted, pending'],
            ]),
            'confirmation' => 'These deliveries are awaiting dispatch.',
            'items' => $pendingItems,
            'footnote' => '',
        ],
        'exceptions' => [
            'title' => 'Exceptions',
            'headline' => $countLabel((int) ($stats['exceptions'] ?? count($exceptionItems))),
            'source' => 'delivery_orders',
            'method' => 'COUNT(*) where status is returned or failed.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Status', 'value' => 'returned, failed'],
            ]),
            'confirmation' => 'These deliveries could not be completed normally and need follow-up.',
            'items' => $exceptionItems,
            'footnote' => '',
        ],
    ];
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_my_deliveries_payload(PDO $pdo, array $query = []): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }
    if (function_exists('deliveries_ensure_sales_invoice_column')) {
        require_once __DIR__ . '/delivery-note-invoice.php';
        deliveries_ensure_sales_invoice_column($pdo);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $orders = [];
    $stats = [
        'total' => 0,
        'pending' => 0,
        'inTransit' => 0,
        'delivered' => 0,
        'exceptions' => 0,
    ];

    $userScopeSql = 'o.created_by = ? OR o.requested_driver_id = ?';
    $userParams = [$userId, $userId];

    try {
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM delivery_orders o WHERE {$userScopeSql}");
        $stmtTotal->execute($userParams);
        $stats['total'] = (int) $stmtTotal->fetchColumn();

        $stmtPending = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_orders o
            WHERE ({$userScopeSql}) AND o.status IN ('request_pending', 'accepted', 'pending')
        ");
        $stmtPending->execute($userParams);
        $stats['pending'] = (int) $stmtPending->fetchColumn();

        $stmtTransit = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_orders o
            WHERE ({$userScopeSql}) AND o.status IN ('in_transit', 'loading')
        ");
        $stmtTransit->execute($userParams);
        $stats['inTransit'] = (int) $stmtTransit->fetchColumn();

        $stmtDelivered = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_orders o
            WHERE ({$userScopeSql}) AND o.status IN ('delivered', 'completed')
        ");
        $stmtDelivered->execute($userParams);
        $stats['delivered'] = (int) $stmtDelivered->fetchColumn();

        $stmtExceptions = $pdo->prepare("
            SELECT COUNT(*) FROM delivery_orders o
            WHERE ({$userScopeSql}) AND o.status IN ('returned', 'failed')
        ");
        $stmtExceptions->execute($userParams);
        $stats['exceptions'] = (int) $stmtExceptions->fetchColumn();
    } catch (Throwable $e) {
        // stats stay at zero
    }

    try {
        $stmt = $pdo->prepare("
            SELECT o.id, o.client_name, o.client_phone, o.delivery_address, o.invoice_ref,
                   o.sales_invoice_id, o.status, o.created_at, o.package_description, o.receipt_file,
                   dn.note_number AS delivery_number
            FROM delivery_orders o
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            WHERE o.created_by = ? OR o.requested_driver_id = ?
            ORDER BY o.created_at DESC
            LIMIT 200
        ");
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $receiptPath = trim((string) ($row['receipt_file'] ?? ''));
            $deliveryNumber = trim((string) ($row['delivery_number'] ?? ''));
            if ($deliveryNumber === '') {
                $deliveryNumber = '#' . (int) ($row['id'] ?? 0);
            }
            $orders[] = [
                'id' => (int) ($row['id'] ?? 0),
                'client_name' => (string) ($row['client_name'] ?? ''),
                'client_phone' => (string) ($row['client_phone'] ?? ''),
                'delivery_address' => (string) ($row['delivery_address'] ?? ''),
                'invoice_ref' => (string) ($row['invoice_ref'] ?? ''),
                'delivery_type' => deliveries_order_delivery_type($row),
                'delivery_number' => $deliveryNumber,
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'description' => (string) ($row['package_description'] ?? ''),
                'receipt_file' => $receiptPath,
                'receipt_name' => $receiptPath !== '' ? basename($receiptPath) : '',
                'receipt_url' => $receiptPath !== '' ? deliveries_resolve_public_path($receiptPath) : '',
            ];
        }
    } catch (Throwable $e) {
        $orders = [];
    }

    $traceRows = [];
    try {
        $stmtTrace = $pdo->prepare("
            SELECT o.id, o.client_name, o.client_phone, o.delivery_address, o.status, o.created_at,
                   dn.note_number AS delivery_number
            FROM delivery_orders o
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            WHERE o.created_by = ? OR o.requested_driver_id = ?
            ORDER BY o.created_at DESC
        ");
        $stmtTrace->execute([$userId, $userId]);
        $traceRows = $stmtTrace->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $traceRows = [];
    }

    $userLabel = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $traceItems = deliveries_map_my_delivery_trace_items($traceRows);
    $kpiTraces = deliveries_build_my_deliveries_kpi_traces($traceItems, $stats, $userId, $userLabel);

    $highlightId = isset($query['highlight']) ? (int) $query['highlight'] : 0;
    $flash = null;
    if (isset($query['created']) && (string) $query['created'] === '1' && $highlightId > 0) {
        $flash = ['type' => 'success', 'message' => "Delivery #{$highlightId} created successfully."];
    }

    return [
        'ok' => true,
        'data' => [
            'orders' => $orders,
            'stats' => $stats,
            'kpiTraces' => $kpiTraces,
            'highlightId' => $highlightId,
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'urls' => [
                'dashboard' => deliveries_module_url('deliveries/index'),
                'createDelivery' => deliveries_module_url('deliveries/create_delivery.php'),
                'myDeliveries' => deliveries_module_url('deliveries/my_deliveries.php'),
                'orderDetails' => deliveries_module_url('deliveries/order_details.php'),
            ],
            'flash' => $flash,
        ],
    ];
}

/**
 * @param list<array<string,mixed>> $notes
 * @return list<array{id:int,deliveryNumber:string,clientName:string,clientPhone:string,destination:string,status:string,createdAt:string,recordedAt:string,creatorName:string}>
 */
function deliveries_map_delivery_note_trace_items(array $notes): array
{
    $items = [];
    foreach ($notes as $note) {
        $noteNumber = trim((string) ($note['note_number'] ?? ''));
        if ($noteNumber === '') {
            $noteNumber = '#' . (int) ($note['id'] ?? 0);
        }
        $itemCount = (int) ($note['item_count'] ?? 0);
        $items[] = [
            'id' => (int) ($note['id'] ?? 0),
            'deliveryNumber' => $noteNumber,
            'clientName' => (string) ($note['customer_name'] ?? ''),
            'clientPhone' => (string) ($note['customer_phone'] ?? ''),
            'destination' => (string) ($note['delivery_address'] ?? ''),
            'status' => $itemCount > 0 ? $itemCount . ' item' . ($itemCount === 1 ? '' : 's') : '-',
            'createdAt' => (string) ($note['delivery_date'] ?? $note['created_at'] ?? ''),
            'recordedAt' => (string) ($note['created_at'] ?? ''),
            'creatorName' => (string) ($note['creator_name'] ?? ''),
        ];
    }
    return $items;
}

/**
 * @param list<array<string,mixed>> $notes
 * @return list<array{id:int,deliveryNumber:string,clientName:string,clientPhone:string,destination:string,status:string,createdAt:string}>
 */
function deliveries_map_customer_trace_items(array $notes): array
{
    $groups = [];
    foreach ($notes as $note) {
        $customer = trim((string) ($note['customer_name'] ?? ''));
        if ($customer === '') {
            continue;
        }
        $key = strtolower($customer);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'id' => count($groups) + 1,
                'deliveryNumber' => '',
                'clientName' => $customer,
                'clientPhone' => (string) ($note['customer_phone'] ?? ''),
                'destination' => (string) ($note['delivery_address'] ?? ''),
                'status' => '0 notes',
                'createdAt' => (string) ($note['delivery_date'] ?? $note['created_at'] ?? ''),
                'count' => 0,
            ];
        }
        $groups[$key]['count']++;
        $groups[$key]['status'] = $groups[$key]['count'] . ' note' . ($groups[$key]['count'] === 1 ? '' : 's');
        $recordedAt = (string) ($note['created_at'] ?? '');
        if ($recordedAt !== '' && ($groups[$key]['createdAt'] === '' || $recordedAt > $groups[$key]['createdAt'])) {
            $groups[$key]['createdAt'] = (string) ($note['delivery_date'] ?? $recordedAt);
        }
        $address = trim((string) ($note['delivery_address'] ?? ''));
        if ($address !== '') {
            $groups[$key]['destination'] = $address;
        }
    }

    return array_values($groups);
}

/**
 * @param list<array<string,mixed>> $notes
 * @param array{total:int,thisMonth:int,thisWeek:int,customers:int} $stats
 * @return array<string, array{title:string,headline:string,source:string,method:string,criteria:list<array{label:string,value:string}>,confirmation:string,items:list<array>,footnote:string,itemsTitle?:string,modalType?:string}>
 */
function deliveries_build_delivery_notes_kpi_traces(array $notes, array $stats): array
{
    $baseCriteria = [
        ['label' => 'Table', 'value' => 'delivery_notes'],
        ['label' => 'Scope', 'value' => 'All company delivery notes'],
    ];

    $countLabel = static function (int $count): string {
        return number_format($count);
    };

    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('-7 days'));
    $allItems = deliveries_map_delivery_note_trace_items($notes);

    $thisMonthItems = array_values(array_filter($allItems, static function (array $item) use ($monthStart): bool {
        $recordedAt = substr((string) ($item['recordedAt'] ?? ''), 0, 10);
        return $recordedAt !== '' && $recordedAt >= $monthStart;
    }));
    $thisWeekItems = array_values(array_filter($allItems, static function (array $item) use ($weekStart): bool {
        $recordedAt = substr((string) ($item['recordedAt'] ?? ''), 0, 10);
        return $recordedAt !== '' && $recordedAt >= $weekStart;
    }));
    $customerItems = deliveries_map_customer_trace_items($notes);

    return [
        'total' => [
            'title' => 'Total notes',
            'headline' => $countLabel((int) ($stats['total'] ?? count($allItems))),
            'source' => 'delivery_notes',
            'method' => 'COUNT(*) of all delivery notes in the company.',
            'criteria' => $baseCriteria,
            'confirmation' => 'These are all delivery notes recorded in the system.',
            'items' => $allItems,
            'itemsTitle' => 'Contributing notes',
            'modalType' => 'notes',
            'footnote' => '',
        ],
        'thisMonth' => [
            'title' => 'This month',
            'headline' => $countLabel((int) ($stats['thisMonth'] ?? count($thisMonthItems))),
            'source' => 'delivery_notes',
            'method' => 'COUNT(*) where created_at is in the current calendar month.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Created from', 'value' => $monthStart],
            ]),
            'confirmation' => 'These notes were created during the current month.',
            'items' => $thisMonthItems,
            'itemsTitle' => 'Contributing notes',
            'modalType' => 'notes',
            'footnote' => '',
        ],
        'thisWeek' => [
            'title' => 'This week',
            'headline' => $countLabel((int) ($stats['thisWeek'] ?? count($thisWeekItems))),
            'source' => 'delivery_notes',
            'method' => 'COUNT(*) where created_at is within the last 7 days.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Created from', 'value' => $weekStart],
            ]),
            'confirmation' => 'These notes were created in the last seven days.',
            'items' => $thisWeekItems,
            'itemsTitle' => 'Contributing notes',
            'modalType' => 'notes',
            'footnote' => '',
        ],
        'customers' => [
            'title' => 'Customers',
            'headline' => $countLabel((int) ($stats['customers'] ?? count($customerItems))),
            'source' => 'delivery_notes',
            'method' => 'COUNT(DISTINCT customer_name) across all delivery notes.',
            'criteria' => array_merge($baseCriteria, [
                ['label' => 'Field', 'value' => 'customer_name'],
            ]),
            'confirmation' => 'These are the unique customers with at least one delivery note.',
            'items' => $customerItems,
            'itemsTitle' => 'Contributing customers',
            'modalType' => 'customers',
            'footnote' => '',
        ],
    ];
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_delivery_notes_payload(PDO $pdo, array $query = []): array
{
    if (function_exists('ensureDeliveryNotesSchema')) {
        ensureDeliveryNotesSchema();
    }

    $notes = [];
    $stats = [
        'total' => 0,
        'thisMonth' => 0,
        'thisWeek' => 0,
        'customers' => 0,
    ];

    $customerSet = [];
    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('-7 days'));

    try {
        $stmt = $pdo->query("
            SELECT dn.*, u.full_name AS creator_name
            FROM delivery_notes dn
            JOIN users u ON dn.created_by = u.id
            ORDER BY dn.created_at DESC
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $items = json_decode((string) ($row['items_json'] ?? '[]'), true);
            $itemCount = is_array($items) ? count($items) : 0;
            $customer = trim((string) ($row['customer_name'] ?? ''));
            if ($customer !== '') {
                $customerSet[strtolower($customer)] = true;
            }

            $createdAt = (string) ($row['created_at'] ?? '');
            $createdDate = substr($createdAt, 0, 10);
            $stats['total']++;
            if ($createdDate >= $monthStart) {
                $stats['thisMonth']++;
            }
            if ($createdDate >= $weekStart) {
                $stats['thisWeek']++;
            }

            $notes[] = [
                'id' => (int) ($row['id'] ?? 0),
                'note_number' => (string) ($row['note_number'] ?? ''),
                'customer_name' => $customer,
                'customer_phone' => (string) ($row['customer_phone'] ?? $row['phone_number'] ?? ''),
                'delivery_address' => (string) ($row['delivery_address'] ?? ''),
                'delivery_date' => (string) ($row['delivery_date'] ?? ''),
                'item_count' => $itemCount,
                'creator_name' => (string) ($row['creator_name'] ?? ''),
                'created_at' => $createdAt,
            ];
        }
        $stats['customers'] = count($customerSet);
    } catch (Throwable $e) {
        $notes = [];
    }

    $moduleQs = deliveries_module_query();
    $modulesUrl = function_exists('company_url') && function_exists('getRequestedCompanySlug')
        ? company_url('select-module', getRequestedCompanySlug() ?: null) . '?' . $moduleQs
        : (function_exists('app_url') ? (string) app_url('/select-module.php') . '?' . $moduleQs : '/select-module.php?' . $moduleQs);

    $flash = null;
    if (isset($query['success']) && (string) $query['success'] === 'created') {
        $flash = ['type' => 'success', 'message' => 'Delivery note created successfully.'];
    }

    $kpiTraces = deliveries_build_delivery_notes_kpi_traces($notes, $stats);

    return [
        'ok' => true,
        'data' => [
            'notes' => $notes,
            'stats' => $stats,
            'kpiTraces' => $kpiTraces,
            'urls' => [
                'modules' => $modulesUrl,
                'dashboard' => deliveries_module_url('deliveries/index'),
                'myDeliveries' => deliveries_module_url('deliveries/my_deliveries.php'),
                'createNote' => deliveries_module_url('deliveries/create_delivery_note.php'),
                'viewNote' => deliveries_module_url('deliveries/view_delivery_note.php'),
            ],
            'flash' => $flash,
        ],
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{id:int,clientName:string,clientPhone:string,rating:int,feedback:string,completionTime:string,orderRef:string,tripRef:string,driverName:string}>
 */
function deliveries_map_review_trace_items(array $rows): array
{
    $items = [];
    foreach ($rows as $row) {
        $orderRef = trim((string) ($row['order_ref'] ?? $row['invoice_ref'] ?? ''));
        if ($orderRef === '') {
            $orderRef = '#' . (int) ($row['id'] ?? 0);
        }
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'clientName' => (string) ($row['client_name'] ?? ''),
            'clientPhone' => (string) ($row['client_phone'] ?? ''),
            'rating' => (int) ($row['rating'] ?? $row['customer_rating'] ?? 0),
            'feedback' => (string) ($row['feedback'] ?? $row['customer_feedback'] ?? ''),
            'completionTime' => (string) ($row['completion_time'] ?? ''),
            'orderRef' => $orderRef,
            'tripRef' => (string) ($row['trip_ref'] ?? ''),
            'driverName' => (string) ($row['driver_name'] ?? ''),
        ];
    }
    return $items;
}

/**
 * @param list<array<string,mixed>> $reviews
 * @param array{avgRating:float,totalReviews:int,positivePercent:int} $stats
 * @return array<string, array<string,mixed>>
 */
function deliveries_build_customer_reviews_kpi_traces(array $reviews, array $stats): array
{
    $allItems = deliveries_map_review_trace_items($reviews);
    $positiveItems = array_values(array_filter($allItems, static function (array $item): bool {
        return (int) ($item['rating'] ?? 0) >= 4;
    }));

    $countLabel = static function (int $count): string {
        return number_format($count);
    };

    $avgLabel = number_format((float) ($stats['avgRating'] ?? 0), 1);

    return [
        'avgRating' => [
            'title' => 'Average satisfaction',
            'headline' => $avgLabel,
            'confirmation' => 'Average star rating across all submitted customer reviews.',
            'items' => $allItems,
            'itemsTitle' => 'Contributing reviews',
            'modalType' => 'reviews',
        ],
        'totalReviews' => [
            'title' => 'Total reviews',
            'headline' => $countLabel((int) ($stats['totalReviews'] ?? count($allItems))),
            'confirmation' => 'All deliveries with a customer rating recorded.',
            'items' => $allItems,
            'itemsTitle' => 'Contributing reviews',
            'modalType' => 'reviews',
        ],
        'positive' => [
            'title' => 'Positive sentiment',
            'headline' => ((int) ($stats['positivePercent'] ?? 0)) . '%',
            'confirmation' => 'Share of reviews rated 4 stars or higher.',
            'items' => $positiveItems,
            'itemsTitle' => 'Contributing reviews',
            'modalType' => 'reviews',
        ],
    ];
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_customer_reviews_payload(PDO $pdo, array $query = []): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }

    $stats = [
        'avgRating' => 0.0,
        'totalReviews' => 0,
        'positivePercent' => 0,
    ];
    $reviews = [];

    try {
        $stmtStats = $pdo->query("
            SELECT
                AVG(customer_rating) AS avg_rating,
                COUNT(customer_rating) AS total_reviews,
                COUNT(CASE WHEN customer_rating >= 4 THEN 1 END) AS positive_feedback
            FROM delivery_orders
            WHERE customer_rating IS NOT NULL
        ");
        $rowStats = $stmtStats ? $stmtStats->fetch(PDO::FETCH_ASSOC) : false;
        if ($rowStats) {
            $stats['avgRating'] = round((float) ($rowStats['avg_rating'] ?? 0), 1);
            $stats['totalReviews'] = (int) ($rowStats['total_reviews'] ?? 0);
            $positive = (int) ($rowStats['positive_feedback'] ?? 0);
            $stats['positivePercent'] = $stats['totalReviews'] > 0
                ? (int) round(($positive / $stats['totalReviews']) * 100)
                : 0;
        }
    } catch (Throwable $e) {
        $stats = ['avgRating' => 0.0, 'totalReviews' => 0, 'positivePercent' => 0];
    }

    try {
        $stmtReviews = $pdo->query("
            SELECT o.id, o.client_name, o.client_phone, o.customer_rating, o.customer_feedback,
                   o.completion_time, o.invoice_ref, t.trip_ref, u.full_name AS driver_name,
                   dn.note_number AS delivery_number
            FROM delivery_orders o
            LEFT JOIN delivery_trips t ON o.trip_id = t.id
            LEFT JOIN users u ON t.driver_id = u.id
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            WHERE o.customer_rating IS NOT NULL
            ORDER BY o.completion_time DESC
            LIMIT 100
        ");
        $rows = $stmtReviews ? ($stmtReviews->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $orderRef = trim((string) ($row['invoice_ref'] ?? ''));
            if ($orderRef === '') {
                $orderRef = trim((string) ($row['delivery_number'] ?? ''));
            }
            if ($orderRef === '') {
                $orderRef = '#' . (int) ($row['id'] ?? 0);
            }
            $reviews[] = [
                'id' => (int) ($row['id'] ?? 0),
                'client_name' => (string) ($row['client_name'] ?? ''),
                'client_phone' => (string) ($row['client_phone'] ?? ''),
                'rating' => (int) ($row['customer_rating'] ?? 0),
                'feedback' => (string) ($row['customer_feedback'] ?? ''),
                'completion_time' => (string) ($row['completion_time'] ?? ''),
                'order_ref' => $orderRef,
                'trip_ref' => (string) ($row['trip_ref'] ?? ''),
                'driver_name' => (string) ($row['driver_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $reviews = [];
    }

    $moduleQs = deliveries_module_query();
    $modulesUrl = function_exists('company_url') && function_exists('getRequestedCompanySlug')
        ? company_url('select-module', getRequestedCompanySlug() ?: null) . '?' . $moduleQs
        : (function_exists('app_url') ? (string) app_url('/select-module.php') . '?' . $moduleQs : '/select-module.php?' . $moduleQs);

    $kpiTraces = deliveries_build_customer_reviews_kpi_traces($reviews, $stats);

    return [
        'ok' => true,
        'data' => [
            'reviews' => $reviews,
            'stats' => $stats,
            'kpiTraces' => $kpiTraces,
            'urls' => [
                'modules' => $modulesUrl,
                'dashboard' => deliveries_module_url('deliveries/index'),
                'myDeliveries' => deliveries_module_url('deliveries/my_deliveries.php'),
                'orderDetails' => deliveries_module_url('deliveries/order_details.php'),
            ],
        ],
    ];
}
