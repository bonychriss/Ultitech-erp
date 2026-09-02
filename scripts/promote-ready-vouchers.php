<?php
$slug = $argv[1] ?? 'roadmaster';
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/{$slug}";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require __DIR__ . '/../includes/config.php';
voucher_bootstrap_operational_pdo();

$rows = $pdo->query(
    "SELECT id, voucher_no, applicant, department_manager, checked_by, status
     FROM payment_vouchers WHERE status = 'confirming' ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$ready = [];
foreach ($rows as $v) {
    if (voucherCoreApprovalRolesComplete($pdo, (int) $v['id'], $v)) {
        $ready[] = $v['voucher_no'];
    }
}
echo 'ready to promote: ' . count($ready) . "\n";
echo implode("\n", $ready) . "\n";
$promoted = repairStuckConfirmingVouchers($pdo, 500);
echo "promoted: {$promoted}\n";
