<?php
$slug = $argv[1] ?? 'roadmaster';
$_GET = ['company_slug' => $slug];
$_SERVER['REQUEST_URI'] = "/public_html/{$slug}/admin/all-vouchers.php";
$_SERVER['SCRIPT_NAME'] = '/public_html/admin/all-vouchers.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
require __DIR__ . '/../includes/config.php';

echo "=== {$slug} ===\n";
echo 'tenant DB=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'IS_TENANT_DB=' . (defined('IS_TENANT_DB') && IS_TENANT_DB ? 'yes' : 'no') . "\n";

$erp = erp_data_pdo();
echo 'erp_data_pdo DB=' . ($erp ? $erp->query('SELECT DATABASE()')->fetchColumn() : 'null') . "\n";
echo 'erp has payees=' . ($erp && erp_connection_has_table($erp, 'payees') ? 'yes' : 'no') . "\n";

$op = voucher_operational_pdo();
echo 'voucher_operational_pdo DB=' . ($op ? $op->query('SELECT DATABASE()')->fetchColumn() : 'null') . "\n";

$tenantBefore = $pdo->query('SELECT DATABASE()')->fetchColumn();
voucher_bootstrap_operational_pdo();
$tenantAfter = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "bootstrap switched DB: " . ($tenantBefore !== $tenantAfter ? "YES {$tenantBefore} -> {$tenantAfter}" : 'no') . "\n";

$prefix = getCurrentPaymentVoucherSequencePrefix($pdo, currentCompanyId());
echo 'voucher prefix=' . $prefix . "\n";
$seqPdo = documentSequencesPdo($pdo);
echo 'documentSequencesPdo DB=' . ($seqPdo ? $seqPdo->query('SELECT DATABASE()')->fetchColumn() : 'null') . "\n";
