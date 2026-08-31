<?php
// modules/finance/categories.php
require_once '../../includes/functions.php';
requireLogin();

// Access Control: Admins or Finance only
if (!isAdmin() && !isFinance()) {
    die("Access Denied");
}

$success = '';
$error = '';

// Handle Create
// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Create
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (empty($name)) {
            $error = "Category name is required.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO expenses_categories (name, account_code, parent_id) VALUES (?, ?, ?)");
                $stmt->execute([$name, $code, $parent_id]);
                $success = "Category created successfully!";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Update
    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (empty($name)) {
            $error = "Category name is required.";
        } else {
             try {
                $stmt = $pdo->prepare("UPDATE expenses_categories SET name = ?, account_code = ?, parent_id = ? WHERE id = ?");
                $stmt->execute([$name, $code, $parent_id, $id]);
                $success = "Category updated successfully!";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
    
    // Delete
    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM expenses_categories WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Category deleted successfully!";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Fetch Categories
$allCats = $pdo->query("SELECT * FROM expenses_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$parents = [];
$children = [];

foreach ($allCats as $c) {
    if (empty($c['parent_id'])) {
        $parents[] = $c;
    } else {
        $children[$c['parent_id']][] = $c;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expense Categories - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

<?php include '../../includes/header_employee.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <h2 class="fw-bold text-dark mb-4">Expense Categories</h2>
        
        <!-- Nav -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link" href="my_expenses.php">To Report</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_reports.php">My Reports</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="approvals.php">To Approve</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="categories.php">Categories</a>
            </li>
        </ul>

        <div class="row">
            <!-- Create Form -->
            <div class="col-md-4">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fw-bold">Add Category</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="create">
                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Taxes" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Parent Category</label>
                                <select name="parent_id" class="form-select">
                                    <option value="">None (Main Category)</option>
                                    <?php foreach ($parents as $p): ?>
                                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select a parent to make this a sub-category</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Account Code (Optional)</label>
                                <input type="text" name="code" class="form-control" placeholder="e.g. 60001">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Add Category</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- List -->
            <div class="col-md-8">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Name</th>
                                    <th>Account Code</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parents as $p): ?>
                                <!-- Parent Row -->
                                <tr class="table-light">
                                    <td class="ps-4 fw-bold text-dark">
                                        <?php if (isset($children[$p['id']])): ?>
                                            <button class="btn btn-sm btn-link text-decoration-none p-0 me-2 text-dark" onclick="toggleSubCategories(<?= $p['id'] ?>, this)">
                                                <i class="fas fa-chevron-right" style="width: 16px;"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="d-inline-block" style="width: 24px;"></span>
                                        <?php endif; ?>
                                        <i class="fas fa-folder me-2 text-secondary"></i><?= htmlspecialchars($p['name']) ?>
                                    </td>
                                    <td>
                                        <?php if ($p['account_code']): ?>
                                            <span class="badge bg-white text-dark border"><?= htmlspecialchars($p['account_code']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-light text-primary" onclick="editCategory(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', '<?= addslashes($p['account_code'] ?? '') ?>', '')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-light text-danger" onclick="deleteCategory(<?= $p['id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                    <!-- Children Rows -->
                                    <?php if (isset($children[$p['id']])): ?>
                                        <?php foreach ($children[$p['id']] as $sub): ?>
                                        <tr class="child-of-<?= $p['id'] ?>" style="display: none;">
                                            <td class="ps-5 text-muted">
                                                <i class="fas fa-level-up-alt fa-rotate-90 me-2 small"></i><?= htmlspecialchars($sub['name']) ?>
                                            </td>
                                            <td>
                                                <?php if ($sub['account_code']): ?>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($sub['account_code']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <button class="btn btn-sm btn-white text-primary" onclick="editCategory(<?= $sub['id'] ?>, '<?= addslashes($sub['name']) ?>', '<?= addslashes($sub['account_code'] ?? '') ?>', '<?= $p['id'] ?>')">
                                                    <i class="fas fa-pen small"></i>
                                                </button>
                                                <button class="btn btn-sm btn-white text-danger" onclick="deleteCategory(<?= $sub['id'] ?>)">
                                                    <i class="fas fa-times small"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <!-- Orphans (if any schema mismatch) -->
                                <!-- (Omitted for brevity as seed ensures structure) -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_id" id="edit_parent" class="form-select">
                            <option value="">None (Main Category)</option>
                            <?php foreach ($parents as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Account Code</label>
                        <input type="text" name="code" id="edit_code" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteForm" method="POST" style="display:none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

    function toggleSubCategories(parentId, btn) {
        const rows = document.querySelectorAll('.child-of-' + parentId);
        const icon = btn.querySelector('i');
        const isHidden = rows[0].style.display === 'none';
        
        rows.forEach(row => {
            row.style.display = isHidden ? '' : 'none';
        });

        if (isHidden) {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-down');
        } else {
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-right');
        }
    }

    function editCategory(id, name, code, parentId) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_code').value = code;
        document.getElementById('edit_parent').value = parentId || '';
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    function deleteCategory(id) {
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
                document.getElementById('delete_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        })
    }

</script>

<?php if ($success): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: '<?= addslashes($success) ?>',
        timer: 1500,
        showConfirmButton: false
    });
</script>
<?php endif; ?>

<?php if ($error): ?>
<script>
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: '<?= addslashes($error) ?>'
    });
</script>
<?php endif; ?>

</body>
</html>
