<?php
require_once '../includes/functions.php';
requireLogin();

$filename = 'voucher_bulk_template.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Column Headers
fputcsv($output, [
    'Payee Name',
    'Voucher Description',
    'Currency',
    'Date (YYYY-MM-DD)',
    'Total Amount',
    'Item Name',
    'Payment Type',
    'Budget Type',
    'Applicant Name',
    'Department Manager Name',
    'Finance Checked By Name'
]);

// Sample Data
fputcsv($output, [
    'John Doe',
    'Monthly Office Supplies',
    'TZS',
    date('Y-m-d'),
    '50000',
    'Stationery',
    'Cash Payment',
    'Office Expenses',
    $_SESSION['full_name'] ?? 'Staff Member',
    'Manager Name',
    'Finance Officer'
]);

fclose($output);
exit;
?>
