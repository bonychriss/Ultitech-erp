<?php
// session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../includes/shipment-functions.php';
requireLogin();
ensure_shipment_po_linking_schema($pdo);

$step = $_POST['step'] ?? 1;
$preview_data = [];
$mappings = [];
$errors = [];
$success_count = 0;

// Helper: Normalize Header
function normalize_header($header) {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $header)));
}

// Helper: Fuzzy Product Match
function find_product_match($pdo, $desc) {
    // 1. Exact Match on Code
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE product_code = ? LIMIT 1");
    $stmt->execute([$desc]);
    if ($row = $stmt->fetch()) return $row;

    // 2. Exact Match on Name
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE name = ? LIMIT 1");
    $stmt->execute([$desc]);
    if ($row = $stmt->fetch()) return $row;

    // 3. Like Match
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE name LIKE ? LIMIT 1");
    $stmt->execute(["%$desc%"]);
    if ($row = $stmt->fetch()) return $row;
    
    // 4. Keyword Rules (Hardcoded for now as per request)
    $rules = [
        'MASK' => 'Face Mask', 'FACEMASK' => 'Face Mask', 
        'GLOVES' => 'Gloves', 'EYEGLASS' => 'Safety Glasses',
        'CLOTHES' => 'Protective Clothing'
    ];
    foreach ($rules as $key => $val) {
        if (stripos($desc, $key) !== false) {
             $stmt = $pdo->prepare("SELECT id, name FROM products WHERE name LIKE ? LIMIT 1");
             $stmt->execute(["%$val%"]);
             if ($row = $stmt->fetch()) return $row;
        }
    }
    
    return null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($step == 2 && isset($_FILES['csv_file'])) {
        // Process Upload
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, "r")) !== FALSE) {
            $headers = fgetcsv($handle, 1000, ",");
            $expected_cols = ['supplier', 'contact', 'invoicenumber', 'track', 'pkgs', 'cbm', 'value', 'desc', 'shipmentdate', 'shipper', 'ecc', 'etd', 'eta', 'status'];
            
            // Map headers to index
            $header_map = [];
            foreach ($headers as $index => $col) {
                $norm = normalize_header($col);
                if (in_array($norm, $expected_cols)) $header_map[$norm] = $index;
                // Expanded mapping for variations
                if ($norm == 'invoice') $header_map['invoicenumber'] = $index;
                if ($norm == 'tracking') $header_map['track'] = $index;
                if ($norm == 'packages') $header_map['pkgs'] = $index;
                if ($norm == 'description') $header_map['desc'] = $index;
                if ($norm == 'date') $header_map['shipmentdate'] = $index;
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Extract Data using Map
                $row = [];
                $row['supplier'] = $data[$header_map['supplier'] ?? 0] ?? '';
                $row['contact'] = $data[$header_map['contact'] ?? 1] ?? '';
                $row['invoice_number'] = $data[$header_map['invoicenumber'] ?? 2] ?? '';
                $row['tracking_number'] = $data[$header_map['track'] ?? 3] ?? 'NA';
                $row['pkgs'] = (int)($data[$header_map['pkgs'] ?? 4] ?? 1);
                $row['cbm'] = (float)($data[$header_map['cbm'] ?? 5] ?? 0);
                $row['value'] = (float)preg_replace('/[^0-9.]/', '', $data[$header_map['value'] ?? 6] ?? 0);
                $row['description'] = $data[$header_map['desc'] ?? 7] ?? '';
                $row['shipment_date'] = $data[$header_map['shipmentdate'] ?? 8] ?? '';
                $row['shipper'] = $data[$header_map['shipper'] ?? 9] ?? '';
                $row['ecc'] = $data[$header_map['ecc'] ?? 10] ?? '';
                $row['etd'] = $data[$header_map['etd'] ?? 11] ?? '';
                $row['eta'] = $data[$header_map['eta'] ?? 12] ?? '';
                $row['status'] = strtolower($data[$header_map['status'] ?? 13] ?? 'pending');
                
                // Conversions
                if ($row['status'] == 'arrived') $row['status'] = 'arrived_at_port';
                
                // Auto-Match Product
                $match = find_product_match($pdo, $row['description']);
                $row['matched_product_id'] = $match['id'] ?? null;
                $row['matched_product_name'] = $match['name'] ?? null;
                
                $preview_data[] = $row;
            }
            fclose($handle);
            $_SESSION['import_data'] = $preview_data;
        }
    } elseif ($step == 3) {
        // Process Import
        $preview_data = $_SESSION['import_data'] ?? [];
        foreach ($preview_data as $row) {
             // 1. Get/Create Supplier
             $supp_id = null;
             if (!empty($row['supplier'])) {
                 $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE name = ?");
                 $stmt->execute([$row['supplier']]);
                 if ($s = $stmt->fetch()) {
                     $supp_id = $s['id'];
                 } else {
                     $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_person) VALUES (?, ?)");
                     $stmt->execute([$row['supplier'], 'Imported Contact']);
                     $supp_id = $pdo->lastInsertId();
                 }
             }
             
             // 2. Get/Create Shipper
             $shipper_id = null;
              if (!empty($row['shipper'])) {
                 $stmt = $pdo->prepare("SELECT id FROM shippers WHERE name = ?");
                 $stmt->execute([$row['shipper']]);
                 if ($s = $stmt->fetch()) {
                     $shipper_id = $s['id'];
                 } else {
                     $stmt = $pdo->prepare("INSERT INTO shippers (name) VALUES (?)");
                     $stmt->execute([$row['shipper']]);
                     $shipper_id = $pdo->lastInsertId();
                 }
             }
             
             // 3. Insert Shipment
            try {
                 $stmt = $pdo->prepare("INSERT INTO shipments (
                    supplier_id, contact_number, invoice_number, tracking_number, 
                    packages_count, cbm, total_value, description, 
                    shipment_date, shipper_id, estimated_clearance_cost, etd, eta, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                // Convert ecc to decimal (column was renamed from ecc_number VARCHAR to estimated_clearance_cost DECIMAL)
                $ecc_value = !empty($row['ecc']) ? (float)preg_replace('/[^0-9.]/', '', $row['ecc']) : 0.00;
                
                $stmt->execute([
                    $supp_id, $row['contact'], $row['invoice_number'], $row['tracking_number'],
                    $row['pkgs'], $row['cbm'], $row['value'], $row['description'],
                    $row['shipment_date'] ?: null, $shipper_id, $ecc_value, 
                    $row['etd'] ?: null, $row['eta'] ?: null, $row['status']
                ]);
                $success_count++;
            } catch (Exception $e) {
                $errors[] = "Row Error (" . $row['invoice_number'] . "): " . $e->getMessage();
            }
        }
        unset($_SESSION['import_data']);
    }
}

$page_title = 'Import Shipments';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Bulk Import Wizard</h4>
            <a href="index.php" class="btn btn-secondary btn-sm rounded-0"><i class="fas fa-times"></i> Close</a>
        </div>
        
        <div class="card border-0 shadow-sm rounded-0">
            <div class="card-body">
                <!-- Progress -->
                <div class="progress mb-4" style="height: 5px;">
                    <div class="progress-bar" style="width: <?php echo ($step/3)*100; ?>%"></div>
                </div>
                
                <?php if ($step == 1): ?>
                <!-- STEP 1: UPLOAD -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="step" value="2">
                    <div class="text-center py-5">
                        <i class="fas fa-file-csv fa-4x text-muted mb-3"></i>
                        <h5>Upload CSV File</h5>
                        <p class="text-muted small">Matches specific external system format.</p>
                        
                        <div class="mx-auto" style="max-width: 400px;">
                            <input type="file" name="csv_file" class="form-control rounded-0 mb-3" accept=".csv" required>
                            <button type="submit" class="btn btn-primary rounded-0 w-100 mb-3">Upload & Preview</button>
                            <a href="download_template.php" class="btn btn-outline-success rounded-0 w-100 btn-sm"><i class="fas fa-file-excel"></i> Download Sample Excel/CSV</a>
                        </div>
                        
                        <div class="mt-4 text-start bg-light p-3 mx-auto" style="max-width: 500px;">
                            <small class="fw-bold d-block text-uppercase mb-2">Required Columns:</small>
                            <code class="d-block small text-muted">Supplier, Contact, Invoice Number, Track, Pkgs, CBM, Value, Desc, Shipment Date, Shipper, ECC, ETD, ETA, Status</code>
                        </div>
                    </div>
                </form>
                
                <?php elseif ($step == 2): ?>
                <!-- STEP 2: PREVIEW -->
                <form method="POST">
                    <input type="hidden" name="step" value="3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Preview Import Data (<?php echo count($preview_data); ?> rows)</h5>
                        <button type="submit" class="btn btn-success rounded-0"><i class="fas fa-check"></i> Confirm & Import</button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered table-striped" style="font-size: 0.8rem;">
                            <thead class="bg-dark text-white">
                                <tr>
                                    <th>Supplier</th>
                                    <th>Invoice #</th>
                                    <th>Contact</th>
                                    <th>Pkgs</th>
                                    <th>CBM</th>
                                    <th>Desc / Product</th>
                                    <th>Shipper</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach(array_slice($preview_data, 0, 50) as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['supplier']); ?> <span class="badge bg-light text-dark border ms-1">New/Ex</span></td>
                                    <td><?php echo htmlspecialchars($row['invoice_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['contact']); ?></td>
                                    <td><?php echo $row['pkgs']; ?></td>
                                    <td><?php echo $row['cbm']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($row['description']); ?>
                                        <?php if($row['matched_product_name']): ?>
                                            <br><small class="text-success"><i class="fas fa-link"></i> <?php echo $row['matched_product_name']; ?></small>
                                        <?php else: ?>
                                            <br><small class="text-warning"><i class="fas fa-question-circle"></i> Not Linked</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['shipper']); ?></td>
                                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if(count($preview_data) > 50): ?>
                            <div class="alert alert-info py-2 rounded-0">And <?php echo count($preview_data)-50; ?> more rows...</div>
                        <?php endif; ?>
                    </div>
                </form>
                
                <?php elseif ($step == 3): ?>
                <!-- STEP 3: RESULTS -->
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle fa-4x text-success"></i>
                    </div>
                    <h4>Import Completed!</h4>
                    <p class="lead text-muted"><?php echo $success_count; ?> shipments successfully created.</p>
                    
                    <?php if(!empty($errors)): ?>
                        <div class="alert alert-warning text-start mx-auto" style="max-width: 600px;">
                            <h6>Errors occurred:</h6>
                            <ul class="mb-0 small">
                                <?php foreach($errors as $err): ?>
                                    <li><?php echo htmlspecialchars($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <a href="index.php" class="btn btn-primary rounded-0 px-4 mt-3">Go to Shipments</a>
                </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
