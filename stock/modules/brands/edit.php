<?php
// stock/modules/brands/edit.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM brands WHERE id = ?");
$stmt->execute([$id]);
$brand = $stmt->fetch();

if (!$brand) { header("Location: index.php"); exit; }

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_brand'])) {
    $name = trim($_POST['name']);
    $brand_type = $_POST['brand_type'] ?? 'spare_part';
    $meta_title = trim($_POST['meta_title']);
    $meta_description = trim($_POST['meta_description']);
    $logo = $brand['logo'];

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../uploads/brands/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        // Delete old logo
        if ($logo && file_exists($uploadDir . $logo)) {
            unlink($uploadDir . $logo);
        }

        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $logo = 'brand_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logo);
    }

    try {
        $stmt = $pdo->prepare("UPDATE brands SET name = ?, brand_type = ?, logo = ?, meta_title = ?, meta_description = ? WHERE id = ?");
        $stmt->execute([$name, $brand_type, $logo, $meta_title, $meta_description, $id]);
        header("Location: index.php?update=success");
        exit;
    } catch (PDOException $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<!-- Tailwind CSS -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; }
    .form-input { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; transition: all 0.2s; background: #fff; color: #000; }
    .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
</style>

<div class="main-content-wrapper p-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-slate-800">Edit Brand: <?= htmlspecialchars($brand['name']) ?></h1>
            <a href="index.php" class="text-slate-400 hover:text-slate-600 font-medium flex items-center gap-2">
                <i class="fas fa-arrow-left text-xs"></i> Back to Brands
            </a>
        </div>

        <?php if($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl mb-6 text-sm font-medium">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form action="edit.php?id=<?= $id ?>" method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-8 space-y-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Brand Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($brand['name']) ?>" required class="form-input">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Brand Category</label>
                    <select name="brand_type" class="form-input appearance-none pr-10" style="background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;">
                        <option value="spare_part" <?= $brand['brand_type'] === 'spare_part' ? 'selected' : '' ?>>Spare Part</option>
                        <option value="truck" <?= $brand['brand_type'] === 'truck' ? 'selected' : '' ?>>Truck</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Logo</label>
                    <div id="drop-zone" class="relative border-2 border-dashed border-slate-200 rounded-xl p-8 transition-all hover:border-blue-400 hover:bg-blue-50 group cursor-pointer text-center">
                        <input type="file" name="logo" id="logo-input" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="handleFileSelect(this)">
                        
                        <div id="upload-placeholder" class="<?= $brand['logo'] ? 'hidden' : '' ?> space-y-2">
                            <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto group-hover:bg-blue-100 transition-colors">
                                <i class="fas fa-cloud-upload-alt text-slate-400 group-hover:text-blue-500 transition-colors"></i>
                            </div>
                            <div class="text-xs font-medium text-slate-500">
                                <span class="text-blue-600 font-bold">Click to upload</span> or drag and drop
                            </div>
                        </div>

                        <div id="file-preview" class="<?= $brand['logo'] ? '' : 'hidden' ?> space-y-2">
                            <img id="preview-img" src="<?= $brand['logo'] ? '../../uploads/brands/'.$brand['logo'] : '#' ?>" class="h-16 mx-auto object-contain rounded border border-slate-200 p-1 bg-white">
                            <p id="file-name" class="text-xs font-bold text-slate-600 truncate px-4"><?= $brand['logo'] ?: '' ?></p>
                            <button type="button" onclick="resetUpload()" class="text-[10px] font-bold text-red-500 hover:text-red-600 uppercase tracking-wider">Remove & Change</button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Meta Title</label>
                        <input type="text" name="meta_title" value="<?= htmlspecialchars($brand['meta_title'] ?? '') ?>" class="form-input">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Meta Description</label>
                        <textarea name="meta_description" class="form-input min-h-[100px]"><?= htmlspecialchars($brand['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="bg-slate-50 px-8 py-6 flex justify-end gap-3 border-t border-slate-100">
                <button type="button" onclick="location.href='index.php'" class="px-6 py-2.5 rounded-xl border border-slate-200 font-bold text-slate-500 hover:bg-slate-100 transition-all text-sm">Cancel</button>
                <button type="submit" name="update_brand" class="px-6 py-2.5 rounded-xl bg-blue-600 font-bold text-white hover:bg-blue-700 transition-all shadow-md shadow-blue-200 text-sm">Update Brand</button>
            </div>
        </form>
    </div>
</div>

<script>
function handleFileSelect(input) {
    const placeholder = document.getElementById('upload-placeholder');
    const preview = document.getElementById('file-preview');
    const img = document.getElementById('preview-img');
    const name = document.getElementById('file-name');

    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            name.textContent = input.files[0].name;
            placeholder.classList.add('hidden');
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function resetUpload() {
    const input = document.getElementById('logo-input');
    const placeholder = document.getElementById('upload-placeholder');
    const preview = document.getElementById('file-preview');
    
    input.value = '';
    placeholder.classList.remove('hidden');
    preview.classList.add('hidden');
}

const dropZone = document.getElementById('drop-zone');
['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        dropZone.classList.add('border-blue-500', 'bg-blue-50');
    }, false);
});
['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, e => {
        e.preventDefault();
        dropZone.classList.remove('border-blue-500', 'bg-blue-50');
    }, false);
});
</script>
