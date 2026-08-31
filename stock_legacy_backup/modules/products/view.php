<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];

$hasCategoriesTable = false;
$hasStocksCategoriesTable = false;
$useCategoriesTable = false;
try {
    $hasCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'categories'")->fetchColumn();
    $hasStocksCategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_categories'")->fetchColumn();
    $catsCount = $hasCategoriesTable ? (int)($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() ?: 0) : 0;
    $stocksCatsCount = $hasStocksCategoriesTable ? (int)($pdo->query("SELECT COUNT(*) FROM stocks_categories")->fetchColumn() ?: 0) : 0;
    $useCategoriesTable = $hasCategoriesTable && ($catsCount >= $stocksCatsCount);
} catch (Throwable $e) {
    $hasCategoriesTable = false;
    $hasStocksCategoriesTable = false;
    $useCategoriesTable = false;
}

$hasProductsSupplierId = false;
try {
    $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasProductsSupplierId = in_array('supplier_id', $cols, true);
} catch (Throwable $e) {
    $hasProductsSupplierId = false;
}

$sql = 'SELECT p.*, c.name as category_name, ' . ($hasProductsSupplierId ? 's.name' : 'NULL') . ' as supplier_name, st.quantity as gross_stock, st.location,
        (
            SELECT SUM(soi.quantity)
            FROM sales_order_items soi
            JOIN sales_orders so ON soi.order_id = so.id
            WHERE soi.product_id = p.id
            AND so.status IN ("confirmed", "invoiced", "paid")
            AND so.status NOT IN ("shipped", "delivered", "cancelled")
            AND (so.shipped_at IS NULL OR so.shipped_at = "0000-00-00 00:00:00")
        ) as pending_demand
        FROM products p
        LEFT JOIN ' . ($useCategoriesTable ? 'categories' : 'stocks_categories') . ' c ON p.category_id = c.id
        ' . ($hasProductsSupplierId ? 'LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id' : '') . '
        LEFT JOIN stock st ON p.id = st.product_id
        WHERE p.id = ?';
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash('success', 'Product not found', 'danger');
    redirect('index.php');
}

$stmt = $pdo->prepare('SELECT id, type as movement_type, quantity, reference_type, reference_id, transaction_date as created_at FROM stocks_transactions WHERE item_id = ? ORDER BY transaction_date DESC LIMIT 10');
$stmt->execute([$id]);
$movements = $stmt->fetchAll();

$images = [];
$imgFile = $product['image'] ?? $product['main_image'] ?? '';
if ($imgFile !== '') {
    $images[] = ['image_name' => $imgFile, 'is_primary' => 1];
}

$currency = $product['currency'] ?? 'USD';
$priceSymbol = ($currency === 'TZS') ? 'TSh ' : '$';

$page_title = 'View Product';
include '../../includes/header.php';
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .pv-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .pv-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .pv-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .pv-card-h {
        background-color: #1c2331;
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.65rem 1rem;
        border-bottom: 2px solid #151a24;
    }
    .movements-table thead tr.pv-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .movements-table thead tr.pv-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
</style>

<main class="main-content pv-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Products
                </a>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn pv-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0">
                    <i class="fas fa-edit text-sm"></i> Edit
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0"><?php echo htmlspecialchars($product['name'] ?? ''); ?></h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <?php
            $v_gross = (float)($product['gross_stock'] ?? 0);
            $v_pending = (float)($product['pending_demand'] ?? 0);
            $v_available = $v_gross - $v_pending;
            ?>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span class="font-mono text-sm bg-gray-100 px-2 py-0.5 rounded border border-gray-200"><?php echo htmlspecialchars($product['product_code'] ?? ''); ?></span>
                <span class="text-gray-300">|</span>
                <span><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></span>
                <span class="text-gray-300">|</span>
                <span class="font-semibold text-gray-800 tabular-nums">Available: <?php echo htmlspecialchars((string) $v_available); ?></span>
                <?php if ($v_pending > 0): ?>
                    <span class="text-xs text-gray-500">(Phys: <?php echo $v_gross; ?> | Sold: <?php echo $v_pending; ?>)</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-4 pt-4">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm mb-4">
                        <div class="pv-card-h"><i class="fas fa-image me-2 opacity-80"></i>Image</div>
                        <div class="p-3 text-center">
                            <?php if (empty($images)): ?>
                                <div class="bg-gray-50 d-flex align-items-center justify-content-center rounded border border-gray-100" style="min-height: 220px;">
                                    <div class="text-gray-400">
                                        <i class="fas fa-image fa-3x mb-2 d-block"></i>
                                        <span class="text-sm">No image</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div id="productCarousel" class="carousel slide" data-bs-ride="carousel">
                                    <div class="carousel-inner rounded border border-gray-100 overflow-hidden bg-white">
                                        <?php foreach ($images as $index => $img): ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <?php
                                                $imgName = (string) ($img['image_name'] ?? '');
                                                $imgUrl = resolveProductImageUrl($id, $imgName, 'medium');
                                                ?>
                                                <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                                     class="d-block w-100" alt="" style="object-fit: contain; max-height: 240px;">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($images) > 1): ?>
                                        <button class="carousel-control-prev" type="button" data-bs-target="#productCarousel" data-bs-slide="prev">
                                            <span class="carousel-control-prev-icon rounded-circle bg-dark bg-opacity-75" style="width: 2rem; height: 2rem;" aria-hidden="true"></span>
                                            <span class="visually-hidden">Previous</span>
                                        </button>
                                        <button class="carousel-control-next" type="button" data-bs-target="#productCarousel" data-bs-slide="next">
                                            <span class="carousel-control-next-icon rounded-circle bg-dark bg-opacity-75" style="width: 2rem; height: 2rem;" aria-hidden="true"></span>
                                            <span class="visually-hidden">Next</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php if (count($images) > 1): ?>
                                    <div class="row g-1 mt-2 justify-content-center">
                                        <?php foreach ($images as $index => $img): ?>
                                            <div class="col-3">
                                                <?php
                                                $tName = (string) ($img['image_name'] ?? '');
                                                $tUrl = resolveProductImageUrl($id, $tName, 'thumbnail');
                                                ?>
                                                <img src="<?php echo htmlspecialchars($tUrl); ?>"
                                                     class="img-thumbnail pv-thumb p-0 border border-gray-200 rounded cursor-pointer"
                                                     style="cursor: pointer; opacity: <?php echo $index === 0 ? '1' : '0.55'; ?>;"
                                                     alt=""
                                                     onclick="if(typeof jQuery!=='undefined'){jQuery('#productCarousel').carousel(<?php echo $index; ?>); jQuery('.pv-thumb').css('opacity','0.55'); jQuery(this).css('opacity','1');}">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm mb-4">
                        <div class="pv-card-h"><i class="fas fa-info-circle me-2 opacity-80"></i>Information</div>
                        <div class="p-4">
                            <h2 class="h5 fw-bold text-gray-900 mb-1"><?php echo htmlspecialchars($product['name'] ?? ''); ?></h2>
                            <p class="text-gray-500 small mb-3"><?php echo htmlspecialchars($product['product_code'] ?? ''); ?></p>
                            <p class="text-gray-600 text-base mb-0" style="white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($product['description'] ?? '')); ?></p>
                            <hr class="my-3 border-gray-200">
                            <div class="d-flex justify-content-between mb-2 text-base"><span class="text-gray-500">Category</span><span class="fw-medium text-gray-900"><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></span></div>
                            <div class="d-flex justify-content-between mb-2 text-base"><span class="text-gray-500">Supplier</span><span class="fw-medium text-gray-900"><?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></span></div>
                            <div class="d-flex justify-content-between text-base"><span class="text-gray-500">Unit price</span><span class="fw-bold text-[#2563EB] tabular-nums"><?php echo $priceSymbol . number_format((float) ($product['unit_price'] ?? 0), 2); ?></span></div>
                            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'procurement'], true)): ?>
                                <div class="d-flex justify-content-between mt-2 text-base"><span class="text-gray-500">Cost</span><span class="fw-medium text-gray-700 tabular-nums"><?php echo $priceSymbol . number_format((float) ($product['cost_price'] ?? 0), 2); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm mb-4">
                        <div class="pv-card-h"><i class="fas fa-warehouse me-2 opacity-80"></i>Stock status</div>
                        <div class="p-4 text-center">
                            <?php
                            $available_view = (float)($product['gross_stock'] ?? 0) - (float)($product['pending_demand'] ?? 0);
                            $accent_color = ($available_view <= ($product['reorder_level'] ?? 0)) ? '#DC3545' : '#28A745';
                            ?>
                            <p class="display-6 fw-bold mb-0 tabular-nums" style="color: <?php echo $accent_color; ?>;"><?php echo htmlspecialchars((string) $available_view); ?></p>
                            <p class="text-gray-500 small mb-3">Net available stock</p>
                            <div class="border-top border-gray-100 pt-3 text-start text-base">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-gray-500 font-semibold">Physical total</span>
                                    <strong class="text-gray-900 tabular-nums"><?php echo htmlspecialchars((string) ($product['gross_stock'] ?? 0)); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-gray-500">Sold / Pending</span>
                                    <strong class="text-amber-600 tabular-nums"><?php echo htmlspecialchars((string) ($product['pending_demand'] ?? 0)); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-gray-500">Location</span>
                                    <strong class="text-gray-900"><?php echo htmlspecialchars($product['location'] ?? 'Not assigned'); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-gray-500">Reorder level</span>
                                    <strong class="text-gray-900 tabular-nums"><?php echo htmlspecialchars((string) ($product['reorder_level'] ?? 0)); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm">
                        <div class="pv-card-h"><i class="fas fa-history me-2 opacity-80"></i>Recent movements</div>
                        <div class="overflow-x-auto">
                            <table class="table table-hover align-middle mb-0 movements-table w-100">
                                <thead>
                                    <tr class="pv-table-head">
                                        <th class="ps-3 py-3">Date</th>
                                        <th class="py-3">Type</th>
                                        <th class="py-3">Qty</th>
                                        <th class="py-3">Ref</th>
                                        <th class="pe-3 py-3">Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($movements as $mov): ?>
                                        <tr class="border-b border-gray-100">
                                            <td class="ps-3 py-3 text-base text-gray-700 whitespace-nowrap"><?php echo date('d M Y H:i', strtotime($mov['created_at'])); ?></td>
                                            <td class="py-3 text-base">
                                                <?php if (($mov['movement_type'] ?? '') === 'in'): ?>
                                                    <span class="text-success fw-bold"><i class="fas fa-arrow-down"></i> IN</span>
                                                <?php elseif (($mov['movement_type'] ?? '') === 'out'): ?>
                                                    <span class="text-danger fw-bold"><i class="fas fa-arrow-up"></i> OUT</span>
                                                <?php else: ?>
                                                    <span class="text-warning fw-bold"><i class="fas fa-exchange-alt"></i> ADJ</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 fw-bold text-base tabular-nums"><?php echo htmlspecialchars((string) ($mov['quantity'] ?? '')); ?></td>
                                            <td class="py-3 text-base text-gray-700"><?php echo ucfirst(htmlspecialchars((string) ($mov['reference_type'] ?? ''))); ?> #<?php echo htmlspecialchars((string) ($mov['reference_id'] ?? '')); ?></td>
                                            <td class="pe-3 py-3 small text-gray-500"><?php echo htmlspecialchars($mov['notes'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($movements)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-12 text-gray-500 text-base">
                                                <i class="fas fa-clipboard-list text-4xl text-gray-300 mb-3 d-block"></i>
                                                No movements recorded
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
