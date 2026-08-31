<?php
/**
 * Unified General Ledger posting for invoices, sales payments, and revenue collections.
 */

require_once __DIR__ . '/accounting_service.php';
require_once __DIR__ . '/fa_gl_linking.php';
require_once __DIR__ . '/accounting_settings.php';

if (!function_exists('invoice_gl_tables_ready')) {
    function invoice_gl_tables_ready(PDO $pdo): bool
    {
        return function_exists('tableExists')
            && tableExists('erp_accounts', $pdo)
            && tableExists('erp_journal_entries', $pdo)
            && tableExists('erp_journal_items', $pdo);
    }
}

if (!function_exists('invoice_gl_journal_reference_exists')) {
    function invoice_gl_journal_reference_exists(PDO $pdo, string $reference): bool
    {
        $reference = trim($reference);
        if ($reference === '' || !invoice_gl_tables_ready($pdo)) {
            return false;
        }
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM erp_journal_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (in_array('reference', $cols, true)) {
                $st = $pdo->prepare('SELECT 1 FROM erp_journal_entries WHERE reference = ? LIMIT 1');
                $st->execute([$reference]);
                return (bool) $st->fetchColumn();
            }
            $st = $pdo->prepare('SELECT 1 FROM erp_journal_entries WHERE description LIKE ? LIMIT 1');
            $st->execute(['%' . $reference . '%']);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('invoice_gl_journal_reference_exists: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('invoice_gl_find_account_id')) {
    /**
     * Resolve an erp_accounts.id by code and/or name patterns.
     *
     * @param list<string> $codes
     * @param list<string> $namePatterns partial names
     */
    function invoice_gl_find_account_id(PDO $pdo, string $type, array $codes = [], array $namePatterns = []): ?int
    {
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE type = ? AND code = ? LIMIT 1');
            $st->execute([$type, $code]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        foreach ($namePatterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE type = ? AND name LIKE ? ORDER BY code ASC LIMIT 1');
            $st->execute([$type, '%' . $pattern . '%']);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        $st = $pdo->prepare('SELECT id FROM erp_accounts WHERE type = ? ORDER BY code ASC LIMIT 1');
        $st->execute([$type]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id > 0 ? $id : null;
    }
}

if (!function_exists('invoice_gl_resolve_financial_account')) {
    /** Map a financial_accounts row to an erp_accounts.id (and persist gl_account_id when missing). */
    function invoice_gl_resolve_financial_account(PDO $pdo, int $financialAccountId): ?int
    {
        return fa_gl_link_financial_account($pdo, $financialAccountId);
    }
}

if (!function_exists('invoice_gl_load_invoice_row')) {
    function invoice_gl_load_invoice_row(PDO $pdo, int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $cols = [];
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return null;
        }
        if (!$cols) {
            return null;
        }

        $hasSub = in_array('subtotal', $cols, true);
        $hasDisc = in_array('discount_amount', $cols, true);
        $hasTax = in_array('tax_amount', $cols, true);
        $hasShip = in_array('shipping_charges', $cols, true);
        $subSel = $hasSub ? 'COALESCE(i.subtotal, 0)' : '0';
        $discSel = $hasDisc ? 'COALESCE(i.discount_amount, 0)' : '0';
        $taxSel = $hasTax ? 'COALESCE(i.tax_amount, 0)' : '0';
        $shipSel = $hasShip ? 'COALESCE(i.shipping_charges, 0)' : '0';

        $sql = "
            SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount,
                   {$subSel} AS subtotal,
                   {$discSel} AS discount_amount,
                   {$taxSel} AS tax_amount,
                   {$shipSel} AS shipping_charges,
                   COALESCE(c.company_name, '') AS customer_name
            FROM invoices i
            LEFT JOIN customers c ON c.id = i.customer_id
            WHERE i.id = ?
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('invoice_gl_split_amounts')) {
    /** @return array{total: float, revenue: float, tax: float} */
    function invoice_gl_split_amounts(array $row): array
    {
        $total = round((float) ($row['total_amount'] ?? 0), 2);
        $subtotal = (float) ($row['subtotal'] ?? 0);
        $discount = (float) ($row['discount_amount'] ?? 0);
        $tax = round((float) ($row['tax_amount'] ?? 0), 2);
        $ship = (float) ($row['shipping_charges'] ?? 0);
        $revenue = round(max(0, $subtotal - $discount + $ship), 2);
        if ($revenue <= 0 && $total > 0) {
            $revenue = round(max(0, $total - $tax), 2);
        }
        if (abs(($revenue + $tax) - $total) > 0.05 && $total > 0) {
            $tax = round(max(0, $total - $revenue), 2);
        }

        return ['total' => $total, 'revenue' => $revenue, 'tax' => $tax];
    }
}

if (!function_exists('invoice_gl_post_balanced_entry')) {
    function invoice_gl_post_balanced_entry(
        PDO $pdo,
        string $date,
        string $reference,
        string $description,
        array $items
    ): int {
        if (!invoice_gl_tables_ready($pdo)) {
            throw new RuntimeException('General ledger tables are not available.');
        }
        if (invoice_gl_journal_reference_exists($pdo, $reference)) {
            return 0;
        }

        $svc = new AccountingService($pdo);
        $journalId = $svc->postEntry($date, $reference, $description, $items);
        if (!$journalId) {
            throw new RuntimeException('Failed to post journal entry: ' . $reference);
        }

        return (int) $journalId;
    }
}

if (!function_exists('invoice_gl_ensure_invoice_recognition')) {
    /** Dr AR / Cr Revenue (+ Tax). Idempotent per invoice. */
    function invoice_gl_ensure_invoice_recognition(PDO $pdo, int $invoiceId): int
    {
        if ($invoiceId <= 0) {
            return 0;
        }

        $reference = 'INV-REC-' . $invoiceId;
        if (invoice_gl_journal_reference_exists($pdo, $reference)) {
            return 0;
        }

        $row = invoice_gl_load_invoice_row($pdo, $invoiceId);
        if (!$row) {
            return 0;
        }

        $amounts = invoice_gl_split_amounts($row);
        if ($amounts['total'] <= 0) {
            return 0;
        }

        $arId = invoice_gl_find_account_id($pdo, 'asset', ['1200', '1010', '1100'], ['Receivable']);
        $revId = accounting_resolve_default_sales_revenue_gl_account_id($pdo);
        $taxId = invoice_gl_find_account_id($pdo, 'liability', ['2200', '2020'], ['Tax']);

        if (!$arId || !$revId) {
            throw new RuntimeException('Could not resolve AR or Revenue accounts in the general ledger.');
        }

        $items = [
            ['account_id' => $arId, 'debit' => $amounts['total'], 'credit' => 0],
            ['account_id' => $revId, 'debit' => 0, 'credit' => $amounts['revenue']],
        ];
        if ($amounts['tax'] > 0.00001 && $taxId) {
            $items[] = ['account_id' => $taxId, 'debit' => 0, 'credit' => $amounts['tax']];
        } elseif ($amounts['tax'] > 0.00001) {
            $items[1]['credit'] = round($amounts['total'], 2);
        }

        // Automated Cost of Goods Sold (COGS) Recognition
        $cogsId = invoice_gl_find_account_id($pdo, 'expense', ['6000', '5001'], ['Cost of Goods', 'COGS']);
        $invId = invoice_gl_find_account_id($pdo, 'asset', ['1300'], ['Inventory']);
        if ($cogsId && $invId) {
            $cogsTotal = 0.0;
            try {
                $stmtCogs = $pdo->prepare("
                    SELECT soi.quantity, COALESCE(p.buying_price, p.cost_price, 0) AS cost
                    FROM sales_order_items soi
                    JOIN invoices i ON i.order_id = soi.order_id
                    JOIN products p ON soi.product_id = p.id
                    WHERE i.id = ?
                ");
                $stmtCogs->execute([$invoiceId]);
                while ($cogsItem = $stmtCogs->fetch(PDO::FETCH_ASSOC)) {
                    $cogsTotal += (float)$cogsItem['quantity'] * (float)$cogsItem['cost'];
                }
            } catch (Throwable $e) {
                error_log('COGS calculation failed: ' . $e->getMessage());
            }

            if ($cogsTotal > 0.00001) {
                $items[] = ['account_id' => $cogsId, 'debit' => round($cogsTotal, 2), 'credit' => 0];
                $items[] = ['account_id' => $invId, 'debit' => 0, 'credit' => round($cogsTotal, 2)];
            }
        }

        $invNo = trim((string) ($row['invoice_number'] ?? ('#' . $invoiceId)));
        $customer = trim((string) ($row['customer_name'] ?? ''));
        $desc = 'Invoice recognition: ' . $invNo . ($customer !== '' ? ' - ' . $customer : '');
        $date = (string) ($row['invoice_date'] ?? date('Y-m-d'));

        return invoice_gl_post_balanced_entry($pdo, $date, $reference, $desc, $items);
    }
}

if (!function_exists('invoice_gl_post_invoice_payment')) {
    /** Dr Bank/Cash / Cr AR. Idempotent per payment id. */
    function invoice_gl_post_invoice_payment(
        PDO $pdo,
        int $invoiceId,
        int $paymentId,
        float $amount,
        string $paymentDate,
        int $financialAccountId,
        ?string $referenceOverride = null
    ): int {
        if ($invoiceId <= 0 || $amount <= 0) {
            return 0;
        }

        invoice_gl_ensure_invoice_recognition($pdo, $invoiceId);

        $reference = $referenceOverride !== null && trim($referenceOverride) !== ''
            ? trim($referenceOverride)
            : ($paymentId > 0
                ? ('INV-PAY-' . $invoiceId . '-' . $paymentId)
                : ('INV-PAY-' . $invoiceId . '-' . md5($paymentDate . '|' . $amount . '|' . $financialAccountId)));

        if (invoice_gl_journal_reference_exists($pdo, $reference)) {
            return 0;
        }

        $bankId = invoice_gl_resolve_financial_account($pdo, $financialAccountId);
        if (!$bankId) {
            $bankId = invoice_gl_find_account_id($pdo, 'asset', ['1002', '1001'], ['Bank', 'Cash']);
        }
        $arId = invoice_gl_find_account_id($pdo, 'asset', ['1200', '1010', '1100'], ['Receivable']);
        if (!$bankId || !$arId) {
            throw new RuntimeException('Could not resolve Bank or Accounts Receivable for payment posting.');
        }

        $row = invoice_gl_load_invoice_row($pdo, $invoiceId);
        $invNo = trim((string) ($row['invoice_number'] ?? ('#' . $invoiceId)));
        $desc = 'Payment received: ' . $invNo . ' (TZS ' . number_format($amount, 2) . ')';

        return invoice_gl_post_balanced_entry(
            $pdo,
            $paymentDate,
            $reference,
            $desc,
            [
                ['account_id' => $bankId, 'debit' => round($amount, 2), 'credit' => 0],
                ['account_id' => $arId, 'debit' => 0, 'credit' => round($amount, 2)],
            ]
        );
    }
}

if (!function_exists('invoice_gl_post_revenue_recognition')) {
    function invoice_gl_post_revenue_recognition(
        PDO $pdo,
        int $entryId,
        string $voucherNumber,
        string $entryDate,
        string $customerName,
        string $narration,
        float $amountTotal,
        float $amountExclusive,
        float $vatAmount,
        ?int $revenueGlAccountId = null
    ): int {
        if ($entryId <= 0 || $amountTotal <= 0) {
            return 0;
        }

        $reference = 'REV-REC-' . $entryId;
        if (invoice_gl_journal_reference_exists($pdo, $reference)) {
            return 0;
        }

        $arId = invoice_gl_find_account_id($pdo, 'asset', ['1200', '1010', '1100'], ['Receivable']);
        $revId = $revenueGlAccountId ?: accounting_resolve_default_sales_revenue_gl_account_id($pdo);
        $taxId = invoice_gl_find_account_id($pdo, 'liability', ['2200', '2020'], ['Tax']);

        if (!$arId || !$revId) {
            throw new RuntimeException('Could not resolve AR or Revenue accounts for revenue entry.');
        }

        $items = [
            ['account_id' => $arId, 'debit' => round($amountTotal, 2), 'credit' => 0],
            ['account_id' => $revId, 'debit' => 0, 'credit' => round($amountExclusive, 2)],
        ];
        if ($vatAmount > 0.00001 && $taxId) {
            $items[] = ['account_id' => $taxId, 'debit' => 0, 'credit' => round($vatAmount, 2)];
        } elseif ($vatAmount > 0.00001) {
            $items[1]['credit'] = round($amountTotal, 2);
        }

        $desc = 'Revenue recognition: ' . $voucherNumber . ' - ' . $customerName . ($narration !== '' ? ' (' . $narration . ')' : '');

        return invoice_gl_post_balanced_entry($pdo, $entryDate, $reference, $desc, $items);
    }
}

if (!function_exists('invoice_gl_post_revenue_payment')) {
    function invoice_gl_post_revenue_payment(
        PDO $pdo,
        int $entryId,
        string $voucherNumber,
        string $collectionDate,
        float $amount,
        int $financialAccountId,
        ?int $collectionId = null,
        ?string $referenceOverride = null
    ): int {
        if ($entryId <= 0 || $amount <= 0) {
            return 0;
        }

        $reference = $referenceOverride !== null && trim($referenceOverride) !== ''
            ? trim($referenceOverride)
            : ($collectionId > 0
                ? ('REV-PAY-' . $entryId . '-' . $collectionId)
                : ('REV-PAY-' . $entryId . '-' . md5($collectionDate . '|' . $amount . '|' . $financialAccountId)));

        if (invoice_gl_journal_reference_exists($pdo, $reference)) {
            return 0;
        }

        $bankId = invoice_gl_resolve_financial_account($pdo, $financialAccountId);
        if (!$bankId) {
            $bankId = invoice_gl_find_account_id($pdo, 'asset', ['1002', '1001'], ['Bank', 'Cash']);
        }
        $arId = invoice_gl_find_account_id($pdo, 'asset', ['1200', '1010', '1100'], ['Receivable']);
        if (!$bankId || !$arId) {
            throw new RuntimeException('Could not resolve Bank or AR for revenue collection.');
        }

        $desc = 'Revenue collection: ' . $voucherNumber . ' (TZS ' . number_format($amount, 2) . ')';

        return invoice_gl_post_balanced_entry(
            $pdo,
            $collectionDate,
            $reference,
            $desc,
            [
                ['account_id' => $bankId, 'debit' => round($amount, 2), 'credit' => 0],
                ['account_id' => $arId, 'debit' => 0, 'credit' => round($amount, 2)],
            ]
        );
    }
}

if (!function_exists('invoice_gl_sales_invoice_already_posted')) {
    function invoice_gl_sales_invoice_already_posted(PDO $pdo, int $invoiceId, string $invoiceNumber): bool
    {
        if (invoice_gl_journal_reference_exists($pdo, 'INV-REC-' . $invoiceId)) {
            return true;
        }
        if ($invoiceNumber === '') {
            return false;
        }
        try {
            $st = $pdo->prepare(
                'SELECT 1 FROM erp_journal_entries
                 WHERE description LIKE ? OR description LIKE ?
                 LIMIT 1'
            );
            $st->execute([
                '%Invoice Posting: ' . $invoiceNumber . '%',
                '%Ref: Invoice #' . $invoiceId . '%',
            ]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('invoice_gl_financial_account_from_payment_method')) {
    function invoice_gl_financial_account_from_payment_method(PDO $pdo, string $paymentMethod, int $fallbackAccountId = 1): int
    {
        if (!tableExists('financial_accounts', $pdo)) {
            return $fallbackAccountId;
        }

        $label = trim($paymentMethod);
        if (strpos($label, ' - ') !== false) {
            $label = trim(substr($label, strrpos($label, ' - ') + 3));
        }
        if (preg_match('/^\s*[0-9]{3,10}\s*-\s*(.+)$/', $label, $m)) {
            $label = trim($m[1]);
        }

        if ($label !== '') {
            $st = $pdo->prepare(
                "SELECT id FROM financial_accounts
                 WHERE status = 'active'
                   AND (name LIKE ? OR name LIKE ?)
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $st->execute(['%' . $label . '%', '% - ' . $label]);
            $id = (int) ($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        $bucket = 'bank';
        if (stripos($paymentMethod, 'cash') !== false) {
            $bucket = 'cash';
        } elseif (stripos($paymentMethod, 'mobile') !== false) {
            $bucket = 'mobile';
        }
        $st = $pdo->prepare(
            "SELECT id FROM financial_accounts
             WHERE status = 'active' AND type = ?
             ORDER BY id ASC
             LIMIT 1"
        );
        $st->execute([$bucket]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id > 0 ? $id : $fallbackAccountId;
    }
}

if (!function_exists('invoice_gl_default_financial_account_id')) {
    function invoice_gl_default_financial_account_id(PDO $pdo): int
    {
        if (!tableExists('financial_accounts', $pdo)) {
            return 0;
        }
        $id = (int) ($pdo->query("SELECT id FROM financial_accounts WHERE status = 'active' AND type = 'bank' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        return (int) ($pdo->query("SELECT id FROM financial_accounts WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
    }
}

if (!function_exists('invoice_gl_backfill_all')) {
    /**
     * Post historical invoices/revenue to GL. Idempotent � safe to run multiple times.
     *
     * @return array<string, mixed>
     */
    function invoice_gl_backfill_all(PDO $pdo, array $options = []): array
    {
        $dryRun = !empty($options['dry_run']);
        $defaultFaId = invoice_gl_default_financial_account_id($pdo);

        $stats = [
            'dry_run' => $dryRun,
            'invoices_recognized' => 0,
            'invoice_payments' => 0,
            'revenue_recognized' => 0,
            'revenue_payments' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        if (!invoice_gl_tables_ready($pdo)) {
            $stats['errors'][] = 'General ledger tables are not available.';
            return $stats;
        }

        $runStep = static function (callable $fn) use ($dryRun, &$stats): void {
            if ($dryRun) {
                return;
            }
            try {
                $fn();
            } catch (Throwable $e) {
                $stats['errors'][] = $e->getMessage();
            }
        };

        if (tableExists('invoices', $pdo)) {
            $sql = "SELECT i.id, i.invoice_number
                    FROM invoices i
                    WHERE LOWER(COALESCE(i.status, '')) NOT IN ('cancelled', 'canceled')
                    ORDER BY i.id ASC";
            foreach ($pdo->query($sql) as $row) {
                $invoiceId = (int) ($row['id'] ?? 0);
                $invoiceNumber = trim((string) ($row['invoice_number'] ?? ''));
                if ($invoiceId <= 0 || invoice_gl_sales_invoice_already_posted($pdo, $invoiceId, $invoiceNumber)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($dryRun) {
                    $stats['invoices_recognized']++;
                    continue;
                }
                $runStep(static function () use ($pdo, $invoiceId, &$stats): void {
                    invoice_gl_ensure_invoice_recognition($pdo, $invoiceId);
                    $stats['invoices_recognized']++;
                });
            }
        }

        if (tableExists('sales_payments', $pdo)) {
            foreach ($pdo->query('SELECT id, invoice_id, amount, payment_date, payment_method FROM sales_payments ORDER BY id ASC') as $row) {
                $paymentId = (int) ($row['id'] ?? 0);
                $invoiceId = (int) ($row['invoice_id'] ?? 0);
                $amount = (float) ($row['amount'] ?? 0);
                if ($paymentId <= 0 || $invoiceId <= 0 || $amount <= 0) {
                    continue;
                }
                if (invoice_gl_journal_reference_exists($pdo, 'INV-PAY-' . $invoiceId . '-' . $paymentId)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($dryRun) {
                    $stats['invoice_payments']++;
                    continue;
                }
                $faId = invoice_gl_financial_account_from_payment_method($pdo, (string) ($row['payment_method'] ?? ''), $defaultFaId);
                $payDate = (string) ($row['payment_date'] ?? date('Y-m-d'));
                $runStep(static function () use ($pdo, $invoiceId, $paymentId, $amount, $payDate, $faId, &$stats): void {
                    invoice_gl_post_invoice_payment($pdo, $invoiceId, $paymentId, $amount, $payDate, $faId);
                    $stats['invoice_payments']++;
                });
            }
        }

        if (tableExists('revenue_entries', $pdo)) {
            $sql = "SELECT id, voucher_number, entry_date, customer_name, narration,
                           amount_total, amount_exclusive, vat_amount, revenue_account_id
                    FROM revenue_entries
                    WHERE (source_invoice_id IS NULL OR source_invoice_id = 0)
                      AND LOWER(COALESCE(approval_status, '')) NOT IN ('void', 'voided')
                    ORDER BY id ASC";
            foreach ($pdo->query($sql) as $row) {
                $entryId = (int) ($row['id'] ?? 0);
                if ($entryId <= 0 || invoice_gl_journal_reference_exists($pdo, 'REV-REC-' . $entryId)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($dryRun) {
                    $stats['revenue_recognized']++;
                    continue;
                }
                $runStep(static function () use ($pdo, $row, $entryId, &$stats): void {
                    invoice_gl_post_revenue_recognition(
                        $pdo,
                        $entryId,
                        (string) ($row['voucher_number'] ?? ('REV-' . $entryId)),
                        (string) ($row['entry_date'] ?? date('Y-m-d')),
                        (string) ($row['customer_name'] ?? ''),
                        (string) ($row['narration'] ?? ''),
                        (float) ($row['amount_total'] ?? 0),
                        (float) ($row['amount_exclusive'] ?? 0),
                        (float) ($row['vat_amount'] ?? 0),
                        !empty($row['revenue_account_id']) ? (int) $row['revenue_account_id'] : null
                    );
                    $stats['revenue_recognized']++;
                });
            }
        }

        if (tableExists('revenue_collections', $pdo) && tableExists('revenue_entries', $pdo)) {
            $accCol = function_exists('resolveExistingColumn')
                ? resolveExistingColumn('revenue_collections', 'account_id', ['bank_account_id', 'gl_account_id', 'financial_account_id'])
                : 'account_id';
            $accSelect = $accCol ? ('COALESCE(rc.' . $accCol . ', 0) AS deposit_account_id') : '0 AS deposit_account_id';
            $sql = "SELECT rc.id, rc.entry_id, rc.collection_date, rc.amount_collected, {$accSelect}, re.voucher_number
                    FROM revenue_collections rc
                    INNER JOIN revenue_entries re ON re.id = rc.entry_id
                    WHERE rc.amount_collected > 0
                    ORDER BY rc.id ASC";
            foreach ($pdo->query($sql) as $row) {
                $entryId = (int) ($row['entry_id'] ?? 0);
                $collectionId = (int) ($row['id'] ?? 0);
                $amount = (float) ($row['amount_collected'] ?? 0);
                if ($entryId <= 0 || $collectionId <= 0 || $amount <= 0) {
                    continue;
                }
                if (invoice_gl_journal_reference_exists($pdo, 'REV-PAY-' . $entryId . '-' . $collectionId)) {
                    $stats['skipped']++;
                    continue;
                }
                $faId = (int) ($row['deposit_account_id'] ?? 0) ?: $defaultFaId;
                if ($dryRun) {
                    $stats['revenue_payments']++;
                    continue;
                }
                $runStep(static function () use ($pdo, $entryId, $row, $collectionId, $amount, $faId, &$stats): void {
                    invoice_gl_post_revenue_payment(
                        $pdo,
                        $entryId,
                        (string) ($row['voucher_number'] ?? ('REV-' . $entryId)),
                        (string) ($row['collection_date'] ?? date('Y-m-d')),
                        $amount,
                        $faId,
                        $collectionId
                    );
                    $stats['revenue_payments']++;
                });
            }
        }

        if (tableExists('account_transactions', $pdo)) {
            $sql = "SELECT t.id, t.account_id, t.transaction_date, t.amount, t.reference_type, t.reference_id, re.voucher_number
                    FROM account_transactions t
                    LEFT JOIN revenue_entries re ON re.id = t.reference_id
                    WHERE t.type = 'credit' AND t.amount > 0
                      AND t.reference_type IN ('revenue_entry', 'revenue_collection', 'invoice_payment')
                    ORDER BY t.id ASC";
            foreach ($pdo->query($sql) as $row) {
                $txId = (int) ($row['id'] ?? 0);
                $refType = (string) ($row['reference_type'] ?? '');
                $refId = (int) ($row['reference_id'] ?? 0);
                $amount = (float) ($row['amount'] ?? 0);
                $faId = (int) ($row['account_id'] ?? 0) ?: $defaultFaId;
                if ($txId <= 0 || $refId <= 0 || $amount <= 0) {
                    continue;
                }
                $payDate = substr((string) ($row['transaction_date'] ?? date('Y-m-d')), 0, 10);

                if ($refType === 'invoice_payment') {
                    $reference = 'INV-PAY-TX-' . $txId;
                    if (invoice_gl_journal_reference_exists($pdo, $reference)) {
                        $stats['skipped']++;
                        continue;
                    }
                    $dup = $pdo->prepare(
                        "SELECT 1 FROM sales_payments sp
                         WHERE sp.invoice_id = ? AND ABS(sp.amount - ?) < 0.02
                           AND EXISTS (
                               SELECT 1 FROM erp_journal_entries je
                               WHERE je.reference = CONCAT('INV-PAY-', sp.invoice_id, '-', sp.id)
                           )
                         LIMIT 1"
                    );
                    $dup->execute([$refId, $amount]);
                    if ($dup->fetchColumn()) {
                        $stats['skipped']++;
                        continue;
                    }
                    if ($dryRun) {
                        $stats['invoice_payments']++;
                        continue;
                    }
                    $runStep(static function () use ($pdo, $refId, $amount, $payDate, $faId, $reference, &$stats): void {
                        invoice_gl_post_invoice_payment($pdo, $refId, 0, $amount, $payDate, $faId, $reference);
                        $stats['invoice_payments']++;
                    });
                    continue;
                }

                $reference = 'REV-PAY-TX-' . $txId;
                if (invoice_gl_journal_reference_exists($pdo, $reference)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($dryRun) {
                    $stats['revenue_payments']++;
                    continue;
                }
                $runStep(static function () use ($pdo, $refId, $row, $amount, $payDate, $faId, $reference, &$stats): void {
                    invoice_gl_post_revenue_payment(
                        $pdo,
                        $refId,
                        (string) ($row['voucher_number'] ?? ('REV-' . $refId)),
                        $payDate,
                        $amount,
                        $faId,
                        null,
                        $reference
                    );
                    $stats['revenue_payments']++;
                });
            }
        }

        if (tableExists('revenue_entries', $pdo)) {
            $sql = "SELECT id, voucher_number, entry_date, total_paid, payment_mode
                    FROM revenue_entries
                    WHERE (source_invoice_id IS NULL OR source_invoice_id = 0)
                      AND total_paid > 0.01
                      AND LOWER(COALESCE(approval_status, '')) NOT IN ('void', 'voided')
                    ORDER BY id ASC";
            foreach ($pdo->query($sql) as $row) {
                $entryId = (int) ($row['id'] ?? 0);
                $paid = (float) ($row['total_paid'] ?? 0);
                if ($entryId <= 0 || $paid <= 0) {
                    continue;
                }

                $posted = 0.0;
                if (tableExists('account_transactions', $pdo)) {
                    $st = $pdo->prepare(
                        "SELECT COALESCE(SUM(amount), 0) FROM account_transactions
                         WHERE reference_type IN ('revenue_entry', 'revenue_collection')
                           AND reference_id = ? AND type = 'credit'"
                    );
                    $st->execute([$entryId]);
                    $posted = (float) ($st->fetchColumn() ?: 0);
                }
                if (tableExists('revenue_collections', $pdo)) {
                    $st = $pdo->prepare('SELECT COALESCE(SUM(amount_collected), 0) FROM revenue_collections WHERE entry_id = ?');
                    $st->execute([$entryId]);
                    $posted += (float) ($st->fetchColumn() ?: 0);
                }

                $remainder = round($paid - $posted, 2);
                if ($remainder <= 0.01 || invoice_gl_journal_reference_exists($pdo, 'REV-PAY-' . $entryId . '-LEGACY')) {
                    if ($remainder <= 0.01) {
                        $stats['skipped']++;
                    } else {
                        $stats['skipped']++;
                    }
                    continue;
                }
                $faId = invoice_gl_financial_account_from_payment_method($pdo, (string) ($row['payment_mode'] ?? 'Bank'), $defaultFaId);
                if ($dryRun) {
                    $stats['revenue_payments']++;
                    continue;
                }
                $runStep(static function () use ($pdo, $entryId, $row, $remainder, $faId, &$stats): void {
                    invoice_gl_post_revenue_payment(
                        $pdo,
                        $entryId,
                        (string) ($row['voucher_number'] ?? ('REV-' . $entryId)),
                        (string) ($row['entry_date'] ?? date('Y-m-d')),
                        $remainder,
                        $faId,
                        null,
                        'REV-PAY-' . $entryId . '-LEGACY'
                    );
                    $stats['revenue_payments']++;
                });
            }
        }

        return $stats;
    }
}
