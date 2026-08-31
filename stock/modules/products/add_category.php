<?php
// stock/modules/products/add_category.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once __DIR__ . '/category_schema.php';
requireLogin();

$stockCategoryCols = stock_categories_table_columns($pdo);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $item_type = $_POST['item_type'] ?? 'general';
    $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $orderLevel = (int)($_POST['order_level'] ?? 0);
    $level = (int)($_POST['level'] ?? 0);
    $metaTitle = trim($_POST['meta_title'] ?? '');
    $metaDesc = trim($_POST['meta_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    // File Handling - Check gallery selection first
    $bannerName = !empty($_POST['gallery_banner']) ? $_POST['gallery_banner'] : null;
    $iconName = !empty($_POST['gallery_icon']) ? $_POST['gallery_icon'] : null;
    $coverName = !empty($_POST['gallery_cover']) ? $_POST['gallery_cover'] : null;
    
    $uploadDir = __DIR__ . '/../../uploads/categories/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    // Manual Uploads Override Gallery Selection
    if (isset($_FILES['banner']) && $_FILES['banner']['error'] === 0) {
        $bannerName = 'banner_' . time() . '_' . $_FILES['banner']['name'];
        move_uploaded_file($_FILES['banner']['tmp_name'], $uploadDir . $bannerName);
    }
    if (isset($_FILES['icon']) && $_FILES['icon']['error'] === 0) {
        $iconName = 'icon_' . time() . '_' . $_FILES['icon']['name'];
        move_uploaded_file($_FILES['icon']['tmp_name'], $uploadDir . $iconName);
    }
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === 0) {
        $coverName = 'cover_' . time() . '_' . $_FILES['cover_image']['name'];
        move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploadDir . $coverName);
    }

    $fields = [];
    $placeholders = [];
    $values = [];
    $fields[] = 'name';
    $placeholders[] = '?';
    $values[] = $name;
    if (!empty($stockCategoryCols['description'])) {
        $fields[] = 'description';
        $placeholders[] = '?';
        $values[] = $description;
    }
    if (!empty($stockCategoryCols['parent_id'])) {
        $fields[] = 'parent_id';
        $placeholders[] = '?';
        $values[] = $parentId;
    }
    if (!empty($stockCategoryCols['order_level'])) {
        $fields[] = 'order_level';
        $placeholders[] = '?';
        $values[] = $orderLevel;
    }
    if (!empty($stockCategoryCols['level'])) {
        $fields[] = 'level';
        $placeholders[] = '?';
        $values[] = $level;
    }
    if (!empty($stockCategoryCols['item_type'])) {
        $fields[] = 'item_type';
        $placeholders[] = '?';
        $values[] = $item_type;
    }
    if (!empty($stockCategoryCols['banner']) && $bannerName !== null) {
        $fields[] = 'banner';
        $placeholders[] = '?';
        $values[] = $bannerName;
    }
    if (!empty($stockCategoryCols['icon']) && $iconName !== null) {
        $fields[] = 'icon';
        $placeholders[] = '?';
        $values[] = $iconName;
    }
    if (!empty($stockCategoryCols['cover_image']) && $coverName !== null) {
        $fields[] = 'cover_image';
        $placeholders[] = '?';
        $values[] = $coverName;
    }
    if (!empty($stockCategoryCols['meta_title'])) {
        $fields[] = 'meta_title';
        $placeholders[] = '?';
        $values[] = $metaTitle;
    }
    if (!empty($stockCategoryCols['meta_description'])) {
        $fields[] = 'meta_description';
        $placeholders[] = '?';
        $values[] = $metaDesc;
    }
    if (!empty($stockCategoryCols['status'])) {
        $fields[] = 'status';
        $placeholders[] = '?';
        $values[] = $status;
    }

    $sql = 'INSERT INTO categories (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($values);
    
    flash('success', 'Category added successfully!');
    redirect('categories.php');
}

if (!empty($stockCategoryCols['parent_id'])) {
    $parents = $pdo->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $parents = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$showCategoryDescription = !empty($stockCategoryCols['description']);
$showCategoryParent = !empty($stockCategoryCols['parent_id']);
$showCategoryItemType = !empty($stockCategoryCols['item_type']);
$showCategoryOrder = !empty($stockCategoryCols['order_level']);
$showCategoryLevel = !empty($stockCategoryCols['level']);
$showCategoryBanner = !empty($stockCategoryCols['banner']);
$showCategoryIcon = !empty($stockCategoryCols['icon']);
$showCategoryCover = !empty($stockCategoryCols['cover_image']);
$showCategoryMetaTitle = !empty($stockCategoryCols['meta_title']);
$showCategoryMetaDesc = !empty($stockCategoryCols['meta_description']);
$showCategoryStatus = !empty($stockCategoryCols['status']);

$page_title = 'Add Category';
include '../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .main-content-wrapper { margin-left: 280px; padding: 2rem; }
    
    .form-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; max-width: 1000px; margin-left: 0; margin-right: auto; }
    .form-header { padding: 20px 32px; border-bottom: 1px solid #f1f5f9; background: #fff; }
    .form-body { padding: 32px; }
    
    .form-row { display: grid; grid-template-columns: 240px 1fr; align-items: start; margin-bottom: 24px; }
    .form-label { font-size: 14px; font-weight: 500; color: #1e293b; padding-top: 10px; }
    .form-input { width: 100%; padding: 10px 16px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #1e293b; outline: none; transition: all 0.2s; background: #fff; }
    .form-input:focus { border-color: #9333ea; box-shadow: 0 0 0 4px rgba(147, 51, 234, 0.1); }
    
    /* File Input Styling */
    .file-input-wrapper { position: relative; display: flex; align-items: center; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; flex-grow: 1; }
    .file-input-btn { background: #f1f5f9; color: #1e293b; padding: 10px 24px; font-size: 14px; font-weight: 500; border-right: 1px solid #e2e8f0; cursor: pointer; transition: background 0.2s; }
    .file-input-btn:hover { background: #e2e8f0; }
    .file-input-text { padding: 0 16px; color: #94a3b8; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .file-hidden { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    
    .btn-gallery { padding: 10px 20px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-weight: 600; color: #64748b; background: #fff; transition: all 0.2s; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
    .btn-gallery:hover { background: #f8fafc; color: #9333ea; border-color: #9333ea; }

    .help-text { font-size: 11px; color: #94a3b8; margin-top: 6px; }
    
    .btn-save { background: #9333ea; color: white; padding: 12px 40px; border-radius: 10px; font-weight: 600; font-size: 14px; transition: all 0.2s; box-shadow: 0 4px 12px rgba(147, 51, 234, 0.2); }
    .btn-save:hover { background: #7e22ce; transform: translateY(-1px); }

    /* Modal Gallery */
    .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px; max-height: 400px; overflow-y: auto; padding: 10px; }
    .gallery-item { cursor: pointer; border: 2px solid transparent; border-radius: 8px; overflow: hidden; transition: all 0.2s; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; background: #f8fafc; }
    .gallery-item:hover { border-color: #9333ea; transform: scale(1.05); }
    .gallery-item img { width: 100%; height: 100%; object-fit: cover; }

    @media (max-width: 992px) { .main-content-wrapper { margin-left: 0; padding: 1rem; } .form-row { grid-template-columns: 1fr; gap: 8px; } }
</style>

<div class="main-content-wrapper">
    <div class="w-full max-w-[1200px]">
        
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-slate-800">Add New Category</h1>
            <a href="categories.php" class="text-slate-400 hover:text-slate-600 font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to list
            </a>
        </div>

        <form action="add_category.php" method="POST" enctype="multipart/form-data">
            <div class="form-card">
                <div class="form-header">
                    <h2 class="text-lg font-semibold text-slate-800">Category Information</h2>
                </div>
                
                <div class="form-body">
                    <!-- Name -->
                    <div class="form-row">
                        <label class="form-label">Name</label>
                        <div>
                            <input type="text" name="name" placeholder="Name" required class="form-input">
                        </div>
                    </div>

                    <?php if ($showCategoryDescription): ?>
                    <div class="form-row">
                        <label class="form-label">Description</label>
                        <div>
                            <textarea name="description" class="form-input h-28 py-3" placeholder="Short description"></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryItemType): ?>
                    <!-- Type -->
                    <div class="form-row">
                        <label class="form-label">Type</label>
                        <div>
                            <select name="item_type" class="form-input appearance-none pr-10" style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;">
                                <option value="general">Physical</option>
                                <option value="spare_part">Spare Parts</option>
                                <option value="vehicle">Trucks / Fleet</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryParent): ?>
                    <!-- Parent Category -->
                    <div class="form-row">
                        <label class="form-label">Parent Category</label>
                        <div>
                            <select name="parent_id" class="form-input appearance-none pr-10" style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;">
                                <option value="">No Parent</option>
                                <?php foreach ($parents as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryOrder): ?>
                    <!-- Ordering Number -->
                    <div class="form-row">
                        <label class="form-label">Ordering Number</label>
                        <div>
                            <input type="number" name="order_level" placeholder="Order Level" value="0" class="form-input">
                            <p class="help-text">Higher number has high priority</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryLevel): ?>
                    <div class="form-row">
                        <label class="form-label">Level</label>
                        <div>
                            <input type="number" name="level" value="0" class="form-input">
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryBanner): ?>
                    <!-- Banner -->
                    <div class="form-row">
                        <label class="form-label">Banner</label>
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="file-input-wrapper">
                                    <div class="file-input-btn">Browse</div>
                                    <div class="file-input-text" id="text-banner">Choose File</div>
                                    <input type="file" name="banner" class="file-hidden" onchange="updateFileName(this, 'banner')">
                                </div>
                                <input type="hidden" name="gallery_banner" id="gallery-banner">
                                <button type="button" onclick="openGallery('banner')" class="btn-gallery">
                                    <i class="fas fa-images"></i> Select from Files
                                </button>
                            </div>
                            <p class="help-text">Minimum dimensions required: 150px width X 150px height.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryIcon): ?>
                    <!-- Icon -->
                    <div class="form-row">
                        <label class="form-label">Icon</label>
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="file-input-wrapper">
                                    <div class="file-input-btn">Browse</div>
                                    <div class="file-input-text" id="text-icon">Choose File</div>
                                    <input type="file" name="icon" class="file-hidden" onchange="updateFileName(this, 'icon')">
                                </div>
                                <input type="hidden" name="gallery_icon" id="gallery-icon">
                                <button type="button" onclick="openGallery('icon')" class="btn-gallery">
                                    <i class="fas fa-images"></i> Select from Files
                                </button>
                            </div>
                            <p class="help-text">Minimum dimensions required: 16px width X 16px height.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryCover): ?>
                    <!-- Cover Image -->
                    <div class="form-row">
                        <label class="form-label">Cover Image</label>
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="file-input-wrapper">
                                    <div class="file-input-btn">Browse</div>
                                    <div class="file-input-text" id="text-cover">Choose File</div>
                                    <input type="file" name="cover_image" class="file-hidden" onchange="updateFileName(this, 'cover')">
                                </div>
                                <input type="hidden" name="gallery_cover" id="gallery-cover">
                                <button type="button" onclick="openGallery('cover')" class="btn-gallery">
                                    <i class="fas fa-images"></i> Select from Files
                                </button>
                            </div>
                            <p class="help-text">Minimum dimensions required: 250px width X 250px height.</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryMetaTitle): ?>
                    <!-- Meta Title -->
                    <div class="form-row">
                        <label class="form-label">Meta Title</label>
                        <div>
                            <input type="text" name="meta_title" placeholder="Meta Title" class="form-input">
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryMetaDesc): ?>
                    <!-- Meta Description -->
                    <div class="form-row">
                        <label class="form-label">Meta description</label>
                        <div>
                            <textarea name="meta_description" class="form-input h-32 py-3" placeholder="Meta description"></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($showCategoryStatus): ?>
                    <input type="hidden" name="status" value="active">
                    <?php endif; ?>

                    <div class="flex justify-start mt-10">
                        <button type="submit" class="btn-save">Save Category</button>
                    </div>

                </div>
            </div>
        </form>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function updateFileName(input, field) {
    const text = document.getElementById('text-' + field);
    if (input.files && input.files.length > 0) {
        text.textContent = input.files[0].name;
        text.style.color = '#1e293b';
        // Clear gallery selection if manual file is picked
        const galleryInput = document.getElementById('gallery-' + field);
        if (galleryInput) galleryInput.value = '';
    } else {
        text.textContent = 'Choose File';
        text.style.color = '#94a3b8';
    }
}

async function openGallery(field) {
    window.__activeField = field;
    Swal.fire({
        title: 'Select File',
        width: '90%',
        html: `
            <iframe src="../uploads/index.php?mode=select" id="gallery-iframe" style="width: 100%; height: 600px; border: none; border-radius: 8px;"></iframe>
        `,
        showConfirmButton: false,
        customClass: {
            container: 'swal2-wide-modal'
        }
    });
}

window.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'fileSelected') {
        const field = window.__activeField;
        const file = event.data.name; // The filename
        const rel = event.data.rel;   // The relative path
        const folder = event.data.folder;
        
        const hiddenInput = document.getElementById('gallery-' + field);
        const textDisplay = document.getElementById('text-' + field);
        
        // If it's in categories folder, we just need the filename
        // If it's in products, we might need a different handling in PHP.
        // For now, let's just use the filename as before.
        hiddenInput.value = file;
        
        textDisplay.textContent = 'Selected: ' + file;
        textDisplay.style.color = '#9333ea';
        
        // Clear manual file input
        const fileInput = document.querySelector(`input[name="${field === 'cover' ? 'cover_image' : field}"]`);
        if (fileInput) fileInput.value = '';

        Swal.close();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
