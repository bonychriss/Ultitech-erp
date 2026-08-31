<?php

class AccountingEngine {
    
    private $pdo;
    private $userId;

    public function __construct($pdo, $userId) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    /**
     * Helper to find an account ID by its Type (and optionally default system flags if we had them)
     * For now, we search by Name or Type convention.
     */
    public function getAccountId($type, $nameLike = null) {
        $sql = "SELECT id FROM erp_accounts WHERE type = ?";
        $params = [$type];

        if ($nameLike) {
            $sql .= " AND name LIKE ?";
            $params[] = "%$nameLike%";
        }
        
        // Prefer 'code' order (usually smaller code = main account)
        $sql .= " ORDER BY code ASC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Create a Journal Entry
     */
    public function createJournalEntry($date, $description, $referenceType, $referenceId, $items) {
        // Generate JE Number
        $stmt = $this->pdo->query("SELECT MAX(CAST(SUBSTRING(entry_number, 4) AS UNSIGNED)) FROM erp_journal_entries");
        $lastNum = $stmt->fetchColumn() ?: 0;
        $jeNumber = 'JE-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);

        // Insert Header
        // Note: We might need to add reference columns to the schema if they don't exist
        // For now we put reference in description if schema doesn't support it, 
        // but let's check if we can add columns or if we should just append to description.
        // The current schema in api/journal-entries.php only shows: entry_number, date, description, created_by
        
        $finalDesc = $description . " (Ref: $referenceType #$referenceId)";
        
        $stmt = $this->pdo->prepare("INSERT INTO erp_journal_entries (entry_number, date, description, created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$jeNumber, $date, $finalDesc, $this->userId]);
        $jeId = $this->pdo->lastInsertId();

        // Insert Items
        $itemStmt = $this->pdo->prepare("INSERT INTO erp_journal_items (journal_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
        
        foreach ($items as $item) {
            $itemStmt->execute([
                $jeId,
                $item['account_id'],
                $item['debit'],
                $item['credit']
            ]);
        }
        
        return $jeId;
    }

    /**
     * Automated Posting of Invoice
     * Dr Accounts Receivable
     * Cr Sales Revenue
     * Cr Tax Payable (if any)
     */
    public function postInvoice($invoiceId) {
        // Fetch Invoice Data
        $stmt = $this->pdo->prepare("SELECT * FROM erp_invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();
        
        if (!$inv) throw new Exception("Invoice not found");
        
        // 1. Identify Accounts
        
        // Debit: Accounts Receivable (Asset)
        $arAccount = $this->getAccountId('asset', 'Receivable'); 
        if (!$arAccount) $arAccount = $this->getAccountId('asset'); // Fallback
        
        // Credit: Sales Income (Revenue)
        $salesAccount = $this->getAccountId('revenue', 'Sales');
        if (!$salesAccount) $salesAccount = $this->getAccountId('revenue'); // Fallback
        
        // Credit: Tax Payable (Liability)
        $taxAccount = $this->getAccountId('liability', 'Tax');
        if (!$taxAccount) $taxAccount = $this->getAccountId('liability'); // Fallback

        if (!$arAccount || !$salesAccount) {
            throw new Exception("Could not determine automatic accounts for AR or Sales. Please configure Chart of Accounts.");
        }

        $items = [];
        
        // Debit AR (Total Receivable)
        $items[] = [
            'account_id' => $arAccount,
            'debit' => $inv['total'],
            'credit' => 0
        ];
        
        // Credit Sales (Subtotal)
        $items[] = [
            'account_id' => $salesAccount,
            'debit' => 0,
            'credit' => $inv['subtotal']
        ];
        
        // Credit Tax
        if ($inv['tax_amount'] > 0) {
            $items[] = [
                'account_id' => $taxAccount,
                'debit' => 0,
                'credit' => $inv['tax_amount']
            ];
        }
        
        // Create JE
        return $this->createJournalEntry(
            date('Y-m-d'), 
            "Invoice Posting: " . $inv['invoice_number'], 
            'Invoice', 
            $invoiceId, 
            $items
        );
    }

    /**
     * Automated Payment Registration
     * Dr Bank/Cash
     * Cr Accounts Receivable
     */
    public function registerPayment($invoiceId) {
        $stmt = $this->pdo->prepare("SELECT * FROM erp_invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();
        
        if (!$inv) throw new Exception("Invoice not found");

        // Debit: Bank (Asset)
        $bankAccount = $this->getAccountId('asset', 'Bank');
        if (!$bankAccount) $bankAccount = $this->getAccountId('asset', 'Cash');
        if (!$bankAccount) $bankAccount = $this->getAccountId('asset');
        
        // Credit: Accounts Receivable (Asset) - Must match the one used in Posting!
        $arAccount = $this->getAccountId('asset', 'Receivable');
        if (!$arAccount) $arAccount = $this->getAccountId('asset');

        if (!$bankAccount || !$arAccount) {
            throw new Exception("Could not determine automatic accounts for Bank or AR.");
        }
        
        $items = [];
        
        // Dr Bank
        $items[] = [
            'account_id' => $bankAccount,
            'debit' => $inv['total'],
            'credit' => 0
        ];
        
        // Cr AR
        $items[] = [
            'account_id' => $arAccount,
            'debit' => 0,
            'credit' => $inv['total']
        ];
        
        return $this->createJournalEntry(
            date('Y-m-d'), 
            "Payment Received: " . $inv['invoice_number'], 
            'Invoice', 
            $invoiceId, 
            $items
        );
    }
}
