<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

requireLogin();
if (!isFinance() && !isAdmin()) {
    header('Location: select-module.php?error=access_denied');
    exit();
}
$_SESSION['active_module'] = 'revenue';

// If entry id is explicitly provided, honor it.
$requestedId = (int) ($_GET['id'] ?? 0);
if ($requestedId > 0) {
    header('Location: revenue_record_payment.php?id=' . $requestedId . '&module=revenue');
    exit();
}

// Prefer an entry with outstanding balance, newest first.
try {
    $stmt = $pdo->query("
        SELECT id
        FROM revenue_entries
        WHERE COALESCE(amount_total, 0) - COALESCE(total_paid, 0) > 0.009
        ORDER BY COALESCE(entry_date, created_at) DESC, id DESC
        LIMIT 1
    ");
    $entryId = (int) ($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $entryId = 0;
}

if ($entryId > 0) {
    header('Location: revenue_record_payment.php?id=' . $entryId . '&module=revenue');
    exit();
}

// Fallback: open latest revenue entry even if fully paid.
try {
    $stmtAny = $pdo->query("SELECT id FROM revenue_entries ORDER BY COALESCE(entry_date, created_at) DESC, id DESC LIMIT 1");
    $fallbackId = (int) ($stmtAny->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $fallbackId = 0;
}

if ($fallbackId > 0) {
    header('Location: revenue_record_payment.php?id=' . $fallbackId . '&module=revenue');
    exit();
}

// No entries at all.
header('Location: revenue_create.php?module=revenue&info=create_revenue_first');
exit();

