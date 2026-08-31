<?php

require_once __DIR__ . '/revenue_sync.php';

/**
 * Revenue ledger helpers (Option A): keep a stable, auditable ledger table
 * that is synced from Sales invoices/payments.
 */

function revenueLedgerTableColumns(PDO $pdo): array
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM revenue_ledger')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($cols) ? $cols : [];
    } catch (Throwable $e) {
        return [];
    }
}

function invoiceTableColumns(PDO $pdo): array
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($cols) ? $cols : [];
    } catch (Throwable $e) {
        return [];
    }
}

function salesOrdersTableColumns(PDO $pdo): array
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($cols) ? $cols : [];
    } catch (Throwable $e) {
        return [];
    }
}

function ensureRevenueLedgerSchema($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS revenue_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_type VARCHAR(30) NOT NULL,
                source_id INT NOT NULL,
                customer_id INT NULL,
                entry_date DATE NULL,
                currency VARCHAR(10) NOT NULL DEFAULT 'TZS',
                amount_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'sent',
                revenue_entry_id INT NULL DEFAULT NULL,
                posted_by INT NULL,
                posted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_source (source_type, source_id),
                KEY idx_customer (customer_id),
                KEY idx_entry_date (entry_date),
                KEY idx_revenue_entry (revenue_entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $cols = revenueLedgerTableColumns($pdo);
        if ($cols && !in_array('revenue_entry_id', $cols, true)) {
            $pdo->exec('ALTER TABLE revenue_ledger ADD COLUMN revenue_entry_id INT NULL DEFAULT NULL AFTER status');
        }
        if ($cols && !in_array('posted_by', $cols, true)) {
            $pdo->exec('ALTER TABLE revenue_ledger ADD COLUMN posted_by INT NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // Do not block Sales flows if schema creation fails.
    }
}

/**
 * Insert ledger rows for any invoices not yet linked (fixes missed syncs / schema mismatches).
 */
function backfillInvoicesMissingFromRevenueLedger($pdo, $limit = 1000)
{
    ensureRevenueLedgerSchema($pdo);

    try {
        $invCols = invoiceTableColumns($pdo);
        if (!$invCols) {
            return;
        }

        $amountPaidExpr = in_array('amount_paid', $invCols, true)
            ? 'COALESCE(i.amount_paid, 0)'
            : '0';

        $soCols = salesOrdersTableColumns($pdo);
        $currencyExpr = in_array('currency', $soCols, true)
            ? "COALESCE(so.currency, 'TZS')"
            : "'TZS'";

        $sql = "
            INSERT INTO revenue_ledger
                (source_type, source_id, customer_id, entry_date, currency, amount_total, amount_paid, status, posted_by)
            SELECT
                'invoice',
                i.id,
                i.customer_id,
                i.invoice_date,
                {$currencyExpr},
                i.total_amount,
                {$amountPaidExpr},
                LOWER(COALESCE(i.status, 'sent')),
                NULL
            FROM invoices i
            LEFT JOIN sales_orders so ON so.id = i.order_id
            LEFT JOIN revenue_ledger rl ON rl.source_type = 'invoice' AND rl.source_id = i.id
            WHERE rl.id IS NULL
              AND LOWER(COALESCE(i.status, '')) <> 'cancelled'
            LIMIT " . (int) $limit;

        $pdo->exec($sql);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Ensure an invoice is represented in revenue_ledger and kept up-to-date.
 */
function syncInvoiceToRevenueLedger($pdo, $invoiceId, $userId = null)
{
    if ($invoiceId <= 0) {
        return;
    }

    ensureRevenueLedgerSchema($pdo);

    try {
        $invCols = invoiceTableColumns($pdo);
        $amountPaidExpr = in_array('amount_paid', $invCols, true)
            ? 'COALESCE(i.amount_paid, 0) AS amount_paid'
            : '0 AS amount_paid';

        $soCols = salesOrdersTableColumns($pdo);
        $currencyExpr = in_array('currency', $soCols, true)
            ? "COALESCE(so.currency, 'TZS') AS currency"
            : "'TZS' AS currency";

        $sql = "
            SELECT
                i.id,
                i.customer_id,
                i.invoice_date,
                i.total_amount,
                {$amountPaidExpr},
                i.status,
                {$currencyExpr}
            FROM invoices i
            LEFT JOIN sales_orders so ON so.id = i.order_id
            WHERE i.id = ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return;
        }

        $status = strtolower((string) ($inv['status'] ?? 'sent'));
        if ($status === '') {
            $status = 'sent';
        }

        $amountTotal = (float) ($inv['total_amount'] ?? 0);
        $amountPaid = (float) ($inv['amount_paid'] ?? 0);
        if ($status === 'partial') {
            $status = 'sent';
        }

        $ledgerSql = "
            INSERT INTO revenue_ledger
                (source_type, source_id, customer_id, entry_date, currency, amount_total, amount_paid, status, posted_by)
            VALUES
                ('invoice', ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                customer_id = VALUES(customer_id),
                entry_date = VALUES(entry_date),
                currency = VALUES(currency),
                amount_total = VALUES(amount_total),
                amount_paid = VALUES(amount_paid),
                status = VALUES(status)
        ";
        $stUp = $pdo->prepare($ledgerSql);
        $stUp->execute([
            (int) $inv['id'],
            (int) ($inv['customer_id'] ?? 0) ?: null,
            $inv['invoice_date'] ?? null,
            (string) ($inv['currency'] ?? 'TZS'),
            $amountTotal,
            $amountPaid,
            $status,
            $userId,
        ]);

        $revenueEntryId = syncInvoiceToRevenue($pdo, $invoiceId);
        if ($revenueEntryId > 0) {
            $pdo->prepare('UPDATE revenue_ledger SET revenue_entry_id = ? WHERE source_type = ? AND source_id = ? LIMIT 1')
                ->execute([$revenueEntryId, 'invoice', $invoiceId]);
        }

        if (is_file(__DIR__ . '/invoice_gl_posting.php')) {
            require_once __DIR__ . '/invoice_gl_posting.php';
            if (function_exists('invoice_gl_ensure_invoice_recognition')) {
                invoice_gl_ensure_invoice_recognition($pdo, $invoiceId);
            }
        }
    } catch (Throwable $e) {
        // Do not block Sales flows.
    }
}

/**
 * Create revenue_entries (and ledger links) for invoices missing a linked voucher.
 */
function backfillInvoicesToRevenueEntries($pdo, $limit = 2000)
{
    ensureRevenueSourceInvoiceSchema($pdo);
    ensureRevenueLedgerSchema($pdo);
    try {
        $lim = max(1, $limit);
        $sql = "
            SELECT i.id
            FROM invoices i
            LEFT JOIN revenue_entries re ON re.source_invoice_id = i.id
            WHERE re.id IS NULL
              AND LOWER(COALESCE(i.status, '')) NOT IN ('cancelled', 'canceled')
            LIMIT {$lim}
        ";
        $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $id) {
            syncInvoiceToRevenueLedger($pdo, (int) $id, null);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Find the G/L journal entry posted for a revenue payment collection.
 */
function findRevenueCollectionJournalEntry(PDO $pdo, string $voucherRef, string $collectionDate, float $amount): ?array
{
    $voucherRef = trim($voucherRef);
    if ($voucherRef === '' || $collectionDate === '') {
        return null;
    }

    $paymentDescSql = "(
        je.description LIKE '%Payment Collection%'
        OR je.description LIKE '%Payment Receipt%'
        OR je.description LIKE '%Payment Received%'
        OR je.description LIKE '%Debt Payment%'
        OR je.description LIKE '%Revenue collection%'
    )";

    $cols = [];
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM erp_journal_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return null;
    }

    $selectRef = in_array('reference', $cols, true) ? 'je.reference' : "NULL AS reference";
    $baseSelect = "
        SELECT je.id,
               {$selectRef},
               je.description,
               je.date,
               COALESCE(SUM(ji.debit), 0) AS total_debit,
               COALESCE(SUM(ji.credit), 0) AS total_credit
        FROM erp_journal_entries je
        LEFT JOIN erp_journal_items ji ON ji.journal_id = je.id
    ";

    $attempts = [];

    if (in_array('reference', $cols, true)) {
        $attempts[] = [
            $baseSelect . "
                WHERE je.reference = ?
                  AND je.date = ?
                  AND {$paymentDescSql}
                GROUP BY je.id, je.description, je.date" . (in_array('reference', $cols, true) ? ', je.reference' : '') . "
                HAVING ABS(COALESCE(SUM(ji.debit), 0) - ?) < 0.02
                ORDER BY je.id DESC
                LIMIT 1
            ",
            [$voucherRef, $collectionDate, $amount],
        ];
    }

    $attempts[] = [
        $baseSelect . "
            WHERE je.date = ?
              AND je.description LIKE ?
              AND {$paymentDescSql}
            GROUP BY je.id, je.description, je.date" . (in_array('reference', $cols, true) ? ', je.reference' : '') . "
            HAVING ABS(COALESCE(SUM(ji.debit), 0) - ?) < 0.02
            ORDER BY je.id DESC
            LIMIT 1
        ",
        [$collectionDate, '%' . $voucherRef . '%', $amount],
    ];

    $attempts[] = [
        $baseSelect . "
            WHERE je.date = ?
              AND {$paymentDescSql}
            GROUP BY je.id, je.description, je.date" . (in_array('reference', $cols, true) ? ', je.reference' : '') . "
            HAVING ABS(COALESCE(SUM(ji.debit), 0) - ?) < 0.02
            ORDER BY je.id DESC
            LIMIT 1
        ",
        [$collectionDate, $amount],
    ];

    try {
        foreach ($attempts as [$sql, $params]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

