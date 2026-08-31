<?php
require_once '../../config/database.php';
require_once '../../config/paths.php';
require_once '../../config/functions.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

$stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
$stmt->execute([$id]);
$s = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$s) {
    flash('success', 'Supplier not found', 'danger');
    redirect('index.php');
}

$companyCtx = function_exists('stock_image_company_context')
    ? stock_image_company_context()
    : ['slug' => (string) ($_SESSION['company_slug'] ?? ''), 'company_id' => (int) ($_SESSION['company_id'] ?? 0)];
$isRoadmasterCompany = strtolower((string) ($companyCtx['slug'] ?? '')) === 'roadmaster'
    || (int) ($companyCtx['company_id'] ?? 0) === 2;

$type = strtolower(trim((string) ($s['supplier_type'] ?? 'general')));
if ($type === '') {
    $type = 'general';
}
$name_upper = strtoupper((string) ($s['name'] ?? ''));
if ($isRoadmasterCompany && ($type === 'general' || $type === '')) {
    if (strpos($name_upper, 'MOTOR') !== false || strpos($name_upper, 'TRUCK') !== false || strpos($name_upper, 'VEHICLE') !== false || strpos($name_upper, 'JIEFANG') !== false) {
        $type = 'vehicle';
    } elseif (strpos($name_upper, 'SPARE') !== false || strpos($name_upper, 'PART') !== false) {
        $type = 'spare_part';
    }
} elseif (!$isRoadmasterCompany) {
    $type = 'general';
}

$productCount = 0;
$linkedProducts = [];
$supplierPurchaseOrders = [];
$supplyPoLineCount = 0;
$supplyPoTotalUsd = 0.0;

try {
    $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasSupplierOnProducts = in_array('supplier_id', $productCols, true);
    $hasBuyingPrice = in_array('buying_price', $productCols, true);
    $hasUnitPrice = in_array('unit_price', $productCols, true);
    $hasProductCurrency = in_array('currency', $productCols, true);

    if ($hasSupplierOnProducts) {
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = ?');
        $stmtCount->execute([$id]);
        $productCount = (int) $stmtCount->fetchColumn();

        $priceSelect = $hasBuyingPrice ? 'p.buying_price' : ($hasUnitPrice ? 'p.unit_price' : 'NULL');
        $currencySelect = $hasProductCurrency ? 'p.currency' : "'USD'";
        $sqlProducts = "SELECT p.id, p.name, p.product_code, {$priceSelect} AS unit_cost, {$currencySelect} AS currency,
                c.name AS category_name,
                COALESCE((SELECT SUM(s2.quantity) FROM stock s2 WHERE s2.product_id = p.id), 0) AS stock_qty
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.supplier_id = ?
            ORDER BY p.name ASC
            LIMIT 500";
        try {
            $stmtProducts = $pdo->prepare($sqlProducts);
            $stmtProducts->execute([$id]);
            $linkedProducts = $stmtProducts->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $stmtProducts = $pdo->prepare(
                'SELECT p.id, p.name, p.product_code, c.name AS category_name
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 WHERE p.supplier_id = ?
                 ORDER BY p.name ASC
                 LIMIT 500'
            );
            $stmtProducts->execute([$id]);
            $linkedProducts = $stmtProducts->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($linkedProducts as &$lpRow) {
                $lpRow['stock_qty'] = 0;
                $lpRow['unit_cost'] = null;
                $lpRow['currency'] = 'USD';
            }
            unset($lpRow);
        }
    }
} catch (Throwable $e) {
    $productCount = 0;
    $linkedProducts = [];
}

try {
    $poTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'stocks_purchase_orders'")->fetchColumn();
    if ($poTableExists) {
        $poCols = $pdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('supplier_id', $poCols, true)) {
            $poNumCol = in_array('po_number', $poCols, true) ? 'p.po_number' : "CONCAT('PO-', p.id)";
            $totalCol = in_array('total_amount', $poCols, true) ? 'p.total_amount' : 'NULL';
            $currencyCol = in_array('currency', $poCols, true) ? 'p.currency' : "'USD'";
            $createdCol = in_array('created_at', $poCols, true) ? 'p.created_at' : 'NULL';
            $stmtPo = $pdo->prepare(
                "SELECT p.id, {$poNumCol} AS po_number, p.status, {$createdCol} AS created_at,
                    {$totalCol} AS total_amount, {$currencyCol} AS currency,
                    (SELECT COUNT(*) FROM stocks_po_items pi WHERE pi.po_id = p.id) AS line_count
                 FROM stocks_purchase_orders p
                 WHERE p.supplier_id = ?
                 ORDER BY p.id DESC
                 LIMIT 100"
            );
            $stmtPo->execute([$id]);
            $supplierPurchaseOrders = $stmtPo->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($supplierPurchaseOrders as $poRow) {
                $supplyPoLineCount += (int) ($poRow['line_count'] ?? 0);
                $supplyPoTotalUsd += (float) ($poRow['total_amount'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    $supplierPurchaseOrders = [];
}

$openSupplySummary = isset($_GET['inventory']) && (string) $_GET['inventory'] === '1';

$supplierCode = '';
foreach (['supplier_code', 'code', 'reference_no', 'contact_code'] as $codeCol) {
    if (!empty($s[$codeCol])) {
        $supplierCode = trim((string) $s[$codeCol]);
        break;
    }
}
if ($supplierCode === '') {
    $supplierCode = 'SUP-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

$addressDisplay = trim((string) ($s['address'] ?? $s['location'] ?? ''));
$lastUpdated = $s['updated_at'] ?? $s['created_at'] ?? null;
$lastUpdatedDisplay = $lastUpdated ? date('d M Y', strtotime((string) $lastUpdated)) : '—';
$statusLabel = strtolower(trim((string) ($s['status'] ?? 'active')));
$isActive = $statusLabel === '' || $statusLabel === 'active' || $statusLabel === '1';
$contactPerson = trim((string) ($s['contact_person'] ?? ''));
$email = trim((string) ($s['email'] ?? ''));
$phone = trim((string) ($s['phone'] ?? ''));
$paymentTerms = trim((string) ($s['payment_terms'] ?? ''));
$currency = trim((string) ($s['currency'] ?? ''));
$city = trim((string) ($s['city'] ?? ''));

$partnerCategoryLabel = 'General Partner';
$partnerCategoryIcon = 'fa-users';
$partnerCategoryIconClass = 'sp-icon-blue';
if ($type === 'vehicle') {
    $partnerCategoryLabel = 'Truck Vendor';
    $partnerCategoryIcon = 'fa-truck';
    $partnerCategoryIconClass = 'sp-icon-blue';
} elseif ($type === 'spare_part') {
    $partnerCategoryLabel = 'Spare Parts';
    $partnerCategoryIcon = 'fa-cogs';
    $partnerCategoryIconClass = 'sp-icon-green';
}

$supplierName = (string) ($s['name'] ?? '');
$initials = strtoupper(substr($supplierName, 0, 1));
if (strpos($supplierName, ' ') !== false) {
    $parts = explode(' ', $supplierName);
    $initials = strtoupper($parts[0][0] . ($parts[1][0] ?? ''));
}

$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$page_title = 'Supplier Profile | ' . $supplierName;
include '../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --ugt-primary: #7c3aed;
        --ugt-primary-dark: #6d28d9;
    }
    body { font-family: 'Inter', sans-serif; background: #f1f5f9 !important; color: #0f172a; }
    .main-content { background: #f1f5f9 !important; }
    .supplier-profile-page { min-height: 100vh; padding: 28px 32px 48px; }
    .sp-container { max-width: 1200px; margin: 0 auto; }
    .sp-stat-icon, .sp-contact-icon, .sp-field-icon, .sp-footer-clock {
        border: 1px solid transparent;
    }
    .sp-icon-blue { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
    .sp-icon-purple { background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe; }
    .sp-icon-teal { background: #ecfeff; color: #0891b2; border-color: #a5f3fc; }
    .sp-icon-green { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
    .sp-icon-amber { background: #fffbeb; color: #d97706; border-color: #fde68a; }
    .sp-icon-rose { background: #fff1f2; color: #e11d48; border-color: #fecdd3; }
    .sp-icon-indigo { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
    .sp-icon-slate { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }

    .sp-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }
    .sp-header-left { display: flex; align-items: center; gap: 18px; min-width: 0; }
    .sp-back-btn {
        width: 44px; height: 44px; border-radius: 50%;
        border: 1px solid #bfdbfe; background: #eff6ff; color: #2563eb;
        display: inline-flex; align-items: center; justify-content: center;
        text-decoration: none; flex-shrink: 0; transition: all .2s ease;
    }
    .sp-back-btn:hover { background: #2563eb; color: #fff; border-color: #2563eb; }
    .sp-header-kicker {
        display: inline-block; font-size: 11px; font-weight: 600; letter-spacing: .06em;
        text-transform: uppercase; color: #2563eb; background: #eff6ff;
        padding: 4px 10px; border-radius: 6px; margin-bottom: 6px; border: 1px solid #bfdbfe;
    }
    .sp-header-title {
        font-size: 26px; font-weight: 700; color: #0f172a; line-height: 1.25; margin: 0;
        word-break: break-word;
    }
    .sp-btn-edit {
        display: inline-flex; align-items: center; gap: 10px; padding: 12px 22px;
        background: var(--ugt-primary); color: #fff; border-radius: 12px; font-size: 14px; font-weight: 600;
        text-decoration: none; box-shadow: 0 4px 14px rgba(124, 58, 237, .28); transition: all .2s ease;
        white-space: nowrap;
    }
    .sp-btn-edit:hover { background: var(--ugt-primary-dark); color: #fff; }
    .sp-btn-edit i { color: #fff; }

    .sp-stats-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .sp-stat-card {
        background: #fff; border: 1px solid #e8edf3; border-radius: 16px;
        padding: 18px 20px; box-shadow: 0 1px 3px rgba(15, 23, 42, .04);
    }
    .sp-stat-card-clickable {
        cursor: pointer; transition: border-color .2s ease, box-shadow .2s ease, transform .15s ease;
        text-align: left; width: 100%; font: inherit; color: inherit;
    }
    .sp-stat-card-clickable:hover {
        border-color: #c4b5fd; box-shadow: 0 8px 24px rgba(124, 58, 237, .12); transform: translateY(-1px);
    }
    .sp-stat-card-clickable .sp-stat-hint {
        font-size: 10px; color: #7c3aed; margin-top: 6px; font-weight: 500;
    }
    .sp-supply-overlay {
        position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 1050;
        display: none; align-items: flex-start; justify-content: center;
        padding: 24px 16px; overflow-y: auto;
    }
    .sp-supply-overlay.is-open { display: flex; }
    .sp-supply-panel {
        background: #fff; border-radius: 20px; width: 100%; max-width: 920px;
        box-shadow: 0 24px 60px rgba(15, 23, 42, .18); border: 1px solid #e8edf3;
        margin: auto 0;
    }
    .sp-supply-head {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
        padding: 22px 24px; border-bottom: 1px solid #f1f5f9;
    }
    .sp-supply-title { font-size: 18px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
    .sp-supply-sub { font-size: 13px; color: #64748b; margin: 0; }
    .sp-supply-close {
        width: 36px; height: 36px; border-radius: 10px; border: 1px solid #e2e8f0;
        background: #fff; color: #64748b; cursor: pointer; flex-shrink: 0;
    }
    .sp-supply-close:hover { background: #f8fafc; color: #0f172a; }
    .sp-supply-summary-grid {
        display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px;
        padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #f1f5f9;
    }
    .sp-supply-metric {
        background: #fff; border: 1px solid #e8edf3; border-radius: 12px; padding: 14px 16px;
    }
    .sp-supply-metric-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; }
    .sp-supply-metric-value { font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 4px; }
    .sp-supply-body { padding: 20px 24px 24px; max-height: min(60vh, 520px); overflow-y: auto; }
    .sp-supply-section-title {
        font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
        color: #64748b; margin: 0 0 12px;
    }
    .sp-supply-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .sp-supply-table th {
        text-align: left; padding: 10px 12px; font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em; color: #94a3b8;
        background: #f8fafc; border-bottom: 1px solid #e8edf3;
    }
    .sp-supply-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a; vertical-align: middle; }
    .sp-supply-table tr:last-child td { border-bottom: 0; }
    .sp-supply-table .text-end { text-align: right; }
    .sp-supply-table a { color: #2563eb; text-decoration: none; font-weight: 500; }
    .sp-supply-table a:hover { text-decoration: underline; }
    .sp-supply-empty { text-align: center; padding: 28px 16px; color: #94a3b8; font-size: 14px; }
    .sp-supply-foot {
        padding: 16px 24px; border-top: 1px solid #f1f5f9;
        display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end;
    }
    .sp-supply-link-btn {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px;
        border-radius: 10px; font-size: 13px; font-weight: 600; text-decoration: none;
        background: var(--ugt-primary); color: #fff;
    }
    .sp-supply-link-btn.secondary {
        background: #fff; color: var(--ugt-primary); border: 1px solid #ddd6fe;
    }
    @media (max-width: 640px) {
        .sp-supply-summary-grid { grid-template-columns: 1fr; }
    }
    .sp-stat-top { display: flex; align-items: flex-start; gap: 14px; }
    .sp-stat-icon {
        width: 42px; height: 42px; border-radius: 12px; display: inline-flex;
        align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;
    }
    .sp-stat-label {
        font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
        color: #94a3b8; margin-bottom: 6px;
    }
    .sp-stat-value { font-size: 15px; font-weight: 600; color: #0f172a; line-height: 1.3; }
    .sp-stat-value-lg { font-size: 28px; font-weight: 700; color: #0f172a; line-height: 1; }
    .sp-stat-sub { font-size: 12px; color: #94a3b8; margin-top: 2px; }
    .sp-status-pill {
        display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 999px;
        font-size: 12px; font-weight: 600; color: #15803d; background: #dcfce7;
        border: 1px solid #bbf7d0;
    }
    .sp-status-pill.inactive { color: #64748b; background: #f1f5f9; border-color: #e2e8f0; }

    .sp-detail-card {
        background: #fff; border: 1px solid #e8edf3; border-radius: 20px;
        box-shadow: 0 8px 30px rgba(15, 23, 42, .05); overflow: hidden;
    }
    .sp-detail-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0;
    }
    .sp-detail-col {
        padding: 32px 28px;
        border-right: 1px solid #f1f5f9;
    }
    .sp-detail-col:last-child { border-right: 0; }

    .sp-avatar {
        width: 88px; height: 88px; border-radius: 50%; background: #eef2ff; color: #4f46e5;
        border: 2px solid #c7d2fe;
        display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 700;
        margin-bottom: 16px;
    }
    .sp-profile-name { font-size: 17px; font-weight: 700; color: #0f172a; margin: 0 0 4px; line-height: 1.35; }
    .sp-profile-role { font-size: 12px; color: #94a3b8; margin: 0 0 24px; }
    .sp-contact-list { display: flex; flex-direction: column; gap: 18px; }
    .sp-contact-item { display: flex; gap: 12px; align-items: flex-start; }
    .sp-contact-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .sp-field-label {
        font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
        color: #94a3b8; margin-bottom: 3px; display: block;
    }
    .sp-field-value { font-size: 14px; font-weight: 500; color: #0f172a; line-height: 1.45; }
    .sp-field-value a { color: #2563eb; text-decoration: none; }
    .sp-field-value a:hover { text-decoration: underline; }

    .sp-section-title {
        font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
        color: #94a3b8; margin: 0 0 22px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
    }
    .sp-fields { display: flex; flex-direction: column; gap: 20px; }
    .sp-field-row { display: flex; gap: 12px; align-items: flex-start; }
    .sp-field-icon {
        width: 36px; height: 36px; border-radius: 10px;
        display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    }

    .sp-detail-footer {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        padding: 18px 28px; border-top: 1px solid #f1f5f9; background: #fafbfc; flex-wrap: wrap;
    }
    .sp-footer-meta { display: flex; align-items: center; gap: 10px; color: #94a3b8; }
    .sp-footer-meta .sp-footer-clock {
        width: 36px; height: 36px; border-radius: 10px; font-size: 14px;
        display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .sp-footer-label {
        font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
        color: #94a3b8; display: block; margin-bottom: 2px;
    }
    .sp-footer-value { font-size: 13px; font-weight: 600; color: #64748b; }
    .sp-btn-unregister {
        display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
        border: 1px solid #fecaca; background: #fff; color: #dc2626; border-radius: 10px;
        font-size: 13px; font-weight: 600; text-decoration: none; transition: all .2s ease;
    }
    .sp-btn-unregister:hover { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; }

    @media (max-width: 1024px) {
        .sp-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .sp-detail-grid { grid-template-columns: 1fr; }
        .sp-detail-col { border-right: 0; border-bottom: 1px solid #f1f5f9; }
        .sp-detail-col:last-child { border-bottom: 0; }
    }
    @media (max-width: 640px) {
        .supplier-profile-page { padding: 16px; }
        .sp-stats-grid { grid-template-columns: 1fr; }
        .sp-page-header { flex-direction: column; align-items: flex-start; }
        .sp-btn-edit { width: 100%; justify-content: center; }
    }
</style>

<main class="main-content supplier-profile-page">
    <div class="sp-container">

        <header class="sp-page-header">
            <div class="sp-header-left">
                <a href="index.php" class="sp-back-btn" aria-label="Back to suppliers">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <span class="sp-header-kicker">Supplier Profile</span>
                    <h1 class="sp-header-title"><?= $esc($supplierName) ?></h1>
                </div>
            </div>
            <a href="edit.php?id=<?= $id ?>" class="sp-btn-edit">
                <i class="fas fa-user-edit"></i> Edit Partner Details
            </a>
        </header>

        <div class="sp-stats-grid">
            <div class="sp-stat-card">
                <div class="sp-stat-top">
                    <span class="sp-stat-icon <?= $esc($partnerCategoryIconClass) ?>">
                        <i class="fas <?= $esc($partnerCategoryIcon) ?>"></i>
                    </span>
                    <div>
                        <div class="sp-stat-label">Partner Category</div>
                        <div class="sp-stat-value"><?= $esc($partnerCategoryLabel) ?></div>
                    </div>
                </div>
            </div>
            <button type="button" class="sp-stat-card sp-stat-card-clickable" id="linkedInventoryCard" aria-expanded="false" aria-controls="supplySummaryOverlay">
                <div class="sp-stat-top">
                    <span class="sp-stat-icon sp-icon-purple">
                        <i class="fas fa-link"></i>
                    </span>
                    <div>
                        <div class="sp-stat-label">Linked Inventory</div>
                        <div class="sp-stat-value-lg"><?= (int) $productCount ?></div>
                        <div class="sp-stat-sub">SKU Supplied</div>
                        <div class="sp-stat-hint">Click to view supply summary</div>
                    </div>
                </div>
            </button>
            <div class="sp-stat-card">
                <div class="sp-stat-top">
                    <span class="sp-stat-icon sp-icon-teal">
                        <i class="fas fa-id-card"></i>
                    </span>
                    <div>
                        <div class="sp-stat-label">System Identity</div>
                        <div class="sp-stat-value"><?= $esc($supplierCode) ?></div>
                    </div>
                </div>
            </div>
            <div class="sp-stat-card">
                <div class="sp-stat-top">
                    <span class="sp-stat-icon sp-icon-green">
                        <i class="fas fa-shield-alt"></i>
                    </span>
                    <div>
                        <div class="sp-stat-label">Account Status</div>
                        <span class="sp-status-pill<?= $isActive ? '' : ' inactive' ?>">
                            <?= $isActive ? 'Active' : $esc(ucfirst($statusLabel ?: 'Inactive')) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="sp-detail-card">
            <div class="sp-detail-grid">

                <div class="sp-detail-col">
                    <div class="sp-avatar"><?= $esc($initials) ?></div>
                    <h2 class="sp-profile-name"><?= $esc($supplierName) ?></h2>
                    <p class="sp-profile-role">Registered Partner</p>
                    <div class="sp-contact-list">
                        <div class="sp-contact-item">
                            <span class="sp-contact-icon sp-icon-rose"><i class="far fa-envelope"></i></span>
                            <div>
                                <span class="sp-field-label">Primary Email</span>
                                <?php if ($email !== ''): ?>
                                    <div class="sp-field-value"><a href="mailto:<?= $esc($email) ?>"><?= $esc($email) ?></a></div>
                                <?php else: ?>
                                    <div class="sp-field-value">No email on record</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="sp-contact-item">
                            <span class="sp-contact-icon sp-icon-green"><i class="fas fa-phone-alt"></i></span>
                            <div>
                                <span class="sp-field-label">Direct Contact</span>
                                <div class="sp-field-value"><?= $esc($phone !== '' ? $phone : 'No phone on record') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sp-detail-col">
                    <h3 class="sp-section-title">Operational Details</h3>
                    <div class="sp-fields">
                        <div class="sp-field-row">
                            <span class="sp-field-icon sp-icon-indigo"><i class="fas fa-user"></i></span>
                            <div>
                                <span class="sp-field-label">Head of Operations / Contact</span>
                                <div class="sp-field-value"><?= $esc($contactPerson !== '' ? $contactPerson : 'Unspecified') ?></div>
                            </div>
                        </div>
                        <div class="sp-field-row">
                            <span class="sp-field-icon sp-icon-amber"><i class="far fa-file-alt"></i></span>
                            <div>
                                <span class="sp-field-label">Standard Payment Terms</span>
                                <div class="sp-field-value"><?= $esc($paymentTerms !== '' ? $paymentTerms : 'Default Net 30') ?></div>
                            </div>
                        </div>
                        <div class="sp-field-row">
                            <span class="sp-field-icon sp-icon-teal"><i class="fas fa-dollar-sign"></i></span>
                            <div>
                                <span class="sp-field-label">Operational Currency</span>
                                <div class="sp-field-value"><?= $esc($currency !== '' ? $currency : 'USD ($)') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sp-detail-col">
                    <h3 class="sp-section-title">Physical Infrastructure</h3>
                    <div class="sp-fields">
                        <div class="sp-field-row">
                            <span class="sp-field-icon sp-icon-rose"><i class="fas fa-map-marker-alt"></i></span>
                            <div>
                                <span class="sp-field-label">Warehouse / Office Address</span>
                                <div class="sp-field-value"><?= $addressDisplay !== '' ? nl2br($esc($addressDisplay)) : 'Address details pending update...' ?></div>
                            </div>
                        </div>
                        <div class="sp-field-row">
                            <span class="sp-field-icon sp-icon-blue"><i class="fas fa-globe-americas"></i></span>
                            <div>
                                <span class="sp-field-label">Region / City</span>
                                <div class="sp-field-value"><?= $esc($city !== '' ? $city : 'Not Specified') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <footer class="sp-detail-footer">
                <div class="sp-footer-meta">
                    <span class="sp-footer-clock sp-icon-amber"><i class="far fa-clock"></i></span>
                    <div>
                        <span class="sp-footer-label">Last Registry Update</span>
                        <span class="sp-footer-value"><?= $esc($lastUpdatedDisplay) ?></span>
                    </div>
                </div>
                <a href="delete.php?id=<?= $id ?>" class="sp-btn-unregister" onclick="return confirm('Remove this partner permanently?')">
                    <i class="far fa-trash-alt"></i> Unregister Partner
                </a>
            </footer>
        </div>

    </div>

    <div class="sp-supply-overlay<?= $openSupplySummary ? ' is-open' : '' ?>" id="supplySummaryOverlay" role="dialog" aria-modal="true" aria-labelledby="supplySummaryTitle">
        <div class="sp-supply-panel">
            <div class="sp-supply-head">
                <div>
                    <h2 class="sp-supply-title" id="supplySummaryTitle">Supply summary</h2>
                    <p class="sp-supply-sub"><?= $esc($supplierName) ?> · <?= $esc($supplierCode) ?></p>
                </div>
                <button type="button" class="sp-supply-close" id="supplySummaryClose" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sp-supply-summary-grid">
                <div class="sp-supply-metric">
                    <div class="sp-supply-metric-label">Catalogue SKUs</div>
                    <div class="sp-supply-metric-value"><?= (int) $productCount ?></div>
                </div>
                <div class="sp-supply-metric">
                    <div class="sp-supply-metric-label">Purchase orders</div>
                    <div class="sp-supply-metric-value"><?= count($supplierPurchaseOrders) ?></div>
                </div>
                <div class="sp-supply-metric">
                    <div class="sp-supply-metric-label">PO line items</div>
                    <div class="sp-supply-metric-value"><?= (int) $supplyPoLineCount ?></div>
                </div>
            </div>
            <div class="sp-supply-body">
                <h3 class="sp-supply-section-title">Products supplied (catalogue)</h3>
                <?php if (empty($linkedProducts)): ?>
                    <div class="sp-supply-empty">No products are linked to this supplier yet.</div>
                <?php else: ?>
                    <table class="sp-supply-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Code</th>
                                <th>Category</th>
                                <th class="text-end">Stock</th>
                                <th class="text-end">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linkedProducts as $prod):
                                $prodId = (int) ($prod['id'] ?? 0);
                                $stockQty = (int) ($prod['stock_qty'] ?? 0);
                                $cost = isset($prod['unit_cost']) ? (float) $prod['unit_cost'] : null;
                                $prodCurrency = trim((string) ($prod['currency'] ?? 'USD'));
                                ?>
                                <tr>
                                    <td>
                                        <a href="../products/view.php?id=<?= $prodId ?>"><?= $esc($prod['name'] ?? 'Product') ?></a>
                                    </td>
                                    <td><?= $esc($prod['product_code'] ?? '—') ?></td>
                                    <td><?= $esc($prod['category_name'] ?? '—') ?></td>
                                    <td class="text-end"><?= $stockQty ?></td>
                                    <td class="text-end">
                                        <?= $cost !== null && $cost > 0
                                            ? $esc(number_format($cost, 2) . ' ' . $prodCurrency)
                                            : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3 class="sp-supply-section-title" style="margin-top:28px">Purchase orders from this supplier</h3>
                <?php if (empty($supplierPurchaseOrders)): ?>
                    <div class="sp-supply-empty">No purchase orders recorded for this supplier.</div>
                <?php else: ?>
                    <table class="sp-supply-table">
                        <thead>
                            <tr>
                                <th>PO #</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-end">Lines</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supplierPurchaseOrders as $poRow):
                                $poId = (int) ($poRow['id'] ?? 0);
                                $poDate = !empty($poRow['created_at']) ? date('d M Y', strtotime((string) $poRow['created_at'])) : '—';
                                $poTotal = (float) ($poRow['total_amount'] ?? 0);
                                $poCurr = trim((string) ($poRow['currency'] ?? 'USD'));
                                ?>
                                <tr>
                                    <td>
                                        <a href="../purchases/view_po.php?id=<?= $poId ?>"><?= $esc($poRow['po_number'] ?? ('PO-' . $poId)) ?></a>
                                    </td>
                                    <td><?= $esc($poDate) ?></td>
                                    <td><?= $esc($poRow['status'] ?? '—') ?></td>
                                    <td class="text-end"><?= (int) ($poRow['line_count'] ?? 0) ?></td>
                                    <td class="text-end">
                                        <?= $poTotal > 0 ? $esc(number_format($poTotal, 2) . ' ' . $poCurr) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="sp-supply-foot">
                <a href="../products/index.php?supplier=<?= $id ?>" class="sp-supply-link-btn secondary">View in product list</a>
                <button type="button" class="sp-supply-link-btn secondary" id="supplySummaryClose2">Close</button>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    const overlay = document.getElementById('supplySummaryOverlay');
    const openBtn = document.getElementById('linkedInventoryCard');
    const closeBtn = document.getElementById('supplySummaryClose');
    const closeBtn2 = document.getElementById('supplySummaryClose2');

    function openSummary() {
        if (!overlay) return;
        overlay.classList.add('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function closeSummary() {
        if (!overlay) return;
        overlay.classList.remove('is-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (window.location.search.includes('inventory=1')) {
            const url = new URL(window.location.href);
            url.searchParams.delete('inventory');
            window.history.replaceState({}, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
        }
    }

    if (openBtn) openBtn.addEventListener('click', openSummary);
    if (overlay && overlay.classList.contains('is-open')) {
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    if (closeBtn) closeBtn.addEventListener('click', closeSummary);
    if (closeBtn2) closeBtn2.addEventListener('click', closeSummary);
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeSummary();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSummary();
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>
