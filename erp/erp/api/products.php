<?php
require_once '../../includes/functions.php';

// Prevent HTML error output from breaking JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    global $pdo;
    // Ensure image_path column exists for product images
    // 1. Ensure barcode column exists (Must be first as image_path depends on it)
    try {
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_products' AND COLUMN_NAME = 'barcode'");
        $chk->execute();
        if (!$chk->fetch()) {
            $pdo->exec("ALTER TABLE erp_products ADD COLUMN barcode VARCHAR(100) DEFAULT NULL AFTER sku");
        }
    } catch (Throwable $schemaEx) { /* ignore */
    }

    // 2. Ensure image_path column exists (Depends on barcode)
    try {
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_products' AND COLUMN_NAME = 'image_path'");
        $chk->execute();
        if (!$chk->fetch()) {
            $pdo->exec("ALTER TABLE erp_products ADD COLUMN image_path VARCHAR(255) NULL AFTER barcode");
        }
    } catch (Throwable $schemaEx) { /* ignore */
    }

    // 3. Ensure supplier_id column exists
    try {
        $chk = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_products' AND COLUMN_NAME = 'supplier_id'");
        $chk->execute();
        if (!$chk->fetch()) {
            $pdo->exec("ALTER TABLE erp_products ADD COLUMN supplier_id INT NULL AFTER category_id");
            $pdo->exec("ALTER TABLE erp_products ADD CONSTRAINT fk_product_supplier FOREIGN KEY (supplier_id) REFERENCES erp_suppliers(id) ON DELETE SET NULL");
        }
    } catch (Throwable $schemaEx) { /* ignore */
    }

    // 4. Ensure Audit Columns (created_by, created_at, updated_at) exist
    // Using information_schema for robust checking
    try {
        $checkCol = function ($col, $def) use ($pdo) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_products' AND COLUMN_NAME = ?");
            $stmt->execute([$col]);
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("ALTER TABLE erp_products ADD COLUMN $col $def");
            }
        };

        $checkCol('created_by', "INT(11) DEFAULT NULL");
        $checkCol('created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        // For updated_at, we just add it if missing. We don't force ON UPDATE definition if it exists but differs.
        $checkCol('updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    } catch (Throwable $schemaEx) {
        // Log error silently if possible, or carry on
        error_log("Schema Check Error: " . $schemaEx->getMessage());
    }

    // Handle optional image upload
    $uploadedPath = null;
    if (!empty($_FILES['image']) && isset($_FILES['image']['error']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $maxBytes = 2 * 1024 * 1024; // 2MB
            if ($_FILES['image']['size'] > $maxBytes) {
                throw new Exception('Image too large. Max 2MB');
            }
            $tmp = $_FILES['image']['tmp_name'];
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            $mime = $finfo ? finfo_file($finfo, $tmp) : mime_content_type($tmp);
            if ($finfo)
                finfo_close($finfo);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                throw new Exception('Invalid image format. Use JPG, PNG, GIF, or WEBP');
            }
            $ext = $allowed[$mime];
            $uploadDir = realpath(__DIR__ . '/../../');
            if ($uploadDir === false) {
                throw new Exception('Upload path error');
            }
            $relDir = 'uploads/products';
            $fullDir = $uploadDir . DIRECTORY_SEPARATOR . $relDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0775, true);
            }
            $base = preg_replace('/[^A-Za-z0-9_-]/', '-', $_POST['sku'] ?? 'prod');
            $filename = $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $fullDir . DIRECTORY_SEPARATOR . $filename;
            if (!@move_uploaded_file($tmp, $dest)) {
                throw new Exception('Failed to save image');
            }
            // Store path relative to web root
            $uploadedPath = $relDir . '/' . $filename;
        } else {
            throw new Exception('Upload error code: ' . (int) $_FILES['image']['error']);
        }
    }

    if ($action === 'create') {
        // Validate required fields
        if (empty($_POST['name']) || empty($_POST['unit_price'])) {
            throw new Exception('Product name and price are required');
        }

        // Check for duplicate SKU
        $stmt = $pdo->prepare("SELECT id FROM erp_products WHERE sku = ?");
        $stmt->execute([$_POST['sku']]);
        if ($stmt->fetch()) {
            throw new Exception('Product SKU already exists');
        }

        // Insert product
        $sql = "INSERT INTO erp_products (
            sku, name, description, category_id, supplier_id, unit, 
            unit_price, cost_price, stock_quantity, reorder_level, barcode, image_path, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['sku'],
            $_POST['name'],
            $_POST['description'] ?? null,
            !empty($_POST['category_id']) ? $_POST['category_id'] : null,
            !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null,
            $_POST['unit'] ?? 'pcs',
            floatval($_POST['unit_price']),
            floatval($_POST['cost_price'] ?? 0),
            floatval($_POST['stock_quantity'] ?? 0),
            floatval($_POST['reorder_level'] ?? 0),
            $_POST['barcode'] ?? null,
            $uploadedPath,
            $_SESSION['user_id'] ?? null
        ]);

        echo json_encode(['success' => true, 'message' => 'Product created successfully', 'id' => $pdo->lastInsertId()]);

    } elseif ($action === 'update') {
        // Validate required fields
        if (empty($_POST['id']) || empty($_POST['name']) || empty($_POST['unit_price'])) {
            throw new Exception('Product ID, name and price are required');
        }

        $set = "UPDATE erp_products SET 
            name = ?, description = ?, category_id = ?, supplier_id = ?, unit = ?, 
            unit_price = ?, cost_price = ?, stock_quantity = ?, reorder_level = ?, barcode = ?, status = ?";
        $params = [
            $_POST['name'],
            $_POST['description'] ?? null,
            !empty($_POST['category_id']) ? $_POST['category_id'] : null,
            !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null,
            $_POST['unit'] ?? 'pcs',
            floatval($_POST['unit_price']),
            floatval($_POST['cost_price'] ?? 0),
            floatval($_POST['stock_quantity'] ?? 0),
            floatval($_POST['reorder_level'] ?? 0),
            $_POST['barcode'] ?? null,
            $_POST['status'] ?? 'active'
        ];
        if ($uploadedPath !== null) {
            $set .= ", image_path = ?";
            $params[] = $uploadedPath;
        }
        $set .= " WHERE id = ?";
        $params[] = $_POST['id'];
        $stmt = $pdo->prepare($set);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Product updated successfully']);

    } elseif ($action === 'delete') {
        if (empty($_POST['id'])) {
            throw new Exception('Product ID is required');
        }

        // Check if product is used in invoices
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_invoice_items WHERE product_id = ?");
        $stmt->execute([$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Cannot delete product that has been sold. Mark as inactive instead.');
        }

        $stmt = $pdo->prepare("DELETE FROM erp_products WHERE id = ?");
        $stmt->execute([$_POST['id']]);

        echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
