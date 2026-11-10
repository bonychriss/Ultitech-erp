<?php

declare(strict_types=1);

$guardTestState = ['enabled' => false, 'configured' => [], 'options' => []];

function accounting_settings_get(PDO $pdo, string $key): ?string
{
    global $guardTestState;
    return $guardTestState['enabled'] ? '1' : null;
}

function accounting_get_gl_role_account_id(PDO $pdo, string $role): ?int
{
    global $guardTestState;
    return $guardTestState['configured'][$role] ?? null;
}

function accounting_gl_role_options(PDO $pdo, string $role): array
{
    global $guardTestState;
    return $guardTestState['options'][$role];
}

function invoice_gl_journal_reference_exists(PDO $pdo, string $reference): bool
{
    return false;
}

function invoice_gl_load_invoice_row(PDO $pdo, int $invoiceId): array
{
    return ['invoice_number' => 'INV-1', 'invoice_date' => '2026-01-01'];
}

function invoice_gl_split_amounts(array $row): array
{
    return ['total' => 100, 'revenue' => 100, 'tax' => 0];
}

function accounting_resolve_gl_role_account_id(PDO $pdo, string $role): ?int
{
    return ['receivable' => 30, 'output_tax' => 40, 'cogs' => 10, 'inventory' => 20][$role];
}

function accounting_resolve_default_sales_revenue_gl_account_id(PDO $pdo): ?int
{
    return 50;
}

function invoice_gl_post_balanced_entry(PDO $pdo, string $date, string $reference, string $description, array $items): int
{
    global $postedItems;
    $postedItems = $items;
    return 123;
}

require_once __DIR__ . '/../includes/invoice_gl_posting.php';

$pdo = new PDO('sqlite::memory:');
$guardTestState['configured'] = ['cogs' => 10, 'inventory' => 20];
$guardTestState['options'] = [
    'cogs' => ['filtered' => true, 'options' => [['id' => 10]]],
    'inventory' => ['filtered' => true, 'options' => [['id' => 20]]],
];

if (invoice_gl_require_stock_accounts($pdo) !== null) {
    throw new RuntimeException('An inactive guard must preserve existing behavior.');
}
$guardTestState['enabled'] = true;
if (invoice_gl_require_stock_accounts($pdo) !== ['cogs' => 10, 'inventory' => 20]) {
    throw new RuntimeException('Valid mappings were not returned.');
}

foreach (['missing', 'unmatched', 'inactive'] as $case) {
    $saved = $guardTestState;
    if ($case === 'missing') {
        unset($guardTestState['configured']['cogs']);
    } elseif ($case === 'unmatched') {
        $guardTestState['options']['inventory']['filtered'] = false;
    } else {
        $guardTestState['options']['cogs']['options'] = [];
    }
    try {
        invoice_gl_require_stock_accounts($pdo);
        throw new LogicException('Expected ' . $case . ' mapping to be rejected.');
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'Accounting Settings') === false) {
            throw $e;
        }
    }
    $guardTestState = $saved;
}

$pdo->exec('CREATE TABLE invoices (id INTEGER, order_id INTEGER)');
$pdo->exec('CREATE TABLE sales_order_items (order_id INTEGER, product_id INTEGER, quantity REAL)');
$pdo->exec('CREATE TABLE products (id INTEGER, buying_price REAL, cost_price REAL)');
$pdo->exec('INSERT INTO invoices VALUES (1, 1)');
$pdo->exec('INSERT INTO sales_order_items VALUES (1, 1, 2)');
$pdo->exec('INSERT INTO products VALUES (1, 15, 20)');
$postedItems = [];
if (invoice_gl_ensure_invoice_recognition($pdo, 1) !== 123
    || $postedItems[2] !== ['account_id' => 10, 'debit' => 30.0, 'credit' => 0]
    || $postedItems[3] !== ['account_id' => 20, 'debit' => 0, 'credit' => 30.0]
) {
    throw new RuntimeException('Invoice cost was not posted to the configured stock accounts.');
}

$postedItems = [];
unset($guardTestState['configured']['inventory']);
try {
    invoice_gl_ensure_invoice_recognition($pdo, 1);
    throw new LogicException('Invoice posting accepted a missing inventory mapping.');
} catch (RuntimeException $e) {
    if ($postedItems !== []) {
        throw new RuntimeException('Invoice journal was posted despite invalid stock mappings.', 0, $e);
    }
}

echo "Invoice stock mapping guard checks passed.\n";
