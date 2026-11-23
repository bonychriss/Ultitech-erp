<?php
declare(strict_types=1);

$page = file_get_contents(dirname(__DIR__) . '/erp/outstanding-invoices/index.php');
if ($page === false) {
    throw new RuntimeException('Unable to read outstanding invoices page.');
}
$start = strpos($page, '$search = trim(');
$end = $start === false ? false : strpos($page, '$pageRows = $displayRows;', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Unable to locate outstanding sorting logic.');
}
$sorting = substr($page, $start, $end - $start);
$invoices = [
    ['id' => 2, 'invoice_date' => '2026-03-01', 'entity_name' => 'Beta', 'invoice_number' => 'PV-2', 'amount' => 20],
    ['id' => 1, 'invoice_date' => '2026-02-01', 'entity_name' => 'Alpha', 'invoice_number' => 'PV-1', 'amount' => 10],
    ['id' => 3, 'invoice_date' => '2020-01-01', 'entity_name' => 'Gamma', 'invoice_number' => 'PV-3', 'amount' => 30],
];

$check = static function (string $tab, array $query, array $expected, string $expectedSort) use ($sorting, $invoices): void {
    $activeTab = $tab;
    $_GET = $query;
    eval($sorting);
    if (array_column($displayRows, 'id') !== $expected || $sortKey !== $expectedSort) {
        throw new RuntimeException('Unexpected order for ' . json_encode([$tab, $query, array_column($displayRows, 'id'), $sortKey]));
    }
};

$check('payables', [], [3, 2, 1], 'recent');
$check('receivables', [], [2, 1, 3], 'date');
$check('payables', ['sort' => 'date', 'dir' => 'desc'], [2, 1, 3], 'date');
$check('payables', ['sort' => 'date', 'dir' => 'asc'], [3, 1, 2], 'date');
$check('payables', ['sort' => 'name', 'dir' => 'asc'], [1, 2, 3], 'name');
$check('payables', ['sort' => 'amount', 'dir' => 'desc'], [3, 2, 1], 'amount');
$check('payables', ['q' => 'PV-3'], [3], 'recent');
echo "Outstanding invoice sort cases passed.\n";
