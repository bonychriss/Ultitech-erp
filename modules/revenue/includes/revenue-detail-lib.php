<?php

declare(strict_types=1);

/**
 * Revenue entry detail payload for desk modal.
 */

/**
 * @return array<string, mixed>|null
 */
function revenue_detail_load_entry(PDO $pdo, int $entryId): ?array
{
    if ($entryId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT re.*,
               i.invoice_number AS linked_invoice_number,
               i.invoice_date,
               i.due_date AS invoice_due_date,
               cust.company_name AS resolved_company_name,
               cust.customer_code AS resolved_customer_code,
               cust.id AS resolved_customer_id,
               u.full_name AS creator_name,
               app.full_name AS approver_name
        FROM revenue_entries re
        LEFT JOIN invoices i ON i.id = re.source_invoice_id
        LEFT JOIN customers cust ON cust.id = i.customer_id
        LEFT JOIN users u ON u.id = re.approved_by
        LEFT JOIN users app ON app.id = re.approved_by
        WHERE re.id = ?
        LIMIT 1
    ');
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    return $entry ?: null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function revenue_detail_fetch_ledger(PDO $pdo, string $voucherId): array
{
    if ($voucherId === '' || $voucherId === 'N/A') {
        return [];
    }

    if (!function_exists('resolveExistingColumn')) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
    }

    try {
        $refCol = resolveExistingColumn('erp_journal_entries', 'reference', ['entry_number', 'ref_no', 'voucher_no']) ?: 'reference';
        $stmt = $pdo->prepare("
            SELECT ji.debit, ji.credit,
                   COALESCE(ji.memo, ji.description, ji.narration, '') AS line_description,
                   a.name AS account_name,
                   a.code AS account_code,
                   je.date AS j_date
            FROM erp_journal_items ji
            JOIN erp_journal_entries je ON je.id = ji.journal_id
            JOIN erp_accounts a ON a.id = ji.account_id
            WHERE je.{$refCol} = ?
            ORDER BY je.id ASC, ji.debit DESC, ji.credit ASC
        ");
        $stmt->execute([$voucherId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'date' => (string) ($row['j_date'] ?? ''),
                'account_name' => (string) ($row['account_name'] ?? ''),
                'account_code' => (string) ($row['account_code'] ?? ''),
                'description' => trim((string) ($row['line_description'] ?? '')),
                'debit' => (float) ($row['debit'] ?? 0),
                'credit' => (float) ($row['credit'] ?? 0),
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function revenue_detail_fetch_attachments(PDO $pdo, int $entryId, array $entry): array
{
    $attachments = [];
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.file_name, a.file_path, a.file_size, a.uploaded_at
            FROM attachments a
            WHERE a.related_type = 'revenue_entry' AND a.related_id = ?
            ORDER BY a.uploaded_at DESC
        ");
        $stmt->execute([$entryId]);
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $attachments = [];
    }

    if (!$attachments && !empty($entry['attachment'])) {
        $attachments[] = [
            'id' => 0,
            'file_name' => basename((string) $entry['attachment']),
            'file_path' => (string) $entry['attachment'],
            'file_size' => null,
            'uploaded_at' => (string) ($entry['created_at'] ?? ''),
        ];
    }

    return array_map(static function (array $file): array {
        return [
            'id' => (int) ($file['id'] ?? 0),
            'file_name' => (string) ($file['file_name'] ?? 'Attachment'),
            'file_path' => (string) ($file['file_path'] ?? ''),
            'file_size' => isset($file['file_size']) && is_numeric($file['file_size']) ? (float) $file['file_size'] : null,
            'uploaded_at' => (string) ($file['uploaded_at'] ?? ''),
        ];
    }, $attachments);
}

/**
 * @return array<int, array<string, mixed>>
 */
function revenue_detail_fetch_notes(PDO $pdo, int $entryId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT n.id, n.note, n.created_at, COALESCE(u.full_name, 'System') AS user_name
            FROM notes n
            LEFT JOIN users u ON u.id = n.created_by
            WHERE n.related_type = 'revenue_entry' AND n.related_id = ?
            ORDER BY n.created_at DESC
        ");
        $stmt->execute([$entryId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
                SELECT rn.id, rn.note, rn.created_at, COALESCE(u.full_name, 'System') AS user_name
                FROM revenue_notes rn
                LEFT JOIN users u ON u.id = rn.user_id
                WHERE rn.entry_id = ?
                ORDER BY rn.created_at DESC
            ");
            $stmt->execute([$entryId]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            $notes = [];
        }
    }

    return array_map(static function (array $note): array {
        return [
            'id' => (int) ($note['id'] ?? 0),
            'note' => (string) ($note['note'] ?? ''),
            'created_at' => (string) ($note['created_at'] ?? ''),
            'user_name' => (string) ($note['user_name'] ?? 'System'),
        ];
    }, $notes);
}

/**
 * @return array<int, array<string, mixed>>
 */
function revenue_detail_build_timeline(array $entry, float $amountPaid): array
{
    $status = (string) ($entry['approval_status'] ?? 'Pending');
    $isPostedLike = in_array(strtolower($status), ['ratified', 'posted'], true);

    $events = [[
        'action' => 'Created',
        'description' => 'Revenue entry was created.',
        'time' => (string) ($entry['created_at'] ?? ''),
        'user' => (string) ($entry['creator_name'] ?: 'System'),
        'tone' => 'primary',
    ]];

    if ($isPostedLike) {
        $events[] = [
            'action' => 'Posted',
            'description' => 'Revenue entry was posted / ratified.',
            'time' => (string) ($entry['approved_at'] ?? ''),
            'user' => (string) ($entry['approver_name'] ?: $entry['creator_name'] ?: 'System'),
            'tone' => 'success',
        ];
    }

    if ($amountPaid > 0.0) {
        $events[] = [
            'action' => 'Payment recorded',
            'description' => 'Payment was recorded for this voucher.',
            'time' => (string) ($entry['updated_at'] ?? $entry['approved_at'] ?? ''),
            'user' => (string) ($entry['approver_name'] ?: $entry['creator_name'] ?: 'System'),
            'tone' => 'success',
        ];
    }

    if (!empty($entry['updated_at'])) {
        $events[] = [
            'action' => 'Updated',
            'description' => 'Revenue entry details were updated.',
            'time' => (string) $entry['updated_at'],
            'user' => (string) ($entry['approver_name'] ?: $entry['creator_name'] ?: 'System'),
            'tone' => 'neutral',
        ];
    }

    return $events;
}

/**
 * @return array<string, mixed>
 */
function revenue_detail_build_init(PDO $pdo, int $entryId): array
{
    $row = revenue_detail_load_entry($pdo, $entryId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Revenue entry not found.'];
    }

    $enriched = revenue_entries_enrich_row($row);
    $voucherId = (string) ($row['voucher_number'] ?? ('REV-' . $entryId));

    $amountNet = (float) ($row['amount_exclusive'] ?? 0);
    $amountVat = (float) ($row['vat_amount'] ?? 0);
    $amountTotal = (float) ($row['amount_total'] ?? 0);
    $amountPaid = ren_row_amount_paid($row);
    try {
        if (function_exists('tableExists') && tableExists('revenue_collections', $pdo)) {
            $colStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_collected), 0) FROM revenue_collections WHERE entry_id = ?');
            $colStmt->execute([$entryId]);
            $paidFromCollections = (float) $colStmt->fetchColumn();
            if ($paidFromCollections > 0.0001) {
                $amountPaid = $paidFromCollections;
            }
        }
    } catch (Throwable $e) {
    }
    $balance = max(0.0, $amountTotal - $amountPaid);

    $approvalStatus = (string) ($row['approval_status'] ?? 'Pending');
    $displayApproval = strcasecmp($approvalStatus, 'Ratified') === 0 ? 'Posted' : $approvalStatus;

    $paymentStatus = (string) ($row['payment_status'] ?? 'Unpaid');
    $paymentLower = strtolower($paymentStatus);
    $approvalLower = strtolower($approvalStatus);
    $titleStatus = 'Pending';
    if (in_array($paymentLower, ['paid', 'partial'], true)) {
        $titleStatus = $paymentLower === 'paid' ? 'Paid' : 'Partial';
    } elseif (in_array($approvalLower, ['posted', 'ratified'], true)) {
        $titleStatus = $balance > 0.009 ? 'Partial' : 'Paid';
    }

    $customerName = trim((string) ($row['resolved_company_name'] ?? ''));
    if ($customerName === '') {
        $customerName = trim((string) ($row['customer_name'] ?? ''));
    }

    $vatPct = $amountNet > 0.0001 ? (int) round(($amountVat / $amountNet) * 100) : 18;

    return [
        'ok' => true,
        'entry' => array_merge($enriched, [
            'id' => $entryId,
            'voucher_number' => $voucherId,
            'customer_name' => $customerName !== '' ? $customerName : 'N/A',
            'customer_code' => (string) ($row['resolved_customer_code'] ?? ''),
            'entry_date' => (string) ($row['entry_date'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'due_date' => (string) ($row['invoice_due_date'] ?? ''),
            'description' => ren_description($row),
            'payment_mode' => trim((string) ($row['payment_mode'] ?? '')),
            'approval_status_display' => $displayApproval,
            'title_status' => $titleStatus,
            'title_status_class' => match (strtolower($titleStatus)) {
                'paid' => 'paid',
                'partial' => 'partial',
                default => 'pending',
            },
            'creator_name' => (string) ($row['creator_name'] ?? 'System'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? $row['approved_at'] ?? ''),
            'linked_invoice_number' => (string) ($row['linked_invoice_number'] ?? ''),
            'narration' => (string) ($row['narration'] ?? ''),
            'amount_paid' => $amountPaid,
            'balance_due' => $balance,
        ]),
        'amounts' => [
            'net' => $amountNet,
            'vat' => $amountVat,
            'vat_pct' => $vatPct,
            'total' => $amountTotal,
            'paid' => $amountPaid,
            'balance' => $balance,
        ],
        'timeline' => revenue_detail_build_timeline($row, $amountPaid),
        'ledger' => revenue_detail_fetch_ledger($pdo, $voucherId),
        'attachments' => revenue_detail_fetch_attachments($pdo, $entryId, $row),
        'notes' => revenue_detail_fetch_notes($pdo, $entryId),
        'can_edit' => ren_can_edit($row),
        'can_pay' => ren_can_pay($row) && $balance > 0.009,
    ];
}
