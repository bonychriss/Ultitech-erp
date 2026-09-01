<?php
$slug = $argv[1] ?? 'ultimate';
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/public_html/{$slug}/admin/all-vouchers.php";
$_SERVER['SCRIPT_NAME'] = '/public_html/admin/all-vouchers.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
require __DIR__ . '/../includes/config.php';
voucher_bootstrap_operational_pdo();
ensureApprovalsTableSchema();
$repaired = repairMissingVoucherApprovalRows($pdo, 100);
echo "{$slug}: repaired {$repaired} vouchers\n";
$missing = (int) $pdo->query(
    "SELECT COUNT(*) FROM payment_vouchers pv
     WHERE NOT EXISTS (SELECT 1 FROM approvals a WHERE a.voucher_id = pv.id)
     AND pv.status IN ('pending','confirming','approved')
     AND COALESCE(pv.applicant,'') <> ''"
)->fetchColumn();
echo "still missing: {$missing}\n";
