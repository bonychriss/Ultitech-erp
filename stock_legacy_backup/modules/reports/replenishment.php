<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

// AJAX Handler for detailed order insights
if (isset($_GET['action']) && $_GET['action'] === 'get_order_details') {
    $p_id = (int)$_GET['product_id'];
    $detailMode = $_GET['detail_mode'] ?? 'all';

    if ($detailMode === 'invoice') {
        // Negative-stock replenishment is usually caused by already invoiced sales.
        // Show those invoice-backed rows first so procurement can open the invoice details directly.
        $sql = "SELECT so.id as order_id, so.order_number, so.created_at, u.full_name as salesperson, soi.quantity, c.company_name as customer,
                       inv.id as invoice_id, inv.invoice_number, so.status
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.order_id = so.id
                LEFT JOIN users u ON so.created_by = u.id
                LEFT JOIN customers c ON so.customer_id = c.id
                LEFT JOIN invoices inv ON inv.order_id = so.id
                WHERE soi.product_id = ?
                AND so.status IN ('invoiced', 'paid', 'shipped', 'delivered')
                ORDER BY (inv.id IS NULL) ASC, so.created_at DESC
                LIMIT 15";
    } else {
        $sql = "SELECT so.id as order_id, so.order_number, so.created_at, u.full_name as salesperson, soi.quantity, c.company_name as customer,
                       inv.id as invoice_id, inv.invoice_number, so.status
                FROM sales_order_items soi
                JOIN sales_orders so ON soi.order_id = so.id
                LEFT JOIN users u ON so.created_by = u.id
                LEFT JOIN customers c ON so.customer_id = c.id
                LEFT JOIN invoices inv ON inv.order_id = so.id
                WHERE soi.product_id = ?
                AND so.status IN ('confirmed', 'invoiced', 'paid', 'shipped', 'delivered')
                ORDER BY (inv.id IS NULL) ASC, so.created_at DESC
                LIMIT 15";
    }
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$p_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($details);
    exit;
}

// Ensure schema
try {
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hide_replenishment', $cols)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN hide_replenishment TINYINT(1) DEFAULT 0");
    }
} catch (Exception $e) {}

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!hasRole('admin')) {
        header("Location: replenishment.php?msg=Access denied&type=error");
        exit;
    }
    $p_id = (int)$_POST['product_id'];
    $pdo->prepare("UPDATE products SET hide_replenishment = 1 WHERE id = ?")->execute([$p_id]);
    header("Location: replenishment.php?msg=deleted");
    exit;
}

$page_title = 'Replenishment Report';
include '../../includes/header.php';

// Logic: Find Products where Demand (Pending SOs) > Current Stock
$sql = "
    SELECT 
        p.*,
        COALESCE(s.quantity, 0) as current_stock,
        (
            SELECT SUM(soi.quantity)
            FROM sales_order_items soi
            JOIN sales_orders so ON soi.order_id = so.id
            WHERE soi.product_id = p.id
            AND so.status IN ('confirmed', 'invoiced', 'paid')
            AND so.status NOT IN ('shipped', 'delivered', 'cancelled')
            AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00')
        ) as pending_demand
    FROM products p
    LEFT JOIN stock s ON p.id = s.product_id
    WHERE (p.hide_replenishment IS NULL OR p.hide_replenishment = 0)
    HAVING pending_demand > 0 OR current_stock < 0
    ORDER BY (current_stock - pending_demand) ASC
";

$stmt = $pdo->query($sql);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link href="/stock/assets/css/style.css" rel="stylesheet">
<link href="../../assets/css/sales-mobile.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .rep-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .rep-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .rep-btn-primary:hover {
        background-color: #1D4ED8 !important;
        border-color: #1D4ED8 !important;
        color: #fff !important;
    }
    .replenishment-row { cursor: pointer; transition: background 0.15s; }
    .replenishment-row:hover { background-color: #f9fafb !important; }
    .table-danger-soft { background-color: #fef2f2; }
    .replenishment-table thead tr.rep-table-head th {
        background-color: #1c2331 !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        border-bottom: 2px solid #151a24 !important;
        vertical-align: middle;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .replenishment-table thead tr.rep-table-head th:not(:last-child) {
        border-right: 1px solid rgba(255, 255, 255, 0.08);
    }
    .btn-xs { padding: 0.1rem 0.3rem; line-height: 1; }
    .insight-badge { font-size: 0.65rem; padding: 2px 6px; border-radius: 10px; }
    .insights-shell { background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%); }
    .insight-list { display: grid; gap: 0.85rem; padding: 1rem; }
    .insight-card {
        border: 1px solid #e8eef7;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        padding: 0.95rem 1rem;
    }
    .insight-card-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 0.85rem;
    }
    .insight-date {
        font-size: 0.72rem;
        font-weight: 700;
        color: #2563eb;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: #eff6ff;
        border-radius: 999px;
        padding: 0.35rem 0.6rem;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .insight-order-link {
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        text-decoration: none;
    }
    .insight-order-link:hover { color: #2563eb; }
    .insight-customer { color: #64748b; font-size: 0.78rem; margin-top: 0.15rem; }
    .insight-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.75rem;
    }
    .insight-meta {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.7rem 0.75rem;
        min-height: 70px;
    }
    .insight-label {
        display: block;
        font-size: 0.64rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #94a3b8;
        margin-bottom: 0.35rem;
    }
    .insight-value {
        font-size: 0.82rem;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.35;
    }
    .insight-qty {
        font-size: 1rem;
        font-weight: 800;
        color: #111827;
    }
    .invoice-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.38rem 0.7rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid transparent;
    }
    .invoice-pill.view {
        background: #ecfdf3;
        color: #047857;
        border-color: #a7f3d0;
    }
    .invoice-pill.create {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fdba74;
    }
    .status-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.38rem 0.7rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .status-chip.status-primary { background: #dbeafe; color: #1d4ed8; }
    .status-chip.status-info { background: #cffafe; color: #0f766e; }
    .status-chip.status-secondary { background: #e2e8f0; color: #475569; }
    .status-chip.status-success { background: #dcfce7; color: #15803d; }
    @media (max-width: 768px) {
        .insight-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .insight-card-top { flex-direction: column; }
    }
</style>

<main class="main-content rep-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="../purchases/index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Purchase orders
                </a>
                <a href="../purchases/create.php" class="btn rep-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0">
                    <i class="fas fa-plus text-sm"></i> New purchase
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                        <i class="fas fa-boxes text-[#2563EB]"></i><span>Replenishment</span>
                    </h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <span><i class="fas fa-hand-pointer text-gray-400 me-1"></i>Click a row for orders and invoices driving demand.</span>
                <span class="text-gray-300">|</span>
                <span class="font-medium text-gray-700 tabular-nums"><?php echo count($items); ?> product<?php echo count($items) === 1 ? '' : 's'; ?></span>
            </div>
        </div>

        <div class="bg-white border-t border-gray-200">
            <div class="overflow-x-auto">
                <table class="table table-hover align-middle mb-0 replenishment-table w-100">
                    <thead>
                        <tr class="rep-table-head">
                            <th class="ps-3 py-3" style="width: 40%">Product</th>
                            <th class="text-center py-3">Stock</th>
                            <th class="text-center py-3" title="Sold but not shipped">Pending</th>
                            <th class="text-center py-3">Net status</th>
                            <th class="text-center py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-16 px-4">
                                <i class="fas fa-check-circle text-5xl text-emerald-400 mb-4"></i>
                                <p class="text-gray-700 text-lg font-medium mb-1">No shortages found</p>
                                <p class="text-gray-500 text-base">Pending orders can be fulfilled with current stock.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item):
                                $pending = $item['pending_demand'] ?? 0;
                                $stock = $item['current_stock'];
                                $net = $stock - $pending;
                            ?>
                            <?php
                                $rowImage = $item['image'] ?? $item['main_image'] ?? '';
                            ?>
                            <tr class="replenishment-row border-b border-gray-100 <?php echo $net < 0 ? 'table-danger-soft' : ''; ?>"
                                onclick="showOrderInsights(<?php echo $item['id']; ?>, '<?php echo addslashes($item['name']); ?>', '<?php echo $stock < 0 ? 'invoice' : 'all'; ?>')">
                                <td class="ps-3 py-3">
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($rowImage)): ?>
                                            <img src="/stock/uploads/products/<?php echo $item['id']; ?>/medium/<?php echo htmlspecialchars($rowImage); ?>"
                                                 class="rounded border me-2" width="40" height="40" style="object-fit:cover;" alt="">
                                        <?php else: ?>
                                            <div class="rounded border me-2 bg-gray-50 d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                                <i class="fas fa-box text-gray-400"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div style="line-height: 1.25;">
                                            <div class="fw-bold text-gray-900 text-base text-truncate" style="max-width: 280px;"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <div class="text-gray-500 text-sm"><?php echo htmlspecialchars($item['product_code'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center py-3 fw-bold text-gray-900 text-base tabular-nums"><?php echo htmlspecialchars((string) $stock); ?></td>
                                <td class="text-center py-3 fw-bold text-amber-600 text-base tabular-nums">
                                    <?php echo htmlspecialchars((string) $pending); ?>
                                    <i class="fas fa-search-plus ms-1 opacity-50 small"></i>
                                </td>
                                <td class="text-center py-3">
                                    <?php if ($net < 0): ?>
                                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-[#DC3545] text-white">Short: <?php echo abs($net); ?></span>
                                    <?php else: ?>
                                        <span class="inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full bg-[#28A745] text-white">Available: <?php echo $net; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center py-3" onclick="event.stopPropagation()">
                                    <div class="d-flex gap-1 justify-content-center align-items-center">
                                        <a href="#"
                                           data-product-name="<?php echo htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES); ?>"
                                           data-product-code="<?php echo htmlspecialchars((string)($item['product_code'] ?? ''), ENT_QUOTES); ?>"
                                           onclick="event.preventDefault(); event.stopPropagation(); choosePOType(<?php echo (int)$item['id']; ?>, <?php echo (int)abs($net); ?>, this.dataset.productName || '', this.dataset.productCode || '');"
                                           class="btn btn-sm rep-btn-primary text-white border-0 px-2 py-1" style="font-size: 0.8rem;">
                                            <i class="fas fa-plus"></i> PO
                                        </a>
                                        <?php if (hasRole('admin')): ?>
                                            <form method="POST" id="deleteForm-<?php echo $item['id']; ?>" class="m-0 d-inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                                <button type="button" onclick="confirmDelete(<?php echo $item['id']; ?>)" class="btn btn-sm btn-outline-danger px-2 py-1" style="font-size: 0.8rem;">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
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
</main>

<!-- Insights Modal -->
<div class="modal fade" id="insightsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3 border-0" style="background-color: #2563EB;">
                <h6 class="modal-title fw-bold mb-0" id="modalTitle"><i class="fas fa-chart-line me-2"></i>Order insights</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="modalLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted small">Fetching demand details...</p>
                </div>
                <div id="modalContent" class="d-none">
                    <div id="insightsTableBody" class="insights-shell">
                        <!-- Dynamic Content -->
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 bg-gray-50 border-top border-gray-200">
                <button type="button" class="btn btn-sm btn-secondary rounded-md px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function choosePOType(productId, qty, productName = '', productCode = '') {
        const safeQty = (parseInt(qty, 10) > 0) ? parseInt(qty, 10) : 1;
        const p = encodeURIComponent(String(productId));
        const q = encodeURIComponent(String(safeQty));
        const pn = encodeURIComponent(String(productName || ''));
        const pc = encodeURIComponent(String(productCode || ''));
        const extra = `&product_name=${pn}&product_code=${pc}`;

        const goDomestic = () => window.location.href = `../purchases/domestic_create.php?product_id=${p}&qty=${q}${extra}`;
        const goOutdoor = () => window.location.href = `../purchases/create.php?product_id=${p}&qty=${q}${extra}`;

        // Fallback for localhost / offline environments where CDN scripts may not load.
        if (typeof Swal === 'undefined' || !Swal || typeof Swal.fire !== 'function') {
            const isDomestic = window.confirm('Create Domestic PO?\n\nOK = Domestic\nCancel = Outdoor');
            if (isDomestic) return goDomestic();
            return goOutdoor();
        }

        Swal.fire({
            title: 'Create PO',
            text: 'Which PO type do you want?',
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Domestic',
            denyButtonText: 'Outdoor',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2563EB'
        }).then((result) => {
            if (result.isConfirmed) {
                goDomestic();
            } else if (result.isDenied) {
                goOutdoor();
            }
        });
    }

    function showOrderInsights(productId, productName, detailMode = 'all') {
        const modal = new bootstrap.Modal(document.getElementById('insightsModal'));
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-search-dollar me-2"></i>Demand Insights: ' + productName;
        
        const loading = document.getElementById('modalLoading');
        const content = document.getElementById('modalContent');
        const tbody = document.getElementById('insightsTableBody');
        
        loading.classList.remove('d-none');
        content.classList.add('d-none');
        tbody.innerHTML = '';
        
        modal.show();
        
        fetch(`replenishment.php?action=get_order_details&product_id=${productId}&detail_mode=${detailMode}`)
            .then(r => r.json())
            .then(data => {
                loading.classList.add('d-none');
                content.classList.remove('d-none');
                
                if (data.length === 0) {
                    tbody.innerHTML = `<div class="text-center py-5 text-muted small">${detailMode === 'invoice' ? 'No invoice history found for this replenishment item.' : 'No recent order history found for this product.'}</div>`;
                } else {
                    tbody.innerHTML = '<div class="insight-list"></div>';
                    const insightList = tbody.querySelector('.insight-list');
                    data.forEach(d => {
                        const date = new Date(d.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
                        // NOTE: This page lives under /stock/modules/reports/, so we must go up 3 levels to reach /modules/.
                        const orderLink = `../../../modules/sales/orders/view.php?id=${d.order_id}`;
                        const invoiceLink = d.invoice_id ? `../../../modules/sales/invoices/view.php?id=${d.invoice_id}` : null;
                        const createInvoiceLink = `../../../modules/sales/invoices/create.php?order_id=${d.order_id}`;
                        const invoiceDisplay = d.invoice_id
                            ? `<a href="${invoiceLink}" class="invoice-pill view" target="_blank"><i class="fas fa-file-invoice"></i>${d.invoice_number || 'View Invoice'}</a>`
                            : `<a href="${createInvoiceLink}" class="invoice-pill create" target="_blank"><i class="fas fa-plus-circle"></i>Create Invoice</a>`;
                        
                        let statusColor = 'status-primary';
                        if (d.status === 'shipped') statusColor = 'status-secondary';
                        if (d.status === 'delivered') statusColor = 'status-success';
                        if (d.status === 'invoiced' || d.status === 'paid') statusColor = 'status-info';
                        
                        const row = `
                            <div class="insight-card">
                                <div class="insight-card-top">
                                    <div>
                                        <div class="insight-date"><i class="fas fa-calendar-alt"></i>${date}</div>
                                    </div>
                                    <div class="text-end">
                                        ${invoiceDisplay}
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <a href="${orderLink}" class="insight-order-link" target="_blank">#${d.order_number}</a>
                                    <div class="insight-customer">${d.customer || 'Unknown Customer'}</div>
                                </div>
                                <div class="insight-grid">
                                    <div class="insight-meta">
                                        <span class="insight-label">Salesperson</span>
                                        <div class="insight-value">${d.salesperson || 'System'}</div>
                                    </div>
                                    <div class="insight-meta">
                                        <span class="insight-label">Status</span>
                                        <div class="insight-value"><span class="status-chip ${statusColor}">${d.status}</span></div>
                                    </div>
                                    <div class="insight-meta">
                                        <span class="insight-label">Customer</span>
                                        <div class="insight-value">${d.customer || 'Unknown Customer'}</div>
                                    </div>
                                    <div class="insight-meta">
                                        <span class="insight-label">Invoice Qty</span>
                                        <div class="insight-qty">${d.quantity}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                        insightList.insertAdjacentHTML('beforeend', row);
                    });
                }
            })
            .catch(err => {
                loading.innerHTML = '<div class="text-danger py-4"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load details.</div>';
                console.error(err);
            });
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'Hidding Need?',
            text: "This hides the product from the replenishment report until stock changes.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, hide it'
        }).then((result) => {
            if (result.isConfirmed) document.getElementById('deleteForm-' + id).submit();
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
