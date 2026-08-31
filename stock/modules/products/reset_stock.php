<?php
// stock/modules/products/reset_stock.php
require_once '../../config/database.php';
require_once '../../config/functions.php';

requireLogin();

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = $_GET['id'];

// Check if Admin
if (!hasRole('admin')) {
    flash('success', 'Access Denied: Only Admins can reset stock.', 'danger');
    redirect('index.php');
}

try {
    // Reset stock to 0 in the stock table
    // We update stock table where product_id matches
    $stmt = $pdo->prepare("UPDATE stock SET quantity = 0 WHERE product_id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        flash('success', 'Product stock has been reset to 0.', 'success');
    } else {
        // If no rows updated, maybe it was already 0 or no stock entry exists
        // Check if stock entry exists
        $check = $pdo->prepare("SELECT quantity FROM stock WHERE product_id = ?");
        $check->execute([$id]);
        $s = $check->fetch();
        
        if ($s) {
            flash('success', 'Stock is already 0.', 'info');
        } else {
            // Create stock entry if missing
            $stmtIns = $pdo->prepare("INSERT INTO stock (product_id, quantity, location) VALUES (?, 0, '')");
            $stmtIns->execute([$id]);
            flash('success', 'Stock entry created and set to 0.', 'success');
        }
    }

} catch (PDOException $e) {
    flash('success', 'Database Error: ' . $e->getMessage(), 'danger');
}

redirect('index.php');
?>
