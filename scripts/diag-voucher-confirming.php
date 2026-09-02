<?php
$slug = $argv[1] ?? 'roadmaster';
$onlyId = isset($argv[2]) ? (int) $argv[2] : 0;
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/{$slug}/admin/dashboard";
$_SERVER['SCRIPT_NAME'] = '/admin/dashboard.php';
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require __DIR__ . '/../includes/config.php';
voucher_bootstrap_operational_pdo();

$rows = $pdo->query(
    $onlyId > 0
        ? "SELECT pv.id, pv.voucher_no, pv.status, pv.applicant, pv.department_manager, pv.checked_by
           FROM payment_vouchers pv WHERE pv.id = {$onlyId}"
        : "SELECT pv.id, pv.voucher_no, pv.status, pv.applicant, pv.department_manager, pv.checked_by
           FROM payment_vouchers pv
           WHERE pv.status = 'confirming'
           ORDER BY pv.id DESC
           LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as $v) {
    $id = (int) ($v['id'] ?? 0);
    $appr = $pdo->prepare('SELECT id, role, status, approver_name FROM approvals WHERE voucher_id = ? ORDER BY id');
    $appr->execute([$id]);
    $approvals = $appr->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pending = function_exists('countPendingEmployeeApprovalRoles')
        ? countPendingEmployeeApprovalRoles($pdo, $id)
        : -1;
    $core = function_exists('voucherCoreApprovalRolesComplete')
        ? (voucherCoreApprovalRolesComplete($pdo, $id, $v) ? 'yes' : 'no')
        : 'n/a';
    echo json_encode([
        'voucher' => $v,
        'approvals' => $approvals,
        'pending_count' => $pending,
        'core_complete' => $core,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n---\n";
}
