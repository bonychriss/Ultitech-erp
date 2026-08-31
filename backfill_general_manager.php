<?php
// One-time backfill for payment_vouchers.general_manager
// Usage (CLI):
//   php scripts/backfill_general_manager.php --dry-run
//   php scripts/backfill_general_manager.php --apply
// Optional:
//   php scripts/backfill_general_manager.php --apply --limit=500
// Can also be opened via browser and toggled with ?mode=dry or ?mode=apply

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';

function out($msg) { echo $msg . (PHP_SAPI === 'cli' ? PHP_EOL : '<br>'); }

// Parse args
$mode = 'dry-run';
$limit = null;
if (PHP_SAPI === 'cli') {
    foreach ($argv as $arg) {
        if ($arg === '--apply') $mode = 'apply';
        if ($arg === '--dry-run') $mode = 'dry-run';
        if (strpos($arg, '--limit=') === 0) { $limit = (int)substr($arg, 8); }
    }
} else {
    $mode = (isset($_GET['mode']) && $_GET['mode'] === 'apply') ? 'apply' : 'dry-run';
    if (isset($_GET['limit'])) { $limit = (int)$_GET['limit']; }
}

out('Backfill General Manager (mode: ' . $mode . ')');

try {
    // 1) Identify eligible vouchers for each mapping
    $whereCommon = "pv.status='approved' AND (pv.general_manager IS NULL OR pv.general_manager='' OR pv.general_manager='RAJAB') AND pv.approved_by IS NOT NULL";

    // a) Approver email = rajabmwanyika@gmail.com -> GM = 'RAJABU MWANYIKA'
    $sqlCountMwanyika = "SELECT COUNT(*) AS c FROM payment_vouchers pv
        JOIN users u ON u.id = pv.approved_by
        WHERE $whereCommon AND LOWER(u.email) = 'rajabmwanyika@gmail.com'";
    $stmt = $pdo->query($sqlCountMwanyika);
    $countMwanyika = (int)($stmt->fetch()['c'] ?? 0);

    // b) Approver email = rajabmsomali@gmail.com -> GM = approver full_name (fallback username)
    $sqlCountMsomali = "SELECT COUNT(*) AS c FROM payment_vouchers pv
        JOIN users u ON u.id = pv.approved_by
        WHERE $whereCommon AND LOWER(u.email) = 'rajabmsomali@gmail.com'";
    $stmt = $pdo->query($sqlCountMsomali);
    $countMsomali = (int)($stmt->fetch()['c'] ?? 0);

    out("Eligible for RAJABU MWANYIKA: $countMwanyika");
    out("Eligible for approver full name (Msomali): $countMsomali");

    // Optionally show samples in dry-run
    if ($mode === 'dry-run') {
        $limClause = $limit ? ' LIMIT ' . (int)$limit : ' LIMIT 10';
        $sampleMwanyika = $pdo->query("SELECT pv.id, pv.voucher_no, pv.general_manager FROM payment_vouchers pv
            JOIN users u ON u.id = pv.approved_by
            WHERE $whereCommon AND LOWER(u.email)='rajabmwanyika@gmail.com' ORDER BY pv.id DESC $limClause")->fetchAll();
        $sampleMsomali = $pdo->query("SELECT pv.id, pv.voucher_no, pv.general_manager, u.full_name, u.username FROM payment_vouchers pv
            JOIN users u ON u.id = pv.approved_by
            WHERE $whereCommon AND LOWER(u.email)='rajabmsomali@gmail.com' ORDER BY pv.id DESC $limClause")->fetchAll();
        if (!empty($sampleMwanyika)) { out('Sample (RAJABU MWANYIKA):'); foreach ($sampleMwanyika as $r) { out(' - #' . $r['id'] . ' ' . $r['voucher_no'] . ' (current GM: ' . ($r['general_manager'] ?? '') . ')'); } }
        if (!empty($sampleMsomali)) { out('Sample (Msomali -> full_name/username):'); foreach ($sampleMsomali as $r) { out(' - #' . $r['id'] . ' ' . $r['voucher_no'] . ' (current GM: ' . ($r['general_manager'] ?? '') . ', full_name: ' . ($r['full_name'] ?? '') . ', username: ' . ($r['username'] ?? '') . ')'); } }
        out('Dry-run complete. Re-run with --apply to perform updates.');
        exit(0);
    }

    // 2) Apply updates in a single transaction
    $pdo->beginTransaction();

    // a) Set GM to RAJABU MWANYIKA for approver email = rajabmwanyika@gmail.com
    $sqlUpdateMwanyika = "UPDATE payment_vouchers pv
        JOIN users u ON u.id = pv.approved_by
        SET pv.general_manager = 'RAJABU MWANYIKA'
        WHERE $whereCommon AND LOWER(u.email) = 'rajabmwanyika@gmail.com'";
    if ($limit) { $sqlUpdateMwanyika .= ' ORDER BY pv.id DESC LIMIT ' . (int)$limit; }
    $affMwanyika = $pdo->exec($sqlUpdateMwanyika);

    // b) Set GM to approver full_name (fallback username) for approver email = rajabmsomali@gmail.com
    $sqlUpdateMsomali = "UPDATE payment_vouchers pv
        JOIN users u ON u.id = pv.approved_by
        SET pv.general_manager = COALESCE(NULLIF(TRIM(u.full_name), ''), NULLIF(TRIM(u.username), ''))
        WHERE $whereCommon AND LOWER(u.email) = 'rajabmsomali@gmail.com'";
    if ($limit) { $sqlUpdateMsomali .= ' ORDER BY pv.id DESC LIMIT ' . (int)$limit; }
    $affMsomali = $pdo->exec($sqlUpdateMsomali);

    $pdo->commit();

    out('Updated rows:');
    out(' - RAJABU MWANYIKA: ' . (int)$affMwanyika);
    out(' - Msomali -> full_name/username: ' . (int)$affMsomali);
    out('Done. You can re-run safely; the operation is idempotent.');

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    out('ERROR: ' . $e->getMessage());
    exit(1);
}
