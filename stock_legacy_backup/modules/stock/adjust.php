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

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .mov-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .mov-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .mov-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .mov-filter-card {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .adj-stock-hint {
        font-size: 0.95rem;
        color: #4b5563;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 10px;
        padding: 0.75rem 1rem;
    }
</style>

<main class="main-content mov-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="../../index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="Stock module home">
                    <i class="fas fa-arrow-left text-sm"></i> Stock
                </a>
                <a href="movements.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-history text-sm"></i> Movements
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-sliders-h text-[#2563EB]"></i><span>Stock adjustment</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                <span class="text-gray-600"><i class="fas fa-info-circle text-gray-400 me-1"></i>Manually add, remove, or set on-hand quantity. Changes are logged on the movements page.</span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-4">
            <div class="row justify-content-center g-0">
                <div class="col-12 col-lg-8 col-xl-7">
                    <div class="mov-filter-card p-4 p-md-5">
                        <form method="POST" id="adjustForm" class="mb-0" onsubmit="var b=this.querySelector('button[type=submit]'); if(b){ b.disabled=true; b.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i> Processingâ€¦'; }">
                            <div class="mb-4">
                                <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1 d-block" style="letter-spacing:0.04em;">Product</label>
                                <select name="product_id" class="form-select border-gray-200 rounded-md" required id="productSelect">
                                    <option value="">Select productâ€¦</option>
                                    <?php foreach ($products as $p): ?>
                                        <option value="<?= $p['id'] ?>" data-qty="<?= htmlspecialchars((string)($p['current_qty'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="adj-stock-hint mt-3 mb-0" id="currentStockDisplay" role="status">Current stock: â€”</div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1 d-block" style="letter-spacing:0.04em;">Adjustment type</label>
                                    <select name="type" class="form-select border-gray-200 rounded-md" required>
                                        <option value="add">Add stock (+)</option>
                                        <option value="subtract">Remove stock (-)</option>
                                        <option value="set">Set quantity to (=)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1 d-block" style="letter-spacing:0.04em;">Quantity</label>
                                    <input type="number" name="quantity" class="form-control border-gray-200 rounded-md" required min="1" inputmode="numeric">
                                </div>
                            </div>

                            <div class="mt-4 mb-4">
                                <label class="form-label text-gray-700 fw-semibold small text-uppercase mb-1 d-block" style="letter-spacing:0.04em;">Reason / notes</label>
                                <textarea name="notes" class="form-control border-gray-200 rounded-md" rows="3" required placeholder="e.g. Broken items found during cycle count"></textarea>
                            </div>

                            <button type="submit" class="btn mov-btn-primary w-100 py-3 rounded-md fw-semibold border-0 text-base">
                                <i class="fas fa-save me-2"></i> Save adjustment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('productSelect');
    var disp = document.getElementById('currentStockDisplay');
    if (!sel || !disp) {
        return;
    }
    function updateStockHint() {
        var opt = sel.options[sel.selectedIndex];
        var qty = opt ? opt.getAttribute('data-qty') : null;
        if (sel.value && qty !== null && qty !== '') {
            disp.innerHTML = 'Current stock: <strong class="text-gray-900 tabular-nums">' + qty + '</strong>';
        } else {
            disp.textContent = 'Current stock: â€”';
        }
    }
    sel.addEventListener('change', updateStockHint);
    updateStockHint();
});
</script>

<?php include '../../includes/footer.php'; ?>
