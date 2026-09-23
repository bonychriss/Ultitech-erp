<?php

declare(strict_types=1);

/**
 * Build window.__CV_CFG__ for the Create Voucher React app.
 *
 * @param array{
 *   payees?: list<array<string,mixed>>,
 *   users?: list<array<string,mixed>>,
 *   financeUsers?: list<array<string,mixed>>,
 *   salesOrders?: list<array<string,mixed>>,
 *   purchaseOrders?: list<array<string,mixed>>,
 *   flash?: array{title?:string,message?:string,variant?:string}|null,
 *   error?: string,
 *   module?: string,
 * } $data
 * @return array<string,mixed>
 */
function createVoucherBuildClientCfg(array $data = []): array
{
    $mapUserNames = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) ($r['full_name'] ?? ''));
            if ($name !== '') {
                $out[] = ['full_name' => $name];
            }
        }
        return $out;
    };

    $mapPayees = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'name' => (string) ($r['name'] ?? ''),
                'type' => (string) ($r['type'] ?? ''),
            ];
        }
        return $out;
    };

    $mapSalesOrders = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'order_number' => (string) ($r['order_number'] ?? ''),
                'customer_name' => (string) ($r['customer_name'] ?? ''),
                'salesperson_name' => (string) ($r['salesperson_name'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
            ];
        }
        return $out;
    };

    $mapPurchaseOrders = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'po_number' => (string) ($r['po_number'] ?? ''),
                'supplier_name' => (string) ($r['supplier_name'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
            ];
        }
        return $out;
    };

    $module = (string) ($data['module'] ?? ($_GET['module'] ?? 'voucher'));
    if ($module === '') {
        $module = 'voucher';
    }
    $moduleQs = '?module=' . rawurlencode($module);

    $cvPostUrl = function_exists('company_url')
        ? company_url('employee/create-voucher.php')
        : 'create-voucher.php';
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($qs !== '') {
        $cvPostUrl .= (str_contains($cvPostUrl, '?') ? '&' : '?') . $qs;
    } elseif (!str_contains($cvPostUrl, 'module=')) {
        $cvPostUrl .= $moduleQs;
    }

    $cvCancelUrl = function_exists('company_url')
        ? company_url('employee/my-vouchers.php')
        : 'my-vouchers.php';
    $cvCancelUrl .= (str_contains($cvCancelUrl, '?') ? '&' : '?') . 'module=' . rawurlencode($module);

    $canRestrict = false;
    if (function_exists('isAdmin') && function_exists('isFinance')) {
        $canRestrict = isAdmin() || isFinance();
    } elseif (function_exists('isAdmin')) {
        $canRestrict = isAdmin();
    }

    return [
        'postUrl' => $cvPostUrl,
        'cancelUrl' => $cvCancelUrl,
        'module' => $module,
        'preparedBy' => trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')),
        'today' => date('Y-m-d'),
        'canRestrict' => $canRestrict,
        'currencies' => ['TZS', 'USD', 'CNY'],
        'purposes' => [
            ['value' => 'general', 'label' => 'General Payment'],
            ['value' => 'stock_purchase', 'label' => 'Stock Purchase'],
        ],
        'paymentTypes' => ['Bank Transfer', 'Cash Payment', 'Cheque', 'Mobile Payment'],
        'budgetTypes' => [
            'Operational Expenses',
            'Procurement & Supplies',
            'Employee Costs',
            'Sales & Marketing',
            'Logistics & Delivery',
            'Administration & Management',
            'Projects & Capital Expenditure (CAPEX)',
            'Financial Obligations',
            'Tax & Compliance',
            'Others / Miscellaneous',
        ],
        'payees' => $mapPayees(is_array($data['payees'] ?? null) ? $data['payees'] : []),
        'users' => $mapUserNames(is_array($data['users'] ?? null) ? $data['users'] : []),
        'financeUsers' => $mapUserNames(is_array($data['financeUsers'] ?? null) ? $data['financeUsers'] : []),
        'salesOrders' => $mapSalesOrders(is_array($data['salesOrders'] ?? null) ? $data['salesOrders'] : []),
        'purchaseOrders' => $mapPurchaseOrders(is_array($data['purchaseOrders'] ?? null) ? $data['purchaseOrders'] : []),
        'poViewBaseUrl' => function_exists('app_url')
            ? app_url('/stock/modules/purchases/view_po.php')
            : '/stock/modules/purchases/view_po.php',
        'poDocumentUrl' => function_exists('app_url')
            ? app_url('/employee/create-voucher-ui/po-document.php')
            : 'create-voucher-ui/po-document.php',
        'flash' => $data['flash'] ?? null,
        'error' => (string) ($data['error'] ?? ''),
    ];
}
