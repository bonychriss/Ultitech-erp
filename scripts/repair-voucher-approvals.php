<?php
$slug = $argv[1] ?? 'roadmaster';
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/public_html/{$slug}/admin/all-vouchers.php";
$_SERVER['SCRIPT_NAME'] = '/public_html/admin/all-vouchers.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require __DIR__ . '/../includes/config.php';
voucher_bootstrap_operational_pdo();
ensureApprovalsTableSchema();

$idColumnOk = function_exists('ensureApprovalsTableIdColumn') ? ensureApprovalsTableIdColumn($pdo) : false;
echo "{$slug}: approvals id column repaired=" . ($idColumnOk ? 'yes' : 'no') . "\n";

$deduped = function_exists('repairVoucherApprovalDuplicates') ? repairVoucherApprovalDuplicates($pdo, null, 200) : 0;
echo "{$slug}: deduped {$deduped} vouchers\n";

$promoted = function_exists('repairStuckConfirmingVouchers') ? repairStuckConfirmingVouchers($pdo, 200) : 0;
echo "{$slug}: promoted {$promoted} confirming vouchers to pending\n";

$repaired = function_exists('repairMissingVoucherApprovalRows') ? repairMissingVoucherApprovalRows($pdo, 100) : 0;
echo "{$slug}: backfilled {$repaired} missing approval rows\n";

$missing = (int) $pdo->query(
    "SELECT COUNT(*) FROM payment_vouchers pv
     WHERE NOT EXISTS (SELECT 1 FROM approvals a WHERE a.voucher_id = pv.id)
     AND pv.status IN ('pending','confirming','approved')
     AND COALESCE(pv.applicant,'') <> ''"
)->fetchColumn();
echo "still missing approval rows: {$missing}\n";

$stuck = (int) $pdo->query(
    "SELECT COUNT(*) FROM payment_vouchers WHERE status = 'confirming'"
)->fetchColumn();
echo "confirming vouchers remaining: {$stuck}\n";
