<?php
require_once '../includes/functions.php';
requireLogin();

// Only Finance department users can mark as posted
if (!isFinance()) {
    http_response_code(403);
    echo 'Forbidden: Only Finance department can mark vouchers as posted.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$voucher_id = isset($_POST['voucher_id']) ? (int)$_POST['voucher_id'] : 0;
if ($voucher_id <= 0) {
    http_response_code(400);
    echo 'Invalid voucher id';
    exit;
}

try {
    ensurePostedColumnsOnPaymentVouchers();

    // Verify voucher exists
    $stmt = $pdo->prepare('SELECT id, is_posted FROM payment_vouchers WHERE id = ? LIMIT 1');
    $stmt->execute([$voucher_id]);
    $v = $stmt->fetch();
    if (!$v) {
        http_response_code(404);
        echo 'Voucher not found';
        exit;
    }
    if ((int)$v['is_posted'] === 1) {
        // Already marked posted
        header('Location: view-voucher.php?id=' . $voucher_id);
        exit;
    }

    $upd = $pdo->prepare('UPDATE payment_vouchers SET is_posted = 1, posted_by = ?, posted_at = NOW(), updated_at = NOW() WHERE id = ?');
    $upd->execute([(int)$_SESSION['user_id'], $voucher_id]);

    // Optional: create a notification for the creator
    try {
        createNotification([
            'user_id' => null,
            'audience' => 'user',
            'title' => 'Voucher posted',
            'message' => 'Voucher has been marked as posted by Finance.',
            'voucher_id' => $voucher_id,
        ]);
    } catch (Throwable $e) { /* ignore */ }

    header('Location: view-voucher.php?id=' . $voucher_id);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
