<?php
$src = file_get_contents(__DIR__ . '/../../../revenue_entries.php');
if (!preg_match('/function ren_bootstrap/s', $src, $m, PREG_OFFSET_CAPTURE)) {
    fwrite(STDERR, "start not found\n");
    exit(1);
}
$start = $m[0][1];
if (!preg_match('/function ren_pages.*?\n\}/s', $src, $m2, PREG_OFFSET_CAPTURE, $start)) {
    fwrite(STDERR, "end not found\n");
    exit(1);
}
$end = $m2[0][1] + strlen($m2[0][0]);
$chunk = substr($src, $start, $end - $start);

$header = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Revenue entries desk data layer (shared by React API and CSV export).
 */

function revenue_entries_resolve_pdo(): PDO
{
    if (!function_exists('revenue_resolve_pdo')) {
        require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
    }
    $pdo = revenue_resolve_pdo();
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }
    global $pdo;
    $GLOBALS['pdo'] = $pdo;

    return $pdo;
}

PHP;

$out = __DIR__ . '/../includes/revenue-entries-lib.php';
file_put_contents($out, $header . $chunk);
echo "Wrote {$out}\n";
