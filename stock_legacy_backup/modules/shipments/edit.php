<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();
ensure_shipment_po_linking_schema($pdo);

if (!isset($_GET['id'])) {
    redirect('index.php');
}
$id = (int) $_GET['id'];

$stmt = $pdo->prepare('SELECT s.*, spo.po_number AS linked_po_number
    FROM shipments s
    LEFT JOIN stocks_purchase_orders spo ON spo.id = s.stocks_po_id
    WHERE s.id = ?');
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    redirect('index.php');
}

$suppliers = $pdo->query('SELECT * FROM stocks_suppliers ORDER BY name ASC')->fetchAll();
$shippers = $pdo->query('SELECT * FROM shippers WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'];
    $invoice_number = clean_input($_POST['invoice_number']);
    $tracking_number = clean_input($_POST['tracking_number']);
    $contact_number = clean_input($_POST['contact_number']);
    $shipper_id = !empty($_POST['shipper_id']) ? $_POST['shipper_id'] : null;
    $est_cost = $_POST['estimated_clearance_cost'] ?? 0;
    $shipment_date = !empty($_POST['shipment_date']) ? $_POST['shipment_date'] : null;
    $etd = !empty($_POST['etd']) ? $_POST['etd'] : null;
    $eta = !empty($_POST['eta']) ? $_POST['eta'] : null;
    $packages = $_POST['packages_count'] ?? 1;
    $cbm = $_POST['cbm'] ?? 0;
    $value = $_POST['total_value'] ?? 0;
    $total_value_currency = normalize_shipment_total_value_currency((string) ($_POST['total_value_currency'] ?? 'USD'));
    $description = clean_input($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'pending';

    $stmt = $pdo->prepare('UPDATE shipments SET
        supplier_id=?, invoice_number=?, tracking_number=?, contact_number=?,
        shipper_id=?, estimated_clearance_cost=?, shipment_date=?, etd=?, eta=?,
        packages_count=?, cbm=?, total_value=?, total_value_currency=?, description=?, status=?,
        updated_at=NOW()
        WHERE id=?');

    if ($stmt->execute([
        $supplier_id, $invoice_number, $tracking_number, $contact_number,
        $shipper_id, $est_cost, $shipment_date, $etd, $eta,
        $packages, $cbm, $value, $total_value_currency, $description, $status,
        $id,
    ])) {
        flash('success', 'Shipment updated successfully');
        redirect('view.php?id=' . $id);
    }
    $error = 'Failed to update shipment';
}

$is_form_error = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== null);
$form = $is_form_error ? $_POST : [];
$v = static function (string $key, $default = '') use ($form, $shipment) {
    if ($form !== []) {
        $x = $form[$key] ?? $default;

        return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    }
    $x = $shipment[$key] ?? $default;
    if ($x === null) {
        return htmlspecialchars((string) $default, ENT_QUOTES, 'UTF-8');
    }

    return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
};

$dateVal = static function (string $key) use ($form, $shipment) {
    $raw = $form !== [] ? ($form[$key] ?? '') : ($shipment[$key] ?? '');
    if ($raw === null || $raw === '') {
        return '';
    }
    $s = (string) $raw;

    return strlen($s) >= 10 ? substr($s, 0, 10) : $s;
};

$status_opts = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'shipped' => 'Shipped',
    'in_transit' => 'In transit',
    'arrived_at_port' => 'Arrived at port',
    'in_customs' => 'In customs',
    'ready_for_pickup' => 'Ready for pickup',
    'out_for_delivery' => 'Out for delivery',
    'delivered' => 'Delivered',
    'delayed' => 'Delayed',
    'cancelled' => 'Cancelled',
];
$currentStatus = $form !== [] ? ($form['status'] ?? 'pending') : ($shipment['status'] ?? 'pending');
if (!isset($status_opts[$currentStatus])) {
    $status_opts = [
        $currentStatus => ucwords(str_replace('_', ' ', (string) $currentStatus)),
    ] + $status_opts;
}

$page_title = 'Edit Shipment';
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
                <a href="view.php?id=<?php echo $id; ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-eye text-sm"></i> View
                </a>
                <div class="flex items-center gap-2 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate m-0">Edit shipment</h1>
                    <span class="text-gray-400 font-mono text-sm truncate"><?php echo htmlspecialchars($shipment['invoice_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="flex-1 min-w-[8px]"></div>
            </div>
            <div class="px-4 py-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                <?php if (!empty($shipment['stocks_po_id'])): ?>
                    <i class="fas fa-link text-gray-400 me-1"></i>Linked stock PO
                    <a class="fw-semibold text-[#2563EB] text-decoration-none" href="../purchases/view_po.php?id=<?php echo (int) $shipment['stocks_po_id']; ?>"><?php echo htmlspecialchars($shipment['linked_po_number'] ?: '#' . (int) $shipment['stocks_po_id'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <span class="text-gray-400 mx-2">¡¤</span>
                <?php endif; ?>
                <i class="fas fa-info-circle text-gray-400 me-1"></i>Fields marked <span class="fw-semibold text-gray-800">*</span> are required.
            </div>
        </div>

        <div class="px-4 pt-4">
            <?php if ($error !== null): ?>
                <div class="alert alert-danger rounded-lg border-0 shadow-sm mb-4" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden mx-auto" style="max-width: 56rem;">
                <form method="post" action="edit.php?id=<?php echo $id; ?>">

                    <div class="ship-form-card-h"><i class="fas fa-file-invoice me-2 opacity-80"></i>Basic information</div>
                    <div class="p-4 border-bottom border-gray-100">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="supplier_id" class="form-label fw-semibold text-gray-700">Supplier <span class="text-danger">*</span></label>
                                <select name="supplier_id" id="supplier_id" class="form-select rounded-md border-gray-300" required>
                                    <option value="">Select supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo (int) $sup['id']; ?>" <?php echo (string) ($form['supplier_id'] ?? $shipment['supplier_id']) === (string) $sup['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="invoice_number" class="form-label fw-semibold text-gray-700">Invoice number <span class="text-danger">*</span></label>
                                <input type="text" name="invoice_number" id="invoice_number" class="form-control rounded-md border-gray-300" required placeholder="e.g. INV-2026-001" value="<?php echo $v('invoice_number'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="contact_number" class="form-label fw-semibold text-gray-700">Contact number</label>
                                <input type="text" name="contact_number" id="contact_number" class="form-control rounded-md border-gray-300" placeholder="e.g. 0086123456789" value="<?php echo $v('contact_number'); ?>">
                            </div>
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="description" class="form-label fw-semibold text-gray-700">Description</label>
                                <textarea name="description" id="description" class="form-control rounded-md border-gray-300" rows="2" placeholder="e.g. product summary for customs"><?php echo $v('description'); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="ship-form-card-h"><i class="fas fa-shipping-fast me-2 opacity-80"></i>Shipping &amp; status</div>
                    <div class="p-4 border-bottom border-gray-100">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="shipper_id" class="form-label fw-semibold text-gray-700">Shipper / forwarder</label>
                                <select name="shipper_id" id="shipper_id" class="form-select rounded-md border-gray-300">
                                    <option value="">Select shipper</option>
                                    <?php foreach ($shippers as $ship): ?>
                                        <option value="<?php echo (int) $ship['id']; ?>" <?php echo (string) ($form['shipper_id'] ?? $shipment['shipper_id'] ?? '') === (string) $ship['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($ship['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label fw-semibold text-gray-700">Status</label>
                                <select name="status" id="status" class="form-select rounded-md border-gray-300">
                                    <?php foreach ($status_opts as $val => $label): ?>
                                        <option value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentStatus === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="estimated_clearance_cost" class="form-label fw-semibold text-gray-700">Est. clearance cost</label>
                                <div class="input-group">
                                    <span class="input-group-text rounded-start border-gray-300 bg-gray-50">$</span>
                                    <input type="number" step="0.01" name="estimated_clearance_cost" id="estimated_clearance_cost" class="form-control rounded-end border-gray-300" value="<?php echo $v('estimated_clearance_cost', '0.00'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label for="tracking_number" class="form-label fw-semibold text-gray-700">Tracking number</label>
                                <input type="text" name="tracking_number" id="tracking_number" class="form-control rounded-md border-gray-300" value="<?php echo $v('tracking_number'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="ship-form-card-h"><i class="fas fa-calendar-alt me-2 opacity-80"></i>Timeline</div>
                    <div class="p-4 border-bottom border-gray-100">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="shipment_date" class="form-label fw-semibold text-gray-700">Shipment date</label>
                                <input type="date" name="shipment_date" id="shipment_date" class="form-control rounded-md border-gray-300" value="<?php echo htmlspecialchars($dateVal('shipment_date'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="etd" class="form-label fw-semibold text-gray-700">ETD (departure)</label>
                                <input type="date" name="etd" id="etd" class="form-control rounded-md border-gray-300" value="<?php echo htmlspecialchars($dateVal('etd'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="eta" class="form-label fw-semibold text-gray-700">ETA (arrival)</label>
                                <input type="date" name="eta" id="eta" class="form-control rounded-md border-gray-300" value="<?php echo htmlspecialchars($dateVal('eta'), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="ship-form-card-h"><i class="fas fa-boxes me-2 opacity-80"></i>Cargo</div>
                    <div class="p-4">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="packages_count" class="form-label fw-semibold text-gray-700">Total packages</label>
                                <input type="number" name="packages_count" id="packages_count" class="form-control rounded-md border-gray-300" min="1" value="<?php echo $v('packages_count', '1'); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="cbm" class="form-label fw-semibold text-gray-700">Total CBM (m0…6)</label>
                                <input type="number" step="0.001" name="cbm" id="cbm" class="form-control rounded-md border-gray-300" value="<?php echo $v('cbm', '0.000'); ?>">
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="total_value" class="form-label fw-semibold text-gray-700">Total invoice value</label>
                                <div class="input-group">
                                    <select name="total_value_currency" id="total_value_currency" class="form-select rounded-start border-gray-300" style="max-width: 7.5rem;" aria-label="Invoice currency">
                                        <?php
                                        $curSel = $v('total_value_currency', 'USD');
                                        foreach (shipment_total_value_currency_options() as $code => $label):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $curSel === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" step="0.01" name="total_value" id="total_value" class="form-control rounded-end border-gray-300" value="<?php echo $v('total_value', '0.00'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 px-4 py-3 bg-gray-50 border-top border-gray-200">
                        <button type="submit" class="btn ship-btn-primary rounded-md px-4 py-2 fw-semibold border-0">
                            <i class="fas fa-save me-2"></i>Update shipment
                        </button>
                        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary rounded-md px-4 py-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
