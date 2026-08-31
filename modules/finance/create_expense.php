<?php
// modules/finance/create_expense.php
require_once '../../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$company_id = (int) (currentCompanyId() ?? 0);
$companyFilter = $company_id > 0 ? " AND company_id = " . (int) $company_id : "";
$error = '';
$success = '';

// Fetch Categories and Group
$allCats = $pdo->query("SELECT * FROM expenses_categories WHERE 1=1{$companyFilter} ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$parents = [];
$subs = [];

foreach ($allCats as $c) {
    if (empty($c['parent_id'])) {
        $parents[] = $c;
    } else {
        $subs[$c['parent_id']][] = $c;
    }
}
$subsJson = json_encode($subs);

// Prefill from Voucher (if passed)
$prefill = [
    'description' => '',
    'amount' => '',
    'date' => date('Y-m-d'),
    'voucher_number' => '',
    'voucher_id' => ''
];
if (isset($_GET['voucher_id'])) {
    $vid = (int)$_GET['voucher_id'];
    $vStmt = $pdo->prepare("SELECT * FROM payment_vouchers WHERE id = ? AND company_id = ?");
    $vStmt->execute([$vid, $company_id]);
    $vData = $vStmt->fetch();
    if ($vData) {
        $prefill['description'] = $vData['description'] ?? "Payment for " . ($vData['payee_name'] ?? 'Payee');
        $prefill['amount'] = $vData['total_amount'];
        $prefill['date'] = date('Y-m-d'); // Posting date
        $prefill['voucher_number'] = $vData['voucher_no'];
        $prefill['voucher_id'] = $vid;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?? date('Y-m-d');
    $category_id = (int) $_POST['category_id'];
    $reference = trim($_POST['reference'] ?? '');
    $voucher = trim($_POST['voucher_number'] ?? '');
    
    if (empty($description) || $amount <= 0 || empty($voucher)) {
        $error = "Please provide a description, voucher number, and a valid amount.";
    } else {
        try {
            // Receipt Upload
            $receipt_path = null;
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0) {
                // Simple upload logic
                $ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
                $filename = 'receipt_' . time() . '_' . rand(100,999) . '.' . $ext;
                $target = __DIR__ . '/uploads/' . $filename;
                if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads');
                
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target)) {
                    $receipt_path = 'modules/finance/uploads/' . $filename; // Relative to web root conceptually or need adjustment
                }
            }

            $stmt = $pdo->prepare("INSERT INTO expenses_requests (company_id, user_id, description, date, amount, category_id, reference, voucher_number, receipt_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
            $stmt->execute([$company_id, $user_id, $description, $date, $amount, $category_id, $reference, $voucher, $receipt_path]);
            
            // If linked to a pending voucher, mark it as posted
            if (!empty($_POST['linked_voucher_id'])) {
                $vid = (int)$_POST['linked_voucher_id'];
                $pdo->prepare("UPDATE payment_vouchers SET is_posted = 1 WHERE id = ? AND company_id = ?")->execute([$vid, $company_id]);
            }
            
            header("Location: my_expenses.php?success=created");
            exit;
            
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Expense - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php include '../../includes/header_employee.php'; ?>

<div class="container mt-5" style="max-width: 900px;">
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h4 class="mb-0">New Expense</h4>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="linked_voucher_id" value="<?= htmlspecialchars($prefill['voucher_id']) ?>">
                
                <?php if ($prefill['voucher_id']): ?>
                <div class="alert alert-info py-2">
                    <i class="fas fa-info-circle me-1"></i> Posting Voucher <strong><?= htmlspecialchars($prefill['voucher_number']) ?></strong>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" placeholder="e.g. Client Lunch" value="<?= htmlspecialchars($prefill['description']) ?>" required autofocus>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Category</label>
                        <select id="main_category" class="form-select" required>
                            <option value="">Select Category...</option>
                            <?php foreach ($parents as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Budget Type</label>
                        <select name="category_id" id="sub_category" class="form-select" required disabled>
                            <option value="">Select Category First</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($prefill['date']) ?>" required>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Total Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">TZS</span>
                            <input type="number" name="amount" class="form-control" step="0.01" value="<?= htmlspecialchars($prefill['amount']) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Voucher Number</label>
                        <input type="text" name="voucher_number" class="form-control" value="<?= htmlspecialchars($prefill['voucher_number']) ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Bill Reference</label>
                        <input type="text" name="reference" class="form-control" placeholder="Optional">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Attach Receipt</label>
                    <input type="file" name="receipt" class="form-control" accept="image/*,.pdf">
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="my_expenses.php" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary px-4">Save Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const subs = <?= $subsJson ?>;
    const mainSelect = document.getElementById('main_category');
    const subSelect = document.getElementById('sub_category');

    mainSelect.addEventListener('change', function() {
        const parentId = this.value;
        subSelect.innerHTML = '<option value="">Select Budget Type...</option>';
        subSelect.disabled = true;

        if (parentId && subs[parentId]) {
            subs[parentId].forEach(sub => {
                const opt = document.createElement('option');
                opt.value = sub.id;
                opt.textContent = sub.name;
                subSelect.appendChild(opt);
            });
            subSelect.disabled = false;
        }
    });
</script>
</div> <!-- Close flex-grow-1 -->
</div> <!-- Close layout-main-wrapper -->

</body>
</html>
