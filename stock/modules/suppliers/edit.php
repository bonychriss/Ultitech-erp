<?php
// stock/modules/suppliers/edit.php — React supplier edit desk
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

if (!isset($_GET['id'])) {
    redirect('index.php');
}

$id = (int) $_GET['id'];
if ($id <= 0) {
    redirect('index.php');
}

$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    flash('success', 'Supplier not found', 'danger');
    redirect('index.php');
}

$companyCtx = function_exists('stock_image_company_context')
    ? stock_image_company_context()
    : ['slug' => (string) ($_SESSION['company_slug'] ?? ''), 'company_id' => (int) ($_SESSION['company_id'] ?? 0)];
$isRoadmasterCompany = strtolower((string) ($companyCtx['slug'] ?? '')) === 'roadmaster'
    || (int) ($companyCtx['company_id'] ?? 0) === 2;
$isUltimateStockSimple = (
    (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/ultimate/') !== false)
    || (!empty($_SESSION['company_slug']) && strtolower((string) $_SESSION['company_slug']) === 'ultimate')
);

$error = '';
$plainInput = static function ($value): string {
    $value = trim((string) ($value ?? ''));
    $value = stripslashes($value);
    // Undo accidental multi-encoding from older clean_input() saves.
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $value) {
            break;
        }
        $value = $decoded;
    }
    // Drop UTF-8 replacement chars and other control characters.
    $value = str_replace("\xEF\xBF\xBD", '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    return $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $plainInput($_POST['name'] ?? '');
    $supplier_type = $plainInput($_POST['supplier_type'] ?? 'general');
    if (!$isRoadmasterCompany) {
        $supplier_type = 'general';
    }
    $contact_person = $plainInput($_POST['contact_person'] ?? '');
    $email = $plainInput($_POST['email'] ?? '');
    $phone = $plainInput($_POST['phone'] ?? '');
    $address = $plainInput($_POST['address'] ?? '');
    $notes = $plainInput($_POST['notes'] ?? '');

    if ($name === '') {
        $error = 'Supplier Name is required.';
    } else {
        try {
            $supplierCols = $pdo->query('SHOW COLUMNS FROM suppliers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $hasCol = static function (string $col) use ($supplierCols): bool {
                return in_array($col, $supplierCols, true);
            };

            $updateMap = [
                'name' => $name,
                'supplier_type' => $supplier_type,
                'contact_person' => $contact_person,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'location' => $address,
                'notes' => $notes,
            ];

            $setParts = [];
            $params = [];
            foreach ($updateMap as $col => $val) {
                if ($hasCol($col)) {
                    $setParts[] = "{$col} = ?";
                    $params[] = $val;
                }
            }

            if (!$hasCol('name')) {
                throw new RuntimeException("Suppliers table is missing required 'name' column.");
            }

            $params[] = $id;
            $sql = 'UPDATE suppliers SET ' . implode(', ', $setParts) . ' WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            flash('success', 'Supplier updated successfully!');
            redirect('index.php?update=success');
        } catch (PDOException $e) {
            $error = 'Database Error: ' . $e->getMessage();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // Refresh row values after failed POST for form re-display
    $supplier['name'] = $name;
    $supplier['supplier_type'] = $supplier_type;
    $supplier['contact_person'] = $contact_person;
    $supplier['email'] = $email;
    $supplier['phone'] = $phone;
    $supplier['address'] = $address;
    $supplier['notes'] = $notes;
}

$formValues = [
    'name' => $plainInput($supplier['name'] ?? ''),
    'supplier_type' => $plainInput($supplier['supplier_type'] ?? 'general'),
    'contact_person' => $plainInput($supplier['contact_person'] ?? ''),
    'email' => $plainInput($supplier['email'] ?? ''),
    'phone' => $plainInput($supplier['phone'] ?? ''),
    'address' => $plainInput($supplier['address'] ?? $supplier['location'] ?? ''),
    'notes' => $plainInput($supplier['notes'] ?? ''),
];
if (!$isRoadmasterCompany) {
    $formValues['supplier_type'] = 'general';
}

$supplierTypeNormalized = strtolower($formValues['supplier_type']);
$showDepartment = $isRoadmasterCompany
    && $supplierTypeNormalized !== 'general'
    && $supplierTypeNormalized !== '';
if (isset($_GET['type']) && strtolower((string) $_GET['type']) === 'general') {
    $showDepartment = false;
}

$supplierCodeDisplay = '';
foreach (['supplier_code', 'code', 'reference_no'] as $codeCol) {
    if (!empty($supplier[$codeCol])) {
        $supplierCodeDisplay = trim((string) $supplier[$codeCol]);
        break;
    }
}
if ($supplierCodeDisplay === '') {
    $supplierCodeDisplay = 'SUP-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

$initials = function_exists('stock_profile_initials')
    ? stock_profile_initials($formValues['name'])
    : strtoupper(substr($formValues['name'] !== '' ? $formValues['name'] : 'SU', 0, 2));
$avatarKey = $id . '|' . $formValues['name'];
$avatarStyle = function_exists('stock_profile_avatar_style')
    ? stock_profile_avatar_style($avatarKey)
    : 'background:#7c3aed;color:#fff;';

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

$page_title = 'Edit Supplier';
$employeeHeaderTitle = 'Edit supplier';
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
</style>
<main class="main-content products-desk-react-root">
    <noscript>
        <div class="alert alert-warning m-3">JavaScript is required to edit suppliers.</div>
    </noscript>
    <script>
        window.__STOCK_PAGE__ = <?= json_encode([
            'page' => 'supplier-edit',
            'data' => [
                'isRoadmaster' => $isRoadmasterCompany,
                'isUltimate' => $isUltimateStockSimple,
                'showDepartment' => $showDepartment,
                'indexUrl' => 'index.php',
                'viewUrl' => 'view.php',
                'formAction' => 'edit.php?id=' . $id,
                'error' => $error,
                'supplier' => [
                    'id' => $id,
                    'name' => $formValues['name'],
                    'supplier_type' => $formValues['supplier_type'],
                    'contact_person' => $formValues['contact_person'],
                    'email' => $formValues['email'],
                    'phone' => $formValues['phone'],
                    'address' => $formValues['address'],
                    'notes' => $formValues['notes'],
                    'supplier_code' => $supplierCodeDisplay,
                    'initials' => $initials,
                    'avatar_style' => $avatarStyle,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)) ?: '{"page":"supplier-edit","data":{}}' ?>;
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.css?v=<?= (int) $assetVersion ?>">
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>stock-ui/dist/assets/stock-ui.js?v=<?= (int) $assetVersion ?>"></script>
</main>
<?php include '../../includes/footer.php'; ?>
