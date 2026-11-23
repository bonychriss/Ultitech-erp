<?php
/**
 * Post opening balances, money movements and revenue to the double-entry ledger.
 *
 *   php scripts/gl-sync.php <company_slug>            dry run (everything rolled back)
 *   php scripts/gl-sync.php <company_slug> --apply    commit
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$slug = $argv[1] ?? 'ultimate';
$apply = in_array('--apply', $argv, true);
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/{$slug}";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
define('GL_AUTOSYNC', false);
require __DIR__ . '/../includes/config.php';
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/gl/gl_bridge.php';

global $pdo;
echo 'Database: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo $apply ? "MODE: APPLY\n\n" : "MODE: DRY RUN (nothing will be saved)\n\n";

if (!gl_ensure_schema($pdo)) {
    fwrite(STDERR, "Could not create ledger tables.\n");
    exit(1);
}

$stats = gl_bridge_sync_all($pdo, ['dry_run' => !$apply]);
echo "Entries posted:   {$stats['posted']}\n";
echo "Entries reversed: {$stats['reversed']}\n";
foreach ($stats['by_type'] as $type => $n) {
    echo sprintf("  %-24s %d\n", $type, $n);
}
if ($stats['errors']) {
    echo "\nErrors (" . count($stats['errors']) . "):\n";
    foreach ($stats['errors'] as $e) {
        echo "  - {$e}\n";
    }
}

if ($apply) {
    $tb = gl_trial_balance($pdo);
    $dr = array_sum(array_column($tb, 'debit'));
    $cr = array_sum(array_column($tb, 'credit'));
    echo "\nTrial balance: debits " . number_format($dr, 2) . ' / credits ' . number_format($cr, 2)
        . (abs($dr - $cr) < 0.005 ? "  BALANCED\n" : "  NOT BALANCED\n");
    foreach ($tb as $r) {
        $net = $r['debit'] - $r['credit'];
        echo sprintf("  %-8s %-45s %-9s %18s\n", $r['code'], mb_substr($r['name'], 0, 45), $r['type'], number_format($net, 2));
    }
}
exit($stats['errors'] ? 2 : 0);
