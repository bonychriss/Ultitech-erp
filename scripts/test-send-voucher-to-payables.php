<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/view-voucher-ui/payables.php';

function validOutstandingDueDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE erp_outstanding_invoices (id INTEGER PRIMARY KEY, type TEXT, invoice_date TEXT, entity_name TEXT, invoice_number TEXT, narration TEXT, amount REAL, source_payment_voucher_id INTEGER UNIQUE, due_date TEXT, status TEXT DEFAULT 'outstanding')");
$voucher = [
    'id' => 1, 'status' => 'approved', 'currency' => 'TZS', 'payee_name' => 'Test supplier',
    'voucher_no' => 'TEST-PV', 'total_amount' => 1000, 'date_created' => '2026-11-09',
    'description' => 'Manual non-credit voucher', 'due_date' => '2026-12-09',
    'is_paid' => 0, 'is_posted' => 0, 'is_draft' => 0,
];
if (sendVoucherToPayables($pdo, $voucher) !== false || sendVoucherToPayables($pdo, $voucher) !== true) {
    throw new RuntimeException('Creation or duplicate detection failed.');
}
$rows = $pdo->query('SELECT * FROM erp_outstanding_invoices')->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 1 || (int) $rows[0]['source_payment_voucher_id'] !== 1
    || $rows[0]['due_date'] !== '2026-12-09' || (float) $rows[0]['amount'] !== 1000.0) {
    throw new RuntimeException('Created payable details were incorrect.');
}
foreach ([
    ['is_paid' => 1], ['is_posted' => 1], ['is_draft' => 1], ['status' => 'paid'],
    ['status' => 'cancelled'], ['currency' => 'USD'], ['total_amount' => 0],
    ['payee_name' => ''], ['date_created' => 'invalid'],
] as $changes) {
    try {
        sendVoucherToPayables($pdo, array_replace($voucher, ['id' => 2], $changes));
        throw new RuntimeException('Invalid voucher was accepted: ' . json_encode($changes));
    } catch (DomainException $expected) {
    }
}
$pdo->exec("UPDATE erp_outstanding_invoices SET status = 'paid'");
try {
    sendVoucherToPayables($pdo, $voucher);
    throw new RuntimeException('Closed payable was accepted.');
} catch (DomainException $expected) {
}
if ((int) $pdo->query('SELECT COUNT(*) FROM erp_outstanding_invoices')->fetchColumn() !== 1) {
    throw new RuntimeException('Invalid vouchers created payables.');
}
echo "Manual voucher to Payables cases passed.\n";
