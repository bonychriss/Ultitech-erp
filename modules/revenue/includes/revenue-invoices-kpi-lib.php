<?php

declare(strict_types=1);

/**
 * Invoice KPI aggregates for the revenue desk (mirrors revenue_invoices.php logic).
 */

/**
 * @return array<string, mixed>
 */
function revenue_invoice_kpi_column_meta(PDO $pdo): array
{
    $invCols = [];
    if (function_exists('invoiceTableColumns')) {
        $invCols = invoiceTableColumns($pdo) ?: [];
    }
    if (!$invCols) {
        try {
            $invCols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $invCols = [];
        }
    }

    $hasBalanceDue = in_array('balance_due', $invCols, true);
    $hasAmountPaid = in_array('amount_paid', $invCols, true);
    $hasDueDate = in_array('due_date', $invCols, true);
    $hasInvoiceDate = in_array('invoice_date', $invCols, true);
    $hasCustomerId = in_array('customer_id', $invCols, true);
    $hasStatus = in_array('status', $invCols, true);
    $hasTotalAmount = in_array('total_amount', $invCols, true);

    $amountPaidExpr = $hasAmountPaid ? 'COALESCE(i.amount_paid, 0)' : '0';
    $totalExpr = $hasTotalAmount ? 'COALESCE(i.total_amount, 0)' : '0';
    $balanceExpr = $hasBalanceDue
        ? "COALESCE(i.balance_due, {$totalExpr} - {$amountPaidExpr})"
        : "({$totalExpr} - {$amountPaidExpr})";
    $dueDateExpr = $hasDueDate ? 'i.due_date' : ($hasInvoiceDate ? 'i.invoice_date' : 'NULL');
    $dueDateFilterCol = $hasDueDate ? 'i.due_date' : ($hasInvoiceDate ? 'i.invoice_date' : null);
    $paidStatusSql = $hasStatus ? " OR LOWER(COALESCE(i.status,'')) = 'paid'" : '';
    $isPaidExpr = "({$balanceExpr} <= 0.001{$paidStatusSql})";

    return [
        'inv_cols' => $invCols,
        'has_customer_id' => $hasCustomerId,
        'has_invoice_date' => $hasInvoiceDate,
        'has_status' => $hasStatus,
        'total_expr' => $totalExpr,
        'balance_expr' => $balanceExpr,
        'due_date_expr' => $dueDateExpr,
        'due_date_filter_col' => $dueDateFilterCol,
        'is_paid_expr' => $isPaidExpr,
    ];
}

/**
 * @param array<string, mixed> $filters
 * @return array{0:array<int,string>,1:array<string,mixed>}
 */
function revenue_invoice_kpi_build_where(array $filters, PDO $pdo, array $meta): array
{
    $where = ['1=1'];
    $params = [];

    if (($meta['has_status'] ?? false)) {
        $where = ["LOWER(COALESCE(i.status,'')) NOT IN ('cancelled','canceled')"];
    }

    $search = trim((string) ($filters['search'] ?? ''));
    $customerId = (int) ($filters['customer_id'] ?? 0);
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));

    if ($search !== '') {
        $searchParts = ['i.invoice_number LIKE :iq'];
        if ($meta['has_customer_id'] ?? false) {
            $searchParts[] = 'COALESCE(c.company_name, \'\') LIKE :iq';
            $searchParts[] = 'COALESCE(c.customer_code, \'\') LIKE :iq';
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $params[':iq'] = '%' . $search . '%';
    }

    if ($customerId > 0 && ($meta['has_customer_id'] ?? false)) {
        $where[] = 'i.customer_id = :icust';
        $params[':icust'] = $customerId;
    }

    if ($dateFrom !== '' && ($meta['has_invoice_date'] ?? false)) {
        $where[] = 'i.invoice_date >= :idf';
        $params[':idf'] = $dateFrom;
    }

    if ($dateTo !== '' && ($meta['has_invoice_date'] ?? false)) {
        $where[] = 'i.invoice_date <= :idt';
        $params[':idt'] = $dateTo;
    }

    return [$where, $params];
}

/**
 * @param array<string, mixed> $deskFilters
 * @return array<string, mixed>
 */
function revenue_invoice_kpi_fetch(PDO $pdo, array $deskFilters): array
{
    $empty = [
        'total' => 0.0,
        'paid' => 0.0,
        'outstanding' => 0.0,
        'overdue' => 0.0,
        'pct_paid' => 0.0,
        'pct_outstanding' => 0.0,
        'pct_overdue' => 0.0,
        'count' => 0,
        'trace_rows' => [],
        'available' => false,
    ];

    if (!function_exists('tableExists') || !tableExists('invoices', $pdo)) {
        return $empty;
    }

    $meta = revenue_invoice_kpi_column_meta($pdo);
    if (!($meta['inv_cols'] ?? [])) {
        return $empty;
    }

    $filters = [
        'search' => trim((string) ($deskFilters['search'] ?? '')),
        'date_from' => trim((string) ($deskFilters['date_from'] ?? '')),
        'date_to' => trim((string) ($deskFilters['date_to'] ?? '')),
        'customer_id' => (int) ($deskFilters['customer_id'] ?? 0),
    ];

    [$where, $params] = revenue_invoice_kpi_build_where($filters, $pdo, $meta);
    $whereSql = implode(' AND ', $where);

    $custJoin = ($meta['has_customer_id'] ?? false)
        ? ' LEFT JOIN customers c ON c.id = i.customer_id'
        : '';

    $totalExpr = $meta['total_expr'];
    $balanceExpr = $meta['balance_expr'];
    $isPaidExpr = $meta['is_paid_expr'];
    $dueDateExpr = $meta['due_date_expr'];
    $dueDateFilterCol = $meta['due_date_filter_col'];
    $overdueCase = $dueDateFilterCol !== null
        ? "CASE WHEN {$dueDateFilterCol} IS NOT NULL AND {$dueDateFilterCol} < CURDATE() AND GREATEST(0, {$balanceExpr}) > 0.001 THEN GREATEST(0, {$balanceExpr}) ELSE 0 END"
        : '0';

    $sql = "
        SELECT
            COALESCE(SUM({$totalExpr}), 0) AS total_invoices,
            COALESCE(SUM(CASE WHEN {$isPaidExpr} THEN GREATEST(0, {$totalExpr}) ELSE 0 END), 0) AS paid,
            COALESCE(SUM(GREATEST(0, {$balanceExpr})), 0) AS outstanding,
            COALESCE(SUM({$overdueCase}), 0) AS overdue,
            COUNT(*) AS invoice_count
        FROM invoices i{$custJoin}
        WHERE {$whereSql}";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('revenue_invoice_kpi_fetch: ' . $e->getMessage());

        return $empty;
    }

    $total = (float) ($row['total_invoices'] ?? 0);
    $paid = (float) ($row['paid'] ?? 0);
    $outstanding = (float) ($row['outstanding'] ?? 0);
    $overdue = (float) ($row['overdue'] ?? 0);
    $count = (int) ($row['invoice_count'] ?? 0);

    $traceRows = [];
    $customerSelect = ($meta['has_customer_id'] ?? false) ? "COALESCE(c.company_name, '-')" : "'-'";
    $invoiceDateSelect = ($meta['has_invoice_date'] ?? false) ? 'i.invoice_date' : 'NULL';
    $statusSelect = ($meta['has_status'] ?? false) ? 'i.status' : "''";
    $traceSql = "
        SELECT
            i.id,
            i.invoice_number,
            {$invoiceDateSelect} AS invoice_date,
            {$dueDateExpr} AS due_date,
            {$totalExpr} AS total_amount,
            {$balanceExpr} AS balance_due,
            {$statusSelect} AS status,
            {$customerSelect} AS customer_name
        FROM invoices i{$custJoin}
        WHERE {$whereSql}
        ORDER BY " . (($meta['has_invoice_date'] ?? false) ? 'i.invoice_date DESC, ' : '') . "i.id DESC
        LIMIT 120";

    try {
        $st = $pdo->prepare($traceSql);
        $st->execute($params);
        $traceRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('revenue_invoice_kpi_trace: ' . $e->getMessage());
    }

    return [
        'total' => $total,
        'paid' => $paid,
        'outstanding' => $outstanding,
        'overdue' => $overdue,
        'pct_paid' => $total > 0 ? ($paid / $total) * 100 : 0.0,
        'pct_outstanding' => $total > 0 ? ($outstanding / $total) * 100 : 0.0,
        'pct_overdue' => $total > 0 ? ($overdue / $total) * 100 : 0.0,
        'count' => $count,
        'trace_rows' => array_map(static function (array $r): array {
            $bal = (float) ($r['balance_due'] ?? 0);
            $status = strtolower(trim((string) ($r['status'] ?? '')));
            $isPaid = ($bal <= 0.001 || $status === 'paid');

            return [
                'id' => (int) ($r['id'] ?? 0),
                'invoice_number' => (string) ($r['invoice_number'] ?? ''),
                'invoice_date' => (string) ($r['invoice_date'] ?? ''),
                'due_date' => (string) ($r['due_date'] ?? ''),
                'customer_name' => (string) ($r['customer_name'] ?? ''),
                'total_amount' => (float) ($r['total_amount'] ?? 0),
                'balance_due' => max(0.0, $bal),
                'status' => (string) ($r['status'] ?? ''),
                'is_paid' => $isPaid,
            ];
        }, $traceRows),
        'available' => true,
    ];
}
