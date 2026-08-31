<?php
class StockStatistics {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getQuickStats() {
        // Today's Purchases (USD & TZS)
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT pr.currency, SUM(p.total_amount) as total 
                                     FROM purchases p 
                                     JOIN products pr ON p.product_id = pr.id 
                                     WHERE DATE(p.created_at) = ? AND p.status = 'Received'
                                     GROUP BY pr.currency");
        $stmt->execute([$today]);
        $purchases = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // currency => total
        
        // Low Stock Count
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM products p JOIN stock s ON p.id = s.product_id WHERE s.quantity <= p.reorder_level");
        $low_stock = $stmt->fetchColumn();

        return [
            'purchases_usd' => $purchases['USD'] ?? 0,
            'purchases_tzs' => $purchases['TZS'] ?? 0,
            'low_stock' => $low_stock
        ];
    }

    public function getMonthlyPurchaseTrend($limit = 6) {
        // Last $limit months
        $data = [
            'labels' => [],
            'usd' => [],
            'tzs' => []
        ];

        for ($i = $limit - 1; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $data['labels'][] = date('M Y', strtotime("-$i months"));
            
            // USD
            $stmt = $this->pdo->prepare("SELECT SUM(p.total_amount) 
                                         FROM purchases p 
                                         JOIN products pr ON p.product_id = pr.id 
                                         WHERE DATE_FORMAT(p.created_at, '%Y-%m') = ? 
                                         AND p.status = 'Received' 
                                         AND (pr.currency = 'USD' OR pr.currency IS NULL)");
            $stmt->execute([$month]);
            $data['usd'][] = $stmt->fetchColumn() ?: 0;

            // TZS
            $stmt = $this->pdo->prepare("SELECT SUM(p.total_amount) 
                                         FROM purchases p 
                                         JOIN products pr ON p.product_id = pr.id 
                                         WHERE DATE_FORMAT(p.created_at, '%Y-%m') = ? 
                                         AND p.status = 'Received' 
                                         AND pr.currency = 'TZS'");
            $stmt->execute([$month]);
            $data['tzs'][] = $stmt->fetchColumn() ?: 0;
        }

        return $data;
    }

    public function getStockDistributionByCategory() {
        // Returns count of products per category
        $stmt = $this->pdo->query("SELECT c.name, COUNT(p.id) as count 
                                   FROM products p 
                                   JOIN categories c ON p.category_id = c.id 
                                   GROUP BY c.id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStockStatusDistribution() {
        $stmt = $this->pdo->query("SELECT 
                                    SUM(CASE WHEN s.quantity > p.reorder_level THEN 1 ELSE 0 END) as in_stock,
                                    SUM(CASE WHEN s.quantity <= p.reorder_level AND s.quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                                    SUM(CASE WHEN s.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock
                                   FROM products p 
                                   JOIN stock s ON p.id = s.product_id");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTopProductsByValue($limit = 10) {
        // Value = Quantity * Unit Price. Needs to separate by currency or just show list.
        // We will show list and symbol.
        $stmt = $this->pdo->prepare("SELECT p.name, p.product_code, s.quantity, p.unit_price, p.currency, (s.quantity * p.unit_price) as total_value 
                                     FROM products p 
                                     JOIN stock s ON p.id = s.product_id 
                                     ORDER BY total_value DESC 
                                     LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
