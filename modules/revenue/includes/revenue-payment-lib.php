<?php

declare(strict_types=1);

/**
 * Record customer payments against revenue entries (desk modal + API).
 */

/**
 * @return array<string, mixed>
 */
function revenue_payment_fetch_accounts(PDO $pdo): array
{
    $accounts = [];
    try {
        $rows = $pdo->query(
            "SELECT id, name, currency, type FROM financial_accounts WHERE status = 'active' ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $bucket = function_exists('balancesAccountLiquidityBucket')
                ? balancesAccountLiquidityBucket((string) ($row['type'] ?? 'bank'))
                : 'bank';
            $accounts[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'TZS'),
                'type' => (string) ($row['type'] ?? ''),
                'bucket' => $bucket,
            ];
        }
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query(
                "SELECT id, account_name AS name FROM erp_bank_accounts WHERE status = 'active' ORDER BY account_name ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $accounts[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'currency' => 'TZS',
                    'type' => 'bank',
                    'bucket' => 'bank',
                ];
            }
        } catch (Throwable $e2) {
            $accounts = [];
        }
    }

    return $accounts;
}

/**
 * @return array<string, mixed>|null
 */
function revenue_payment_load_entry(PDO $pdo, int $entryId): ?array
{
    if ($entryId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT re.*,
               i.invoice_number AS linked_invoice_number,
               i.invoice_date,
               i.due_date AS invoice_due_date,
               c.company_name AS customer_name_resolved,
               c.customer_code AS customer_code_resolved
        FROM revenue_entries re
        LEFT JOIN invoices i ON i.id = re.source_invoice_id
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE re.id = ?
        LIMIT 1
    ');
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        return null;
    }

    $amountTotal = (float) ($entry['amount_total'] ?? 0);
    $amountPaid = (float) ($entry['total_paid'] ?? 0);
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

    $amountDue = max(0.0, $amountTotal - $amountPaid);
    $customerName = trim((string) ($entry['customer_name_resolved'] ?? ''));
    if ($customerName === '') {
        $customerName = trim((string) ($entry['customer_name'] ?? ''));
    }
    if ($customerName === '') {
        $customerName = 'N/A';
    }

    $voucherId = (string) ($entry['voucher_number'] ?? ('REV-' . $entryId));
    $invoiceNo = (string) ($entry['linked_invoice_number'] ?? '');
    if ($invoiceNo === '') {
        $invoiceNo = $voucherId;
    }

    $enriched = revenue_entries_enrich_row($entry);

    $invoiceDate = !empty($entry['invoice_date']) ? (string) $entry['invoice_date'] : (string) ($entry['entry_date'] ?? '');
    $dueDate = !empty($entry['invoice_due_date']) ? (string) $entry['invoice_due_date'] : '';

    return [
        'entry' => $enriched,
        'entry_id' => $entryId,
        'voucher_number' => $voucherId,
        'invoice_number' => $invoiceNo,
        'customer_name' => $customerName,
        'customer_code' => (string) ($entry['customer_code_resolved'] ?? ''),
        'invoice_date' => $invoiceDate,
        'due_date' => $dueDate,
        'amount_total' => $amountTotal,
        'amount_paid' => $amountPaid,
        'amount_due' => $amountDue,
        'default_amount' => $amountDue > 0 ? $amountDue : $amountTotal,
        'collection_date' => date('Y-m-d'),
        'default_reference' => 'TRF-' . date('Ymd') . '-001',
        'default_payment_notes' => 'Payment for ' . $invoiceNo,
        'can_pay' => ren_can_pay($entry) && $amountDue > 0.009,
        'payment_methods' => ['Bank Transfer', 'Cash', 'Mobile Money', 'Cheque'],
        'currencies' => [
            ['code' => 'TZS', 'label' => 'TZS - Tanzanian Shilling'],
            ['code' => 'USD', 'label' => 'USD - US Dollar'],
            ['code' => 'EUR', 'label' => 'EUR - Euro'],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function revenue_payment_build_init(PDO $pdo, int $entryId): array
{
    require_once dirname(__DIR__, 3) . '/includes/revenue_account_helpers.php';
    if (is_file(dirname(__DIR__, 3) . '/modules/balances/functions.php')) {
        require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';
    }
    revenue_ensure_account_schema($pdo);

    $payload = revenue_payment_load_entry($pdo, $entryId);
    if ($payload === null) {
        return ['ok' => false, 'error' => 'Revenue entry not found.'];
    }
    if (!$payload['can_pay']) {
        return ['ok' => false, 'error' => 'This entry has no outstanding balance to pay.'];
    }

    return [
        'ok' => true,
        'csrf_token' => function_exists('csrf_token') ? csrf_token() : '',
        'accounts' => revenue_payment_fetch_accounts($pdo),
        'payment_methods' => $payload['payment_methods'],
        'entry' => $payload,
    ];
}

/**
 * @param array<string, mixed> $post
 * @param array<string, mixed>|null $files
 * @return array<string, mixed>
 */
function revenue_payment_process(PDO $pdo, array $post, ?array $files = null): array
{
    require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
    require_once dirname(__DIR__, 3) . '/includes/accounting_service.php';
    require_once dirname(__DIR__, 3) . '/includes/revenue_account_helpers.php';
    require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';
    require_once dirname(__DIR__, 3) . '/includes/invoice_gl_posting.php';

    revenue_ensure_account_schema($pdo);

    $entryId = (int) ($post['entry_id'] ?? 0);
    $collectionDate = trim((string) ($post['collection_date'] ?? ''));
    $amountCollected = (float) ($post['amount_collected'] ?? 0);
    $accountId = (int) ($post['account_id'] ?? 0);
    $paymentMethod = trim((string) ($post['payment_method'] ?? ''));
    $payerName = trim((string) ($post['payer_name'] ?? ''));
    $referenceNumber = trim((string) ($post['reference_number'] ?? ''));
    $currency = strtoupper(trim((string) ($post['currency'] ?? 'TZS')));
    $paymentNotes = trim((string) ($post['payment_notes'] ?? ''));
    $internalNote = trim((string) ($post['internal_note'] ?? ''));

    if ($entryId <= 0) {
        return ['ok' => false, 'errors' => ['Invalid revenue entry.']];
    }
    if ($collectionDate === '') {
        return ['ok' => false, 'errors' => ['Payment date is required.']];
    }
    if ($paymentMethod === '') {
        return ['ok' => false, 'errors' => ['Payment method is required.']];
    }
    if ($payerName === '') {
        return ['ok' => false, 'errors' => ['Payer name is required.']];
    }
    if ($currency === '') {
        return ['ok' => false, 'errors' => ['Currency is required.']];
    }
    if ($amountCollected <= 0) {
        return ['ok' => false, 'errors' => ['Payment amount must be greater than zero.']];
    }
    if ($accountId <= 0) {
        return ['ok' => false, 'errors' => ['Please select a deposit account.']];
    }

    $loaded = revenue_payment_load_entry($pdo, $entryId);
    if ($loaded === null) {
        return ['ok' => false, 'errors' => ['Revenue entry not found.']];
    }
    if (!$loaded['can_pay']) {
        return ['ok' => false, 'errors' => ['This entry cannot accept payments.']];
    }
    if ($amountCollected > $loaded['amount_due'] + 0.009) {
        return [
            'ok' => false,
            'errors' => ['Payment amount cannot exceed the outstanding balance (' . number_format($loaded['amount_due'], 2) . ').'],
        ];
    }

    $revenueCollectionAccountCol = resolveExistingColumn(
        'revenue_collections',
        'account_id',
        ['bank_account_id', 'gl_account_id', 'financial_account_id']
    );

    try {
        $pdo->beginTransaction();

        if ($revenueCollectionAccountCol) {
            $stmt = $pdo->prepare(
                "INSERT INTO revenue_collections (entry_id, collection_date, amount_collected, {$revenueCollectionAccountCol}) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$entryId, $collectionDate, $amountCollected, $accountId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO revenue_collections (entry_id, collection_date, amount_collected) VALUES (?, ?, ?)'
            );
            $stmt->execute([$entryId, $collectionDate, $amountCollected]);
        }
        $collectionId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'SELECT voucher_number, customer_name, narration, amount_total, amount_exclusive, vat_amount, total_paid
             FROM revenue_entries WHERE id = ?'
        );
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            throw new RuntimeException('Revenue entry not found.');
        }

        $newTotalPaid = (float) ($entry['total_paid'] ?? 0) + $amountCollected;
        $newStatus = $newTotalPaid >= (float) ($entry['amount_total'] ?? 0) - 0.009 ? 'Paid' : 'Partial';

        $stmt = $pdo->prepare('UPDATE revenue_entries SET total_paid = ?, payment_status = ?, payment_mode = ? WHERE id = ?');
        $stmt->execute([$newTotalPaid, $newStatus, $paymentMethod, $entryId]);

        $descriptionParts = [
            "Debt Payment: {$entry['voucher_number']}",
            $payerName,
        ];
        if ($referenceNumber !== '') {
            $descriptionParts[] = "Ref {$referenceNumber}";
        }
        if ($paymentNotes !== '') {
            $descriptionParts[] = $paymentNotes;
        }
        if ($internalNote !== '') {
            $descriptionParts[] = $internalNote;
        }
        $description = implode(' - ', $descriptionParts) . ' (' . ($entry['narration'] ?? '') . ')';

        if (is_array($files) && !empty($files['payment_attachment']['tmp_name']) && (int) ($files['payment_attachment']['error'] ?? 1) === UPLOAD_ERR_OK) {
            $uploadDir = dirname(__DIR__, 3) . '/uploads/revenue/payments/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $orig = (string) ($files['payment_attachment']['name'] ?? 'attachment');
            $ext = pathinfo($orig, PATHINFO_EXTENSION);
            $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'dat';
            $target = $uploadDir . 'pay_' . $entryId . '_' . time() . '.' . $safeExt;
            if (move_uploaded_file((string) $files['payment_attachment']['tmp_name'], $target)) {
                $description .= ' [Attachment: uploads/revenue/payments/' . basename($target) . ']';
            }
        }

        recordTransaction($accountId, 'credit', $amountCollected, $description, 'revenue_collection', $entryId, $collectionDate);

        invoice_gl_post_revenue_recognition(
            $pdo,
            $entryId,
            (string) $entry['voucher_number'],
            $collectionDate,
            (string) $entry['customer_name'],
            (string) ($entry['narration'] ?? ''),
            (float) ($entry['amount_total'] ?? 0),
            (float) ($entry['amount_exclusive'] ?? max(0, (float) ($entry['amount_total'] ?? 0) - (float) ($entry['vat_amount'] ?? 0))),
            (float) ($entry['vat_amount'] ?? 0)
        );
        invoice_gl_post_revenue_payment(
            $pdo,
            $entryId,
            (string) $entry['voucher_number'],
            $collectionDate,
            $amountCollected,
            $accountId,
            $collectionId
        );

        if (!empty($loaded['entry']['source_invoice_id'])) {
            $invoiceId = (int) $loaded['entry']['source_invoice_id'];
            if ($invoiceId > 0) {
                if (!function_exists('invoiceTableColumns')) {
                    require_once dirname(__DIR__, 3) . '/includes/revenue_ledger.php';
                }
                $invCols = invoiceTableColumns($pdo);
                if (in_array('amount_paid', $invCols, true) && in_array('status', $invCols, true)) {
                    $invPayStatus = $newStatus === 'Paid' ? 'paid' : ($newStatus === 'Partial' ? 'partial' : 'sent');
                    $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')
                        ->execute([$newTotalPaid, $invPayStatus, $invoiceId]);
                }
            }
        }

        $pdo->commit();

        $listUrl = function_exists('app_url')
            ? app_url('/revenue_entries.php?module=revenue&success=' . urlencode('Payment recorded successfully'))
            : '/revenue_entries.php?module=revenue&success=' . urlencode('Payment recorded successfully');

        return [
            'ok' => true,
            'message' => 'Payment recorded successfully.',
            'redirect' => $listUrl,
            'entry_id' => $entryId,
            'payment_status' => $newStatus,
            'total_paid' => $newTotalPaid,
            'balance_due' => max(0.0, (float) ($entry['amount_total'] ?? 0) - $newTotalPaid),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('revenue_payment_process: ' . $e->getMessage());

        return ['ok' => false, 'errors' => ['Payment could not be recorded. Please try again.']];
    }
}
