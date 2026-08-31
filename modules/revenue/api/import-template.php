<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';
require_once __DIR__ . '/../includes/revenue-import-lib.php';

revenueDeskRequireAccess();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="revenue-import-template.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, revenue_import_template_headers());

$year = (int) date('Y');
fputcsv($out, [
    'ABC TRADING LTD',
    'Office Chair',
    date('j-M', mktime(0, 0, 0, 4, 7, $year)),
    '100-123-456',
    '40-012345-A',
    '2',
    '450000.00',
    '18',
]);
fputcsv($out, [
    'Sunrise Hardware',
    'Printer Paper A4',
    date('j-M', mktime(0, 0, 0, 4, 9, $year)),
    '100-987-654',
    '',
    '10',
    '85000.00',
    '0',
]);
fputcsv($out, [
    'ABC TRADING LTD',
    'Consulting Hours',
    date('j-M', mktime(0, 0, 0, 4, 10, $year)),
    '100-123-456',
    '40-012345-A',
    '5',
    '750000.00',
    '18',
]);

fclose($out);
