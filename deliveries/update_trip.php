<?php
require_once '../includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    $tripId = $_POST['trip_id'] ?? null;

    if (!$tripId) {
        die("Trip ID Missing");
    }

    try {
        if ($action === 'start_trip') {
            $stmt = $pdo->prepare("UPDATE delivery_trips SET status = 'in_transit', start_time = NOW() WHERE id = ?");
            $stmt->execute([$tripId]);
            
            // Also update all orders in this trip to 'in_transit' if they are 'planned'
            $upOrders = $pdo->prepare("UPDATE delivery_orders SET status = 'in_transit' WHERE trip_id = ? AND status = 'pending'");
            $upOrders->execute([$tripId]);
        } 
        elseif ($action === 'complete_trip') {
            $stmt = $pdo->prepare("UPDATE delivery_trips SET status = 'completed', end_time = NOW() WHERE id = ?");
            $stmt->execute([$tripId]);
            
            // Logic for completing the trip: check if any orders are still pending?
            // Usually, completing a trip means it's done. 
        }

        header("Location: view_trip.php?trip_id=" . $tripId);
        exit;
    } catch (Exception $e) {
        die("Error updating trip: " . $e->getMessage());
    }
} else {
    header("Location: index.php");
    exit;
}
?>
