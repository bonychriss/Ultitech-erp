<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$page_title = 'Supplier Statement';
include '../../includes/header.php';

$returnUrl = trim((string)($_GET['return'] ?? ''));
$backHref = '../../dashboard.php';
if ($returnUrl !== '' && str_starts_with($returnUrl, '/')) {
    $backHref = $returnUrl;
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$supplier_id = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : 0;

// Fetch suppliers (stock schema)
$suppliers = [];
try {
    $suppliers = $pdo->query("SELECT id, name FROM stocks_suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $suppliers = [];
}

$supplier_name = '';
if ($supplier_id > 0) {
    foreach ($suppliers as $s) {
        if ((int) ($s['id'] ?? 0) === $supplier_id) {
            $supplier_name = (string) ($s['name'] ?? '');
            break;
        }
    }
}

// Purchases for the supplier in date range
$rows = [];
$openingBalance = 0.0;
$totals = [
    'count' => 0,
    'total' => 0.0,
    'cancelled' => 0.0,
];

if ($supplier_id > 0) {
    // Opening balance (purchases before start date; payments/credits not modeled here)
    try {
        $stOb = $pdo->prepare("
            SELECT
                COALESCE(SUM(COALESCE((
                    SELECT SUM(COALESCE(pi.qty_ordered, 0) * COALESCE(pi.unit_cost, 0))
                    FROM stocks_po_items pi
                    WHERE pi.po_id = p.id
                ), 0)), 0) AS ob
            FROM stocks_purchase_orders p
            WHERE p.supplier_id = ?
              AND DATE(p.created_at) < ?
              AND COALESCE(p.status,'') <> 'Cancelled'
        ");
        $stOb->execute([$supplier_id, $start_date]);
        $openingBalance = (float) ($stOb->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $openingBalance = 0.0;
    }

    $sql = "
        SELECT
            p.id,
            p.po_number,
            p.status,
            p.created_at,
            COALESCE((
                SELECT SUM(COALESCE(pi.qty_ordered, 0) * COALESCE(pi.unit_cost, 0))
                FROM stocks_po_items pi
                WHERE pi.po_id = p.id
            ), 0) AS total_amount
        FROM stocks_purchase_orders p
        WHERE p.supplier_id = ?
          AND DATE(p.created_at) BETWEEN ? AND ?
        ORDER BY p.created_at ASC
    ";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$supplier_id, $start_date, $end_date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    foreach ($rows as $r) {
        $totals['count']++;
        $amt = (float) ($r['total_amount'] ?? 0);
        $totals['total'] += $amt;
        if (strcasecmp((string) ($r['status'] ?? ''), 'Cancelled') === 0) {
            $totals['cancelled'] += $amt;
        }
    }
}
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="/assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .st-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .st-card-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .st-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        flex: 0 0 auto;
    }
    .st-icon i {
        font-size: 18px;
        line-height: 1;
    }
    @media (max-width: 575.98px) {
        .st-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
        }
        .st-icon i {
            font-size: 16px;
        }
    }
    .st-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
    .st-table-wrapper {
        overflow-x: auto;
        overflow-y: visible !important;
        position: relative;
    }
    .st-table thead th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
</style>

<main class="main-content st-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="<?php echo htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8'); ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="Back">
                    <i class="fas fa-arrow-left text-sm"></i> Back
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-file-invoice text-[#2563EB]"></i><span>Supplier statement</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="../suppliers/index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline" title="Suppliers">
                    <i class="fas fa-users text-sm"></i>
                </a>
            </div>
            <div class="px-4 py-3 bg-gray-50/80 border-b border-gray-100">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Supplier</label>
                        <select name="supplier_id" class="form-select">
                            <option value="0">Select supplier...</option>
                            <?php foreach ($suppliers as $s): ?>
                                <?php $sid = (int) ($s['id'] ?? 0); ?>
                                <option value="<?php echo $sid; ?>" <?php echo $sid === $supplier_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) ($s['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Start date</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="form-control">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">End date</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="form-control">
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary fw-semibold">
                            <i class="fas fa-search me-1"></i> View
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="px-4 pt-4">
            <div class="row g-3">
                <div class="col-12 col-lg-4">
                    <div class="st-card p-4 h-100">
                        <div class="st-card-top">
                            <div class="min-w-0">
                                <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Supplier</div>
                                <div class="text-lg fw-bold text-gray-900 mb-0 text-truncate">
                                    <?php echo $supplier_id > 0 ? htmlspecialchars($supplier_name !== '' ? $supplier_name : ('Supplier #' . $supplier_id)) : '-'; ?>
                                </div>
                                <div class="text-sm text-gray-600 mt-2">
                                    <i class="fas fa-calendar-alt text-gray-400 me-1" aria-hidden="true"></i>
                                    Period: <span class="fw-semibold"><?php echo htmlspecialchars($start_date); ?></span> to <span class="fw-semibold"><?php echo htmlspecialchars($end_date); ?></span>
                                </div>
                                <div class="text-sm text-gray-600 mt-2">
                                    Opening balance: <span class="fw-semibold tabular-nums">TZS <?php echo number_format((float) $openingBalance, 2); ?></span>
                                </div>
                            </div>
                            <span class="st-icon bg-indigo-50 text-indigo-700" aria-hidden="true">
                                <i class="fas fa-building"></i>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-8">
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <div class="st-card p-4 h-100">
                                <div class="st-card-top">
                                    <div class="min-w-0">
                                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">POs</div>
                                        <div class="text-2xl fw-bold text-gray-900 tabular-nums"><?php echo (int) $totals['count']; ?></div>
                                    </div>
                                    <span class="st-icon bg-slate-50 text-slate-700" aria-hidden="true">
                                        <i class="fas fa-clipboard-list"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="st-card p-4 h-100">
                                <div class="st-card-top">
                                    <div class="min-w-0">
                                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total</div>
                                        <div class="text-2xl fw-bold text-gray-900 tabular-nums">TZS <?php echo number_format((float) $totals['total'], 2); ?></div>
                                    </div>
                                    <span class="st-icon bg-emerald-50 text-emerald-700" aria-hidden="true">
                                        <i class="fas fa-coins"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="st-card p-4 h-100">
                                <div class="st-card-top">
                                    <div class="min-w-0">
                                        <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Cancelled</div>
                                        <div class="text-2xl fw-bold text-gray-900 tabular-nums">TZS <?php echo number_format((float) $totals['cancelled'], 2); ?></div>
                                    </div>
                                    <span class="st-icon bg-rose-50 text-rose-700" aria-hidden="true">
                                        <i class="fas fa-ban"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="st-card p-4">
                                <div class="d-flex align-items-start gap-2 text-sm text-gray-600">
                                    <span class="st-icon bg-amber-50 text-amber-800" aria-hidden="true" style="width:32px;height:32px;border-radius:10px;">
                                        <i class="fas fa-info-circle" style="font-size:14px;"></i>
                                    </span>
                                    <span>Payments/credits are not included unless a payments table is added in your schema.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="px-4 py-4">
            <div class="st-card">
                <div class="px-4 py-3 border-b border-gray-100 d-flex justify-content-between align-items-center">
                    <div class="fw-bold text-gray-900">Statement lines</div>
                    <?php if ($supplier_id > 0): ?>
                        <a class="text-sm fw-semibold text-gray-700 hover:text-[#2563EB] no-underline"
                           href="../purchases/index.php?domestic=1&import=1"
                           title="Open purchases">
                            Open purchases <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="st-table-wrapper">
                    <table class="table table-hover align-middle mb-0 st-table">
                        <thead>
                            <tr>
                                <th class="ps-3 py-3" style="min-width: 130px;">Date</th>
                                <th class="py-3" style="min-width: 150px;">PO number</th>
                                <th class="py-3 text-center" style="min-width: 120px;">Status</th>
                                <th class="py-3 text-end pe-3" style="min-width: 140px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($supplier_id === 0): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">Select a supplier to view the statement.</td>
                                </tr>
                            <?php elseif (empty($rows)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">No purchases found for this supplier in the selected date range.</td>
                                </tr>
                            <?php else: ?>
                                <?php if (abs($openingBalance) > 0.005): ?>
                                    <tr class="border-b border-gray-100 bg-gray-50/60">
                                        <td class="ps-3 py-3 text-gray-700"><?php echo htmlspecialchars(date('Y-m-d', strtotime($start_date))); ?></td>
                                        <td class="py-3 fw-semibold text-gray-900">Opening balance</td>
                                        <td class="py-3 text-center"><span class="badge bg-secondary">Opening</span></td>
                                        <td class="py-3 text-end pe-3 fw-bold tabular-nums text-gray-900">TZS <?php echo number_format((float) $openingBalance, 2); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($rows as $r): ?>
                                    <?php
                                    $status = (string) ($r['status'] ?? '');
                                    $badge = match($status) {
                                        'Received' => 'bg-success',
                                        'Cancelled' => 'bg-danger',
                                        default => 'bg-warning text-dark'
                                    };
                                    ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50/80">
                                        <td class="ps-3 py-3 text-gray-700"><?php echo htmlspecialchars(date('Y-m-d', strtotime((string) ($r['created_at'] ?? '')))); ?></td>
                                        <td class="py-3 fw-semibold text-gray-900"><?php echo htmlspecialchars((string) ($r['po_number'] ?? '')); ?></td>
                                        <td class="py-3 text-center">
                                            <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status !== '' ? $status : '-'); ?></span>
                                        </td>
                                        <td class="py-3 text-end pe-3 fw-bold tabular-nums text-gray-900">TZS <?php echo number_format((float) ($r['total_amount'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>

