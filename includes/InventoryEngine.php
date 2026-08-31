<?php
/**
 * InventoryEngine.php
 * Handles FIFO stock valuation, batch management, and stock movements.
 */
class InventoryEngine {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Add stock (Inbound) - Creates a new batch
     */
    public function addStock($productId, $quantity, $costPrice, $refType, $refId, $userId) {
        if ($quantity <= 0) return false;

        $this->pdo->beginTransaction();
        try {
            // 1. Create Batch
            $stmt = $this->pdo->prepare("INSERT INTO erp_product_batches (product_id, batch_number, quantity, remaining_quantity, cost_price, received_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            // Generate batch number like BATCH-YYYYMMDD-RAND
            $batchNum = 'BATCH-' . date('Ymd') . '-' . rand(1000, 9999);
            $stmt->execute([
                $productId,
                $batchNum,
                $quantity,
                $quantity, // Initially remaining = total
                $costPrice,
                date('Y-m-d'),
                null // Expiry optional
            ]);
            $batchId = $this->pdo->lastInsertId();

            // 2. Log Movement
            $this->logMovement($productId, $quantity, 'in', $refType, $refId, $userId, "Stock Added (Batch $batchNum)");

            // 3. Update Product Total Stock
            $this->updateProductStock($productId);

            $this->pdo->commit();
            return $batchId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Remove stock (Outbound) - Consumes batches via FIFO
     */
    public function removeStock($productId, $quantity, $refType, $refId, $userId) {
        if ($quantity <= 0) return false;

        $this->pdo->beginTransaction();
        try {
            $remainingToDeduct = $quantity;
            $mrCost = 0; // Total cost of Goods Sold for this transaction

            // Fetch batches with remaining qty > 0, ordered by received_date ASC (FIFO)
            $stmt = $this->pdo->prepare("SELECT id, remaining_quantity, cost_price FROM erp_product_batches WHERE product_id = ? AND remaining_quantity > 0 ORDER BY received_date ASC, id ASC");
            $stmt->execute([$productId]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($batches as $batch) {
                if ($remainingToDeduct <= 0) break;

                $deduct = min($remainingToDeduct, $batch['remaining_quantity']);
                
                // Update batch
                $update = $this->pdo->prepare("UPDATE erp_product_batches SET remaining_quantity = remaining_quantity - ? WHERE id = ?");
                $update->execute([$deduct, $batch['id']]);

                // Accumulate cost
                $mrCost += ($deduct * $batch['cost_price']);
                
                $remainingToDeduct -= $deduct;
            }

            if ($remainingToDeduct > 0) {
                // Not enough stock! Allow negative? For strict FIFO, we shouldn't.
                // But often ERPs allow negative stock to fix later.
                // Here we throw exception to enforce discipline.
                throw new Exception("Insufficient stock for Product ID $productId. Shortage: $remainingToDeduct");
            }

            // Log Movement
            $this->logMovement($productId, $quantity, 'out', $refType, $refId, $userId, "Stock Removed (FIFO)");

            // Update Product Total Stock
            $this->updateProductStock($productId);

            // Return the Cost of Goods Sold (COGS) for this transaction for accounting
            $this->pdo->commit();
            return $mrCost;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function logMovement($productId, $qty, $type, $refType, $refId, $userId, $notes) {
        $stmt = $this->pdo->prepare("INSERT INTO erp_stock_movements (product_id, quantity, type, reference_type, reference_id, created_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$productId, $qty, $type, $refType, $refId, $userId, $notes]);
    }

    private function updateProductStock($productId) {
        // Recalculate total from movements or batches? Batches is safer for FIFO sync.
        $stmt = $this->pdo->prepare("SELECT SUM(remaining_quantity) FROM erp_product_batches WHERE product_id = ?");
        $stmt->execute([$productId]);
        $total = $stmt->fetchColumn() ?: 0;

        $this->pdo->prepare("UPDATE erp_products SET stock_quantity = ? WHERE id = ?")->execute([$total, $productId]);
    }

    /**
     * Get Total Value of Inventory (Sum of all batches)
     */
    public function getInventoryValue() {
        return $this->pdo->query("SELECT SUM(remaining_quantity * cost_price) FROM erp_product_batches WHERE remaining_quantity > 0")->fetchColumn() ?: 0;
    }
}
