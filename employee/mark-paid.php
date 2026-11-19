<?php
require_once '../includes/functions.php';
require_once '../modules/balances/functions.php';
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

$uploadedProofPath = null;
$paymentCommitted = false;

$voucher_id = isset($_POST['voucher_id']) ? (int)$_POST['voucher_id'] : 0;
if ($voucher_id <= 0) {
    http_response_code(400);
    echo 'Invalid voucher id';
    exit;
}

try {
    // Ensure columns exist
    ensurePaidColumnsOnPaymentVouchers();
    ensureSwiftDocumentColumn();

    // Verify voucher exists and fetch all required metadata for Balances & purchase sync
    $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $selectCols = ['id', 'voucher_no', 'payee_name', 'total_amount', 'currency', 'is_paid', 'status', 'approved_by'];
    foreach (['purpose', 'payment_purpose', 'voucher_purpose', 'linked_stock_po_id', 'linked_stock_po_ids', 'payment_execution_status'] as $col) {
        if (in_array($col, $pvCols, true)) {
            $selectCols[] = $col;
        }
    }
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', array_map(static fn ($c) => '`' . str_replace('`', '', (string) $c) . '`', $selectCols))
        . ' FROM payment_vouchers WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$voucher_id]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        http_response_code(404);
        echo 'Voucher not found';
        exit;
    }
    $voucherPurpose = function_exists('resolvePaymentVoucherPurposeFromRow')
        ? resolvePaymentVoucherPurposeFromRow($v)
        : 'general';

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
        header('Location: view-voucher.php?id=' . $voucher_id);
        exit;
    }

    $pay_account_id = isset($_POST['account_id']) ? (int) $_POST['account_id'] : 0;
    $totalAmount = round((float) ($v['total_amount'] ?? 0), 2);
    $companyId = (int) (currentCompanyId() ?? 0);

    $balancesPdo = function_exists('balances_resolve_pdo') ? balances_resolve_pdo() : null;
    if (!($balancesPdo instanceof PDO)) {
        $balancesPdo = $pdo;
    }
    $alreadyPaid = 0.0;
    if (function_exists('tableExists') && tableExists('account_transactions', $balancesPdo)) {
        try {
            $scoped = function_exists('balancesUseCompanyScope') && balancesUseCompanyScope($balancesPdo) && $companyId > 0
                && function_exists('columnExists') && columnExists('account_transactions', 'company_id', $balancesPdo);
            if ($scoped) {
                $paidStmt = $balancesPdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM account_transactions
                     WHERE reference_type = 'payment_voucher' AND reference_id = ? AND company_id = ? AND type = 'debit'"
                );
                $paidStmt->execute([$voucher_id, $companyId]);
            } else {
                $paidStmt = $balancesPdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM account_transactions
                     WHERE reference_type = 'payment_voucher' AND reference_id = ? AND type = 'debit'"
                );
                $paidStmt->execute([$voucher_id]);
            }
            $alreadyPaid = round((float) $paidStmt->fetchColumn(), 2);
        } catch (Throwable $ePaid) {
            $alreadyPaid = 0.0;
        }
    }
    $balanceDue = max(0.0, round($totalAmount - $alreadyPaid, 2));

    $paymentAmountRaw = trim((string) ($_POST['payment_amount'] ?? ''));
    if ($paymentAmountRaw === '') {
        $amount = $balanceDue > 0 ? $balanceDue : $totalAmount;
    } elseif (!is_numeric($paymentAmountRaw)) {
        http_response_code(400);
        echo 'Enter a valid amount to pay.';
        exit;
    } else {
        $amount = round((float) $paymentAmountRaw, 2);
    }

    if ($pay_account_id <= 0) {
        http_response_code(400);
        echo 'Select a payment account so marking this voucher paid updates Balances.';
        exit;
    }
    if ($totalAmount <= 0) {
        http_response_code(400);
        echo 'Voucher amount must be greater than zero.';
        exit;
    }
    if ($balanceDue <= 0) {
        http_response_code(400);
        echo 'This voucher has no remaining balance due.';
        exit;
    }
    if ($amount <= 0) {
        http_response_code(400);
        echo 'Amount to pay must be greater than zero.';
        exit;
    }
    if ($amount > $balanceDue + 0.009) {
        http_response_code(400);
        echo 'Amount to pay cannot exceed the balance due of ' . number_format($balanceDue, 2) . '.';
        exit;
    }
    $newPaidTotal = round($alreadyPaid + $amount, 2);
    $markFullyPaid = $newPaidTotal >= ($totalAmount - 0.009);

    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope($pdo) && $companyId > 0) {
        $accountStmt = $pdo->prepare("SELECT id, type FROM financial_accounts WHERE id = ? AND company_id = ? AND status = 'active' LIMIT 1");
        $accountStmt->execute([$pay_account_id, $companyId]);
    } else {
        $accountStmt = $pdo->prepare("SELECT id, type FROM financial_accounts WHERE id = ? AND status = 'active' LIMIT 1");
        $accountStmt->execute([$pay_account_id]);
    }
    $paymentAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$paymentAccount) {
        http_response_code(400);
        echo 'Selected payment account is invalid or inactive.';
        exit;
    }
    if (!in_array(balancesAccountLiquidityBucket((string) ($paymentAccount['type'] ?? '')), ['cash', 'bank', 'mobile'], true)) {
        http_response_code(400);
        echo 'Selected account is not a valid cash, bank, or mobile-money payment source.';
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

    // Prepare destination path (tenant storage when company-scoped).
    $baseDir = ensureVoucherUploadsDir($companyId > 0 ? $companyId : null);
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
    $uploadedProofPath = $destPath;
    $appRootNorm = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $baseDirNorm = str_replace('\\', '/', $baseDir);
    if (str_starts_with($baseDirNorm, $appRootNorm . '/')) {
        $relBase = substr($baseDirNorm, strlen($appRootNorm) + 1);
    } elseif ($companyId > 0) {
        $relBase = 'storage/tenant_' . $companyId . '/vouchers';
    } else {
        $relBase = 'assets/uploads/vouchers';
    }
    $relPath = $relBase . '/' . $voucher_id . '/' . $filename;

    $pdo->beginTransaction();
    $lockStmt = $pdo->prepare('SELECT is_paid, status FROM payment_vouchers WHERE id = ? LIMIT 1 FOR UPDATE');
    $lockStmt->execute([$voucher_id]);
    $lockedVoucher = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lockedVoucher) {
        throw new RuntimeException('Voucher not found.');
    }
    if ((int) ($lockedVoucher['is_paid'] ?? 0) === 1) {
        $pdo->rollBack();
        @unlink($uploadedProofPath);
        $uploadedProofPath = null;
        header('Location: view-voucher.php?id=' . $voucher_id);
        exit;
    }
    if (strtolower((string) ($lockedVoucher['status'] ?? '')) !== 'approved') {
        throw new RuntimeException('Voucher must be approved before payment.');
    }

    $desc = $voucherPurpose === 'stock_purchase'
        ? "Stock Purchase Payment Voucher #{$v['voucher_no']} to {$v['payee_name']}"
        : "Payment for Voucher #{$v['voucher_no']} to {$v['payee_name']}";
    if (!balancesRecordTransaction(
        $pdo,
        $pay_account_id,
        'debit',
        $amount,
        $desc,
        'payment_voucher',
        $voucher_id,
        null,
        $companyId > 0 ? $companyId : null
    )) {
        throw new RuntimeException('Failed to deduct amount from the selected account.');
    }

    $pvColsLive = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $sets = [
        'swift_document = ?',
        $markFullyPaid ? 'is_paid = 1' : 'is_paid = 0',
        'paid_by = ?',
        'payment_account_id = ?',
        'paid_at = NOW()',
        'updated_at = NOW()',
    ];
    $vals = [$relPath, (int) $_SESSION['user_id'], $pay_account_id > 0 ? $pay_account_id : null];
    if (in_array('payment_execution_status', $pvColsLive, true)) {
        $sets[] = $markFullyPaid ? "payment_execution_status = 'paid'" : "payment_execution_status = 'partially_paid'";
    }
    $vals[] = $voucher_id;
    $upd = $pdo->prepare('UPDATE payment_vouchers SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $upd->execute($vals);
    balancesRecalculateAccount($pdo, $pay_account_id, $companyId > 0 ? $companyId : null);
    $pdo->commit();
    $paymentCommitted = true;

    // Stock purchase PVs: update linked purchase payment_status (+ supplier_payments mirror, no second bank debit)
    if ($voucherPurpose === 'stock_purchase' && function_exists('syncLinkedStockPurchasesAfterVoucherPaid')) {
        syncLinkedStockPurchasesAfterVoucherPaid($pdo, $voucher_id, [
            'amount' => $amount,
            'fully_paid' => $markFullyPaid,
            'account_id' => $pay_account_id,
            'swift_path' => $relPath,
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'reference_no' => (string) ($v['voucher_no'] ?? ('PV-' . $voucher_id)),
            'payment_method' => trim((string) ($_POST['payment_method'] ?? 'Bank Transfer')),
            'record_supplier_payment' => true,
        ]);
    }

    // Fetch new balance for the toast
    $newBal = 0;
    $currency = 'TZS';
    if ($pay_account_id > 0) {
        $aStmt = $pdo->prepare("SELECT current_balance, currency FROM financial_accounts WHERE id = ?");
        $aStmt->execute([$pay_account_id]);
        $acc = $aStmt->fetch();
        if ($acc) {
            $newBal = (float)$acc['current_balance'];
            $currency = $acc['currency'];
        }
    }

    // Optional: create a notification for the creator
    try {
        createNotification([
            'user_id' => null,
            'audience' => 'user',
            'title' => $voucherPurpose === 'stock_purchase' ? 'Stock purchase voucher paid' : 'Voucher paid',
            'message' => "Voucher has been marked as paid. Deducted: {$currency} " . number_format($amount, 2) . ". New Balance: {$currency} " . number_format($newBal, 2),
            'voucher_id' => $voucher_id,
        ]);
    } catch (Throwable $e) { /* ignore */ }

    if ($markFullyPaid) {
        $_SESSION['success_msg'] = "Voucher marked as paid. Deducted: {$currency} " . number_format($amount, 2) . ". New Balance: {$currency} " . number_format($newBal, 2);
    } else {
        $remaining = max(0.0, round($totalAmount - $newPaidTotal, 2));
        $_SESSION['success_msg'] = "Partial payment recorded: {$currency} " . number_format($amount, 2)
            . ". Remaining: {$currency} " . number_format($remaining, 2)
            . ". New Balance: {$currency} " . number_format($newBal, 2);
    }
    if ($voucherPurpose === 'stock_purchase') {
        $_SESSION['success_msg'] .= $markFullyPaid
            ? ' Linked purchase payment status updated.'
            : ' Linked purchase marked partially paid.';
    }

    $redirectUrl = 'view-voucher.php?id=' . $voucher_id . '&paid=1&deducted=' . $amount . '&balance=' . $newBal . '&currency=' . urlencode($currency);
    header('Location: ' . $redirectUrl);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!$paymentCommitted && $uploadedProofPath && is_file($uploadedProofPath)) {
        @unlink($uploadedProofPath);
    }
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
