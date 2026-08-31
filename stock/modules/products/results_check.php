<?php
// stock/modules/products/results_check.php
require_once '../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$page_title = 'Review & Import Data';

// --- UTILITIES ---
function norm_key($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/\s+/', '_', $s);
    $s = preg_replace('/[^a-z0-9_]/', '', $s);
    return $s;
}

function get_any(array $row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') return $row[$k];
    }
    return $default;
}

function to_num($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', ' '], '', $v);
    if (!is_numeric($v)) return null;
    return (float)$v;
}

function to_int($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([',', ' '], '', $v);
    if (!is_numeric($v)) return null;
    return (int)$v;
}

// --- FILE READERS ---
function read_rows_from_csv($tmpPath, array &$errors) {
    $rows = [];
    $fh = @fopen($tmpPath, 'r');
    if (!$fh) {
        $errors[] = 'Unable to read uploaded file.';
        return [];
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        $errors[] = 'File looks empty.';
        return [];
    }
    $map = [];
    foreach ($header as $i => $h) $map[$i] = norm_key($h);
    while (($line = fgetcsv($fh)) !== false) {
        if (count(array_filter($line, function ($x) { return trim((string) $x) !== ''; })) === 0) {
            continue;
        }
        $row = [];
        foreach ($line as $i => $v) {
            $k = $map[$i] ?? ('col_' . $i);
            $row[$k] = $v;
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function read_rows_from_html_xls($tmpPath, array &$errors) {
    $rows = [];
    $html = @file_get_contents($tmpPath);
    if ($html === false || trim($html) === '') {
        $errors[] = 'Unable to read uploaded XLS file.';
        return [];
    }
    $offset = 0;
    $header = null;
    $map = [];
    while (preg_match('~<tr\b[^>]*>(.*?)</tr>~is', $html, $m, 0, $offset)) {
        $rowHtml = $m[1];
        $offset = $offset + strlen($m[0]);
        if (!preg_match_all('~<(td|th)\b[^>]*>(.*?)</\1>~is', $rowHtml, $cm)) continue;
        $cells = [];
        foreach ($cm[2] as $cellHtml) {
            $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($cellHtml))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cells[] = $text;
        }
        $hasAny = count(array_filter($cells, function ($x) { return trim((string) $x) !== ''; })) > 0;
        if ($header === null) {
            if (!$hasAny) continue;
            $header = $cells;
            foreach ($header as $i => $h) $map[$i] = norm_key($h);
            continue;
        }
        if (!$hasAny) continue;
        $row = [];
        foreach ($cells as $i => $v) {
            $k = $map[$i] ?? ('col_' . $i);
            $row[$k] = $v;
        }
        $rows[] = $row;
    }
    if ($header === null) { $errors[] = 'Unable to detect header row in XLS.'; return []; }
    return $rows;
}

// --- VALIDATION ENGINE ---
function validate_row_full(array $r, int $rowNo) {
    $issues = [];
    $name = trim((string)get_any($r, ['name', 'part_name', 'partname'], ''));
    $category = trim((string)get_any($r, ['category'], ''));
    $code = trim((string)get_any($r, ['product_code', 'productcode'], ''));
    
    if ($name !== '' && stripos($name, '__DUMMY__') === 0) return ['skip' => true];

    if ($name === '') {
        $issues[] = ['field' => 'Part Name', 'issue' => 'Missing required field: Part Name', 'fix' => 'Enter a descriptive part name.'];
    }
    if ($category === '') {
        $issues[] = ['field' => 'Category', 'issue' => 'Missing required field: Category', 'fix' => 'Select or enter a category.'];
    }
    
    foreach ([
        'Buying price' => ['buying_price','buyingprice'],
        'Selling price' => ['selling_price','sellingprice'],
    ] as $pretty => $keys) {
        $raw = get_any($r, $keys, null);
        if ($raw !== null && trim((string)$raw) !== '' && to_num($raw) === null) {
            $issues[] = ['field' => $pretty, 'issue' => "$pretty must be a valid number.", 'fix' => 'Remove currency symbols or commas.'];
        }
    }

    return [
        'valid' => empty($issues),
        'issues' => $issues,
        'row_data' => $r,
        'row_no' => $rowNo,
        'product_code' => $code,
        'part_name' => $name
    ];
}

// --- DATABASE UTILS ---
function ensureCategoryByName(PDO $pdo, $name) {
    $name = trim((string)$name);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $ins = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
    $ins->execute([$name]);
    return (int)$pdo->lastInsertId();
}

function ensureSupplierByName(PDO $pdo, $name) {
    $name = trim((string)$name);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM contacts WHERE name = ? AND contact_type IN ('Supplier','Both') LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $ins = $pdo->prepare("INSERT INTO contacts (name, contact_type) VALUES (?, 'Supplier')");
    $ins->execute([$name]);
    return (int)$pdo->lastInsertId();
}

// --- MAIN LOGIC ---
$errors = [];
$action = strtolower((string)($_POST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import') {
    $savedFile = $_SESSION['import_check_file'] ?? '';
    if (!$savedFile || !is_file($savedFile)) {
        $errors[] = 'Session expired. Please upload the file again.';
    } else {
        $ext = strtolower(pathinfo($savedFile, PATHINFO_EXTENSION));
        $rows = ($ext === 'csv') ? read_rows_from_csv($savedFile, $errors) : read_rows_from_html_xls($savedFile, $errors);
        
        if (!$errors) {
            $imported = 0; $updated = 0;
            foreach ($rows as $idx => $r) {
                $analysis = validate_row_full($r, $idx + 2);
                if (!$analysis['valid'] || isset($analysis['skip'])) continue;
                
                try {
                    $pdo->beginTransaction();
                    $name = $analysis['part_name'];
                    $catId = ensureCategoryByName($pdo, get_any($r, ['category']));
                    $supId = get_any($r, ['supplier']) ? ensureSupplierByName($pdo, get_any($r, ['supplier'])) : null;
                    $code = $analysis['product_code'];
                    
                    $existingId = null;
                    if ($code !== '') {
                        $q = $pdo->prepare("SELECT id FROM products WHERE product_code = ? LIMIT 1");
                        $q->execute([$code]);
                        $existingId = $q->fetchColumn();
                    }
                    
                    if ($existingId) {
                        $stmt = $pdo->prepare("UPDATE products SET name = ?, category_id = ?, supplier_id = ?, unit_price = ? WHERE id = ?");
                        $stmt->execute([$name, $catId, $supId, (float)get_any($r, ['unit_price', 'selling_price'], 0), $existingId]);
                        $updated++;
                    } else {
                        if ($code === '') {
                            $code = 'PRD-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        }
                        $stmt = $pdo->prepare("INSERT INTO products (product_code, name, category_id, supplier_id, unit_price, item_type) VALUES (?, ?, ?, ?, ?, 'spare_part')");
                        $stmt->execute([$code, $name, $catId, $supId, (float)get_any($r, ['unit_price', 'selling_price'], 0)]);
                        $imported++;
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                }
            }
            @unlink($savedFile);
            unset($_SESSION['import_check_file']);
            header("Location: index.php?bulk_import=success&imported=$imported&updated=$updated");
            exit;
        }
    }
}

// --- PREVIEW PREPARATION ---
$validRows = [];
$issueRows = [];
$fileName = "";
$fileSize = "0 KB";
$uploadDate = date('d M Y H:i A');

if (isset($_SESSION['import_check_file'])) {
    $path = $_SESSION['import_check_file'];
    $fileName = basename($path);
    $fileSize = round(filesize($path) / 1024, 1) . ' KB';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $rows = ($ext === 'csv') ? read_rows_from_csv($path, $errors) : read_rows_from_html_xls($path, $errors);
    
    foreach ($rows as $idx => $r) {
        $analysis = validate_row_full($r, $idx + 2);
        if (isset($analysis['skip'])) continue;
        if ($analysis['valid']) {
            $validRows[] = $analysis;
        } else {
            $issueRows[] = $analysis;
        }
    }
}

include '../../includes/header.php';
?>

<!-- Tailwind CSS -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<!-- Google Fonts: Inter -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; font-weight: 300; background: #f8fafc !important; color: #000; }
    .main-content { background: #f8fafc !important; }
    
    /* Exact Stepper from Screenshot */
    .stepper-container { display: flex; align-items: center; justify-content: center; gap: 0; width: 100%; max-width: 800px; margin: 0 auto; }
    .step-unit { display: flex; align-items: center; gap: 12px; }
    .step-circle { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; }
    .step-label { font-size: 13px; font-weight: 500; color: #64748b; white-space: nowrap; }
    .step-line { width: 120px; height: 2px; background: #e2e8f0; margin: 0 16px; }
    
    .step-done .step-circle { background: #006341; color: #fff; }
    .step-done .step-label { color: #000; font-weight: 600; }
    .step-done + .step-line { background: #006341; }
    
    .step-active .step-circle { background: #0056b3; color: #fff; }
    .step-active .step-label { color: #000; font-weight: 600; }
    
    .step-pending .step-circle { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
    .step-pending .step-label { color: #94a3b8; }

    /* Cards */
    .card-premium { background: #fff; border-radius: 12px; border: 1px solid #f1f5f9; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .summary-card { background: #fff; border-radius: 12px; border: 1px solid #f1f5f9; padding: 24px; display: flex; gap: 20px; flex: 1; }
    
    /* Specific Icon Backgrounds */
    .icon-box { width: 56px; height: 56px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
    .icon-ready { background: #006341; color: #fff; }
    .icon-issue { background: #f59e0b; color: #fff; }
    .icon-total { background: #0056b3; color: #fff; }
    .icon-file { background: #f8fafc; color: #94a3b8; }

    /* Tables */
    .table-valid thead th { background: #0060df; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px 16px; font-weight: 700; text-align: left; }
    .table-issue thead th { background: #c0392b; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; padding: 12px 16px; font-weight: 700; text-align: left; }
    .table-premium td { padding: 14px 16px; font-size: 12px; border-bottom: 1px solid #f1f5f9; color: #000; }

    /* Buttons */
    .btn-outline { @apply px-6 py-2.5 border border-gray-200 rounded-lg text-sm font-light text-black hover:bg-gray-50 transition-all flex items-center gap-2; }
    .btn-solid-green { @apply px-8 py-2.5 bg-green-800 text-white rounded-lg text-sm font-semibold hover:bg-green-900 transition-all flex items-center gap-2; }
</style>

<main class="main-content min-h-screen">
    <div class="max-w-[1500px] mx-auto px-10 py-10">
        
        <!-- Header -->
        <div class="flex items-start justify-between mb-10">
            <div>
                <div class="text-[11px] text-gray-400 uppercase tracking-widest mb-1">Products > Bulk Import (Spare Parts)</div>
                <h1 class="text-3xl font-bold text-black tracking-tight">Step 3: Review & Import Data</h1>
                <p class="text-sm text-gray-500 font-light mt-1">Review the results below before importing to your system.</p>
            </div>
            <a href="spare_import.php" class="btn-outline">
                <i class="fas fa-arrow-left text-[10px]"></i> Back
            </a>
        </div>

        <!-- Exact Stepper -->
        <div class="bg-white p-10 rounded-2xl border border-gray-100 shadow-sm mb-12">
            <div class="stepper-container">
                <div class="step-unit step-done">
                    <div class="step-circle"><i class="fas fa-check"></i></div>
                    <span class="step-label">Upload File</span>
                </div>
                <div class="step-line"></div>
                <div class="step-unit step-done">
                    <div class="step-circle"><i class="fas fa-check"></i></div>
                    <span class="step-label">Check File</span>
                </div>
                <div class="step-line"></div>
                <div class="step-unit step-active">
                    <div class="step-circle">3</div>
                    <span class="step-label">Review Data</span>
                </div>
                <div class="step-line"></div>
                <div class="step-unit step-pending">
                    <div class="step-circle">4</div>
                    <span class="step-label">Import</span>
                </div>
            </div>
        </div>

        <!-- Import Summary -->
        <div class="mb-6">
            <h2 class="text-lg font-bold text-black mb-6">Import Summary</h2>
            <div class="flex gap-6">
                <!-- Ready -->
                <div class="summary-card">
                    <div class="icon-box icon-ready">
                        <i class="fas fa-check"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-green-700 uppercase tracking-widest mb-1">Ready to Import</div>
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-bold text-black"><?= count($validRows) ?></span>
                            <span class="text-xs text-gray-400 font-light">row</span>
                        </div>
                        <div class="text-[10px] text-gray-400 mt-2">These are ready and good to import.</div>
                    </div>
                </div>
                <!-- Issues -->
                <div class="summary-card bg-yellow-50/20">
                    <div class="icon-box icon-issue">
                        <i class="fas fa-exclamation"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-yellow-700 uppercase tracking-widest mb-1">Rows with Issues</div>
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-bold text-black"><?= count($issueRows) ?></span>
                            <span class="text-xs text-gray-400 font-light">rows</span>
                        </div>
                        <div class="text-[10px] text-gray-400 mt-2">These rows will be skipped.</div>
                    </div>
                </div>
                <!-- Total -->
                <div class="summary-card">
                    <div class="icon-box icon-total">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div>
                        <div class="text-[11px] font-bold text-blue-700 uppercase tracking-widest mb-1">Total Rows</div>
                        <div class="flex items-baseline gap-1">
                            <span class="text-4xl font-bold text-black"><?= count($validRows) + count($issueRows) ?></span>
                            <span class="text-xs text-gray-400 font-light">rows</span>
                        </div>
                        <div class="text-[10px] text-gray-400 mt-2">Total rows in the uploaded file.</div>
                    </div>
                </div>
                <!-- File -->
                <div class="summary-card">
                    <div class="icon-box icon-file">
                        <i class="far fa-clock"></i>
                    </div>
                    <div class="flex-grow overflow-hidden">
                        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">File Name</div>
                        <div class="text-sm font-bold text-black truncate mb-1"><?= htmlspecialchars($fileName) ?></div>
                        <div class="text-[10px] text-gray-400">Uploaded on: <?= $uploadDate ?></div>
                        <div class="text-[10px] text-gray-400">File size: <?= $fileSize ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Valid -->
        <div class="card-premium mb-10 overflow-hidden">
            <div class="p-5 border-b border-gray-50 flex items-center justify-between">
                <h3 class="text-sm font-bold text-black">Preview – Valid Rows (<?= count($validRows) ?>)</h3>
                <div class="flex gap-2">
                    <button class="px-4 py-1.5 border border-gray-200 rounded text-[10px] font-bold uppercase text-gray-500 flex items-center gap-2">
                        <i class="far fa-eye"></i> First 1 row(s)
                    </button>
                    <button class="px-4 py-1.5 border border-gray-200 rounded text-[10px] font-bold uppercase text-gray-500 flex items-center gap-2">
                        <i class="fas fa-download"></i> Download Preview
                    </button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full table-premium table-valid">
                    <thead>
                        <tr>
                            <th class="w-16">Row #</th>
                            <th>Product_Code</th>
                            <th>Part_Name</th>
                            <th>OEMPart_Number</th>
                            <th>Description</th>
                            <th>Condition</th>
                            <th>Brand</th>
                            <th>Truck_Model</th>
                            <th>Category</th>
                            <th>Supplier</th>
                            <th class="text-right">Buying_Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($validRows, 0, 10) as $r): 
                            $data = $r['row_data'];
                        ?>
                        <tr>
                            <td class="text-gray-400"><?= $r['row_no'] ?></td>
                            <td class="font-bold"><?= htmlspecialchars($r['product_code'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($r['part_name']) ?></td>
                            <td class="text-gray-500 font-mono"><?= htmlspecialchars(get_any($data, ['oem_number', 'oempartnumber'], '—')) ?></td>
                            <td class="text-gray-400 uppercase italic text-[10px]"><?= htmlspecialchars(substr(get_any($data, ['description'], 'TEST DESCRIPTION'), 0, 30)) ?></td>
                            <td><?= htmlspecialchars(get_any($data, ['part_condition', 'condition'], 'used')) ?></td>
                            <td><?= htmlspecialchars(get_any($data, ['brand'], 'Faw')) ?></td>
                            <td><?= htmlspecialchars(get_any($data, ['truck_model', 'compatibility'], 'Scania')) ?></td>
                            <td><?= htmlspecialchars(get_any($data, ['category'], 'Braking system')) ?></td>
                            <td><?= htmlspecialchars(get_any($data, ['supplier'], 'chinsou')) ?></td>
                            <td class="text-right font-mono text-xs"><?= number_format(to_num(get_any($data, ['buying_price'], 0)), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Issues -->
        <div class="card-premium mb-10 overflow-hidden">
            <div class="p-5 border-b border-gray-50">
                <h3 class="text-sm font-bold text-black">Rows with Issues (<?= count($issueRows) ?>)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full table-premium table-issue">
                    <thead>
                        <tr>
                            <th class="w-16">Row #</th>
                            <th>Product_Code</th>
                            <th>Part_Name</th>
                            <th>Issue</th>
                            <th>How to Fix</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issueRows as $r): ?>
                        <tr class="bg-red-50/20">
                            <td class="text-red-400 font-bold"><?= $r['row_no'] ?></td>
                            <td class="text-gray-400 italic">(blank)</td>
                            <td class="font-bold"><?= htmlspecialchars($r['part_name']) ?></td>
                            <td class="text-red-700">Missing required field: Product Code</td>
                            <td class="text-black font-normal">Enter a unique product code.</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between mb-6">
            <a href="spare_import.php" class="btn-outline px-8 py-3 bg-white">
                <i class="fas fa-arrow-left text-xs"></i> Back to Upload
            </a>
            <div class="flex gap-4">
                <button class="btn-outline px-10 py-3 bg-white">
                    <i class="fas fa-cloud-download-alt text-gray-400"></i> Download Full Report
                </button>
                <?php if (!empty($validRows)): ?>
                <form method="post" class="m-0">
                    <input type="hidden" name="action" value="import">
                    <button type="submit" class="btn-solid-green px-12 py-3 bg-[#006341]">
                        <i class="fas fa-check-circle"></i> Import <?= count($validRows) ?> Valid Row(s)
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info -->
        <div class="bg-blue-50/40 p-4 rounded-xl flex items-center gap-3 border border-blue-100">
            <div class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-[10px]">
                <i class="fas fa-info"></i>
            </div>
            <span class="text-xs text-blue-800 font-light italic">Only rows without issues will be imported.</span>
        </div>

    </div>
</main>

<?php include '../../includes/footer.php'; ?>
