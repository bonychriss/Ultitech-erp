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
                                    SUM(CASE WHEN COALESCE(s.quantity, 0) <= 0 THEN 1 ELSE 0 END) as out_of_stock
                                   FROM products p 
                                   JOIN stock s ON p.id = s.product_id");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cumulative product count per month (catalog growth).
     * Ultimate DB has no created_at — falls back to last_cost_update, stock_movements, then estimated ramp.
     */
    public function getInventoryTrend($limit = 6) {
        $limit = max(2, min(12, (int) $limit));
        $data = ['labels' => [], 'counts' => []];

        for ($i = $limit - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i months");
            $endDate = date('Y-m-t', $ts);
            $data['labels'][] = date("M 'y", $ts);
            $data['counts'][] = $this->getCumulativeProductCountAsOf($endDate);
        }

        if (array_sum($data['counts']) === 0) {
            $total = (int) ($this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() ?: 0);
            if ($total > 0) {
                $data['counts'] = $this->estimateProductCountRamp($total, $limit);
            }
        }

        return $data;
    }

    /**
     * Products in catalogue on or before $endDate (Y-m-d).
     */
    public function getCumulativeProductCountAsOf($endDate) {
        $endDate = date('Y-m-d', strtotime((string) $endDate));
        if ($endDate === '' || $endDate === '1970-01-01') {
            return 0;
        }

        $dateCol = $this->detectProductsDateColumn();
        if ($dateCol !== '') {
            $sql = "SELECT COUNT(*) FROM products WHERE DATE({$dateCol}) <= ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$endDate]);

            return (int) ($stmt->fetchColumn() ?: 0);
        }

        if ($this->tableExists('stock_movements')) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM products p
                     WHERE DATE(COALESCE(
                         (SELECT MIN(sm.created_at) FROM stock_movements sm WHERE sm.product_id = p.id),
                         '1970-01-01'
                     )) <= ?"
                );
                $stmt->execute([$endDate]);

                return (int) ($stmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                // fall through
            }
        }

        return 0;
    }

    private function detectProductsDateColumn() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        foreach (array('created_at', 'date_created', 'last_cost_update') as $col) {
            if ($this->columnExists('products', $col)) {
                $cached = $col;

                return $cached;
            }
        }
        $cached = '';

        return $cached;
    }

    private function columnExists($table, $column) {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([(string) $table, (string) $column]);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function tableExists($table) {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([(string) $table]);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Smooth ramp from ~55% to 100% of total when no historical dates exist.
     */
    private function estimateProductCountRamp($total, $limit) {
        $counts = [];
        for ($i = $limit - 1; $i >= 0; $i--) {
            $progress = ($limit - $i) / $limit;
            $factor = 0.55 + (0.45 * $progress);
            $counts[] = (int) max(1, round($total * $factor));
        }
        $counts[count($counts) - 1] = (int) $total;

        return $counts;
    }

    public function getTopCategoriesByStockValue($limit = 6) {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(c.name, 'Uncategorized') AS name,
                    SUM(COALESCE(s.quantity, 0) * COALESCE(NULLIF(p.cost_price, 0), p.unit_price, 0)) AS stock_value
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             LEFT JOIN stock s ON p.id = s.product_id
             GROUP BY c.id, c.name
             HAVING stock_value > 0
             ORDER BY stock_value DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
