<?php
/**
 * Sales notification alerts (cron-safe):
 * - Quotation due dates (due soon / overdue)
 * - Unpaid invoices (overdue by due_date)
 *
 * Writes into `system_notifications` with Sales links so the Sales header bell can filter them.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

global $pdo;

// Create dedupe log table (per user + entity + day + kind)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales_notification_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            kind VARCHAR(40) NOT NULL,
            entity_type VARCHAR(20) NOT NULL,
            entity_id INT NOT NULL,
            alert_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sales_notif (user_id, kind, entity_type, entity_id, alert_date),
            INDEX idx_sales_notif_date (alert_date),
            CONSTRAINT fk_sales_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // ignore (alerts will still attempt best-effort without dedupe)
}

function sales_admin_user_ids(): array
{
    global $pdo;
    try {
        $st = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        return array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
    } catch (Throwable $e) {
        return [];
    }
}

function sales_notify_once(int $userId, string $kind, string $entityType, int $entityId, string $title, string $message, string $link, string $type = 'warning'): bool
{
    global $pdo;
    $today = date('Y-m-d');

    // Dedupe check
    try {
        $st = $pdo->prepare("SELECT 1 FROM sales_notification_log WHERE user_id = ? AND kind = ? AND entity_type = ? AND entity_id = ? AND alert_date = ? LIMIT 1");
        $st->execute([$userId, $kind, $entityType, $entityId, $today]);
        if ($st->fetchColumn()) {
            return false;
        }
    } catch (Throwable $e) {
        // if log fails, continue best-effort
    }

    // Insert system notification
    try {
        ensureNotificationsTable(); // system_notifications
        $stN = $pdo->prepare("INSERT INTO system_notifications (user_id, title, message, link, type) VALUES (?, ?, ?, ?, ?)");
        $stN->execute([$userId, $title, $message, $link, $type]);
    } catch (Throwable $e) {
        return false;
    }

    // Log send
    try {
        $stI = $pdo->prepare("INSERT INTO sales_notification_log (user_id, kind, entity_type, entity_id, alert_date) VALUES (?, ?, ?, ?, ?)");
        $stI->execute([$userId, $kind, $entityType, $entityId, $today]);
    } catch (Throwable $e) {
        // ignore
    }

    return true;
}

$daysAhead = isset($_GET['quote_due_days']) ? max(0, (int)$_GET['quote_due_days']) : 3;

$adminIds = sales_admin_user_ids();
$sent = [
    'quote_due_soon' => 0,
    'quote_overdue' => 0,
    'invoice_overdue' => 0,
];

// 1) Quotation due dates (sales_orders.valid_until)
try {
    $stQ = $pdo->prepare("
        SELECT so.id, so.order_number, so.valid_until, so.created_by, c.company_name
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.id
        WHERE so.status = 'quotation'
          AND so.valid_until IS NOT NULL
          AND so.valid_until <> '0000-00-00'
          AND DATE(so.valid_until) <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ORDER BY so.valid_until ASC, so.id ASC
        LIMIT 300
    ");
    $stQ->execute([$daysAhead]);
    $quotes = $stQ->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($quotes as $q) {
        $qid = (int)($q['id'] ?? 0);
        if ($qid <= 0) continue;

        $validUntil = (string)($q['valid_until'] ?? '');
        $dueTs = $validUntil !== '' ? strtotime($validUntil) : false;
        if (!$dueTs) continue;

        $isOverdue = ($dueTs < strtotime(date('Y-m-d')));
        $kind = $isOverdue ? 'quote_overdue' : 'quote_due_soon';
        $orderNo = (string)($q['order_number'] ?? ('SO#' . $qid));
        $cust = trim((string)($q['company_name'] ?? ''));

        $title = $isOverdue ? 'Quotation overdue' : 'Quotation due soon';
        $message = $orderNo
            . ($cust !== '' ? (" - " . $cust) : '')
            . ' (Valid until: ' . date('Y-m-d', $dueTs) . ')';

        $link = app_url('/modules/sales/orders/view.php?id=' . $qid . '&module=sales');
        $type = $isOverdue ? 'danger' : 'warning';

        // Notify the responsible user first; fall back to admins only if unknown.
        $targets = [];
        $owner = (int)($q['created_by'] ?? 0);
        if ($owner > 0) {
            $targets[] = $owner;
        } else {
            $targets = array_values(array_unique(array_merge($targets, $adminIds)));
        }

        foreach ($targets as $uid) {
            if (sales_notify_once((int)$uid, $kind, 'sales_order', $qid, $title, $message, $link, $type)) {
                $sent[$kind]++;
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

// 2) Unpaid invoices (invoices.due_date passed and not paid/cancelled)
try {
    $stI = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.due_date, i.status,
               c.company_name,
               COALESCE(i.created_by, so.created_by) AS owner_id
        FROM invoices i
        LEFT JOIN sales_orders so ON i.order_id = so.id
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.status NOT IN ('paid','cancelled')
          AND i.due_date IS NOT NULL
          AND i.due_date <> '0000-00-00'
          AND DATE(i.due_date) < CURDATE()
        ORDER BY i.due_date ASC, i.id ASC
        LIMIT 400
    ");
    $stI->execute();
    $invs = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($invs as $inv) {
        $iid = (int)($inv['id'] ?? 0);
        if ($iid <= 0) continue;

        $invNo = (string)($inv['invoice_number'] ?? ('INV#' . $iid));
        $cust = trim((string)($inv['company_name'] ?? ''));
        $due = (string)($inv['due_date'] ?? '');
        $dueTs = $due !== '' ? strtotime($due) : false;
        if (!$dueTs) continue;

        $title = 'Unpaid invoice overdue';
        $message = $invNo
            . ($cust !== '' ? (" - " . $cust) : '')
            . ' (Due: ' . date('Y-m-d', $dueTs) . ')';

        $link = app_url('/modules/sales/invoices/view.php?id=' . $iid . '&module=sales');
        // Notify the responsible user first; fall back to admins only if unknown.
        $targets = [];
        $owner = (int)($inv['owner_id'] ?? 0);
        if ($owner > 0) {
            $targets[] = $owner;
        } else {
            $targets = array_values(array_unique(array_merge($targets, $adminIds)));
        }

        foreach ($targets as $uid) {
            if (sales_notify_once((int)$uid, 'invoice_overdue', 'invoice', $iid, $title, $message, $link, 'danger')) {
                $sent['invoice_overdue']++;
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'quote_due_days' => $daysAhead,
    'sent' => $sent,
], JSON_PRETTY_PRINT);

