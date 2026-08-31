<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$page_title = 'Stock Adjustment';
include '../../includes/header.php';

// Fetch Products
$products = $pdo->query("SELECT id, name, product_code, (SELECT quantity FROM stock WHERE product_id = products.id) as current_qty FROM products ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $product_id = $_POST['product_id'];
    $type = $_POST['type']; // 'add', 'subtract', 'set'
    $quantity = (int)$_POST['quantity'];
    $notes = clean_input($_POST['notes']);
    $user_id = $_SESSION['user_id'] ?? 1;

    try {
        $pdo->beginTransaction();

        // Get Current Stock
        $stmtCheck = $pdo->prepare("SELECT id, quantity FROM stock WHERE product_id = ?");
        $stmtCheck->execute([$product_id]);
        $stock = $stmtCheck->fetch();

        $current_qty = $stock ? $stock['quantity'] : 0;
        $new_qty = $current_qty;
        $movement_qty = 0;
        $movement_type = 'adjustment';

        if ($type == 'add') {
            $new_qty = $current_qty + $quantity;
            $movement_qty = $quantity;
            $movement_type = 'in'; // Or adjustment
        } elseif ($type == 'subtract') {
            $new_qty = $current_qty - $quantity;
            $movement_qty = $quantity;
            $movement_type = 'out'; // Or adjustment
        } elseif ($type == 'set') {
            $new_qty = $quantity;
            $movement_qty = abs($new_qty - $current_qty);
            $movement_type = ($new_qty > $current_qty) ? 'in' : 'out';
        }

        // Update Stock Table
        if ($stock) {
            $pdo->prepare("UPDATE stock SET quantity = ?, last_updated = NOW() WHERE id = ?")->execute([$new_qty, $stock['id']]);
        } else {
            $pdo->prepare("INSERT INTO stock (product_id, quantity, location, last_updated) VALUES (?, ?, 'Warehouse A', NOW())")->execute([$product_id, $new_qty]);
        }

        // Record Movement
        $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, created_at) VALUES (?, ?, ?, 'adjustment', '0', ?, NOW())")
            ->execute([$product_id, $movement_type, $movement_qty, $notes . " (Type: $type)"]);

        $pdo->commit();
        flash('success', 'Stock adjusted successfully.');
        redirect('movements.php');

    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', 'Error adjusting stock: ' . $e->getMessage());
    }
}
?>

<main class="main-content">
    <div class="stock-container">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-1">New Stock Adjustment</h4>
                <p class="text-muted mb-0">Manually correct stock levels</p>
            </div>
            <a href="movements.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back to History
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <form method="POST" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').innerHTML = 'Processing...';">
                            <div class="mb-3">
                                <label class="form-label">Product</label>
                                <select name="product_id" class="form-select select2" required id="productSelect">
                                    <option value="">Select Product...</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= $p['id'] ?>" data-qty="<?= $p['current_qty'] ?? 0 ?>">
                                            <?= htmlspecialchars($p['name']) ?> (<?= $p['product_code'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text mt-2" id="currentStockDisplay">Current Stock: -</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Adjustment Type</label>
                                    <select name="type" class="form-select" required>
                                        <option value="add">Add Stock (+)</option>
                                        <option value="subtract">Remove Stock (-)</option>
                                        <option value="set">Set Quantity To (=)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" class="form-control" required min="1">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Reason / Notes</label>
                                <textarea name="notes" class="form-control" rows="3" required placeholder="e.g. Broken items found during cycle count"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2">
                                <i class="fas fa-save me-2"></i> Save Adjustment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
    $(document).ready(function() {
        $('.select2').select2();
        
        $('#productSelect').on('change', function() {
            var selected = $(this).find('option:selected');
            var qty = selected.data('qty');
            if(qty !== undefined) {
                $('#currentStockDisplay').html('Current Stock: <strong>' + qty + '</strong>');
            } else {
                $('#currentStockDisplay').text('Current Stock: -');
            }
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>
