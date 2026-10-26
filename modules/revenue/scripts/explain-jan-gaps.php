<?php
$_GET['company_slug'] = 'ultimate';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/ultimate/';
$_SERVER['SCRIPT_NAME'] = '/ultimate/x.php';
require dirname(__DIR__, 3) . '/includes/config.php';

$cols = $pdo->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN);
echo "columns_sample: " . implode(', ', array_slice($cols, 0, 30)) . PHP_EOL;

$sql = "SELECT id, voucher_number, entry_date, customer_name, payment_status, approval_status,
               amount_total, source_invoice_id, narration
        FROM revenue_entries
        WHERE entry_date >= '2026-01-01' AND entry_date < '2026-02-01'
        ORDER BY entry_date ASC, id ASC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "january_count=" . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo sprintf(
        "%s\t%s\tid=%s\tpaid=%s\tapproval=%s\tinv=%s\t%s\n",
        $r['voucher_number'],
        $r['entry_date'],
        $r['id'],
        $r['payment_status'],
        $r['approval_status'] ?? '',
        $r['source_invoice_id'] ?? '',
        substr((string) $r['customer_name'], 0, 40)
    );
}
