<?php
/**
 * Read-only: ledger trial balance and reconciliation checks for one company.
 *
 * Usage: php scripts/gl-ledger-report.php --company=ultimate|--db=<database> [--from=2026-01-01] [--to=2026-12-31]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/gl/gl_reports.php';

// Read options after functions.php: it defines globals such as $slug.
$opts = getopt('', ['company::', 'db::', 'from::', 'to::']);
$companySlug = strtolower(trim((string) ($opts['company'] ?? '')));
$from = (string) ($opts['from'] ?? date('Y') . '-01-01');
$to = (string) ($opts['to'] ?? date('Y-m-d'));

if (!empty($opts['db'])) {
    $c = ['db_name' => (string) $opts['db'], 'db_host' => null, 'db_user' => null, 'db_pass' => null];
} else {
    $control = $GLOBALS['control_pdo'] ?? $GLOBALS['pdo'] ?? null;
    $st = $control->prepare('SELECT company_slug, db_name, db_host, db_user, db_pass FROM companies WHERE LOWER(company_slug) = ? LIMIT 1');
    $st->execute([$companySlug]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        fwrite(STDERR, "Company '{$companySlug}' not found.\n");
        exit(1);
    }
}
$pdo = connectToTenantDatabase((string) $c['db_name'], $c['db_host'] ?: null, $c['db_user'] ?: null, $c['db_pass'] ?: null);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

$n = static fn ($v): string => number_format((float) $v, 2);
echo "DB {$c['db_name']}  source=" . gl_report_source($pdo) . '  autosync=' . (gl_meta_get($pdo, 'autosync') ?? '-') . "  period {$from}..{$to}\n";

echo "\n== Trial balance (ledger)\n";
$dr = $cr = 0.0;
foreach (gl_account_movements($pdo, $from, $to) as $a) {
    printf("  %-6s %-42s %-9s %16s %16s %16s %16s\n", $a['code'], mb_strimwidth($a['name'], 0, 42), $a['type'], $n($a['opening']), $n($a['debit']), $n($a['credit']), $n($a['closing']));
    $dr += $a['debit'];
    $cr += $a['credit'];
}
echo "  TOTAL period Dr {$n($dr)} Cr {$n($cr)}\n";

echo "\n== Money accounts (saved vs ledger)\n";
foreach (gl_report_money_accounts($pdo) as $a) {
    if ($a['tx_count'] === 0 && abs($a['stored']) < 0.005 && abs($a['ledger']) < 0.005) {
        continue;
    }
    printf("  %-34s tx %5d saved %16s computed %16s ledger %16s\n", mb_strimwidth($a['name'], 0, 34), $a['tx_count'], $n($a['stored']), $n($a['computed']), $n($a['ledger']));
}

$ar = gl_report_receivables_check($pdo);
echo "\n== Receivables: ledger {$n($ar['ledger'])} vs outstanding on revenue entries {$n($ar['outstanding'])} (receipts not in any money account {$n($ar['unrecorded_receipts'])})\n";

$sus = gl_report_suspense_lines($pdo);
$sum = array_sum(array_map(static fn ($s) => (float) $s['amount'], $sus));
echo "\n== Suspense: " . count($sus) . " lines, {$n($sum)}\n";
$byMemo = [];
foreach ($sus as $s) {
    $k = preg_replace('/:.*$/', '', (string) $s['memo']);
    $byMemo[$k] = ($byMemo[$k] ?? 0) + (float) $s['amount'];
}
arsort($byMemo);
foreach (array_slice($byMemo, 0, 15, true) as $k => $v) {
    echo "  {$n($v)}  {$k}\n";
}
echo '  pending transactions: ' . gl_report_pending_sources($pdo) . "\n";
