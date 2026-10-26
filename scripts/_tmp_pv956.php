<?php
require __DIR__ . '/../includes/config.php';
$s = $pdo->prepare('SELECT id, voucher_no, status, created_by, IFNULL(is_posted,0) is_posted, company_id FROM payment_vouchers WHERE id=956');
$s->execute();
$v = $s->fetch(PDO::FETCH_ASSOC);
echo "VOUCHER:\n";
var_export($v);
echo "\nUSERS:\n";
$u = $pdo->query("SELECT id, username, full_name, role, department, status FROM users WHERE full_name LIKE '%MUDATHIR%' OR username LIKE '%mudathir%' OR full_name LIKE '%Magina%' OR id=" . (int)($v['created_by'] ?? 0));
var_export($u->fetchAll(PDO::FETCH_ASSOC));
echo "\n";