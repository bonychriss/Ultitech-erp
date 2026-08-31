<?php
// stock/modules/products/import.php
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
requireLogin();

$page_title = 'Bulk Import';
include '../../includes/header.php';

function build_url($path) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $base . '/' . ltrim($path, '/');
}

$spareImportUrl = build_url('spare_import.php');
$truckImportUrl = build_url('truck_import.php');
?>

<main class="main-content">
    <div class="container-fluid py-4" style="background:#f8fafc; min-height:100vh;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <div class="text-muted small">Products</div>
                <h1 class="h4 fw-bold mb-0">Bulk Import</h1>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fas fa-arrow-left me-1"></i> Back</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="text-muted small">Import type</div>
                                <div class="h5 fw-bold mb-1">Spare Parts</div>
                                <div class="text-muted small">Use this for brake pads, filters, bearings, etc.</div>
                            </div>
                            <div style="width:44px;height:44px;border-radius:12px;background:#ecfeff;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-screwdriver-wrench" style="color:#0f766e;font-size:18px;"></i>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-grid gap-2">
                            <a class="btn btn-success" href="<?= htmlspecialchars($spareImportUrl) ?>">
                                <i class="fas fa-upload me-1"></i> Upload / Import Spare Parts
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="text-muted small">Import type</div>
                                <div class="h5 fw-bold mb-1">Trucks</div>
                                <div class="text-muted small">Use this for vehicles/trucks inventory records.</div>
                            </div>
                            <div style="width:44px;height:44px;border-radius:12px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-truck" style="color:#166534;font-size:18px;"></i>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-grid gap-2">
                            <a class="btn btn-primary" href="<?= htmlspecialchars($truckImportUrl) ?>">
                                <i class="fas fa-upload me-1"></i> Upload / Import Trucks
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="small text-muted mt-3">
            Tip: Open the import page for your type to download the template and upload your file.
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>

