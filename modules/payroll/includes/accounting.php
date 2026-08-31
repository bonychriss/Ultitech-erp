<?php
/**
 * modules/payroll/includes/accounting.php
 * Handles integration between Payroll and Accounting Ledger.
 */

function postPayrollToLedger($run_id) {
    global $pdo;
    $shouldCommit = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $shouldCommit = true;
        }

        // 1. Fetch Payroll Run Data
        $stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?');
        $stmt->execute([$run_id]);
        $run = $stmt->fetch();
        if (!$run) throw new Exception("Payroll run not found.");
        if ($run['status'] === 'paid') throw new Exception("Payroll already posted.");

        // 2. Fetch Aggregated Payslip Data
        $stmt = $pdo->prepare("
            SELECT 
                SUM(gross_salary) as total_gross,
                SUM(tax_deduction) as total_tax,
                SUM(nssf_deduction) as total_nssf,
                SUM(other_deductions) as total_other,
                SUM(net_salary) as total_net
            FROM " . payroll_table('payslips') . "
            WHERE payroll_run_id = ?
        ");
        $stmt->execute([$run_id]);
        $totals = $stmt->fetch();

        if (!$totals || $totals['total_gross'] == 0) {
            throw new Exception("No payslip data found for this run.");
        }

        $accounts = [
            'expense' => getOrCreateAccount('6001', 'Salaries Expense', 'expense'),
            'bank'    => getOrCreateAccount('1001', 'Bank Account', 'asset'),
            'paye'    => getOrCreateAccount('2001', 'PAYE Payable', 'liability'),
            'nssf'    => getOrCreateAccount('2002', 'NSSF Payable', 'liability'),
            'other'   => getOrCreateAccount('2003', 'Other Payroll Deductions', 'liability'),
        ];

        // 4. Create Journal Entry Header
        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)) FROM erp_journal_entries");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $jeNumber = 'JE-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
        
        $period = date('F Y', strtotime($run['year'] . '-' . $run['month'] . '-01'));
        $description = "Payroll Posting - " . $period;

        $stmt = $pdo->prepare("INSERT INTO erp_journal_entries (entry_number, date, description, status, created_by) VALUES (?, CURDATE(), ?, 'posted', ?)");
        $stmt->execute([$jeNumber, $description, $_SESSION['user_id'] ?? 0]);
        $jeId = $pdo->lastInsertId();

        // 5. Create Journal Items (Double Entry)
        
        // DEBIT: Salaries Expense (Total Gross)
        insertJournalItem($jeId, $accounts['expense'], $totals['total_gross'], 0);

        // CREDIT: Bank Account (Total Net)
        insertJournalItem($jeId, $accounts['bank'], 0, $totals['total_net']);

        // CREDIT: PAYE Payable
        if ($totals['total_tax'] > 0) {
            insertJournalItem($jeId, $accounts['paye'], 0, $totals['total_tax']);
        }

        // CREDIT: NSSF Payable
        if ($totals['total_nssf'] > 0) {
            insertJournalItem($jeId, $accounts['nssf'], 0, $totals['total_nssf']);
        }

        // CREDIT: Other Deductions
        if ($totals['total_other'] > 0) {
            insertJournalItem($jeId, $accounts['other'], 0, $totals['total_other']);
        }

        // 6. Final Validation: Do debits equal credits?
        $stmt = $pdo->prepare("SELECT SUM(debit) as d, SUM(credit) as c FROM erp_journal_items WHERE journal_id = ?");
        $stmt->execute([$jeId]);
        $chk = $stmt->fetch();
        if (abs($chk['d'] - $chk['c']) > 0.01) {
            throw new Exception("Accounting mismatch: Debits (" . $chk['d'] . ") != Credits (" . $chk['c'] . ")");
        }

        if ($shouldCommit) {
            $pdo->commit();
        }
        return true;
    } catch (Exception $e) {
        if ($shouldCommit && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Payroll Accounting Error: " . $e->getMessage());
        return $e->getMessage();
    }
}

function getOrCreateAccount($code, $name, $type) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = ? OR name = ?");
    $stmt->execute([$code, $name]);
    $acc = $stmt->fetch();
    if ($acc) return $acc['id'];

    $stmt = $pdo->prepare("INSERT INTO erp_accounts (code, name, type, status, is_system) VALUES (?, ?, ?, 'active', 1)");
    $stmt->execute([$code, $name, $type]);
    return $pdo->lastInsertId();
}

function insertJournalItem($jeId, $accId, $debit, $credit) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO erp_journal_items (journal_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
    $stmt->execute([$jeId, $accId, $debit, $credit]);
}
