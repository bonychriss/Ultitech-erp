<?php

class ReplenishmentService {
    protected $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Run replenishment logic.
     * Finds products below reorder level and creates draft POs grouped by supplier.
     * Returns array of created PO IDs.
     */
    public function run() {
        // 1. Find products needing replenishment
        // Must have a supplier assigned.
        $sql = "SELECT p.*, s.name as supplier_name 
                FROM erp_products p 
                JOIN erp_suppliers s ON p.supplier_id = s.id 
                WHERE p.stock_quantity <= p.reorder_level 
                AND p.status = 'active'";
        
        $stmt = $this->pdo->query($sql);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return ['status' => 'success', 'message' => 'No products need replenishment', 'po_ids' => []];
        }

        // 2. Group by Supplier
        $bySupplier = [];
        foreach ($products as $p) {
            $supplierId = $p['supplier_id'];
            if (!isset($bySupplier[$supplierId])) {
                $bySupplier[$supplierId] = [
                    'supplier_name' => $p['supplier_name'],
                    'items' => []
                ];
            }
            // Calculate detailed qty (e.g. reorder up to X amount? Or fixed amount?)
            // For now, let's order enough to reach reorder_level * 2, or just 10 if 0.
            // Simplified: Order 10 units.
            $orderQty = max(10, $p['reorder_level'] * 2 - $p['stock_quantity']);
            
            $bySupplier[$supplierId]['items'][] = [
                'product_id' => $p['id'],
                'quantity' => $orderQty,
                'cost' => $p['cost_price']
            ];
        }
        
        // 3. Create POs
        $poIds = [];
        $this->pdo->beginTransaction();
        
        try {
            foreach ($bySupplier as $supplierId => $data) {
                // Check if there is already a DRAFT PO for this supplier?
                // If yes, maybe append to it? For simplicity, create new.
                
                // Generate PO Number
                $stmt = $this->pdo->query("SELECT MAX(CAST(SUBSTRING(po_number, 4) AS UNSIGNED)) FROM erp_purchase_orders");
                $lastNum = $stmt->fetchColumn() ?: 0;
                $poNumber = 'PO-' . str_pad($lastNum + 1, 6, '0', STR_PAD_LEFT);
                
                // Create Header
                $sql = "INSERT INTO erp_purchase_orders (po_number, supplier_id, order_date, total_amount, status, created_by) VALUES (?, ?, NOW(), 0, 'draft', ?)";
                $user = $_SESSION['user_id'] ?? 1; // Default to admin if system task
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$poNumber, $supplierId, $user]);
                $poId = $this->pdo->lastInsertId();
                
                $totalAmount = 0;
                
                // Create Items
                $itemSql = "INSERT INTO erp_purchase_order_items (po_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?)";
                $itemStmt = $this->pdo->prepare($itemSql);
                
                foreach ($data['items'] as $item) {
                    $lineTotal = $item['quantity'] * $item['cost'];
                    $itemStmt->execute([
                        $poId,
                        $item['product_id'],
                        $item['quantity'],
                        $item['cost'],
                        $lineTotal
                    ]);
                    $totalAmount += $lineTotal;
                }
                
                // Update Total
                $upd = $this->pdo->prepare("UPDATE erp_purchase_orders SET total_amount = ? WHERE id = ?");
                $upd->execute([$totalAmount, $poId]);
                
                $poIds[] = $poId;
            }
            
            $this->pdo->commit();
            
            return [
                'status' => 'success', 
                'message' => count($poIds) . " Purchase Order(s) generated.", 
                'po_ids' => $poIds
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
