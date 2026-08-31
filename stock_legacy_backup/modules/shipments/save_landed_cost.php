<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../classes/LandedCostCalculator.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['shipment_id'])) {
    
    $shipmentId = $_POST['shipment_id'];
    $calculator = new LandedCostCalculator($pdo);
    
    // 1. Prepare Columns to Update
    // Just a quick way to map POST fields to DB columns
    $fields = [
        'shipping_cost', 'insurance_cost', 'shipping_method', 
        'customs_duty', 'customs_brokerage', 'port_charges',
        'local_transport', 'warehousing_fees', 'other_costs'
    ];
    
    $params = [];
    $sql = "UPDATE shipments SET ";
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $sql .= "$field = ?, ";
            $params[] = $_POST[$field];
        }
    }
    
    $sql .= "updated_at = NOW() WHERE id = ?";
    $params[] = $shipmentId;
    
    try {
        $pdo->beginTransaction();
        
        // Update Shipment Main Costs
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Populate shipment_costs (simplified - clearing old and re-inserting summary lines)
        // In a real robust system, we would have line item management. Here we map the summary fields to cost details.
        $pdo->prepare("DELETE FROM shipment_costs WHERE shipment_id = ?")->execute([$shipmentId]);
        
        $insertDetail = $pdo->prepare("INSERT INTO shipment_costs (shipment_id, cost_type, amount, description, entered_by) VALUES (?, ?, ?, ?, ?)");
        
        // Map input fields to cost types
        $fieldMap = [
            'shipping_cost' => 'shipping',
            'insurance_cost' => 'insurance',
            'customs_duty' => 'duty',
            'customs_brokerage' => 'brokerage',
            'port_charges' => 'port',
            'local_transport' => 'transport',
            'warehousing_fees' => 'storage',
            'other_costs' => 'other'
        ];
        
        foreach ($fieldMap as $postKey => $costType) {
            if (!empty($_POST[$postKey])) {
                $insertDetail->execute([$shipmentId, $costType, $_POST[$postKey], 'Auto-generated from summary', $_SESSION['user_id']]);
            }
        }
        
        // 2. Run Allocation
        $method = $_POST['allocation_method'] ?? 'value';
        $calculator->allocateCosts($shipmentId, $method);
        
        // 3. Update Product Master Price if requested
        if (isset($_POST['update_products']) && $_POST['update_products'] == '1') {
            $calculator->updateProductMasterCosts($shipmentId);
            flash('success', 'Landed costs saved & Product "Buying Prices" updated successfully!');
        } else {
            flash('success', 'Landed costs saved successfully.');
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', 'Error saving costs: ' . $e->getMessage());
    }
    
    redirect('view.php?id=' . $shipmentId . '&tab=landed-cost');
} else {
    redirect('index.php'); 
}
?>
