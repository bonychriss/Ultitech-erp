<?php

require_once '../../../includes/functions.php';
requireLogin();
require_once __DIR__ . '/../includes/balances_integration.php';
require_once __DIR__ . '/../includes/import_helpers.php';

$samples = ['FUEL', 'TRANSPORT', 'AIRTIME'];
try {
    $ctx = expenses_import_build_account_context($pdo);
    $names = [];
    foreach ($ctx['expense_options'] as $option) {
        $name = trim((string) ($option['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    if ($names !== []) {
        $samples = [
            $names[0],
            $names[1] ?? $names[0],
            $names[2] ?? $names[0],
        ];
    }
} catch (Throwable $e) {
    error_log('import-template samples: ' . $e->getMessage());
}

$filename = 'expenses-import-template.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'wb');

fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, expenses_import_template_headers());

$year = (int) date('Y');
fputcsv($out, [
    date('j-M', mktime(0, 0, 0, 4, 7, $year)),
    $samples[0],
    '4000.00',
    '4000.00',
]);
fputcsv($out, [
    date('j-M', mktime(0, 0, 0, 4, 9, $year)),
    $samples[1],
    '20000.00',
    '16949.15',
]);
fputcsv($out, [
    date('j-M', mktime(0, 0, 0, 4, 10, $year)),
    $samples[2],
    '10000.00',
    '10000.00',
]);

fclose($out);
