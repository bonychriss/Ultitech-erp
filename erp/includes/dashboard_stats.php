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
        $today = $this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE DATE(invoice_date) = CURRENT_DATE")->fetchColumn();
        
        // MTD
        $mtd = $this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE MONTH(invoice_date) = MONTH(CURRENT_DATE) AND YEAR(invoice_date) = YEAR(CURRENT_DATE)")->fetchColumn();
        
        // YTD
        $ytd = $this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE YEAR(invoice_date) = YEAR(CURRENT_DATE)")->fetchColumn();

        return ['today' => $today, 'mtd' => $mtd, 'ytd' => $ytd];
    }

    private function getOutstanding() {
        // AR: Sum of Invoice Balances
        try {
            $ar = $this->pdo->query("SELECT COALESCE(SUM(balance_due), 0) FROM invoices WHERE status != 'paid' AND status != 'cancelled'")->fetchColumn();
        } catch (Exception $e) { $ar = 0; }
        
        // AP: Placeholder or Vouchers
        $ap = $this->pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM payment_vouchers WHERE is_paid = 0")->fetchColumn();
        
        return ['ar' => $ar, 'ap' => $ap];
    }

    private function getCashPosition() {
        try {
            $sql = "SELECT COALESCE(SUM(ji.debit) - SUM(ji.credit), 0) 
                    FROM erp_journal_items ji
                    JOIN erp_accounts a ON ji.account_id = a.id
                    WHERE a.name IN ('Cash', 'Bank')";
            return $this->pdo->query($sql)->fetchColumn();
        } catch (Exception $e) {
            return 0; // Robust fallback if ledger tables missing
        }
    }

    private function getStockAlerts() {
        try {
            return $this->pdo->query("SELECT COUNT(*) FROM products p JOIN stock s ON p.id = s.product_id WHERE s.quantity <= p.reorder_level")->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getPendingApprovals() {
        $pvs = $this->pdo->query("SELECT COUNT(*) FROM payment_vouchers WHERE status = 'pending'")->fetchColumn();
        $pos = 0;
        try {
            $pos = $this->pdo->query("SELECT COUNT(*) FROM erp_purchase_orders WHERE status = 'pending'")->fetchColumn();
        } catch (Exception $e) {}
        return $pvs + $pos;
    }

    public function getSalesTrend() {
        // Last 6 months sales
        $sql = "SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month, SUM(total_amount) as total 
                FROM invoices 
                WHERE invoice_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY month 
                ORDER BY month ASC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getTopCustomers() {
        $sql = "SELECT c.company_name as name, SUM(i.total_amount) as total 
                FROM invoices i 
                JOIN customers c ON i.customer_id = c.id
                GROUP BY c.id, c.company_name
                ORDER BY total DESC 
                LIMIT 5";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
