<?php
require_once '../../includes/functions.php';
global $pdo;

// Safe HTML escape helper to avoid deprecation warnings on null
if (!function_exists('h')) {
    function h($v): string {
        if ($v === null) return '';
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// --- Schema upgrade / compatibility layer for erp_expenses ---
// Legacy schema (from erp_complete_schema.sql) had: expense_date, category, amount, description, reference, created_by, created_at
// Current application logic expects: expense_number, date, payee, account_id (FK), amount, payment_method, description, status, created_by, created_at
// This block brings an old table up to date without destroying existing data.
try {
    // Ensure table exists (create with modern schema if missing entirely)
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_number VARCHAR(20) UNIQUE NULL,
        date DATE NULL,
        payee VARCHAR(150) NULL,
        account_id INT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
        description TEXT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (account_id), INDEX (date), INDEX (status),
        FOREIGN KEY (account_id) REFERENCES erp_accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Fetch existing columns
    $colsStmt = $pdo->query("SHOW COLUMNS FROM erp_expenses");
    $existingCols = array_map(fn($r) => $r['Field'], $colsStmt->fetchAll());

    $addColumn = function($definition) use ($pdo) {
        try { $pdo->exec("ALTER TABLE erp_expenses ADD COLUMN $definition"); } catch (Throwable $e) { /* ignore */ }
    };
    // Add missing modern columns if absent
    if (!in_array('expense_number', $existingCols)) $addColumn("expense_number VARCHAR(20) NULL UNIQUE AFTER id");
    // Legacy used expense_date; migrate to date
    $hasExpenseDate = in_array('expense_date', $existingCols);
    $hasDate = in_array('date', $existingCols);
    if (!$hasDate) { $addColumn("date DATE NULL AFTER expense_number"); }
    if ($hasExpenseDate && $hasDate) {
        // Backfill date from expense_date where date IS NULL
        try { $pdo->exec("UPDATE erp_expenses SET date = expense_date WHERE date IS NULL"); } catch (Throwable $e) { /* ignore */ }
    }
    if (!in_array('payee', $existingCols)) $addColumn("payee VARCHAR(150) NULL AFTER date");
    if (!in_array('account_id', $existingCols)) $addColumn("account_id INT NULL AFTER payee");
    if (!in_array('payment_method', $existingCols)) $addColumn("payment_method VARCHAR(30) NOT NULL DEFAULT 'cash' AFTER amount");
    if (!in_array('status', $existingCols)) $addColumn("status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER description");
    if (!in_array('created_by', $existingCols)) $addColumn("created_by INT NULL AFTER status");
    if (!in_array('created_at', $existingCols)) $addColumn("created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
    // Add foreign key for account_id if possible
    try { $pdo->exec("ALTER TABLE erp_expenses ADD CONSTRAINT fk_erp_expenses_account FOREIGN KEY (account_id) REFERENCES erp_accounts(id) ON DELETE SET NULL"); } catch (Throwable $e) { /* ignore */ }
} catch (Throwable $schemaE) {
    // Log but do not block page rendering
    @error_log('erp_expenses schema upgrade failed: ' . $schemaE->getMessage());
}

// Determine which date column to use safely
$dateCol = 'date';
try {
    $probe = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'date'")->fetch();
    if (!$probe) { $dateCol = 'expense_date'; }
} catch (Throwable $e) { $dateCol = 'expense_date'; }

// Build query dynamically depending on account_id presence
$joinAccount = false;
try {
    $probeAcc = $pdo->query("SHOW COLUMNS FROM erp_expenses LIKE 'account_id'")->fetch();
    $joinAccount = (bool)$probeAcc;
} catch (Throwable $e) { $joinAccount = false; }

$sql = "SELECT e.*" . ($joinAccount ? ", a.name AS account_name" : ", e.category AS account_name") .
       " FROM erp_expenses e " . ($joinAccount ? "LEFT JOIN erp_accounts a ON e.account_id = a.id " : "") .
       " ORDER BY e.$dateCol DESC LIMIT 50";

$expenses = [];
try { $expenses = $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { /* fail-soft */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expenses - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>* { margin: 0; padding: 0; box-sizing: border-box; } body { margin: 0; padding: 0; background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; } .header { margin: 0; background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; } .header h1 { font-size: 1.5rem; font-weight: 500; } .container { max-width: 100%; padding: 24px; } .page-wrapper { margin-left: 220px; min-height: 100vh; } @media (max-width: 768px) { .page-wrapper { margin-left: 0; } } .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; } .table { width: 100%; border-collapse: collapse; } .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; } .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; } .table tr:hover { background: #f8f9fa; } .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; } .btn-primary { background: #1a73e8; color: white; } .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }</style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div style="padding: 16px 24px 0; text-align: right;"><div class="header-actions"><a href="../index.php" class="btn btn-secondary">â† Back</a><a href="create-expense.php" class="btn btn-primary">+ Record Expense</a></div></div>
    <div class="container"><div class="card"><table class="table"><thead><tr><th>Date</th><th>Expense #</th><th>Payee</th><th>Category</th><th>Amount</th><th>Payment Method</th></tr></thead><tbody>
    <?php if (empty($expenses)): ?><tr><td colspan="6" style="text-align: center; padding: 32px; color: #5f6368;">No expenses recorded yet.</td></tr><?php else: ?><?php foreach ($expenses as $exp): ?><tr><td><?= isset($exp['date']) && $exp['date'] ? date('M d, Y', strtotime($exp['date'])) : '-' ?></td><td><?= h($exp['expense_number'] ?? '') ?></td><td><?= h($exp['payee'] ?? '-') ?></td><td><?= h($exp['account_name'] ?? '') ?></td><td style="font-weight: 600;">TSh <?= number_format($exp['amount'] ?? 0, 2) ?></td><td><?= h($exp['payment_method'] ?? 'cash') ?></td></tr><?php endforeach; ?><?php endif; ?>
    </tbody></table></div></div>
</div>
</body>
</html>

