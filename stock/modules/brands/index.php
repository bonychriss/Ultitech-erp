<?php
// stock/modules/brands/index.php — React brands desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

$brandCols = [];
try {
    foreach ($pdo->query('SHOW COLUMNS FROM `brands`') as $row) {
        if (!empty($row['Field'])) {
            $brandCols[$row['Field']] = true;
        }
    }
} catch (Throwable $e) {
    $brandCols = [];
}

$saveBrandLogo = static function (array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    if ((int) ($file['size'] ?? 0) < 1) {
        return null;
    }
    $uploadDir = function_exists('stock_brand_upload_dir')
        ? stock_brand_upload_dir()
        : (__DIR__ . '/../../uploads/brands');
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    $uploadDir = rtrim(str_replace('\\', '/', (string) $uploadDir), '/') . '/';
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        $ext = 'png';
    }
    $destName = 'brand_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === '' && isset($_POST['add_brand'])) {
        $action = 'add';
    }
    if ($action === '' && isset($_POST['update_brand'])) {
        $action = 'edit';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $brandType = (string) ($_POST['brand_type'] ?? ($isUltimateStockSimple ? 'general' : 'spare_part'));
    if (!in_array($brandType, ['spare_part', 'truck', 'general', 'vehicle'], true)) {
        $brandType = $isUltimateStockSimple ? 'general' : 'spare_part';
    }
    if ($brandType === 'vehicle') {
        $brandType = 'truck';
    }
    if ($isUltimateStockSimple) {
        $brandType = 'general';
    }
    $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
    $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));

    if ($action === 'add' || $action === 'edit') {
        if ($name === '') {
            flash('success', 'Brand name is required.', 'danger');
            redirect('index.php');
        }

        $logo = null;
        if (isset($_FILES['logo']) && is_array($_FILES['logo'])) {
            $logo = $saveBrandLogo($_FILES['logo']);
        }

        try {
            if ($action === 'add') {
                $fields = ['name'];
                $placeholders = ['?'];
                $values = [$name];
                if (!empty($brandCols['brand_type'])) {
                    $fields[] = 'brand_type';
                    $placeholders[] = '?';
                    $values[] = $brandType;
                }
                if (!empty($brandCols['logo']) && $logo !== null) {
                    $fields[] = 'logo';
                    $placeholders[] = '?';
                    $values[] = $logo;
                }
                if (!empty($brandCols['meta_title'])) {
                    $fields[] = 'meta_title';
                    $placeholders[] = '?';
                    $values[] = $metaTitle;
                }
                if (!empty($brandCols['meta_description'])) {
                    $fields[] = 'meta_description';
                    $placeholders[] = '?';
                    $values[] = $metaDescription;
                }
                $sql = 'INSERT INTO brands (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $pdo->prepare($sql)->execute($values);
                flash('success', 'Brand added successfully!');
            } else {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) {
                    flash('success', 'Invalid brand.', 'danger');
                    redirect('index.php');
                }
                $stmt = $pdo->prepare('SELECT * FROM brands WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    flash('success', 'Brand not found.', 'danger');
                    redirect('index.php');
                }
                $logoName = (string) ($existing['logo'] ?? '');
                if ($logo !== null) {
                    if ($logoName !== '') {
                        $oldPaths = [
                            __DIR__ . '/../../uploads/brands/' . $logoName,
                        ];
                        if (function_exists('stock_brand_upload_dir')) {
                            $oldPaths[] = rtrim(str_replace('\\', '/', (string) stock_brand_upload_dir()), '/') . '/' . $logoName;
                        }
                        foreach ($oldPaths as $oldPath) {
                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }
                    }
                    $logoName = $logo;
                }
                $set = ['name = ?'];
                $values = [$name];
                if (!empty($brandCols['brand_type'])) {
                    $set[] = 'brand_type = ?';
                    $values[] = $brandType;
                }
                if (!empty($brandCols['logo'])) {
                    $set[] = 'logo = ?';
                    $values[] = $logoName !== '' ? $logoName : null;
                }
                if (!empty($brandCols['meta_title'])) {
                    $set[] = 'meta_title = ?';
                    $values[] = $metaTitle;
                }
                if (!empty($brandCols['meta_description'])) {
                    $set[] = 'meta_description = ?';
                    $values[] = $metaDescription;
                }
                $values[] = $id;
                $pdo->prepare('UPDATE brands SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
                flash('success', 'Brand updated successfully!');
            }
        } catch (PDOException $e) {
            flash('success', 'Could not save brand.', 'danger');
        }
        redirect('index.php');
    }
}

$stmt = $pdo->query('SELECT * FROM brands ORDER BY name ASC');
$brands = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$base = isset($stockBasePath) && $stockBasePath !== ''
    ? rtrim((string) $stockBasePath, '/') . '/'
    : (function_exists('app_url') ? rtrim(app_url('/stock'), '/') . '/' : '/stock/');
if (function_exists('app_url')) {
    $assetBase = rtrim(app_url('/stock'), '/') . '/';
} else {
    $assetBase = preg_replace('#/([A-Za-z0-9-]+)/stock/#', '/stock/', $base) ?: $base;
}
if (strpos($assetBase, '/stock/') === false) {
    $assetBase = $base;
}

$brandPayload = [];
foreach ($brands as $row) {
    $logo = (string) ($row['logo'] ?? '');
    $logoUrl = $logo !== '' && function_exists('stock_brand_image_url')
        ? stock_brand_image_url($logo)
        : '';
    $brandPayload[] = [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'brand_type' => (string) ($row['brand_type'] ?? ($isUltimateStockSimple ? 'general' : 'spare_part')),
        'logo' => $logo,
        'logo_url' => $logoUrl,
        'meta_title' => (string) ($row['meta_title'] ?? ''),
        'meta_description' => (string) ($row['meta_description'] ?? ''),
    ];
}

$toast = '';
if (isset($_GET['delete']) && $_GET['delete'] === 'success') {
    $toast = 'deleted';
} elseif (isset($_GET['update']) && $_GET['update'] === 'success') {
    $toast = 'updated';
}

$page_title = 'Brands';
$employeeHeaderTitle = 'Brands';
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
body.page-products-desk .brand-desk-btn,
body.page-products-desk button.brand-desk-btn,
body.page-products-desk a.brand-desk-btn {
    border-radius: 9999px !important;
}
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to manage brands.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'brands-list',
            'data' => [
                'isUltimate' => $isUltimateStockSimple,
                'brands' => $brandPayload,
                'hasBrandType' => !empty($brandCols['brand_type']),
                'hasLogo' => !empty($brandCols['logo']),
                'hasMeta' => !empty($brandCols['meta_title']) || !empty($brandCols['meta_description']),
                'baseUrl' => $assetBase,
                'formAction' => 'index.php',
                'deleteUrl' => 'delete.php',
                'toast' => $toast,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"brands-list","data":{"brands":[]}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
