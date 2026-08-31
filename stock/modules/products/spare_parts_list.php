<?php
// stock/modules/products/spare_parts_list.php
require_once '../../config/database.php';
require_once '../../config/functions.php';
require_once '../../config/paths.php';
requireLogin();

$page_title = 'Spare Parts';

// Column capability detection (avoid hard dependencies across deployments)
$hasCreatedBy = false;
$hasVisibility = false; // catalogue_visibility
$hasFeatured = false;   // is_featured
$hasTodayDeal = false;  // is_todays_deal / todays_deal

try { $pdo->query("SELECT created_by FROM products LIMIT 1"); $hasCreatedBy = true; } catch (Throwable $e) {}
try { $pdo->query("SELECT catalogue_visibility FROM products LIMIT 1"); $hasVisibility = true; } catch (Throwable $e) {}
try { $pdo->query("SELECT is_featured FROM products LIMIT 1"); $hasFeatured = true; } catch (Throwable $e) {}
try { $pdo->query("SELECT is_todays_deal FROM products LIMIT 1"); $hasTodayDeal = true; } catch (Throwable $e) {
    try { $pdo->query("SELECT todays_deal FROM products LIMIT 1"); $hasTodayDeal = true; } catch (Throwable $e2) {}
}

// Simple toggle handler (best-effort)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle']) && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $toggle = (string)$_POST['toggle'];

    try {
        if ($toggle === 'published' && $hasVisibility) {
            $stmt = $pdo->prepare("UPDATE products SET catalogue_visibility = IF(catalogue_visibility='visible','hidden','visible') WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($toggle === 'featured' && $hasFeatured) {
            $stmt = $pdo->prepare("UPDATE products SET is_featured = IF(IFNULL(is_featured,0)=1,0,1) WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($toggle === 'todays_deal' && $hasTodayDeal) {
            // Support either is_todays_deal or todays_deal
            try {
                $pdo->query("SELECT is_todays_deal FROM products LIMIT 1");
                $stmt = $pdo->prepare("UPDATE products SET is_todays_deal = IF(IFNULL(is_todays_deal,0)=1,0,1) WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("UPDATE products SET todays_deal = IF(IFNULL(todays_deal,0)=1,0,1) WHERE id = ?");
                $stmt->execute([$id]);
            }
        }
    } catch (Throwable $e) {
        // ignore toggle failures (schema may not match)
    }

    header('Location: spare_parts_list.php');
    exit;
}

// Sales count: best-effort from stock_movements out/sale
$hasMovements = false;
try { $pdo->query("SELECT reference_type FROM stock_movements LIMIT 1"); $hasMovements = true; } catch (Throwable $e) {}

$selectCreatedBy = $hasCreatedBy ? ", u.full_name AS created_by_name" : ", NULL AS created_by_name";
$joinCreatedBy = $hasCreatedBy ? "LEFT JOIN users u ON p.created_by = u.id" : "";
$selectSales = $hasMovements
    ? ", (SELECT COUNT(*) FROM stock_movements sm WHERE sm.product_id = p.id AND sm.movement_type = 'out' AND sm.reference_type = 'sale') AS sale_count"
    : ", 0 AS sale_count";

$selectPublished = $hasVisibility
    ? ", IFNULL(p.catalogue_visibility, 'visible') AS catalogue_visibility"
    : ", 'visible' AS catalogue_visibility";
$selectFeatured = $hasFeatured
    ? ", IFNULL(p.is_featured, 0) AS is_featured"
    : ", 0 AS is_featured";
$selectTodayDeal = $hasTodayDeal
    ? ", " . (function_exists('str_starts_with') ? "" : "") . "1 AS __dummy" // placeholder overwritten below
    : ", 0 AS todays_deal";

// Handle todays deal select field name
if ($hasTodayDeal) {
    try {
        $pdo->query("SELECT is_todays_deal FROM products LIMIT 1");
        $selectTodayDeal = ", IFNULL(p.is_todays_deal, 0) AS todays_deal";
    } catch (Throwable $e) {
        $selectTodayDeal = ", IFNULL(p.todays_deal, 0) AS todays_deal";
    }
}

// Fetch spare parts
$sql = "
    SELECT
        p.id, p.product_code, p.name, p.main_image,
        p.unit_price, p.buying_price, p.reorder_level,
        IFNULL(st.quantity, 0) AS quantity,
        IFNULL(p.unit_of_measure, 'pcs') AS unit_of_measure
        $selectPublished
        $selectFeatured
        $selectTodayDeal
        $selectCreatedBy
        $selectSales
    FROM products p
    LEFT JOIN stock st ON p.id = st.product_id
    $joinCreatedBy
    WHERE 1=1
";

// Prefer item_type when available; fallback to category naming
$hasItemType = false;
try { $pdo->query("SELECT item_type FROM products LIMIT 1"); $hasItemType = true; } catch (Throwable $e) {}
if ($hasItemType) {
    $sql .= " AND p.item_type = 'spare_part' ";
} else {
    $sql .= " AND (p.name IS NOT NULL) ";
}
$sql .= " ORDER BY p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<main class="main-content">
    <div class="container-fluid py-4 sp-page">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
            <div class="sp-title">
                <div class="sp-kicker">All products</div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <a href="add.php" class="btn sp-btn-primary">Add New Product</a>
            </div>
        </div>

        <div class="card sp-card">
            <div class="sp-toolbar">
                <div class="sp-toolbar-left">
                    <div class="sp-toolbar-caption">All Product</div>
                </div>
                <div class="sp-toolbar-right">
                    <select class="form-select form-select-sm sp-select" style="width:140px;" disabled>
                        <option>Bulk Action</option>
                    </select>
                    <select class="form-select form-select-sm sp-select" style="width:120px;" disabled>
                        <option>Sort by</option>
                    </select>
                    <input class="form-control form-control-sm sp-search" id="spSearch" placeholder="Type & Enter">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0" id="spareTable">
                    <thead class="bg-light">
                        <tr>
                            <th style="width:34px;" class="ps-3"><input type="checkbox" class="form-check-input" id="spCheckAll"></th>
                            <th style="width:70px;">&nbsp;</th>
                            <th>Name</th>
                            <th>Added By</th>
                            <th>Info</th>
                            <th class="text-center">Total Stock</th>
                            <th class="text-center pe-3">Options</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $qty = (int)($r['quantity'] ?? 0);
                        $saleCount = (int)($r['sale_count'] ?? 0);
                        $price = (float)($r['unit_price'] ?? 0);
                        $rating = 0;
                        $addedBy = trim((string)($r['created_by_name'] ?? ''));
                        if ($addedBy === '') $addedBy = 'Ultimate';
                        $img = '';
                        if (!empty($r['main_image'])) {
                            $img = $stockBasePath . 'uploads/products/' . (int)$r['id'] . '/thumbnail/' . $r['main_image'];
                        }
                        $isToday = !empty($r['todays_deal']);
                        $isPub = ($r['catalogue_visibility'] ?? 'visible') === 'visible';
                        $isFeat = !empty($r['is_featured']);
                    ?>
                        <tr class="sp-row">
                            <td class="ps-3"><input type="checkbox" class="form-check-input sp-cb"></td>
                            <td>
                                <?php if ($img): ?>
                                    <img class="sp-thumb" src="<?= htmlspecialchars($img) ?>" alt="">
                                <?php else: ?>
                                    <div class="sp-thumb sp-thumb--empty">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="sp-name"><?= htmlspecialchars($r['name'] ?? '') ?></div>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($addedBy) ?></td>
                            <td class="small sp-info">
                                <div><span class="sp-info-k">Num of Sale:</span> <?= $saleCount ?> Times</div>
                                <div><span class="sp-info-k">Base Price:</span> <?= number_format($price, 2) ?></div>
                                <div><span class="sp-info-k">Rating:</span> <?= $rating ?></div>
                            </td>
                            <td class="text-center fw-semibold"><?= $qty ?></td>
                            <td class="text-center pe-3">
                                <div class="d-flex justify-content-center gap-2">
                                    <a class="sp-opt sp-opt-view" href="view.php?id=<?= (int)$r['id'] ?>" title="View"><i class="fas fa-eye"></i></a>
                                    <a class="sp-opt sp-opt-edit" href="edit.php?id=<?= (int)$r['id'] ?>" title="Edit"><i class="fas fa-pen"></i></a>
                                    <a class="sp-opt sp-opt-del" href="delete.php?id=<?= (int)$r['id'] ?>" title="Delete" onclick="return confirm('Delete this product?');"><i class="fas fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">No spare parts found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<style>
    .sp-page{ background:#f4f6fb; min-height:100vh; }
    .sp-kicker{ color:#111827; font-weight:700; font-size:14px; }
    .sp-btn-primary{
        background:#6d28d9; color:#fff; border:0;
        padding:10px 16px; border-radius:999px;
        font-weight:700; box-shadow:0 8px 20px rgba(109,40,217,.22);
    }
    .sp-btn-primary:hover{ color:#fff; background:#5b21b6; }

    .sp-card{ border:0; border-radius:16px; box-shadow:0 14px 30px rgba(15,23,42,.06); overflow:hidden; }
    .sp-toolbar{ background:#fff; display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid #eef2f7; }
    .sp-toolbar-caption{ font-weight:600; color:#0f172a; }
    .sp-toolbar-right{ display:flex; gap:12px; align-items:center; }
    .sp-search{ width:220px; border-radius:12px; border:1px solid #eef2f7; padding:10px 12px; }
    .sp-select{ border-radius:12px; border:1px solid #eef2f7; }

    .sp-thumb{ width:44px; height:44px; object-fit:cover; border-radius:10px; border:1px solid #eef2f7; background:#fff; }
    .sp-thumb--empty{ display:flex; align-items:center; justify-content:center; color:#94a3b8; }
    .sp-name{ font-weight:700; color:#0f172a; font-size:13px; line-height:1.15; text-transform:uppercase; }
    .sp-info{ color:#0f172a; }
    .sp-info-k{ font-weight:700; }

    .sp-opt{
        width:26px; height:26px; border-radius:999px;
        display:inline-flex; align-items:center; justify-content:center;
        text-decoration:none; font-size:11px;
        border:1px solid #eef2f7; background:#fff;
    }
    .sp-opt-view{ color:#16a34a; background:#e7f9ef; border-color:#d1fae5; }
    .sp-opt-edit{ color:#2563eb; background:#eaf2ff; border-color:#dbeafe; }
    .sp-opt-del{ color:#ef4444; background:#ffecec; border-color:#fee2e2; }

    #spareTable thead th{
        font-size:12px; font-weight:600; color:#6b7280; background:#fff;
        border-bottom:1px solid #eef2f7;
    }
    #spareTable tbody td{ border-top:1px solid #f3f4f6; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const input = document.getElementById('spSearch');
    const rows = Array.from(document.querySelectorAll('#spareTable tbody tr.sp-row'));
    input && input.addEventListener('input', function(){
        const q = (this.value || '').toLowerCase();
        rows.forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
    });
    const all = document.getElementById('spCheckAll');
    if(all){
        all.addEventListener('change', function(){
            document.querySelectorAll('.sp-cb').forEach(cb => cb.checked = all.checked);
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>

