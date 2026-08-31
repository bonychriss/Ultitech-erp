<?php
require_once '../includes/functions.php';
requireLogin();

// Increase execution time for large files
set_time_limit(300);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bulk-upload-vouchers.php');
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    die("Error: No file uploaded or there was an error during upload.");
}

$file = $_FILES['csv_file']['tmp_name'];
$handle = fopen($file, 'r');

// 1. Define Valid Options
$validPaymentTypes = ['Bank Transfer', 'Cash Payment', 'Cheque', 'Mobile Payment'];
$validBudgetTypes = [
    'Operational Expenses', 'Procurement & Supplies', 'Employee Costs', 
    'Sales & Marketing', 'Logistics & Delivery', 'Administration & Management',
    'Projects & Capital Expenditure (CAPEX)', 'Financial Obligations', 
    'Tax & Compliance', 'Others / Miscellaneous'
];
$validCurrencies = ['TZS', 'USD'];

// 2. Load Active Users Data
$userData = [];
try {
    $uStmt = $pdo->query("SELECT id, full_name, department, role FROM users WHERE is_active = 1");
    while ($u = $uStmt->fetch()) {
        $nameLower = strtolower(trim($u['full_name']));
        $userData[$nameLower] = [
            'id' => $u['id'],
            'name' => $u['full_name'],
            'dept' => strtolower(trim($u['department'])),
            'role' => $u['role']
        ];
    }
} catch (Exception $e) {}

// Utility to check if user can "Check" (Finance or Admin)
function canUserCheck($user) {
    if (!$user) return false;
    return ($user['dept'] === 'finance' || $user['role'] === ROLE_ADMIN);
}

// 3. Check Schema
$hasCheckedBy = false;
try { $pdo->query("SELECT checked_by FROM payment_vouchers LIMIT 1"); $hasCheckedBy = true; } catch (Exception $e) { }
$hasIsBulk = false;
try { $pdo->query("SELECT is_bulk FROM payment_vouchers LIMIT 1"); $hasIsBulk = true; } catch (Exception $e) { }
$hasCompanyId = false;
try { $pdo->query("SELECT company_id FROM payment_vouchers LIMIT 1"); $hasCompanyId = true; } catch (Exception $e) { }
$hasBulkAuth = false;
try { $pdo->query("SELECT is_bulk_authorized FROM approvals LIMIT 1"); $hasBulkAuth = true; } catch (Exception $e) { }

// 4. Validate Header
$header = fgetcsv($handle);
$expectedHeader = ['Payee Name', 'Voucher Description', 'Currency', 'Date', 'Total Amount', 'Item Name', 'Payment Type', 'Budget Type', 'Applicant Name', 'Department Manager Name', 'Finance Checked By Name'];

$headerError = null;
if (!$header || count($header) < count($expectedHeader)) {
    $headerError = "The uploaded CSV does not match the required template format.";
} else {
    foreach ($expectedHeader as $i => $expected) {
        if (trim(strtolower($header[$i])) !== trim(strtolower($expected))) {
            $headerError = "Column mismatch at position " . ($i + 1) . ". Expected '" . $expected . "' but found '" . ($header[$i] ?: 'empty') . "'.";
            break;
        }
    }
}

if ($headerError) {
    echo_error_page("Invalid Template", $headerError, 'bulk-upload-vouchers.php');
    fclose($handle);
    exit;
}

// 5. Pass 1: Comprehensive Validation
$rows = [];
$rowErrors = [];
$rowCount = 0; $totalCount = 0;

while (($csvRow = fgetcsv($handle)) !== FALSE) {
    $totalCount++;
    if (count($csvRow) < 5) continue; // Skip empty rows

    $errors = [];
    $data = [
        'payee' => trim($csvRow[0]),
        'desc' => trim($csvRow[1]),
        'currency' => strtoupper(trim($csvRow[2])),
        'date_raw' => trim($csvRow[3]),
        'amount_raw' => trim($csvRow[4]),
        'item' => trim($csvRow[5]),
        'pay_type' => trim($csvRow[6]),
        'bud_type' => trim($csvRow[7]),
        'applicant' => trim($csvRow[8]),
        'manager' => trim($csvRow[9]),
        'checker' => trim($csvRow[10])
    ];

    // Field Validations
    if (empty($data['payee'])) $errors[] = "Payee Name is required.";
    if (empty($data['item'])) $errors[] = "Item Name is required.";
    
    // Amount Validation
    $cleanAmount = str_replace(',', '', $data['amount_raw']);
    if (!is_numeric($cleanAmount) || floatval($cleanAmount) <= 0) {
        $errors[] = "Invalid Amount: '" . $data['amount_raw'] . "'. Must be a positive number.";
    } else {
        $data['amount'] = floatval($cleanAmount);
    }

    // Currency Validation
    if (!in_array($data['currency'], $validCurrencies)) {
        $errors[] = "Invalid Currency: '" . $data['currency'] . "'. Use TZS or USD.";
    }

    // Date Validation
    $timestamp = strtotime($data['date_raw']);
    if (!$timestamp) {
        $errors[] = "Invalid Date: '" . $data['date_raw'] . "'. Use YYYY-MM-DD.";
    } else {
        $data['date_formatted'] = date('Y-m-d', $timestamp);
    }

    // Types Validation
    if (!in_array($data['pay_type'], $validPaymentTypes)) {
        $errors[] = "Invalid Payment Type: '" . $data['pay_type'] . "'.";
    }
    if (!in_array($data['bud_type'], $validBudgetTypes)) {
        $errors[] = "Invalid Budget Type: '" . $data['bud_type'] . "'.";
    }

    // User Existence Checks
    $appUser = $userData[strtolower($data['applicant'])] ?? null;
    $mgrUser = $userData[strtolower($data['manager'])] ?? null;
    $chkUser = $userData[strtolower($data['checker'])] ?? null;

    if (!$appUser) $errors[] = "Applicant '" . $data['applicant'] . "' not found in active users.";
    if (!$mgrUser) $errors[] = "Dept Manager '" . $data['manager'] . "' not found in active users.";
    if (!$chkUser) {
        $errors[] = "Finance Checker '" . $data['checker'] . "' not found in active users.";
    } else if (!canUserCheck($chkUser)) {
        $errors[] = "User '" . $data['checker'] . "' is not authorized for 'Checked By' role (Must be Finance or Admin).";
    }

    if (!empty($errors)) {
        $rowErrors[] = "Row " . ($totalCount + 1) . ": " . implode(" ", $errors);
    } else {
        $data['app_id'] = $appUser['id'];
        $data['mgr_id'] = $mgrUser['id'];
        $data['chk_id'] = $chkUser['id'];
        $rows[] = $data;
    }
}
fclose($handle);

// 6. Report All Validation Errors
if (!empty($rowErrors)) {
    echo_error_page("Data Validation Failed", "Found " . count($rowErrors) . " error(s) in your CSV file. Please fix them and try again.", 'bulk-upload-vouchers.php', $rowErrors);
    exit;
}

if (empty($rows)) {
    echo_error_page("No Data Found", "Wait, we didn't find any valid voucher data to import.", 'bulk-upload-vouchers.php');
    exit;
}

// 7. Pass 2: Transactional Insertion
$successCount = 0;
try {
    $pdo->beginTransaction();

    foreach ($rows as $r) {
        $voucher_no = generateVoucherNumber();

        // Insert Voucher
        $vCols = "voucher_no, payee_name, description, currency, total_amount, applicant, department_manager, prepared_by, created_by, date_created, status, supporting_documents, is_restricted";
        $vPlaces = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirming', 0, 0";
        $vValues = [$voucher_no, $r['payee'], $r['desc'], $r['currency'], $r['amount'], $r['applicant'], $r['manager'], $_SESSION['full_name'], $_SESSION['user_id'], $r['date_formatted']];

        if ($hasCheckedBy) { $vCols .= ", checked_by"; $vPlaces .= ", ?"; $vValues[] = $r['checker']; }
        if ($hasIsBulk) { $vCols .= ", is_bulk"; $vPlaces .= ", 1"; }
        if ($hasCompanyId) { $vCols .= ", company_id"; $vPlaces .= ", ?"; $vValues[] = (int) currentCompanyId(); }

        $vStmt = $pdo->prepare("INSERT INTO payment_vouchers ($vCols) VALUES ($vPlaces)");
        $vStmt->execute($vValues);
        $voucher_id = $pdo->lastInsertId();

        // Insert Item
        $iStmt = $pdo->prepare("INSERT INTO voucher_items (voucher_id, payment_type, budget_type, name, amount, description) VALUES (?, ?, ?, ?, ?, ?)");
        $iStmt->execute([$voucher_id, $r['pay_type'], $r['bud_type'], $r['payee'], $r['amount'], $r['desc']]);

        // Insert Approvals
        $aCols = "voucher_id, approver_id, approver_name, role, status, created_at";
        $aPlaces = "?, ?, ?, ?, ?, NOW()";
        if ($hasBulkAuth) { $aCols .= ", is_bulk_authorized"; $aPlaces .= ", ?"; }
        $aStmt = $pdo->prepare("INSERT INTO approvals ($aCols) VALUES ($aPlaces)");

        $roles = [
            ['role' => 'Applicant', 'name' => $r['applicant'], 'id' => $r['app_id'], 'auto' => true],
            ['role' => 'Department Manager', 'name' => $r['manager'], 'id' => $r['mgr_id'], 'auto' => true],
            ['role' => 'Checked By', 'name' => $r['checker'], 'id' => $r['chk_id'], 'auto' => true],
            ['role' => 'General Manager', 'name' => '', 'id' => null, 'auto' => false]
        ];

        foreach ($roles as $rl) {
            if ($rl['name'] === '' && $rl['role'] !== 'General Manager') continue;
            // Force status to 'pending' to ensure formal approval is required for all roles
            $status = 'pending';
            $params = [$voucher_id, $rl['id'], $rl['name'], $rl['role'], $status];
            if ($hasBulkAuth) { $params[] = 0; }
            $aStmt->execute($params);
        }
        $successCount++;
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo_error_page("Database Error", "Import failed due to a system error: " . $e->getMessage(), 'bulk-upload-vouchers.php');
    exit;
}

// 8. Success Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Success</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="dashboard">
    <script>
        Swal.fire({
            title: 'Upload Successful!',
            html: '<b><?= $successCount ?></b> vouchers have been imported with 100% accuracy validation.',
            icon: 'success',
            timer: 4500,
            timerProgressBar: true,
            confirmButtonColor: '#1e40af',
            confirmButtonText: 'View Vouchers'
        }).then(() => {
            window.location.href = 'my-vouchers.php?module=voucher';
        });
    </script>
</body>
</html>

<?php
/**
 * Utility to show errors in a consistent SweetAlert UI
 */
function echo_error_page($title, $text, $backUrl, $details = []) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= $title ?></title>
        <link rel="stylesheet" href="../assets/css/style.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>
    <body class="dashboard">
        <script>
            Swal.fire({
                title: '<?= addslashes($title) ?>',
                html: '<?= addslashes($text) ?><br><br>' + 
                      <?php if (!empty($details)): ?>
                      '<div style="text-align: left; background: #fee2e2; padding: 12px; border-radius: 8px; font-size: 0.8em; max-height: 250px; overflow-y: auto;">' +
                      '<b>Found these issues:</b><br><?= implode("<br>", array_map("addslashes", array_slice($details, 0, 50))) ?>' +
                      '</div>'
                      <?php else: ?> '' <?php endif; ?>,
                icon: 'error',
                confirmButtonColor: '#1e40af',
                confirmButtonText: 'Back to Upload'
            }).then(() => {
                window.location.href = '<?= $backUrl ?>';
            });
        </script>
    </body>
    </html>
    <?php
}
?>
