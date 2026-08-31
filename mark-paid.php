<?php
require_once 'includes/functions.php';
require_once 'modules/balances/functions.php';
requireLogin();

// Only Finance department users or Admins can mark as paid
if (!isFinance() && !isAdmin()) {
    http_response_code(403);
    echo 'Forbidden: Only Finance department or Admins can confirm payments.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$voucher_id = isset($_POST['voucher_id']) ? (int)$_POST['voucher_id'] : 0;
$returnParams = '';
if (isset($_POST['return']) && $_POST['return'] === 'finance') {
    $returnParams = '&return=finance';
}
if ($voucher_id <= 0) {
    http_response_code(400);
    echo 'Invalid voucher id';
    exit;
}

try {
    // Ensure columns exist
    ensurePaidColumnsOnPaymentVouchers();
    ensureSwiftDocumentColumn();
    ensurePostedColumnsOnPaymentVouchers();

    // Verify voucher exists and fetch all required metadata for Balances & Unified Ledger
    $stmt = $pdo->prepare('SELECT id, voucher_no, payee_name, total_amount, currency, is_paid, status, approved_by FROM payment_vouchers WHERE id = ? LIMIT 1');
    $stmt->execute([$voucher_id]);
    $v = $stmt->fetch();
    if (!$v) {
        http_response_code(404);
        echo 'Voucher not found';
        exit;
    }

    // Enforce: voucher must be approved by an admin before finance can mark paid (mirror strict logic)
    $statusLower = strtolower((string)($v['status'] ?? ''));
    if ($statusLower !== 'approved') {
        http_response_code(400);
        echo 'Voucher must be approved before payment.';
        exit;
    }
    if (!isAdmin()) { // finance path
        $approverId = isset($v['approved_by']) ? (int)$v['approved_by'] : 0;
        if ($approverId <= 0) {
            http_response_code(400);
            echo 'Voucher lacks admin approval.';
            exit;
        }
        $u = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_active = 1');
        $u->execute([$approverId]);
        $ur = $u->fetch();
        if (!$ur || (string)$ur['role'] !== ROLE_ADMIN) {
            http_response_code(400);
            echo 'Voucher must be approved by an admin before Finance can mark paid.';
            exit;
        }
    }
    if ((int)$v['is_paid'] === 1) {
        // Already marked paid
        header('Location: view-voucher.php?id=' . $voucher_id . $returnParams);
        exit;
    }

    // Validate file upload (require SWIFT proof)
    if (!isset($_FILES['swift_file']) || !is_array($_FILES['swift_file'])) {
        http_response_code(400);
        echo 'SWIFT proof file is required.';
        exit;
    }
    $file = $_FILES['swift_file'];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        if ((int)$file['error'] === UPLOAD_ERR_INI_SIZE || (int)$file['error'] === UPLOAD_ERR_FORM_SIZE) {
            // Exceeded server/file form limits
            $uploadMax = ini_get('upload_max_filesize');
            $postMax = ini_get('post_max_size');
            http_response_code(400);
            echo 'File is larger than the server limit (upload_max_filesize=' . htmlspecialchars((string)$uploadMax) . ', post_max_size=' . htmlspecialchars((string)$postMax) . '). Please increase server limits and try again.';
            exit;
        }
        http_response_code(400);
        echo 'Upload failed. Please try again.';
        exit;
    }
    // Enforce max size up to server limits (upload_max_filesize & post_max_size)
    $toBytes = function($val){
        $val = trim((string)$val);
        if ($val === '') return 0;
        $unit = strtolower(substr($val, -1));
        $num = (float)$val;
        switch ($unit) {
            case 'g': $num *= 1024; // fallthrough
            case 'm': $num *= 1024; // fallthrough
            case 'k': $num *= 1024; break;
            default: /* bytes */
        }
        return (int)round($num);
    };
    $serverLimit = min(
        max(1, $toBytes(ini_get('upload_max_filesize') ?: '10M')),
        max(1, $toBytes(ini_get('post_max_size') ?: '10M'))
    );
    if ((int)$file['size'] <= 0 || (int)$file['size'] > $serverLimit) {
        http_response_code(400);
        $mb = max(1, floor($serverLimit / 1024 / 1024));
        echo 'File must be greater than 0 bytes and not exceed server limit of ' . $mb . 'MB.';
        exit;
    }
    // Validate type by MIME and extension
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // some browsers may send octet-stream for PDFs or Office files; allow with extension check below
        'application/octet-stream'
    ];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['pdf','jpg','jpeg','png','gif','webp','svg','bmp','doc','docx','xls','xlsx'];
    if (!in_array($mime, $allowedMimes, true) || !in_array($ext, $allowedExt, true)) {
        http_response_code(400);
        echo 'Only PDF, Image, or Office files are allowed (pdf, jpg, doc, docx, xls, xlsx, etc.).';
        exit;
    }

    // Prepare destination path
    $baseDir = ensureVoucherUploadsDir(); // .../assets/uploads/vouchers
    $voucherDir = $baseDir . '/' . $voucher_id;
    if (!is_dir($voucherDir)) { @mkdir($voucherDir, 0775, true); }
    if (is_dir($voucherDir) && !is_writable($voucherDir)) { @chmod($voucherDir, 0775); }
    $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'dat';
    $filename = 'swift-proof-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $safeExt;
    $destPath = $voucherDir . '/' . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo 'Failed to store uploaded file.';
        exit;
    }
    // Build relative path to serve via web
    $relPath = 'assets/uploads/vouchers/' . $voucher_id . '/' . $filename;

    // Balances: Record Transaction before marking paid
    $pay_account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
    $amount = (float)($v['total_amount'] ?? 0);
    if ($pay_account_id > 0) {
        $desc = "Payment for Voucher #{$v['voucher_no']} to {$v['payee_name']}";
        // Outflow = 'debit' in the Balances module logic
        $success = recordTransaction($pay_account_id, 'debit', $amount, $desc, 'payment_voucher', $voucher_id);
        if (!$success) {
            throw new Exception("Failed to deduct amount from the selected account.");
        }
    }

    // Persist swift_document path and mark as paid
    $upd = $pdo->prepare('UPDATE payment_vouchers SET swift_document = ?, is_paid = 1, paid_by = ?, payment_account_id = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ?');
    $upd->execute([$relPath, (int)$_SESSION['user_id'], $pay_account_id > 0 ? $pay_account_id : null, $voucher_id]);

    // Fetch new balance for the toast
    $newBal = 0;
    $currency = 'TZS';
    if ($pay_account_id > 0) {
        $aStmt = $pdo->prepare("SELECT current_balance, currency FROM financial_accounts WHERE id = ?");
        $aStmt->execute([$pay_account_id]);
        $acc = $aStmt->fetch();
        if ($acc) {
            $newBal = (float)$acc['current_balance'];
     lo-            }
    }

    // Optional: create a notification for the creator
    try {
        createNotification([
            'user_id' => null,
            'audience' => 'user',
            'title' => 'Voucher paid',
            'message' => "Voucher has been marked as paid. Deducted: {$currency} " . number_format($amount, 2) . ". New Balance: {$currency} " . number_format($newBal, 2),
            'voucher_id' => $voucher_id,
        ]);
    } catch (Throwable $e) { /* ignore */ }

    $_SESSION['success_msg'] = "Voucher marked as paid. Deducted: {$currency} " . number_format($amount, 2) . ". New Balance: {$currency} " . number_format($newBal, 2);
    
    $redirectUrl = 'view-voucher.php?id=' . $voucher_id . '&paid=1&deducted=' . $amount . '&balance=' . $newBal . '&currency=' . urlencode($currency) . $returnParams;
    header('Location: ' . $redirectUrl);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
