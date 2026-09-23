<?php
require __DIR__ . '/includes/functions.php';
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}
global $pdo;
$id = 642;
$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "DB={$db}\n";
$cols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN);
$hasPoIds = in_array('linked_stock_po_ids', $cols, true);
$sql = 'SELECT id, voucher_no, supporting_documents, linked_stock_po_id'
    . ($hasPoIds ? ', linked_stock_po_ids' : '')
    . ' FROM payment_vouchers WHERE id=?';
$s = $pdo->prepare($sql);
$s->execute([$id]);
$row = $s->fetch(PDO::FETCH_ASSOC);
echo "voucher=\n";
print_r($row);

$a = $pdo->prepare('SELECT id, voucher_id, file_path, original_name, mime_type, size_bytes FROM voucher_attachments WHERE voucher_id=?');
$a->execute([$id]);
$rows = $a->fetchAll(PDO::FETCH_ASSOC);
echo 'att_count=' . count($rows) . "\n";
print_r($rows);

if (function_exists('getVoucherAttachments')) {
    $g = getVoucherAttachments($id);
    echo 'getVoucherAttachments=' . count($g) . "\n";
    print_r($g);
}

// Also check recent vouchers with attachments
$r = $pdo->query('SELECT voucher_id, COUNT(*) c FROM voucher_attachments GROUP BY voucher_id ORDER BY voucher_id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
echo "recent attachment counts:\n";
print_r($r);
