<?php
require_once '../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/includes/balances_integration.php';

global $pdo;

// -- Reuse Filtering Logic --
$filters = expenses_parse_list_filters($_GET);

$params = [];
$where = expenses_build_list_where($filters, $params);
$where_clause = ' ' . $where;
$sql = "SELECT e.*
       FROM erp_expenses e " .
       $where_clause .
       " ORDER BY e.date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($expenses as &$exp) {
    $exp = expenses_enrich_list_row($pdo, $exp);
}
unset($exp);

// -- Generate CSV --
$filename = "expenses_export_" . date('Y-m-d_His') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Expense #',
    'Paid via',
    'Bank / Cash',
    'Date',
    'Account',
    'Amount',
    'Status',
]);

foreach ($expenses as $exp) {
    $posted = (int) ($exp['is_posted'] ?? 0) === 1;
    $status = strtolower((string) ($exp['status'] ?? ''));
    if ($posted || $status === 'posted') {
        $statusLabel = 'Posted';
    } elseif ($status === 'draft') {
        $statusLabel = 'Draft';
    } elseif ($status === 'rejected') {
        $statusLabel = 'Rejected';
    } elseif ($status === 'pending') {
        $statusLabel = 'Pending';
    } else {
        $statusLabel = 'Unposted';
    }

    fputcsv($output, [
        $exp['expense_number'],
        $exp['payment_method_label'] ?? '',
        $exp['source_account_name'] ?? '',
        $exp['date'],
        $exp['category_name'] ?? $exp['main_account_name'] ?? '',
        $exp['amount'],
        $statusLabel,
    ]);
}

fclose($output);
exit;
