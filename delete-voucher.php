<?php
require_once 'includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my-vouchers.php');
    exit();
}

$voucher_id = isset($_POST['voucher_id']) ? (int)$_POST['voucher_id'] : 0;
if ($voucher_id <= 0) {
    header('Location: my-vouchers.php?error=invalid');
    exit();
}

if (!canDeleteVoucher($voucher_id, $_SESSION['user_id'])) {
    header('Location: my-vouchers.php?error=forbidden');
    exit();
}

try {
    $stmt = $pdo->prepare('DELETE FROM payment_vouchers WHERE id = ?');
    $stmt->execute([$voucher_id]);
    header('Location: my-vouchers.php?msg=deleted');
    exit();
} catch (Exception $e) {
    header('Location: my-vouchers.php?error=delete_failed');
    exit();
}
