<?php
/**
 * Product label builder - select products and print/PDF shelf labels.
 */
require_once __DIR__ . '/../stock/config/database.php';
require_once __DIR__ . '/label-lib.php';
requireLogin();

$active_module = 'store-management';
$page_title = 'Product Labels';
$employeeHeaderTitle = 'Product Labels';
$employeeHeaderSubtitle = 'Create printable PDF labels with product image and code';
$employeeHeaderExtraClass = 'sms-label-employee-header';

if (function_exists('app_url')) {
    $rootPath = app_url('/');
    $stockBasePath = app_url('stock/');
} else {
    $rootPath = '../';
    $stockBasePath = '../stock/';
}
$logoBase = $rootPath;
$modulesLink = rtrim($rootPath, '/') . '/select-module.php';

function sms_label_absolute_url(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

$search = trim((string) ($_GET['search'] ?? ''));
$categoryId = (int) ($_GET['category_id'] ?? 0);
$placedFilter = (string) ($_GET['placed'] ?? 'all');
if (!in_array($placedFilter, ['all', 'placed', 'unplaced'], true)) {
    $placedFilter = 'all';
}

sms_ensure_label_placements_table($pdo);
$labelCompanyId = sms_label_company_id();

$imageSql = function_exists('stock_product_main_image_sql')
    ? stock_product_main_image_sql($pdo, 'p')
    : 'p.main_image';

$where = 'WHERE 1=1';
$params = [];
if ($search !== '') {
    $wildcard = '%' . $search . '%';
    try {
        $productCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $productCols = [];
    }
    if (in_array('sku', $productCols, true)) {
        $where .= ' AND (p.name LIKE ? OR p.product_code LIKE ? OR p.sku LIKE ?)';
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
    } else {
        $where .= ' AND (p.name LIKE ? OR p.product_code LIKE ?)';
        $params[] = $wildcard;
        $params[] = $wildcard;
    }
}
if ($categoryId > 0) {
    $where .= ' AND p.category_id = ?';
    $params[] = $categoryId;
}
if ($placedFilter === 'placed') {
    $where .= ' AND plp.id IS NOT NULL';
} elseif ($placedFilter === 'unplaced') {
    $where .= ' AND plp.id IS NULL';
}

$sql = "SELECT p.id, p.product_code, p.name, {$imageSql} AS image_file, c.name AS category_name,
               CASE WHEN plp.id IS NULL THEN 0 ELSE 1 END AS label_placed
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_label_placements plp
            ON plp.product_id = p.id AND plp.company_id = ?
        {$where}
        ORDER BY label_placed ASC, p.name ASC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$labelCompanyId], $params));
$products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$placedCount = sms_count_label_placed($pdo);

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$labelDownloadUrl = function_exists('app_url')
    ? app_url('store-management-system/label-download.php')
    : 'label-download.php';

$labelStarUrl = function_exists('app_url')
    ? app_url('store-management-system/label-star.php')
    : 'label-star.php';

$labelPageUrl = function_exists('app_url')
    ? app_url('store-management-system/index.php?page=labels')
    : 'index.php?page=labels';

$perPageDefault = sms_label_resolve_per_page($_GET['per_page'] ?? 1);

include __DIR__ . '/../stock/includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<style>
    header.sms-label-employee-header.employee-header--page-context {
        height: auto !important;
        min-height: 72px;
        padding: 18px 1.5rem 14px !important;
        background: #f8fafc !important;
        border-bottom: 1px solid #e2e8f0 !important;
        box-shadow: none !important;
    }
    header.sms-label-employee-header .header-content {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between;
        gap: 16px;
        width: 100%;
        padding: 0 !important;
        flex-wrap: nowrap;
    }
    header.sms-label-employee-header .header-left {
        flex: 0 0 auto;
        min-width: 0;
    }
    header.sms-label-employee-header .employee-header-page-heading {
        flex: 1 1 auto;
        min-width: 0;
        margin-left: 0 !important;
        padding-left: 0 !important;
    }
    header.sms-label-employee-header .employee-header-page-title {
        font-size: 1.375rem !important;
        line-height: 1.2 !important;
    }
    header.sms-label-employee-header .employee-header-page-subtitle {
        font-size: 0.8125rem !important;
        color: #6b7280 !important;
        margin-top: 2px !important;
    }
    header.sms-label-employee-header .header-right.header-actions-tray {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: flex-end;
        gap: 8px !important;
        margin-left: auto !important;
        flex: 0 0 auto;
        flex-wrap: nowrap;
        white-space: nowrap;
    }
    header.sms-label-employee-header .header-right > .d-md-flex {
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        max-width: 220px;
        padding: 7px 12px;
        margin: 0 !important;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        font-size: 12px !important;
        font-weight: 600;
        color: #374151 !important;
        line-height: 1.2;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    header.sms-label-employee-header .header-right > .d-md-flex span:not(.badge) {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 160px;
    }
    header.sms-label-employee-header .header-right .badge {
        display: none !important;
    }
    header.sms-label-employee-header .theme-toggle-btn,
    header.sms-label-employee-header .header-notif-bell-btn {
        width: 40px !important;
        height: 40px !important;
        min-width: 40px !important;
        min-height: 40px !important;
        margin: 0 !important;
        border-radius: 50% !important;
        background: #fff !important;
        border: 1px solid #e5e7eb !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        flex-shrink: 0;
    }
    header.sms-label-employee-header .theme-toggle-btn:hover,
    header.sms-label-employee-header .header-notif-bell-btn:hover {
        background: #f8fafc !important;
    }
    @media (max-width: 991.98px) {
        header.sms-label-employee-header .header-content {
            flex-wrap: wrap;
            row-gap: 12px;
        }
        header.sms-label-employee-header .employee-header-page-heading {
            flex: 1 1 100%;
            order: 1;
        }
        header.sms-label-employee-header .header-left {
            order: 0;
        }
        header.sms-label-employee-header .header-right.header-actions-tray {
            order: 2;
            margin-left: auto !important;
        }
    }
    @media (max-width: 767.98px) {
        header.sms-label-employee-header.employee-header--page-context {
            min-height: 0;
            padding: 10px 1rem 8px !important;
        }
        header.sms-label-employee-header .employee-header-page-heading {
            display: none !important;
        }
        header.sms-label-employee-header .header-right > .d-md-flex {
            display: none !important;
        }
        header.sms-label-employee-header .header-content {
            flex-wrap: nowrap;
            row-gap: 0;
        }
        header.sms-label-employee-header .header-right.header-actions-tray {
            order: 0;
        }
    }

    .sms-label-page { max-width: 1400px; margin: 0 auto; }
    .sms-label-preview-card, .sms-label-table-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .sms-label-toolbar {
        padding: 0;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        background: transparent;
        border: none;
        box-shadow: none;
        overflow: visible;
    }

    .sms-filters-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 10px 22px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 999px !important;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
        font-weight: 600;
        font-size: 14px;
        color: #111827;
        cursor: pointer;
        transition: box-shadow 0.15s ease, transform 0.15s ease;
    }
    .sms-filters-pill-btn:hover {
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
        transform: translateY(-1px);
    }
    .sms-filters-pill-btn i { font-size: 15px; }
    .sms-filters-pill-btn .sms-filter-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: #2563eb;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
    }

    .sms-filter-wrap {
        position: relative;
        flex: 0 0 auto;
        overflow: visible;
    }

    .sms-filter-drawer {
        position: fixed;
        inset: 0;
        z-index: 10050;
        pointer-events: none;
        visibility: hidden;
    }
    .sms-filter-drawer.is-open {
        pointer-events: auto;
        visibility: visible;
    }
    .sms-filter-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.35);
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .sms-filter-drawer.is-open .sms-filter-backdrop {
        opacity: 1;
    }
    .sms-filter-panel {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: min(380px, 92vw);
        background: #fff;
        box-shadow: 4px 0 28px rgba(15, 23, 42, 0.14);
        transform: translateX(-100%);
        transition: transform 0.28s ease;
        display: flex;
        flex-direction: column;
        padding: 0;
    }
    .sms-filter-drawer.is-open .sms-filter-panel {
        transform: translateX(0);
    }
    .sms-filter-panel form {
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .sms-filter-panel-head {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 22px 24px 18px 40px;
        border-bottom: 1px solid #f1f5f9;
        min-height: 72px;
    }
    .sms-filter-panel-head h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 700;
        color: #111827;
        letter-spacing: -0.02em;
    }
    .sms-filter-close-btn {
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 1px solid #e5e7eb;
        background: #fff;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.12);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #111827;
        font-size: 14px;
        z-index: 2;
    }
    .sms-filter-close-btn:hover { background: #f8fafc; }
    .sms-filter-panel-body {
        flex: 1;
        overflow-y: auto;
        padding: 20px 24px 24px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .sms-filter-field label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 6px;
    }
    .sms-filter-field input,
    .sms-filter-field select {
        width: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 14px;
        background: #fff;
        color: #0f172a;
    }
    .sms-filter-apply-btn {
        margin-top: 0;
        flex: 1;
        width: auto;
        background: #111827;
        color: #fff;
        border: none;
        border-radius: 999px !important;
        padding: 12px 18px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
    }
    .sms-filter-apply-btn:hover { background: #1f2937; }
    .sms-filter-actions {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }
    .sms-filter-clear-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 16px;
        border-radius: 999px !important;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }
    .sms-filter-clear-btn:hover {
        background: #f8fafc;
        color: #334155;
    }

    @media (max-width: 767.98px) {
        .sms-label-toolbar {
            align-items: flex-start;
        }
        .sms-filter-wrap {
            flex: 1 1 100%;
            width: 100%;
        }
        .sms-filters-pill-btn {
            width: 100%;
            justify-content: center;
        }
        .sms-filter-drawer {
            position: absolute;
            inset: auto;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 10050;
        }
        .sms-filter-backdrop {
            display: none;
        }
        .sms-filter-panel {
            position: relative;
            left: auto;
            top: auto;
            bottom: auto;
            width: 100%;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transform: none;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.12);
            transition: max-height 0.28s ease, opacity 0.2s ease;
        }
        .sms-filter-drawer.is-open .sms-filter-panel {
            transform: none;
            max-height: 75vh;
            opacity: 1;
            overflow-y: auto;
        }
        .sms-filter-panel form {
            height: auto;
        }
        .sms-filter-panel-head {
            padding: 14px 16px 10px;
            min-height: 0;
            justify-content: flex-start;
        }
        .sms-filter-panel-head h2 {
            font-size: 16px;
        }
        .sms-filter-close-btn {
            display: none;
        }
        .sms-filter-panel-body {
            padding: 12px 16px 16px;
        }
    }

    .sms-label-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-left: auto;
    }
    .sms-label-actions label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        margin: 0;
        white-space: nowrap;
    }
    @media (max-width: 767.98px) {
        .store-management-shell.main-content {
            padding: 0.75rem 1rem 1.5rem !important;
        }
        .sms-label-toolbar {
            gap: 10px;
        }
        .sms-label-actions {
            width: 100%;
            margin-left: 0;
            flex-wrap: nowrap;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }
        .sms-label-actions label {
            font-size: 13px;
            gap: 6px;
        }
        .sms-btn-generate {
            flex: 1 1 auto;
            min-width: 0;
            max-width: 100%;
            padding: 10px 14px !important;
            font-size: 13px;
            white-space: nowrap;
        }
    }
    .sms-label-grid { display: grid; grid-template-columns: 1fr minmax(460px, 40%); gap: 1rem; align-items: start; }
    .sms-label-preview-card { padding: 1.25rem; min-width: 0; }
    .sms-label-table-card { overflow: hidden; }
    .sms-label-table { width: 100%; border-collapse: collapse; }
    .sms-label-table th {
        background: #f8fafc;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        text-align: left;
    }
    .sms-label-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 13px;
        vertical-align: middle;
    }
    .sms-label-table tr:hover { background: #f8fafc; }
    .sms-label-table tr.is-placed { background: #fffbeb; }
    .sms-label-table tr.is-placed:hover { background: #fef3c7; }
    .label-star-btn {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 17px;
        line-height: 1;
        padding: 4px;
        color: #cbd5e1;
        transition: color 0.15s ease, transform 0.15s ease;
    }
    .label-star-btn:hover { transform: scale(1.08); }
    .label-star-btn.is-placed { color: #f59e0b; }
    .label-star-btn:disabled { opacity: 0.5; cursor: wait; }
    .label-select-cell.is-hidden,
    .label-qty-cell.is-hidden {
        opacity: 0;
        pointer-events: none;
        user-select: none;
    }
    .label-select-cell.is-hidden input,
    .label-qty-cell.is-hidden input {
        visibility: hidden;
    }
    .sms-label-thumb {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        object-fit: cover;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
    }
    .sms-label-thumb-fallback {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
    }
    .sms-qty-input {
        width: 70px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 6px 8px;
        font-size: 13px;
    }
    .sms-btn-generate {
        background: #7c3aed !important;
        color: #fff !important;
        border: none !important;
        border-radius: 999px !important;
        padding: 10px 22px !important;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(124, 58, 237, 0.25);
        transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 42px;
    }
    .sms-btn-generate:hover {
        background: #6d28d9 !important;
        box-shadow: 0 4px 14px rgba(124, 58, 237, 0.32);
        transform: translateY(-1px);
    }
    .sms-btn-generate:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    .sms-download-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .sms-download-overlay.is-active {
        opacity: 1;
        visibility: visible;
    }
    .sms-download-modal {
        background: #fff;
        border-radius: 16px;
        padding: 2rem 2.5rem;
        width: min(380px, 90vw);
        text-align: center;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
    }
    .sms-download-spinner {
        width: 56px;
        height: 56px;
        margin: 0 auto 1rem;
        border: 4px solid #e2e8f0;
        border-top-color: #2563eb;
        border-radius: 50%;
        animation: sms-label-spin 0.8s linear infinite;
    }
    @keyframes sms-label-spin {
        to { transform: rotate(360deg); }
    }
    .sms-download-status {
        font-size: 15px;
        font-weight: 600;
        color: #1e293b;
        margin: 0;
    }
    .sms-download-progress {
        height: 6px;
        background: #e2e8f0;
        border-radius: 999px;
        overflow: hidden;
        margin-top: 1rem;
    }
    .sms-download-bar {
        height: 100%;
        width: 0%;
        background: linear-gradient(90deg, #2563eb, #3b82f6);
        border-radius: 999px;
        transition: width 0.35s ease;
    }
    .sms-download-icon {
        font-size: 2rem;
        color: #2563eb;
        margin-bottom: 0.75rem;
        animation: sms-label-bounce 1s ease infinite;
    }
    .sms-download-error-icon {
        display: none;
        font-size: 2.5rem;
        color: #dc2626;
        margin-bottom: 0.75rem;
        line-height: 1;
    }
    .sms-download-overlay.is-error .sms-download-spinner,
    .sms-download-overlay.is-error .sms-download-icon,
    .sms-download-overlay.is-error .sms-download-progress {
        display: none;
    }
    .sms-download-overlay.is-error .sms-download-error-icon {
        display: block;
    }
    .sms-download-overlay.is-error .sms-download-status {
        color: #b91c1c;
        font-size: 14px;
        line-height: 1.45;
        font-weight: 600;
    }
    @keyframes sms-label-bounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-6px); }
    }

    .sms-success-sheet {
        position: fixed;
        inset: 0;
        z-index: 10000;
        pointer-events: none;
        visibility: hidden;
    }
    .sms-success-sheet.is-open {
        pointer-events: auto;
        visibility: visible;
    }
    .sms-success-sheet-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        opacity: 0;
        transition: opacity 0.25s ease;
    }
    .sms-success-sheet.is-open .sms-success-sheet-backdrop {
        opacity: 1;
    }
    .sms-success-sheet-panel {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        background: #fff;
        border-radius: 20px 20px 0 0;
        padding: 12px 20px calc(20px + env(safe-area-inset-bottom, 0px));
        transform: translateY(100%);
        transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
        box-shadow: 0 -10px 40px rgba(15, 23, 42, 0.14);
        text-align: center;
    }
    .sms-success-sheet.is-open .sms-success-sheet-panel {
        transform: translateY(0);
    }
    .sms-success-sheet-handle {
        width: 40px;
        height: 4px;
        background: #e2e8f0;
        border-radius: 999px;
        margin: 0 auto 14px;
    }
    .sms-success-sheet-icon {
        font-size: 2.75rem;
        color: #10b981;
        margin-bottom: 6px;
        line-height: 1;
    }
    .sms-success-sheet-title {
        margin: 0 0 6px;
        font-size: 1.25rem;
        font-weight: 700;
        color: #111827;
        letter-spacing: -0.02em;
    }
    .sms-success-sheet-filename {
        margin: 0 0 8px;
        font-size: 13px;
        font-weight: 600;
        color: #64748b;
        word-break: break-all;
    }
    .sms-success-sheet-hint {
        margin: 0 0 18px;
        font-size: 13px;
        color: #94a3b8;
        line-height: 1.45;
    }
    .sms-success-sheet-actions {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .sms-success-share-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        background: #7c3aed !important;
        color: #fff !important;
        border: none !important;
        border-radius: 999px !important;
        padding: 14px 18px !important;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(124, 58, 237, 0.28);
    }
    .sms-success-share-btn:hover {
        background: #6d28d9 !important;
    }
    .sms-success-share-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
    }
    .sms-success-done-btn {
        width: 100%;
        background: transparent !important;
        border: none !important;
        color: #64748b !important;
        border-radius: 999px !important;
        padding: 12px 18px !important;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }
    .sms-success-done-btn:hover {
        color: #334155 !important;
        background: #f8fafc !important;
    }
    @media (min-width: 768px) {
        .sms-success-sheet {
            display: none !important;
        }
    }

    /* Preview label - matches print format */
    .label-preview-sheet {
        border: 2px solid #111;
        border-radius: 8px;
        padding: 16px;
        background: #fff;
        min-height: 220px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .label-preview-image {
        width: 100%;
        height: 110px;
        object-fit: contain;
        background: #fafafa;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
    }
    .label-preview-line {
        font-size: 15px;
        font-weight: 800;
        color: #000;
        line-height: 1.45;
        text-transform: uppercase;
        font-family: Arial, Helvetica, sans-serif;
        word-break: normal;
        overflow-wrap: break-word;
        hyphens: none;
    }
    .label-preview-empty {
        color: #94a3b8;
        font-size: 13px;
        text-align: center;
        padding: 2rem 1rem;
    }

    .label-preview-sheet.layout-1 {
        flex-direction: row;
        align-items: stretch;
        width: 100%;
        min-height: 0;
        aspect-ratio: 297 / 210;
        max-width: 100%;
        padding: 14px;
        gap: 10px;
        overflow: hidden;
    }
    .label-preview-sheet.layout-1 .label-preview-image {
        flex: 0 0 44%;
        width: 44%;
        max-width: 44%;
        height: 100%;
        max-height: 100%;
        align-self: center;
        object-fit: contain;
        object-position: center;
    }
    .label-preview-sheet.layout-1 .label-preview-details {
        flex: 1 1 56%;
        min-width: 0;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        gap: 14px;
        padding-top: 18px;
        padding-right: 4px;
    }
    .label-preview-sheet.layout-1 .label-preview-line {
        font-size: 11px;
        line-height: 1.45;
        letter-spacing: 0.01em;
    }
    .label-preview-sheet.layout-2,
    .label-preview-sheet.layout-4,
    .label-preview-sheet.layout-6,
    .label-preview-sheet.layout-8 {
        min-height: 220px;
    }
    .label-preview-sheet.layout-2 .label-preview-line,
    .label-preview-sheet.layout-4 .label-preview-line,
    .label-preview-sheet.layout-6 .label-preview-line,
    .label-preview-sheet.layout-8 .label-preview-line {
        font-size: 12px;
    }

    @media (max-width: 1280px) {
        .sms-label-grid { grid-template-columns: 1fr minmax(380px, 44%); }
    }

    @media (max-width: 1100px) {
        .sms-label-grid { grid-template-columns: 1fr; }
        .label-preview-sheet.layout-1 .label-preview-line {
            font-size: 13px;
        }
    }
</style>

<main class="main-content store-management-shell" style="background:#f8fafc;padding:1.25rem 1.5rem 2rem;">
    <div class="sms-label-page">
        <div class="sms-label-toolbar">
            <div class="sms-filter-wrap" id="filterWrap">
                <button type="button" class="sms-filters-pill-btn" id="openFiltersBtn" aria-expanded="false" aria-controls="filterDrawer">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                    <span>Filters</span>
                    <?php
                    $activeFilterCount = 0;
                    if ($search !== '') {
                        $activeFilterCount++;
                    }
                    if ($categoryId > 0) {
                        $activeFilterCount++;
                    }
                    if ($placedFilter !== 'all') {
                        $activeFilterCount++;
                    }
                    ?>
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="sms-filter-badge" id="filterActiveBadge"><?= $activeFilterCount ?></span>
                    <?php else: ?>
                        <span class="sms-filter-badge hidden" id="filterActiveBadge" style="display:none;"></span>
                    <?php endif; ?>
                </button>

                <div id="filterDrawer" class="sms-filter-drawer" aria-hidden="true">
                    <div class="sms-filter-backdrop" id="filterBackdrop"></div>
                    <aside class="sms-filter-panel" role="dialog" aria-modal="true" aria-labelledby="filterDrawerTitle">
                        <form id="filterForm" method="get" action="<?= htmlspecialchars($labelPageUrl) ?>">
                            <div class="sms-filter-panel-head">
                                <button type="button" class="sms-filter-close-btn" id="closeFiltersBtn" aria-label="Close filters">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <h2 id="filterDrawerTitle">Filters</h2>
                            </div>
                            <div class="sms-filter-panel-body">
                                <div class="sms-filter-field">
                                    <label for="filterSearch">Search</label>
                                    <input type="text" id="filterSearch" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Product name or code...">
                                </div>
                                <div class="sms-filter-field">
                                    <label for="filterCategory">Category</label>
                                    <select id="filterCategory" name="category_id">
                                        <option value="0">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="sms-filter-field">
                                    <label for="filterPlaced">Label status</label>
                                    <select id="filterPlaced" name="placed">
                                        <option value="all" <?= $placedFilter === 'all' ? 'selected' : '' ?>>All products</option>
                                        <option value="unplaced" <?= $placedFilter === 'unplaced' ? 'selected' : '' ?>>Not placed yet</option>
                                        <option value="placed" <?= $placedFilter === 'placed' ? 'selected' : '' ?>>Already placed</option>
                                    </select>
                                </div>
                                <div class="sms-filter-field">
                                    <label for="filterPerPage">Products per page</label>
                                    <select name="per_page" id="filterPerPage">
                                        <?php foreach ([1 => '1 (wide landscape)', 2 => '2', 4 => '4', 6 => '6', 8 => '8'] as $value => $label): ?>
                                            <option value="<?= $value ?>" <?= $perPageDefault === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="sms-filter-actions">
                                    <button type="submit" class="sms-filter-apply-btn" id="applyFiltersBtn">Apply Filters</button>
                                    <a href="<?= htmlspecialchars($labelPageUrl) ?>" class="sms-filter-clear-btn">Clear</a>
                                </div>
                            </div>
                        </form>
                    </aside>
                </div>
            </div>

            <div class="sms-label-actions">
                <label class="text-sm text-slate-600 flex items-center gap-2">
                    <input type="checkbox" id="selectAllLabels" class="rounded border-slate-300">
                    Select all
                </label>
                <button type="submit" class="sms-btn-generate" id="generatePdfBtn" form="labelForm" disabled>
                    <i class="fas fa-file-pdf mr-1"></i> Download PDF
                </button>
            </div>
        </div>

        <form id="labelForm" method="post" action="<?= htmlspecialchars($labelDownloadUrl) ?>">
            <input type="hidden" name="per_page" id="labelPerPage" value="<?= $perPageDefault ?>">
            <div class="sms-label-grid">
                <div class="sms-label-table-card">
                    <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between gap-3 flex-wrap">
                        <h3 class="font-bold text-slate-700">Products</h3>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-semibold bg-amber-100 text-amber-700 px-2 py-1 rounded-full" id="placedCountBadge">
                                <i class="fas fa-star mr-1"></i><?= $placedCount ?> placed
                            </span>
                            <span class="text-xs font-semibold bg-slate-100 text-slate-600 px-2 py-1 rounded-full"><?= count($products) ?> items</span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="sms-label-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;" title="Mark label as placed">Placed</th>
                                    <th style="width:40px;"></th>
                                    <th style="width:56px;">Image</th>
                                    <th>Product</th>
                                    <th>Code</th>
                                    <th style="width:90px;">Labels</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-slate-400 py-10">No products found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $idx => $product):
                                        $pid = (int) $product['id'];
                                        $isPlaced = (int) ($product['label_placed'] ?? 0) === 1;
                                        $imageUrl = function_exists('stock_product_list_image_url')
                                            ? stock_product_list_image_url($pid, (string) ($product['image_file'] ?? ''), 'medium', $stockBasePath)
                                            : '';
                                        $imageAbs = sms_label_absolute_url($imageUrl);
                                    ?>
                                        <tr class="label-product-row<?= $isPlaced ? ' is-placed' : '' ?>" data-product-id="<?= $pid ?>"
                                            data-code="<?= htmlspecialchars($product['product_code'] ?? '', ENT_QUOTES) ?>"
                                            data-name="<?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES) ?>"
                                            data-image="<?= htmlspecialchars($imageAbs, ENT_QUOTES) ?>"
                                            data-placed="<?= $isPlaced ? '1' : '0' ?>">
                                            <td class="label-star-cell">
                                                <button type="button"
                                                    class="label-star-btn<?= $isPlaced ? ' is-placed' : '' ?>"
                                                    data-product-id="<?= $pid ?>"
                                                    data-placed="<?= $isPlaced ? '1' : '0' ?>"
                                                    title="<?= $isPlaced ? 'Label already placed  click to unmark' : 'Mark label as placed' ?>"
                                                    aria-label="<?= $isPlaced ? 'Label placed' : 'Mark label as placed' ?>">
                                                    <i class="<?= $isPlaced ? 'fas' : 'far' ?> fa-star"></i>
                                                </button>
                                            </td>
                                            <td class="label-select-cell<?= $isPlaced ? ' is-hidden' : '' ?>">
                                                <input type="checkbox" class="label-check rounded border-slate-300" name="product_ids[]" value="<?= $pid ?>"<?= $isPlaced ? ' disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <?php if ($imageUrl !== ''): ?>
                                                    <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="sms-label-thumb">
                                                <?php else: ?>
                                                    <div class="sms-label-thumb-fallback"><i class="fas fa-box"></i></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="font-semibold text-slate-800"><?= htmlspecialchars($product['name'] ?? '') ?></div>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($product['category_name'] ?? '') ?></div>
                                            </td>
                                            <td class="font-mono text-xs text-slate-600"><?= htmlspecialchars($product['product_code'] ?? '') ?></td>
                                            <td class="label-qty-cell<?= $isPlaced ? ' is-hidden' : '' ?>">
                                                <input type="number" class="sms-qty-input label-qty" name="quantities[<?= $pid ?>]" value="1" min="1" max="99" disabled>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="sms-label-preview-card">
                    <h3 class="font-bold text-slate-700 mb-3">Label Preview</h3>
                    <div id="labelPreview" class="label-preview-sheet layout-1">
                        <div class="label-preview-empty">Select a product to preview the label format.</div>
                    </div>
                    <p class="text-xs text-slate-400 mt-3 leading-relaxed">
                        Default is <strong>1 product per page</strong> wide landscape with a large image and product details. Click <strong>Download PDF</strong> to generate and save the file directly.
                    </p>
                </div>
            </div>
        </form>
    </div>
</main>

<div id="labelDownloadOverlay" class="sms-download-overlay" aria-hidden="true">
    <div class="sms-download-modal" role="status" aria-live="polite">
        <div class="sms-download-icon"><i class="fas fa-file-pdf"></i></div>
        <div class="sms-download-error-icon" aria-hidden="true"><i class="fas fa-exclamation-circle"></i></div>
        <div class="sms-download-spinner" aria-hidden="true"></div>
        <p id="labelDownloadStatus" class="sms-download-status">Processing...</p>
        <div class="sms-download-progress">
            <div id="labelDownloadBar" class="sms-download-bar"></div>
        </div>
    </div>
</div>

<div id="labelSuccessSheet" class="sms-success-sheet" aria-hidden="true">
    <div class="sms-success-sheet-backdrop" id="successSheetBackdrop"></div>
    <div class="sms-success-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="successSheetTitle">
        <div class="sms-success-sheet-handle" aria-hidden="true"></div>
        <div class="sms-success-sheet-icon"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
        <h2 id="successSheetTitle" class="sms-success-sheet-title">Download successful!</h2>
        <p class="sms-success-sheet-filename" id="successSheetFilename"></p>
        <p class="sms-success-sheet-hint">Your label PDF has been saved. Share it with your team or open it from your downloads.</p>
        <div class="sms-success-sheet-actions">
            <button type="button" class="sms-success-share-btn" id="successShareBtn">
                <i class="fas fa-share-alt" aria-hidden="true"></i>
                <span>Share PDF</span>
            </button>
            <button type="button" class="sms-success-done-btn" id="successDoneBtn">Done</button>
        </div>
    </div>
</div>

<script>
(function () {
    const labelDownloadUrl = <?= json_encode($labelDownloadUrl) ?>;
    const labelStarUrl = <?= json_encode($labelStarUrl) ?>;
    const initialPlacedCount = <?= (int) $placedCount ?>;
    const checks = document.querySelectorAll('.label-check');
    const qtyInputs = document.querySelectorAll('.label-qty');
    const selectAll = document.getElementById('selectAllLabels');
    const generateBtn = document.getElementById('generatePdfBtn');
    const labelForm = document.getElementById('labelForm');
    const preview = document.getElementById('labelPreview');
    const perPageSelect = document.getElementById('filterPerPage');
    const labelPerPage = document.getElementById('labelPerPage');
    const filterForm = document.getElementById('filterForm');
    const filterSearch = document.getElementById('filterSearch');
    const overlay = document.getElementById('labelDownloadOverlay');
    const statusEl = document.getElementById('labelDownloadStatus');
    const progressBar = document.getElementById('labelDownloadBar');
    const successSheet = document.getElementById('labelSuccessSheet');
    const successSheetFilename = document.getElementById('successSheetFilename');
    const successShareBtn = document.getElementById('successShareBtn');
    const successDoneBtn = document.getElementById('successDoneBtn');
    const successSheetBackdrop = document.getElementById('successSheetBackdrop');
    const downloadBtnHtml = generateBtn ? generateBtn.innerHTML : '';
    let lastDownloadBlob = null;
    let lastDownloadFilename = '';
    let mobileSuccessSheetShown = false;
    let downloadInProgress = false;

    function isMobileDevice() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }

    function canSharePdfFile(blob, filename) {
        if (!navigator.share) {
            return false;
        }
        if (!navigator.canShare) {
            return true;
        }
        try {
            const file = new File([blob], filename, { type: 'application/pdf' });
            return navigator.canShare({ files: [file] });
        } catch (err) {
            return false;
        }
    }

    function cleanupSuccessDownload() {
        lastDownloadBlob = null;
        lastDownloadFilename = '';
    }

    function showSuccessSheet(blob, filename) {
        lastDownloadBlob = blob;
        lastDownloadFilename = filename;
        if (successSheetFilename) {
            successSheetFilename.textContent = filename;
        }
        if (successShareBtn) {
            const canShare = canSharePdfFile(blob, filename);
            successShareBtn.style.display = canShare ? '' : 'none';
        }
        successSheet?.classList.add('is-open');
        successSheet?.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function hideSuccessSheet() {
        successSheet?.classList.remove('is-open');
        successSheet?.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        cleanupSuccessDownload();
    }

    async function shareDownloadedPdf() {
        if (!lastDownloadBlob || !lastDownloadFilename) {
            return;
        }

        const file = new File([lastDownloadBlob], lastDownloadFilename, { type: 'application/pdf' });
        const shareData = {
            files: [file],
            title: 'Product Labels',
            text: 'Product label PDF'
        };

        try {
            if (navigator.share) {
                if (!navigator.canShare || navigator.canShare(shareData)) {
                    await navigator.share(shareData);
                    return;
                }
                await navigator.share({
                    title: 'Product Labels',
                    text: 'Product labels PDF: ' + lastDownloadFilename
                });
            }
        } catch (err) {
            if (err && err.name !== 'AbortError') {
                console.error('Share failed:', err);
            }
        }
    }

    successShareBtn?.addEventListener('click', shareDownloadedPdf);
    successDoneBtn?.addEventListener('click', hideSuccessSheet);
    successSheetBackdrop?.addEventListener('click', hideSuccessSheet);

    function setProgress(percent, message) {
        if (progressBar) {
            progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (message && statusEl) {
            statusEl.textContent = message;
        }
    }

    function parseDownloadErrorMessage(response, rawText) {
        const status = response ? response.status : 0;
        const text = String(rawText || '').trim();

        if (status === 429 || /too many requests/i.test(text)) {
            return 'Too many download requests. Please wait a minute, then try again.';
        }
        if (status === 503) {
            return 'The server is busy. Please wait a moment and try again.';
        }
        if (status === 403) {
            return 'Download was blocked. Please refresh the page and try again.';
        }

        if (text.startsWith('{')) {
            try {
                const json = JSON.parse(text);
                if (json.message) {
                    return String(json.message);
                }
                if (json.error) {
                    return String(json.error);
                }
            } catch (err) {
                // Not JSON  fall through.
            }
        }

        if (/<html|<body|<h1/i.test(text)) {
            try {
                const doc = new DOMParser().parseFromString(text, 'text/html');
                const heading = doc.querySelector('h1')?.textContent?.trim();
                if (heading && /too many requests/i.test(heading)) {
                    return 'Too many download requests. Please wait a minute, then try again.';
                }
                if (heading) {
                    return heading.replace(/\s+/g, ' ');
                }
            } catch (err) {
                // Ignore parse errors.
            }
            return 'The server could not process your download. Please try again later.';
        }


        if (text) {
            return text.length > 220 ? text.slice(0, 220) + '...' : text;
        }

        if (status >= 500) {
            return 'The server could not generate your PDF. Please try again later.';
        }

        return 'Failed to generate PDF.';
    }

    function showOverlay() {
        overlay?.classList.remove('is-error');
        overlay?.classList.add('is-active');
        overlay?.setAttribute('aria-hidden', 'false');
    }

    function showDownloadError(message) {
        overlay?.classList.add('is-error');
        setProgress(0, message);
    }

    function hideOverlay() {
        overlay?.classList.remove('is-active', 'is-error');
        overlay?.setAttribute('aria-hidden', 'true');
        setProgress(0, 'Processing...');
    }

    function extractFilename(response) {
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/i);
        if (match && match[1]) {
            return match[1];
        }
        return 'product-labels-' + new Date().toISOString().slice(0, 10) + '.pdf';
    }

    async function downloadLabelsPdf() {
        if (!labelForm || downloadInProgress) {
            return;
        }

        downloadInProgress = true;
        mobileSuccessSheetShown = false;
        const formData = new FormData(labelForm);

        if (generateBtn) {
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Processing...';
        }

        showOverlay();
        setProgress(15, 'Processing...');

        let errorWaitMs = 2200;

        try {
            setProgress(35, 'Preparing your PDF...');

            const response = await fetch(labelDownloadUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const contentType = (response.headers.get('Content-Type') || '').toLowerCase();

            if (!response.ok) {
                const errorText = await response.text();
                if (response.status === 429) {
                    errorWaitMs = 4000;
                }
                throw new Error(parseDownloadErrorMessage(response, errorText));
            }

            if (!contentType.includes('application/pdf')) {
                const errorText = await response.text();
                throw new Error(parseDownloadErrorMessage(response, errorText));
            }

            setProgress(75, 'Almost ready...');

            const blob = await response.blob();
            if (!blob || blob.size === 0) {
                throw new Error('Generated PDF is empty.');
            }

            const filename = extractFilename(response);
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(objectUrl);

            setProgress(100, 'Download complete!');

            if (isMobileDevice()) {
                await new Promise((resolve) => setTimeout(resolve, 450));
                hideOverlay();
                showSuccessSheet(blob, filename);
                mobileSuccessSheetShown = true;
            } else {
                await new Promise((resolve) => setTimeout(resolve, 900));
            }
        } catch (err) {
            console.error('Label PDF download error:', err);
            showDownloadError(err.message || 'PDF download failed. Please try again.');
            await new Promise((resolve) => setTimeout(resolve, errorWaitMs));
        } finally {
            downloadInProgress = false;
            if (!mobileSuccessSheetShown) {
                hideOverlay();
            }
            if (generateBtn) {
                generateBtn.disabled = !Array.from(checks).some((c) => c.checked);
                generateBtn.innerHTML = downloadBtnHtml;
            }
        }
    }

    labelForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        downloadLabelsPdf();
    });

    let placedCount = initialPlacedCount;
    const placedCountBadge = document.getElementById('placedCountBadge');

    function updatePlacedCount(delta) {
        placedCount = Math.max(0, placedCount + delta);
        if (placedCountBadge) {
            placedCountBadge.innerHTML = '<i class="fas fa-star mr-1"></i>' + placedCount + ' placed';
        }
    }

    function setRowPlacedState(row, btn, isPlaced) {
        const placedValue = isPlaced ? '1' : '0';
        const check = row?.querySelector('.label-check');
        const qty = row?.querySelector('.label-qty');
        const selectCell = row?.querySelector('.label-select-cell');
        const qtyCell = row?.querySelector('.label-qty-cell');

        row?.classList.toggle('is-placed', isPlaced);
        row?.setAttribute('data-placed', placedValue);
        btn?.classList.toggle('is-placed', isPlaced);
        btn?.setAttribute('data-placed', placedValue);
        btn?.setAttribute('title', isPlaced ? 'Label already placed  click to unmark' : 'Mark label as placed');
        btn?.setAttribute('aria-label', isPlaced ? 'Label placed' : 'Mark label as placed');
        if (btn) {
            btn.innerHTML = '<i class="' + (isPlaced ? 'fas' : 'far') + ' fa-star"></i>';
        }

        selectCell?.classList.toggle('is-hidden', isPlaced);
        qtyCell?.classList.toggle('is-hidden', isPlaced);

        if (check) {
            if (isPlaced) {
                check.checked = false;
                check.disabled = true;
            } else {
                check.disabled = false;
            }
        }
        if (qty) {
            qty.disabled = true;
        }

        syncQtyState();
    }

    document.querySelectorAll('.label-star-btn').forEach((btn) => {
        btn.addEventListener('mousedown', (event) => {
            event.stopPropagation();
        });

        btn.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            const productId = parseInt(btn.getAttribute('data-product-id') || '0', 10);
            if (!productId || btn.disabled) {
                return;
            }

            const currentlyPlaced = btn.getAttribute('data-placed') === '1';
            const nextPlaced = !currentlyPlaced;
            const row = btn.closest('.label-product-row');

            btn.disabled = true;

            try {
                const body = new FormData();
                body.append('product_id', String(productId));
                body.append('placed', nextPlaced ? '1' : '0');

                const response = await fetch(labelStarUrl, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin'
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Could not update label status.');
                }

                setRowPlacedState(row, btn, !!data.placed);
                updatePlacedCount(data.placed ? 1 : -1);
            } catch (err) {
                console.error('Label star toggle error:', err);
                alert(err.message || 'Could not update label status.');
            } finally {
                btn.disabled = false;
            }
        });
    });

    function applyPreviewLayout() {
        const perPage = perPageSelect ? perPageSelect.value : '1';
        preview.classList.remove('layout-1', 'layout-2', 'layout-4', 'layout-6', 'layout-8');
        preview.classList.add('layout-' + perPage);
    }

    function buildPreviewHtml(code, name, image, perPage) {
        const imageHtml = image
            ? `<img src="${image}" alt="" class="label-preview-image">`
            : `<div class="label-preview-image flex items-center justify-center text-slate-300"><i class="fas fa-image fa-2x"></i></div>`;
        const detailsHtml = `
            <div class="label-preview-line">PRODUCT CODE: ${escapeHtml(code)}</div>
            <div class="label-preview-line">PRODUCT NAME : ${escapeHtml(name)}</div>
            <div class="label-preview-line">SIZE(s) :</div>
        `;

        if (perPage === '1') {
            return `${imageHtml}<div class="label-preview-details">${detailsHtml}</div>`;
        }

        return `${imageHtml}${detailsHtml}`;
    }

    function renderPreview(row) {
        if (!row) {
            preview.innerHTML = '<div class="label-preview-empty">Select a product to preview the label format.</div>';
            return;
        }
        const perPage = perPageSelect ? perPageSelect.value : '1';
        preview.innerHTML = buildPreviewHtml(
            row.dataset.code || '',
            row.dataset.name || '',
            row.dataset.image || '',
            perPage
        );
        applyPreviewLayout();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    checks.forEach((check, i) => {
        check.addEventListener('change', () => {
            syncQtyState();
            if (check.checked) {
                renderPreview(check.closest('.label-product-row'));
            }
        });
        check.closest('.label-product-row')?.addEventListener('click', (e) => {
            const row = check.closest('.label-product-row');
            if (row?.getAttribute('data-placed') === '1') {
                return;
            }
            if (e.target.closest('.label-star-cell, .label-star-btn, input, button, a, label')) {
                return;
            }
            check.checked = !check.checked;
            check.dispatchEvent(new Event('change'));
        });
    });

    selectAll?.addEventListener('change', () => {
        checks.forEach(c => { c.checked = selectAll.checked; });
        syncQtyState();
        if (selectAll.checked && checks.length) {
            renderPreview(checks[0].closest('.label-product-row'));
        }
    });

    function updateGenerateState() {
        const any = Array.from(checks).some(c => c.checked);
        generateBtn.disabled = !any;
    }

    function syncQtyState() {
        checks.forEach((check, i) => {
            if (qtyInputs[i]) {
                qtyInputs[i].disabled = !check.checked;
            }
        });
        updateGenerateState();
    }

    perPageSelect?.addEventListener('change', () => {
        if (labelPerPage) {
            labelPerPage.value = perPageSelect.value;
        }
        applyPreviewLayout();
        const checked = Array.from(checks).find(c => c.checked);
        if (checked) {
            renderPreview(checked.closest('.label-product-row'));
        }
    });

    filterForm?.addEventListener('submit', () => {
        if (labelPerPage && perPageSelect) {
            labelPerPage.value = perPageSelect.value;
        }
        closeFilterDrawer();
    });

    filterSearch?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            filterForm?.requestSubmit();
        }
    });

    syncQtyState();
    applyPreviewLayout();

    const filterDrawer = document.getElementById('filterDrawer');
    const filterWrap = document.getElementById('filterWrap');
    const openFiltersBtn = document.getElementById('openFiltersBtn');
    const closeFiltersBtn = document.getElementById('closeFiltersBtn');
    const filterBackdrop = document.getElementById('filterBackdrop');

    function isMobileFilter() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }

    function openFilterDrawer() {
        const alreadyOpen = filterDrawer?.classList.contains('is-open');
        if (alreadyOpen) {
            closeFilterDrawer();
            return;
        }
        filterDrawer?.classList.add('is-open');
        filterDrawer?.setAttribute('aria-hidden', 'false');
        openFiltersBtn?.setAttribute('aria-expanded', 'true');
        if (!isMobileFilter()) {
            document.body.style.overflow = 'hidden';
        }
    }

    function closeFilterDrawer() {
        filterDrawer?.classList.remove('is-open');
        filterDrawer?.setAttribute('aria-hidden', 'true');
        openFiltersBtn?.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    openFiltersBtn?.addEventListener('click', (event) => {
        event.stopPropagation();
        openFilterDrawer();
    });
    closeFiltersBtn?.addEventListener('click', closeFilterDrawer);
    filterBackdrop?.addEventListener('click', closeFilterDrawer);

    document.addEventListener('click', (event) => {
        if (!filterDrawer?.classList.contains('is-open')) {
            return;
        }
        if (isMobileFilter() && filterWrap && !filterWrap.contains(event.target)) {
            closeFilterDrawer();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && successSheet?.classList.contains('is-open')) {
            hideSuccessSheet();
            return;
        }
        if (event.key === 'Escape' && filterDrawer?.classList.contains('is-open')) {
            closeFilterDrawer();
        }
    });
})();
</script>

<?php include __DIR__ . '/../stock/includes/footer.php'; ?>
