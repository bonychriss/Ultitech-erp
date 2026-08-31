<?php

declare(strict_types=1);

require_once __DIR__ . '/load-data.php';

/**
 * @return list<array{id:int,invoice_number:string,customer_name:string,customer_phone:string,customer_address:string}>
 */
function deliveries_load_sales_invoices(): array
{
    $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!is_file($salesFunctions)) {
        return [];
    }
    require_once $salesFunctions;

    try {
        $salesDb = sales_pdo();
        if (
            !sales_connection_has_table($salesDb, 'invoices')
            || !sales_connection_has_table($salesDb, 'customers')
        ) {
            return [];
        }

        $sql = 'SELECT i.id, i.invoice_number, c.company_name AS customer_name,
                       c.phone AS customer_phone, c.address AS customer_address
                FROM invoices i
                INNER JOIN customers c ON i.customer_id = c.id
                WHERE i.status != \'cancelled\'';
        $params = [];
        if (function_exists('salesAppendCompanyScope')) {
            salesAppendCompanyScope($sql, $params, 'invoices', 'i');
        }
        $sql .= ' ORDER BY i.id DESC LIMIT 100';

        if ($params !== []) {
            $stmt = $salesDb->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $salesDb->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'invoice_number' => (string) ($row['invoice_number'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'customer_phone' => (string) ($row['customer_phone'] ?? ''),
                'customer_address' => (string) ($row['customer_address'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_load_create_payload(PDO $pdo): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }
    if (function_exists('ensureDeliveryNotesSchema')) {
        ensureDeliveryNotesSchema();
    }

    $drivers = [];
    try {
        $rows = $pdo->query("SELECT id, full_name FROM users WHERE department = 'Driver' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $drivers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'full_name' => (string) ($row['full_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $drivers = [];
    }

    $deliveryNotes = [];
    try {
        $rows = $pdo->query(
            'SELECT id, note_number, customer_name, delivery_address FROM delivery_notes ORDER BY created_at DESC LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $deliveryNotes[] = [
                'id' => (int) ($row['id'] ?? 0),
                'note_number' => (string) ($row['note_number'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'delivery_address' => (string) ($row['delivery_address'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $deliveryNotes = [];
    }

    $warehouses = ['Warehouse A', 'Main Store', 'Distribution Center'];
    $moduleQs = deliveries_module_query();
    $createDispatch = !empty($_GET['create_dispatch']);

    $dispatchDashboard = '';
    $dispatchHelpers = dirname(__DIR__, 2) . '/dispatch/dispatch-helpers.php';
    if ($createDispatch && is_file($dispatchHelpers)) {
        require_once $dispatchHelpers;
        if (function_exists('dispatch_module_url')) {
            $dispatchDashboard = dispatch_module_url('dispatch/index.php');
        }
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $department = trim((string) ($_SESSION['department'] ?? ''));
    $isDriver = strcasecmp($department, 'Driver') === 0;

    return [
        'ok' => true,
        'data' => [
            'drivers' => $drivers,
            'deliveryNotes' => $deliveryNotes,
            'warehouses' => $warehouses,
            'invoices' => deliveries_load_sales_invoices(),
            'currentUser' => [
                'id' => $userId,
                'fullName' => trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')),
                'department' => $department,
                'isDriver' => $isDriver,
            ],
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'createDispatch' => $createDispatch,
            'urls' => [
                'dashboard' => deliveries_module_url('deliveries/index'),
                'createDelivery' => deliveries_module_url('deliveries/create_delivery.php'),
                'createDeliveryNote' => deliveries_module_url('deliveries/create_delivery_note.php'),
                'myDeliveries' => deliveries_module_url('deliveries/my_deliveries.php'),
                'dispatchDashboard' => $dispatchDashboard,
            ],
            'moduleQuery' => $moduleQs,
            'routePricing' => [
                'baseFare' => 5000,
                'ratePerKm' => 1500,
                'minimumFare' => 8000,
                'currency' => 'TZS',
            ],
        ],
    ];
}
