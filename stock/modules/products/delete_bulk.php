<?php
// stock/modules/products/delete_bulk.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (isset($_GET['ids']) && !empty($_GET['ids'])) {
    $ids = explode(',', $_GET['ids']);
    $success_count = 0;
    $fail_count = 0;

    foreach ($ids as $id) {
        $id = trim($id);
        if (empty($id)) continue;

        try {
            // Attempt to delete individual product
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success_count++;
            } else {
                $fail_count++;
            }
        } catch (PDOException $e) {
            $fail_count++;
        }
    }

    if ($success_count > 0 && $fail_count == 0) {
        flash('success', "Successfully deleted $success_count products.");
    } elseif ($success_count > 0 && $fail_count > 0) {
        flash('success', "Deleted $success_count products. $fail_count products could not be deleted due to existing records.", 'warning');
    } elseif ($fail_count > 0) {
        flash('success', "Error: $fail_count products could not be deleted. They may be linked to existing purchase/sales history.", 'danger');
    }
} else {
    flash('success', 'No products selected for deletion.', 'warning');
}

redirect('index.php');
?>
