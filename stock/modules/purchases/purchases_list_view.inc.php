<?php
/** @var array<int,array<string,mixed>> $purchases */
/** @var callable $poStatusClass */
$filterQs = static function (array $extra = []) use ($showDomestic, $showImport, $search): string {
    $params = array_merge([
        'domestic' => $showDomestic,
        'import' => $showImport,
    ], $extra);
    if ($search !== '') {
        $params['search'] = $search;
    }
    return '?' . http_build_query($params);
};
$dashboardUrl = isset($stockBasePath) ? rtrim((string) $stockBasePath, '/') . '/dashboard.php' : '../../dashboard.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .po-page { background: #f8fafc; color: #0f172a; }
    .po-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .po-btn-primary { background: #7c3aed; color: #fff; border: 1px solid #7c3aed; }
    .po-btn-primary:hover { background: #6d28d9; color: #fff; }
    .po-btn-outline { background: #fff; color: #334155; border: 1px solid #e2e8f0; }
    .po-btn-outline:hover { background: #f8fafc; }
    .po-tab { color: #64748b; border-bottom: 2px solid transparent; padding-bottom: 10px; margin-bottom: -1px; font-size: 14px; font-weight: 500; }
    .po-tab.active { color: #7c3aed; border-bottom-color: #7c3aed; font-weight: 600; }
    .po-stats-grid .po-stat-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        gap: 0.75rem;
        padding: 1.25rem 1rem;
    }
    .po-stats-grid .po-stat-card .po-stat-body {
        width: 100%;
        min-width: 0;
    }
    .po-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }
    .type-pill-domestic, .type-pill-import { font-size: 9px; line-height: 1.2; letter-spacing: 0.04em; font-weight: 600; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; }
    .type-pill-domestic { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .type-pill-import { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .po-status-pill { font-size: 9px; line-height: 1.2; letter-spacing: 0.02em; font-weight: 600; padding: 3px 8px; border-radius: 4px; border: 1px solid transparent; }
    .po-status-approved { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
    .po-status-pending { background: #ffedd5; color: #c2410c; border-color: #fed7aa; }
    .po-status-received { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
    .po-status-rejected { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
    .po-status-draft { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
    .po-table thead th { background: #f8fafc; color: #64748b; font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    .po-table tbody td { border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
    .po-table tbody tr:hover { background: #fafafa; }
    .po-menu { display: none; position: absolute; right: 0; top: 100%; margin-top: 4px; min-width: 220px; z-index: 50; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 25px rgba(15,23,42,.08); padding: 4px 0; }
    .po-menu.show { display: block; }
    .po-menu a, .po-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 12px; font-size: 13px; color: #374151; text-align: left; background: transparent; border: none; cursor: pointer; }
    .po-menu a:hover, .po-menu button:hover { background: #f8fafc; }
    .po-menu-divider { border-top: 1px solid #f1f5f9; margin: 4px 0; }
    .po-action-btn { width: 32px; height: 32px; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; background: #fff; color: #64748b; }
    .po-action-btn.view { background: #ecfdf5; border-color: #bbf7d0; color: #16a34a; }
    .po-action-btn.view:hover { background: #dcfce7; }
    .purchases-table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
    #purchasesTable {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
    }
    #purchasesTable th,
    #purchasesTable td {
        overflow: hidden;
        vertical-align: middle;
        padding: 0.65rem 0.5rem;
    }
    #purchasesTable thead th {
        white-space: nowrap;
        font-size: 10px;
        letter-spacing: 0.03em;
    }
    #purchasesTable .cell-truncate {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
        max-width: 100%;
    }
    #purchasesTable .cell-product-inner {
        min-width: 0;
        max-width: 100%;
    }
    #purchasesTable .cell-product-name {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 13px;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.3;
    }
    #purchasesTable .cell-product-code {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 11px;
        color: #94a3b8;
        margin-top: 2px;
    }
    #purchasesTable col.col-product { width: 15%; }
    #purchasesTable col.col-supplier { width: 12%; }
    #purchasesTable col.col-po { width: 8%; }
    #purchasesTable col.col-type { width: 6%; }
    #purchasesTable col.col-qty { width: 5%; }
    #purchasesTable col.col-total { width: 9%; }
    #purchasesTable col.col-status { width: 8%; }
    #purchasesTable col.col-paystatus { width: 8%; }
    #purchasesTable col.col-date { width: 8%; }
    #purchasesTable col.col-docs { width: 4%; }
    #purchasesTable col.col-actions { width: 13%; }
    #purchasesTable .po-col-status .po-status-pill {
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: inline-block;
        vertical-align: middle;
    }
    #purchasesTable .po-actions-wrap {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.25rem;
        max-width: 100%;
    }
    #purchasesTable td.po-col-actions {
        overflow: visible;
    }
    #purchasesTable .po-menu {
        right: 0;
        left: auto;
    }
    .po-mobile-list {
        display: none;
    }
    .po-mobile-card {
        padding: 1rem 1rem 0.85rem;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
    }
    .po-mobile-card:last-child {
        border-bottom: none;
    }
    .po-mobile-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.5rem;
    }
    .po-mobile-po-no {
        font-size: 0.875rem;
        font-weight: 600;
        color: #2563eb;
        text-decoration: none;
        word-break: break-all;
        line-height: 1.3;
    }
    .po-mobile-product {
        margin: 0 0 0.35rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .po-mobile-badge {
        display: inline-block;
        margin-bottom: 0.35rem;
        padding: 0.15rem 0.45rem;
        border-radius: 4px;
        font-size: 0.65rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #64748b;
    }
    .po-mobile-code,
    .po-mobile-supplier {
        margin: 0 0 0.25rem;
        font-size: 0.75rem;
        color: #64748b;
        line-height: 1.3;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .po-mobile-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-top: 0.5rem;
        font-size: 0.75rem;
        color: #64748b;
    }
    .po-mobile-total {
        font-weight: 600;
        color: #16a34a;
        white-space: nowrap;
    }
    .po-mobile-card-foot {
        display: flex;
        justify-content: flex-end;
        margin-top: 0.65rem;
        padding-top: 0.5rem;
    }
    .po-mobile-card-foot .po-actions-wrap {
        position: relative;
    }
    @media (max-width: 767px) {
        .po-mobile-list {
            display: block;
        }
        .purchases-table-desktop {
            display: none !important;
        }
    }
    @media (min-width: 768px) {
        .po-mobile-list {
            display: none !important;
        }
    }
    @media (max-width: 992px) {
        .po-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        html body .main-content, html body.dashboard .main-content { margin-left: 0 !important; width: 100% !important; }
    }
    @media (max-width: 768px) {
        .po-quick-po-btns { display: none !important; }
        .po-page-header-intro { display: none !important; }
        .po-page-header { display: none !important; }
        .po-top-bar {
            margin-bottom: 1rem !important;
            flex-wrap: nowrap !important;
            gap: 0.5rem !important;
        }
        .po-top-bar .po-breadcrumbs {
            font-size: 0.75rem;
            gap: 0.25rem;
        }
        .po-top-bar .po-breadcrumbs .po-crumb-mid {
            display: none;
        }
        .po-top-bar .po-btn-new-po {
            padding: 0.5rem 0.65rem;
            font-size: 0.8125rem;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .po-top-bar .po-btn-new-po .po-btn-new-po-label-long { display: none; }
        .po-top-bar .po-btn-new-po .po-btn-new-po-label-short { display: inline; }
        .po-mobile-search-row {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            max-width: 100%;
            width: 100%;
        }
        .po-mobile-search-row .po-filter-icon-btn {
            flex-shrink: 0;
            width: auto;
            min-width: 40px;
            height: 44px;
            padding: 0 0.15rem 0 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #0f172a;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: none;
        }
        .po-mobile-search-row .po-filter-icon-btn:hover,
        .po-mobile-search-row .po-filter-icon-btn:focus {
            background: #f1f5f9;
            outline: none;
        }
        .po-mobile-search-row .po-filter-icon-btn i {
            font-size: 1.35rem;
            line-height: 1;
        }
        .po-mobile-search-row .po-mobile-search-form {
            flex: 1 1 auto;
            min-width: 0;
            width: 82%;
            max-width: 340px;
        }
        .po-mobile-search-row .po-mobile-search-form input[type="text"] {
            width: 100%;
            min-height: 44px;
        }
        .po-mobile-search-clear {
            flex-shrink: 0;
            font-size: 12px;
            padding: 0.5rem 0.65rem;
            white-space: nowrap;
        }
    }
    .po-top-bar {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        width: 100%;
    }
    .po-top-bar .po-breadcrumbs {
        flex: 1 1 0;
        min-width: 0;
        margin-bottom: 0;
        flex-wrap: nowrap;
        overflow: hidden;
        white-space: nowrap;
    }
    .po-top-bar .po-btn-new-po {
        flex-shrink: 0;
    }
    .po-btn-new-po .po-btn-new-po-label-short { display: none; }
    /* PO list: bell on header row — top right, same line as hamburger */
    @media (max-width: 768px) {
        body.po-list-page .employee-header .header-content {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 0.5rem !important;
        }
        body.po-list-page .employee-header .header-left {
            flex: 0 0 auto;
            margin-right: 0 !important;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        body.po-list-page .employee-header .header-actions-tray {
            flex: 0 0 auto;
            margin-left: auto !important;
            display: flex !important;
            align-items: center;
            gap: 0.5rem;
        }
        body.po-list-page .employee-header .header-actions-tray > .notif {
            display: flex !important;
        }
        body.po-list-page .employee-header .header-actions-tray .d-none.d-md-flex {
            display: none !important;
        }
    }
</style>
<script>document.body.classList.add('po-list-page');</script>

<main class="main-content po-page min-h-screen pb-10">
    
    <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <!-- Breadcrumbs + New PO -->
        <div class="po-top-bar mb-5">
            <nav class="po-breadcrumbs text-sm text-slate-500 flex items-center gap-2" aria-label="Breadcrumb">
                <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="hover:text-violet-600 shrink-0">Dashboard</a>
                <span class="text-slate-300 shrink-0 po-crumb-mid">/</span>
                <span class="text-slate-600 shrink-0 po-crumb-mid">Purchases</span>
                <span class="text-slate-300 shrink-0">/</span>
                <span class="text-slate-900 font-medium truncate min-w-0">Purchase Orders</span>
            </nav>
            <a href="domestic_create.php" class="po-btn-new-po po-btn-primary px-4 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2 no-underline shadow-sm shrink-0">
                <i class="fas fa-plus text-xs"></i>
                <span class="po-btn-new-po-label-long">New Purchase Order</span>
                <span class="po-btn-new-po-label-short">New PO</span>
            </a>
        </div>

        <!-- Mobile: filter + search -->
        <div class="po-mobile-search-top mb-4 md:hidden">
            <div class="po-mobile-search-row">
                <button type="button" id="poFiltersToggleMobile" class="po-filter-icon-btn" aria-label="Filters" title="Filters">
                    <i class="fas fa-sliders-h" aria-hidden="true"></i>
                </button>
                <form method="GET" action="index.php" class="relative po-mobile-search-form">
                    <input type="hidden" name="domestic" value="<?= (int) $showDomestic ?>">
                    <input type="hidden" name="import" value="<?= (int) $showImport ?>">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input type="text" name="search" placeholder="Search PO, supplier..." value="<?= htmlspecialchars($search) ?>"
                           class="w-full pl-9 pr-3 py-2.5 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-violet-200 focus:border-violet-400">
                </form>
                <?php if ($search !== ''): ?>
                    <a href="<?= htmlspecialchars($filterQs(['page' => 1, 'search' => ''])) ?>" class="po-mobile-search-clear po-btn-outline rounded-lg text-sm no-underline">Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Page header -->
        <div class="po-page-header flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-6">
            <div class="po-page-header-intro flex items-start gap-4">
                <div class="w-12 h-12 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center shrink-0">
                    <i class="fas fa-file-invoice text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900 m-0">All Purchase Orders</h1>
                    <p class="text-sm text-slate-500 mt-1 mb-0">Manage and track all purchase orders in one place.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 po-header-actions">
                <a href="create.php" class="po-quick-po-btns po-btn-outline px-4 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-upload text-xs"></i> Abroad PO
                </a>
                <a href="domestic_create.php" class="po-quick-po-btns po-btn-outline px-4 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-truck-loading text-xs"></i> Internal PO
                </a>
            </div>
        </div>

        <!-- Tabs + search -->
        <div class="po-card px-4 sm:px-6 pt-4 mb-6">
            <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4 border-b border-slate-200 pb-0">
                <div class="flex flex-wrap items-center gap-6">
                    <a href="<?= htmlspecialchars($filterQs(['domestic' => 1, 'import' => 1, 'page' => 1])) ?>" class="po-tab no-underline <?= ($activePoTab ?? 'all') === 'all' ? 'active' : '' ?>">
                        Purchase Orders (<?= (int) ($poStatsTotal ?? 0) ?>)
                    </a>
                    <a href="<?= htmlspecialchars($filterQs(['domestic' => 1, 'import' => 0, 'page' => 1])) ?>" class="po-tab no-underline <?= ($activePoTab ?? '') === 'domestic' ? 'active' : '' ?>">Internal</a>
                    <a href="<?= htmlspecialchars($filterQs(['domestic' => 0, 'import' => 1, 'page' => 1])) ?>" class="po-tab no-underline <?= ($activePoTab ?? '') === 'import' ? 'active' : '' ?>">Abroad</a>
                </div>
                <div class="po-desktop-search hidden md:flex flex-wrap items-center gap-2 pb-3 xl:pb-4 w-full xl:w-auto">
                    <form method="GET" action="index.php" class="relative flex-1 min-w-[220px] xl:w-72">
                        <input type="hidden" name="domestic" value="<?= (int) $showDomestic ?>">
                        <input type="hidden" name="import" value="<?= (int) $showImport ?>">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" name="search" placeholder="Search PO, supplier, product..." value="<?= htmlspecialchars($search) ?>"
                               class="w-full pl-9 pr-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-200 focus:border-violet-400">
                    </form>
                    <?php if ($search !== ''): ?>
                        <a href="<?= htmlspecialchars($filterQs(['page' => 1, 'search' => ''])) ?>" class="po-btn-outline px-3 py-2 rounded-lg text-sm no-underline">Clear</a>
                    <?php endif; ?>
                    <button type="button" id="poFiltersToggle" class="po-btn-outline px-3 py-2 rounded-lg text-sm inline-flex items-center gap-2">
                        <i class="fas fa-sliders-h text-xs"></i> Filters
                    </button>
                </div>
            </div>
            <div id="poFiltersPanel" class="hidden py-3 border-t border-slate-100 text-sm text-slate-600">
                <span class="font-medium text-slate-700 mr-2">Type:</span>
                Internal <?= $showDomestic ? 'on' : 'off' ?> &middot; Abroad <?= $showImport ? 'on' : 'off' ?>
                — use the tabs above to switch filters.
            </div>
        </div>

        <!-- Stats cards -->
        
        
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-6 po-stats-grid">
            <div class="po-card po-stat-card">
                <div class="po-stat-icon bg-violet-100 text-violet-600"><i class="fas fa-clipboard-list"></i></div>
                <div class="po-stat-body">
                    <div class="text-xs text-slate-500">Total POs</div>
                    <div class="text-xl font-semibold text-slate-900"><?= (int) ($poStatsTotal ?? 0) ?></div>
                </div>
            </div>
            <div class="po-card po-stat-card">
                <div class="po-stat-icon bg-green-100 text-green-600"><i class="fas fa-check-circle"></i></div>
                <div class="po-stat-body">
                    <div class="text-xs text-slate-500">Approved</div>
                    <div class="text-xl font-semibold text-slate-900"><?= (int) ($poStatsApproved ?? 0) ?></div>
                    <div class="text-[11px] text-slate-400"><?= number_format((float) ($poStatsApprovedPct ?? 0), 1) ?>% of total</div>
                </div>
            </div>
            <div class="po-card po-stat-card">
                <div class="po-stat-icon bg-orange-100 text-orange-600"><i class="fas fa-clock"></i></div>
                <div class="po-stat-body">
                    <div class="text-xs text-slate-500">Pending</div>
                    <div class="text-xl font-semibold text-slate-900"><?= (int) ($poStatsPending ?? 0) ?></div>
                    <div class="text-[11px] text-slate-400"><?= number_format((float) ($poStatsPendingPct ?? 0), 1) ?>% of total</div>
                </div>
            </div>
            <div class="po-card po-stat-card">
                <div class="po-stat-icon bg-red-100 text-red-600"><i class="fas fa-times-circle"></i></div>
                <div class="po-stat-body">
                    <div class="text-xs text-slate-500">Rejected</div>
                    <div class="text-xl font-semibold text-slate-900"><?= (int) ($poStatsRejected ?? 0) ?></div>
                    <div class="text-[11px] text-slate-400"><?= number_format((float) ($poStatsRejectedPct ?? 0), 1) ?>% of total</div>
                </div>
            </div>
            <div class="po-card po-stat-card sm:col-span-2 xl:col-span-1">
                <div class="po-stat-icon bg-blue-100 text-blue-600"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="po-stat-body">
                    <div class="text-xs text-slate-500">Total Value</div>
                    <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($currency . number_format((float) ($poStatsValue ?? 0), 2)) ?></div>
                    <div class="text-[11px] text-slate-400">All currencies</div>
                </div>
            </div>
        </div>

        <!-- List / table -->
        <div class="po-card overflow-hidden">
            <?php include __DIR__ . '/includes/purchases_mobile_list.inc.php'; ?>
            <div class="purchases-table-wrapper purchases-table-desktop">
                <table class="po-table w-full text-left border-collapse" id="purchasesTable">
                    <colgroup>
                        <col class="col-po">
                        <col class="col-product">
                        <col class="col-supplier">
                        <col class="col-type">
                        <col class="col-qty">
                        <col class="col-total">
                        <col class="col-status">
                        <col class="col-paystatus">
                        <col class="col-date">
                        <col class="col-docs">
                        <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-3 py-3">PO Number</th>
                            <th class="px-3 py-3">Product</th>
                            <th class="px-3 py-3 po-col-supplier">Supplier</th>
                            <th class="px-3 py-3 text-center po-col-type">Type</th>
                            <th class="px-3 py-3 text-center po-col-qty">Qty</th>
                            <th class="px-3 py-3 text-right po-col-total">Total</th>
                            <th class="px-3 py-3 text-center po-col-status">Status</th>
                            <th class="px-3 py-3 text-center po-col-status">Payment</th>
                            <th class="px-3 py-3">Date</th>
                            <th class="px-3 py-3 text-center po-col-docs">Docs</th>
                            <th class="px-3 py-3 text-right pr-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchases)): ?>
                            <tr>
                                <td colspan="11" class="px-6 py-14 text-center text-sm text-slate-500">
                                    No purchase orders found.
                                    <a href="domestic_create.php" class="text-violet-600 hover:underline ml-1">Create one</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($purchases as $po):
                                $poId = (int) ($po['id'] ?? 0);
                                $status = (string) ($po['status'] ?? '');
                                $poTypeRaw = trim((string) ($po['purchase_type'] ?? 'domestic'));
                                $poType = in_array($poTypeRaw, ['domestic', 'import'], true) ? $poTypeRaw : 'domestic';
                                $isImport = $poType === 'import';
                                $itemCount = (int) ($po['item_count'] ?? 0);
                                $hasShipment = (int) ($po['has_shipment'] ?? 0) === 1;
                                $linkedShipmentId = (int) ($po['linked_shipment_id'] ?? 0);
                                $rowWf = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
                                $createdTs = strtotime((string) ($po['created_at'] ?? '')) ?: 0;
                                $createdLabel = $createdTs ? date('M j, Y', $createdTs) : '-';
                                $statusLabel = function_exists('purchaseDisplayStatusLabel')
                                    ? purchaseDisplayStatusLabel($status, $rowWf)
                                    : ($status !== '' ? $status : '-');
                                $canReceive = !in_array($status, ['Received', 'Cancelled'], true)
                                    && (!function_exists('purchaseStatusesBlockingReceive') || !in_array($status, purchaseStatusesBlockingReceive(), true))
                                    && (!$isImport || $hasShipment);
                                $attCount = (int) ($po['attachment_count'] ?? 0);
                                if ($attCount === 0 && !empty($po['invoice_attachment'])) {
                                    $attCount = 1;
                                }
                            ?>
                            <tr>
                                <td class="px-3 py-3">
                                    <a href="view_po.php?id=<?= $poId ?>" class="cell-truncate text-sm font-semibold text-blue-600 hover:text-blue-800 no-underline block" title="<?= htmlspecialchars((string) ($po['purchase_no'] ?? '')) ?>"><?= htmlspecialchars((string) ($po['purchase_no'] ?? '-')) ?></a>
                                </td>
                                <td class="px-3 py-3 cell-product">
                                    <div class="cell-product-inner">
                                        <span class="cell-product-name" title="<?= htmlspecialchars((string) ($po['product_name'] ?? '')) ?>"><?= htmlspecialchars((string) ($po['product_name'] ?? '-')) ?></span>
                                        <?php if ($itemCount > 1): ?>
                                            <span class="inline-flex mt-0.5 px-1.5 py-0.5 rounded text-[9px] bg-slate-100 text-slate-600">+<?= $itemCount - 1 ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($po['product_code'])): ?>
                                            <span class="cell-product-code" title="<?= htmlspecialchars((string) $po['product_code']) ?>"><?= htmlspecialchars((string) $po['product_code']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-3 py-3 po-col-supplier">
                                    <span class="cell-truncate text-xs font-medium text-slate-700 block" title="<?= htmlspecialchars((string) ($po['supplier_name'] ?? '')) ?>"><?= htmlspecialchars((string) ($po['supplier_name'] ?? '-')) ?></span>
                                </td>
                                <td class="px-3 py-3 text-center po-col-type">
                                    <?php if ($isImport): ?>
                                        <span class="type-pill-import">Abroad</span>
                                    <?php else: ?>
                                        <span class="type-pill-domestic">Internal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-center text-sm text-slate-700 po-col-qty"><?= number_format((float) ($po['total_qty'] ?? 0), 2) ?></td>
                                <td class="px-3 py-3 text-right text-sm font-semibold text-green-600 po-col-total" title="<?= htmlspecialchars(strtoupper(trim((string) ($po['currency'] ?? $defaultCurrencyCode)))) ?>">
                                    <span class="cell-truncate block"><?= htmlspecialchars($formatPoTotalDisplay($po)) ?></span>
                                </td>
                                <td class="px-3 py-3 text-center po-col-status">
                                    <span class="po-status-pill <?= $poStatusClass($status) ?>" title="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                </td>
                                <td class="px-3 py-3 text-center po-col-status">
                                    <?php 
                                    $payStatus = strtolower(trim((string) ($po['payment_status'] ?? 'unpaid')));
                                    $payStatusLabel = ucfirst($payStatus);
                                    $payStatusClass = ($payStatus === 'paid') 
                                        ? 'bg-green-100 text-green-800 border-green-200' 
                                        : 'bg-amber-100 text-amber-800 border-amber-200';
                                    ?>
                                    <span class="po-status-pill <?= $payStatusClass ?>" title="<?= htmlspecialchars($payStatusLabel) ?>"><?= htmlspecialchars($payStatusLabel) ?></span>
                                </td>
                                <td class="px-3 py-3 text-sm text-slate-600">
                                    <span class="cell-truncate block"><?= htmlspecialchars($createdLabel) ?></span>
                                </td>
                                <td class="px-3 py-3 text-center po-col-docs">
                                    <?php if (!$hasPurchaseAttachments && empty($po['invoice_attachment'])): ?>
                                        <span class="text-slate-300">&mdash;</span>
                                    <?php elseif ($attCount > 0): ?>
                                        <?php if (!empty($po['invoice_attachment'])): ?>
                                            <a href="download_invoice.php?id=<?= $poId ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 no-underline" title="View invoice">
                                                <i class="far fa-file-alt"></i> <?= $attCount ?>
                                            </a>
                                        <?php else: ?>
                                            <a href="open_attachment.php?purchase_id=<?= $poId ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 no-underline" title="<?= $attCount ?> attachment<?= $attCount === 1 ? '' : 's' ?>">
                                                <i class="far fa-file-alt"></i> <?= $attCount ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-300">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-right pr-3 po-col-actions">
                                    <div class="inline-flex items-center justify-end gap-1 relative po-actions-wrap">
                                        <a href="view_po.php?id=<?= $poId ?>" class="po-action-btn view no-underline" title="View PO"><i class="fas fa-eye text-sm"></i></a>
                                        <button type="button" class="po-action-btn po-actions-toggle" data-po-id="<?= $poId ?>" title="More actions" aria-expanded="false"><i class="fas fa-ellipsis-v text-sm"></i></button>
                                        
                                        <div class="po-menu" id="po-menu-<?= $poId ?>" role="menu">
                                            <a href="view_po.php?id=<?= $poId ?>"><i class="fas fa-file-alt text-gray-400 w-4"></i> View PO</a>
                                            <?php if (function_exists('purchaseOrderEditableStatuses') && in_array($status, purchaseOrderEditableStatuses($rowWf), true)): ?>
                                                <a href="edit.php?id=<?= $poId ?>"><i class="fas fa-edit text-blue-500 w-4"></i> Edit</a>
                                            <?php endif; ?>
                                            <?php if ($canReceive): ?>
                                                <a href="domestic_receive.php?id=<?= $poId ?>"><i class="fas fa-check-circle text-green-600 w-4"></i> Receive stock</a>
                                            <?php endif; ?>
                                            <?php if (!empty($po['invoice_attachment'])): ?>
                                                <a href="download_invoice.php?id=<?= $poId ?>" target="_blank" rel="noopener"><i class="fas fa-file-invoice text-green-600 w-4"></i> View invoice</a>
                                                <a href="download_invoice.php?id=<?= $poId ?>&download=1"><i class="fas fa-download text-green-600 w-4"></i> Download invoice</a>
                                            <?php endif; ?>
                                            <div class="po-menu-divider"></div>
                                            <a href="create.php?clone_from_id=<?= $poId ?>"><i class="fas fa-copy text-gray-400 w-4"></i> Clone order</a>
                                            <?php if (function_exists('purchaseCancelableStatuses') && in_array($status, purchaseCancelableStatuses($rowWf), true)): ?>
                                                <div class="po-menu-divider"></div>
                                                <a href="cancel.php?id=<?= $poId ?>" onclick="return confirm('Cancel this order?');"><i class="fas fa-times-circle text-red-500 w-4"></i> Cancel order</a>
                                            <?php endif; ?>
                                            <?php if ($isAdmin): ?>
                                                <div class="po-menu-divider"></div>
                                                <button type="button" class="text-red-600 po-delete" data-po-id="<?= $poId ?>"><i class="fas fa-trash-alt w-4"></i> Delete order</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- List footer -->
            <div class="px-4 py-3 border-t border-slate-100 shrink-0 text-sm text-slate-500">
                Showing all <?= (int) ($totalFiltered ?? 0) ?> purchase order<?= (int) ($totalFiltered ?? 0) === 1 ? '' : 's' ?>
            </div>
        </div>
    </div>
</main>

<?php if (!empty($po_lottie_show)): ?>
<?php include __DIR__ . '/includes/po-success-lottie.php'; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.body.classList.add('po-list-page');
    var filtersPanel = document.getElementById('poFiltersPanel');
    function bindFiltersToggle(btn) {
        if (!btn || !filtersPanel) return;
        btn.addEventListener('click', function() {
            filtersPanel.classList.toggle('hidden');
        });
    }
    bindFiltersToggle(document.getElementById('poFiltersToggle'));
    bindFiltersToggle(document.getElementById('poFiltersToggleMobile'));

    <?php if (!empty($poCreateSuccess)): ?>
    (function () {
        var poMsg = <?= json_encode(trim((string) ($po_lottie_message ?? ($poCreateSuccess['message'] ?? ''))), JSON_UNESCAPED_UNICODE) ?>;
        var poTitle = <?= json_encode(trim((string) (($poCreateSuccess['title'] ?? 'Success') . ': ' . ($poCreateSuccess['message'] ?? ''))), JSON_UNESCAPED_UNICODE) ?>;
        var toastTitle = poMsg !== '' ? poMsg : poTitle;
        if (window.StockSupplierSuccessLottie && window.StockSupplierSuccessLottie.show(toastTitle)) {
            return;
        }
        if (typeof Swal !== 'undefined') {
            Swal.fire({ toast: true, position: 'top-end', icon: <?= json_encode(($poCreateSuccess['variant'] ?? 'success') === 'warning' ? 'warning' : 'success') ?>, title: toastTitle, showConfirmButton: false, timer: 4500, timerProgressBar: true });
        }
    })();
    <?php endif; ?>

    <?php
    $swalMsg = '';
    $swalType = 'success';
    if (isset($_SESSION['success'])) {
        $swalMsg = (string) $_SESSION['success'];
        $swalType = (string) ($_SESSION['success_type'] ?? 'success');
        unset($_SESSION['success'], $_SESSION['success_type']);
    }
    ?>
    <?php if ($swalMsg !== ''): ?>
    if (typeof Swal !== 'undefined') {
        Swal.fire({ toast: true, position: 'top-end', icon: <?= json_encode($swalType === 'error' ? 'error' : 'success') ?>, title: <?= json_encode($swalMsg) ?>, showConfirmButton: false, timer: 4000, timerProgressBar: true });
    }
    <?php endif; ?>

    function closeAllPoMenus() {
        document.querySelectorAll('.po-menu.show').forEach(function(menu) { menu.classList.remove('show'); });
        document.querySelectorAll('.po-actions-toggle[aria-expanded="true"]').forEach(function(btn) { btn.setAttribute('aria-expanded', 'false'); });
    }
    document.querySelectorAll('.po-actions-toggle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var wrap = btn.closest('.po-actions-wrap');
            var menu = wrap ? wrap.querySelector('.po-menu') : null;
            if (!menu) {
                var id = btn.getAttribute('data-po-id');
                menu = id ? document.getElementById('po-menu-' + id) : null;
            }
            if (!menu) return;
            var isOpen = menu.classList.contains('show');
            closeAllPoMenus();
            if (!isOpen) { menu.classList.add('show'); btn.setAttribute('aria-expanded', 'true'); }
        });
    });
    document.addEventListener('click', closeAllPoMenus);
    document.querySelectorAll('.po-delete').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            closeAllPoMenus();
            var id = btn.getAttribute('data-po-id');
            var go = function() { window.location.href = 'delete.php?id=' + id; };
            var msg = 'Are you sure you want to PERMANENTLY DELETE this Purchase Order? This action cannot be undone.';
            if (window.StockAlert && window.StockAlert.confirm) { window.StockAlert.confirm(msg, 'Delete Order', go); }
            else if (window.confirm(msg)) { go(); }
        });
    });
});
</script>
