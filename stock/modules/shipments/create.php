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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Inter', sans-serif;
        background: #f8fafc;
        color: #1e293b;
    }
    .main-content-wrapper {
        padding: 2rem;
    }
    .page-shell {
        padding-left: 4rem;
    }
    .editor-shell {
        max-width: 1140px;
        margin: 0 auto;
    }
    .editor-topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .editor-layout {
        display: grid;
        grid-template-columns: 180px minmax(0, 1fr);
        gap: 2rem;
        align-items: start;
    }
    .section-nav {
        position: sticky;
        top: 96px;
        align-self: start;
    }
    .section-nav ul {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .section-nav li + li { margin-top: 0.5rem; }
    .section-nav a {
        display: block;
        padding: 0.45rem 0.75rem;
        border-radius: 8px;
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .section-nav a:hover { background: #eff6ff; color: #2563eb; }
    .section-nav a.is-active { background: #f3e8ff; color: #7c3aed; font-weight: 600; }
    .editor-main { min-width: 0; }
    .editor-section {
        padding-bottom: 2rem;
        margin-bottom: 2rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .editor-section:last-of-type { margin-bottom: 1.5rem; }
    .section-header { margin-bottom: 1.25rem; }
    .section-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }
    .section-subtitle {
        font-size: 12px;
        color: #94a3b8;
        margin: 0;
    }
    .form-row {
        display: grid;
        grid-template-columns: 210px 1fr;
        align-items: start;
        margin-bottom: 24px;
    }
    .form-row:last-child { margin-bottom: 0; }
    .form-label {
        font-size: 14px;
        font-weight: 500;
        color: #1e293b;
        padding-top: 12px;
        margin: 0;
    }
    .form-label span { color: #ef4444; margin-left: 2px; }
    .form-input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
        color: #1e293b;
        outline: none;
        transition: all 0.2s;
        background: #fff;
    }
    .form-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    .help-text {
        font-size: 12px;
        color: #94a3b8;
        margin-top: 6px;
        line-height: 1.5;
    }
    .btn-save {
        background: #7c3aed;
        color: white;
        padding: 14px 48px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 15px;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.22);
        border: 0;
    }
    .btn-save:hover { background: #6d28d9; color: #fff; }
    .btn-cancel {
        border: 1px solid #d8b4fe;
        color: #7c3aed;
        background: #faf5ff;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 14px 24px;
        border-radius: 12px;
        font-weight: 600;
    }
    .btn-cancel:hover {
        background: #f3e8ff;
        color: #6d28d9;
        text-decoration: none;
    }
    @media (max-width: 992px) {
        .main-content-wrapper { padding: 1rem !important; }
        .page-shell { padding-left: 0; }
        .editor-topbar { flex-direction: column; align-items: flex-start; }
        .editor-layout { grid-template-columns: 1fr; gap: 1rem; }
        .section-nav { position: static; }
        .section-nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .section-nav li + li { margin-top: 0; }
        .form-row { grid-template-columns: 1fr; gap: 8px; margin-bottom: 20px; }
        .form-label { padding-top: 0; font-size: 13px; }
        .btn-save { width: 100%; padding: 14px 24px; }
    }
</style>

<div class="main-content-wrapper">
    <div class="page-shell editor-shell">
        <div class="editor-topbar">
            <div>
                <h1 class="text-xl font-semibold text-slate-800 m-0">Create Shipment</h1>
                <?php if ($po_data): ?>
                    <p class="text-sm text-slate-500 mt-1 mb-0">
                        Linked to purchase order #PO-<?php echo str_pad((string) $po_data['id'], 5, '0', STR_PAD_LEFT); ?>
                    </p>
                <?php endif; ?>
            </div>
            <a href="index.php" class="text-slate-400 hover:text-slate-600 text-sm font-medium flex items-center gap-2 no-underline">
                <i class="fas fa-arrow-left text-xs"></i> Back to Shipments
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 max-w-[1000px]" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="create.php<?php echo htmlspecialchars($form_action_q, ENT_QUOTES, 'UTF-8'); ?>" method="POST">
            <?php if ($purchase_id): ?>
                <input type="hidden" name="purchase_id" value="<?php echo htmlspecialchars((string) $purchase_id, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>

            <div class="editor-layout">
                <aside class="section-nav">
                    <ul>
                        <li><a href="#basic-info" class="is-active">Basic</a></li>
                        <li><a href="#shipping-status">Shipping</a></li>
                        <li><a href="#timeline">Timeline</a></li>
                        <li><a href="#cargo">Cargo</a></li>
                    </ul>
                </aside>

                <div class="editor-main">
                    <section class="editor-section" id="basic-info">
                        <div class="section-header">
                            <h2 class="section-title">Basic Information</h2>
                            <p class="section-subtitle">Core shipment details and supplier references.</p>
                        </div>
                        <div class="form-row">
                            <label for="supplier_id" class="form-label">Supplier <span>*</span></label>
                            <div>
                                <select name="supplier_id" id="supplier_id" class="form-input" required>
                                    <option value="">Select supplier</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo (int) $sup['id']; ?>" <?php echo $supplier_sel !== '' && (string) $sup['id'] === $supplier_sel ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="invoice_number" class="form-label">Invoice Number <span>*</span></label>
                            <div>
                                <input type="text" name="invoice_number" id="invoice_number" class="form-input" required placeholder="e.g. INV-2026-001" value="<?php echo $shp('invoice_number'); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="contact_number" class="form-label">Contact Number</label>
                            <div>
                                <input type="text" name="contact_number" id="contact_number" class="form-input" placeholder="e.g. 0086123456789" value="<?php echo $shp('contact_number', $contact_default); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="description" class="form-label">Description</label>
                            <div>
                                <textarea name="description" id="description" class="form-input" rows="2" placeholder="e.g. product summary for customs"><?php echo $shp('description', $desc_default); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="shipping-status">
                        <div class="section-header">
                            <h2 class="section-title">Shipping &amp; Status</h2>
                            <p class="section-subtitle">Carrier references and clearance estimates.</p>
                        </div>
                        <div class="form-row">
                            <label for="shipper_id" class="form-label">Shipper / Forwarder</label>
                            <div>
                                <select name="shipper_id" id="shipper_id" class="form-input">
                                    <option value="">Select shipper</option>
                                    <?php foreach ($shippers as $ship): ?>
                                        <option value="<?php echo (int) $ship['id']; ?>" <?php echo $shp('shipper_id') !== '' && $shp('shipper_id') === (string) $ship['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ship['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="status" class="form-label">Status</label>
                            <div>
                                <select name="status" id="status" class="form-input">
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
                        </div>
                        <div class="form-row">
                            <label for="estimated_clearance_cost" class="form-label">Est. Clearance Cost</label>
                            <div>
                                <input type="number" step="any" name="estimated_clearance_cost" id="estimated_clearance_cost" class="form-input" value="<?php echo $shp('estimated_clearance_cost', '0.00'); ?>">
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="tracking_number" class="form-label">Tracking Number</label>
                            <div>
                                <input type="text" name="tracking_number" id="tracking_number" class="form-input" value="<?php echo $shp('tracking_number', $tracking_default); ?>">
                            </div>
                        </div>
                    </section>

                    <section class="editor-section" id="timeline">
                        <div class="section-header">
                            <h2 class="section-title">Timeline</h2>
                            <p class="section-subtitle">Shipment and expected arrival dates.</p>
                        </div>
                        <div class="form-row">
                            <label for="shipment_date" class="form-label">Shipment Date</label>
                            <div><input type="date" name="shipment_date" id="shipment_date" class="form-input" value="<?php echo $shp('shipment_date'); ?>"></div>
                        </div>
                        <div class="form-row">
                            <label for="etd" class="form-label">ETD (Departure)</label>
                            <div><input type="date" name="etd" id="etd" class="form-input" value="<?php echo $shp('etd'); ?>"></div>
                        </div>
                        <div class="form-row">
                            <label for="eta" class="form-label">ETA (Arrival)</label>
                            <div><input type="date" name="eta" id="eta" class="form-input" value="<?php echo $shp('eta', $_GET['eta'] ?? ''); ?>"></div>
                        </div>
                    </section>

                    <section class="editor-section" id="cargo">
                        <div class="section-header">
                            <h2 class="section-title">Cargo</h2>
                            <p class="section-subtitle">Packages, volume, and invoice value.</p>
                        </div>
                        <div class="form-row">
                            <label for="packages_count" class="form-label">Total Packages</label>
                            <div><input type="number" step="any" name="packages_count" id="packages_count" class="form-input" value="<?php echo $shp('packages_count', '1'); ?>"></div>
                        </div>
                        <div class="form-row">
                            <label for="cbm" class="form-label">Total CBM (m3)</label>
                            <div><input type="number" step="any" name="cbm" id="cbm" class="form-input" value="<?php echo $shp('cbm', '0.000'); ?>"></div>
                        </div>
                        <div class="form-row">
                            <label for="total_value" class="form-label">Total Invoice Value</label>
                            <div class="d-flex gap-2">
                                <select name="total_value_currency" id="total_value_currency" class="form-input" style="max-width: 160px;" aria-label="Invoice currency">
                                    <?php
                                    $curSel = $shp('total_value_currency', 'USD');
                                    foreach (shipment_total_value_currency_options() as $code => $label):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $curSel === $code ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" step="any" name="total_value" id="total_value" class="form-input" value="<?php echo $shp('total_value', $total_val_default); ?>">
                            </div>
                        </div>
                    </section>

                    <div class="d-flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="btn-save">
                            <i class="fas fa-save me-2"></i>Create shipment
                        </button>
                        <a href="index.php" class="btn-cancel">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
