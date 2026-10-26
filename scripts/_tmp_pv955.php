<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=ultitech_erp', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = $pdo->query("SHOW COLUMNS FROM payment_vouchers")->fetchAll(PDO::FETCH_COLUMN);
echo "has is_restricted: " . (in_array('is_restricted', $cols, true) ? 'yes' : 'no') . PHP_EOL;

$sql = 'SELECT id, status, IFNULL(is_posted,0) AS is_posted, IFNULL(is_paid,0) AS is_paid, created_by';
if (in_array('is_restricted', $cols, true)) {
    $sql .= ', is_restricted';
}
if (in_array('company_id', $cols, true)) {
    $sql .= ', company_id';
}
$sql .= ' FROM payment_vouchers WHERE id = 955';
$row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
var_export($row);
echo PHP_EOL;

$ap = $pdo->prepare("SELECT id, role, status, user_id FROM approvals WHERE voucher_id = 955 ORDER BY id");
$ap->execute();
foreach ($ap->fetchAll(PDO::FETCH_ASSOC) as $a) {
    echo "approval: " . json_encode($a) . PHP_EOL;
}
