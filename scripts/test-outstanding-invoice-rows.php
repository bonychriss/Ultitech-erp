<?php
declare(strict_types=1);

// Run with PHP CLI and the PDO SQLite extension; no application database is modified.
function tableExists(string $table, PDO $pdo): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function columnExists(string $table, string $column, PDO $pdo): bool
{
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $info) {
        if ($info['name'] === $column) {
            return true;
        }
    }
    return false;
}

$source = file_get_contents(dirname(__DIR__) . '/includes/functions.php');
if ($source === false) {
    throw new RuntimeException('Unable to read outstanding invoice helper.');
}
$start = strpos($source, 'function outstandingInvoiceRows(PDO $connection): array');
$end = $start === false ? false : strpos($source, 'function syncOutstandingRevenueBalance(', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Unable to locate outstanding invoice helper.');
}
eval(substr($source, $start, $end - $start));

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE erp_outstanding_invoices (id INTEGER, type TEXT, status TEXT, amount REAL, invoice_date TEXT, due_date TEXT, source_revenue_entry_id INTEGER, source_invoice_id INTEGER, source_payment_voucher_id INTEGER)");
$db->exec('CREATE TABLE revenue_entries (id INTEGER, amount_total REAL, total_paid REAL)');
$db->exec('CREATE TABLE invoices (id INTEGER, status TEXT)');
$db->exec('CREATE TABLE payment_vouchers (id INTEGER, is_paid INTEGER, status TEXT, due_date TEXT)');
$db->exec("INSERT INTO invoices VALUES (1,'paid'),(2,'partial'),(3,'cancelled')");
$db->exec('INSERT INTO revenue_entries VALUES (2,100,40),(3,100,100),(4,100,0)');
$db->exec("INSERT INTO payment_vouchers VALUES (5,1,'approved',NULL),(6,0,'paid',NULL),(7,0,'approved','2026-12-01')");
$db->exec("INSERT INTO erp_outstanding_invoices VALUES
  (1,'receivable','outstanding',100,'2026-01-10',NULL,NULL,1,NULL),
  (2,'receivable','outstanding',100,'2026-01-09',NULL,2,2,NULL),
  (3,'receivable','outstanding',100,'2026-01-08',NULL,3,NULL,NULL),
  (4,'receivable','paid',100,'2026-01-07',NULL,4,NULL,NULL),
  (5,'payable','outstanding',100,'2026-01-06',NULL,NULL,NULL,5),
  (6,'payable','outstanding',100,'2026-01-05',NULL,NULL,NULL,6),
  (7,'payable','outstanding',100,'2026-01-04',NULL,NULL,NULL,7),
  (8,'payable','paid',100,'2026-01-03',NULL,NULL,NULL,NULL),
  (9,'receivable','outstanding',25,'2026-01-02',NULL,NULL,NULL,NULL),
  (10,'receivable','outstanding',25,'2026-01-01',NULL,NULL,3,NULL)");

function expectIds(array $rows, array $expected): void
{
    if (array_column($rows, 'id') !== $expected) {
        throw new RuntimeException('Unexpected outstanding rows: ' . json_encode($rows));
    }
}

$rows = outstandingInvoiceRows($db);
expectIds($rows, [2, 7, 9]);
if ($rows[0]['amount'] !== 60.0 || !$rows[0]['partially_paid'] || $rows[1]['due_date'] !== '2026-12-01') {
    throw new RuntimeException('Partial balance or voucher due date was incorrect.');
}
$db->exec('UPDATE revenue_entries SET total_paid = 100 WHERE id = 2');
$db->exec('UPDATE payment_vouchers SET is_paid = 1 WHERE id = 7');
expectIds(outstandingInvoiceRows($db), [9]);
$db->exec("UPDATE revenue_entries SET total_paid = 0 WHERE id = 2");
$db->exec("UPDATE payment_vouchers SET is_paid = 0 WHERE id = 7");
$rows = outstandingInvoiceRows($db);
expectIds($rows, [2, 7, 9]);
if ($rows[0]['amount'] !== 100.0 || $rows[0]['partially_paid']) {
    throw new RuntimeException('Reopened unpaid balance was incorrect.');
}
$db->exec('DROP TABLE payment_vouchers');
$db->exec('CREATE TABLE payment_vouchers (id INTEGER, is_paid INTEGER)');
$db->exec('INSERT INTO payment_vouchers VALUES (7,1)');
expectIds(outstandingInvoiceRows($db), [2, 5, 6, 9]);
$db->exec('DROP TABLE payment_vouchers');
expectIds(outstandingInvoiceRows($db), [2, 5, 6, 7, 9]);
echo "Outstanding invoice query cases passed.\n";
