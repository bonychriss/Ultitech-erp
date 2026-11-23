<?php
declare(strict_types=1);

/** Call within a transaction after locking the voucher. Returns true if already sent. */
function sendVoucherToPayables(PDO $pdo, array $voucher): bool
{
    $voucherId = (int) $voucher['id'];
    $status = strtolower(trim((string) ($voucher['status'] ?? '')));
    $currency = strtoupper(trim((string) ($voucher['currency'] ?? 'TZS'))) ?: 'TZS';
    $name = trim((string) ($voucher['payee_name'] ?? ''));
    $number = trim((string) ($voucher['voucher_no'] ?? ''));
    $amount = (float) ($voucher['total_amount'] ?? 0);
    if ($name === '' || $number === '' || $amount <= 0) {
        throw new DomainException('Complete the voucher payee, number and amount first.');
    }
    if ($currency !== 'TZS') {
        throw new DomainException('Only TZS vouchers can be sent to Outstanding Payables.');
    }
    if (!empty($voucher['is_paid']) || !empty($voucher['is_posted']) || !empty($voucher['is_draft'])
        || in_array($status, ['rejected', 'draft', 'paid', 'posted', 'cancelled', 'canceled', 'void'], true)) {
        throw new DomainException('Only unpaid, unposted vouchers can be sent to Payables.');
    }
    $existing = $pdo->prepare('SELECT status FROM erp_outstanding_invoices WHERE source_payment_voucher_id = ? LIMIT 1');
    $existing->execute([$voucherId]);
    $existingStatus = $existing->fetchColumn();
    if ($existingStatus !== false && $existingStatus !== 'outstanding') {
        throw new DomainException('This voucher has a closed Payables record. Contact Finance to review it.');
    }
    if ($existingStatus !== false) {
        return true;
    }
    $date = substr(trim((string) ($voucher['date_created'] ?? '')), 0, 10);
    if (!validOutstandingDueDate($date)) {
        throw new DomainException('The voucher date is invalid.');
    }
    $dueDate = trim((string) ($voucher['due_date'] ?? ''));
    $description = trim((string) ($voucher['description'] ?? ''));
    $insert = $pdo->prepare("INSERT INTO erp_outstanding_invoices
        (type, invoice_date, entity_name, invoice_number, narration, amount, source_payment_voucher_id, due_date)
        VALUES ('payable', ?, ?, ?, ?, ?, ?, ?)");
    $insert->execute([$date, $name, $number, $description !== '' ? $description : 'Payment voucher ' . $number,
        $amount, $voucherId, validOutstandingDueDate($dueDate) ? $dueDate : null]);
    return false;
}
