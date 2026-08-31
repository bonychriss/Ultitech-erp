<?php

declare(strict_types=1);

function customerStatementDeskBootstrap(): void
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 2) . '/includes/config.php';
        require_once dirname(__DIR__, 2) . '/includes/functions.php';
        require_once dirname(__DIR__, 2) . '/includes/document_layouts.php';
        $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
        if (is_file($salesFunctions)) {
            require_once $salesFunctions;
        }
        $booted = true;
    }
}

function customerStatementDeskRequireAccess(): void
{
    customerStatementDeskBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireLogin();
}

function customer_statement_pdo(): PDO
{
    global $pdo;
    if (function_exists('sales_pdo')) {
        $salesDb = sales_pdo();
        if ($salesDb instanceof PDO) {
            return $salesDb;
        }
    }

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function customer_statement_web_base(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }

    return function_exists('app_url') ? app_url('/customer_statement') : '/customer_statement';
}

function customer_statement_module_query(): string
{
    $module = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
    return $module !== '' ? $module : 'sales';
}

function customer_statement_due_label(?string $dueDate): string
{
    if ($dueDate === null || $dueDate === '' || $dueDate === '0000-00-00') {
        return '-';
    }
    $due = strtotime($dueDate);
    if ($due === false) {
        return '-';
    }
    $today = strtotime('today');
    $diff = (int) round(($due - $today) / 86400);
    if ($diff > 1) {
        return $diff . ' days';
    }
    if ($diff === 1) {
        return '1 day';
    }
    if ($diff === 0) {
        return '0 days';
    }
    if ($diff === -1) {
        return '1 day ago';
    }

    return (string) abs($diff) . ' days ago';
}

function customer_statement_row_is_paid(array $r, bool $isOpening): bool
{
    if ($isOpening) {
        return false;
    }

    return (float) ($r['line_balance'] ?? 0) <= 0.009;
}

function customer_statement_fmt_date(?string $d): string
{
    if ($d === null || $d === '' || $d === '0000-00-00') {
        return '-';
    }
    $t = strtotime($d);

    return $t ? date('j M Y', $t) : '-';
}

/**
 * @return array<string, mixed>
 */
function customer_statement_opening_row(float $openingBalance): array
{
    return [
        'is_opening' => true,
        'invoice_date' => '',
        'due_date' => '',
        'invoice_date_fmt' => '-',
        'due_date_fmt' => '-',
        'due_relative' => '',
        'invoice_number' => 'Opening balance',
        'company_name' => '',
        'order_status' => '',
        'payment_status_label' => '',
        'total_amount' => 0.0,
        'amount_paid' => 0.0,
        'line_balance' => $openingBalance,
        'invoice_status' => '',
        'row_overdue' => false,
        'is_paid' => false,
    ];
}

/**
 * @return array{primary: string, secondary: string}
 */
function customer_statement_due_column_parts(array $r, bool $isOpening): array
{
    if ($isOpening) {
        return ['primary' => '-', 'secondary' => ''];
    }
    $rel = (string) ($r['due_relative'] ?? '-');
    if ($rel !== '-' && $rel !== '') {
        return ['primary' => $rel, 'secondary' => ''];
    }

    return ['primary' => '-', 'secondary' => ''];
}

/**
 * @param array<string, mixed> $query
 * @return array{customer_ids:list<int>,date_from:string,date_to:string,period:string}
 */
function customer_statement_parse_filters(array $query): array
{
    $customerIds = [];
    if (isset($query['customer_ids']) && is_array($query['customer_ids'])) {
        foreach ($query['customer_ids'] as $cid) {
            if (is_scalar($cid) && ctype_digit((string) $cid)) {
                $customerIds[] = (int) $cid;
            }
        }
    }
    if ($customerIds === []) {
        $single = (int) ($query['customer_id'] ?? 0);
        if ($single > 0) {
            $customerIds = [$single];
        }
    }
    $customerIds = array_values(array_unique(array_filter($customerIds, static fn($v) => $v > 0)));

    $dateFrom = trim((string) ($query['date_from'] ?? ''));
    $dateTo = trim((string) ($query['date_to'] ?? ''));
    $periodPreset = preg_replace('/[^a-z_]/', '', strtolower((string) ($query['period'] ?? '')));
    $allowedPeriods = ['this_year', 'this_month', 'last_30'];

    if ($periodPreset !== '' && in_array($periodPreset, $allowedPeriods, true)) {
        if ($periodPreset === 'this_year') {
            $dateFrom = date('Y-01-01');
            $dateTo = date('Y-m-d');
        } elseif ($periodPreset === 'this_month') {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-d');
        } else {
            $dateTo = date('Y-m-d');
            $dateFrom = date('Y-m-d', strtotime('-30 days'));
        }
    } elseif ($dateFrom === '' && $dateTo === '') {
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-d');
    }

    return [
        'customer_ids' => $customerIds,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'period' => $periodPreset,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function customer_statement_fetch_customers(PDO $db): array
{
    try {
        $stmtC = $db->query('SELECT id, customer_code, company_name, phone, email FROM customers ORDER BY company_name ASC');
        return $stmtC ? ($stmtC->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('customer_statement_fetch_customers: ' . $e->getMessage());
        return [];
    }
}

/**
 * @param array{customer_ids:list<int>,date_from:string,date_to:string,period:string} $filters
 * @return array<string, mixed>
 */
function customer_statement_build(PDO $db, array $filters): array
{
    $customerIds = $filters['customer_ids'];
    $dateFrom = $filters['date_from'];
    $dateTo = $filters['date_to'];

    $selectedCustomers = [];
    $rows = [];
    $monthly = [];
    $grandTotal = 0.0;
    $sumPaid = 0.0;
    $sumBalance = 0.0;
    $openingBalance = 0.0;

    if ($customerIds === []) {
        return [
            'selected_customers' => [],
            'rows' => [],
            'monthly' => [],
            'grand_total' => 0.0,
            'sum_paid' => 0.0,
            'sum_balance' => 0.0,
            'opening_balance' => 0.0,
            'closing_balance' => 0.0,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    $invCols = [];
    try {
        $invCols = $db->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $invCols = [];
    }
    $hasInvoiceDate = in_array('invoice_date', $invCols, true);
    $hasDueDate = in_array('due_date', $invCols, true);
    $hasAmountPaid = in_array('amount_paid', $invCols, true);

    $invDateExpr = $hasInvoiceDate ? 'i.invoice_date' : 'DATE(i.created_at)';
    $dueDateExpr = $hasDueDate ? 'i.due_date' : "DATE_ADD($invDateExpr, INTERVAL 30 DAY)";
    $amountPaidExpr = $hasAmountPaid ? 'COALESCE(i.amount_paid, 0)' : '0';

    try {
        $in = implode(',', array_fill(0, count($customerIds), '?'));
        $stCust = $db->prepare("SELECT id, customer_code, company_name, phone, email FROM customers WHERE id IN ($in) ORDER BY company_name ASC");
        $stCust->execute($customerIds);
        $selectedCustomers = $stCust->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $selectedCustomers = [];
    }

    if ($dateFrom !== '') {
        try {
            $inO = implode(',', array_fill(0, count($customerIds), '?'));
            $paramsO = $customerIds;
            $paramsO[] = $dateFrom;
            $stO = $db->prepare("
                SELECT COALESCE(SUM(i.total_amount - $amountPaidExpr), 0) AS ob
                FROM invoices i
                WHERE i.customer_id IN ($inO)
                  AND i.status != 'cancelled'
                  AND $invDateExpr < ?
            ");
            $stO->execute($paramsO);
            $openingBalance = (float) $stO->fetchColumn();
        } catch (Throwable $e) {
            $openingBalance = 0.0;
        }
    }

    try {
        $in = implode(',', array_fill(0, count($customerIds), '?'));
        $where = "i.customer_id IN ($in) AND i.status != 'cancelled'";
        $params = $customerIds;
        if ($dateFrom !== '') {
            $where .= " AND $invDateExpr >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= " AND $invDateExpr <= ?";
            $params[] = $dateTo;
        }

        $sql = "SELECT
                    i.id,
                    $invDateExpr AS invoice_date,
                    $dueDateExpr AS due_date,
                    i.invoice_number,
                    i.total_amount,
                    $amountPaidExpr AS amount_paid,
                    (i.total_amount - $amountPaidExpr) AS line_balance,
                    i.status AS invoice_status,
                    c.company_name,
                    c.customer_code,
                    so.status AS order_status
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                LEFT JOIN sales_orders so ON i.order_id = so.id
                WHERE $where
                ORDER BY invoice_date ASC, c.company_name ASC, i.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    foreach ($rows as &$r) {
        $amount = (float) ($r['total_amount'] ?? 0);
        $paid = (float) ($r['amount_paid'] ?? 0);
        $bal = (float) ($r['line_balance'] ?? 0);
        $grandTotal += $amount;
        $sumPaid += $paid;
        $sumBalance += $bal;

        $date = (string) ($r['invoice_date'] ?? '');
        $monthKey = $date !== '' ? date('Y-m', strtotime($date)) : 'unknown';
        if (!isset($monthly[$monthKey])) {
            $monthly[$monthKey] = [
                'key' => $monthKey,
                'label' => strtoupper(date('F', strtotime($date ?: 'now'))),
                'rows' => [],
                'total' => 0.0,
                'total_paid' => 0.0,
                'total_balance' => 0.0,
            ];
        }
        $monthly[$monthKey]['total'] += $amount;
        $monthly[$monthKey]['total_paid'] += $paid;
        $monthly[$monthKey]['total_balance'] += $bal;

        $invSt = (string) ($r['invoice_status'] ?? '');
        $r['payment_status_label'] = ($invSt === 'paid') ? 'Paid' : (($invSt === 'partial') ? 'Partial' : (($invSt === 'overdue') ? 'Overdue' : ucfirst($invSt ?: 'Open')));
        $r['due_relative'] = customer_statement_due_label(isset($r['due_date']) ? (string) $r['due_date'] : null);
        $r['invoice_date_fmt'] = customer_statement_fmt_date(isset($r['invoice_date']) ? (string) $r['invoice_date'] : null);
        $r['due_date_fmt'] = customer_statement_fmt_date(isset($r['due_date']) ? (string) $r['due_date'] : null);
        $r['is_opening'] = false;
        $r['is_paid'] = customer_statement_row_is_paid($r, false);

        $dueTs = !empty($r['due_date']) && $r['due_date'] !== '0000-00-00' ? strtotime((string) $r['due_date']) : false;
        $r['row_overdue'] = ($bal > 0.009) && $dueTs && $dueTs < strtotime('today');

        $monthly[$monthKey]['rows'][] = $r;
    }
    unset($r);

    if ($monthly !== []) {
        $firstKey = array_key_first($monthly);
        if ($firstKey !== null) {
            array_unshift($monthly[$firstKey]['rows'], customer_statement_opening_row($openingBalance));
            $monthly[$firstKey]['total_balance'] = (float) ($monthly[$firstKey]['total_balance'] ?? 0) + $openingBalance;
        }
    }

    if ($monthly === []) {
        $labelBase = $dateFrom !== '' ? $dateFrom : date('Y-m-d');
        $monthKey = date('Y-m', strtotime($labelBase));
        $monthly[$monthKey] = [
            'key' => $monthKey,
            'label' => strtoupper(date('F', strtotime($labelBase))),
            'rows' => [customer_statement_opening_row($openingBalance)],
            'total' => 0.0,
            'total_paid' => 0.0,
            'total_balance' => $openingBalance,
        ];
    }

    return [
        'selected_customers' => $selectedCustomers,
        'rows' => $rows,
        'monthly' => array_values($monthly),
        'grand_total' => $grandTotal,
        'sum_paid' => $sumPaid,
        'sum_balance' => $sumBalance,
        'opening_balance' => $openingBalance,
        'closing_balance' => $openingBalance + $sumBalance,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

/**
 * @param array{customer_ids:list<int>,date_from:string,date_to:string,period:string} $filters
 * @param list<array<string,mixed>> $selectedCustomers
 */
function customer_statement_action_query(array $filters, array $selectedCustomers, array $extra = []): string
{
    $q = array_merge($_GET ?: [], $extra);
    $q['date_from'] = $filters['date_from'];
    $q['date_to'] = $filters['date_to'];
    if ($filters['customer_ids'] !== []) {
        $q['customer_ids'] = array_values($filters['customer_ids']);
    }
    unset($q['period']);

    return '?' . http_build_query($q);
}

/**
 * @return array<string, string>
 */
function customer_statement_urls(array $filters, array $selectedCustomers): array
{
    $base = customer_statement_web_base() . '/index.php';
    $module = customer_statement_module_query();
    $returnPath = $base . '?module=' . rawurlencode($module)
        . '&date_from=' . rawurlencode($filters['date_from'])
        . '&date_to=' . rawurlencode($filters['date_to']);

    $urls = [
        'self' => $base . customer_statement_action_query($filters, $selectedCustomers),
        'customer_catalogue' => function_exists('app_url')
            ? app_url('/modules/sales/customers/catalogue.php?module=' . rawurlencode($module) . '&doc=statement&return=' . rawurlencode($returnPath))
            : '/modules/sales/customers/catalogue.php?module=' . rawurlencode($module) . '&doc=statement&return=' . rawurlencode($returnPath),
        'export_excel' => '',
        'export_pdf' => '',
        'whatsapp' => '',
        'mailto' => '',
        'excel_icon' => function_exists('app_url')
            ? app_url('/assets/icons/icons8-export-excel-color-96.png')
            : '/assets/icons/icons8-export-excel-color-96.png',
    ];

    if ($filters['customer_ids'] !== []) {
        $urls['export_excel'] = $base . customer_statement_action_query($filters, $selectedCustomers, ['download' => 'excel']);
    }

    if ($filters['customer_ids'] !== [] && count($selectedCustomers) === 1) {
        $urls['export_pdf'] = $base . customer_statement_action_query($filters, $selectedCustomers, ['download' => 'pdf']);
        $msg = 'Statement period ' . $filters['date_from'] . ' to ' . $filters['date_to'] . ' ù ' . (string) ($selectedCustomers[0]['company_name'] ?? '');
        $digits = preg_replace('/\D+/', '', (string) ($selectedCustomers[0]['phone'] ?? ''));
        if ($digits !== '') {
            $urls['whatsapp'] = 'https://wa.me/' . $digits . '?text=' . rawurlencode($msg);
        }
        $em = trim((string) ($selectedCustomers[0]['email'] ?? ''));
        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $urls['mailto'] = 'mailto:' . $em . '?subject=' . rawurlencode('Customer statement') . '&body=' . rawurlencode($msg);
        }
    }

    return $urls;
}

/**
 * @return array<string, mixed>
 */
function customer_statement_init_data(): array
{
    $db = customer_statement_pdo();
    $filters = customer_statement_parse_filters($_GET);
    $module = customer_statement_module_query();
    $customers = customer_statement_fetch_customers($db);
    $statement = customer_statement_build($db, $filters);
    $urls = customer_statement_urls($filters, $statement['selected_customers']);

    return [
        'module' => $module,
        'customers' => array_map(static function ($c) {
            return [
                'id' => (int) ($c['id'] ?? 0),
                'customer_code' => (string) ($c['customer_code'] ?? ''),
                'company_name' => (string) ($c['company_name'] ?? ''),
                'phone' => (string) ($c['phone'] ?? ''),
                'email' => (string) ($c['email'] ?? ''),
            ];
        }, $customers),
        'filters' => $filters,
        'statement' => $statement,
        'urls' => $urls,
        'company_name' => defined('COMPANY_NAME') ? (string) COMPANY_NAME : 'Company',
    ];
}

function customer_statement_handle_download(array $filters): bool
{
    if ($filters['customer_ids'] === [] || !isset($_GET['download'])) {
        return false;
    }

    $download = (string) $_GET['download'];
    $db = customer_statement_pdo();
    $statement = customer_statement_build($db, $filters);
    $selectedCustomers = $statement['selected_customers'];
    $monthly = [];
    foreach ($statement['monthly'] as $m) {
        $monthly[$m['key'] ?? ''] = $m;
    }

    $dateFrom = $filters['date_from'];
    $dateTo = $filters['date_to'];
    $grandTotal = (float) $statement['grand_total'];
    $sumPaid = (float) $statement['sum_paid'];
    $sumBalance = (float) $statement['sum_balance'];
    $openingBalance = (float) $statement['opening_balance'];

    if ($download === 'pdf' || $download === 'preview') {
        if (count($selectedCustomers) !== 1) {
            return false;
        }
        $vars = [
            'customer' => $selectedCustomers[0],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'monthly' => $monthly,
            'grandTotal' => $grandTotal,
            'sumPaid' => $sumPaid,
            'sumBalance' => $sumBalance,
            'openingBalance' => $openingBalance,
        ];
        $custName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($selectedCustomers[0]['customer_code'] ?? 'customer'));
        $fileName = 'Customer_Statement_' . $custName . '_' . date('Ymd_His');
        if ($download === 'pdf') {
            downloadDocumentPdf('customer_statement', $vars, $fileName);
        }
        $html = renderDocumentHtml('customer_statement', $vars);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    if ($download !== 'excel') {
        return false;
    }

    $safeName = 'Multiple_Customers';
    if (count($selectedCustomers) === 1) {
        $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($selectedCustomers[0]['customer_code'] ?? 'Customer'));
    }
    $fileName = 'Customer_Statement_' . $safeName . '_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $company = defined('COMPANY_NAME') ? COMPANY_NAME : 'Company';
    $customerLabel = customer_statement_excel_customer_label($selectedCustomers);

    echo customer_statement_render_excel_html(
        'CUSTOMER STATEMENT',
        (string) $company,
        $customerLabel,
        $dateFrom,
        $dateTo,
        $grandTotal,
        $sumPaid,
        $sumBalance,
        $monthly
    );
    exit;
}

/**
 * @param list<array<string, mixed>> $selectedCustomers
 */
function customer_statement_excel_customer_label(array $selectedCustomers): string
{
    if ($selectedCustomers === []) {
        return '';
    }

    $names = [];
    foreach ($selectedCustomers as $customer) {
        $name = trim((string) ($customer['company_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    if ($names === []) {
        return '';
    }

    if (count($names) === 1) {
        $code = trim((string) ($selectedCustomers[0]['customer_code'] ?? ''));
        if ($code !== '') {
            return $names[0] . ' (' . $code . ')';
        }

        return $names[0];
    }

    return implode('; ', $names);
}

/**
 * @param array<string, array<string, mixed>> $monthly
 */
function customer_statement_render_excel_html(
    string $titleName,
    string $company,
    string $customerLabel,
    string $dateFrom,
    string $dateTo,
    float $grandTotal,
    float $sumPaid,
    float $sumBalance,
    array $monthly
): string {
    ob_start();
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8">
<style>
  body { font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; }
  .title { font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 6px; }
  .sub { color: #444; text-align: center; margin-top: 6px; font-size: 15px; }
  .customer { font-size: 17px; font-weight: 700; color: #111; margin-top: 8px; }
  .stmt-xls-table { border-collapse: collapse; width: 100%; margin-top: 14px; }
  .stmt-xls-table th, .stmt-xls-table td { border: 1px solid #000; padding: 14px 14px; vertical-align: middle; font-size: 15px; }
  .stmt-xls-table th { background: #f3d58a; font-weight: 700; text-transform: uppercase; font-size: 13px; }
  .month { font-size: 17px; font-weight: 800; text-align: center; margin-top: 22px; margin-bottom: 4px; }
  .stmt-xls-table .total-row td { font-weight: 800; background: #e5e7eb; }
  .right { text-align: right; }
</style></head><body>
<?php
    echo '<div class="title">' . htmlspecialchars($titleName) . '</div>';
    if ($customerLabel !== '') {
        echo '<div class="sub customer"><strong>Customer:</strong> ' . htmlspecialchars($customerLabel) . '</div>';
    }
    echo '<div class="sub">' . htmlspecialchars($company) . ' - Period: ' . htmlspecialchars($dateFrom) . ' to ' . htmlspecialchars($dateTo) . '</div>';
    echo '<div class="sub"><strong>Totals - Invoiced:</strong> ' . number_format($grandTotal, 2)
        . ' &nbsp; <strong>Paid:</strong> ' . number_format($sumPaid, 2)
        . ' &nbsp; <strong>Balance:</strong> ' . number_format($sumBalance, 2) . '</div>';

    foreach ($monthly as $m) {
        echo '<div class="month">' . htmlspecialchars((string) ($m['label'] ?? '')) . '</div>';
        echo '<table class="stmt-xls-table"><thead><tr>
            <th>Invoice #</th><th>Invoice date</th><th>Due (days)</th><th>Order status</th><th>Status</th>
            <th class="right">Total</th><th class="right">Paid</th><th class="right">Balance</th>
        </tr></thead><tbody>';
        foreach (($m['rows'] ?? []) as $r) {
            $isOpRow = !empty($r['is_opening']);
            $dueP = customer_statement_due_column_parts($r, $isOpRow);
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) ($r['invoice_number'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['invoice_date_fmt'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars($dueP['primary']) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['order_status'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($r['payment_status_label'] ?? '')) . '</td>';
            echo '<td class="right">' . number_format((float) ($r['total_amount'] ?? 0), 2) . '</td>';
            echo '<td class="right">' . number_format((float) ($r['amount_paid'] ?? 0), 2) . '</td>';
            echo '<td class="right">' . number_format((float) ($r['line_balance'] ?? 0), 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr class="total-row"><td colspan="5">Month total</td>';
        echo '<td class="right">' . number_format((float) ($m['total'] ?? 0), 2) . '</td>';
        echo '<td class="right">' . number_format((float) ($m['total_paid'] ?? 0), 2) . '</td>';
        echo '<td class="right">' . number_format((float) ($m['total_balance'] ?? 0), 2) . '</td></tr>';
        echo '</tbody></table>';
    }
    echo '</body></html>';

    return (string) ob_get_clean();
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function customerStatementDeskLoadReactAssets(): ?array
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
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $base = customer_statement_web_base();
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'distHtml' => $distHtml,
        'assetBase' => $base . '/frontend/dist/assets/',
        'apiUrl' => $base . '/api',
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function customerStatementDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 2) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

function customerStatementRenderReactShell(): void
{
    $assets = customerStatementDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Customer statement</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Customer statement</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>customer_statement/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Customer statement';
    $employeeHeaderTitle = 'Customer statement';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';

    $cfg = [
        'module' => customer_statement_module_query(),
    ];

    $statementHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__STATEMENT_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__STATEMENT_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';</script>';

    require dirname(__FILE__) . '/statement-react-shell.php';
    exit;
}
