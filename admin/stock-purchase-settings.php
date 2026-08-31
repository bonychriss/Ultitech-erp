<?php
/**
 * Stock purchase workflow settings: PO type (Internal/Abroad) and payment voucher linking for Finance desk.
 */
require_once __DIR__ . '/../includes/functions.php';
$stockFnsPath = __DIR__ . '/../stock/config/functions.php';
if (is_file($stockFnsPath)) {
    require_once $stockFnsPath;
}
requireAdmin();

$redirectPoId = (int) ($_GET['po_id'] ?? 0);
if ($redirectPoId > 0) {
    $editTarget = function_exists('app_url')
        ? app_url('/stock/modules/purchases/edit_classification.php?id=' . $redirectPoId)
        : '../stock/modules/purchases/edit_classification.php?id=' . $redirectPoId;
    header('Location: ' . $editTarget);
    exit;
}

$companyId = (int) (currentCompanyId() ?? 0);
$message = '';
$error = '';

$settingsQs = static function (array $extra = []) {
    $qs = http_build_query(array_merge($_GET ?: [], $extra));
    return $qs === '' ? '' : ('?' . $qs);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_stock_purchase_settings'])) {
    if ($companyId <= 0) {
        $error = 'Unable to resolve company context.';
    } else {
        $enabled = isset($_POST['stock_purchase_allow_po_classification_edit']) ? '1' : '0';
        $roles = [];
        foreach (['admin', 'procurement', 'finance'] as $roleKey) {
            if (!empty($_POST['role_' . $roleKey])) {
                $roles[] = $roleKey;
            }
        }
        if ($roles === []) {
            $roles = ['admin', 'procurement'];
        }
        $settingsPdo = stockPurchaseCompanySettingsPdo();
        if (!($settingsPdo instanceof PDO)) {
            $error = 'Unable to connect to company database to save settings.';
        } else {
            $ok1 = saveCompanySettingValue($settingsPdo, $companyId, 'stock_purchase_allow_po_classification_edit', $enabled);
            $ok2 = saveCompanySettingValue($settingsPdo, $companyId, 'stock_purchase_po_edit_roles', implode(',', $roles));
            if ($ok1 && $ok2) {
                $message = 'Stock purchase settings saved.';
            } else {
                $error = 'Settings could not be saved. Ensure company_settings supports key/value storage for this company.';
            }
        }
    }
}

$featureEnabled = isStockPurchasePoClassificationEditEnabled();
$allowedRoles = stockPurchasePoClassificationEditRoles();
$canEditPo = canEditStockPurchasePoClassification();

$searchPo = trim((string) ($_GET['q'] ?? ''));
$purchaseOrders = [];
$linkableVouchers = [];

$settingsPdoForData = stockPurchaseCompanySettingsPdo();
$dataPdo = ($settingsPdoForData instanceof PDO) ? $settingsPdoForData : $pdo;

if (tableExists('stocks_purchase_orders', $dataPdo)) {
    $poCols = [];
    try {
        $poCols = $dataPdo->query('SHOW COLUMNS FROM stocks_purchase_orders')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $poCols = [];
    }
    $params = [];
    $where = ["po.status NOT IN ('Cancelled')"];
    if (in_array('company_id', $poCols, true) && $companyId > 0) {
        $where[] = 'po.company_id = ?';
        $params[] = $companyId;
    }
    if ($searchPo !== '') {
        $where[] = '(po.po_number LIKE ? OR po.supplier_invoice_no LIKE ?)';
        $like = '%' . $searchPo . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql = "
        SELECT po.id, po.po_number, po.status, po.purchase_type, po.payment_voucher_id, po.payment_voucher_ids,
               po.created_at, po.total_amount, po.currency,
               ss.name AS supplier_name
        FROM stocks_purchase_orders po
        LEFT JOIN stocks_suppliers ss ON ss.id = po.supplier_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY po.id DESC
        LIMIT 80
    ";
    try {
        $stmt = $dataPdo->prepare($sql);
        $stmt->execute($params);
        $purchaseOrders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : ('Could not load purchase orders: ' . $e->getMessage());
    }
}

$financeDeskUrl = function_exists('app_url')
    ? app_url('/modules/finance/stock-purchase-payment-desk.php')
    : '/modules/finance/stock-purchase-payment-desk.php';
$stockPoListUrl = function_exists('app_url')
    ? app_url('/stock/modules/purchases/index.php')
    : '/stock/modules/purchases/index.php';

$page_title = 'Stock purchase settings';
include_once __DIR__ . '/../includes/header_employee.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { corePlugins: { preflight: false } };</script>
<style>
    .sp-settings { font-family: 'Outfit', system-ui, sans-serif; font-size: 15px; color: #374151; }
    .sp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0; box-shadow: 0 1px 2px rgba(15,23,42,0.04); }
    .sp-table th { background: #f8fafc; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
    .sp-table td, .sp-table th { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .sp-pill-internal { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 10px; font-weight: 600; padding: 2px 8px; text-transform: uppercase; }
    .sp-pill-abroad { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 10px; font-weight: 600; padding: 2px 8px; text-transform: uppercase; }
    .sp-btn-primary { background: #a855f7; color: #fff; border: none; padding: 0.45rem 1rem; font-weight: 600; }
    .sp-btn-primary:hover { background: #9333ea; color: #fff; }
    .sp-btn-outline { border: 1px solid #e2e8f0; background: #fff; color: #334155; padding: 0.45rem 1rem; }
    .sp-btn-outline:hover { background: #f8fafc; }
</style>

<main class="main-content sp-settings bg-[#F9F9F9] min-h-[50vh] pb-10">
    <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
        <div class="px-4 py-3 flex flex-wrap items-center gap-3">
            <a href="settings.php<?= htmlspecialchars($settingsQs(['module' => 'settings'])) ?>" class="sp-btn-outline text-sm no-underline inline-flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Settings hub
            </a>
            <h1 class="text-xl font-bold text-gray-900 m-0 flex items-center gap-2">
                <i class="fas fa-file-invoice-dollar text-purple-600"></i> Stock purchase &amp; vouchers
            </h1>
            <div class="flex-1"></div>
            <a href="<?= htmlspecialchars($financeDeskUrl) ?>" class="sp-btn-outline text-sm no-underline">Finance payment desk</a>
            <a href="<?= htmlspecialchars($stockPoListUrl) ?>" class="sp-btn-outline text-sm no-underline">Purchase orders</a>
        </div>
        <div class="px-4 py-2 text-sm text-gray-600 bg-gray-50 border-b border-gray-100">
            Mark purchase orders as <strong>Internal</strong> or <strong>Abroad</strong>, link approved Stock Purchase payment vouchers, then Finance approves payment on the desk.
        </div>
    </div>

    <div class="px-4 pt-4 max-w-6xl mx-auto">
        <?php if ($message !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="sp-card p-4 mb-4">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Workflow settings</h2>
            <p class="text-sm text-gray-600 mb-3">
                When enabled, authorized users can correct PO type and voucher links below. Linked vouchers appear on the
                <a href="<?= htmlspecialchars($financeDeskUrl) ?>" class="text-purple-600">Stock Purchase Payment Desk</a> for payment approval.
            </p>
            <?php if ($featureEnabled && $canEditPo): ?>
                <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2 mb-3">
                    <i class="fas fa-check-circle me-1"></i> Editing is <strong>enabled</strong> for your account.
                </p>
            <?php endif; ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="save_stock_purchase_settings" value="1">
                <div class="col-12">
                    <input type="hidden" name="stock_purchase_allow_po_classification_edit" value="0">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="stock_purchase_allow_po_classification_edit" value="1" id="featEnable"
                            <?= $featureEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="featEnable">Allow editing purchase type and payment voucher links</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="text-sm fw-semibold mb-2">Roles that may edit (when enabled)</div>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach (['admin' => 'Admin', 'procurement' => 'Procurement', 'finance' => 'Finance'] as $rk => $rl): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="role_<?= $rk ?>" value="1" id="role_<?= $rk ?>"
                                    <?= in_array($rk, $allowedRoles, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="role_<?= $rk ?>"><?= htmlspecialchars($rl) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="sp-btn-primary">Save settings</button>
                </div>
            </form>
        </div>

        <div class="sp-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-semibold text-gray-900 m-0">Purchase orders</h2>
                <form method="get" class="flex gap-2">
                    <input type="hidden" name="module" value="<?= htmlspecialchars((string) ($_GET['module'] ?? 'settings')) ?>">
                    <input type="text" name="q" value="<?= htmlspecialchars($searchPo) ?>" placeholder="Search PO number�" class="form-control form-control-sm" style="min-width:200px;">
                    <button type="submit" class="sp-btn-outline btn-sm">Search</button>
                </form>
            </div>

            <?php if (!$featureEnabled): ?>
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 p-3 mb-0">
                    Enable <strong>Allow editing purchase type and payment voucher links</strong> above, then click <strong>Save settings</strong>.
                    <?php if ($companyId <= 0): ?> Company context is missing; log in again or open this page from your company URL.<?php endif; ?>
                </p>
            <?php elseif (!$canEditPo): ?>
                <p class="text-sm text-gray-600 mb-0">
                    Your role (<strong><?= htmlspecialchars((string) ($_SESSION['role'] ?? 'unknown')) ?></strong>) is not in the allowed list.
                    Enable <strong>Admin</strong> (or your role) under workflow settings and save again.
                </p>
            <?php elseif (empty($purchaseOrders)): ?>
                <p class="text-sm text-gray-500 mb-0">No open purchase orders found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="sp-table w-full text-left">
                        <thead>
                            <tr>
                                <th>PO</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Linked vouchers</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchaseOrders as $po):
                                $poId = (int) ($po['id'] ?? 0);
                                $linkedIds = parseStockPurchasePoLinkedVoucherIds($po);
                                $ptype = ($po['purchase_type'] ?? 'domestic') === 'import' ? 'import' : 'domestic';
                                $editableStatus = canEditStockPurchasePoClassificationForStatus((string) ($po['status'] ?? ''));
                                $editPoUrl = function_exists('app_url')
                                    ? app_url('/stock/modules/purchases/edit_classification.php?id=' . $poId)
                                    : '../stock/modules/purchases/edit_classification.php?id=' . $poId;
                            ?>
                            <tr>
                                <td>
                                    <div class="font-semibold text-blue-700"><?= htmlspecialchars((string) ($po['po_number'] ?? ('#' . $poId))) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars((string) ($po['created_at'] ?? '')) ?></div>
                                </td>
                                <td class="text-sm"><?= htmlspecialchars((string) ($po['supplier_name'] ?? '-')) ?></td>
                                <td class="text-sm"><?= htmlspecialchars((string) ($po['status'] ?? '')) ?></td>
                                <td>
                                    <?php if ($ptype === 'import'): ?>
                                        <span class="sp-pill-abroad">Abroad</span>
                                    <?php else: ?>
                                        <span class="sp-pill-internal">Internal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm">
                                    <?php if ($linkedIds === []): ?>
                                        <span class="text-amber-600">None</span>
                                    <?php else: ?>
                                        <?= count($linkedIds) ?> linked
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($editableStatus && $canEditPo): ?>
                                        <a href="<?= htmlspecialchars($editPoUrl, ENT_QUOTES, 'UTF-8') ?>" class="sp-btn-outline btn-sm inline-flex items-center gap-1 no-underline" title="Edit type and payment vouchers">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    <?php elseif (!$featureEnabled): ?>
                                        <span class="text-xs text-gray-400">Off</span>
                                    <?php elseif (!$canEditPo): ?>
                                        <span class="text-xs text-gray-400">No access</span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-4 p-3 bg-white border border-gray-200 text-sm text-gray-600">
            <strong>Office workflow:</strong> Sales creates a Stock Purchase payment voucher &rarr; Procurement creates a PO and links the voucher &rarr;
            set <strong>Internal</strong> or <strong>Abroad</strong> here if needed &rarr; Finance uses the payment desk to mark vouchers paid.
        </div>
    </div>
</main>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
