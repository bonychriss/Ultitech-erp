<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();
// requireRole(['admin', 'procurement']);

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? 'add';
    $name = clean_input($_POST['name']);
    $description = clean_input($_POST['description']);
    
    if ($action == 'add') {
        $status = $_POST['status'] ?? 'active';
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description, status) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $status]);
            flash('success', 'Category added successfully!');
        }
    } elseif ($action == 'edit') {
        $id = $_POST['id'];
        $status = $_POST['status'];
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $description, $status, $id]);
        flash('success', 'Category updated successfully!');
    }
    redirect('categories.php');
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        flash('success', 'Category deleted successfully!');
    } catch (PDOException $e) {
        flash('success', 'Cannot delete category because it is in use.', 'danger');
    }
    redirect('categories.php');
}

$stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll();

$page_title = 'Product Categories';
include '../../includes/header.php';
?>

<main class="main-content">
    <div class="stock-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Product Categories</h2>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal" style="border-radius: 0;">
                <i class="fas fa-plus"></i> Add Category
            </button>
        </div>
        
        <?php flash('success'); ?>
        
        <div class="card border-0 shadow-sm" style="border-radius: 0;">
            <div class="card-body p-0">
                <table class="table table-striped table-hover table-sm datatable mb-0" style="font-size: 0.85rem;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th class="ps-3 py-2">Name</th>
                            <th class="py-2">Description</th>
                            <th class="py-2">Status</th>
                            <th class="text-center py-2 pe-3" width="100">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($categories as $cat): ?>
                        <tr>
                            <td class="ps-3 align-middle"><?php echo htmlspecialchars($cat['name']); ?></td>
                            <td class="align-middle"><?php echo htmlspecialchars($cat['description']); ?></td>
                            <td class="align-middle">
                                <?php if($cat['status'] == 'active'): ?>
                                    <span class="badge bg-success rounded-0">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary rounded-0">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center align-middle">
                                <a href="#" class="text-primary me-2" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#editModal" 
                                        data-id="<?php echo $cat['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($cat['name']); ?>"
                                        data-desc="<?php echo htmlspecialchars($cat['description']); ?>"
                                        data-status="<?php echo htmlspecialchars($cat['status']); ?>"
                                        title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="categories.php?delete=<?php echo $cat['id']; ?>" 
                                   class="text-danger" 
                                   onclick="return confirm('Delete this category?');"
                                   title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content" style="border-radius: 0;">
                <div class="modal-header">
                    <h5 class="modal-title">Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" required style="border-radius: 0;">
                    </div>
                    <div class="mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control" style="border-radius: 0;"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Status</label>
                        <select name="status" class="form-select" style="border-radius: 0;">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 0;">Close</button>
                    <button type="submit" class="btn btn-primary" style="border-radius: 0;">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content" style="border-radius: 0;">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required style="border-radius: 0;">
                    </div>
                    <div class="mb-3">
                        <label>Description</label>
                        <textarea name="description" id="edit_desc" class="form-control" style="border-radius: 0;"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-select" style="border-radius: 0;">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 0;">Close</button>
                    <button type="submit" class="btn btn-primary" style="border-radius: 0;">Update</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    var editModal = document.getElementById('editModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var id = button.getAttribute('data-id');
            var name = button.getAttribute('data-name');
            var desc = button.getAttribute('data-desc');
            var status = button.getAttribute('data-status');
            
            editModal.querySelector('#edit_id').value = id;
            editModal.querySelector('#edit_name').value = name;
            editModal.querySelector('#edit_desc').value = desc;
            editModal.querySelector('#edit_status').value = status;
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
