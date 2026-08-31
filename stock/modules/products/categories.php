<?php
// stock/modules/products/categories.php — React categories desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
require_once __DIR__ . '/category_schema.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$stockCategoryCols = stock_categories_ensure_image_columns($pdo);

$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

// --- Action Handlers ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'toggle_featured') {
        header('Content-Type: application/json');
        if (empty($stockCategoryCols['is_featured'])) {
            echo json_encode(['success' => false, 'message' => 'Column is_featured is not available on this database.']);
            exit;
        }
        $id = (int) $_POST['id'];
        $val = (int) $_POST['featured'];
        $pdo->prepare('UPDATE categories SET is_featured = ? WHERE id = ?')->execute([$val, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
    $orderLevel = (int) ($_POST['order_level'] ?? 0);
    $level = (int) ($_POST['level'] ?? 0);
    $item_type = $_POST['item_type'] ?? 'general';
    if ($isUltimateStockSimple) {
        $item_type = 'general';
    }
    $status = $_POST['status'] ?? 'active';

    $iconName = $_POST['old_icon'] ?? null;
    $coverName = $_POST['old_cover'] ?? null;
    $bannerName = $_POST['old_banner'] ?? null;
    if ($iconName === '') {
        $iconName = null;
    }
    if ($coverName === '') {
        $coverName = null;
    }
    if ($bannerName === '') {
        $bannerName = null;
    }
    $uploadDir = function_exists('stock_category_upload_dir')
        ? stock_category_upload_dir()
        : (__DIR__ . '/../../uploads/categories/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $uploadDir = rtrim(str_replace('\\', '/', (string) $uploadDir), '/') . '/';

    $saveCategoryUpload = static function (array $file, string $prefix) use ($uploadDir): ?string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return null;
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1) {
            return null;
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) ($file['name'] ?? 'image.jpg')));
        if ($safe === '' || $safe === '_' || $safe === '.') {
            $safe = 'image.jpg';
        }
        $destName = $prefix . time() . '_' . $safe;
        $dest = $uploadDir . $destName;
        if (!@move_uploaded_file($tmp, $dest)) {
            return null;
        }
        if (!is_file($dest) || @filesize($dest) < 1) {
            @unlink($dest);
            return null;
        }

        return $destName;
    };

    if (isset($_FILES['icon']) && is_array($_FILES['icon'])) {
        $saved = $saveCategoryUpload($_FILES['icon'], 'icon_');
        if ($saved !== null) {
            $iconName = $saved;
        }
    }
    if (isset($_FILES['cover_image']) && is_array($_FILES['cover_image'])) {
        $saved = $saveCategoryUpload($_FILES['cover_image'], 'cover_');
        if ($saved !== null) {
            $coverName = $saved;
        }
    }
    if (isset($_FILES['banner']) && is_array($_FILES['banner'])) {
        $saved = $saveCategoryUpload($_FILES['banner'], 'banner_');
        if ($saved !== null) {
            $bannerName = $saved;
        }
    }

    if ($name === '') {
        flash('success', 'Category name is required.', 'danger');
        redirect('categories.php');
    }

    if ($action == 'add') {
        $fields = [];
        $placeholders = [];
        $values = [];
        $fields[] = 'name';
        $placeholders[] = '?';
        $values[] = $name;
        if (!empty($stockCategoryCols['description'])) {
            $fields[] = 'description';
            $placeholders[] = '?';
            $values[] = $description;
        }
        if (!empty($stockCategoryCols['parent_id'])) {
            $fields[] = 'parent_id';
            $placeholders[] = '?';
            $values[] = $parentId;
        }
        if (!empty($stockCategoryCols['order_level'])) {
            $fields[] = 'order_level';
            $placeholders[] = '?';
            $values[] = $orderLevel;
        }
        if (!empty($stockCategoryCols['level'])) {
            $fields[] = 'level';
            $placeholders[] = '?';
            $values[] = $level;
        }
        if (!empty($stockCategoryCols['status'])) {
            $fields[] = 'status';
            $placeholders[] = '?';
            $values[] = $status;
        }
        if (!empty($stockCategoryCols['item_type'])) {
            $fields[] = 'item_type';
            $placeholders[] = '?';
            $values[] = $item_type;
        }
        if (!empty($stockCategoryCols['icon']) && $iconName !== null) {
            $fields[] = 'icon';
            $placeholders[] = '?';
            $values[] = $iconName;
        }
        if (!empty($stockCategoryCols['cover_image']) && $coverName !== null) {
            $fields[] = 'cover_image';
            $placeholders[] = '?';
            $values[] = $coverName;
        }
        if (!empty($stockCategoryCols['banner']) && $bannerName !== null) {
            $fields[] = 'banner';
            $placeholders[] = '?';
            $values[] = $bannerName;
        }
        $sql = 'INSERT INTO categories (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $pdo->prepare($sql)->execute($values);
        flash('success', 'Category created successfully!');
    } elseif ($action == 'edit') {
        $id = (int) $_POST['id'];
        $set = [];
        $values = [];
        $set[] = 'name = ?';
        $values[] = $name;
        if (!empty($stockCategoryCols['description'])) {
            $set[] = 'description = ?';
            $values[] = $description;
        }
        if (!empty($stockCategoryCols['parent_id'])) {
            $set[] = 'parent_id = ?';
            $values[] = $parentId;
        }
        if (!empty($stockCategoryCols['order_level'])) {
            $set[] = 'order_level = ?';
            $values[] = $orderLevel;
        }
        if (!empty($stockCategoryCols['level'])) {
            $set[] = 'level = ?';
            $values[] = $level;
        }
        if (!empty($stockCategoryCols['status'])) {
            $set[] = 'status = ?';
            $values[] = $status;
        }
        if (!empty($stockCategoryCols['item_type'])) {
            $set[] = 'item_type = ?';
            $values[] = $item_type;
        }
        if (!empty($stockCategoryCols['icon'])) {
            $set[] = 'icon = ?';
            $values[] = $iconName;
        }
        if (!empty($stockCategoryCols['cover_image'])) {
            $set[] = 'cover_image = ?';
            $values[] = $coverName;
        }
        if (!empty($stockCategoryCols['banner'])) {
            $set[] = 'banner = ?';
            $values[] = $bannerName;
        }
        $values[] = $id;
        $sql = 'UPDATE categories SET ' . implode(', ', $set) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($values);
        flash('success', 'Category updated successfully!');
    }
    redirect('categories.php');
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    try {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        flash('success', 'Category deleted successfully!');
    } catch (Exception $e) {
        flash('success', 'Cannot delete: Linked items exist.', 'danger');
    }
    redirect('categories.php');
}

// --- Data ---
$parentJoin = '';
$parentSelect = 'NULL AS parent_name';
if (!empty($stockCategoryCols['parent_id'])) {
    $parentJoin = ' LEFT JOIN categories p ON c.parent_id = p.id ';
    $parentSelect = 'p.name AS parent_name';
}
$orderParts = [];
if (!empty($stockCategoryCols['order_level'])) {
    $orderParts[] = 'c.order_level ASC';
}
$orderParts[] = 'c.name ASC';
$orderSql = implode(', ', $orderParts);

$stmt = $pdo->query("SELECT c.*, {$parentSelect} FROM categories c {$parentJoin} ORDER BY {$orderSql}");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
// Static assets under /{company}/stock/uploads/ 404 — always use real /stock/ or /storage/ URLs for images.
if (function_exists('app_url')) {
    $assetBase = rtrim(app_url('/stock'), '/') . '/';
} else {
    $assetBase = preg_replace('#/([A-Za-z0-9-]+)/stock/#', '/stock/', $base) ?: $base;
}
if (strpos($assetBase, '/stock/') === false) {
    $assetBase = $base;
}

$categoryPayload = [];
foreach ($categories as $row) {
    $icon = (string) ($row['icon'] ?? '');
    $cover = (string) ($row['cover_image'] ?? '');
    $banner = (string) ($row['banner'] ?? '');
    $iconUrl = $icon !== '' && function_exists('stock_category_image_url')
        ? stock_category_image_url($icon)
        : '';
    $coverUrl = $cover !== '' && function_exists('stock_category_image_url')
        ? stock_category_image_url($cover)
        : '';
    $bannerUrl = $banner !== '' && function_exists('stock_category_image_url')
        ? stock_category_image_url($banner)
        : '';
    $categoryPayload[] = [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== ''
            ? (int) $row['parent_id']
            : null,
        'parent_name' => $row['parent_name'] ?? null,
        'order_level' => (int) ($row['order_level'] ?? 0),
        'level' => (int) ($row['level'] ?? 0),
        'item_type' => (string) ($row['item_type'] ?? 'general'),
        'status' => (string) ($row['status'] ?? 'active'),
        'is_featured' => !empty($row['is_featured']),
        'icon' => $icon,
        'cover_image' => $cover,
        'banner' => $banner,
        'icon_url' => $iconUrl,
        'cover_url' => $coverUrl,
        'banner_url' => $bannerUrl,
    ];
}

$page_title = 'Categories';
$employeeHeaderTitle = 'Categories';
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk';

$assetVersion = max(
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.js') ?: 0),
    (int) (@filemtime(__DIR__ . '/../../stock-ui/dist/assets/stock-ui.css') ?: 0),
    time()
);

include '../../includes/header.php';
?>
<style>
body.page-products-desk.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-products-desk.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-products-desk,
body.page-products-desk.dashboard,
body.page-products-desk .layout-main-wrapper,
body.page-products-desk .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-products-desk .employee-header.employee-header--products-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-products-desk .employee-header--products-desk::after { display: none !important; }
body.page-products-desk .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    font-size: clamp(1.05rem, 2vw, 1.35rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: min(42rem, 70vw);
}
body.page-products-desk .employee-header--products-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.products-desk-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.products-desk-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk { padding: 0 0.75rem !important; }
    main.main-content.products-desk-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-products-desk,
html[data-theme="dark"] body.page-products-desk.dashboard,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-products-desk main.main-content.products-desk-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    color: #f8fafc !important;
}
body.page-products-desk .cat-desk-btn,
body.page-products-desk button.cat-desk-btn,
body.page-products-desk a.cat-desk-btn {
    border-radius: 9999px !important;
}
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to manage categories.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'categories-list',
            'data' => [
                'isUltimate' => $isUltimateStockSimple,
                'categories' => $categoryPayload,
                'hasItemType' => !empty($stockCategoryCols['item_type']),
                'hasParent' => !empty($stockCategoryCols['parent_id']),
                'hasOrder' => !empty($stockCategoryCols['order_level']),
                'hasLevel' => !empty($stockCategoryCols['level']),
                'hasFeatured' => !empty($stockCategoryCols['is_featured']),
                'hasStatus' => !empty($stockCategoryCols['status']),
                'hasIcon' => !empty($stockCategoryCols['icon']),
                'hasCover' => !empty($stockCategoryCols['cover_image']),
                'hasBanner' => !empty($stockCategoryCols['banner']),
                'baseUrl' => $assetBase,
                'formAction' => 'categories.php',
                'addCategoryUrl' => 'add_category.php',
                'editCategoryUrl' => 'edit_category.php',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"categories-list","data":{"categories":[]}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
