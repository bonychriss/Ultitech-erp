<?php
// erp/accounting/journal_entry_service.php

class JournalEntryService
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Trigger: Invoice Posted (Revenue Recognition)
    public function postInvoice($invoiceId)
    {
        // Fetch Invoice and Items
        $stmt = $this->pdo->prepare("SELECT * FROM erp_invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch();

        // Fetch Items with Category Accounts
        $sql = "SELECT ii.*, p.category_id, c.income_account_id 
                FROM erp_invoice_items ii
                JOIN erp_products p ON ii.product_id = p.id
                JOIN erp_categories c ON p.category_id = c.id
                WHERE ii.invoice_id = ?";
        $items = $this->pdo->prepare($sql);
        $items->execute([$invoiceId]);
        $lines = $items->fetchAll();

        // Determine AR Account (Customer's default)
        $cust = $this->pdo->prepare("SELECT receivable_account_id FROM erp_customers WHERE id = ?");
        $cust->execute([$inv['customer_id']]);
        $arInfo = $cust->fetch();
        $arAccount = $arInfo['receivable_account_id']; // Needs fallback if null

        // Create Journal Entry Header
        $this->pdo->prepare("INSERT INTO erp_journal_entries (date, reference, journal_type, source_document) VALUES (?, ?, 'sale', ?)")
            ->execute([$inv['invoice_date'], 'INV: ' . $inv['invoice_number'], $inv['invoice_number']]);
        $entryId = $this->pdo->lastInsertId();

        // 1. Debit AR (Total Amount)
        $this->createItem($entryId, $arAccount, $inv['customer_id'], 'Invoice ' . $inv['invoice_number'], $inv['total'], 0);

        // 2. Credit Income (Per Line Item)
        foreach ($lines as $line) {
            $incomeAccount = $line['income_account_id']; // Using Category specific account
            $amount = $line['total']; // Excluding tax for now, normally tax is separate line
            $this->createItem($entryId, $incomeAccount, null, 'Product Sales: ' . $line['description'], 0, $amount);
        }

        // 3. Tax Liability (Simplified)
        if ($inv['tax_amount'] > 0) {
            // Fetch Tax Payable Account (Hardcoded or Config)
            $taxAcc = $this->getAccountByCode('220000'); // Tax Payable
            $this->createItem($entryId, $taxAcc, null, 'Tax Liability', 0, $inv['tax_amount']);
        }
    }

    // Trigger: Delivery Validation (COGS / Stock Asset)
    public function postDelivery($deliveryId)
    {
        // 1. Fetch Delivery
        $stmt = $this->pdo->prepare("SELECT * FROM erp_delivery_orders WHERE id = ?");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch();
        if (!$delivery)
            return;

        // 2. Fetch Moves (Items)
        $stmtMoves = $this->pdo->prepare("SELECT m.*, p.cost_price, p.category_id 
                                          FROM erp_stock_moves m 
                                          JOIN erp_products p ON m.product_id = p.id 
                                          WHERE m.delivery_order_id = ?");
        $stmtMoves->execute([$deliveryId]);
        $moves = $stmtMoves->fetchAll();

        if (empty($moves))
            return;

        // 3. Create Journal Entry
        $this->pdo->prepare("INSERT INTO erp_journal_entries (date, reference, journal_type, source_document) VALUES (?, ?, 'sale', ?)")
            ->execute([$delivery['date'], 'WH/OUT: ' . $delivery['delivery_number'], $delivery['delivery_number']]);
        $entryId = $this->pdo->lastInsertId();

        // 4. Create Items
        foreach ($moves as $move) {
            // Get Accounts from Category
            $stmtCat = $this->pdo->prepare("SELECT expense_account_id, stock_valuation_account_id FROM erp_categories WHERE id = ?");
            $stmtCat->execute([$move['category_id']]);
            $cat = $stmtCat->fetch();

            $cogsAcc = $cat['expense_account_id'];
            $assetAcc = $cat['stock_valuation_account_id'];

            if (!$cogsAcc || !$assetAcc)
                continue;

            $amount = floatval($move['quantity']) * floatval($move['cost_price']);

            // Debit COGS
            $this->createItem($entryId, $cogsAcc, null, 'COGS: ' . $delivery['delivery_number'], $amount, 0);
            // Credit Stock Asset
            $this->createItem($entryId, $assetAcc, null, 'Stock Out: ' . $delivery['delivery_number'], 0, $amount);
        }
    }

    public function reconcilePayment($transactionId)
    {
        // Fetch Transaction
        $stmt = $this->pdo->prepare("SELECT * FROM erp_bank_transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $txn = $stmt->fetch();

        // Check if Credit or Debit
        if ($txn['debit'] > 0) {
            // Money In (Customer Payment)
            // Debit Bank (102000), Credit Suspense (103000)

            $bankAcc = $this->getAccountByCode('102000');
            $suspenseAcc = $this->getAccountByCode('103000');

            $this->pdo->prepare("INSERT INTO erp_journal_entries (date, reference, journal_type, source_document) VALUES (?, ?, 'bank', ?)")
                ->execute([$txn['transaction_date'], 'BNK: ' . $txn['id'], 'BNK-' . $txn['id']]);
            $entryId = $this->pdo->lastInsertId();

            $this->createItem($entryId, $bankAcc, null, 'Bank Deposit', $txn['debit'], 0);
            $this->createItem($entryId, $suspenseAcc, null, 'Clear Suspense', 0, $txn['debit']);
        }
    }

    private function createItem($entryId, $accountId, $partnerId, $desc, $debit, $credit)
    {
        $stmt = $this->pdo->prepare("INSERT INTO erp_journal_items (entry_id, account_id, partner_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$entryId, $accountId, $partnerId, $desc, $debit, $credit]);
    }

    private function getAccountByCode($code)
    {
        $stmt = $this->pdo->prepare("SELECT id FROM erp_chart_of_accounts WHERE code = ?");
        $stmt->execute([$code]);
        return $stmt->fetchColumn();
    }
}
