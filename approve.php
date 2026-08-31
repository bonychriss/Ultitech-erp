<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
// requireRole(['admin', 'procurement']);

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = $_GET['id'];
$stmt = $pdo->prepare("UPDATE purchases SET status = 'Approved', updated_at = NOW() WHERE id = ?");

if ($stmt->execute([$id])) {
    flash('success', 'Purchase Order approved successfully.');
} else {
    flash('danger', 'Failed to approve order.');
}

redirect("view_po.php?id=$id&order_approved=true");
?>
