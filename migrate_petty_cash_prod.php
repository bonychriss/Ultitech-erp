<?php
/**
 * Dynamic Production Migration Script
 * This script runs the migration logic on the remote server dynamically.
 */

// Basic authentication or key check for security
if (($_GET['key'] ?? '') !== 'ultimate_secret_key_2026') {
    die('Unauthorized');
}

require_once __DIR__ . '/includes/functions.php';
global $pdo;

echo "<pre>";
echo "Starting production migration...\n";

// 1. Back up all petty cash tables
$tables = ['petty_cash_vouchers', 'petty_cash_replenishments', 'petty_cash_balance', 'petty_cash_categories'];
foreach ($tables as $t) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$t]);
        if (!$stmt->fetchColumn()) {
            echo "Table $t does not exist, skipping backup.\n";
            continue;
        }
        echo "Backing up table $t...\n";
        $pdo->exec("DROP TABLE IF EXISTS `backup_$t`");
        $pdo->exec("CREATE TABLE `backup_$t` AS SELECT * FROM `$t`");
        $cnt = $pdo->query("SELECT COUNT(*) FROM `backup_$t`")->fetchColumn();
        echo "Backup table `backup_$t` created with $cnt records.\n";
    } catch (Exception $e) {
        echo "Error backing up $t: " . $e->getMessage() . "\n";
    }
}

// 2. Map and Migrate Vouchers to erp_expenses dynamically
try {
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'backup_petty_cash_vouchers'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        throw new Exception("backup_petty_cash_vouchers table does not exist. Cannot migrate.");
    }
    
    $vouchers = $pdo->query("SELECT * FROM backup_petty_cash_vouchers")->fetchAll(PDO::FETCH_ASSOC);
    echo "Fetched " . count($vouchers) . " vouchers from backup.\n";

    // Clear previous migrated records to avoid duplicates
    $pdo->exec("DELETE FROM erp_expenses WHERE expense_number LIKE 'PCV%' OR expense_number LIKE 'PC-%'");
    echo "Cleared existing erp_expenses matching PCV/PC patterns.\n";

    $users = $pdo->query("SELECT id, full_name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Dynamic schema checks for erp_expenses
    $stmt = $pdo->query("DESCRIBE erp_expenses");
    $all_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Resolve cash account ID
    $cash_account_id = null;
    $cash_stmt = $pdo->query("SELECT id FROM financial_accounts WHERE name LIKE '%cash%' OR name LIKE '%Cash%' OR type = 'cash' LIMIT 1");
    if ($cash_stmt) {
        $cash_account_id = (int)$cash_stmt->fetchColumn();
    }
    if (!$cash_account_id) {
        $cash_account_id = 89; // Fallback
    }
    echo "Resolved payment cash account ID: $cash_account_id\n";

    // Resolve expense accounts
    $expense_accounts = [];
    if (function_exists('expenses_fetch_expense_sub_accounts')) {
        try {
            $expense_accounts = expenses_fetch_expense_sub_accounts($pdo);
        } catch (Throwable $e) {}
    }
    if (empty($expense_accounts)) {
        try {
            $expense_accounts = $pdo->query("SELECT id, name FROM erp_accounts WHERE type = 'expense'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    $transport_account_id = null;
    $general_expense_account_id = null;
    foreach ($expense_accounts as $acc) {
        $acc_id = (int)($acc['id'] ?? 0);
        $acc_name = strtolower($acc['name'] ?? $acc['label'] ?? '');
        if (!$general_expense_account_id) {
            $general_expense_account_id = $acc_id;
        }
        if (strpos($acc_name, 'transport') !== false || strpos($acc_name, 'travel') !== false || strpos($acc_name, 'fuel') !== false) {
            $transport_account_id = $acc_id;
        }
    }
    if (!$transport_account_id) {
        $transport_account_id = $general_expense_account_id;
    }
    echo "Resolved general expense account ID: $general_expense_account_id, transport expense account ID: $transport_account_id\n";

    $migrated = 0;
    foreach ($vouchers as $v) {
        $status_map = [
            'pending' => 'pending',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'cancelled' => 'rejected',
        ];
        $status = $status_map[$v['status']] ?? 'pending';

        $row_data = [
            'expense_number' => $v['voucher_number'],
            'date' => $v['date'],
            'payee' => $users[(int)$v['custodian_id']] ?? 'Petty Cash Custodian',
            'amount' => (float)$v['amount'],
            'description' => trim($v['description']) ?: 'No description provided (Migrated Petty Cash)',
            'created_by' => $v['created_by'] ?: (int)$v['custodian_id'],
            'created_at' => $v['created_at'],
            'status' => $status,
        ];

        // Map account_id
        $cat = strtolower(trim($v['category']));
        if (strpos($cat, 'transport') !== false || strpos($cat, 'travel') !== false || strpos($cat, 'fuel') !== false) {
            $row_data['account_id'] = $transport_account_id ?: 0;
        } else {
            $row_data['account_id'] = $general_expense_account_id ?: 0;
        }

        // Map attachment
        $attachment = $v['receipt_path'];
        if ($attachment && strpos($attachment, 'petty-cash/') !== false) {
            $attachment = str_replace('petty-cash/', 'vouchers/', $attachment);
        }
        $row_data['attachment'] = $attachment;

        // Optional columns check and mapping
        if (in_array('tax_amount', $all_columns, true)) {
            $row_data['tax_amount'] = 0.00;
        }
        if (in_array('currency_code', $all_columns, true)) {
            $row_data['currency_code'] = 'TZS';
        }
        if (in_array('payment_method', $all_columns, true)) {
            $row_data['payment_method'] = 'cash';
        }
        if (in_array('pv_id', $all_columns, true)) {
            $row_data['pv_id'] = null;
        }
        if (in_array('source_type', $all_columns, true)) {
            $row_data['source_type'] = 'receipt';
        }
        if (in_array('source_account_id', $all_columns, true)) {
            $row_data['source_account_id'] = $cash_account_id;
        }
        if (in_array('is_posted', $all_columns, true)) {
            $row_data['is_posted'] = ($status === 'approved') ? 1 : 0;
        }
        if (in_array('is_active', $all_columns, true)) {
            $row_data['is_active'] = ($status === 'approved') ? 1 : 0;
        }
        if (in_array('approved_by', $all_columns, true)) {
            $row_data['approved_by'] = $v['approved_by'] ?: null;
        }
        if (in_array('approved_at', $all_columns, true)) {
            $row_data['approved_at'] = $v['approved_at'] ?: null;
        }

        // Build the query dynamically for this row
        $fields = array_keys($row_data);
        $placeholders = array_fill(0, count($fields), '?');
        $query = "INSERT INTO erp_expenses (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $insert_stmt = $pdo->prepare($query);
        $insert_stmt->execute(array_values($row_data));
        $migrated++;
    }
    echo "Migrated $migrated records to erp_expenses successfully.\n";
} catch (Exception $e) {
    echo "Error migrating vouchers: " . $e->getMessage() . "\n";
}

// 3. Move Physical Attachment Files on server
$pettyCashDir = __DIR__ . '/assets/uploads/petty-cash';
$vouchersDir = __DIR__ . '/assets/uploads/vouchers';

if (is_dir($pettyCashDir)) {
    echo "Moving files from uploads/petty-cash to uploads/vouchers...\n";
    $files = array_diff(scandir($pettyCashDir), ['.', '..']);
    $moved = 0;
    foreach ($files as $file) {
        $src = $pettyCashDir . '/' . $file;
        $dst = $vouchersDir . '/' . $file;
        if (rename($src, $dst)) {
            $moved++;
        }
    }
    echo "Moved $moved files.\n";
    @rmdir($pettyCashDir);
    echo "Removed legacy uploads/petty-cash directory.\n";
} else {
    echo "Legacy uploads/petty-cash directory not found or already moved.\n";
}

// 4. Drop original petty cash tables
foreach ($tables as $t) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
        echo "Dropped table `$t`.\n";
    } catch (Exception $e) {
        echo "Error dropping $t: " . $e->getMessage() . "\n";
    }
}

echo "Production migration completed successfully!\n";
echo "</pre>";
