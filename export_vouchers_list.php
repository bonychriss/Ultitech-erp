<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

// Export filename
$filename = 'vouchers_export_' . date('Y-m-d_H-i') . '.csv';

// Headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open output stream
$output = fopen('php://output', 'w');

// Output BOM for Excel UTF-8 recognition
fputs($output, "\xEF\xBB\xBF");

// Column Headers
fputcsv($output, [
    'ID',
    'Voucher No',
    'Date Created',
    'Payee Name',
    'Description',
    'Amount',
    'Currency',
    'Status',
    'Prepared By',
    'Department',
    'Paid',
    'Posted'
]);

// Build Query with Filters (mirroring dashboard logic)
$where = ["1=1"];
$params = [];

// Date Filters
if (!empty($_GET['date_from'])) {
    $where[] = "DATE(pv.date_created) >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[] = "DATE(pv.date_created) <= ?";
    $params[] = $_GET['date_to'];
}

// Status Filter (if passed, currently only Admin Dashboard has it, but good to support)
// Note: Admin dashboard uses select with values pending/approved/rejected/all
// But also supports ?status=... in GET.
// The filter input name in admin dashboard is just a select onchange="filterByStatus" acting on CLIENT SIDE (hiding rows).
// Wait, the admin dashboard filter for "Status" (`filterByStatus`) is PURE JS hiding rows. It does NOT reload the page with ?status=...
// The search input (`searchInput`) is also PURE JS.
// ONLY the Date inputs cause a server reload (form submission).
// AND the sort dropdown causes a reload.

// CRITICAL: The user wants "Export All".
// If the user uses the JS filters (Search Text or Status Dropdown), those are NOT sent to the server.
// However, the DATE filters ARE sent to the server (GET request).
// So `export_vouchers_list.php` will receive `date_from` and `date_to`.
// It will NOT receive the JS-only filters.
// Correct implementation is to export what the SERVER sees (Date filtered list), ignoring client-side JS filtering.
// This is acceptable behavior for "Export".

// sort is also passed potentially. Order doesn't matter much for export, but we can default ID desc.

$sql = "
    SELECT 
        pv.id,
        pv.voucher_no,
        pv.date_created,
        pv.payee_name,
        pv.description,
        pv.total_amount,
        pv.currency,
        pv.status,
        pv.prepared_by,
        u.full_name AS creator_name,
        u.department,
        IFNULL(pv.is_paid,0) as is_paid,
        IFNULL(pv.is_posted,0) as is_posted
    FROM payment_vouchers pv
    LEFT JOIN users u ON pv.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pv.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Format data
    $preparedBy = trim($row['prepared_by'] ?: '');
    if ($preparedBy === '' && !empty($row['creator_name'])) {
        $preparedBy = $row['creator_name'];
    }

    fputcsv($output, [
        $row['id'],
        $row['voucher_no'],
        $row['date_created'],
        $row['payee_name'],
        $row['description'],
        $row['total_amount'],
        $row['currency'],
        ucfirst($row['status']),
        $preparedBy,
        $row['department'],
        ($row['is_paid'] ? 'Yes' : 'No'),
        ($row['is_posted'] ? 'Yes' : 'No')
    ]);
}

fclose($output);
exit;
