<?php
/**
 * Create product (JSON / multipart).
 * POST stock/modules/products/api/create-product.php
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
    // Browsers that followed a slug 302 after POST arrive as GET — send them back to the form.
    if (!$wantsJson && function_exists('stock_desk_url')) {
        header('Location: ' . stock_desk_url('product-create'));
        exit;
    }
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed — use POST to create a product.']);
    exit;
}

$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

$requireProductImage = $isUltimateStockSimple
    || (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/roadmaster/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'roadmaster');

$year = date('Y');

function stock_products_api_generate_code(PDO $pdo, string $prefix): string
{
    $stmtMax = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(product_code, '-', -1) AS UNSIGNED)) FROM products WHERE product_code LIKE ?"
    );
    $stmtMax->execute([$prefix . '%']);
    $maxNum = $stmtMax->fetchColumn();
    $nextNum = $maxNum ? ((int) $maxNum + 1) : 1;
    return $prefix . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);
}

function stock_products_api_has_column(PDO $pdo, string $column, bool $refresh = false): bool
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

function stock_products_api_ensure_column(PDO $pdo, string $column, string $definition): void
{
    if (stock_products_api_has_column($pdo, $column)) {
        return;
    }
    try {
        $safe = str_replace('`', '``', $column);
        $pdo->exec('ALTER TABLE products ADD COLUMN `' . $safe . '` ' . $definition);
        stock_products_api_has_column($pdo, $column, true);
    } catch (Throwable $e) {
        /* ignore */
    }
}

if ($isUltimateStockSimple) {
    $type = 'general';
} else {
    $type = (string) ($_POST['register_type'] ?? 'spare_part');
}

$name = trim((string) ($_POST['name'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$category_id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
$supplier_id = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
$unit_price = (float) ($_POST['unit_price'] ?? 0);
$buying_price = (float) ($_POST['buying_price'] ?? 0);
$wholesale_price = (float) ($_POST['wholesale_price'] ?? 0);
$reorder_level = (int) ($_POST['reorder_level'] ?? 10);
$current_stock = (int) ($_POST['current_stock'] ?? 0);
$location = trim((string) ($_POST['location'] ?? ''));
$brand = trim((string) ($_POST['brand'] ?? ''));
$currency = (string) ($_POST['currency'] ?? 'USD');
if (!in_array($currency, ['USD', 'TZS', 'EUR'], true)) {
    $currency = 'USD';
}

$compatibility = trim((string) ($_POST['compatible_truck_model'] ?? ''));
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

$isTruckCreate = !$isUltimateStockSimple && ($type === 'truck' || $type === 'vehicle');

if ($isTruckCreate) {
    stock_products_api_ensure_column($pdo, 'drive_configuration', 'VARCHAR(50) NULL');
    stock_products_api_ensure_column($pdo, 'fuel_type', 'VARCHAR(40) NULL');
    stock_products_api_ensure_column($pdo, 'cab_details', 'TEXT NULL');
    stock_products_api_ensure_column($pdo, 'tire_model', 'VARCHAR(100) NULL');
    stock_products_api_ensure_column($pdo, 'curb_weight_kg', 'INT NULL');
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
} elseif ($name === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $isUltimateStockSimple ? 'Product name is required.' : 'Product/Vehicle name is required.',
    ]);
    exit;
}

if (empty($category_id)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Category is required.']);
    exit;
}

if ($isUltimateStockSimple && $description === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Description is required.']);
    exit;
}

if ($isUltimateStockSimple && $brand === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Brand is required.']);
    exit;
}

if (!$isUltimateStockSimple && !$isTruckCreate && $oem_number === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'OEM / Part no is required.']);
    exit;
}

if ($requireProductImage) {
    $hasUploadedImage = false;
    if (isset($_FILES['product_images']) && is_array($_FILES['product_images']['name'] ?? null)) {
        $fileNames = $_FILES['product_images']['name'];
        $fileErrors = $_FILES['product_images']['error'] ?? [];
        foreach ($fileNames as $i => $origName) {
            if (trim((string) $origName) === '') {
                continue;
            }
            if ((int) ($fileErrors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $hasUploadedImage = true;
                break;
            }
        }
    }
    if (!$hasUploadedImage) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please add at least one product image before saving.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    if ($isUltimateStockSimple) {
        $product_code = stock_products_api_generate_code($pdo, "PRD-{$year}-");
        $item_type = 'general';
    } else {
        $product_code = ($type === 'truck')
            ? stock_products_api_generate_code($pdo, "TRK-{$year}-")
            : stock_products_api_generate_code($pdo, "PRD-{$year}-");
        $item_type = ($type === 'truck') ? 'vehicle' : 'spare_part';
    }

    $insertCols = ['product_code', 'name', 'description', 'category_id', 'supplier_id', 'unit_price'];
    $insertVals = [$product_code, $name, $description, $category_id, $supplier_id, $unit_price];

    $optionalFields = [
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
        'item_type' => $item_type,
        'oem_number' => ($oem_number !== '' ? $oem_number : null),
        'status' => 'active',
    ];

    foreach ($optionalFields as $column => $value) {
        if (stock_products_api_has_column($pdo, $column)) {
            $insertCols[] = $column;
            $insertVals[] = $value;
        }
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $quotedCols = implode(', ', array_map(static function ($column) {
        return '`' . $column . '`';
    }, $insertCols));
    $stmt = $pdo->prepare("INSERT INTO products ({$quotedCols}) VALUES ({$placeholders})");
    $stmt->execute($insertVals);
    $product_id = (int) $pdo->lastInsertId();

    $productUploadBase = function_exists('stock_product_upload_base_dir')
        ? stock_product_upload_base_dir()
        : (__DIR__ . '/../../../uploads');
    $imageProcessor = new ImageProcessor($productUploadBase ?: (__DIR__ . '/../../../uploads'));

    if (!empty($_POST['gallery_images'])) {
        $galleryImages = json_decode((string) $_POST['gallery_images'], true);
        if (is_array($galleryImages)) {
            foreach ($galleryImages as $index => $imgName) {
                $imgName = trim((string) $imgName);
                if ($imgName === '') {
                    continue;
                }
                $is_primary = ($index === 0) ? 1 : 0;
                $pdo->prepare(
                    'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, ?, ?)'
                )->execute([$product_id, $imgName, $is_primary, $_SESSION['user_id'] ?? null]);
                if ($is_primary) {
                    $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$imgName, $product_id]);
                }
            }
        }
    }

    if (isset($_FILES['product_images']) && is_array($_FILES['product_images']['name'] ?? null)) {
        $files = $_FILES['product_images'];
        $count = count($files['name']);
        $imageErrors = [];
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
            try {
                $filename = $imageProcessor->processUploadedImage($tmp, $product_id);
            } catch (Throwable $imgEx) {
                $imageErrors[] = $imgEx->getMessage();
                continue;
            }
            if ($filename === '' || $filename === false || $filename === null) {
                continue;
            }
            $hasPrimaryStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
            $hasPrimaryStmt->execute([$product_id]);
            $hasPrimary = (int) $hasPrimaryStmt->fetchColumn();
            $is_primary = ($hasPrimary === 0) ? 1 : 0;
            $pdo->prepare(
                'INSERT INTO product_images (product_id, image_name, is_primary, uploaded_by) VALUES (?, ?, ?, ?)'
            )->execute([$product_id, $filename, $is_primary, $_SESSION['user_id'] ?? null]);
            if ($is_primary) {
                $pdo->prepare('UPDATE products SET main_image = ? WHERE id = ?')->execute([$filename, $product_id]);
            }
        }
    }

    $pdo->prepare('INSERT INTO stock (product_id, quantity, location) VALUES (?, ?, ?)')
        ->execute([$product_id, max(0, $current_stock), $location]);

    $pdo->commit();

    $redirect = function_exists('stock_desk_url')
        ? stock_desk_url('products', ['created' => '1', 'created_id' => $product_id])
        : ((function_exists('app_url') ? rtrim(app_url('/stock'), '/') : '/stock')
            . '/modules/products/index.php?created=1&created_id=' . $product_id);

    $payload = [
        'ok' => true,
        'id' => $product_id,
        'product_code' => $product_code,
        'redirect' => $redirect,
    ];
    if (!empty($imageErrors)) {
        $payload['image_warning'] = $imageErrors[0];
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
