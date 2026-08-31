<?php
require_once __DIR__ . '/../../includes/functions.php';

class DashboardStats {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getKPIs() {
        return [
            'sales' => $this->getSalesKPIs(),
            'outstanding' => $this->getOutstanding(),
            'cash' => $this->getCashPosition(),
            'stock' => $this->getStockAlerts(),
            'approvals' => $this->getPendingApprovals()
        ];
    }

    private function getSalesKPIs() {
        // Today
        $today = $this->pdo->query("SELECT COALESCE(SUM(total), 0) FROM erp_invoices WHERE DATE(invoice_date) = CURRENT_DATE")->fetchColumn();
        
        // MTD
        $mtd = $this->pdo->query("SELECT COALESCE(SUM(total), 0) FROM erp_invoices WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE) AND YEAR(invoice_date) = YEAR(CURRENT_DATE)")->fetchColumn();
        
        // YTD
        $ytd = $this->pdo->query("SELECT COALESCE(SUM(total), 0) FROM erp_invoices WHERE YEAR(invoice_date) = YEAR(CURRENT_DATE)")->fetchColumn();

        return ['today' => $today, 'mtd' => $mtd, 'ytd' => $ytd];
    }

    private function getOutstanding() {
        // AR: Sum of Invoice Balances (Using invoice table is safer than Ledger for now as we just enabled automation)
        $ar = $this->pdo->query("SELECT COALESCE(SUM(balance), 0) FROM erp_invoices WHERE status != 'paid' AND status != 'void'")->fetchColumn();
        
        // AP: Sum of Pending POs (Proxy for AP until full AP Ledger is active)
        $ap = $this->pdo->query("SELECT COALESCE(SUM(total), 0) FROM erp_purchase_orders WHERE status IN ('pending', 'approved')")->fetchColumn();
        
        return ['ar' => $ar, 'ap' => $ap];
    }

    private function getCashPosition() {
        // Cash + Bank from Ledger
        // We look for accounts named 'Cash' or 'Bank'
        // Formula: Sum(Debit) - Sum(Credit) for Asset accounts is usually Positive Balance
        $sql = "SELECT COALESCE(SUM(ji.debit) - SUM(ji.credit), 0) 
                FROM erp_journal_items ji
                JOIN erp_accounts a ON ji.account_id = a.id
                WHERE a.name IN ('Cash', 'Bank')";
        return $this->pdo->query($sql)->fetchColumn();
    }

    private function getStockAlerts() {
        // Count products where stock < reorder_level (assuming reorder_level column exists, else < 5)
        // Check if reorder_level exists? If not, we'll just check stock < 10 for now.
        // Let's assume stock_quantity exists.
        return $this->pdo->query("SELECT COUNT(*) FROM erp_products WHERE stock_quantity <= 10")->fetchColumn();
    }

    private function getPendingApprovals() {
        $pvs = $this->pdo->query("SELECT COUNT(*) FROM payment_vouchers WHERE status = 'pending'")->fetchColumn();
        $pos = $this->pdo->query("SELECT COUNT(*) FROM erp_purchase_orders WHERE status = 'pending'")->fetchColumn();
        return $pvs + $pos;
    }

    public function getSalesTrend() {
        // Last 6 months sales
        $sql = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, SUM(total) as total 
                FROM erp_invoices 
                WHERE invoice_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY month 
                ORDER BY month ASC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getTopCustomers() {
        $sql = "SELECT c.name, SUM(i.total) as total 
                FROM erp_invoices i 
                JOIN erp_customers c ON i.customer_id = c.id
                GROUP BY c.id 
                ORDER BY total DESC 
                LIMIT 5";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
