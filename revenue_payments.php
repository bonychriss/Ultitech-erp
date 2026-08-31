<?php declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/revenue_ledger.php';

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}
$_SESSION['active_module'] = 'revenue';

ensureRevenueSourceInvoiceSchema($pdo);
ensureRevenueLedgerSchema($pdo);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `revenue_collections` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `entry_id` int(11) NOT NULL,
      `collection_date` date DEFAULT NULL,
      `amount_collected` decimal(15,2) DEFAULT 0.00,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `entry_id` (`entry_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
}

$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$customerId = (int) ($_GET['customer_id'] ?? 0);
$method = trim((string) ($_GET['method'] ?? ''));
$status = strtolower(trim((string) ($_GET['status'] ?? '')));
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$baseFrom = "
    FROM revenue_collections rc
    JOIN revenue_entries re ON re.id = rc.entry_id
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    LEFT JOIN customers c ON c.id = i.customer_id
";

$where = ['1=1'];
$params = [];
if ($dateFrom !== '') {
    $where[] = 'rc.collection_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'rc.collection_date <= :date_to';
    $params[':date_to'] = $dateTo;
}
if ($customerId > 0) {
    $where[] = 'c.id = :customer_id';
    $params[':customer_id'] = $customerId;
}
if ($method !== '') {
    $where[] = 'COALESCE(NULLIF(re.payment_mode, \'\'), \'Bank Transfer\') = :method';
    $params[':method'] = $method;
}
if ($status !== '') {
    if ($status === 'completed') {
        $where[] = 'rc.amount_collected > 0';
    } elseif ($status === 'pending') {
        $where[] = 'LOWER(COALESCE(re.payment_status, \'\')) = \'pending\'';
    } elseif ($status === 'failed') {
        $where[] = '(LOWER(COALESCE(re.payment_status, \'\')) = \'failed\' OR rc.amount_collected <= 0)';
    }
}
if ($search !== '') {
    $where[] = "(
        re.voucher_number LIKE :search
        OR i.invoice_number LIKE :search
        OR re.customer_name LIKE :search
        OR c.company_name LIKE :search
        OR CAST(rc.id AS CHAR) LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) {$baseFrom} WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rowsSql = "
    SELECT
        rc.id AS collection_id,
        rc.entry_id,
        rc.collection_date,
        rc.amount_collected,
        rc.created_at AS collection_created_at,
        re.voucher_number,
        re.customer_name,
        re.payment_mode,
        re.amount_total,
        re.total_paid,
        re.payment_status,
        i.invoice_number,
        i.invoice_date,
        i.due_date,
        c.company_name AS customer_company
    {$baseFrom}
    WHERE {$whereSql}
    ORDER BY rc.collection_date DESC, rc.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$rowsStmt = $pdo->prepare($rowsSql);
$rowsStmt->execute($params);
$payments = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allTimeStmt = $pdo->query('SELECT COALESCE(SUM(amount_collected),0) FROM revenue_collections');
$totalPaymentsAllTime = (float) $allTimeStmt->fetchColumn();

$thisMonthStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount_collected),0)
    FROM revenue_collections
    WHERE collection_date >= :mstart AND collection_date <= :mend
");
$thisMonthStmt->execute([
    ':mstart' => date('Y-m-01'),
    ':mend' => date('Y-m-t'),
]);
$totalThisMonth = (float) $thisMonthStmt->fetchColumn();

$overdueStmt = $pdo->query("
    SELECT COALESCE(SUM(GREATEST(0, re.amount_total - re.total_paid)),0)
    FROM revenue_entries re
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    WHERE COALESCE(i.due_date, re.entry_date) < CURDATE()
      AND GREATEST(0, re.amount_total - re.total_paid) > 0
");
$overduePayments = (float) $overdueStmt->fetchColumn();

$avgDaysStmt = $pdo->query("
    SELECT AVG(DATEDIFF(rc.collection_date, COALESCE(i.invoice_date, re.entry_date))) AS avg_days
    FROM revenue_collections rc
    JOIN revenue_entries re ON re.id = rc.entry_id
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    WHERE rc.collection_date IS NOT NULL
      AND COALESCE(i.invoice_date, re.entry_date) IS NOT NULL
");
$avgPaymentDays = max(0, (int) round((float) $avgDaysStmt->fetchColumn()));

$customerOptions = $pdo->query("
    SELECT c.id, c.company_name
    FROM customers c
    WHERE TRIM(COALESCE(c.company_name, '')) <> ''
    ORDER BY c.company_name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$methodOptions = $pdo->query("
    SELECT DISTINCT COALESCE(NULLIF(re.payment_mode, ''), 'Bank Transfer') AS payment_method
    FROM revenue_entries re
    ORDER BY payment_method ASC
")->fetchAll(PDO::FETCH_COLUMN) ?: [];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=revenue_payments_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Payment Ref', 'Date', 'Customer', 'Invoice', 'Payment Method', 'Amount (TZS)', 'Status']);
    foreach ($payments as $p) {
        $statusText = ((float) ($p['amount_collected'] ?? 0) <= 0)
            ? 'Failed'
            : ((strtolower((string) ($p['payment_status'] ?? '')) === 'pending') ? 'Pending' : 'Completed');
        fputcsv($out, [
            'PAY-' . date('Y', strtotime((string) ($p['collection_date'] ?? 'now'))) . '-' . str_pad((string) ((int) ($p['collection_id'] ?? 0)), 4, '0', STR_PAD_LEFT),
            (string) ($p['collection_date'] ?? ''),
            (string) (($p['customer_company'] ?: $p['customer_name']) ?: 'N/A'),
            (string) (($p['invoice_number'] ?: $p['voucher_number']) ?: '-'),
            (string) (($p['payment_mode'] ?: 'Bank Transfer')),
            number_format((float) ($p['amount_collected'] ?? 0), 2, '.', ''),
            $statusText,
        ]);
    }
    fclose($out);
    exit();
}

function pay_qs(array $overrides = []): string
{
    $qs = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return http_build_query($qs);
}

function pay_status_meta(array $row): array
{
    $paymentStatus = strtolower(trim((string) ($row['payment_status'] ?? '')));
    $amt = (float) ($row['amount_collected'] ?? 0);
    if ($amt <= 0 || $paymentStatus === 'failed') {
        return ['Failed', 'st-failed'];
    }
    if ($paymentStatus === 'pending') {
        return ['Pending', 'st-pending'];
    }
    return ['Completed', 'st-completed'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Revenue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        body.dashboard.pay-page { background:#f8fafc!important; color:#0f172a; font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        .pay-wrap { max-width:none; width:calc(100% - 12px); margin:0 0 0 12px; padding:24px 24px 22px 20px; }
        .pay-head { display:grid; grid-template-columns:minmax(220px,1fr) minmax(320px,520px) minmax(220px,1fr); align-items:start; gap:14px; margin-bottom:18px; }
        .pay-title { margin:0; font-size:34px; font-weight:800; line-height:1.08; }
        .pay-sub { margin:8px 0 0; color:#64748b; font-size:14px; }
        .pay-actions { display:flex; gap:10px; justify-self:end; }
        .btn-pay { border-radius:9px; border:1px solid #dbe2ea; background:#fff; color:#0f172a; font-size:13px; font-weight:700; padding:9px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
        .btn-pay.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
        .pay-search { margin:0; position:relative; max-width:520px; width:100%; justify-self:center; }
        .pay-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:12px; }
        .pay-search input { width:100%; border:1px solid #dbe2ea; border-radius:10px; height:42px; padding:0 14px 0 34px; font-size:13px; }
        .pay-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:16px; }
        .kpi { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:14px; display:flex; align-items:flex-start; gap:12px; }
        .kpi .ico { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
        .kpi .label { font-size:12px; font-weight:700; color:#64748b; margin:0 0 4px; }
        .kpi .value { margin:0; font-size:27px; font-weight:800; line-height:1.1; color:#0f172a; }
        .kpi .meta { margin:4px 0 0; font-size:12px; color:#64748b; }
        .kpi.blue .ico { background:#dbeafe; color:#2563eb; }
        .kpi.green { background:#f0fdf4; }
        .kpi.green .ico { background:#dcfce7; color:#16a34a; }
        .kpi.orange { background:#fff7ed; }
        .kpi.orange .ico { background:#ffedd5; color:#ea580c; }
        .kpi.purple .ico { background:#ede9fe; color:#7c3aed; }
        .pay-filters { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:10px; display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; margin-bottom:10px; }
        .pay-filters input,.pay-filters select { height:38px; border:1px solid #dbe2ea; border-radius:8px; font-size:12px; padding:0 10px; background:#fff; }
        .pay-table-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; overflow:hidden; }
        table.pay-table { width:100%; border-collapse:collapse; }
        .pay-table th { font-size:11px; color:#64748b; font-weight:800; letter-spacing:.02em; text-transform:uppercase; padding:12px 14px; border-bottom:1px solid #eef2f7; white-space:nowrap; }
        .pay-table td { font-size:12px; color:#334155; padding:12px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .pay-table td.num { text-align:right; font-variant-numeric:tabular-nums; font-weight:800; color:#16a34a; white-space:nowrap; }
        .pay-ref { color:#2563eb; font-weight:700; text-decoration:none; }
        .pay-customer { font-weight:700; color:#0f172a; }
        .st { padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; display:inline-block; }
        .st-completed { background:#dcfce7; color:#15803d; }
        .st-pending { background:#ffedd5; color:#c2410c; }
        .st-failed { background:#fee2e2; color:#dc2626; }
        .pay-actions-col { display:flex; gap:8px; color:#2563eb; }
        .pay-actions-col a { color:#2563eb; text-decoration:none; font-size:12px; }
        .pay-foot { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:12px 14px; }
        .pay-foot small { color:#64748b; font-size:11px; }
        .pager { display:flex; align-items:center; gap:6px; }
        .pager a,.pager span { min-width:26px; height:26px; border:1px solid #dbe2ea; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-size:12px; color:#334155; text-decoration:none; background:#fff; }
        .pager .on { background:#2563eb; border-color:#2563eb; color:#fff; font-weight:700; }
        @media (max-width: 1200px) { .pay-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .pay-filters { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width: 992px) { .pay-wrap { width:100%; margin:0; padding:16px; } .pay-head { grid-template-columns:1fr; } .pay-search { justify-self:stretch; max-width:none; } .pay-kpis,.pay-filters { grid-template-columns:1fr; } .pay-table-card { overflow:auto; } .pay-table { min-width:980px; } }
    </style>
</head>
<body class="dashboard pay-page">
<?php require __DIR__ . '/includes/header_employee.php'; ?>
<div class="pay-wrap">
    <form method="get" action="revenue_payments.php">
        <input type="hidden" name="module" value="revenue">
    <div class="pay-head">
        <div>
            <h1 class="pay-title">Payments</h1>
            <p class="pay-sub">Record and manage customer payments</p>
        </div>
        <div class="pay-search">
            <i class="fas fa-search"></i>
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search payments, invoice, customer...">
        </div>
        <div class="pay-actions">
            <a href="revenue_payments.php?<?= h(pay_qs(['export' => 'csv'])) ?>" class="btn-pay"><i class="fas fa-download"></i> Export</a>
            <a href="revenue_record_payment_start.php?module=revenue" class="btn-pay primary"><i class="fas fa-plus"></i> Record Payment</a>
        </div>
    </div>

        <div class="pay-kpis">
            <div class="kpi blue">
                <div class="ico"><i class="fas fa-wallet"></i></div>
                <div>
                    <p class="label">Total Payments</p>
                    <p class="value">TZS <?= h(number_format($totalPaymentsAllTime, 2)) ?></p>
                    <p class="meta">All time payments recorded</p>
                </div>
            </div>
            <div class="kpi green">
                <div class="ico"><i class="far fa-calendar"></i></div>
                <div>
                    <p class="label">This Month</p>
                    <p class="value">TZS <?= h(number_format($totalThisMonth, 2)) ?></p>
                    <p class="meta"><?= h(date('M Y')) ?> payments</p>
                </div>
            </div>
            <div class="kpi orange">
                <div class="ico"><i class="far fa-clock"></i></div>
                <div>
                    <p class="label">Overdue Payments</p>
                    <p class="value">TZS <?= h(number_format($overduePayments, 2)) ?></p>
                    <p class="meta">Outstanding and overdue</p>
                </div>
            </div>
            <div class="kpi purple">
                <div class="ico"><i class="fas fa-arrow-trend-up"></i></div>
                <div>
                    <p class="label">Avg. Payment Time</p>
                    <p class="value"><?= h((string) $avgPaymentDays) ?> Days</p>
                    <p class="meta">Average days to get paid</p>
                </div>
            </div>
        </div>

        <div class="pay-filters">
            <input type="date" name="date_from" value="<?= h($dateFrom) ?>" aria-label="From date">
            <input type="date" name="date_to" value="<?= h($dateTo) ?>" aria-label="To date">
            <select name="customer_id" aria-label="Customer">
                <option value="">All Customers</option>
                <?php foreach ($customerOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"<?= $customerId === (int) $opt['id'] ? ' selected' : '' ?>><?= h((string) $opt['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="method" aria-label="Payment method">
                <option value="">All Payment Methods</option>
                <?php foreach ($methodOptions as $m): ?>
                    <option value="<?= h((string) $m) ?>"<?= $method === (string) $m ? ' selected' : '' ?>><?= h((string) $m) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" aria-label="Status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Completed</option>
                <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending</option>
                <option value="failed"<?= $status === 'failed' ? ' selected' : '' ?>>Failed</option>
            </select>
        </div>
    </form>

    <div class="pay-table-card">
        <table class="pay-table">
            <thead>
            <tr>
                <th>Payment Ref</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Invoice</th>
                <th>Payment Method</th>
                <th style="text-align:right;">Amount (TZS)</th>
                <th>Status</th>
                <th>Received By</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="9" style="padding:24px;text-align:center;color:#64748b;">No payments found for selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $row): ?>
                    <?php
                    [$statusText, $statusClass] = pay_status_meta($row);
                    $payRef = 'PAY-' . date('Y', strtotime((string) ($row['collection_date'] ?: $row['collection_created_at']))) . '-' . str_pad((string) ((int) ($row['collection_id'] ?? 0)), 4, '0', STR_PAD_LEFT);
                    $invoiceRef = (string) (($row['invoice_number'] ?: $row['voucher_number']) ?: '-');
                    $customer = (string) (($row['customer_company'] ?: $row['customer_name']) ?: 'N/A');
                    $methodLabel = trim((string) ($row['payment_mode'] ?? '')) !== '' ? (string) $row['payment_mode'] : 'Bank Transfer';
                    ?>
                    <tr>
                        <td><a class="pay-ref" href="revenue_details.php?id=<?= (int) $row['entry_id'] ?>"><?= h($payRef) ?></a></td>
                        <td><?= h(date('d M Y', strtotime((string) ($row['collection_date'] ?: $row['collection_created_at'])))) ?></td>
                        <td class="pay-customer"><?= h($customer) ?></td>
                        <td><?= h($invoiceRef) ?></td>
                        <td><?= h($methodLabel) ?></td>
                        <td class="num"><?= h(number_format((float) ($row['amount_collected'] ?? 0), 2)) ?></td>
                        <td><span class="st <?= h($statusClass) ?>"><?= h($statusText) ?></span></td>
                        <td>System Admin</td>
                        <td>
                            <div class="pay-actions-col">
                                <a href="revenue_details.php?id=<?= (int) $row['entry_id'] ?>" title="View"><i class="fas fa-eye"></i></a>
                                <a href="revenue_record_payment.php?id=<?= (int) $row['entry_id'] ?>" title="Edit"><i class="fas fa-pen"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="pay-foot">
            <small>Showing <?= h((string) ($totalRows > 0 ? ($offset + 1) : 0)) ?> to <?= h((string) min($offset + $perPage, $totalRows)) ?> of <?= h((string) $totalRows) ?> entries</small>
            <div class="pager">
                <a href="revenue_payments.php?<?= h(pay_qs(['page' => max(1, $page - 1)])) ?>"><i class="fas fa-chevron-left"></i></a>
                <span class="on"><?= h((string) $page) ?></span>
                <a href="revenue_payments.php?<?= h(pay_qs(['page' => min($totalPages, $page + 1)])) ?>"><i class="fas fa-chevron-right"></i></a>
                <select onchange="location='revenue_payments.php?<?= h(pay_qs(['per_page' => null, 'page' => 1])) ?>&per_page='+this.value" style="height:26px;border:1px solid #dbe2ea;border-radius:6px;padding:0 8px;font-size:12px;">
                    <?php foreach ([10,25,50] as $pp): ?>
                        <option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?>/page</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>
</body>
</html>
