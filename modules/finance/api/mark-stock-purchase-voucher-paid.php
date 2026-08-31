<?php
/**
 * Phase 4G-5  Mark Stock Purchase Payment Voucher as Paid (Finance desk only).
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../balances/functions.php';

requireLogin();

if (!isFinance() && !isAdmin()) {
    http_response_code(403);
    exit('Forbidden: Only Finance or Admin can mark stock purchase vouchers as paid.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$deskUrl = function_exists('app_url')
    ? app_url('/modules/finance/stock-purchase-payment-desk.php')
    : '/modules/finance/stock-purchase-payment-desk.php';

function deskPayRedirect(string $deskUrl, string $tab, ?string $success = null, ?string $error = null): void
{
    if ($success !== null) {
        $_SESSION['desk_pay_success'] = $success;
    }
    if ($error !== null) {
        $_SESSION['desk_pay_error'] = $error;
    }
    $params = ['tab' => $tab];
    if (!empty($_GET['module'])) {
        $params['module'] = (string) $_GET['module'];
    }
    if (!empty($_POST['module'])) {
        $params['module'] = (string) $_POST['module'];
    }
    header('Location: ' . $deskUrl . '?' . http_build_query($params));
    exit;
}

/**
 * @return array{ok: bool, message: string}
 */
function deskPayValidateSwiftUpload(array $file): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if ((int) ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || (int) ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'message' => 'Payment proof file exceeds the server upload limit.'];
        }
        return ['ok' => false, 'message' => 'Payment proof upload failed. Please try again.'];
    }

    $toBytes = static function ($val) {
        $val = trim((string) $val);
        if ($val === '') {
            return 0;
        }
        $unit = strtolower(substr($val, -1));
        $num = (float) $val;
        switch ($unit) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
            break;
            default:
        }
        return (int) round($num);
    };

    $serverLimit = min(
        max(1, $toBytes(ini_get('upload_max_filesize') ?: '10M')),
        max(1, $toBytes(ini_get('post_max_size') ?: '10M'))
    );

    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $serverLimit) {
        $mb = max(1, floor($serverLimit / 1024 / 1024));
        return ['ok' => false, 'message' => 'Payment proof must be greater than 0 bytes and not exceed ' . $mb . 'MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowedMimes = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream',
    ];
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'doc', 'docx', 'xls', 'xlsx'];
    if (!in_array($mime, $allowedMimes, true) || !in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'message' => 'Only PDF, image, or Office files are allowed for payment proof.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @return array{ok: bool, relPath: string, absPath: string, message: string}
 */
function deskPayStoreSwiftUpload(int $voucherId, array $file): array
{
    $check = deskPayValidateSwiftUpload($file);
    if (!$check['ok']) {
        return ['ok' => false, 'relPath' => '', 'absPath' => '', 'message' => $check['message']];
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $baseDir = ensureVoucherUploadsDir();
    $voucherDir = $baseDir . '/' . $voucherId;
    if (!is_dir($voucherDir)) {
        @mkdir($voucherDir, 0775, true);
    }
    if (is_dir($voucherDir) && !is_writable($voucherDir)) {
        @chmod($voucherDir, 0775);
    }

    $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'dat';
    $filename = 'swift-proof-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $safeExt;
    $destPath = $voucherDir . '/' . $filename;

    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['ok' => false, 'relPath' => '', 'absPath' => '', 'message' => 'Failed to store uploaded payment proof.'];
    }

    $relPath = 'assets/uploads/vouchers/' . $voucherId . '/' . $filename;
    return ['ok' => true, 'relPath' => $relPath, 'absPath' => $destPath, 'message' => ''];
}

function deskPayVoucherEligible(array $row, int $companyId): bool
{
    if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'approved') {
        return false;
    }
    if ((int) ($row['is_paid'] ?? 0) === 1) {
        return false;
    }
    if (resolvePaymentVoucherPurposeFromRow($row) !== 'stock_purchase') {
        return false;
    }
    if ((int) ($row['linked_stock_po_id'] ?? 0) <= 0) {
        return false;
    }
    if ($companyId > 0 && array_key_exists('company_id', $row) && (int) ($row['company_id'] ?? 0) !== $companyId) {
        return false;
    }
    return true;
}

function deskPaySumVoucherDebits(PDO $pdo, int $voucherId, int $companyId = 0): float
{
    if ($voucherId <= 0 || !tableExists('account_transactions', $pdo)) {
        return 0.0;
    }
    $scoped = function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && $companyId > 0;
    if ($scoped && columnExists('account_transactions', 'company_id', $pdo)) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM account_transactions
             WHERE reference_type = 'payment_voucher' AND reference_id = ? AND company_id = ? AND type = 'debit'"
        );
        $stmt->execute([$voucherId, $companyId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM account_transactions
             WHERE reference_type = 'payment_voucher' AND reference_id = ? AND type = 'debit'"
        );
        $stmt->execute([$voucherId]);
    }

    return (float) $stmt->fetchColumn();
}

function deskPayResolveExistingProofPath(PDO $pdo, int $voucherId, string $swiftDocument): string
{
    $swiftDocument = trim($swiftDocument);
    if ($swiftDocument === '' || !function_exists('voucherSwiftDocumentIsUsablePaymentProof')
        || !voucherSwiftDocumentIsUsablePaymentProof($swiftDocument)) {
        return '';
    }

    return $swiftDocument;
}

/**
 * Keep earlier swift_document on file as a voucher attachment before saving a new installment proof.
 */
function deskPayArchivePriorSwiftProof(int $voucherId, string $swiftPath, int $userId): void
{
    $swiftPath = trim($swiftPath);
    if ($voucherId <= 0 || $swiftPath === '' || !function_exists('addVoucherAttachment')
        || !function_exists('getVoucherAttachments')) {
        return;
    }

    $normalized = ltrim(str_replace('\\', '/', $swiftPath), '/');
    foreach (getVoucherAttachments($voucherId) as $att) {
        $attPath = ltrim(str_replace('\\', '/', (string) ($att['file_path'] ?? '')), '/');
        if ($attPath === $normalized || basename($attPath) === basename($normalized)) {
            return;
        }
    }

    $root = dirname(__DIR__, 3);
    $rel = $normalized;
    if (strpos($rel, 'assets/') !== 0) {
        $rel = 'assets/uploads/vouchers/' . ltrim($rel, '/');
    }
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $mime = 'application/octet-stream';
    $size = 0;
    if (is_file($abs)) {
        $size = (int) filesize($abs);
        try {
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
        } catch (Throwable $e) {
            $mime = 'application/octet-stream';
        }
    }

    addVoucherAttachment(
        $voucherId,
        $swiftPath,
        'Prior payment proof - ' . basename($normalized),
        $mime,
        $size,
        $userId
    );
}

function deskPayHasExistingAccountTransaction(PDO $pdo, int $voucherId, int $companyId): bool
{
    if (!tableExists('account_transactions', $pdo)) {
        return false;
    }
    // Fully paid vouchers must not accept another payment posting.
    if (columnExists('payment_vouchers', 'is_paid', $pdo)) {
        $scoped = function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && $companyId > 0;
        if ($scoped && columnExists('payment_vouchers', 'company_id', $pdo)) {
            $stmt = $pdo->prepare('SELECT COALESCE(is_paid, 0) FROM payment_vouchers WHERE id = ? AND company_id = ? LIMIT 1');
            $stmt->execute([$voucherId, $companyId]);
        } else {
            $stmt = $pdo->prepare('SELECT COALESCE(is_paid, 0) FROM payment_vouchers WHERE id = ? LIMIT 1');
            $stmt->execute([$voucherId]);
        }
        if ((int) $stmt->fetchColumn() === 1) {
            return true;
        }
    }

    return false;
}

function deskPayVerifyFinanceApprover(PDO $pdo, array $row): ?string
{
    if (isAdmin()) {
        return null;
    }
    $approverId = (int) ($row['approved_by'] ?? 0);
    if ($approverId <= 0) {
        return 'Voucher lacks admin approval.';
    }
    $u = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_active = 1');
    $u->execute([$approverId]);
    $ur = $u->fetch();
    if (!$ur || (string) ($ur['role'] ?? '') !== ROLE_ADMIN) {
        return 'Voucher must be approved by an admin before Finance can mark paid.';
    }
    return null;
}

$voucherId = (int) ($_POST['voucher_id'] ?? 0);
$accountId = (int) ($_POST['account_id'] ?? 0);
$paymentReference = trim((string) ($_POST['payment_reference_no'] ?? ''));
$paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
$paymentNotes = trim((string) ($_POST['payment_notes'] ?? ''));
$paymentDateRaw = trim((string) ($_POST['payment_date'] ?? ''));
$paymentAmountRaw = trim((string) ($_POST['payment_amount'] ?? ''));
$useExistingProof = !empty($_POST['use_existing_proof']);
$replaceExistingProof = !empty($_POST['replace_existing_proof']);
$uploadedProofPath = null;
$uploadedProofAbs = null;

if ($voucherId <= 0) {
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Invalid voucher selected.');
}

if ($accountId <= 0) {
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Please select a payment account.');
}

if ($paymentReference === '') {
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Payment reference number is required.');
}

if ($paymentMethod === '') {
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Payment method is required.');
}

if ($paymentAmountRaw === '' || !is_numeric($paymentAmountRaw)) {
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Enter a valid amount to pay.');
}

$paymentAmount = round((float) $paymentAmountRaw, 2);
if ($paymentAmount <= 0) {
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Amount to pay must be greater than zero.');
}

$paymentDate = date('Y-m-d H:i:s');
if ($paymentDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDateRaw)) {
    $paymentDate = $paymentDateRaw . ' ' . date('H:i:s');
}

try {
    ensurePaidColumnsOnPaymentVouchers();
    ensureSwiftDocumentColumn();
    ensurePostedColumnsOnPaymentVouchers();
    ensureVoucherAttachmentsSchema();

    if (function_exists('balancesSyncGlobalPdo')) {
        $balancesPdo = balancesSyncGlobalPdo();
        if ($balancesPdo instanceof PDO) {
            $pdo = $balancesPdo;
        }
    }

    $companyId = (int) (currentCompanyId() ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $pvCols = $pdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $selectCols = ['id', 'voucher_no', 'payee_name', 'total_amount', 'currency', 'is_paid', 'status', 'approved_by', 'swift_document', 'linked_stock_po_id'];
    foreach (['company_id', 'purpose', 'payment_purpose', 'voucher_purpose', 'payment_reference_no', 'payment_execution_status', 'is_posted'] as $col) {
        if (in_array($col, $pvCols, true) && !in_array($col, $selectCols, true)) {
            $selectCols[] = $col;
        }
    }

    $pdo->beginTransaction();

    $sql = 'SELECT ' . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $selectCols))
        . ' FROM payment_vouchers WHERE id = ? LIMIT 1 FOR UPDATE';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$voucherId]);
    } catch (Throwable $e) {
        $sql = 'SELECT ' . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '', $c) . '`', $selectCols))
            . ' FROM payment_vouchers WHERE id = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$voucherId]);
    }
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'Voucher not found.');
    }

    if (!deskPayVoucherEligible($voucher, $companyId)) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'This voucher is not eligible for stock purchase payment.');
    }

    $approverError = deskPayVerifyFinanceApprover($pdo, $voucher);
    if ($approverError !== null) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, $approverError);
    }

    if (deskPayHasExistingAccountTransaction($pdo, $voucherId, $companyId)) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'This voucher is already fully paid. Duplicate payment posting is not allowed.');
    }

    $totalAmount = (float) ($voucher['total_amount'] ?? 0);
    if ($totalAmount <= 0) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'Voucher amount must be greater than zero.');
    }

    $alreadyPaid = deskPaySumVoucherDebits($pdo, $voucherId, $companyId);
    $balanceDue = max(0.0, round($totalAmount - $alreadyPaid, 2));
    if ($balanceDue <= 0) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'This voucher has no remaining balance due.');
    }
    if ($paymentAmount > $balanceDue + 0.009) {
        $pdo->rollBack();
        deskPayRedirect(
            $deskUrl,
            'ready_payment',
            null,
            'Amount to pay cannot exceed the balance due of ' . number_format($balanceDue, 2) . '.'
        );
    }

    $isPartialCompletion = $alreadyPaid > 0.009;

    $swiftColumn = trim((string) ($voucher['swift_document'] ?? ''));
    $resolvedProofPath = deskPayResolveExistingProofPath($pdo, $voucherId, $swiftColumn);
    $hasExistingProof = $resolvedProofPath !== '';
    $hasUploadedFile = isset($_FILES['swift_file']) && is_array($_FILES['swift_file'])
        && (int) ($_FILES['swift_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasUploadedFile) {
        $stored = deskPayStoreSwiftUpload($voucherId, $_FILES['swift_file']);
        if (!$stored['ok']) {
            $pdo->rollBack();
            deskPayRedirect($deskUrl, 'ready_payment', null, $stored['message']);
        }
        $uploadedProofPath = $stored['relPath'];
        $uploadedProofAbs = $stored['absPath'];
    }

    if ($isPartialCompletion) {
        if (!$hasUploadedFile) {
            $pdo->rollBack();
            deskPayRedirect(
                $deskUrl,
                'ready_payment',
                null,
                'Upload a separate SWIFT/bank proof for this installment. The previous payment proof cannot be reused.'
            );
        }
    } elseif (!$hasExistingProof && !$hasUploadedFile) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'Payment proof (SWIFT/document) is required before marking this voucher as paid.');
    } elseif ($hasExistingProof && !$hasUploadedFile && !$useExistingProof) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'Confirm use of the existing payment proof or upload a new document.');
    }

    // Verify payment account belongs to company when scoped.
    if (function_exists('balancesUseCompanyScope') && balancesUseCompanyScope() && $companyId > 0) {
        $accStmt = $pdo->prepare("SELECT id FROM financial_accounts WHERE id = ? AND company_id = ? AND status = 'active' LIMIT 1");
        $accStmt->execute([$accountId, $companyId]);
    } else {
        $accStmt = $pdo->prepare("SELECT id FROM financial_accounts WHERE id = ? AND status = 'active' LIMIT 1");
        $accStmt->execute([$accountId]);
    }
    if (!$accStmt->fetchColumn()) {
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'Selected payment account is invalid or inactive.');
    }

    $amount = $paymentAmount;
    $newPaidTotal = round($alreadyPaid + $paymentAmount, 2);
    $markFullyPaid = $newPaidTotal >= ($totalAmount - 0.009);

    $voucherNo = trim((string) ($voucher['voucher_no'] ?? ('PV-' . $voucherId)));
    $payeeName = trim((string) ($voucher['payee_name'] ?? ''));
    $currency = trim((string) ($voucher['currency'] ?? 'TZS')) ?: 'TZS';
    $desc = 'Stock Purchase Payment Voucher ' . $voucherNo . ' paid to ' . ($payeeName !== '' ? $payeeName : 'supplier');
    $desc .= ' [' . $currency . ' ' . number_format($amount, 2) . ']';
    if ($paymentMethod !== '') {
        $desc .= ' [' . $paymentMethod . ']';
    }
    if ($paymentReference !== '') {
        $desc .= ' Ref: ' . $paymentReference;
    }
    if ($paymentNotes !== '') {
        $desc .= '  ' . $paymentNotes;
    }

    $txnOk = balancesRecordTransaction(
        $pdo,
        $accountId,
        'debit',
        $amount,
        $desc,
        'payment_voucher',
        $voucherId,
        $paymentDate,
        $companyId > 0 ? $companyId : null
    );
    if (!$txnOk) {
        if ($uploadedProofAbs && is_file($uploadedProofAbs)) {
            @unlink($uploadedProofAbs);
        }
        $pdo->rollBack();
        deskPayRedirect($deskUrl, 'ready_payment', null, 'Failed to post the payment to the selected account. Check Chart of Accounts and try again.');
    }

    $swiftToSave = $swiftColumn !== '' ? $swiftColumn : $resolvedProofPath;
    if ($hasUploadedFile) {
        if ($isPartialCompletion && $swiftColumn !== '') {
            deskPayArchivePriorSwiftProof($voucherId, $swiftColumn, $userId);
        }
        if (function_exists('addVoucherAttachment')) {
            $attachLabel = (string) ($_FILES['swift_file']['name'] ?? basename((string) $uploadedProofPath));
            if ($isPartialCompletion) {
                $attachLabel = 'SWIFT installment (' . number_format($paymentAmount, 2) . ') - ' . $attachLabel;
            }
            addVoucherAttachment(
                $voucherId,
                (string) $uploadedProofPath,
                $attachLabel,
                (string) (new finfo(FILEINFO_MIME_TYPE))->file($uploadedProofAbs),
                (int) ($_FILES['swift_file']['size'] ?? 0),
                $userId
            );
        }
        if ($isPartialCompletion || $swiftColumn === '' || $replaceExistingProof) {
            $swiftToSave = (string) $uploadedProofPath;
        }
    } elseif (!$isPartialCompletion && $hasExistingProof && $useExistingProof) {
        $swiftToSave = $resolvedProofPath;
    }

    $sets = [$markFullyPaid ? 'is_paid = 1' : 'is_paid = 0', 'paid_by = ?', 'paid_at = ?', 'payment_account_id = ?'];
    $vals = [$userId, $paymentDate, $accountId];

    if (in_array('swift_document', $pvCols, true) && $swiftToSave !== '') {
        $sets[] = 'swift_document = ?';
        $vals[] = $swiftToSave;
    }
    if (in_array('payment_reference_no', $pvCols, true)) {
        $sets[] = 'payment_reference_no = ?';
        $vals[] = $paymentReference;
    }
    if (in_array('payment_execution_status', $pvCols, true)) {
        $sets[] = $markFullyPaid ? "payment_execution_status = 'paid'" : "payment_execution_status = 'partially_paid'";
    }
    if (in_array('is_posted', $pvCols, true) && $markFullyPaid) {
        $sets[] = 'is_posted = 1';
    }
    if (in_array('posted_by', $pvCols, true) && $markFullyPaid) {
        $sets[] = 'posted_by = ?';
        $vals[] = $userId;
    }
    if (in_array('posted_at', $pvCols, true) && $markFullyPaid) {
        $sets[] = 'posted_at = NOW()';
    }
    if (in_array('updated_at', $pvCols, true)) {
        $sets[] = 'updated_at = NOW()';
    }

    $vals[] = $voucherId;
    $upd = $pdo->prepare('UPDATE payment_vouchers SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $upd->execute($vals);

    $pdo->commit();

    if (function_exists('balancesRecalculateAccount')) {
        balancesRecalculateAccount($pdo, $accountId, $companyId > 0 ? $companyId : null);
    }

    $postedAccountName = '';
    try {
        $accNameStmt = $pdo->prepare('SELECT name FROM financial_accounts WHERE id = ? LIMIT 1');
        $accNameStmt->execute([$accountId]);
        $postedAccountName = trim((string) ($accNameStmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $postedAccountName = '';
    }

    try {
        createNotification([
            'user_id' => null,
            'audience' => 'user',
            'title' => 'Stock purchase voucher paid',
            'message' => $desc,
            'voucher_id' => $voucherId,
        ]);
    } catch (Throwable $e) {
        // ignore
    }

    $successMessage = $markFullyPaid
        ? 'Payment posted successfully. Bank/Cash balance has been updated.'
        : 'Partial payment of ' . number_format($paymentAmount, 2) . ' posted. Remaining balance: ' . number_format(max(0, $totalAmount - $newPaidTotal), 2) . '.';
    if ($postedAccountName !== '') {
        $successMessage .= ' Posted to ' . $postedAccountName . ' — view it in Chart of Accounts.';
    }

    deskPayRedirect(
        $deskUrl,
        $markFullyPaid ? 'paid_posted' : 'ready_payment',
        $successMessage
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($uploadedProofAbs && is_file($uploadedProofAbs)) {
        @unlink($uploadedProofAbs);
    }
    error_log('mark-stock-purchase-voucher-paid: ' . $e->getMessage());
    deskPayRedirect($deskUrl, 'ready_payment', null, 'Unable to post payment. Please try again or contact support.');
}
