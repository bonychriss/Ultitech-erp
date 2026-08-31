<?php

class LandedCostCalculator {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Calculate total landed cost for a shipment
     */
    public function calculateTotalCosts($shipmentId) {
        // Get Shipment Info
        $stmt = $this->pdo->prepare("SELECT total_value FROM shipments WHERE id = ?");
        $stmt->execute([$shipmentId]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shipment) return false;

        $productCost = $shipment['total_value'];

        // Get Additional Costs
        $stmtCosts = $this->pdo->prepare("SELECT SUM(amount) FROM shipment_costs WHERE shipment_id = ?");
        $stmtCosts->execute([$shipmentId]);
        $additionalCosts = $stmtCosts->fetchColumn() ?: 0;

        // Calculate Totals
        $totalLanded = $productCost + $additionalCosts;

        // Update Shipment Table
        $stmtUpdate = $this->pdo->prepare("UPDATE shipments SET total_additional_costs = ?, total_landed_cost = ?, cost_calculated_at = NOW() WHERE id = ?");
        $stmtUpdate->execute([$additionalCosts, $totalLanded, $shipmentId]);

        return [
            'product_cost' => $productCost,
            'additional_costs' => $additionalCosts,
            'total_landed' => $totalLanded
        ];
    }

    /**
     * Allocate costs to products based on method
     * methods: 'value', 'weight', 'volume', 'manual' (manual not fully implemented here)
     */
    public function allocateCosts($shipmentId, $method = 'value') {
        // 1. Get Shipment & Totals
        $totals = $this->calculateTotalCosts($shipmentId);
        $totalAdditional = $totals['additional_costs'];
        $totalProductValue = $totals['product_cost'];

        // 2. Get Shipment Items with Product Details
        $sql = "SELECT si.*, p.weight_kg, p.dimensions_cbm, p.unit_price 
                FROM shipment_items si 
                JOIN products p ON si.product_id = p.id 
                WHERE si.shipment_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$shipmentId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) return false;

        // 3. Prepare Allocation Pools
        $totalWeight = 0;
        $totalVolume = 0;
        
        foreach ($items as $item) {
            $totalWeight += ($item['quantity'] * $item['weight_kg']);
            $totalVolume += ($item['quantity'] * $item['dimensions_cbm']);
        }

        // 4. Clear Old Allocations
        $this->pdo->prepare("DELETE FROM product_landed_costs WHERE shipment_id = ?")->execute([$shipmentId]);

        // 5. Calculate & Insert New Allocations
        foreach ($items as $item) {
            $itemValue = $item['quantity'] * $item['unit_price']; // Using declared price in shipment item usually, but here derived
            
            $allocationRatio = 0;

            if ($method == 'value' && $totalProductValue > 0) {
                $allocationRatio = $itemValue / $totalProductValue;
            } elseif ($method == 'weight' && $totalWeight > 0) {
                $allocationRatio = ($item['quantity'] * $item['weight_kg']) / $totalWeight;
            } elseif ($method == 'volume' && $totalVolume > 0) {
                $allocationRatio = ($item['quantity'] * $item['dimensions_cbm']) / $totalVolume;
            }

            // Calculate share of additional cost
            $allocatedAdditional = $totalAdditional * $allocationRatio;
            $totalItemCost = $itemValue + $allocatedAdditional;
            $unitLandedCost = $item['quantity'] > 0 ? ($totalItemCost / $item['quantity']) : 0;

            // Simplified breakdown (pro-rated) for display purposes
            // In a real scenario, we might want to split duty specifically by HS code, etc.
            // For now, we lump everything into 'additional' but stored in respective columns pro-rata could be done if we fetched details
            
            $stmtInsert = $this->pdo->prepare("INSERT INTO product_landed_costs 
                (product_id, shipment_id, quantity, product_unit_cost, total_product_cost, total_additional_cost, total_landed_cost, landed_cost_per_unit, calculated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            $stmtInsert->execute([
                $item['product_id'],
                $shipmentId,
                $item['quantity'],
                $item['unit_price'],
                $itemValue,
                $allocatedAdditional,
                $totalItemCost,
                $unitLandedCost
            ]);
        }

        return true;
    }

    /**
     * Update actual product Buying Price in master catalogue
     */
    public function updateProductMasterCosts($shipmentId) {
        $stmt = $this->pdo->prepare("SELECT product_id, landed_cost_per_unit FROM product_landed_costs WHERE shipment_id = ?");
        $stmt->execute([$shipmentId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allocations as $alloc) {
            // Update Buying Price
            // Note: This replaces the cost. In advanced systems, this updates Moving Average Cost.
            // We will stick to simple replacement as per request or maybe weighted average?
            // "Update product cost in inventory with landed cost" -> Implies setting the new cost basis.
            
            $stmtUpd = $this->pdo->prepare("UPDATE products SET buying_price = ? WHERE id = ?");
            $stmtUpd->execute([$alloc['landed_cost_per_unit'], $alloc['product_id']]);
        }
        
        $this->pdo->prepare("UPDATE product_landed_costs SET product_cost_updated = 1 WHERE shipment_id = ?")->execute([$shipmentId]);
        
        return count($allocations);
    }
}
?>
