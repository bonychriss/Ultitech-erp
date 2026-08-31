<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
if ($userRole !== 'procurement' && $userRole !== 'admin') {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $client = $_POST['client'];
    $mobile = $_POST['mobile'];
    $shipment_no = $_POST['shipment_no'];
    $shipment_status = $_POST['shipment_status'];
    $tracking_status = $_POST['tracking_status'];
    $packages = $_POST['packages'];
    $cbm = $_POST['cbm'];
    $total_value = $_POST['total_value'];
    $description = $_POST['description'];
    $shipment_date = !empty($_POST['shipment_date']) ? $_POST['shipment_date'] : null;
    $etd = !empty($_POST['etd']) ? $_POST['etd'] : null;
    $eta = !empty($_POST['eta']) ? $_POST['eta'] : null;

    try {
        if ($id) {
            // Update
            $stmt = $pdo->prepare("UPDATE order_tracking SET 
                client = ?, mobile = ?, shipment_no = ?, shipment_status = ?, tracking_status = ?, 
                packages = ?, cbm = ?, total_value = ?, description = ?, 
                shipment_date = ?, etd = ?, eta = ? 
                WHERE id = ?");
            $stmt->execute([
                $client, $mobile, $shipment_no, $shipment_status, $tracking_status, 
                $packages, $cbm, $total_value, $description, 
                $shipment_date, $etd, $eta, $id
            ]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO order_tracking 
                (client, mobile, shipment_no, shipment_status, tracking_status, packages, cbm, total_value, description, shipment_date, etd, eta) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $client, $mobile, $shipment_no, $shipment_status, $tracking_status, 
                $packages, $cbm, $total_value, $description, 
                $shipment_date, $etd, $eta
            ]);
        }
        header("Location: index.php");
        exit;
    } catch (PDOException $e) {
        die("Error saving record: " . $e->getMessage());
    }
}
?>
