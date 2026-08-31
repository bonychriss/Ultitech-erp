<?php

/**
 * Sales ? Revenue: invoice-backed vouchers in revenue_entries (source_invoice_id).
 * Cash/bank credits are posted only when payment is recognized (register / collect / sales payment), not on invoice create.
 */

function revenue_connection_has_revenue_entries($conn)
{
    if (!($conn instanceof PDO)) {
        return false;
    }
    try {
        if (function_exists('erp_connection_has_table')) {
            return erp_connection_has_table($conn, 'revenue_entries');
        }
        $st = $conn->query("SHOW TABLES LIKE 'revenue_entries'");
        return (bool) ($st && $st->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Operational DB name for revenue (env, companies row, or active tenant PDO).
 */
function revenue_resolve_data_db_name()
{
    if (defined('DATA_DB_NAME') && trim((string) DATA_DB_NAME) !== '') {
        return trim((string) DATA_DB_NAME);
    }

    global $control_pdo;
    $cid = 0;
    if (function_exists('currentCompanyId')) {
        $cid = (int) (currentCompanyId() ?: 0);
    }
    if ($cid <= 0 && !empty($_SESSION['company_id'])) {
        $cid = (int) $_SESSION['company_id'];
    }
    if ($cid > 0 && ($control_pdo ?? null) instanceof PDO && function_exists('tableExists') && tableExists('companies', $control_pdo)) {
        try {
            $st = $control_pdo->prepare('SELECT db_name FROM companies WHERE id = ? LIMIT 1');
            $st->execute(array($cid));
            $db = trim((string) ($st->fetchColumn() ?: ''));
            if ($db !== '') {
                return $db;
            }
        } catch (Throwable $e) {
        }
    }

    if (isset($GLOBALS['tenant_pdo']) && $GLOBALS['tenant_pdo'] instanceof PDO) {
        try {
            $db = trim((string) $GLOBALS['tenant_pdo']->query('SELECT DATABASE()')->fetchColumn());
            if ($db !== '') {
                return $db;
            }
        } catch (Throwable $e) {
        }
    }

    return '';
}

/**
 * PDO that has revenue_entries (tenant voucher DB on production).
 */
function revenue_resolve_pdo()
{
    global $pdo, $control_pdo;

    if (isset($GLOBALS['tenant_pdo']) && revenue_connection_has_revenue_entries($GLOBALS['tenant_pdo'])) {
        return $GLOBALS['tenant_pdo'];
    }
    if (revenue_connection_has_revenue_entries($pdo)) {
        return $pdo;
    }

    $dataDb = revenue_resolve_data_db_name();
    if ($dataDb === '' && ($pdo ?? null) instanceof PDO) {
        try {
            $db = trim((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
            if ($db !== '' && revenue_connection_has_revenue_entries($pdo)) {
                return $pdo;
            }
        } catch (Throwable $e) {
        }
    }

    $candidates = array();
    if ($dataDb !== '') {
        $candidates[] = $dataDb;
    }
    $candidates[] = 'new_trading_voucher-35313030c7e2';

    if (function_exists('connectToTenantDatabase')) {
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        foreach (array_values(array_unique(array_filter($candidates))) as $dbName) {
            $tenantPdo = connectToTenantDatabase($dbName, $host);
            if ($tenantPdo instanceof PDO && revenue_connection_has_revenue_entries($tenantPdo)) {
                return $tenantPdo;
            }
        }
    }

    if (revenue_connection_has_revenue_entries($control_pdo)) {
        return $control_pdo;
    }

    return null;
}

function revenueEntriesTableColumns(PDO $pdo): array
{
    try {
        $c = $pdo->query('SHOW COLUMNS FROM revenue_entries')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($c) ? $c : [];
    } catch (Throwable $e) {
        return [];
    }
}

function revenueInvoicesTableColumns(PDO $pdo): array
{
    try {
        $c = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN);
        return is_array($c) ? $c : [];
    } catch (Throwable $e) {
        return [];
    }
}

function ensureRevenueSourceInvoiceSchema($pdo)
{
    try {
        $cols = revenueEntriesTableColumns($pdo);
        if ($cols && !in_array('source_invoice_id', $cols, true)) {
            $pdo->exec('ALTER TABLE revenue_entries ADD COLUMN source_invoice_id INT NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $pdo->exec('CREATE UNIQUE INDEX uq_revenue_entries_source_invoice ON revenue_entries (source_invoice_id)');
    } catch (Throwable $e) {
        // already exists or incompatible
    }
}

/**
 * Next voucher number (shared with revenue_process.php).
 */
function generateRevenueVoucherNumber(PDO $pdo): string
{
    $year = date('Y');
    $month = date('M');
    $prefix = "REV-$year-$month-";

    $stmt = $pdo->prepare('SELECT voucher_number FROM revenue_entries WHERE voucher_number LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($last && !empty($last['voucher_number'])) {
        $parts = explode('-', (string) $last['voucher_number']);
        $num = (int) end($parts) + 1;
    } else {
        $num = 1;
    }

    return $prefix . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
}

/**
 * Create or update revenue_entries for an invoice. Returns entry id, or 0 if skipped / voided.
 */
function syncInvoiceToRevenue($pdo, $invoiceId)
{
    if ($invoiceId <= 0) {
        return 0;
    }

    ensureRevenueSourceInvoiceSchema($pdo);

    $invCols = revenueInvoicesTableColumns($pdo);
    if (!$invCols) {
        return 0;
    }

    $hasSub = in_array('subtotal', $invCols, true);
    $hasDisc = in_array('discount_amount', $invCols, true);
    $hasTax = in_array('tax_amount', $invCols, true);
    $hasShip = in_array('shipping_charges', $invCols, true);
    $hasPaid = in_array('amount_paid', $invCols, true);
    $subSel = $hasSub ? 'COALESCE(i.subtotal, 0)' : '0';
    $discSel = $hasDisc ? 'COALESCE(i.discount_amount, 0)' : '0';
    $taxSel = $hasTax ? 'COALESCE(i.tax_amount, 0)' : '0';
    $shipSel = $hasShip ? 'COALESCE(i.shipping_charges, 0)' : '0';
    $paidSel = $hasPaid ? 'COALESCE(i.amount_paid, 0)' : '0';

    $sql = "
        SELECT
            i.id,
            i.invoice_number,
            i.invoice_date,
            i.customer_id,
            i.total_amount,
            {$subSel} AS subtotal,
            {$discSel} AS discount_amount,
            {$taxSel} AS tax_amount,
            {$shipSel} AS shipping_charges,
            {$paidSel} AS amount_paid,
            LOWER(TRIM(COALESCE(i.status, ''))) AS inv_status,
            COALESCE(c.company_name, '') AS customer_name
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.id = ?
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$invoiceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }

    $invStatus = (string) ($row['inv_status'] ?? '');
    if ($invStatus === 'cancelled' || $invStatus === 'canceled') {
        voidRevenueByInvoice($pdo, $invoiceId);

        return 0;
    }

    $subtotal = (float) ($row['subtotal'] ?? 0);
    $discount = (float) ($row['discount_amount'] ?? 0);
    $tax = (float) ($row['tax_amount'] ?? 0);
    $ship = (float) ($row['shipping_charges'] ?? 0);
    $amountTotal = (float) ($row['total_amount'] ?? 0);
    $invoicePaid = (float) ($row['amount_paid'] ?? 0);

    $amountExclusive = max(0, $subtotal - $discount + $ship);
    $vatAmount = $tax;
    if (abs(($amountExclusive + $vatAmount) - $amountTotal) > 0.05) {
        $vatAmount = $tax;
        $amountExclusive = max(0, round($amountTotal - $vatAmount, 2));
    }

    $newTotalPaid = min($amountTotal, $invoicePaid);
    if ($newTotalPaid >= $amountTotal - 0.02) {
        $paymentStatus = 'Paid';
    } elseif ($newTotalPaid > 0.01) {
        $paymentStatus = 'Partial';
    } else {
        $paymentStatus = 'Unpaid';
    }

    $customerName = trim((string) ($row['customer_name'] ?? ''));
    if ($customerName === '') {
        $customerName = 'Customer #' . (int) ($row['id'] ?? 0);
    }
    $narration = 'Sales invoice ' . ($row['invoice_number'] ?? '') . ' (from Sales)';
    $entryDate = $row['invoice_date'] ?: date('Y-m-d');

    $stEx = $pdo->prepare('SELECT id FROM revenue_entries WHERE source_invoice_id = ? LIMIT 1');
    $stEx->execute([$invoiceId]);
    $entryId = (int) $stEx->fetchColumn();

    if ($entryId <= 0) {
        $stL = $pdo->prepare('SELECT revenue_entry_id FROM revenue_ledger WHERE source_type = ? AND source_id = ? LIMIT 1');
        $stL->execute(['invoice', $invoiceId]);
        $linked = (int) $stL->fetchColumn();
        if ($linked > 0) {
            $stE = $pdo->prepare('SELECT source_invoice_id FROM revenue_entries WHERE id = ? LIMIT 1');
            $stE->execute([$linked]);
            $src = $stE->fetchColumn();
            if ($src === null || $src === false || (int) $src === 0) {
                $entryId = $linked;
            }
        }
    }

    $reCols = revenueEntriesTableColumns($pdo);
    $hasSourceCol = in_array('source_invoice_id', $reCols, true);
    $accountCol = null;
    foreach (['account_id', 'bank_account_id', 'gl_account_id', 'financial_account_id'] as $ac) {
        if (in_array($ac, $reCols, true)) {
            $accountCol = $ac;
            break;
        }
    }

    if ($entryId > 0) {
        if ($hasSourceCol) {
            $upd = $pdo->prepare('UPDATE revenue_entries SET
                entry_date = ?,
                customer_name = ?,
                narration = ?,
                amount_exclusive = ?,
                vat_amount = ?,
                amount_total = ?,
                total_paid = ?,
                payment_status = ?,
                source_invoice_id = COALESCE(source_invoice_id, ?)
                WHERE id = ?');
            $upd->execute([
                $entryDate,
                $customerName,
                $narration,
                $amountExclusive,
                $vatAmount,
                $amountTotal,
                $newTotalPaid,
                $paymentStatus,
                $invoiceId,
                $entryId,
            ]);
        } else {
            $upd = $pdo->prepare('UPDATE revenue_entries SET
                entry_date = ?,
                customer_name = ?,
                narration = ?,
                amount_exclusive = ?,
                vat_amount = ?,
                amount_total = ?,
                total_paid = ?,
                payment_status = ?
                WHERE id = ?');
            $upd->execute([
                $entryDate,
                $customerName,
                $narration,
                $amountExclusive,
                $vatAmount,
                $amountTotal,
                $newTotalPaid,
                $paymentStatus,
                $entryId,
            ]);
        }

        return $entryId;
    }

    $voucher = generateRevenueVoucherNumber($pdo);

    if ($hasSourceCol && $accountCol) {
        $ins = $pdo->prepare("INSERT INTO revenue_entries
            (voucher_number, entry_date, customer_name, narration, payment_mode, amount_exclusive, vat_amount, amount_total, total_paid, payment_status, approval_status, attachment, source_invoice_id, {$accountCol})
            VALUES (?, ?, ?, ?, 'Account Receivable', ?, ?, ?, ?, ?, 'Pending', NULL, ?, NULL)");
        $ins->execute([
            $voucher,
            $entryDate,
            $customerName,
            $narration,
            $amountExclusive,
            $vatAmount,
            $amountTotal,
            $newTotalPaid,
            $paymentStatus,
            $invoiceId,
        ]);
    } elseif ($hasSourceCol) {
        $ins = $pdo->prepare('INSERT INTO revenue_entries
            (voucher_number, entry_date, customer_name, narration, payment_mode, amount_exclusive, vat_amount, amount_total, total_paid, payment_status, approval_status, attachment, source_invoice_id)
            VALUES (?, ?, ?, ?, \'Account Receivable\', ?, ?, ?, ?, ?, \'Pending\', NULL, ?)');
        $ins->execute([
            $voucher,
            $entryDate,
            $customerName,
            $narration,
            $amountExclusive,
            $vatAmount,
            $amountTotal,
            $newTotalPaid,
            $paymentStatus,
            $invoiceId,
        ]);
    } elseif ($accountCol) {
        $ins = $pdo->prepare("INSERT INTO revenue_entries
            (voucher_number, entry_date, customer_name, narration, payment_mode, amount_exclusive, vat_amount, amount_total, total_paid, payment_status, approval_status, attachment, {$accountCol})
            VALUES (?, ?, ?, ?, 'Account Receivable', ?, ?, ?, ?, ?, 'Pending', NULL, NULL)");
        $ins->execute([
            $voucher,
            $entryDate,
            $customerName,
            $narration,
            $amountExclusive,
            $vatAmount,
            $amountTotal,
            $newTotalPaid,
            $paymentStatus,
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO revenue_entries
            (voucher_number, entry_date, customer_name, narration, payment_mode, amount_exclusive, vat_amount, amount_total, total_paid, payment_status, approval_status, attachment)
            VALUES (?, ?, ?, ?, 'Account Receivable', ?, ?, ?, ?, ?, 'Pending', NULL)");
        $ins->execute([
            $voucher,
            $entryDate,
            $customerName,
            $narration,
            $amountExclusive,
            $vatAmount,
            $amountTotal,
            $newTotalPaid,
            $paymentStatus,
        ]);
    }

    return (int) $pdo->lastInsertId();
}

/**
 * Mark linked revenue voucher void when invoice is cancelled / reconciled away.
 */
function voidRevenueByInvoice($pdo, $invoiceId)
{
    if ($invoiceId <= 0) {
        return;
    }
    ensureRevenueSourceInvoiceSchema($pdo);
    try {
        $st = $pdo->prepare('SELECT id, total_paid, narration FROM revenue_entries WHERE source_invoice_id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $ent = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ent) {
            return;
        }
        $note = ' [Invoice voided / cancelled in Sales]';
        $paid = (float) ($ent['total_paid'] ?? 0);
        if ($paid > 0.01) {
            $note = ' [Invoice cancelled � review collections and balances]';
        }
        $nar = (string) ($ent['narration'] ?? '');
        if (stripos($nar, 'Invoice voided') === false && stripos($nar, 'Invoice cancelled') === false) {
            $nar .= $note;
        }
        $pdo->prepare('UPDATE revenue_entries SET approval_status = ?, narration = ? WHERE id = ?')
            ->execute(['Voided', $nar, (int) $ent['id']]);
    } catch (Throwable $e) {
        // ignore
    }
}
