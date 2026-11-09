<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/revenue-lib.php';

header('Content-Type: application/json; charset=utf-8');

function revenue_receivable_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        revenue_receivable_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    $pdo = revenueDeskBootstrap();
    requireLogin();
    if (!isFinance() && !isAdmin()) {
        revenue_receivable_json(['ok' => false, 'error' => 'Access denied.'], 403);
    }

    $input = json_decode(file_get_contents('php://input') ?: '[]', true);
    $input = is_array($input) ? $input : [];
    if (!verify_csrf($input['csrf_token'] ?? '')) {
        revenue_receivable_json(['ok' => false, 'error' => 'Your session has expired. Refresh the page and try again.'], 403);
    }

    $entryId = (int) ($input['id'] ?? 0);
    if ($entryId <= 0) {
        revenue_receivable_json(['ok' => false, 'error' => 'Invalid revenue entry.'], 422);
    }

    $GLOBALS['pdo'] = $pdo;
    ensureOutstandingInvoicesSchema();

    $columns = $pdo->query('SHOW COLUMNS FROM erp_outstanding_invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('source_revenue_entry_id', $columns, true)) {
        $pdo->exec('ALTER TABLE erp_outstanding_invoices ADD COLUMN source_revenue_entry_id INT NULL DEFAULT NULL');
    }
    $sourceIndex = $pdo->query("SHOW INDEX FROM erp_outstanding_invoices WHERE Key_name = 'uniq_source_revenue_entry'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($sourceIndex === []) {
        $pdo->exec('ALTER TABLE erp_outstanding_invoices ADD UNIQUE KEY uniq_source_revenue_entry (source_revenue_entry_id)');
    }

    $entryStmt = $pdo->prepare('SELECT * FROM revenue_entries WHERE id = ? LIMIT 1');
    $entryStmt->execute([$entryId]);
    $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        revenue_receivable_json(['ok' => false, 'error' => 'Revenue entry not found.'], 404);
    }

    $sourceInvoiceId = (int) ($entry['source_invoice_id'] ?? 0);
    if (($entry['approval_status'] ?? '') === 'Pending' && $sourceInvoiceId <= 0) {
        revenue_receivable_json(['ok' => false, 'error' => 'Approve this revenue entry before sending it to Receivables.'], 422);
    }

    $entityName = trim((string) ($entry['customer_name'] ?? ''));
    $invoiceNumber = trim((string) ($entry['voucher_number'] ?? ''));
    $dueDate = null;
    if ($sourceInvoiceId > 0 && function_exists('tableExists') && tableExists('invoices', $pdo)) {
        $invoiceColumns = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $invoiceSelect = [];
        if (in_array('invoice_number', $invoiceColumns, true)) $invoiceSelect[] = 'invoice_number';
        if (in_array('customer_id', $invoiceColumns, true)) $invoiceSelect[] = 'customer_id';
        if (in_array('due_date', $invoiceColumns, true)) $invoiceSelect[] = 'due_date';
        if ($invoiceSelect !== []) {
            $invoiceStmt = $pdo->prepare('SELECT ' . implode(', ', $invoiceSelect) . ' FROM invoices WHERE id = ? LIMIT 1');
            $invoiceStmt->execute([$sourceInvoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $dueDate = trim((string) ($invoice['due_date'] ?? ''));
            if ($dueDate === '' || $dueDate === '0000-00-00') {
                $dueDate = null;
            }
            if (trim((string) ($invoice['invoice_number'] ?? '')) !== '') {
                $invoiceNumber = trim((string) $invoice['invoice_number']);
            }
            $customerId = (int) ($invoice['customer_id'] ?? 0);
            if ($customerId > 0 && tableExists('customers', $pdo)) {
                $customerColumns = $pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach (['company_name', 'name', 'customer_name', 'full_name'] as $nameColumn) {
                    if (in_array($nameColumn, $customerColumns, true)) {
                        $customerStmt = $pdo->prepare('SELECT `' . $nameColumn . '` FROM customers WHERE id = ? LIMIT 1');
                        $customerStmt->execute([$customerId]);
                        $resolvedName = trim((string) ($customerStmt->fetchColumn() ?: ''));
                        if ($resolvedName !== '') $entityName = $resolvedName;
                        break;
                    }
                }
            }
        }
    }

    if ($entityName === '') {
        revenue_receivable_json(['ok' => false, 'error' => 'This entry does not have a customer name.'], 422);
    }

    $balance = max(0, (float) ($entry['amount_total'] ?? 0) - (float) ($entry['total_paid'] ?? 0));
    if ($balance <= 0.009) {
        revenue_receivable_json(['ok' => false, 'error' => 'No unpaid balance remains to send to Receivables.'], 422);
    }

    $existingStmt = $pdo->prepare('SELECT id FROM erp_outstanding_invoices WHERE source_revenue_entry_id = ? LIMIT 1');
    $existingStmt->execute([$entryId]);
    $existingReceivableId = (int) ($existingStmt->fetchColumn() ?: 0);
    $alreadySent = $existingReceivableId > 0;
    if ($alreadySent && $sourceInvoiceId > 0) {
        $updateExisting = $pdo->prepare('UPDATE erp_outstanding_invoices
            SET source_invoice_id = ?, due_date = COALESCE(?, due_date)
            WHERE id = ? AND type = \'receivable\'');
        $updateExisting->execute([$sourceInvoiceId, $dueDate, $existingReceivableId]);
    }
    if (!$alreadySent) {
        $entryDate = trim((string) ($entry['entry_date'] ?? ''));
        $dateObj = DateTime::createFromFormat('!Y-m-d', $entryDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $entryDate) $entryDate = date('Y-m-d');
        $description = trim((string) ($entry['narration'] ?? ''));
        if ($description === '') $description = 'Revenue entry ' . ($invoiceNumber !== '' ? $invoiceNumber : '#' . $entryId);
        $attachment = trim((string) ($entry['attachment'] ?? ''));

        try {
            $insert = $pdo->prepare("INSERT INTO erp_outstanding_invoices
                (type, invoice_date, entity_name, invoice_number, narration, amount, attachment, source_invoice_id, due_date, source_revenue_entry_id)
                VALUES ('receivable', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$entryDate, $entityName, $invoiceNumber !== '' ? $invoiceNumber : null, $description, $balance, $attachment !== '' ? $attachment : null, $sourceInvoiceId > 0 ? $sourceInvoiceId : null, $dueDate, $entryId]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $existingStmt->execute([$entryId]);
            if (!$existingStmt->fetchColumn()) throw $e;
            $alreadySent = true;
        }
    }

    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $redirectUrl = company_url('erp/outstanding-invoices/index.php?module=outstanding&tab=receivables', $slug !== '' ? $slug : null);
    $_SESSION['outstanding_invoice_success'] = $alreadySent
        ? 'This revenue entry is already in Receivables.'
        : 'Revenue entry sent to Receivables.';

    revenue_receivable_json(['ok' => true, 'redirect_url' => $redirectUrl]);
} catch (Throwable $e) {
    error_log('revenue send-to-receivables: ' . $e->getMessage());
    revenue_receivable_json(['ok' => false, 'error' => 'The revenue entry could not be sent to Receivables. Please try again.'], 500);
}