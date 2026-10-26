<?php
/**
 * Update product (JSON / multipart).
 * POST stock/modules/products/api/update-product.php
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/../../../classes/ImageProcessor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
$wantsJson = str_contains($accept, 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($wantsJson && (!function_exists('isLoggedIn') || !isLoggedIn())) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in again.']);
    exit;
}

requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed — use POST to update a product.']);
    exit;
}

$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

function stock_products_update_has_column(PDO $pdo, string $column, bool $refresh = false): bool
{
    static $cache = null;
    if ($refresh || $cache === null) {
        try {
            $cache = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return in_array($column, $cache, true);
}

function stock_products_update_ensure_column(PDO $pdo, string $column, string $definition): void
{
    if (stock_products_update_has_column($pdo, $column)) {
        return;
    }
    try {
        $safe = str_replace('`', '``', $column);
        $pdo->exec('ALTER TABLE products ADD COLUMN `' . $safe . '` ' . $definition);
        stock_products_update_has_column($pdo, $column, true);
    } catch (Throwable $e) {
        /* ignore */
    }
}

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid product id.']);
    exit;
}

$check = $pdo->prepare('SELECT id FROM products WHERE id = ?');
$check->execute([$id]);
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product not found.']);
    exit;
}

$product_code = trim((string) ($_POST['product_code'] ?? ''));
$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$category_id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
$supplier_id = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
$unit_price = (float) ($_POST['unit_price'] ?? 0);
$buying_price = (float) ($_POST['buying_price'] ?? 0);
$wholesale_price = (float) ($_POST['wholesale_price'] ?? 0);
$reorder_level = (int) ($_POST['reorder_level'] ?? 10);
$location = trim((string) ($_POST['location'] ?? ''));
$brand = trim((string) ($_POST['brand'] ?? ''));
$currency = (string) ($_POST['currency'] ?? 'USD');
if (!in_array($currency, ['USD', 'TZS', 'EUR'], true)) {
    $currency = 'USD';
}

$compatibility = trim((string) ($_POST['compatible_truck_model'] ?? $_POST['compatibility'] ?? ''));
$oem_number = trim((string) ($_POST['oem_number'] ?? ''));
$part_condition = (string) ($_POST['part_condition'] ?? 'new');
$uom = trim((string) ($_POST['unit_of_measure'] ?? 'pcs'));

$vin = trim((string) ($_POST['vin'] ?? ''));
$chassis_number = trim((string) ($_POST['chassis_number'] ?? ''));
$engine_number = trim((string) ($_POST['engine_number'] ?? ''));
$model_year = !empty($_POST['model_year']) ? (int) $_POST['model_year'] : null;
$mileage = isset($_POST['mileage']) && $_POST['mileage'] !== '' ? (float) $_POST['mileage'] : null;
$color = trim((string) ($_POST['color'] ?? ''));
$truck_type = trim((string) ($_POST['truck_type'] ?? ''));
$model_number = trim((string) ($_POST['model_number'] ?? ''));
$engine_model = trim((string) ($_POST['engine_model'] ?? ''));
$transmission = trim((string) ($_POST['transmission_model'] ?? ''));
$drive_configuration = trim((string) ($_POST['drive_configuration'] ?? ''));
$cab_details = trim((string) ($_POST['cab_details'] ?? ''));
$fuel_type = trim((string) ($_POST['fuel_type'] ?? ''));
$tire_model = trim((string) ($_POST['tire_model'] ?? ''));
$curb_weight_kg = isset($_POST['curb_weight_kg']) && $_POST['curb_weight_kg'] !== ''
    ? (int) $_POST['curb_weight_kg']
    : null;

if ($isUltimateStockSimple) {
    $item_type = 'general';
} else {
    $type = (string) ($_POST['register_type'] ?? 'spare_part');
    if ($type === 'truck' || $type === 'vehicle') {
        $item_type = 'vehicle';
    } elseif ($type === 'general') {
        $item_type = 'general';
    } else {
        $item_type = 'spare_part';
    }
}

$isTruckUpdate = !$isUltimateStockSimple && ($item_type === 'vehicle');

if ($isTruckUpdate) {
    stock_products_update_ensure_column($pdo, 'drive_configuration', 'VARCHAR(50) NULL');
    stock_products_update_ensure_column($pdo, 'fuel_type', 'VARCHAR(40) NULL');
    stock_products_update_ensure_column($pdo, 'cab_details', 'TEXT NULL');
    stock_products_update_ensure_column($pdo, 'tire_model', 'VARCHAR(100) NULL');
    stock_products_update_ensure_column($pdo, 'curb_weight_kg', 'INT NULL');
    if ($model_number === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Model No. is required.']);
        exit;
    }
    if ($brand === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Make is required.']);
        exit;
    }
    if ($truck_type === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Truck type is required.']);
        exit;
    }
    if ($cab_details === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Cabin is required.']);
        exit;
    }
    if ($engine_model === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Engine is required.']);
        exit;
    }
    if ($transmission === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Transmission is required.']);
        exit;
    }
    if ($fuel_type === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Fuel type is required.']);
        exit;
    }
    if ($name === '') {
        $name = trim($brand . ' ' . $model_number);
    }
    if (empty($category_id)) {
        try {
            $catStmt = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $catStmt->execute([$brand]);
            $category_id = (int) ($catStmt->fetchColumn() ?: 0);
            if ($category_id < 1) {
                $category_id = (int) ($pdo->query('SELECT id FROM categories ORDER BY name ASC LIMIT 1')->fetchColumn() ?: 0);
            }
        } catch (Throwable $e) {
            $category_id = 0;
        }
    }
}

if ($name === '' || $product_code === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Product code and name are required.']);
    exit;
}

if (empty($category_id)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Category is required.']);
    exit;
}

if (!$isUltimateStockSimple && !$isTruckUpdate && $oem_number === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'OEM / Part no is required.']);
    exit;
}

$deleteIds = [];
if (!empty($_POST['delete_image_ids'])) {
    $raw = $_POST['delete_image_ids'];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $raw);
    }
    if (is_array($raw)) {
        foreach ($raw as $did) {
            $did = (int) $did;
            if ($did > 0) {
                $deleteIds[] = $did;
            }
        }
    }
}
$deleteIds = array_values(array_unique($deleteIds));
$setPrimaryId = !empty($_POST['set_primary_id']) ? (int) $_POST['set_primary_id'] : 0;

try {
    $pdo->beginTransaction();

    $updateMap = [
        'product_code' => $product_code,
        'name' => $name,
        'description' => $description,
        'category_id' => $category_id,
        'supplier_id' => $supplier_id,
        'unit_price' => $unit_price,
        'buying_price' => $buying_price,
        'wholesale_price' => $wholesale_price,
        'reorder_level' => $reorder_level,
        'currency' => $currency,
        'brand' => ($brand !== '' ? $brand : null),
        'compatibility' => ($compatibility !== '' ? $compatibility : null),
        'part_condition' => ($part_condition !== '' ? $part_condition : null),
        'unit_of_measure' => ($uom !== '' ? $uom : null),
        'vin' => ($vin !== '' ? $vin : null),
        'engine_number' => ($engine_number !== '' ? $engine_number : null),
        'chassis_number' => ($chassis_number !== '' ? $chassis_number : null),
        'model_year' => $model_year,
        'mileage' => $mileage,
        'color' => ($color !== '' ? $color : null),
        'truck_type' => ($truck_type !== '' ? $truck_type : null),
        'model_number' => ($model_number !== '' ? $model_number : null),
        'engine_model' => ($engine_model !== '' ? $engine_model : null),
        'transmission_model' => ($transmission !== '' ? $transmission : null),
        'drive_configuration' => ($drive_configuration !== '' ? $drive_configuration : null),
        'cab_details' => ($cab_details !== '' ? $cab_details : null),
        'fuel_type' => ($fuel_type !== '' ? $fuel_type : null),
        'tire_model' => ($tire_model !== '' ? $tire_model : null),
        'curb_weight_kg' => $curb_weight_kg,
        'oem_number' => ($oem_number !== '' ? $oem_number : null),
        'item_type' => $item_type,
    ];

    $setParts = [];
    $params = [];
    foreach ($updateMap as $col => $val) {
        if (stock_products_update_has_column($pdo, $col)) {
            $setParts[] = '`' . $col . '` = ?';
            $params[] = $val;
        }
    }
    if (!$setParts) {
        throw new RuntimeException('No editable product columns found.');
    }
    $params[] = $id;
    $pdo->prepare('UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($params);

    $stockCheck = $pdo->prepare('SELECT id FROM stock WHERE product_id = ?');
    $stockCheck->execute([$id]);
    if ($stockCheck->fetch()) {
        $pdo->prepare('UPDATE stock SET location = ? WHERE product_id = ?')->execute([$location, $id]);
    } else {
        $pdo->prepare('INSERT INTO stock (product_id, quantity, location) VALUES (?, 0, ?)')->execute([$id, $location]);
    }

    // Delete selected images
    foreach ($deleteIds as $imgId) {
        $imgStmt = $pdo->prepare('SELECT id, image_name, is_primary FROM product_images WHERE id = ? AND product_id = ?');
        $imgStmt->execute([$imgId, $id]);
        $img = $imgStmt->fetch(PDO::FETCH_ASSOC);
        if (!$img) {
            continue;
        }
        $pdo->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')->execute([$imgId, $id]);
        if (!empty($img['is_primary']) || true) {
            $main = $pdo->prepare('SELECT main_image FROM products WHERE id = ?');
            $main->execute([$id]);
            $mainName = (string) ($main->fetchColumn() ?: '');
            if ($mainName !== '' && $mainName === (string) ($img['image_name'] ?? '')) {
                $next = $pdo->prepare(
                    'SELECT image_name FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1'
                );
                $next->execute([$id]);
                $nextName = $next->fetchColumn();
                $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([
                    $nextName !== false ? $nextName : null,
                    $id,
                ]);
            }
        }
    }

    // Set primary
    if ($setPrimaryId > 0) {
        $ok = $pdo->prepare('SELECT image_name FROM product_images WHERE id = ? AND product_id = ?');
        $ok->execute([$setPrimaryId, $id]);
        $primaryName = $ok->fetchColumn();
        if ($primaryName) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')
                ->execute([$setPrimaryId, $id]);
            $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$primaryName, $id]);
        }
    }

    // New uploads
    if (isset($_FILES['product_images']) && is_array($_FILES['product_images']['name'] ?? null)) {
        $productUploadBase = function_exists('stock_product_upload_base_dir')
            ? stock_product_upload_base_dir()
            : (__DIR__ . '/../../../uploads');
        $imageProcessor = new ImageProcessor($productUploadBase ?: (__DIR__ . '/../../../uploads'));
        $files = $_FILES['product_images'];
        $count = count($files['name']);
        $hasPrimaryStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
        $hasPrimaryStmt->execute([$id]);
        $hasPrimary = (int) $hasPrimaryStmt->fetchColumn() > 0;

        for ($i = 0; $i < $count; $i++) {
            $origName = trim((string) ($files['name'][$i] ?? ''));
            if ($origName === '') {
                continue;
            }
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }
            $filename = $imageProcessor->processUploadedImage($tmp, $id);
            if ($filename === '' || $filename === false || $filename === null) {
                continue;
            }
            $is_primary = $hasPrimary ? 0 : 1;
            $pdo->prepare(
                'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, ?, ?)'
            )->execute([$id, $filename, $is_primary, $_SESSION['user_id'] ?? null]);
            if ($is_primary) {
                $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$filename, $id]);
                $hasPrimary = true;
            }
        }
    }

    $pdo->commit();

    $redirect = function_exists('stock_desk_url')
        ? stock_desk_url('product-edit', ['id' => $id, 'updated' => '1'])
        : ((function_exists('app_url') ? rtrim(app_url('/stock'), '/') : '/stock')
            . '/modules/products/edit.php?id=' . $id . '&updated=1');
    $viewUrl = function_exists('stock_desk_url')
        ? stock_desk_url('product-view', ['id' => $id])
        : ((function_exists('app_url') ? rtrim(app_url('/stock'), '/') : '/stock')
            . '/modules/products/view.php?id=' . $id);

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'redirect' => $redirect,
        'viewUrl' => $viewUrl,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
