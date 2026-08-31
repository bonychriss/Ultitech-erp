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

$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$customerId = (int) ($_GET['customer_id'] ?? 0);
$reason = trim((string) ($_GET['reason'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$creditCondition = "(LOWER(COALESCE(re.narration,'')) LIKE '%credit note%' OR LOWER(COALESCE(re.narration,'')) LIKE '%credit%' OR re.voucher_number LIKE 'CN-%')";
$reasonSql = "CASE
    WHEN LOWER(COALESCE(re.narration,'')) LIKE '%return%' THEN 'Returned Goods'
    WHEN LOWER(COALESCE(re.narration,'')) LIKE '%discount%' THEN 'Discount Given'
    WHEN LOWER(COALESCE(re.narration,'')) LIKE '%price%' OR LOWER(COALESCE(re.narration,'')) LIKE '%error%' THEN 'Pricing Error'
    ELSE 'Credit Adjustment'
END";
$statusSql = "CASE
    WHEN re.approval_status = 'Pending' THEN 'Draft'
    WHEN re.source_invoice_id IS NULL THEN 'Unapplied'
    ELSE 'Applied'
END";

$baseFrom = "
    FROM revenue_entries re
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    LEFT JOIN customers c ON c.id = i.customer_id
";

$where = [$creditCondition];
$params = [];
if ($dateFrom !== '') {
    $where[] = 're.entry_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 're.entry_date <= :date_to';
    $params[':date_to'] = $dateTo;
}
if ($customerId > 0) {
    $where[] = 'c.id = :customer_id';
    $params[':customer_id'] = $customerId;
}
if ($reason !== '') {
    $where[] = "{$reasonSql} = :reason";
    $params[':reason'] = $reason;
}
if ($status !== '') {
    $where[] = "{$statusSql} = :status";
    $params[':status'] = $status;
}
if ($search !== '') {
    $where[] = "(
        re.voucher_number LIKE :search
        OR COALESCE(i.invoice_number,'') LIKE :search
        OR COALESCE(re.customer_name,'') LIKE :search
        OR COALESCE(c.company_name,'') LIKE :search
        OR COALESCE(re.narration,'') LIKE :search
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

$listSql = "
    SELECT
        re.id,
        re.voucher_number,
        re.entry_date,
        re.customer_name,
        re.narration,
        re.amount_total,
        re.approval_status,
        re.source_invoice_id,
        i.invoice_number,
        c.company_name AS customer_company,
        {$reasonSql} AS reason_label,
        {$statusSql} AS credit_status
    {$baseFrom}
    WHERE {$whereSql}
    ORDER BY re.entry_date DESC, re.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$statsStmt = $pdo->query("
    SELECT
        COALESCE(SUM(re.amount_total),0) AS total_credit,
        COALESCE(SUM(CASE WHEN re.source_invoice_id IS NOT NULL THEN re.amount_total ELSE 0 END),0) AS linked_amount,
        COALESCE(SUM(CASE WHEN re.source_invoice_id IS NULL THEN re.amount_total ELSE 0 END),0) AS unapplied_amount,
        COALESCE(SUM(CASE WHEN re.entry_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND re.entry_date <= LAST_DAY(CURDATE()) THEN re.amount_total ELSE 0 END),0) AS this_month,
        COALESCE(SUM(CASE WHEN YEAR(re.entry_date) = YEAR(CURDATE()) THEN re.amount_total ELSE 0 END),0) AS ytd
    FROM revenue_entries re
    WHERE {$creditCondition}
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalCredit = (float) ($stats['total_credit'] ?? 0);
$linkedAmount = (float) ($stats['linked_amount'] ?? 0);
$unappliedAmount = (float) ($stats['unapplied_amount'] ?? 0);
$thisMonthAmount = (float) ($stats['this_month'] ?? 0);
$yearToDateAmount = (float) ($stats['ytd'] ?? 0);

$applyPct = $totalCredit > 0 ? ($linkedAmount / $totalCredit) * 100 : 0;
$unappliedPct = max(0, 100 - $applyPct);

$recentStmt = $pdo->query("
    SELECT
        re.id, re.voucher_number, re.entry_date, re.amount_total,
        COALESCE(c.company_name, re.customer_name, 'N/A') AS customer_label,
        {$statusSql} AS credit_status
    FROM revenue_entries re
    LEFT JOIN invoices i ON i.id = re.source_invoice_id
    LEFT JOIN customers c ON c.id = i.customer_id
    WHERE {$creditCondition}
    ORDER BY re.entry_date DESC, re.id DESC
    LIMIT 3
");
$recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerOptions = $pdo->query("
    SELECT DISTINCT c.id, c.company_name
    FROM customers c
    JOIN invoices i ON i.customer_id = c.id
    JOIN revenue_entries re ON re.source_invoice_id = i.id
    WHERE {$creditCondition}
    ORDER BY c.company_name ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$reasonOptions = ['Returned Goods', 'Discount Given', 'Pricing Error', 'Credit Adjustment'];
$statusOptions = ['Applied', 'Draft', 'Unapplied'];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=credit_notes_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Credit Note #', 'Date', 'Customer', 'Invoice Reference', 'Reason', 'Amount (TZS)', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['voucher_number'] ?? ''),
            (string) ($r['entry_date'] ?? ''),
            (string) (($r['customer_company'] ?: $r['customer_name']) ?: 'N/A'),
            (string) ($r['invoice_number'] ?: '-'),
            (string) ($r['reason_label'] ?? ''),
            number_format((float) ($r['amount_total'] ?? 0), 2, '.', ''),
            (string) ($r['credit_status'] ?? ''),
        ]);
    }
    fclose($out);
    exit();
}

function cn_qs(array $overrides = []): string
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

function cn_status_class(string $status): string
{
    $s = strtolower($status);
    if ($s === 'applied') return 'st-applied';
    if ($s === 'draft') return 'st-draft';
    return 'st-unapplied';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Notes - Revenue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <style>
        body.dashboard.cn-page { background:#f8fafc!important; color:#0f172a; font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        .cn-wrap { max-width:none; width:calc(100% - 12px); margin:0 0 0 12px; padding:24px 24px 22px 20px; }
        .cn-head { display:grid; grid-template-columns:minmax(220px,1fr) minmax(320px,520px) minmax(220px,1fr); align-items:start; gap:14px; margin-bottom:16px; }
        .cn-title { margin:0; font-size:34px; font-weight:800; line-height:1.08; }
        .cn-sub { margin:8px 0 8px; color:#64748b; font-size:14px; }
        .cn-bc { font-size:12px; color:#64748b; display:flex; align-items:center; gap:7px; }
        .cn-bc a { color:#2563eb; text-decoration:none; font-weight:700; }
        .cn-actions { display:flex; gap:10px; justify-self:end; }
        .btn-cn { border-radius:9px; border:1px solid #dbe2ea; background:#fff; color:#0f172a; font-size:13px; font-weight:700; padding:9px 13px; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
        .btn-cn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
        .cn-search { margin:0; position:relative; width:100%; max-width:520px; justify-self:center; }
        .cn-search i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:12px; }
        .cn-search input { width:100%; border:1px solid #dbe2ea; border-radius:10px; height:42px; padding:0 14px 0 34px; font-size:13px; }
        .cn-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:12px; }
        .cn-kpi { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:14px; display:flex; align-items:flex-start; gap:12px; }
        .cn-kpi .ico { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
        .cn-kpi .label { font-size:12px; font-weight:700; color:#64748b; margin:0 0 4px; }
        .cn-kpi .value { margin:0; font-size:27px; font-weight:800; line-height:1.1; color:#0f172a; }
        .cn-kpi .meta { margin:4px 0 0; font-size:12px; color:#64748b; }
        .cn-kpi.blue .ico { background:#dbeafe; color:#2563eb; }
        .cn-kpi.green { background:#f0fdf4; }
        .cn-kpi.green .ico { background:#dcfce7; color:#16a34a; }
        .cn-kpi.orange { background:#fff7ed; }
        .cn-kpi.orange .ico { background:#ffedd5; color:#ea580c; }
        .cn-kpi.purple .ico { background:#ede9fe; color:#7c3aed; }
        .cn-filters { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:10px; display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; margin-bottom:10px; }
        .cn-filters input,.cn-filters select { height:38px; border:1px solid #dbe2ea; border-radius:8px; font-size:12px; padding:0 10px; background:#fff; }
        .cn-grid { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:12px; }
        .cn-table-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; overflow:hidden; }
        table.cn-table { width:100%; border-collapse:collapse; }
        .cn-table th { font-size:11px; color:#64748b; font-weight:800; letter-spacing:.02em; text-transform:uppercase; padding:12px 14px; border-bottom:1px solid #eef2f7; white-space:nowrap; }
        .cn-table td { font-size:12px; color:#334155; padding:12px 14px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .cn-table td.num { text-align:right; font-variant-numeric:tabular-nums; font-weight:800; color:#dc2626; white-space:nowrap; }
        .cn-ref { color:#2563eb; font-weight:700; text-decoration:none; }
        .cn-customer { font-weight:700; color:#0f172a; }
        .st { padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; display:inline-block; }
        .st-applied { background:#dcfce7; color:#15803d; }
        .st-draft { background:#ffedd5; color:#c2410c; }
        .st-unapplied { background:#dbeafe; color:#1d4ed8; }
        .cn-actions-col { display:flex; gap:8px; color:#2563eb; }
        .cn-actions-col a { color:#2563eb; text-decoration:none; font-size:12px; }
        .cn-foot { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:12px 14px; }
        .cn-foot small { color:#64748b; font-size:11px; }
        .pager { display:flex; align-items:center; gap:6px; }
        .pager a,.pager span { min-width:26px; height:26px; border:1px solid #dbe2ea; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-size:12px; color:#334155; text-decoration:none; background:#fff; }
        .pager .on { background:#2563eb; border-color:#2563eb; color:#fff; font-weight:700; }
        .cn-side { display:grid; gap:12px; align-content:start; }
        .cn-side-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; padding:12px; }
        .cn-side h4 { margin:0 0 6px; font-size:15px; font-weight:800; color:#0f172a; }
        .cn-sum-row { display:flex; justify-content:space-between; gap:10px; font-size:12px; color:#475569; margin-bottom:6px; }
        .cn-sum-row strong { color:#0f172a; font-weight:800; }
        .cn-sum-row .green { color:#16a34a; }
        .cn-donut-wrap { display:flex; align-items:center; gap:12px; }
        .cn-donut { --val:0; width:96px; height:96px; border-radius:50%; background:conic-gradient(#22c55e calc(var(--val)*1%), #e2e8f0 0); position:relative; flex:0 0 96px; }
        .cn-donut::before { content:""; position:absolute; inset:12px; border-radius:50%; background:#fff; }
        .cn-donut-center { position:absolute; inset:0; z-index:1; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; color:#0f172a; text-align:center; line-height:1.2; }
        .cn-leg p { margin:0 0 6px; font-size:12px; color:#475569; }
        .cn-leg b { color:#0f172a; }
        .cn-recent { display:grid; gap:8px; }
        .cn-recent-item { border:1px solid #edf2f7; border-radius:9px; padding:8px; }
        .cn-recent-item a { color:#2563eb; font-weight:700; font-size:12px; text-decoration:none; }
        .cn-recent-item div { font-size:11px; color:#64748b; }
        .cn-note { margin-top:10px; border:1px solid #dbe2ea; border-radius:10px; background:#f8fafc; padding:10px 12px; font-size:12px; color:#64748b; display:flex; gap:8px; align-items:flex-start; }
        @media (max-width: 1200px) { .cn-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .cn-filters { grid-template-columns:repeat(2,minmax(0,1fr)); } .cn-grid { grid-template-columns:1fr; } }
        @media (max-width: 992px) { .cn-wrap { width:100%; margin:0; padding:16px; } .cn-head { grid-template-columns:1fr; } .cn-search { justify-self:stretch; max-width:none; } .cn-kpis,.cn-filters { grid-template-columns:1fr; } .cn-table-card { overflow:auto; } .cn-table { min-width:940px; } }
    </style>
</head>
<body class="dashboard cn-page">
<?php require __DIR__ . '/includes/header_employee.php'; ?>
<div class="cn-wrap">
    <form method="get" action="revenue_credit_notes.php">
        <input type="hidden" name="module" value="revenue">
        <div class="cn-head">
            <div>
                <h1 class="cn-title">Credit Notes</h1>
                <p class="cn-sub">Manage credit notes issued to customers</p>
                <div class="cn-bc"><a href="revenue_entries.php?module=revenue">Revenues</a> <i class="fas fa-chevron-right"></i> <span>Credit Notes</span></div>
            </div>
            <div class="cn-search">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search credit notes...">
            </div>
            <div class="cn-actions">
                <a href="revenue_credit_notes.php?<?= h(cn_qs(['export' => 'csv'])) ?>" class="btn-cn"><i class="fas fa-download"></i> Export</a>
                <a href="revenue_credit_note_create.php?module=revenue" class="btn-cn primary"><i class="fas fa-plus"></i> New Credit Note</a>
            </div>
        </div>

        <div class="cn-kpis">
            <div class="cn-kpi blue"><div class="ico"><i class="far fa-file-lines"></i></div><div><p class="label">Total Credit Notes</p><p class="value">TZS <?= h(number_format($totalCredit, 2)) ?></p><p class="meta">All time credit notes</p></div></div>
            <div class="cn-kpi green"><div class="ico"><i class="far fa-circle-check"></i></div><div><p class="label">This Month</p><p class="value">TZS <?= h(number_format($thisMonthAmount, 2)) ?></p><p class="meta"><?= h(date('M Y')) ?> credit notes</p></div></div>
            <div class="cn-kpi orange"><div class="ico"><i class="far fa-clock"></i></div><div><p class="label">Unapplied Amount</p><p class="value">TZS <?= h(number_format($unappliedAmount, 2)) ?></p><p class="meta">Available to apply</p></div></div>
            <div class="cn-kpi purple"><div class="ico"><i class="far fa-copy"></i></div><div><p class="label">Linked to Invoices</p><p class="value">TZS <?= h(number_format($linkedAmount, 2)) ?></p><p class="meta">Already applied</p></div></div>
        </div>

        <div class="cn-filters">
            <input type="date" name="date_from" value="<?= h($dateFrom) ?>" aria-label="From date">
            <input type="date" name="date_to" value="<?= h($dateTo) ?>" aria-label="To date">
            <select name="customer_id" aria-label="Customer">
                <option value="">All Customers</option>
                <?php foreach ($customerOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"<?= $customerId === (int) $opt['id'] ? ' selected' : '' ?>><?= h((string) $opt['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="reason" aria-label="Reason">
                <option value="">All Reasons</option>
                <?php foreach ($reasonOptions as $r): ?>
                    <option value="<?= h($r) ?>"<?= $reason === $r ? ' selected' : '' ?>><?= h($r) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" aria-label="Status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= h($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= h($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="cn-grid">
        <div class="cn-table-card">
            <table class="cn-table">
                <thead>
                <tr>
                    <th>Credit Note #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Invoice Reference</th>
                    <th>Reason</th>
                    <th style="text-align:right;">Amount (TZS)</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" style="padding:24px;text-align:center;color:#64748b;">No credit notes found for selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $customer = (string) (($row['customer_company'] ?: $row['customer_name']) ?: 'N/A');
                        $invoiceRef = (string) ($row['invoice_number'] ?: '-');
                        $statusText = (string) ($row['credit_status'] ?? 'Unapplied');
                        ?>
                        <tr>
                            <td><a class="cn-ref" href="revenue_details.php?id=<?= (int) $row['id'] ?>"><?= h((string) ($row['voucher_number'] ?? '')) ?></a></td>
                            <td><?= h(date('d M Y', strtotime((string) ($row['entry_date'] ?? 'now')))) ?></td>
                            <td class="cn-customer"><?= h($customer) ?></td>
                            <td><?= h($invoiceRef) ?></td>
                            <td><?= h((string) ($row['reason_label'] ?? 'Credit Adjustment')) ?></td>
                            <td class="num"><?= h(number_format((float) ($row['amount_total'] ?? 0), 2)) ?></td>
                            <td><span class="st <?= h(cn_status_class($statusText)) ?>"><?= h($statusText) ?></span></td>
                            <td><div class="cn-actions-col"><a href="revenue_details.php?id=<?= (int) $row['id'] ?>" title="View"><i class="fas fa-eye"></i></a><a href="revenue_edit.php?id=<?= (int) $row['id'] ?>" title="Edit"><i class="fas fa-pen"></i></a></div></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="cn-foot">
                <small>Showing <?= h((string) ($totalRows > 0 ? ($offset + 1) : 0)) ?> to <?= h((string) min($offset + $perPage, $totalRows)) ?> of <?= h((string) $totalRows) ?> entries</small>
                <div class="pager">
                    <a href="revenue_credit_notes.php?<?= h(cn_qs(['page' => max(1, $page - 1)])) ?>"><i class="fas fa-chevron-left"></i></a>
                    <span class="on"><?= h((string) $page) ?></span>
                    <a href="revenue_credit_notes.php?<?= h(cn_qs(['page' => min($totalPages, $page + 1)])) ?>"><i class="fas fa-chevron-right"></i></a>
                    <select onchange="location='revenue_credit_notes.php?<?= h(cn_qs(['per_page' => null, 'page' => 1])) ?>&per_page='+this.value" style="height:26px;border:1px solid #dbe2ea;border-radius:6px;padding:0 8px;font-size:12px;">
                        <?php foreach ([10,25,50] as $pp): ?><option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?>/page</option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <aside class="cn-side">
            <div class="cn-side-card">
                <h4>Credit Note Summary</h4>
                <div class="cn-sum-row"><span>Total Credit Notes</span><strong>TZS <?= h(number_format($totalCredit, 2)) ?></strong></div>
                <div class="cn-sum-row"><span>Linked to Invoices</span><strong>TZS <?= h(number_format($linkedAmount, 2)) ?></strong></div>
                <div class="cn-sum-row"><span>Unapplied Amount</span><strong>TZS <?= h(number_format($unappliedAmount, 2)) ?></strong></div>
                <div class="cn-sum-row"><span>This Month</span><strong class="green">TZS <?= h(number_format($thisMonthAmount, 2)) ?></strong></div>
                <div class="cn-sum-row"><span>This Year to Date</span><strong>TZS <?= h(number_format($yearToDateAmount, 2)) ?></strong></div>
                <div class="cn-donut-wrap">
                    <div class="cn-donut" style="--val: <?= h(number_format($applyPct, 1, '.', '')) ?>;">
                        <div class="cn-donut-center">Total<br>TZS <?= h(number_format($totalCredit / 1000000, 1)) ?>M</div>
                    </div>
                    <div class="cn-leg">
                        <p><b>Applied</b><br>TZS <?= h(number_format($linkedAmount, 2)) ?> (<?= h(number_format($applyPct, 1)) ?>%)</p>
                        <p><b>Unapplied</b><br>TZS <?= h(number_format($unappliedAmount, 2)) ?> (<?= h(number_format($unappliedPct, 1)) ?>%)</p>
                    </div>
                </div>
            </div>

            <div class="cn-side-card">
                <h4>Recent Credit Notes</h4>
                <div class="cn-recent">
                    <?php if (!$recentRows): ?>
                        <div class="cn-recent-item">
                            <div style="font-size:12px;font-weight:700;color:#0f172a;">No recent credit notes</div>
                            <div>TZS 0.00</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentRows as $r): ?>
                            <div class="cn-recent-item">
                                <a href="revenue_details.php?id=<?= (int) $r['id'] ?>"><?= h((string) ($r['voucher_number'] ?? '')) ?></a>
                                <div><?= h((string) ($r['customer_label'] ?? 'N/A')) ?></div>
                                <div>TZS <?= h(number_format((float) ($r['amount_total'] ?? 0), 2)) ?> - <?= h(date('d M Y', strtotime((string) ($r['entry_date'] ?? 'now')))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin-top:8px;"><a href="revenue_credit_notes.php?module=revenue" style="font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;">View all credit notes <i class="fas fa-chevron-right" style="font-size:10px;"></i></a></div>
            </div>
        </aside>
    </div>

    <div class="cn-note">
        <i class="fas fa-info-circle" style="margin-top:2px;"></i>
        <span>Credit notes are used to reduce or cancel an existing invoice. They will automatically update customer balances and revenue reports.</span>
    </div>
</div>
</body>
</html>
