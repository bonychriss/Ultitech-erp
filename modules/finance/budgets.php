<?php
// modules/finance/budgets.php
require_once '../../includes/functions.php';
requireLogin();

$month_filter = $_GET['month'] ?? date('Y-m');
$user_id = $_SESSION['user_id'];

// Handle Budget Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_budget') {
    $category = $_POST['category'];
    $amount = $_POST['amount'];
    $month = $_POST['month'];

    // Check if budget exists
    $stmt = $pdo->prepare("SELECT id FROM finance_budgets WHERE category = ? AND month = ?");
    $stmt->execute([$category, $month]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE finance_budgets SET amount = ? WHERE id = ?");
        $stmt->execute([$amount, $existing['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO finance_budgets (category, amount, month) VALUES (?, ?, ?)");
        $stmt->execute([$category, $amount, $month]);
    }
    
    header("Location: budgets.php?month=$month&msg=saved");
    exit;
}

// Fetch Budgets & Actuals
// 1. Get all categories (distinct from transactions or a fixed list)
// For simplicity, we'll use a fixed list + any custom ones found in budgets/transactions
$categories = [
    "Salary", "Freelance", "Food & Dining", "Transportation", "Housing", 
    "Entertainment", "Utilities", "Healthcare", "Shopping", "Other"
];

// 2. Fetch set budgets for this month
$stmt = $pdo->prepare("SELECT * FROM finance_budgets WHERE month = ?");
$stmt->execute([$month_filter]);
$budget_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$budgets = [];
foreach ($budget_rows as $row) {
    $budgets[$row['category']] = $row['amount'];
}

// 3. Fetch actual usage
$stmt = $pdo->prepare("SELECT category, SUM(amount) as total FROM finance_transactions WHERE type = 'debit' AND DATE_FORMAT(transaction_date, '%Y-%m') = ? GROUP BY category");
$stmt->execute([$month_filter]);
$param_usage = $stmt->fetchAll(PDO::FETCH_ASSOC); // Renamed variable to strict avoid conflict
$usage = [];
foreach ($param_usage as $row) {
    $usage[$row['category']] = $row['total'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budgets - <?php echo defined('SITE_NAME') ? SITE_NAME : 'ERP System'; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/finance.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="dashboard">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-dark">Budget Management</h2>
            <form method="GET" class="d-flex gap-2">
                <input type="month" name="month" class="form-control" value="<?php echo $month_filter; ?>" onchange="this.form.submit()">
            </form>
        </div>

        <!-- <?php include 'includes/navbar.php'; ?> -->

        <div class="row g-4">
            <?php foreach ($categories as $cat): 
                $budget = $budgets[$cat] ?? 0;
                $spent = $usage[$cat] ?? 0;
                $percent = $budget > 0 ? min(100, ($spent / $budget) * 100) : ($spent > 0 ? 100 : 0);
                $color_class = $percent > 90 ? 'bg-danger' : ($percent > 75 ? 'bg-warning' : 'bg-primary');
                // Allow editing via modal
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="finance-card p-4 h-100 position-relative">
                    <button class="btn btn-sm btn-light position-absolute top-0 end-0 m-3" 
                            data-bs-toggle="modal" 
                            data-bs-target="#editBudgetModal"
                            onclick="setModalData('<?php echo htmlspecialchars($cat); ?>', '<?php echo $budget; ?>')">
                        <i class="fas fa-pencil-alt text-muted"></i>
                    </button>

                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="icon-wrapper rounded-circle p-3 bg-light text-primary">
                            <i class="fas fa-tag"></i>
                        </div>
                        <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($cat); ?></h5>
                    </div>
                    
                    <div class="mb-2 d-flex justify-content-between text-muted small fw-bold">
                        <span>Spent: <?php echo number_format($spent); ?></span>
                        <span>Limit: <?php echo number_format($budget); ?></span>
                    </div>

                    <div class="progress progress-custom mb-3">
                        <div class="progress-bar progress-bar-custom <?php echo $color_class; ?>" style="width: <?php echo $percent; ?>%"></div>
                    </div>

                    <?php if ($budget > 0): ?>
                        <div class="text-end small">
                            <?php if ($spent > $budget): ?>
                                <span class="text-danger fw-bold">Over Budget by <?php echo number_format($spent - $budget); ?></span>
                            <?php else: ?>
                                <span class="text-success fw-bold"><?php echo number_format($budget - $spent); ?> Remaining</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-end small text-muted">No budget set</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<!-- Edit Budget Modal -->
<div class="modal fade" id="editBudgetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Set Budget: <span id="modalCategoryName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="set_budget">
                    <input type="hidden" name="month" value="<?php echo $month_filter; ?>">
                    <input type="hidden" name="category" id="modalCategoryInput">
                    
                    <div class="mb-3">
                        <label class="form-label">Monthly Limit (TZS)</label>
                        <input type="number" name="amount" id="modalAmountInput" class="form-control" placeholder="0.00" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Save Budget</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setModalData(category, amount) {
    document.getElementById('modalCategoryName').textContent = category;
    document.getElementById('modalCategoryInput').value = category;
    document.getElementById('modalAmountInput').value = amount > 0 ? amount : '';
}
</script>
</body>
</html>
