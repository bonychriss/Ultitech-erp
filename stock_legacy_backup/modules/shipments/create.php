<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();
ensure_shipment_po_linking_schema($pdo);

// Fetch Data with Error Handling
$suppliers = [];
$shippers = [];
$po_data = null;
$po_items = [];
$auto_description = '';
$purchase_id = $_GET['purchase_id'] ?? $_POST['purchase_id'] ?? null;

try {
    $suppliers = $pdo->query("SELECT * FROM stocks_suppliers ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) {
    error_log("Suppliers Error: " . $e->getMessage());
}

try {
    $stmtShippers = $pdo->query("SELECT * FROM shippers WHERE is_active = 1 ORDER BY name ASC");
    if ($stmtShippers) $shippers = $stmtShippers->fetchAll();
} catch (Throwable $e) {
    error_log("Shippers Error: " . $e->getMessage());
}

if ($purchase_id) {
    $purchase_id = (int) $purchase_id;
    try {
        if (stocks_po_has_linked_shipment($pdo, $purchase_id)) {
            $_SESSION['shipment_notice'] = 'A shipment is already linked to this purchase order.';
            echo "<script>window.location.href='index.php';</script>";
            exit;
        }

        // Fetch PO
        $stmt = $pdo->prepare("SELECT p.*, s.name AS supplier_name
                            FROM stocks_purchase_orders p
                            JOIN stocks_suppliers s ON p.supplier_id = s.id
                            WHERE p.id = ?");
        $stmt->execute([$purchase_id]);
        $po_data = $stmt->fetch();

        // Fetch items for description (stock catalog items)
        $stmtItems = $pdo->prepare("SELECT pi.*, si.name AS product_name, si.sku
                                FROM stocks_po_items pi
                                JOIN stocks_items si ON pi.item_id = si.id
                                WHERE pi.po_id = ?");
        $stmtItems->execute([$purchase_id]);
        $po_items = $stmtItems->fetchAll();

        // Auto-desc
        $desc_items = array_map(static function ($item) {
            return $item['product_name'] ?? '';
        }, $po_items);
        $desc_items = array_filter($desc_items);
        $auto_description = implode(', ', array_slice($desc_items, 0, 3));
        if (count($desc_items) > 3) {
            $auto_description .= ' + ' . (count($desc_items) - 3) . ' others';
        }
    } catch (Throwable $e) {
        error_log('PO Fetch Error: ' . $e->getMessage());
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $supplier_id = $_POST['supplier_id'];
        $invoice_number = clean_input($_POST['invoice_number']);
        $tracking_number = clean_input($_POST['tracking_number']);
        $contact_number = clean_input($_POST['contact_number']);
        $shipper_id = !empty($_POST['shipper_id']) ? $_POST['shipper_id'] : null;
        $est_cost = $_POST['estimated_clearance_cost'] ?? 0;
        $shipment_date = !empty($_POST['shipment_date']) ? $_POST['shipment_date'] : null;
        $etd = !empty($_POST['etd']) ? $_POST['etd'] : null;
        $eta = !empty($_POST['eta']) ? $_POST['eta'] : ($_GET['eta'] ?? null);
        $packages = $_POST['packages_count'] ?? 1;
        $cbm = $_POST['cbm'] ?? 0;
        $value = $_POST['total_value'] ?? 0;
        $total_value_currency = normalize_shipment_total_value_currency((string) ($_POST['total_value_currency'] ?? 'USD'));

        $description = clean_input($_POST['description']);
        $status = $_POST['status'];

        // Generate Shipment Number
        $shipment_number = 'SHP-' . date('Ymd-His'); 

        $purchase_id_post = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : 0;
        $stocks_po_id = $purchase_id_post > 0 ? $purchase_id_post : null;

        if ($stocks_po_id && stocks_po_has_linked_shipment($pdo, $stocks_po_id)) {
            throw new Exception('A shipment is already linked to this purchase order.');
        }

        $stmt = $pdo->prepare("INSERT INTO shipments (
            shipment_number, supplier_id, stocks_po_id, invoice_number, tracking_number, contact_number, shipper_id,
            estimated_clearance_cost, shipment_date, etd, eta, packages_count, cbm, total_value, total_value_currency, description, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt->execute([
            $shipment_number, $supplier_id, $stocks_po_id, $invoice_number, $tracking_number, $contact_number, $shipper_id,
            $est_cost, $shipment_date, $etd, $eta, $packages, $cbm, $value, $total_value_currency, $description, $status,
        ])) {
            $shipment_id = $pdo->lastInsertId();

            if ($stocks_po_id) {
                $stmtItems = $pdo->prepare('SELECT * FROM stocks_po_items WHERE po_id = ?');
                $stmtItems->execute([$stocks_po_id]);
                $items_to_link = $stmtItems->fetchAll();

                $stmtLink = $pdo->prepare('INSERT INTO shipment_items (shipment_id, product_id, stocks_item_id, purchase_id, quantity, unit_price) VALUES (?, NULL, ?, ?, ?, ?)');
                foreach ($items_to_link as $item) {
                    $qty = (int) max(0, round((float) ($item['qty_ordered'] ?? 0)));
                    if ($qty < 1) {
                        continue;
                    }
                    $stmtLink->execute([
                        $shipment_id,
                        (int) $item['item_id'],
                        $stocks_po_id,
                        $qty,
                        (float) ($item['unit_cost'] ?? 0),
                    ]);
                }
            }

            $_SESSION['stock_shipment_create_success'] = [
                'title' => 'Success!',
                'message' => 'Shipment created: ' . $shipment_number,
                'variant' => 'success',
            ];
            redirect('index.php');
        } else {
            $error = "Failed to create shipment (Database Error)";
        }
    } catch (Throwable $e) {
        $error = "Save Error: " . $e->getMessage();
    }
}

$is_form_error = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($error));
$form = $is_form_error ? $_POST : [];
$shp = static function ($key, $default = '') use ($form) {
    return htmlspecialchars($form[$key] ?? $default, ENT_QUOTES, 'UTF-8');
};
$supplier_sel = $is_form_error ? (string) ($form['supplier_id'] ?? '') : (string) ($po_data['supplier_id'] ?? '');
$desc_default = $auto_description;
$contact_default = $po_data ? ($po_data['contact_number'] ?? '') : '';
$po_line_total = 0.0;
if (!empty($po_items) && is_array($po_items)) {
    foreach ($po_items as $ln) {
        $po_line_total += (float) ($ln['qty_ordered'] ?? 0) * (float) ($ln['unit_cost'] ?? 0);
    }
}
$total_val_default = $po_data ? (string) ($po_data['total_amount'] ?? $po_line_total) : '0.00';
$tracking_default = $_GET['tracking'] ?? 'NA';

$form_action_q = $purchase_id ? ('?purchase_id=' . urlencode((string) $purchase_id)) : '';

$page_title = 'Create Shipment';
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
    .btn.ship-btn-primary {
        background-color: #2563EB !important;
        color: #fff !important;
        border-color: #2563EB !important;
    }
    .ship-form-card-h {
        background-color: #1c2331;
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.65rem 1.25rem;
        border-bottom: 2px solid #151a24;
    }
</style>

<main class="main-content ship-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                <a href="index.php" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-arrow-left text-sm"></i> Shipments
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Create shipment</h1>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <?php if ($po_data): ?>
                    <i class="fas fa-link text-gray-400 me-1"></i>Linked to purchase order <strong class="text-gray-800">#PO-<?php echo str_pad((string) $po_data['id'], 5, '0', STR_PAD_LEFT); ?></strong>
                    <span class="text-gray-400 mx-2">Â·</span>
                <?php endif; ?>
                <i class="fas fa-info-circle text-gray-400 me-1"></i>Fields marked <span class="fw-semibold text-gray-800">*</span> are required.
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger rounded-lg border-0 shadow-sm mb-4" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mx-auto" style="max-width: 56rem;">
                <form action="create.php<?php echo htmlspecialchars($form_action_q, ENT_QUOTES, 'UTF-8'); ?>" method="POST">
                    <?php if ($purchase_id): ?>
                        <input type="hidden" name="purchase_id" value="<?php echo htmlspecialchars((string) $purchase_id, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>

                    <div class="ship-form-card-h"><i class="fas fa-file-invoice me-2 opacity-80"></i>Basic information</div>
                    <div class="p-4 p-lg-4 border-bottom border-gray-100">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="supplier_id" class="form-label fw-semibold text-gray-700">Supplier <span class="text-danger">*</span></label>
                                <select name="supplier_id" id="supplier_id" class="form-select rounded-md border-gray-300" required>
                                    <option value="">Select supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo (int) $sup['id']; ?>" <?php echo $supplier_sel !== '' && (string) $sup['id'] === $supplier_sel ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="invoice_number" class="form-label fw-semibold text-gray-700">Invoice number <span class="text-danger">*</span></label>
                                <input type="text" name="invoice_number" id="invoice_number" class="form-control rounded-md border-gray-300" required placeholder="e.g. INV-2026-001" value="<?php echo $shp('invoice_number'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="contact_number" class="form-label fw-semibold text-gray-700">Contact number</label>
                                <input type="text" name="contact_number" id="contact_number" class="form-control rounded-md border-gray-300" placeholder="e.g. 0086123456789" value="<?php echo $shp('contact_number', $contact_default); ?>">
                            </div>
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="description" class="form-label fw-semibold text-gray-700">Description</label>
                                <textarea name="description" id="description" class="form-control rounded-md border-gray-300" rows="2" placeholder="e.g. product summary for customs"><?php echo $shp('description', $desc_default); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="ship-form-card-h"><i class="fas fa-shipping-fast me-2 opacity-80"></i>Shipping &amp; status</div>
                    <div class="p-4 p-lg-4 border-bottom border-gray-100">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="shipper_id" class="form-label fw-semibold text-gray-700">Shipper / forwarder</label>
                                <select name="shipper_id" id="shipper_id" class="form-select rounded-md border-gray-300">
                                    <option value="">Select shipper</option>
                                    <?php foreach ($shippers as $ship): ?>
                                        <option value="<?php echo (int) $ship['id']; ?>" <?php echo $shp('shipper_id') !== '' && $shp('shipper_id') === (string) $ship['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ship['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label fw-semibold text-gray-700">Status</label>
                                <select name="status" id="status" class="form-select rounded-md border-gray-300">
                                    <?php
                                    $st = $shp('status', 'pending');
                                    $status_opts = [
                                        'pending' => 'Pending',
                                        'shipped' => 'Shipped',
                                        'arrived_at_port' => 'Arrived at port',
                                    ];
                                    foreach ($status_opts as $val => $label):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $st === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="estimated_clearance_cost" class="form-label fw-semibold text-gray-700">Est. clearance cost</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start border-gray-300 bg-gray-50">$</span>
                                    <input type="number" step="0.01" name="estimated_clearance_cost" id="estimated_clearance_cost" class="form-control rounded-end border-gray-300" value="<?php echo $shp('estimated_clearance_cost', '0.00'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="tracking_number" class="form-label fw-semibold text-gray-700">Tracking number</label>
                                <input type="text" name="tracking_number" id="tracking_number" class="form-control rounded-md border-gray-300" value="<?php echo $shp('tracking_number', $tracking_default); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="ship-form-card-h"><i class="fas fa-calendar-alt me-2 opacity-80"></i>Timeline</div>
                    <div class="p-4 p-lg-4 border-bottom border-gray-100">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="shipment_date" class="form-label fw-semibold text-gray-700">Shipment date</label>
                                <input type="date" name="shipment_date" id="shipment_date" class="form-control rounded-md border-gray-300" value="<?php echo $shp('shipment_date'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="etd" class="form-label fw-semibold text-gray-700">ETD (departure)</label>
                                <input type="date" name="etd" id="etd" class="form-control rounded-md border-gray-300" value="<?php echo $shp('etd'); ?>">
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="eta" class="form-label fw-semibold text-gray-700">ETA (arrival)</label>
                                <input type="date" name="eta" id="eta" class="form-control rounded-md border-gray-300" value="<?php echo $shp('eta', $_GET['eta'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="ship-form-card-h"><i class="fas fa-boxes me-2 opacity-80"></i>Cargo</div>
                    <div class="p-4 p-lg-4">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="packages_count" class="form-label fw-semibold text-gray-700">Total packages</label>
                                <input type="number" name="packages_count" id="packages_count" class="form-control rounded-md border-gray-300" min="1" value="<?php echo $shp('packages_count', '1'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="cbm" class="form-label fw-semibold text-gray-700">Total CBM (mÂ³)</label>
                                <input type="number" step="0.001" name="cbm" id="cbm" class="form-control rounded-md border-gray-300" value="<?php echo $shp('cbm', '0.000'); ?>">
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="total_value" class="form-label fw-semibold text-gray-700">Total invoice value</label>
                                <div class="input-group">
                                    <select name="total_value_currency" id="total_value_currency" class="form-select rounded-start border-gray-300" style="max-width: 7.5rem;" aria-label="Invoice currency">
                                        <?php
                                        $curSel = $shp('total_value_currency', 'USD');
                                        foreach (shipment_total_value_currency_options() as $code => $label):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $curSel === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" step="0.01" name="total_value" id="total_value" class="form-control rounded-end border-gray-300" value="<?php echo $shp('total_value', $total_val_default); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 px-4 py-3 bg-gray-50 border-top border-gray-200">
                        <button type="submit" class="btn ship-btn-primary rounded-md px-4 py-2 fw-semibold border-0">
                            <i class="fas fa-save me-2"></i>Create shipment
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary rounded-md px-4 py-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
