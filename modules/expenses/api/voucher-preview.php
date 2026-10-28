<?php
/**
 * Purchases desk: payment voucher preview payload (PV + linked POs + attachments).
 */
require_once '../../../includes/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!(function_exists('isAdmin') && isAdmin()) && !(function_exists('isFinance') && isFinance())) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

$pvId = (int) ($_GET['pv_id'] ?? $_GET['id'] ?? 0);
if ($pvId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing voucher id.']);
    exit;
}

try {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database unavailable.');
    }

    $loadData = dirname(__DIR__, 3) . '/view-voucher-ui/load-data.php';
    if (!is_file($loadData)) {
        throw new RuntimeException('Voucher loader missing.');
    }
    require_once $loadData;

    $stmt = $pdo->prepare('SELECT * FROM payment_vouchers WHERE id = ? LIMIT 1');
    $stmt->execute([$pvId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Voucher not found.']);
        exit;
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM voucher_items WHERE voucher_id = ? ORDER BY id');
    $itemsStmt->execute([$pvId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $attachments = function_exists('getVoucherAttachments') ? getVoucherAttachments($pvId) : [];
    $purchaseOrderDocs = function_exists('vv_purchase_order_docs')
        ? vv_purchase_order_docs($pdo, $voucher, $pvId)
        : [];

    $attachmentCompanyId = (int) ($_SESSION['company_id'] ?? ($voucher['company_id'] ?? 0));
    $attachmentRows = [];
    foreach ($attachments as $att) {
        $rel = trim((string) ($att['file_path'] ?? $att['filepath'] ?? $att['path'] ?? ''));
        $name = trim((string) ($att['file_name'] ?? $att['filename'] ?? $att['original_name'] ?? 'Attachment'));
        $isImg = (bool) preg_match('/\.(png|jpe?g|gif|webp|bmp)$/i', $name . ' ' . $rel);
        $proxy = '';
        $missing = true;
        $fileSizeLabel = '';
        if ($rel !== '' && function_exists('resolveStoredMediaFilePath')) {
            $diskPath = resolveStoredMediaFilePath($rel, $attachmentCompanyId);
            if (is_string($diskPath) && is_file($diskPath)) {
                $missing = false;
                $bytes = @filesize($diskPath);
                if (is_int($bytes) && $bytes > 0) {
                    $fileSizeLabel = $bytes >= 1048576
                        ? round($bytes / 1048576, 1) . ' MB'
                        : max(1, (int) round($bytes / 1024)) . ' KB';
                }
                $proxy = function_exists('app_url')
                    ? app_url('/proxy_pdf.php') . '?file=' . rawurlencode(ltrim(str_replace('\\', '/', $rel), '/'))
                    : '/proxy_pdf.php?file=' . rawurlencode(ltrim(str_replace('\\', '/', $rel), '/'));
            }
        } elseif ($rel !== '') {
            $missing = false;
            $proxy = function_exists('app_url')
                ? app_url('/proxy_pdf.php') . '?file=' . rawurlencode(ltrim(str_replace('\\', '/', $rel), '/'))
                : '/proxy_pdf.php?file=' . rawurlencode(ltrim(str_replace('\\', '/', $rel), '/'));
        }

        $attachmentRows[] = [
            'id' => (int) ($att['id'] ?? 0),
            'name' => $name !== '' ? $name : 'Attachment',
            'proxyLink' => $proxy !== '' ? $proxy : null,
            'isImage' => $isImg,
            'typeLabel' => $isImg ? 'Image' : 'File',
            'fileSizeLabel' => $fileSizeLabel !== '' ? $fileSizeLabel : ($missing ? 'Unavailable' : ''),
            'missing' => $missing,
        ];
    }

    $fullUrl = '';
    if (function_exists('company_url')) {
        try {
            $fullUrl = (string) company_url('employee/view-voucher.php')
                . '?id=' . $pvId . '&module=voucher&from=purchases';
        } catch (Throwable $e) {
            $fullUrl = '';
        }
    }
    if ($fullUrl === '' && function_exists('app_url')) {
        $fullUrl = (string) app_url('/employee/view-voucher.php')
            . '?id=' . $pvId . '&module=voucher&from=purchases';
    }
    if ($fullUrl === '') {
        $fullUrl = '../../employee/view-voucher.php?id=' . $pvId . '&module=voucher&from=purchases';
    }

    $status = strtolower(trim((string) ($voucher['status'] ?? 'pending')));
    $isPaid = (int) ($voucher['is_paid'] ?? 0) === 1;
    $isPosted = (int) ($voucher['is_posted'] ?? 0) === 1;
    $statusLabel = $isPosted ? 'Posted' : ($isPaid ? 'Paid' : ucfirst($status !== '' ? $status : 'pending'));

    echo json_encode([
        'ok' => true,
        'voucher' => [
            'id' => $pvId,
            'voucher_no' => (string) ($voucher['voucher_no'] ?? ('PV-' . $pvId)),
            'payee_name' => (string) ($voucher['payee_name'] ?? ''),
            'prepared_by' => (string) ($voucher['prepared_by'] ?? ''),
            'description' => (string) ($voucher['description'] ?? ''),
            'currency' => strtoupper(trim((string) ($voucher['currency'] ?? 'TZS'))) ?: 'TZS',
            'total_amount' => (float) ($voucher['total_amount'] ?? 0),
            'date_created' => (string) ($voucher['date_created'] ?? ''),
            'supporting_documents' => (string) ($voucher['supporting_documents'] ?? '0'),
            'status' => $status,
            'status_label' => $statusLabel,
            'is_paid' => $isPaid,
            'is_posted' => $isPosted,
            'purpose' => function_exists('resolvePaymentVoucherPurposeFromRow')
                ? (string) resolvePaymentVoucherPurposeFromRow($voucher)
                : (string) ($voucher['purpose'] ?? $voucher['payment_purpose'] ?? ''),
        ],
        'items' => array_map(static function ($item) {
            return [
                'payment_type' => (string) ($item['payment_type'] ?? ''),
                'budget_type' => (string) ($item['budget_type'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'amount' => (float) ($item['amount'] ?? 0),
                'description' => (string) ($item['description'] ?? ''),
            ];
        }, $items),
        'purchaseOrderDocs' => $purchaseOrderDocs,
        'attachments' => $attachmentRows,
        'fullVoucherUrl' => $fullUrl,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('voucher-preview: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load voucher preview.']);
}
