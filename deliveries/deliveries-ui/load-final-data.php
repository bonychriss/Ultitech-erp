<?php

declare(strict_types=1);

require_once __DIR__ . '/load-data.php';
require_once __DIR__ . '/delivery-note-invoice.php';

/**
 * Build document links for the public final page.
 *
 * @return list<array{type:string,label:string,subtitle:string,url:string,tone:string,filename:string,downloadMode:string}>
 */
function deliveries_build_final_documents(PDO $pdo, array $order, ?array $dn, int $salesInvoiceId, string $hash): array
{
    $docs = [];
    $invoiceRef = trim((string) ($order['invoice_ref'] ?? ''));

    if ($dn) {
        $noteNumber = (string) ($dn['note_number'] ?? 'delivery-note');
        $path = 'deliveries/view_delivery_note.php?id=' . (int) $dn['id']
            . '&hash=' . urlencode($hash);
        $docs[] = [
            'type' => 'delivery_note',
            'label' => 'Delivery Note',
            'subtitle' => 'Tap to download',
            'url' => deliveries_resolve_public_path($path),
            'tone' => 'blue',
            'filename' => preg_replace('/[^\w\-]/', '_', $noteNumber) . '.pdf',
            'downloadMode' => 'pdf_page',
            'pdfSelector' => '.page-container',
        ];
    }

    if ($salesInvoiceId > 0) {
        $invName = $invoiceRef !== '' ? preg_replace('/[^\w\-]/', '_', $invoiceRef) : ('invoice-' . $salesInvoiceId);
        $docs[] = [
            'type' => 'invoice',
            'label' => 'Invoice',
            'subtitle' => 'Tap to download',
            'url' => deliveries_resolve_public_path(
                'deliveries/public_invoice.php?id=' . $salesInvoiceId . '&hash=' . urlencode($hash)
            ),
            'tone' => 'green',
            'filename' => $invName . '.pdf',
            'downloadMode' => 'pdf_page',
            'pdfSelector' => '#pdf-content',
        ];
    } elseif ($invoiceRef !== '' && !empty($order['invoice_file'])) {
        $filePath = (string) $order['invoice_file'];
        $docs[] = [
            'type' => 'invoice',
            'label' => 'Invoice',
            'subtitle' => 'Tap to download',
            'url' => deliveries_resolve_public_path($filePath),
            'tone' => 'green',
            'filename' => basename($filePath) ?: 'invoice.pdf',
            'downloadMode' => 'file',
        ];
    }

    $receiptPath = !empty($order['receipt_file'])
        ? (string) $order['receipt_file']
        : (string) ($order['package_image'] ?? '');
    if ($receiptPath !== '') {
        $docs[] = [
            'type' => 'receipt',
            'label' => 'Receipt / Proof',
            'subtitle' => 'Tap to download',
            'url' => deliveries_resolve_public_path($receiptPath),
            'tone' => 'purple',
            'filename' => basename($receiptPath) ?: 'receipt',
            'downloadMode' => 'file',
        ];
    }

    return $docs;
}

/**
 * @return array{ok:bool,error?:string,redirect?:string,data?:array}
 */
function deliveries_load_final_payload(PDO $pdo, array $query): array
{
    if (function_exists('ensureDeliveriesSchema')) {
        ensureDeliveriesSchema();
    }

    $hash = trim((string) ($query['hash'] ?? ''));
    if ($hash === '') {
        return ['ok' => false, 'error' => 'Invalid verification link.'];
    }

    if (!function_exists('getOrderByVerificationHash')) {
        return ['ok' => false, 'error' => 'Verification is not available.'];
    }

    $order = getOrderByVerificationHash($hash);
    if (!$order) {
        return ['ok' => false, 'error' => 'Link expired or invalid.'];
    }

    if (deliveries_resolve_sales_invoice_id($pdo, $order) > 0) {
        deliveries_ensure_order_delivery_note($pdo, (int) $order['id'], (int) ($order['created_by'] ?? 0));
        $stmtRefresh = $pdo->prepare('SELECT * FROM delivery_orders WHERE id = ?');
        $stmtRefresh->execute([(int) $order['id']]);
        $order = $stmtRefresh->fetch(PDO::FETCH_ASSOC) ?: $order;
    }

    $dn = null;
    if (!empty($order['delivery_note_id'])) {
        $stmtDn = $pdo->prepare('SELECT * FROM delivery_notes WHERE id = ?');
        $stmtDn->execute([(int) $order['delivery_note_id']]);
        $dn = $stmtDn->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $isSigned = ($order['status'] ?? '') === 'delivered'
        || !empty($order['signature_path'])
        || ($dn && !empty($dn['receiver_signature_path']));

    if (!$isSigned) {
        return [
            'ok' => false,
            'redirect' => deliveries_resolve_public_path('deliveries/verify_delivery.php?hash=' . urlencode($hash)),
        ];
    }

    $brand = deliveries_load_public_company_branding($pdo, deliveries_resolve_order_company_id($pdo, $order));
    $salesInvoiceId = deliveries_resolve_sales_invoice_id($pdo, $order);
    $justSigned = isset($query['success']) && (string) $query['success'] === '1';
    $feedbackSaved = isset($query['feedback_saved']);

    $sigPath = (string) ($order['signature_path'] ?? '');
    $sigSource = (strpos($sigPath, 'sig_pod_') !== false) ? 'driver' : 'client';

    $message = 'This delivery has been verified and signed.';
    if ($justSigned) {
        $message = 'Thank you for choosing ' . ($brand['name'] ?? 'us') . '. Your signature has been recorded securely.';
    } elseif ($sigSource === 'driver') {
        $message = 'This delivery was verified and signed by the driver on your behalf.';
    }

    $documents = deliveries_build_final_documents($pdo, $order, $dn, $salesInvoiceId, $hash);
    $customerRating = $order['customer_rating'] ?? null;

    return [
        'ok' => true,
        'data' => [
            'hash' => $hash,
            'orderId' => (int) ($order['id'] ?? 0),
            'invoiceRef' => (string) ($order['invoice_ref'] ?? ''),
            'brand' => $brand,
            'justSigned' => $justSigned,
            'sigSource' => $sigSource,
            'message' => $message,
            'documents' => $documents,
            'feedback' => [
                'saved' => $feedbackSaved || $customerRating !== null,
                'canSubmit' => !$feedbackSaved && $customerRating === null,
                'rating' => $customerRating !== null ? (int) $customerRating : null,
            ],
        ],
    ];
}

/**
 * @return array{ok:bool,error?:string,data?:array}
 */
function deliveries_process_final_feedback(PDO $pdo, string $hash, int $rating, string $feedback): array
{
    $hash = trim($hash);
    if ($hash === '' || !function_exists('getOrderByVerificationHash')) {
        return ['ok' => false, 'error' => 'Invalid verification link.'];
    }

    $order = getOrderByVerificationHash($hash);
    if (!$order) {
        return ['ok' => false, 'error' => 'Link expired or invalid.'];
    }

    if ($rating < 1 || $rating > 5) {
        return ['ok' => false, 'error' => 'Please select a rating between 1 and 5.'];
    }

    try {
        $stmt = $pdo->prepare('UPDATE delivery_orders SET customer_rating = ?, customer_feedback = ? WHERE id = ?');
        $stmt->execute([$rating, trim($feedback), (int) $order['id']]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save feedback.'];
    }

    return [
        'ok' => true,
        'data' => [
            'feedback' => [
                'saved' => true,
                'canSubmit' => false,
                'rating' => $rating,
            ],
        ],
    ];
}
