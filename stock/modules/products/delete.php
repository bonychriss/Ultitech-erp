<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
// requireRole(['admin', 'procurement']);

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    try {
        // Due to foreign key constraints, we might need to be careful.
        // The schema has ON DELETE CASCADE for stock, but for purchases etc it might restrict.
        // If purchases exist, we likely shouldn't delete the product.
        
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Product deleted successfully!');
    } catch (PDOException $e) {
        flash('success', 'Error: Could not delete product. It may be part of existing purchase records.', 'danger');
    }
}
redirect('index.php');
?>
