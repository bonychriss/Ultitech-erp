<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
// requireRole(['admin', 'procurement']);

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Supplier deleted successfully!');
    } catch (PDOException $e) {
        flash('success', 'Error: Could not delete supplier. It may be linked to products or purchases.', 'danger');
    }
}
redirect('index.php');
?>
