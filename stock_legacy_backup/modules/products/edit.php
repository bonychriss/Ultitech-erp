<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../classes/ImageProcessor.php';

requireLogin();
// requireRole(['admin', 'procurement']);

if (!isset($_GET['id'])) {
    redirect('index.php');
}
$id = $_GET['id'];

// Fetch Product
$stmt = $pdo->prepare("SELECT p.*, s.location FROM products p LEFT JOIN stock s ON p.id = s.product_id WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash('success', 'Product not found', 'danger');
    redirect('index.php');
}

// Fetch Existing Images
// Note: product_images table is currently missing, skipping detailed image management
$existing_images = [];
if (false) {
    $stmtImg = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC");
    $stmtImg->execute([$id]);
    $existing_images = $stmtImg->fetchAll();
}

// Fallback: use `products.image` as the primary image so Edit matches List/View.
$primaryImg = trim((string) ($product['image'] ?? $product['main_image'] ?? ''));
if ($primaryImg !== '') {
    $existing_images = [
        [
            'image_name' => $primaryImg,
            'is_primary' => 1,
        ],
    ];
}

// Fetch Categories and Suppliers
$categories = [];
try {
    $hasCats = (bool) $pdo->query("SHOW TABLES LIKE 'categories'")->fetchColumn();
    $hasStocksCats = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_categories'")->fetchColumn();
    $catsCount = $hasCats ? (int)($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() ?: 0) : 0;
    $stocksCount = $hasStocksCats ? (int)($pdo->query("SELECT COUNT(*) FROM stocks_categories")->fetchColumn() ?: 0) : 0;
    $useCats = $hasCats && ($catsCount >= $stocksCount);

    if ($useCats) {
        $categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
    } elseif ($hasStocksCats) {
        $categories = $pdo->query("SELECT * FROM stocks_categories ORDER BY name ASC")->fetchAll();
    } else {
        $categories = [];
    }
} catch (Throwable $e) {
    $categories = [];
}
$suppliers = $pdo->query("SELECT * FROM stocks_suppliers ORDER BY name ASC")->fetchAll();

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // DEBUG: Log upload data
    file_put_contents('debug_upload_edit.log', print_r($_FILES, true) . "\nPOST: " . print_r($_POST, true), FILE_APPEND);

    // Image actions disabled as product_images table is missing
    if (isset($_POST['delete_image_id']) && false) {
        $imgId = $_POST['delete_image_id'];
        $stmtDel = $pdo->prepare("SELECT image_name FROM product_images WHERE id = ? AND product_id = ?");
        $stmtDel->execute([$imgId, $id]);
        $imgToDelete = $stmtDel->fetch();
        
        if ($imgToDelete) {
            // Delete file logic (Optional in demo, good practice in prod)
            $path = __DIR__ . '/../../uploads/products/' . $id;
            @unlink($path . '/original/' . $imgToDelete['image_name']);
            @unlink($path . '/thumbnail/' . $imgToDelete['image_name']);
            @unlink($path . '/medium/' . $imgToDelete['image_name']);
            @unlink($path . '/large/' . $imgToDelete['image_name']);

            $pdo->prepare("DELETE FROM product_images WHERE id=?")->execute([$imgId]);
            flash('success', 'Image deleted.');
            redirect("edit.php?id=$id");
        }
    }
    
    // Set primary action disabled as product_images table is missing
    if (isset($_POST['set_primary_id']) && false) {
         $imgId = $_POST['set_primary_id'];
         $pdo->beginTransaction();
         $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?")->execute([$id]);
         $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE id = ?")->execute([$imgId]);
         
         // Update main field
         $nStmt = $pdo->prepare("SELECT image_name FROM product_images WHERE id = ?");
         $nStmt->execute([$imgId]);
         $newName = $nStmt->fetchColumn();
         $pdo->prepare("UPDATE products SET main_image = ? WHERE id = ?")->execute([$newName, $id]);
         
         $pdo->commit();
         flash('success', 'Primary image updated.');
         redirect("edit.php?id=$id");
    }

    $product_code = clean_input($_POST['product_code']);
    $name = clean_input($_POST['name']);
    $description = clean_input($_POST['description']);
    $category_id = $_POST['category_id'] ?: null;
    $supplier_id = $_POST['supplier_id'] ?: null;
    $unit_price = $_POST['unit_price'];
    $reorder_level = $_POST['reorder_level'];
    $location = clean_input($_POST['location']);

    if (empty($product_code) || empty($name)) {
        $error = "Product Code and Name are required.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Update Product
            $currency = clean_input($_POST['currency']);
            $cost_price = clean_input($_POST['buying_price']);
            $stmt = $pdo->prepare("UPDATE products SET product_code=?, name=?, description=?, category_id=?, supplier_id=?, unit_price=?, cost_price=?, reorder_level=?, currency=? WHERE id=?");
            $stmt->execute([$product_code, $name, $description, $category_id, $supplier_id, $unit_price, $cost_price, $reorder_level, $currency, $id]);
            
            // Update Stock Location
            $check = $pdo->prepare("SELECT id FROM stock WHERE product_id = ?");
            $check->execute([$id]);
            if ($check->fetch()) {
                $stmt = $pdo->prepare("UPDATE stock SET location=? WHERE product_id=?");
                $stmt->execute([$location, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO stock (product_id, quantity, location) VALUES (?, 0, ?)");
                $stmt->execute([$id, $location]);
            }

            // Handle New Images
            if (isset($_FILES['product_images']) && !empty($_FILES['product_images']['name'][0])) {
                $imageProcessor = new ImageProcessor(__DIR__ . '/../../uploads');
                $files = $_FILES['product_images'];
                $count = count($files['name']);
                
                // Check if any primary exists
                $hasPrimary = count($existing_images) > 0;
                
                for ($i = 0; $i < $count; $i++) {
                    if ($files['error'][$i] == 0) {
                        try {
                            $tempPath = $files['tmp_name'][$i];
                            $filename = $imageProcessor->processUploadedImage($tempPath, $id);
                            
                            $is_primary = (!$hasPrimary && $i == 0) ? 1 : 0; 
                            if ($is_primary) {
                                $pdo->prepare("UPDATE products SET image = ? WHERE id = ?")->execute([$filename, $id]);
                                $hasPrimary = true;
                            }
                        } catch (Exception $e) {
                             $error .= " Image Error: " . $e->getMessage();
                        }
                    }
                }
            }

            $pdo->commit();
            
            if (!empty($error)) {
                 flash('warning', 'Product updated but with errors: ' . $error);
            } else {
                 flash('success', 'Product updated successfully!');
            }
            redirect("edit.php?id=$id"); 
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
            if (strpos($e->getMessage(), 'product_images') !== false) {
                 $error .= " (Check if user is logged in)";
            }
        }
    }
}

$page_title = 'Edit Product';
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
    .pe-shell { font-family: 'Outfit', system-ui, -apple-system, sans-serif; font-size: 16px; color: #374151; }
    .dash-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
    .dash-card-h { background-color: #1c2331; color:#fff; font-weight:700; font-size:.75rem; text-transform: uppercase; letter-spacing:.04em; padding:.65rem 1rem; border-bottom:2px solid #151a24; }
    .img-tile { border:1px solid #e5e7eb; border-radius: 12px; overflow:hidden; background:#fff; }
    .img-tile img { display:block; width:100%; height: 92px; object-fit: cover; background:#f3f4f6; }
    .img-tile .meta { padding:.5rem .6rem; }
</style>

<main class="main-content pe-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-2">
                    <i class="bi bi-arrow-left me-1"></i><span class="d-none d-sm-inline">Products</span><span class="d-sm-none">Back</span>
                </a>
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-edit text-[#2563EB]"></i><span>Edit product</span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="view.php?id=<?php echo (int) $id; ?>" class="btn btn-outline-primary btn-sm rounded-2">
                    <i class="bi bi-eye me-1"></i><span class="d-none d-sm-inline">View</span>
                </a>
                <a href="<?php echo htmlspecialchars(app_url('/select-module.php')); ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-sm bg-gray-50/80 border-b border-gray-100 text-gray-600">
                <span class="small text-muted">Editing: <span class="fw-semibold text-gray-800"><?php echo htmlspecialchars($product['name'] ?? ''); ?></span></span>
                <span class="text-gray-300 d-none d-sm-inline">|</span>
                <span class="small text-muted">Code: <?php echo htmlspecialchars($product['product_code'] ?? ''); ?></span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <?php if ($error): ?>
                <div class="alert alert-danger border-0 shadow-sm"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="row g-3 align-items-start">
                <!-- Left: form -->
                <div class="col-12 col-lg-8">
                    <div class="dash-card mb-3">
                        <div class="dash-card-h">
                            Product details
                        </div>
                        <div class="p-4">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="product_code" class="form-label small fw-semibold text-secondary">Product code *</label>
                                        <input type="text" class="form-control" id="product_code" name="product_code" value="<?php echo htmlspecialchars((string)($product['product_code'] ?? '')); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="name" class="form-label small fw-semibold text-secondary">Product name *</label>
                                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars((string)($product['name'] ?? '')); ?>" required>
                                    </div>

                                    <div class="col-12">
                                        <label for="description" class="form-label small fw-semibold text-secondary">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars((string)($product['description'] ?? '')); ?></textarea>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="category_id" class="form-label small fw-semibold text-secondary">Category</label>
                                        <select class="form-select" id="category_id" name="category_id">
                                            <option value="">Select category</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo (int) $cat['id']; ?>" <?php if ((string)($product['category_id'] ?? '') === (string)($cat['id'] ?? '')) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars((string)($cat['name'] ?? '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="supplier_id" class="form-label small fw-semibold text-secondary">Default supplier</label>
                                        <select class="form-select" id="supplier_id" name="supplier_id">
                                            <option value="">Select supplier</option>
                                            <?php foreach ($suppliers as $sup): ?>
                                                <option value="<?php echo (int) $sup['id']; ?>" <?php if ((string)($product['supplier_id'] ?? '') === (string)($sup['id'] ?? '')) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars((string)($sup['name'] ?? '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="currency" class="form-label small fw-semibold text-secondary">Currency</label>
                                        <select class="form-select" id="currency" name="currency">
                                            <option value="USD" <?php if (($product['currency'] ?? 'USD') == 'USD') echo 'selected'; ?>>USD ($)</option>
                                            <option value="TZS" <?php if (($product['currency'] ?? 'USD') == 'TZS') echo 'selected'; ?>>TZS (TSh)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="buying_price" class="form-label small fw-semibold text-secondary">Buying price</label>
                                        <input type="number" step="0.01" class="form-control" id="buying_price" name="buying_price" value="<?php echo htmlspecialchars((string)($product['buying_price'] ?? $product['cost_price'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="unit_price" class="form-label small fw-semibold text-secondary">Selling price</label>
                                        <input type="number" step="0.01" class="form-control" id="unit_price" name="unit_price" value="<?php echo htmlspecialchars((string)($product['unit_price'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="reorder_level" class="form-label small fw-semibold text-secondary">Reorder level</label>
                                        <input type="number" class="form-control" id="reorder_level" name="reorder_level" value="<?php echo htmlspecialchars((string)($product['reorder_level'] ?? '')); ?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label for="location" class="form-label small fw-semibold text-secondary">Warehouse location</label>
                                        <input type="text" class="form-control" id="location" name="location" value="<?php echo htmlspecialchars((string)($product['location'] ?? '')); ?>">
                                    </div>

                                    <div class="col-12">
                                        <div class="p-3 bg-gray-50 border border-gray-200 rounded-3">
                                            <label class="form-label fw-semibold mb-2">Upload new images</label>
                                            <input type="file" name="product_images[]" multiple accept="image/*" class="form-control">
                                            <div class="small text-muted mt-1">Images will be processed and the first one becomes primary if none exists.</div>
                                        </div>
                                    </div>

                                    <div class="col-12 d-flex flex-wrap gap-2">
                                        <button type="submit" class="btn btn-primary rounded-2 fw-semibold">
                                            <i class="bi bi-save me-1"></i> Update product
                                        </button>
                                        <a href="view.php?id=<?php echo (int) $id; ?>" class="btn btn-outline-secondary rounded-2">
                                            <i class="bi bi-eye me-1"></i> View
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right: images -->
                <div class="col-12 col-lg-4">
                    <div class="dash-card">
                        <div class="dash-card-h">Images</div>
                        <div class="p-3">
                            <?php if (empty($existing_images)): ?>
                                <div class="text-muted small">No images uploaded.</div>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($existing_images as $img): ?>
                                        <div class="col-6 col-sm-4 col-lg-6">
                                            <div class="img-tile <?php echo !empty($img['is_primary']) ? 'border-primary border-2' : ''; ?>">
                                                <img src="../../uploads/products/<?php echo (int) $id; ?>/thumbnail/<?php echo rawurlencode((string) ($img['image_name'] ?? '')); ?>?t=<?php echo time(); ?>" alt="">
                                                <div class="meta">
                                                    <?php if (!empty($img['is_primary'])): ?>
                                                        <span class="badge bg-primary">Primary</span>
                                                    <?php endif; ?>
                                                    <?php if (isset($img['id'])): ?>
                                                        <div class="btn-group btn-group-sm w-100 mt-2">
                                                            <?php if (empty($img['is_primary'])): ?>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="set_primary_id" value="<?php echo (int) $img['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-primary" title="Set as Primary"><i class="fas fa-star"></i></button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this image?');">
                                                                <input type="hidden" name="delete_image_id" value="<?php echo (int) $img['id']; ?>">
                                                                <button type="submit" class="btn btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
