<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../classes/ImageProcessor.php';

requireLogin();

$categories = [];
$suppliers = [];
$cid = currentCompanyId();
try {
    // Live site uses `categories` table
    $sqlCats = "SELECT * FROM categories " . ($cid ? "WHERE company_id = ? OR company_id IS NULL" : "") . " ORDER BY name ASC";
    $stmtCats = $pdo->prepare($sqlCats);
    if ($cid) $stmtCats->execute([$cid]); else $stmtCats->execute();
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categories = [];
}
try {
    $sqlSup = "SELECT * FROM stocks_suppliers " . ($cid ? "WHERE company_id = ? OR company_id IS NULL" : "") . " ORDER BY name ASC";
    $stmtSup = $pdo->prepare($sqlSup);
    if ($cid) $stmtSup->execute([$cid]); else $stmtSup->execute();
    $suppliers = $stmtSup->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $suppliers = [];
}

$error = '';
/** @var array{title: string, message: string, variant: string}|null $productAddSuccess */
$productAddSuccess = null;
if (!empty($_SESSION['stock_product_add_success']) && is_array($_SESSION['stock_product_add_success'])) {
    $productAddSuccess = $_SESSION['stock_product_add_success'];
    unset($_SESSION['stock_product_add_success']);
}

// Ensure products table has required columns for this page (older DBs may miss them).
try {
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if ($cols) {
        if (!in_array('cost_price', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN cost_price DECIMAL(12,2) NULL AFTER unit_price");
        }
        if (!in_array('image', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image VARCHAR(255) NULL AFTER reorder_level");
        }
        if (!in_array('supplier_id', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN supplier_id INT NULL AFTER category_id");
        }
        if (!in_array('currency', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER reorder_level");
        }
    }
} catch (Throwable $e) {
    // Don't block the page if schema checks fail
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $register_type = $_POST['register_type'] ?? 'spare_part';
    $product_code = clean_input($_POST['product_code'] ?? '');
    $name = clean_input($_POST['name'] ?? '');
    $description = clean_input($_POST['description'] ?? '');
    $category_id = $_POST['category_id'] ?? null ?: null;
    $supplier_id = $_POST['supplier_id'] ?? null ?: null;
    $unit_price = $_POST['unit_price'] ?? '0';
    $cost_price = clean_input($_POST['buying_price'] ?? '0');
    $reorder_level = $_POST['reorder_level'] ?? '0';
    $initial_stock_location = clean_input($_POST['location'] ?? '');
    $currency = clean_input($_POST['currency'] ?? 'USD');
    
    // Truck Specs
    $item_type = ($register_type === 'truck') ? 'vehicle' : 'spare_part';
    $truck_type = clean_input($_POST['truck_type'] ?? '');
    $model_number = clean_input($_POST['model_number'] ?? '');
    $engine_model = clean_input($_POST['engine_model'] ?? '');
    $transmission_model = clean_input($_POST['transmission_model'] ?? '');
    $fuel_tank_capacity_l = clean_input($_POST['fuel_tank_capacity_l'] ?? '');
    $cab_details = clean_input($_POST['cab_details'] ?? '');
    $vin = clean_input($_POST['vin'] ?? '');
    $chassis_number = clean_input($_POST['chassis_number'] ?? '');
    $engine_number = clean_input($_POST['engine_number'] ?? '');
    $model_year = $_POST['model_year'] ?? null ?: null;
    $mileage = $_POST['mileage'] ?? null ?: null;
    $color = clean_input($_POST['color'] ?? '');
    $oem_number = clean_input($_POST['oem_number'] ?? '');

    if ($product_code === '' || $name === '') {
        $error = 'Product code and name are required.';
    } else {
        try {
            $pdo->beginTransaction();

            $sqlInsert = "INSERT INTO products (
                company_id, product_code, name, description, category_id, supplier_id, 
                unit_price, cost_price, reorder_level, currency, item_type,
                truck_type, model_number, engine_model, transmission_model, fuel_tank_capacity_l, cab_details,
                vin, chassis_number, engine_number, model_year, mileage, color, oem_number
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sqlInsert);
            $stmt->execute([
                $cid, $product_code, $name, $description, $category_id, $supplier_id,
                $unit_price, $cost_price, $reorder_level, $currency, $item_type,
                $truck_type, $model_number, $engine_model, $transmission_model, $fuel_tank_capacity_l, $cab_details,
                $vin, $chassis_number, $engine_number, $model_year, $mileage, $color, $oem_number
            ]);
            
            $product_id = $pdo->lastInsertId();

            $imageError = '';
            if (isset($_FILES['product_images']) && !empty($_FILES['product_images']['name'][0])) {
                $imageProcessor = new ImageProcessor(__DIR__ . '/../../uploads');
                $files = $_FILES['product_images'];
                $count = count($files['name']);

                for ($i = 0; $i < $count; $i++) {
                    if ($files['error'][$i] == 0) {
                        try {
                            $tempPath = $files['tmp_name'][$i];
                            $filename = $imageProcessor->processUploadedImage($tempPath, $product_id);
                            $is_primary = ($i == 0) ? 1 : 0;
                            
                            // Insert into product_images table if it exists
                            try {
                                $pdo->prepare("INSERT INTO product_images (product_id, image_name, is_primary) VALUES (?, ?, ?)")->execute([$product_id, $filename, $is_primary]);
                            } catch (Exception $e) {}
                            
                            if ($is_primary) {
                                $stmtMain = $pdo->prepare('UPDATE products SET image = ?, main_image = ? WHERE id = ?');
                                $stmtMain->execute([$filename, $filename, $product_id]);
                            }
                        } catch (Exception $e) {
                            $imageError .= ' Image error: ' . $e->getMessage();
                        }
                    }
                }
            }

            $stmt = $pdo->prepare('INSERT INTO stock (product_id, quantity, location, company_id) VALUES (?, 0, ?, ?)');
            $stmt->execute([$product_id, $initial_stock_location, $cid]);

            $pdo->commit();

            $swTitle = $imageError !== '' ? 'Warning' : 'Success!';
            $swText = $imageError !== ''
                ? 'Product created but with issues:' . $imageError
                : 'Product created successfully!';
            $swIcon = $imageError !== '' ? 'warning' : 'success';
            $_SESSION['stock_product_add_success'] = [
                'title' => $swTitle,
                'message' => $swText,
                'variant' => $swIcon,
            ];
            header('Location: add.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() == 23000) {
                if (strpos($e->getMessage(), 'product_code') !== false) {
                    $error = 'Product code already exists.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            } else {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Add Product';
include '../../includes/header.php';

$year = date('Y');
$prefixSpare = "PRD-$year-";
$prefixTruck = "TRK-$year-";

function getNextCode($pdo, $prefix, $cid) {
    $stmtMax = $pdo->prepare('
        SELECT MAX(CAST(SUBSTRING_INDEX(product_code, \'-\', -1) AS UNSIGNED))
        FROM products
        WHERE product_code LIKE ? ' . ($cid ? 'AND company_id = ?' : '') . '
    ');
    $params = [$prefix . '%'];
    if ($cid) $params[] = $cid;
    $stmtMax->execute($params);
    $maxNum = $stmtMax->fetchColumn();
    $nextNum = $maxNum ? ($maxNum + 1) : 1;
    
    do {
        $code = $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM products WHERE product_code = ? ' . ($cid ? 'AND company_id = ?' : ''));
        $checkParams = [$code];
        if ($cid) $checkParams[] = $cid;
        $stmtCheck->execute($checkParams);
        $exists = (int) $stmtCheck->fetchColumn();
        if ($exists) $nextNum++;
    } while ($exists);
    return $code;
}

$auto_code_spare = getNextCode($pdo, $prefixSpare, $cid);
$auto_code_truck = getNextCode($pdo, $prefixTruck, $cid);

$post = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') ? $_POST : [];
$val = static function ($key, $default = '') use ($post) {
    return htmlspecialchars($post[$key] ?? $default, ENT_QUOTES, 'UTF-8');
};
$display_code = ($post['product_code'] ?? '') !== '' ? $val('product_code') : htmlspecialchars($auto_code, ENT_QUOTES, 'UTF-8');
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .pa-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .pa-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .pa-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .pa-form-card-h {
        background-color: #1c2331;
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.65rem 1.25rem;
        border-bottom: 2px solid #151a24;
    }

    /* Mobile success bottom sheet (Dispatch-style) */
    @media (max-width: 767.98px) {
        body.product-add-success-sheet-open {
            overflow: hidden;
            touch-action: none;
        }
    }
    .product-add-success-sheet-backdrop {
        display: none;
    }
    .product-add-success-sheet {
        display: none;
    }
    @media (max-width: 767.98px) {
        .product-add-success-sheet-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            z-index: 1080;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.28s ease, visibility 0.28s ease;
        }
        .product-add-success-sheet-backdrop.is-visible {
            opacity: 1;
            visibility: visible;
        }
        .product-add-success-sheet {
            display: block;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            max-height: min(58vh, 420px);
            background: #fff;
            border-radius: 1.25rem 1.25rem 0 0;
            box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
            z-index: 1090;
            transform: translateY(105%);
            transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
            padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
        .product-add-success-sheet.is-visible {
            transform: translateY(0);
        }
        .product-add-success-sheet-handle {
            width: 40px;
            height: 5px;
            background: #d1d5db;
            border-radius: 999px;
            margin: 12px auto 8px;
            flex-shrink: 0;
        }
    }
</style>

<main class="main-content pa-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Products
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Add product</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="categories.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-tags text-sm"></i> Categories
                </a>
            </div>
            <div class="px-4 py-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <i class="fas fa-info-circle text-gray-400 me-1"></i>Product code and name are required. First uploaded image becomes the primary photo.
                <?php if (isIndustry('trucks')): ?>
                    <div class="mt-2 text-xs font-bold uppercase tracking-wider text-blue-600">Trucking Feature Active</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if ($productAddSuccess): ?>
                <?php $paVariant = ($productAddSuccess['variant'] ?? 'success') === 'warning' ? 'warning' : 'success'; ?>
                <div class="d-md-none product-add-success-sheet-backdrop" id="productAddSuccessBackdrop" aria-hidden="true"></div>
                <div class="d-md-none product-add-success-sheet" id="productAddSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="productAddSuccessSheetTitle">
                    <div class="product-add-success-sheet-handle" aria-hidden="true"></div>
                    <div class="px-4 pb-4 pt-0 text-center">
                        <?php if ($paVariant === 'warning'): ?>
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-15 text-warning mb-3" style="width: 56px; height: 56px;">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                            </div>
                        <?php else: ?>
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                                <i class="fas fa-check fa-lg"></i>
                            </div>
                        <?php endif; ?>
                        <h2 id="productAddSuccessSheetTitle" class="h5 fw-bold text-dark mb-2"><?php echo htmlspecialchars($productAddSuccess['title'] ?? 'Success'); ?></h2>
                        <p class="text-secondary mb-4 small"><?php echo htmlspecialchars($productAddSuccess['message'] ?? ''); ?></p>
                        <a href="index.php" class="btn pa-btn-primary w-100 py-2 rounded-pill fw-semibold border-0 d-inline-flex align-items-center justify-content-center" id="productAddSuccessDismiss">
                            View products
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger rounded-lg border-0 shadow-sm mb-4" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mx-auto" style="max-width: 56rem;">
                <div class="pa-form-card-h"><i class="fas fa-box me-2 opacity-80"></i>Product details</div>
                <div class="p-4 p-lg-5">
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php if (isIndustry('trucks')): ?>
                        <!-- Register Type Toggle -->
                        <div class="bg-gray-50 p-3 rounded-lg border border-gray-200 mb-4 d-flex align-items-center gap-3">
                            <span class="text-xs font-bold text-gray-500 uppercase">Register as:</span>
                            <div class="btn-group btn-group-sm" role="group">
                                <input type="radio" class="btn-check" name="register_type" id="type_spare" value="spare_part" checked onclick="toggleProductFields('spare_part')">
                                <label class="btn btn-outline-primary px-3" for="type_spare">Spare Part</label>

                                <input type="radio" class="btn-check" name="register_type" id="type_truck" value="truck" onclick="toggleProductFields('truck')">
                                <label class="btn btn-outline-primary px-3" for="type_truck">Truck / Vehicle</label>
                            </div>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="register_type" value="spare_part">
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="product_code" class="form-label fw-semibold text-gray-700">Product code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-md border-gray-300 bg-light" id="product_code_input" name="product_code" value="<?= $auto_code_spare ?>" readonly>
                                <small class="text-muted">Auto-generated</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label fw-semibold text-gray-700" id="label_name">Product name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-md border-gray-300" id="name" name="name" required value="<?php echo $val('name'); ?>" placeholder="e.g. Brake Pad Set">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label fw-semibold text-gray-700">Description</label>
                            <textarea class="form-control rounded-md border-gray-300" id="description" name="description" rows="2"><?php echo $val('description'); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label fw-semibold text-gray-700">Category</label>
                                <?php if (empty($categories)): ?>
                                    <div class="alert alert-warning border-0 rounded-lg shadow-sm py-2 px-3 mb-2">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        No categories found. Add one in
                                        <a class="fw-semibold text-decoration-none" href="categories.php">Categories</a>.
                                    </div>
                                <?php endif; ?>
                                <select class="form-select rounded-md border-gray-300" id="category_id" name="category_id">
                                    <option value=""><?php echo empty($categories) ? 'No categories available' : 'Select category'; ?></option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo (int) $cat['id']; ?>" <?php echo (string) ($post['category_id'] ?? '') === (string) ($cat['id'] ?? '') ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($cat['name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="supplier_id" class="form-label fw-semibold text-gray-700">Default supplier</label>
                                <select class="form-select rounded-md border-gray-300" id="supplier_id" name="supplier_id">
                                    <option value="">Select supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo (int) $sup['id']; ?>" <?php echo (string) ($post['supplier_id'] ?? '') === (string) $sup['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row" id="extra_specs_row">
                            <div class="col-md-6 mb-3" id="field_oem">
                                <label for="oem_number" class="form-label fw-semibold text-gray-700">OEM Number</label>
                                <input type="text" class="form-control rounded-md border-gray-300" id="oem_number" name="oem_number" value="<?php echo $val('oem_number'); ?>" placeholder="Optional">
                            </div>
                            <div class="col-md-6 mb-3" id="field_brand">
                                <label for="brand" class="form-label fw-semibold text-gray-700">Brand / Make</label>
                                <input type="text" class="form-control rounded-md border-gray-300" id="brand" name="brand" value="<?php echo $val('brand'); ?>" placeholder="e.g. Volvo, Bosch">
                            </div>
                        </div>

                        <?php if (isIndustry('trucks')): ?>
                        <!-- Truck Specific Fields -->
                        <div id="truck_specs_section" style="display: none;">
                            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-4">
                                <h3 class="text-sm font-bold text-gray-800 uppercase mb-3 tracking-wider">Vehicle Specifications</h3>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold text-gray-700">VIN</label>
                                        <input type="text" name="vin" class="form-control rounded-md border-gray-300">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold text-gray-700">Chassis #</label>
                                        <input type="text" name="chassis_number" class="form-control rounded-md border-gray-300">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold text-gray-700">Engine #</label>
                                        <input type="text" name="engine_number" class="form-control rounded-md border-gray-300">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold text-gray-700">Truck Type</label>
                                        <input type="text" name="truck_type" class="form-control rounded-md border-gray-300" placeholder="e.g. Tractor, Tipper">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold text-gray-700">Model Number</label>
                                        <input type="text" name="model_number" class="form-control rounded-md border-gray-300">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold text-gray-700">Model Year</label>
                                        <input type="number" name="model_year" class="form-control rounded-md border-gray-300">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="currency" class="form-label fw-semibold text-gray-700">Currency</label>
                                <select class="form-select rounded-md border-gray-300" id="currency" name="currency">
                                    <option value="USD" <?php echo ($post['currency'] ?? 'USD') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                    <option value="TZS" <?php echo ($post['currency'] ?? '') === 'TZS' ? 'selected' : ''; ?>>TZS (TSh)</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="buying_price" class="form-label fw-semibold text-gray-700">Buying price</label>
                                <input type="number" step="0.01" class="form-control rounded-md border-gray-300" id="buying_price" name="buying_price" value="<?php echo $val('buying_price', '0.00'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="unit_price" class="form-label fw-semibold text-gray-700">Selling price</label>
                                <input type="number" step="0.01" class="form-control rounded-md border-gray-300" id="unit_price" name="unit_price" value="<?php echo $val('unit_price', '0.00'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="reorder_level" class="form-label fw-semibold text-gray-700">Reorder level</label>
                                <input type="number" class="form-control rounded-md border-gray-300" id="reorder_level" name="reorder_level" value="<?php echo $val('reorder_level', '10'); ?>">
                                <small class="text-muted">Min quantity before low-stock alert</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="location" class="form-label fw-semibold text-gray-700">Warehouse location</label>
                                <input type="text" class="form-control rounded-md border-gray-300" id="location" name="location" placeholder="e.g. Shelf A1" value="<?php echo $val('location'); ?>">
                            </div>
                        </div>

                        <div class="mb-4 p-3 rounded-lg border border-gray-200 bg-gray-50">
                            <label class="form-label fw-semibold text-gray-700 d-block mb-2">Product images</label>
                            <input type="file" name="product_images[]" multiple accept="image/*" class="form-control rounded-md border-gray-300" id="imageInput">
                            <div class="form-text text-gray-600">JPG, PNG, GIF, WebP. First image is primary.</div>
                            <div id="imagePreview" class="d-flex flex-wrap mt-2 gap-2"></div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 pt-2 border-top border-gray-100">
                            <button type="submit" class="btn pa-btn-primary rounded-md px-4 py-2 fw-semibold border-0">
                                <i class="fas fa-save me-2"></i>Save product
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary rounded-md px-4 py-2">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php if ($productAddSuccess): ?>
<script>
(function () {
    var sheet = document.getElementById('productAddSuccessSheet');
    var backdrop = document.getElementById('productAddSuccessBackdrop');
    var btn = document.getElementById('productAddSuccessDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;
    var indexHref = 'index.php';

    function goIndex() {
        window.location.href = indexHref;
    }

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('product-add-success-sheet-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(function () {
            closeSheet(true);
        }, 6000);
    }

    function closeSheet(fromTimer) {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('product-add-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) {
                sheet.setAttribute('aria-hidden', 'true');
            }
            if (fromTimer) goIndex();
        }, 350);
    }

    function init() {
        if (mq.matches) openSheet();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    mq.addEventListener('change', function (e) {
        if (!e.matches) {
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('product-add-success-sheet-open');
        }
    });

    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            window.clearTimeout(autoTimer);
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('product-add-success-sheet-open');
            goIndex();
        });
    }
    backdrop.addEventListener('click', function () {
        closeSheet(true);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) {
            closeSheet(true);
        }
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Swal === 'undefined') return;
    if (!window.matchMedia('(min-width: 768px)').matches) return;
    var d = <?php echo json_encode($productAddSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (!d) return;
    Swal.fire({
        title: d.title || 'Success',
        text: d.message || '',
        icon: d.variant === 'warning' ? 'warning' : 'success',
        confirmButtonColor: '#2563EB',
        confirmButtonText: 'OK'
    }).then(function () {
        window.location.href = 'index.php';
    });
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('imageInput');
    if (!input) return;
    input.addEventListener('change', function() {
        var previewContainer = document.getElementById('imagePreview');
        if (!previewContainer) return;
        previewContainer.innerHTML = '';
        if (!this.files) return;
        for (var i = 0; i < this.files.length; i++) {
            (function(file) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var div = document.createElement('div');
                    div.className = 'position-relative';
                    div.innerHTML = '<img src="' + e.target.result + '" class="img-thumbnail rounded border border-gray-200" style="width: 100px; height: 100px; object-fit: cover;" alt="">';
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            })(this.files[i]);
        }
    });
});
</script>

<script>
function toggleProductFields(type) {
    const truckSection = document.getElementById('truck_specs_section');
    const labelName = document.getElementById('label_name');
    const nameInput = document.getElementById('name');
    const productCodeInput = document.getElementById('product_code_input');
    
    const autoCodeSpare = '<?= $auto_code_spare ?>';
    const autoCodeTruck = '<?= $auto_code_truck ?>';

    if (type === 'truck') {
        if (truckSection) truckSection.style.display = 'block';
        if (labelName) labelName.innerHTML = 'Truck / Vehicle Name <span class="text-danger">*</span>';
        if (nameInput) nameInput.placeholder = 'e.g. Volvo FH16 2020';
        if (productCodeInput) productCodeInput.value = autoCodeTruck;
    } else {
        if (truckSection) truckSection.style.display = 'none';
        if (labelName) labelName.innerHTML = 'Product name <span class="text-danger">*</span>';
        if (nameInput) nameInput.placeholder = 'e.g. Brake Pad Set';
        if (productCodeInput) productCodeInput.value = autoCodeSpare;
    }
}
</script>

<?php include '../../includes/footer.php'; ?>
