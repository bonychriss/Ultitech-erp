<?php
declare(strict_types=1);

/**
 * Sales reports a wrongly issued invoice. An admin approves the reversal
 * so the invoice, revenue voucher, and ledger no longer treat it as a sale.
 */

function salesInvoiceCorrectionsEnsure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_invoice_corrections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        reported_by INT NOT NULL,
        reason VARCHAR(500) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        review_note VARCHAR(500) NULL,
        reported_on DATE NULL DEFAULT NULL,
        attachment VARCHAR(500) NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_sic_invoice (invoice_id),
        KEY idx_sic_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $cols = $pdo->query('SHOW COLUMNS FROM sales_invoice_corrections')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array('reported_on', $cols, true)) {
        $pdo->exec('ALTER TABLE sales_invoice_corrections ADD COLUMN reported_on DATE NULL DEFAULT NULL AFTER reason');
    }
    if (!in_array('attachment', $cols, true)) {
        $pdo->exec('ALTER TABLE sales_invoice_corrections ADD COLUMN attachment VARCHAR(500) NULL DEFAULT NULL AFTER reported_on');
    }
}

function salesInvoiceCorrectionsPendingCount(PDO $pdo): int
{
    salesInvoiceCorrectionsEnsure($pdo);
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM sales_invoice_corrections WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array<int,string> invoice id => pending|approved|rejected
 */
function salesInvoiceCorrectionsStatusMap(PDO $pdo): array
{
    salesInvoiceCorrectionsEnsure($pdo);
    $map = [];
    try {
        $rows = $pdo->query('SELECT invoice_id, status FROM sales_invoice_corrections ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $row) {
        $id = (int) ($row['invoice_id'] ?? 0);
        if ($id > 0 && !isset($map[$id])) {
            $map[$id] = (string) ($row['status'] ?? '');
        }
    }

    return $map;
}

/**
 * @return array<int,array<string,mixed>>
 */
function salesInvoiceCorrectionsList(PDO $pdo, bool $admin, int $userId): array
{
    salesInvoiceCorrectionsEnsure($pdo);
    $sql = "SELECT c.id, c.invoice_id, c.reason, c.reported_on, c.attachment, c.status, c.review_note, c.created_at, c.reviewed_at,
                i.invoice_number, i.invoice_date, i.total_amount, i.amount_paid, i.status AS invoice_status,
                cu.company_name AS customer_name,
                ru.full_name AS reported_by_name,
                vu.full_name AS reviewed_by_name
            FROM sales_invoice_corrections c
            JOIN invoices i ON i.id = c.invoice_id
            LEFT JOIN customers cu ON cu.id = i.customer_id
            LEFT JOIN users ru ON ru.id = c.reported_by
            LEFT JOIN users vu ON vu.id = c.reviewed_by";
    $params = [];
    if (!$admin) {
        $sql .= ' WHERE c.reported_by = ?';
        $params[] = $userId;
    }
    $sql .= " ORDER BY FIELD(c.status, 'pending', 'approved', 'rejected'), c.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_id' => (int) ($row['invoice_id'] ?? 0),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'amount_paid' => (float) ($row['amount_paid'] ?? 0),
            'invoice_status' => (string) ($row['invoice_status'] ?? ''),
            'reason' => (string) ($row['reason'] ?? ''),
            'reported_on' => (string) ($row['reported_on'] ?? ''),
            'attachment' => salesInvoiceCorrectionsAttachmentUrl((string) ($row['attachment'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'review_note' => (string) ($row['review_note'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
            'reported_by_name' => (string) ($row['reported_by_name'] ?? ''),
            'reviewed_by_name' => (string) ($row['reviewed_by_name'] ?? ''),
        ];
    }

    return $rows;
}

function salesInvoiceCorrectionsAttachmentUrl(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    if (!str_starts_with($path, 'uploads/sales/invoice-corrections/')) {
        return '';
    }

    return function_exists('app_url') ? app_url('/' . $path) : '/' . $path;
}

/**
 * @param array<string,mixed>|null $file
 */
function salesInvoiceCorrectionsStoreAttachment(?array $file): string
{
    if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed. Please try again.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Attachment must be 5MB or smaller.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Attachment must be PDF, JPG, PNG, or WEBP.');
    }
    $root = dirname(__DIR__, 3);
    $targetDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'invoice-corrections' . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create the attachment folder.');
    }
    $fileName = 'INV-WRONG_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . $fileName;
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? '')) || !move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save the attachment.');
    }

    return 'uploads/sales/invoice-corrections/' . $fileName;
}

function salesInvoiceCorrectionsReport(PDO $pdo, int $invoiceId, int $userId, string $reason, string $reportedOn = '', string $attachment = ''): array
{
    salesInvoiceCorrectionsEnsure($pdo);
    $reason = trim($reason);
    if ($invoiceId < 1) {
        return ['ok' => false, 'message' => 'Choose an invoice.'];
    }
    if ($reason === '') {
        return ['ok' => false, 'message' => 'Say what is wrong with the invoice.'];
    }
    $reportedOn = trim($reportedOn);
    if ($reportedOn === '') {
        $reportedOn = date('Y-m-d');
    }
    $parsed = DateTime::createFromFormat('Y-m-d', $reportedOn);
    if (!$parsed || $parsed->format('Y-m-d') !== $reportedOn) {
        return ['ok' => false, 'message' => 'Enter a valid date.'];
    }
    if (strlen($reason) > 500) {
        $reason = substr($reason, 0, 500);
    }

    $st = $pdo->prepare('SELECT id, invoice_number, status FROM invoices WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return ['ok' => false, 'message' => 'Invoice not found.'];
    }
    $status = strtolower(trim((string) ($invoice['status'] ?? '')));
    if (in_array($status, ['cancelled', 'canceled'], true)) {
        return ['ok' => false, 'message' => 'This invoice is already cancelled.'];
    }

    $open = $pdo->prepare("SELECT id FROM sales_invoice_corrections WHERE invoice_id = ? AND status = 'pending' LIMIT 1");
    $open->execute([$invoiceId]);
    if ($open->fetchColumn()) {
        return ['ok' => false, 'message' => 'This invoice is already waiting for an admin.'];
    }

    $pdo->prepare('INSERT INTO sales_invoice_corrections (invoice_id, reported_by, reason, reported_on, attachment) VALUES (?, ?, ?, ?, ?)')
        ->execute([$invoiceId, $userId, $reason, $reportedOn, $attachment !== '' ? $attachment : null]);

    try {
        salesInvoiceCorrectionsNotify($pdo, $invoiceId, $userId);
    } catch (Throwable $e) {
    }

    return [
        'ok' => true,
        'message' => 'Reported. An admin will approve the reversal.',
        'id' => (int) $pdo->lastInsertId(),
    ];
}

function salesInvoiceCorrectionsNoticeLink(): string
{
    $link = function_exists('sales_module_url')
        ? sales_module_url('invoices/corrections.php', ['module' => 'sales'])
        : 'modules/sales/invoices/corrections.php?module=sales';
    if (strlen($link) > 255) {
        return 'sales/wrong-invoices?module=sales';
    }

    return $link;
}

function salesInvoiceCorrectionsPushNotice(PDO $pdo, int $userId, string $title, string $message, string $type = 'info'): void
{
    if ($userId < 1) {
        return;
    }
    $link = salesInvoiceCorrectionsNoticeLink();
    $sent = function_exists('createSystemNotification')
        && createSystemNotification($userId, $title, $message, $link, $type);
    if ($sent) {
        return;
    }
    $db = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : $pdo;
    try {
        if (function_exists('ensureNotificationsTable')) {
            ensureNotificationsTable();
        }
        $db->prepare('INSERT INTO system_notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $title, $message, $link, $type]);
    } catch (Throwable $e) {
    }
}

/**
 * Tell admins a reversal is waiting. Tell finance when money was already received.
 */
function salesInvoiceCorrectionsNotify(PDO $pdo, int $invoiceId, int $reporterId): void
{
    $st = $pdo->prepare('SELECT invoice_number, amount_paid FROM invoices WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return;
    }

    $number = trim((string) ($invoice['invoice_number'] ?? ''));
    if ($number === '') {
        $number = '#' . $invoiceId;
    }
    $paid = (float) ($invoice['amount_paid'] ?? 0) > 0.009;

    $userDb = $pdo;
    $appDb = $GLOBALS['pdo'] ?? null;
    if ($appDb instanceof PDO) {
        $userDb = $appDb;
    }

    $cols = [];
    try {
        $cols = $userDb->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $cols = [];
    }
    if ($cols === []) {
        return;
    }

    $select = ['id', 'role'];
    if (in_array('department', $cols, true)) {
        $select[] = 'department';
    }
    if (in_array('extra_roles', $cols, true)) {
        $select[] = 'extra_roles';
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM users';
    if (in_array('is_active', $cols, true)) {
        $sql .= ' WHERE is_active = 1 OR is_active IS NULL';
    }
    try {
        $people = $userDb->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }

    $adminRoles = ['admin', 'administrator', 'company_admin', 'company admin', 'owner'];
    $recipients = [];
    foreach ($people as $person) {
        $id = (int) ($person['id'] ?? 0);
        if ($id < 1 || $id === $reporterId) {
            continue;
        }
        $role = strtolower(trim((string) ($person['role'] ?? '')));
        $dept = strtolower((string) ($person['department'] ?? ''));
        $extra = strtolower((string) ($person['extra_roles'] ?? ''));
        $isAdminRole = in_array($role, $adminRoles, true);
        $isFinance = preg_match('/\b(finance|account|accounts|accounting)\b/', $dept) === 1
            || str_contains($extra, 'finance');
        if ($isAdminRole) {
            $recipients[$id] = 'admin';
        } elseif ($paid && $isFinance) {
            $recipients[$id] = 'finance';
        }
    }
    if ($recipients === []) {
        return;
    }

    foreach ($recipients as $id => $kind) {
        if ($kind === 'finance') {
            $title = 'Wrong invoice needs a collections review';
            $message = $number . ' was reported as wrongly issued, and money has already been received. Review that collection.';
        } else {
            $title = 'Wrong invoice to reverse';
            $message = $number . ' was reported as wrongly issued. Approve and reverse it, or reject the report.';
        }
        salesInvoiceCorrectionsPushNotice($pdo, (int) $id, $title, $message, 'warning');
    }
}

/**
 * Tell the salesperson the decision. Tell finance when money was already received.
 */
function salesInvoiceCorrectionsNotifyDecision(PDO $pdo, int $invoiceId, int $reporterId, int $actorId, string $decision, string $note = '', string $stockNote = '', string $detail = ''): void
{
    $st = $pdo->prepare('SELECT invoice_number, amount_paid FROM invoices WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return;
    }
    $number = trim((string) ($invoice['invoice_number'] ?? ''));
    if ($number === '') {
        $number = '#' . $invoiceId;
    }
    $paid = (float) ($invoice['amount_paid'] ?? 0) > 0.009;
    $approved = $decision === 'approve';
    $note = trim($note);

    $detail = trim($detail);
    if ($approved && $detail !== '') {
        $hearers = [];
        if ($reporterId > 0) {
            $hearers[$reporterId] = true;
        }
        if ($actorId > 0) {
            $hearers[$actorId] = true;
        }
        foreach (array_keys($hearers) as $hearerId) {
            salesInvoiceCorrectionsPushNotice($pdo, (int) $hearerId, 'Wrong invoice reversed', $detail, 'success');
        }
    } elseif ($reporterId > 0 && $reporterId !== $actorId) {
        if ($approved) {
            $message = 'Your report on ' . $number . ' was approved. The invoice is cancelled.';
            if ($paid) {
                $message .= ' Money already received still needs a collections review.';
            }
            if ($stockNote !== '') {
                $message .= ' ' . $stockNote;
            }
            salesInvoiceCorrectionsPushNotice($pdo, $reporterId, 'Wrong invoice reversed', $message, 'success');
        } else {
            $message = 'Your report on ' . $number . ' was rejected. The invoice stays as it is.';
            if ($note !== '') {
                $message .= ' ' . $note;
            }
            salesInvoiceCorrectionsPushNotice($pdo, $reporterId, 'Wrong invoice report rejected', $message, 'info');
        }
    }

    if ($approved && ($detail !== '' || $stockNote !== '')) {
        salesInvoiceCorrectionsNotifyStock(
            $pdo,
            $actorId,
            $reporterId,
            'Wrong invoice reversed',
            $detail !== '' ? $detail : $stockNote
        );
    }

    if (!$paid) {
        return;
    }

    $userDb = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : $pdo;
    try {
        $cols = $userDb->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    if (!in_array('department', $cols, true) && !in_array('extra_roles', $cols, true)) {
        return;
    }
    $select = ['id'];
    if (in_array('department', $cols, true)) {
        $select[] = 'department';
    }
    if (in_array('extra_roles', $cols, true)) {
        $select[] = 'extra_roles';
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM users';
    if (in_array('is_active', $cols, true)) {
        $sql .= ' WHERE is_active = 1 OR is_active IS NULL';
    }
    try {
        $people = $userDb->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }
    foreach ($people as $person) {
        $id = (int) ($person['id'] ?? 0);
        if ($id < 1 || $id === $actorId || $id === $reporterId) {
            continue;
        }
        $dept = strtolower((string) ($person['department'] ?? ''));
        $extra = strtolower((string) ($person['extra_roles'] ?? ''));
        $isFinance = preg_match('/\b(finance|account|accounts|accounting)\b/', $dept) === 1
            || str_contains($extra, 'finance');
        if (!$isFinance) {
            continue;
        }
        if ($approved) {
            salesInvoiceCorrectionsPushNotice(
                $pdo,
                $id,
                'Reversed invoice needs a collections review',
                $number . ' was cancelled. Review the money already received.',
                'warning'
            );
        } else {
            salesInvoiceCorrectionsPushNotice(
                $pdo,
                $id,
                'Wrong invoice report rejected',
                'The report on ' . $number . ' was rejected. No collections review is needed.',
                'info'
            );
        }
    }
}

function salesInvoiceCorrectionsReject(PDO $pdo, int $reportId, int $adminId, string $note): array
{
    salesInvoiceCorrectionsEnsure($pdo);
    $note = trim($note);
    if (strlen($note) > 500) {
        $note = substr($note, 0, 500);
    }
    $st = $pdo->prepare("SELECT id, invoice_id, reported_by FROM sales_invoice_corrections WHERE id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$reportId]);
    $report = $st->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        return ['ok' => false, 'message' => 'That report is no longer waiting.'];
    }
    $pdo->prepare("UPDATE sales_invoice_corrections SET status = 'rejected', reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([$adminId, $note !== '' ? $note : null, $reportId]);

    try {
        salesInvoiceCorrectionsNotifyDecision(
            $pdo,
            (int) $report['invoice_id'],
            (int) $report['reported_by'],
            $adminId,
            'reject',
            $note
        );
    } catch (Throwable $e) {
    }

    return ['ok' => true, 'message' => 'Report rejected. The invoice stays as it is.'];
}

function salesInvoiceCorrectionsApprove(PDO $pdo, int $reportId, int $adminId): array
{
    salesInvoiceCorrectionsEnsure($pdo);
    $st = $pdo->prepare("SELECT * FROM sales_invoice_corrections WHERE id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$reportId]);
    $report = $st->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        return ['ok' => false, 'message' => 'That report is no longer waiting.'];
    }

    $reversed = salesInvoiceReverseWrong($pdo, (int) $report['invoice_id'], (string) $report['reason']);
    if (empty($reversed['ok'])) {
        return $reversed;
    }

    $pdo->prepare("UPDATE sales_invoice_corrections SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
        ->execute([$adminId, $reportId]);

    try {
        salesInvoiceCorrectionsNotifyDecision(
            $pdo,
            (int) $report['invoice_id'],
            (int) $report['reported_by'],
            $adminId,
            'approve',
            '',
            (string) ($reversed['stock_note'] ?? ''),
            (string) ($reversed['message'] ?? '')
        );
    } catch (Throwable $e) {
    }

    return [
        'ok' => true,
        'message' => (string) ($reversed['message'] ?? 'Invoice reversed.'),
    ];
}

/**
 * Cancel the invoice and reverse its revenue and ledger posting. The row stays
 * so the mistake remains visible.
 *
 * @return array{ok:bool,message:string}
 */
function salesInvoiceReverseWrong(PDO $pdo, int $invoiceId, string $reason): array
{
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $st->execute([$invoiceId]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return ['ok' => false, 'message' => 'Invoice not found.'];
    }
    $status = strtolower(trim((string) ($invoice['status'] ?? '')));
    if (!in_array($status, ['cancelled', 'canceled'], true)) {
        $note = trim((string) ($invoice['notes'] ?? ''));
        $line = 'Reversed after a wrong-invoice report' . ($reason !== '' ? ': ' . $reason : '.');
        if ($note !== '' && stripos($note, 'Reversed after a wrong-invoice report') === false) {
            $note .= "\n" . $line;
        } elseif ($note === '') {
            $note = $line;
        }
        $pdo->prepare("UPDATE invoices SET status = 'cancelled', balance_due = 0, notes = ? WHERE id = ?")
            ->execute([$note, $invoiceId]);
    }

    $revenueFile = dirname(__DIR__, 3) . '/includes/revenue_sync.php';
    if (is_file($revenueFile)) {
        require_once $revenueFile;
        if (function_exists('syncInvoiceToRevenue')) {
            syncInvoiceToRevenue($pdo, $invoiceId);
        }
    }

    $glNote = salesInvoiceReverseLedger($pdo, $invoice);
    $stock = salesInvoiceRestoreStock($pdo, $invoice);
    $number = trim((string) ($invoice['invoice_number'] ?? ('#' . $invoiceId)));
    $message = $number . ' is cancelled and taken out of sales and receivables.';
    if ($glNote !== '') {
        $message .= ' ' . $glNote;
    }
    if ($stock['note'] !== '') {
        $message .= ' ' . $stock['note'];
    }
    if ((float) ($invoice['amount_paid'] ?? 0) > 0.009) {
        $message .= ' Money already received is still on the voided revenue voucher and needs a collections review.';
    }

    return ['ok' => true, 'message' => $message, 'stock_note' => $stock['note']];
}

function salesInvoiceQtyText(float $qty): string
{
    $qty = round($qty, 2);
    if (abs($qty - (int) round($qty)) < 0.001) {
        return (string) (int) round($qty);
    }

    return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}

/**
 * Put invoice line quantities back into stock and record the before and after amounts.
 *
 * @return array{note:string}
 */
function salesInvoiceRestoreStock(PDO $pdo, array $invoice): array
{
    $empty = ['note' => ''];
    $orderId = (int) ($invoice['order_id'] ?? 0);
    $invoiceId = (int) ($invoice['id'] ?? 0);
    if ($orderId < 1 || $invoiceId < 1) {
        return $empty;
    }
    $number = trim((string) ($invoice['invoice_number'] ?? ''));
    if ($number === '') {
        $number = '#' . $invoiceId;
    }
    $marker = 'Wrong invoice ' . $number . ' reversed.';

    try {
        $already = $pdo->prepare('SELECT id FROM stock_movements WHERE notes LIKE ? LIMIT 1');
        $already->execute([$marker . '%']);
        if ($already->fetchColumn()) {
            return ['note' => 'Stock was already returned for this invoice.'];
        }
    } catch (Throwable $e) {
        return $empty;
    }

    try {
        $items = $pdo->prepare(
            'SELECT soi.product_id, soi.quantity, p.name
             FROM sales_order_items soi
             LEFT JOIN products p ON p.id = soi.product_id
             WHERE soi.order_id = ?'
        );
        $items->execute([$orderId]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }

    $lines = [];
    foreach ($rows as $row) {
        $productId = (int) ($row['product_id'] ?? 0);
        $qty = (float) ($row['quantity'] ?? 0);
        if ($productId < 1 || $qty <= 0) {
            continue;
        }
        if (!isset($lines[$productId])) {
            $name = trim((string) ($row['name'] ?? ''));
            $lines[$productId] = [
                'name' => $name !== '' ? $name : ('Product #' . $productId),
                'quantity' => 0.0,
            ];
        }
        $lines[$productId]['quantity'] += $qty;
    }
    if ($lines === []) {
        return $empty;
    }

    $mvCols = [];
    try {
        $mvCols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $empty;
    }
    $mvNames = array_map(static fn (array $col): string => (string) ($col['Field'] ?? ''), $mvCols);
    if (!in_array('qty_before', $mvNames, true)) {
        try {
            $pdo->exec('ALTER TABLE stock_movements ADD COLUMN qty_before DECIMAL(12,2) NULL');
            $mvNames[] = 'qty_before';
        } catch (Throwable $e) {
        }
    }
    if (!in_array('qty_after', $mvNames, true)) {
        try {
            $pdo->exec('ALTER TABLE stock_movements ADD COLUMN qty_after DECIMAL(12,2) NULL');
            $mvNames[] = 'qty_after';
        } catch (Throwable $e) {
        }
    }

    $refType = 'invoice_reversal';
    foreach ($mvCols as $col) {
        if ((string) ($col['Field'] ?? '') !== 'reference_type') {
            continue;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        if (str_starts_with($type, 'enum(') && !str_contains($type, 'invoice_reversal')) {
            $refType = str_contains($type, 'adjustment') ? 'adjustment' : 'sale';
        }
    }

    $summaries = [];
    foreach ($lines as $productId => $line) {
        try {
            $stockSt = $pdo->prepare('SELECT id, quantity FROM stock WHERE product_id = ? LIMIT 1');
            $stockSt->execute([$productId]);
            $stock = $stockSt->fetch(PDO::FETCH_ASSOC);
            $before = $stock ? (float) $stock['quantity'] : 0.0;
            $after = $before + (float) $line['quantity'];
            $stockId = $stock ? (int) $stock['id'] : 0;
            $stockCols = $pdo->query('SHOW COLUMNS FROM stock')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($stock) {
                if (in_array('last_updated', $stockCols, true)) {
                    $pdo->prepare('UPDATE stock SET quantity = ?, last_updated = NOW() WHERE id = ?')
                        ->execute([$after, (int) $stock['id']]);
                } else {
                    $pdo->prepare('UPDATE stock SET quantity = ? WHERE id = ?')
                        ->execute([$after, (int) $stock['id']]);
                }
            } elseif (in_array('location', $stockCols, true) && in_array('last_updated', $stockCols, true)) {
                $pdo->prepare("INSERT INTO stock (product_id, quantity, location, last_updated) VALUES (?, ?, 'Warehouse A', NOW())")
                    ->execute([$productId, $after]);
            } else {
                $pdo->prepare('INSERT INTO stock (product_id, quantity) VALUES (?, ?)')
                    ->execute([$productId, $after]);
            }

            $beforeText = salesInvoiceQtyText($before);
            $afterText = salesInvoiceQtyText($after);
            $note = $marker . ' Quantity before ' . $beforeText . ', after ' . $afterText . '.';
            $fields = ['product_id', 'movement_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_at'];
            $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()'];
            $values = [$productId, 'in', (float) $line['quantity'], $refType, (string) $invoiceId, $note];
            if (in_array('qty_before', $mvNames, true)) {
                $fields[] = 'qty_before';
                $placeholders[] = '?';
                $values[] = $before;
            }
            if (in_array('qty_after', $mvNames, true)) {
                $fields[] = 'qty_after';
                $placeholders[] = '?';
                $values[] = $after;
            }
            $quoted = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', $fields));
            try {
                $pdo->prepare('INSERT INTO stock_movements (' . $quoted . ') VALUES (' . implode(', ', $placeholders) . ')')
                    ->execute($values);
            } catch (Throwable $moveError) {
                if ($stockId > 0) {
                    $pdo->prepare('UPDATE stock SET quantity = ? WHERE id = ?')->execute([$before, $stockId]);
                }
                throw $moveError;
            }
            $summaries[] = $line['name'] . ' ' . salesInvoiceQtyText((float) $line['quantity'])
                . ' (before ' . $beforeText . ', after ' . $afterText . ')';
        } catch (Throwable $e) {
            error_log('salesInvoiceRestoreStock: ' . $e->getMessage());
        }
    }

    if ($summaries === []) {
        return ['note' => 'Stock could not be returned.'];
    }

    return ['note' => 'Stock returned: ' . implode('; ', $summaries) . '.'];
}

function salesInvoiceCorrectionsNotifyStock(PDO $pdo, int $actorId, int $reporterId, string $title, string $message): void
{
    $userDb = ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : $pdo;
    try {
        $cols = $userDb->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    if (!in_array('department', $cols, true) && !in_array('extra_roles', $cols, true)) {
        return;
    }
    $select = ['id'];
    if (in_array('department', $cols, true)) {
        $select[] = 'department';
    }
    if (in_array('extra_roles', $cols, true)) {
        $select[] = 'extra_roles';
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM users';
    if (in_array('is_active', $cols, true)) {
        $sql .= ' WHERE is_active = 1 OR is_active IS NULL';
    }
    try {
        $people = $userDb->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }
    foreach ($people as $person) {
        $id = (int) ($person['id'] ?? 0);
        if ($id < 1 || $id === $actorId || $id === $reporterId) {
            continue;
        }
        $dept = strtolower((string) ($person['department'] ?? ''));
        $extra = strtolower((string) ($person['extra_roles'] ?? ''));
        $isStock = preg_match('/\b(stock|store|warehouse|inventory)\b/', $dept) === 1
            || str_contains($extra, 'stock')
            || str_contains($extra, 'store');
        if (!$isStock) {
            continue;
        }
        salesInvoiceCorrectionsPushNotice($pdo, $id, $title, $message, 'info');
    }
}

function salesInvoiceReverseLedger(PDO $pdo, array $invoice): string
{
    $invoiceId = (int) ($invoice['id'] ?? 0);
    if ($invoiceId < 1) {
        return '';
    }
    $glFile = dirname(__DIR__, 3) . '/includes/invoice_gl_posting.php';
    if (!is_file($glFile)) {
        return '';
    }
    require_once $glFile;
    if (!function_exists('invoice_gl_tables_ready') || !invoice_gl_tables_ready($pdo)) {
        return '';
    }
    if (!function_exists('invoice_gl_journal_reference_exists') || !invoice_gl_journal_reference_exists($pdo, 'INV-REC-' . $invoiceId)) {
        return '';
    }
    if (invoice_gl_journal_reference_exists($pdo, 'INV-REV-' . $invoiceId)) {
        return 'The ledger reversal was already posted.';
    }

    try {
        $st = $pdo->prepare('SELECT id, date FROM erp_journal_entries WHERE reference = ? LIMIT 1');
        $st->execute(['INV-REC-' . $invoiceId]);
        $entry = $st->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            return '';
        }
        $accountCol = function_exists('resolveExistingColumn')
            ? resolveExistingColumn('erp_journal_items', 'account_id', ['gl_account_id', 'account'])
            : 'account_id';
        if (!$accountCol) {
            return '';
        }
        $itemsSt = $pdo->prepare("SELECT {$accountCol} AS account_id, debit, credit FROM erp_journal_items WHERE journal_id = ?");
        $itemsSt->execute([(int) $entry['id']]);
        $items = [];
        foreach ($itemsSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            $accountId = (int) ($item['account_id'] ?? 0);
            if ($accountId < 1) {
                continue;
            }
            $items[] = [
                'account_id' => $accountId,
                'debit' => round((float) ($item['credit'] ?? 0), 2),
                'credit' => round((float) ($item['debit'] ?? 0), 2),
            ];
        }
        if (!$items || !function_exists('invoice_gl_post_balanced_entry')) {
            return '';
        }
        $number = trim((string) ($invoice['invoice_number'] ?? ('#' . $invoiceId)));
        invoice_gl_post_balanced_entry(
            $pdo,
            date('Y-m-d'),
            'INV-REV-' . $invoiceId,
            'Reverse wrong invoice ' . $number,
            $items
        );

        return 'A reversing journal was posted.';
    } catch (Throwable $e) {
        error_log('salesInvoiceReverseLedger: ' . $e->getMessage());

        return 'The invoice is cancelled. The ledger reversal could not be posted.';
    }
}
