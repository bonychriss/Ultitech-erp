<?php
/**
 * includes/accounting_service.php
 *
 * Central service to handle General Ledger postings (Double-Entry).
 */

class AccountingService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Post a balanced journal entry to the General Ledger.
     *
     * @param array<int, array<string, mixed>> $items
     * @return int|false
     */
    public function postEntry($date, $reference, $description, $items) {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($items as $item) {
            $totalDebit += (float) ($item['debit'] ?? 0);
            $totalCredit += (float) ($item['credit'] ?? 0);
        }

        if (abs($totalDebit - $totalCredit) > 0.01) {
            error_log("AccountingService Error: Unbalanced entry for reference $reference. Debits ($totalDebit) != Credits ($totalCredit)");
            return false;
        }

        $journalAccountCol = function_exists('resolveExistingColumn')
            ? resolveExistingColumn('erp_journal_items', 'account_id', ['gl_account_id', 'account'])
            : 'account_id';
        if (!$journalAccountCol) {
            error_log('AccountingService Error: journal items account column missing.');
            return false;
        }

        try {
            $entryCols = $this->pdo->query('SHOW COLUMNS FROM erp_journal_entries')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $useOwnTransaction = !$this->pdo->inTransaction();
            if ($useOwnTransaction) {
                $this->pdo->beginTransaction();
            }

            $fields = ['date'];
            $values = [$date];

            if (in_array('entry_number', $entryCols, true)) {
                $lastNum = 0;
                try {
                    $lastNum = (int) ($this->pdo->query("SELECT MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)) FROM erp_journal_entries WHERE entry_number LIKE 'JE-%'")->fetchColumn() ?: 0);
                } catch (Throwable $e) {
                }
                $fields[] = 'entry_number';
                $values[] = 'JE-' . str_pad((string) ($lastNum + 1), 6, '0', STR_PAD_LEFT);
            }

            if (in_array('reference', $entryCols, true)) {
                $fields[] = 'reference';
                $values[] = $reference;
            }

            $fields[] = 'description';
            $values[] = $description;

            if (in_array('status', $entryCols, true)) {
                $fields[] = 'status';
                $values[] = 'posted';
            }

            if (in_array('created_by', $entryCols, true)) {
                $fields[] = 'created_by';
                $values[] = $_SESSION['user_id'] ?? null;
            }

            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $this->pdo->prepare(
                'INSERT INTO erp_journal_entries (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute($values);
            $journalId = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                "INSERT INTO erp_journal_items (journal_id, {$journalAccountCol}, debit, credit) VALUES (?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                if (empty($item['account_id'])) {
                    throw new Exception("AccountingService Error: Missing account_id for item in reference $reference");
                }
                $itemStmt->execute([
                    $journalId,
                    $item['account_id'],
                    $item['debit'] ?? 0,
                    $item['credit'] ?? 0,
                ]);
            }

            if ($useOwnTransaction) {
                $this->pdo->commit();
            }
            return $journalId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('AccountingService Error: ' . $e->getMessage());
            return false;
        }
    }

    public function mapBudgetToAccountCode($budgetType) {
        $map = [
            'Office Supplies' => '5010',
            'Rent' => '5020',
            'Electricity' => '5030',
            'Water' => '5030',
            'Internet' => '5030',
            'Travel' => '5040',
            'Transport' => '5040',
            'Salaries' => '5001',
            'Wages' => '5001',
            'Bonus' => '5001',
        ];

        return $map[$budgetType] ?? '5999';
    }

    public function getAccountIdByCode($code) {
        $stmt = $this->pdo->prepare('SELECT id FROM erp_accounts WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : false;
    }

    public function getAccountIdByName($name) {
        $stmt = $this->pdo->prepare('SELECT id FROM erp_accounts WHERE name LIKE ? LIMIT 1');
        $stmt->execute(['%' . $name . '%']);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : false;
    }
}
