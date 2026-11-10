<?php
declare(strict_types=1);

if (!defined('APP_BASE_PATH')) {
    $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    $base = '';
    if ($docRoot !== '' && strncmp($appRoot, $docRoot, strlen($docRoot)) === 0) {
        $base = trim(substr($appRoot, strlen($docRoot)), '/');
    }
    define('APP_BASE_PATH', $base);
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/user-avatar.php';
require_once __DIR__ . '/../../modules/balances/functions.php';
require_once __DIR__ . '/../load-data.php';
require_once __DIR__ . '/../payables.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Refresh the page and try again.']);
    exit;
}
$voucherId = (int) ($_POST['voucher_id'] ?? 0);
if ($voucherId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid voucher id.']);
    exit;
}
if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

try {
    $view = vv_load_view_payload($pdo, $voucherId);
    if (!$view['ok']) {
        http_response_code((int) ($view['code'] ?? 403));
        echo json_encode(['ok' => false, 'error' => $view['error'] ?? 'Access denied.']);
        exit;
    }
    if (empty($view['payload']['permissions']['canSendToPayables'])) {
        throw new DomainException('This voucher cannot be sent to Payables.');
    }
    ensureOutstandingInvoicesSchema($pdo);
    $hasCompany = columnExists('payment_vouchers', 'company_id', $pdo);
    $companyId = (int) (currentCompanyId() ?? 0);
    if ($hasCompany && $companyId <= 0) {
        throw new DomainException('Select a company before sending this voucher to Payables.');
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ?'
        . ($hasCompany ? ' AND company_id = ?' : '') . ' FOR UPDATE');
    $stmt->execute($hasCompany ? [$voucherId, $companyId] : [$voucherId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        throw new DomainException('Voucher not found.');
    }
    $userId = function_exists('resolveVoucherSessionUserId')
        ? (int) resolveVoucherSessionUserId($pdo)
        : (int) ($_SESSION['user_id'] ?? 0);
    if ((!empty($voucher['is_restricted']) && !isAdmin() && !isFinance()
            && (int) ($voucher['created_by'] ?? 0) !== $userId)
        || (function_exists('viewerCanSeePrivateDraftVoucher') && !viewerCanSeePrivateDraftVoucher($voucher, $userId))) {
        throw new DomainException('Access denied.');
    }
    $alreadySent = sendVoucherToPayables($pdo, $voucher);
    $pdo->commit();
    $message = $alreadySent
        ? 'This voucher is already in Outstanding Payables.'
        : 'Voucher sent to Outstanding Payables.';
    echo json_encode(['ok' => true, 'message' => $message, 'redirect_url' => company_url('erp/outstanding-invoices/index.php?module=outstanding&tab=payables')]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('voucher send-to-payables: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not send this voucher to Payables. Please try again.']);
}
