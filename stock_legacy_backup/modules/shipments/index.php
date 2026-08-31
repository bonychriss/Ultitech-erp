<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';

requireLogin();

/** @var array{title: string, message: string, variant: string}|null $shipmentCreateSuccess */
$shipmentCreateSuccess = null;
if (!empty($_SESSION['stock_shipment_create_success']) && is_array($_SESSION['stock_shipment_create_success'])) {
    $shipmentCreateSuccess = $_SESSION['stock_shipment_create_success'];
    unset($_SESSION['stock_shipment_create_success']);
}

ensure_shipment_po_linking_schema($pdo);
updateShipmentStatusesAutomatically($pdo);

$search = $_GET['search'] ?? '';
$where = 'WHERE 1=1';
$params = [];

if ($search !== '') {
    $where .= ' AND (s.invoice_number LIKE ? OR s.tracking_number LIKE ? OR s.description LIKE ? OR su.name LIKE ?)';
    $params = array_fill(0, 4, '%' . $search . '%');
}

$stmt = $pdo->prepare("SELECT s.*, su.name AS supplier_name, sh.name AS shipper_real_name,
                       spo.po_number AS linked_po_number
                       FROM shipments s
                       LEFT JOIN stocks_suppliers su ON s.supplier_id = su.id
                       LEFT JOIN shippers sh ON s.shipper_id = sh.id
                       LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
                       $where
                       ORDER BY s.created_at DESC");
$stmt->execute($params);
$shipments = $stmt->fetchAll();

$page_title = 'Shipment Tracking';
include '../../includes/header.php';
?>
<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .ship-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .ship-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .ship-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .shipments-table thead tr.ship-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.03em;
        white-space: nowrap;
        padding: 0.65rem 0.5rem !important;
    }
    .shipments-table thead tr.ship-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .shipments-table tbody td {
        font-size: 0.8125rem;
        vertical-align: middle;
    }
    .shipments-table-wrapper {
        overflow: auto;
        max-height: 75vh;
        border-top: 1px solid #e5e7eb;
    }
    .contact-actions { display: flex; flex-direction: column; gap: 2px; }
    .ship-row:hover { background-color: #f9fafb !important; }

    /* Mobile shipment-created success bottom sheet (Dispatch-style) */
    @media (max-width: 767.98px) {
        body.ship-create-success-sheet-open {
            overflow: hidden;
            touch-action: none;
        }
    }
    .ship-create-success-sheet-backdrop {
        display: none;
    }
    .ship-create-success-sheet {
        display: none;
    }
    @media (max-width: 767.98px) {
        .ship-create-success-sheet-backdrop {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            z-index: 1080;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.28s ease, visibility 0.28s ease;
        }
        .ship-create-success-sheet-backdrop.is-visible {
            opacity: 1;
            visibility: visible;
        }
        .ship-create-success-sheet {
            display: block;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            max-height: min(58vh, 420px);
            background: #fff;
            border-radius: 1.25rem 1.25rem 0 0;
            box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.18);
            z-index: 1090;
            transform: translateY(105%);
            transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1);
            padding-bottom: max(1rem, env(safe-area-inset-bottom, 0px));
        }
        .ship-create-success-sheet.is-visible {
            transform: translateY(0);
        }
        .ship-create-success-sheet-handle {
            width: 40px;
            height: 5px;
            background: #d1d5db;
            border-radius: 999px;
            margin: 12px auto 8px;
            flex-shrink: 0;
        }
    }
</style>

<main class="main-content ship-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <?php if ($shipmentCreateSuccess): ?>
            <?php $shipCsVariant = ($shipmentCreateSuccess['variant'] ?? 'success') === 'warning' ? 'warning' : 'success'; ?>
            <div class="d-md-none ship-create-success-sheet-backdrop" id="shipCreateSuccessBackdrop" aria-hidden="true"></div>
            <div class="d-md-none ship-create-success-sheet" id="shipCreateSuccessSheet" role="dialog" aria-modal="true" aria-labelledby="shipCreateSuccessSheetTitle">
                <div class="ship-create-success-sheet-handle" aria-hidden="true"></div>
                <div class="px-4 pb-4 pt-0 text-center">
                    <?php if ($shipCsVariant === 'warning'): ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-15 text-warning mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-exclamation-triangle fa-lg"></i>
                        </div>
                    <?php else: ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 56px; height: 56px;">
                            <i class="fas fa-check fa-lg"></i>
                        </div>
                    <?php endif; ?>
                    <h2 id="shipCreateSuccessSheetTitle" class="h5 fw-bold text-dark mb-2"><?php echo htmlspecialchars($shipmentCreateSuccess['title'] ?? 'Success'); ?></h2>
                    <p class="text-secondary mb-4 small"><?php echo htmlspecialchars($shipmentCreateSuccess['message'] ?? ''); ?></p>
                    <a href="index.php" class="btn ship-btn-primary w-100 py-2 rounded-pill fw-semibold border-0 d-inline-flex align-items-center justify-content-center" id="shipCreateSuccessDismiss">
                        View shipments
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['shipment_notice'])): ?>
            <div class="alert alert-warning border-0 rounded-0 mb-0 d-flex align-items-center justify-content-between gap-2 px-4 py-3" role="alert">
                <span><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars((string) $_SESSION['shipment_notice'], ENT_QUOTES, 'UTF-8'); ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['shipment_notice']); ?>
        <?php endif; ?>
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 border-b border-gray-100">
                <a href="create.php" class="btn ship-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0">
                    <i class="fas fa-plus text-sm"></i> New shipment
                </a>
                <a href="import.php" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-file-csv"></i> Import
                </a>
                <a href="../shippers/index.php" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-truck"></i> Shippers
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Shipments</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="../purchases/index.php" class="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-shopping-cart"></i> Purchases
                </a>
            </div>
            <form method="get" class="px-4 py-2 flex flex-wrap items-center gap-3 bg-gray-50/80 border-b border-gray-100">
                <div class="relative flex-1 min-w-[200px] max-w-xl">
                    <label for="shipSearch" class="visually-hidden">Search shipments</label>
                    <i class="fas fa-filter absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                    <input type="text" id="shipSearch" name="search" class="form-control border-gray-200 rounded-md ps-9"
                           placeholder="Invoice, tracking #, description, supplierâ€¦" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn ship-btn-primary btn-sm rounded-md px-3 border-0"><i class="fas fa-search me-1"></i> Search</button>
                <?php if ($search !== ''): ?>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm rounded-md">Clear</a>
                <?php endif; ?>
            </form>
            <div class="px-4 py-2 text-sm text-gray-600 bg-white border-b border-gray-100">
                <span class="font-medium text-gray-800 tabular-nums"><?php echo count($shipments); ?></span> shipment<?php echo count($shipments) === 1 ? '' : 's'; ?>
                <?php if ($search !== ''): ?><span class="text-gray-400 mx-2">|</span><span>Filtered</span><?php endif; ?>
            </div>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="shipments-table-wrapper">
                <table class="table table-hover table-bordered align-middle mb-0 shipments-table text-nowrap">
                    <thead>
                        <tr class="ship-table-head">
                            <th>Supplier</th>
                            <th>Stock PO</th>
                            <th>Contact</th>
                            <th>Invoice #</th>
                            <th>Track</th>
                            <th class="text-center">Pkgs</th>
                            <th class="text-center">CBM</th>
                            <th class="text-end">Value</th>
                            <th>Desc</th>
                            <th>Ship date</th>
                            <th>Shipper</th>
                            <th>ECC</th>
                            <th>ETD</th>
                            <th>ETA</th>
                            <th>Status</th>
                            <th class="text-center"><i class="fas fa-sliders-h text-white/70" title="Actions"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shipments as $ship): ?>
                            <tr class="ship-row cursor-pointer" onclick="window.location.href='view.php?id=<?php echo (int) $ship['id']; ?>'">
                                <td class="fw-semibold text-gray-900 py-2"><?php echo htmlspecialchars($ship['supplier_name'] ?? ''); ?></td>
                                <td class="py-2 small">
                                    <?php if (!empty($ship['stocks_po_id'])): ?>
                                        <a href="../purchases/view_po.php?id=<?php echo (int) $ship['stocks_po_id']; ?>" class="text-[#2563EB] fw-semibold text-decoration-none" onclick="event.stopPropagation();"><?php echo htmlspecialchars($ship['linked_po_number'] ?: '#' . (int) $ship['stocks_po_id']); ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">â€”</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2">
                                    <?php if (!empty($ship['contact_number'])): ?>
                                        <div class="contact-actions">
                                            <span class="fw-bold small text-gray-800"><?php echo htmlspecialchars($ship['contact_number']); ?></span>
                                            <div class="d-flex gap-1 opacity-75">
                                                <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $ship['contact_number'])); ?>" class="text-secondary p-0" title="Call" onclick="event.stopPropagation();"><i class="fas fa-phone small"></i></a>
                                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $ship['contact_number']); ?>" target="_blank" rel="noopener noreferrer" class="text-secondary p-0" title="WhatsApp" onclick="event.stopPropagation();"><i class="fab fa-whatsapp"></i></a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">â€”</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2"><code class="small"><?php echo htmlspecialchars($ship['invoice_number'] ?? ''); ?></code></td>
                                <td class="text-muted py-2"><?php echo htmlspecialchars($ship['tracking_number'] ?: 'N/A'); ?></td>
                                <td class="text-center py-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-pill text-xs font-semibold bg-gray-100 text-gray-800 border border-gray-200"><?php echo (int) ($ship['packages_count'] ?? 0); ?></span>
                                </td>
                                <td class="text-center py-2 tabular-nums"><?php echo number_format((float) ($ship['cbm'] ?? 0), 3); ?></td>
                                <td class="text-end fw-bold py-2 tabular-nums"><?php echo htmlspecialchars(shipment_currency_display_prefix($ship['total_value_currency'] ?? 'USD'), ENT_QUOTES, 'UTF-8'); ?><?php echo number_format((float) ($ship['total_value'] ?? 0), 2); ?></td>
                                <td class="py-2" style="max-width: 160px;">
                                    <span class="text-truncate d-block" title="<?php echo htmlspecialchars($ship['description'] ?? ''); ?>"><?php echo htmlspecialchars($ship['description'] ?? ''); ?></span>
                                    <?php if (!empty($ship['description'])): ?>
                                        <small class="text-success" style="font-size: 0.65rem;">Auto-linked</small>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 tabular-nums"><?php echo (!empty($ship['shipment_date']) && strtotime($ship['shipment_date']) > 0) ? date('Y-m-d', strtotime($ship['shipment_date'])) : 'â€”'; ?></td>
                                <td class="py-2"><?php echo htmlspecialchars($ship['shipper_real_name'] ?? $ship['shipper_name'] ?? $ship['shipper'] ?? 'â€”'); ?></td>
                                <td class="py-2 font-monospace small tabular-nums">$<?php echo number_format((float) ($ship['estimated_clearance_cost'] ?? 0), 2); ?></td>
                                <td class="py-2 tabular-nums"><?php echo (!empty($ship['etd']) && strtotime($ship['etd']) > 0) ? date('Y-m-d', strtotime($ship['etd'])) : 'â€”'; ?></td>
                                <td class="py-2 tabular-nums"><?php echo (!empty($ship['eta']) && strtotime($ship['eta']) > 0) ? date('Y-m-d', strtotime($ship['eta'])) : 'â€”'; ?></td>
                                <td class="py-2">
                                    <?php
                                    $statusObj = [
                                        'pending' => ['tw' => 'bg-gray-100 text-gray-800 border-gray-200', 'label' => 'Pending'],
                                        'shipped' => ['tw' => 'bg-cyan-50 text-cyan-800 border-cyan-200', 'label' => 'Shipped'],
                                        'in_transit' => ['tw' => 'bg-blue-50 text-blue-800 border-blue-200', 'label' => 'In transit'],
                                        'arrived_at_port' => ['tw' => 'bg-amber-50 text-amber-900 border-amber-200', 'label' => 'Port arrival'],
                                        'delivered' => ['tw' => 'bg-emerald-50 text-emerald-800 border-emerald-200', 'label' => 'Delivered'],
                                        'cancelled' => ['tw' => 'bg-red-50 text-red-800 border-red-200', 'label' => 'Cancelled'],
                                    ];
                                    $st = strtolower((string) ($ship['status'] ?? ''));
                                    $s = $statusObj[$st] ?? ['tw' => 'bg-gray-50 text-gray-700 border-gray-200', 'label' => strtoupper((string) ($ship['status'] ?? 'â€”'))];
                                    ?>
                                    <span class="inline-block px-2 py-0.5 text-xs font-bold rounded-full border <?php echo $s['tw']; ?>"><?php echo htmlspecialchars($s['label']); ?></span>
                                </td>
                                <td class="text-center py-2" onclick="event.stopPropagation()">
                                    <div class="d-flex justify-content-center gap-2">
                                        <?php if (($ship['status'] ?? '') !== 'delivered' && ($ship['status'] ?? '') !== 'cancelled'): ?>
                                            <a href="edit.php?id=<?php echo (int) $ship['id']; ?>" class="text-gray-500 hover:text-[#2563EB]" title="Edit"><i class="fas fa-edit"></i></a>
                                        <?php endif; ?>
                                        <a href="view.php?id=<?php echo (int) $ship['id']; ?>&tab=packages" class="text-gray-500 hover:text-[#2563EB]" title="Packages"><i class="fas fa-box"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($shipments)): ?>
                            <tr>
                                <td colspan="16" class="text-center py-16 px-4">
                                    <i class="fas fa-shipping-fast text-5xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-700 text-lg font-medium mb-1">No shipments found</p>
                                    <p class="text-gray-500 text-base mb-0">
                                        <?php if ($search !== ''): ?>Try a different search.<?php else: ?><a href="create.php" class="text-[#2563EB] fw-semibold">Create a shipment</a> to get started.<?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
function copyToClipboard(text) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(text).catch(function(err) { console.error(err); });
}
</script>

<?php if ($shipmentCreateSuccess): ?>
<script>
(function () {
    var sheet = document.getElementById('shipCreateSuccessSheet');
    var backdrop = document.getElementById('shipCreateSuccessBackdrop');
    var btn = document.getElementById('shipCreateSuccessDismiss');
    if (!sheet || !backdrop) return;

    var mq = window.matchMedia('(max-width: 767.98px)');
    var autoTimer;
    var listHref = 'index.php';

    function goList() {
        window.location.href = listHref;
    }

    function openSheet() {
        if (!mq.matches) return;
        sheet.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ship-create-success-sheet-open');
        requestAnimationFrame(function () {
            backdrop.classList.add('is-visible');
            sheet.classList.add('is-visible');
        });
        window.clearTimeout(autoTimer);
        autoTimer = window.setTimeout(function () {
            closeSheet(true);
        }, 6000);
    }

    function closeSheet(fromTimer) {
        window.clearTimeout(autoTimer);
        backdrop.classList.remove('is-visible');
        sheet.classList.remove('is-visible');
        document.body.classList.remove('ship-create-success-sheet-open');
        window.setTimeout(function () {
            if (!sheet.classList.contains('is-visible')) {
                sheet.setAttribute('aria-hidden', 'true');
            }
            if (fromTimer) goList();
        }, 350);
    }

    function init() {
        if (mq.matches) openSheet();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    mq.addEventListener('change', function (e) {
        if (!e.matches) {
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('ship-create-success-sheet-open');
        }
    });

    if (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            window.clearTimeout(autoTimer);
            backdrop.classList.remove('is-visible');
            sheet.classList.remove('is-visible');
            document.body.classList.remove('ship-create-success-sheet-open');
            goList();
        });
    }
    backdrop.addEventListener('click', function () {
        closeSheet(true);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('is-visible')) {
            closeSheet(true);
        }
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Swal === 'undefined') return;
    if (!window.matchMedia('(min-width: 768px)').matches) return;
    var d = <?php echo json_encode($shipmentCreateSuccess, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (!d) return;
    Swal.fire({
        title: d.title || 'Success',
        text: d.message || '',
        icon: d.variant === 'warning' ? 'warning' : 'success',
        confirmButtonColor: '#2563EB',
        confirmButtonText: 'OK'
    }).then(function () {
        window.location.href = 'index.php';
    });
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
