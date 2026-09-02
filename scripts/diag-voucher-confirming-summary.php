<?php
$slug = $argv[1] ?? 'roadmaster';
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/{$slug}";
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require __DIR__ . '/../includes/config.php';
voucher_bootstrap_operational_pdo();

$rows = $pdo->query(
    "SELECT id, voucher_no, status, applicant, department_manager, checked_by
     FROM payment_vouchers
     WHERE status = 'confirming'
     ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as $v) {
    $id = (int) $v['id'];
    $core = voucherCoreApprovalRolesComplete($pdo, $id, $v) ? 'READY' : 'waiting';
    $appr = $pdo->prepare("SELECT role, status FROM approvals WHERE voucher_id = ?");
    $appr->execute([$id]);
    $statuses = [];
    foreach ($appr->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $rk = normalizeVoucherApprovalRoleKey($a['role'] ?? '');
        $statuses[$rk] = $a['status'] ?? '';
    }
    echo sprintf(
        "%s %s core=%s applicant=%s dm=%s check=%s\n",
        $v['voucher_no'],
        $v['status'],
        $core,
        $statuses['applicant'] ?? '-',
        $statuses['department manager'] ?? '-',
        $statuses['checked by'] ?? '-'
    );
}
