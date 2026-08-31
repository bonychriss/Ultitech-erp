<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/functions.php';
require_once __DIR__ . '/functions.php';
requireLogin();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = 'Vendor Bills';

$company_id = (int) (currentCompanyId() ?? 0);
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'supplier_id' => (int) ($_GET['supplier_id'] ?? 0),
    'payment_status' => trim((string) ($_GET['payment_status'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

if ($filters['supplier_id'] <= 0) {
    unset($filters['supplier_id']);
} else {
    $filters['supplier_id'] = (int) $filters['supplier_id'];
}

$allowedPaymentStatuses = ['unpaid', 'partially_paid', 'paid', 'overpaid'];
if (!in_array($filters['payment_status'], $allowedPaymentStatuses, true)) {
    $filters['payment_status'] = '';
}

$allowedStatuses = ['draft', 'posted', 'cancelled'];
if (!in_array($filters['status'], $allowedStatuses, true)) {
    $filters['status'] = '';
}

if ($filters['q'] === '') {
    unset($filters['q']);
}
if ($filters['date_from'] === '') {
    unset($filters['date_from']);
}
if ($filters['date_to'] === '') {
    unset($filters['date_to']);
}

$bills = [];
$totalRows = 0;
$totalPages = 1;
$suppliers = [];
$summary = [
    'total_bills' => 0,
    'draft_bills' => 0,
    'posted_bills' => 0,
    'unpaid_balance' => 0.0,
];

$userError = '';
$successMessage = '';
$tablesReady = vendorBillTableExists($pdo);

if (isset($_GET['success']) && $_GET['success'] === 'created') {
    $successMessage = 'Vendor bill draft created successfully.';
}

if ($company_id <= 0) {
    $userError = 'Company context is required to view vendor bills. Please sign in again or select a company.';
} elseif (!$tablesReady) {
    $userError = 'Vendor Bills are not available on this database yet. Please run the Phase 3B migration (vendor_bills tables).';
} else {
    try {
        $summaryStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_bills,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_bills,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS posted_bills,
                COALESCE(SUM(CASE WHEN status = ? AND balance_due > 0 THEN balance_due ELSE 0 END), 0) AS unpaid_balance
             FROM vendor_bills
             WHERE company_id = ?'
        );
        $summaryStmt->execute(['draft', 'posted', 'posted', $company_id]);
        $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['total_bills'] = (int) ($summaryRow['total_bills'] ?? 0);
        $summary['draft_bills'] = (int) ($summaryRow['draft_bills'] ?? 0);
        $summary['posted_bills'] = (int) ($summaryRow['posted_bills'] ?? 0);
        $summary['unpaid_balance'] = (float) ($summaryRow['unpaid_balance'] ?? 0);

        $totalRows = countVendorBills($pdo, $company_id, $filters);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $bills = listVendorBills($pdo, $company_id, $filters, $perPage, $offset);

        if (function_exists('tableExists') && tableExists('stocks_suppliers', $pdo)) {
            $supplierSql = 'SELECT id, name FROM stocks_suppliers';
            $supplierParams = [];
            if (function_exists('columnExists') && columnExists('stocks_suppliers', 'company_id', $pdo) && $company_id > 0) {
                $supplierSql .= ' WHERE company_id = ?';
                $supplierParams[] = $company_id;
            }
            $supplierSql .= ' ORDER BY name ASC';
            $supplierStmt = $pdo->prepare($supplierSql);
            $supplierStmt->execute($supplierParams);
            $suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        error_log('Vendor bills index error: ' . $e->getMessage());
        $userError = 'Unable to load vendor bills right now. Please try again later.';
        $bills = [];
        $totalRows = 0;
    }
}

$settings = getCompanySettings($pdo);
$currencySymbol = getCurrencySymbol($settings['currency'] ?? 'TZS');

$buildQueryString = static function (array $extra = []) use ($filters, $page): string {
    $params = array_filter([
        'q' => $filters['q'] ?? null,
        'supplier_id' => $filters['supplier_id'] ?? null,
        'payment_status' => $filters['payment_status'] ?? null,
        'status' => $filters['status'] ?? null,
        'date_from' => $filters['date_from'] ?? null,
        'date_to' => $filters['date_to'] ?? null,
        'page' => $extra['page'] ?? ($page > 1 ? $page : null),
    ], static function ($value) {
        return $value !== null && $value !== '';
    });

    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return $params === [] ? '' : ('?' . http_build_query($params));
};

$paymentStatusBadge = static function (string $status): string {
    $map = [
        'unpaid' => 'bg-orange-100 text-orange-800 border-orange-200',
        'partially_paid' => 'bg-amber-100 text-amber-800 border-amber-200',
        'paid' => 'bg-green-100 text-green-800 border-green-200',
        'overpaid' => 'bg-purple-100 text-purple-800 border-purple-200',
    ];
    return $map[$status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
};

$billStatusBadge = static function (string $status): string {
    $map = [
        'draft' => 'bg-gray-100 text-gray-700 border-gray-200',
        'posted' => 'bg-sky-100 text-sky-800 border-sky-200',
        'cancelled' => 'bg-red-100 text-red-800 border-red-200',
    ];
    return $map[$status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
};

$formatLabel = static function (string $value): string {
    return ucwords(str_replace('_', ' ', $value));
};

$formatDate = static function ($date): string {
    if (!$date || !trim((string) $date)) {
        return 'ù';
    }
    $ts = strtotime((string) $date);
    return $ts ? date('M j, Y', $ts) : 'ù';
};

$formatMoney = static function ($amount, ?string $billCurrency = null) use ($currencySymbol): string {
    $symbol = $billCurrency ? getCurrencySymbol($billCurrency) : $currencySymbol;
    return $symbol . number_format((float) $amount, 2);
};

include __DIR__ . '/../../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .main-content { background: #f8fafc; color: #0f172a; }
    .vb-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
    .vb-table-wrap { border-radius: 12px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0; }
    .vb-row:hover { background: #f8fafc; }
    @media (max-width: 992px) {
        .vb-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .vb-table { min-width: 1100px; }
    }
</style>

<main class="main-content">
    <div class="min-h-screen pb-12">

        <div class="px-6 sm:px-8 pt-6 pb-2">
            <nav class="text-xs text-gray-500 font-light mb-3" aria-label="Breadcrumb">
                <a href="<?= htmlspecialchars($stockBasePath ?? 'stock/') ?>dashboard.php" class="hover:text-gray-800">Stock</a>
                <span class="mx-1">/</span>
                <a href="../index.php" class="hover:text-gray-800">Purchases</a>
                <span class="mx-1">/</span>
                <span class="text-gray-800">Vendor Bills</span>
            </nav>

            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900">Vendor Bills</h1>
                    <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                        Manage supplier invoices and Accounts Payable for stock purchases.
                    </p>
                </div>
                <a href="create.php"
                   class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition text-sm font-medium shadow-sm">
                    <i class="fas fa-plus text-xs"></i>
                    New Vendor Bill
                </a>
            </div>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="px-6 sm:px-8 mt-4">
                <div class="rounded-lg border border-green-200 bg-green-50 text-green-900 px-4 py-3 text-sm">
                    <?= htmlspecialchars($successMessage) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($userError !== ''): ?>
            <div class="px-6 sm:px-8 mt-4">
                <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
                    <?= htmlspecialchars($userError) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($userError === '' && $tablesReady): ?>

        <div class="px-6 sm:px-8 mt-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="vb-card p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Bills</p>
                <p class="text-2xl font-semibold text-slate-900 mt-1"><?= number_format($summary['total_bills']) ?></p>
            </div>
            <div class="vb-card p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Draft Bills</p>
                <p class="text-2xl font-semibold text-slate-700 mt-1"><?= number_format($summary['draft_bills']) ?></p>
            </div>
            <div class="vb-card p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Posted Bills</p>
                <p class="text-2xl font-semibold text-sky-700 mt-1"><?= number_format($summary['posted_bills']) ?></p>
            </div>
            <div class="vb-card p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Unpaid Balance</p>
                <p class="text-2xl font-semibold text-orange-700 mt-1"><?= htmlspecialchars($formatMoney($summary['unpaid_balance'])) ?></p>
            </div>
        </div>

        <div class="px-6 sm:px-8 mt-6">
            <div class="vb-card p-4 sm:p-5">
                <form method="get" action="index.php" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                    <div class="xl:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1" for="filter-q">Search</label>
                        <input type="text" id="filter-q" name="q" value="<?= htmlspecialchars($filters['q'] ?? '') ?>"
                               placeholder="Bill number or supplier invoice no."
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="filter-supplier">Supplier</label>
                        <select id="filter-supplier" name="supplier_id" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
                            <option value="">All suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['id'] ?>"
                                    <?= (isset($filters['supplier_id']) && (int) $filters['supplier_id'] === (int) $supplier['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $supplier['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="filter-payment-status">Payment status</label>
                        <select id="filter-payment-status" name="payment_status" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
                            <option value="">All</option>
                            <?php foreach ($allowedPaymentStatuses as $ps): ?>
                                <option value="<?= htmlspecialchars($ps) ?>" <?= ($filters['payment_status'] ?? '') === $ps ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($formatLabel($ps)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="filter-status">Bill status</label>
                        <select id="filter-status" name="status" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
                            <option value="">All</option>
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?= htmlspecialchars($st) ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($formatLabel($st)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="filter-date-from">Date from</label>
                        <input type="date" id="filter-date-from" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="filter-date-to">Date to</label>
                        <input type="date" id="filter-date-to" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>"
                               class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm">
                    </div>
                    <div class="flex items-end gap-2 xl:col-span-2">
                        <button type="submit" class="px-4 py-2 bg-slate-800 text-white text-sm rounded-lg hover:bg-slate-900">
                            Filter
                        </button>
                        <a href="index.php" class="px-4 py-2 border border-slate-200 text-slate-700 text-sm rounded-lg hover:bg-slate-50">
                            Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="px-6 sm:px-8 mt-6 flex items-center justify-between gap-3">
            <p class="text-sm text-slate-600">
                Showing <?= $totalRows === 0 ? 0 : (($page - 1) * $perPage + 1) ?>
               ù<?= min($page * $perPage, $totalRows) ?> of <?= number_format($totalRows) ?> bill<?= $totalRows === 1 ? '' : 's' ?>
            </p>
            <?php if ($totalPages > 1): ?>
                <div class="flex items-center gap-1 text-sm">
                    <?php if ($page > 1): ?>
                        <a href="index.php<?= htmlspecialchars($buildQueryString(['page' => $page - 1])) ?>"
                           class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-white bg-slate-50">Prev</a>
                    <?php endif; ?>
                    <span class="px-2 text-slate-500">Page <?= (int) $page ?> / <?= (int) $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="index.php<?= htmlspecialchars($buildQueryString(['page' => $page + 1])) ?>"
                           class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-white bg-slate-50">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="px-6 sm:px-8 mt-4 pb-10">
            <div class="vb-table-wrap shadow-sm">
                <div class="vb-table-scroll">
                    <table class="vb-table w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200">
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide">Bill No</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide">Supplier</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide">Supplier Inv.</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide">PO No</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide">Bill Date</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide">Due Date</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide text-right">Total</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide text-right">Paid</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide text-right">Balance</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide text-center">Payment</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide text-center">Status</th>
                                <th class="px-4 py-3 text-[11px] font-medium text-slate-600 uppercase tracking-wide text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($bills)): ?>
                                <tr>
                                    <td colspan="12" class="px-6 py-14 text-center text-sm text-slate-500">
                                        No vendor bills found.
                                        <?php if (array_filter($filters)): ?>
                                            <a href="index.php" class="text-indigo-600 hover:underline ml-1">Clear filters</a>
                                        <?php else: ?>
                                            <a href="create.php" class="text-indigo-600 hover:underline ml-1">Create your first bill</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bills as $bill):
                                    $billId = (int) ($bill['id'] ?? 0);
                                    $billStatus = (string) ($bill['status'] ?? '');
                                    $payStatus = (string) ($bill['payment_status'] ?? '');
                                    $billCurrency = (string) ($bill['currency'] ?? '');
                                    $isDraft = $billStatus === 'draft';
                                ?>
                                <tr class="vb-row text-sm">
                                    <td class="px-4 py-3 font-medium text-indigo-700 whitespace-nowrap">
                                        <?= htmlspecialchars((string) ($bill['bill_number'] ?? 'ù')) ?>
                                    </td>
                                    <td class="px-4 py-3 max-w-[140px] truncate" title="<?= htmlspecialchars((string) ($bill['supplier_name'] ?? '')) ?>">
                                        <?= htmlspecialchars((string) ($bill['supplier_name'] ?? 'ù')) ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                        <?= htmlspecialchars((string) ($bill['supplier_invoice_number'] ?? 'ù')) ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 whitespace-nowrap">
                                        <?php if (!empty($bill['po_number'])): ?>
                                            <a href="../view_po.php?id=<?= (int) ($bill['purchase_order_id'] ?? $bill['linked_stock_po_id'] ?? 0) ?>"
                                               class="text-sky-700 hover:underline">
                                                <?= htmlspecialchars((string) $bill['po_number']) ?>
                                            </a>
                                        <?php else: ?>
                                            ù
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 whitespace-nowrap"><?= htmlspecialchars($formatDate($bill['bill_date'] ?? '')) ?></td>
                                    <td class="px-4 py-3 text-slate-600 whitespace-nowrap"><?= htmlspecialchars($formatDate($bill['due_date'] ?? '')) ?></td>
                                    <td class="px-4 py-3 text-right font-medium text-slate-900 whitespace-nowrap">
                                        <?= htmlspecialchars($formatMoney($bill['total_amount'] ?? 0, $billCurrency)) ?>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-600 whitespace-nowrap">
                                        <?= htmlspecialchars($formatMoney($bill['paid_amount'] ?? 0, $billCurrency)) ?>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-orange-700 whitespace-nowrap">
                                        <?= htmlspecialchars($formatMoney($bill['balance_due'] ?? 0, $billCurrency)) ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium border <?= $paymentStatusBadge($payStatus) ?>">
                                            <?= htmlspecialchars($formatLabel($payStatus !== '' ? $payStatus : 'unpaid')) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium border <?= $billStatusBadge($billStatus) ?>">
                                            <?= htmlspecialchars($formatLabel($billStatus !== '' ? $billStatus : 'draft')) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1">
                                            <a href="view.php?id=<?= $billId ?>"
                                               class="px-2.5 py-1.5 text-xs border border-slate-200 rounded-md hover:bg-slate-50 text-slate-700"
                                               title="View">View</a>
                                            <?php if ($isDraft): ?>
                                                <a href="edit.php?id=<?= $billId ?>"
                                                   class="px-2.5 py-1.5 text-xs border border-slate-200 rounded-md hover:bg-slate-50 text-slate-700"
                                                   title="Edit">Edit</a>
                                                <a href="view.php?id=<?= $billId ?>"
                                                   class="px-2.5 py-1.5 text-xs border border-indigo-200 rounded-md hover:bg-indigo-50 text-indigo-700"
                                                   title="Open bill to post">Post</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php endif; ?>

    </div>
</main>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
