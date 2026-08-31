<?php
// modules/finance/transactions.php
require_once '../../includes/functions.php';
requireLogin();

$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Query Construction
$query = "SELECT * FROM finance_transactions WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (description LIKE ? OR category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($type_filter) {
    // Map filter to DB values
    $db_type = ($type_filter == 'income') ? 'credit' : (($type_filter == 'expense') ? 'debit' : '');
    if ($db_type) {
        $query .= " AND type = ?";
        $params[] = $db_type;
    }
}

// Count Total
$count_query = str_replace("SELECT *", "SELECT COUNT(*) as total", $query);
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Fetch Data
$query .= " ORDER BY transaction_date DESC, id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle Form Submission (Add/Edit/Delete Transaction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ADD
    if ($_POST['action'] === 'add_transaction') {
        $type_input = $_POST['type']; 
        $type = ($type_input == 'income') ? 'credit' : 'debit';
        $amount = $_POST['amount'];
        $category = $_POST['category'];
        $date = $_POST['date'];
        $description = $_POST['description'];

        $stmt = $pdo->prepare("INSERT INTO finance_transactions (type, amount, category, transaction_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$type, $amount, $category, $date, $description, $_SESSION['user_id']])) {
            header("Location: transactions.php?msg=added");
            exit;
        }
    }

    // EDIT
    if ($_POST['action'] === 'edit_transaction') {
        $id = $_POST['id'];
        $type_input = $_POST['type']; 
        $type = ($type_input == 'income') ? 'credit' : 'debit';
        $amount = $_POST['amount'];
        $category = $_POST['category'];
        $date = $_POST['date'];
        $description = $_POST['description'];

        // Security check: ensure user owns transaction or is admin (optional, assuming open for now based on context)
        $stmt = $pdo->prepare("UPDATE finance_transactions SET type=?, amount=?, category=?, transaction_date=?, description=? WHERE id=?");
        if ($stmt->execute([$type, $amount, $category, $date, $description, $id])) {
            header("Location: transactions.php?msg=updated");
            exit;
        }
    }

    // DELETE
    if ($_POST['action'] === 'delete_transaction') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM finance_transactions WHERE id=?");
        if ($stmt->execute([$id])) {
            header("Location: transactions.php?msg=deleted");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - <?php echo defined('SITE_NAME') ? SITE_NAME : 'ERP System'; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/finance.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert for nice confirms -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="dashboard">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-dark">Transactions</h2>
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                <i class="fas fa-plus me-2"></i> Add Transaction
            </button>
        </div>

        <!-- <?php include 'includes/navbar.php'; ?> -->

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                    if ($_GET['msg'] == 'added') echo "Transaction added successfully!";
                    if ($_GET['msg'] == 'updated') echo "Transaction updated successfully!";
                    if ($_GET['msg'] == 'deleted') echo "Transaction deleted successfully!";
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="finance-card p-3 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search description or category..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="income" <?php echo $type_filter == 'income' ? 'selected' : ''; ?>>Income</option>
                        <option value="expense" <?php echo $type_filter == 'expense' ? 'selected' : ''; ?>>Expense</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-secondary w-100">Filter</button>
                </div>
                <div class="col-md-3 text-end">
                     <a href="api/export.php?search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>" class="btn btn-outline-success">
                        <i class="fas fa-file-csv me-2"></i> Export CSV
                    </a>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="finance-card overflow-hidden">
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">No transactions found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($t['transaction_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($t['description']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($t['category']); ?></span></td>
                                    <td>
                                        <?php if ($t['type'] == 'credit'): ?>
                                            <span class="badge bg-soft-success text-success">Income</span>
                                        <?php else: ?>
                                            <span class="badge bg-soft-danger text-danger">Expense</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?php echo number_format($t['amount']); ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light text-muted" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                                <li>
                                                    <a class="dropdown-item" href="#" 
                                                       onclick="openEditModal(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                                        <i class="fas fa-edit me-2 text-primary"></i> Edit
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" 
                                                       onclick="confirmDelete(<?php echo $t['id']; ?>)">
                                                        <i class="fas fa-trash-alt me-2"></i> Delete
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="p-3 border-top">
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">New Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form action="transactions.php" method="POST">
                    <input type="hidden" name="action" value="add_transaction">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Type</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="type" id="typeInputIncome" value="income" autocomplete="off">
                            <label class="btn btn-outline-success w-100" for="typeInputIncome">Income</label>

                            <input type="radio" class="btn-check" name="type" id="typeInputExpense" value="expense" autocomplete="off" checked>
                            <label class="btn btn-outline-danger w-100" for="typeInputExpense">Expense</label>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                         <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Amount</label>
                            <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Category</label>
                        <select name="category" class="form-select" required>
                            <option value="" disabled selected>Select Category</option>
                            <option value="Salary">Salary</option>
                            <option value="Freelance">Freelance</option>
                            <option value="Food & Dining">Food & Dining</option>
                            <option value="Transportation">Transportation</option>
                            <option value="Housing">Housing</option>
                            <option value="Entertainment">Entertainment</option>
                            <option value="Utilities">Utilities</option>
                            <option value="Healthcare">Healthcare</option>
                            <option value="Shopping">Shopping</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="What is this for?"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Save Transaction</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Transaction Modal -->
<div class="modal fade" id="editTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">Edit Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form action="transactions.php" method="POST" id="editForm">
                    <input type="hidden" name="action" value="edit_transaction">
                    <input type="hidden" name="id" id="editId">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Type</label>
                        <div class="d-flex gap-2">
                            <input type="radio" class="btn-check" name="type" id="editTypeIncome" value="income" autocomplete="off">
                            <label class="btn btn-outline-success w-100" for="editTypeIncome">Income</label>

                            <input type="radio" class="btn-check" name="type" id="editTypeExpense" value="expense" autocomplete="off">
                            <label class="btn btn-outline-danger w-100" for="editTypeExpense">Expense</label>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                         <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Date</label>
                            <input type="date" name="date" id="editDate" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted text-uppercase">Amount</label>
                            <input type="number" name="amount" id="editAmount" class="form-control" step="0.01" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted text-uppercase">Category</label>
                        <select name="category" id="editCategory" class="form-select" required>
                            <option value="Salary">Salary</option>
                            <option value="Freelance">Freelance</option>
                            <option value="Food & Dining">Food & Dining</option>
                            <option value="Transportation">Transportation</option>
                            <option value="Housing">Housing</option>
                            <option value="Entertainment">Entertainment</option>
                            <option value="Utilities">Utilities</option>
                            <option value="Healthcare">Healthcare</option>
                            <option value="Shopping">Shopping</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase">Description</label>
                        <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Update Transaction</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form (Hidden) -->
<form id="deleteForm" method="POST" action="transactions.php">
    <input type="hidden" name="action" value="delete_transaction">
    <input type="hidden" name="id" id="deleteId">
</form>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openEditModal(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editDate').value = data.transaction_date;
    document.getElementById('editAmount').value = data.amount;
    document.getElementById('editCategory').value = data.category;
    document.getElementById('editDescription').value = data.description;

    if (data.type === 'credit') {
        document.getElementById('editTypeIncome').checked = true;
    } else {
        document.getElementById('editTypeExpense').checked = true;
    }

    var myModal = new bootstrap.Modal(document.getElementById('editTransactionModal'));
    myModal.show();
}

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    })
}
</script>
</body>
</html>
