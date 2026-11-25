<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/modules/sales/customers/includes/catalogue-lib.php';

class SummaryTestDatabase extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/^SHOW COLUMNS FROM (invoices|sales_orders)$/', $query, $matches)) {
            return parent::query("SELECT name FROM pragma_table_info('{$matches[1]}')");
        }
        return parent::query($query);
    }
}

$db = new SummaryTestDatabase('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE sales_orders (id INTEGER, currency TEXT)');
$db->exec('CREATE TABLE invoices (id INTEGER, order_id INTEGER, customer_id INTEGER, total_amount REAL, amount_paid REAL, status TEXT, currency TEXT)');
$db->exec("INSERT INTO sales_orders VALUES (1, 'TZS'), (2, 'USD'), (3, NULL)");
$db->exec("INSERT INTO invoices VALUES
    (1, 1, 7, 100, 25, 'sent', 'USD'),
    (2, 3, 7, 50, 10, 'paid', 'tzs'),
    (3, 2, 7, 999, 0, 'sent', 'TZS'),
    (4, 3, 7, 22, 0, 'sent', NULL),
    (5, 1, 7, 77, 0, 'cancelled', 'TZS'),
    (6, 1, 7, 88, 0, 'draft', 'TZS'),
    (7, 1, 8, 555, 0, 'sent', 'TZS')");

$summary = customerViewInvoiceSummary($db, 7, 'tzs');
if ($summary !== [
    'currency' => 'TZS',
    'total_sales' => 150.0,
    'total_payments' => 35.0,
    'outstanding' => 115.0,
    'unknown_currency_count' => 1,
]) {
    throw new RuntimeException('Unexpected invoice summary: ' . json_encode($summary));
}
$empty = customerViewInvoiceSummary($db, 999, 'TZS');
if ($empty['total_sales'] !== 0.0 || $empty['unknown_currency_count'] !== 0) {
    throw new RuntimeException('An empty customer must have zero totals.');
}
if (!isset(customerViewInvoiceSummary($db, 7, '')['error'])) {
    throw new RuntimeException('Missing customer currency must not produce totals.');
}
echo "Customer invoice summary cases passed.\n";
