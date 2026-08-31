<?php
// session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

if (!isset($_GET['id'])) {
    redirect('catalogue.php');
}
$id = $_GET['id'];

// Schema tolerance: some installs don't have products.supplier_id
$hasProductsSupplierId = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasProductsSupplierId = in_array('supplier_id', $cols, true);
} catch (Throwable $e) {
    $hasProductsSupplierId = false;
}

// Fetch Product Details
$stmt = $pdo->prepare("SELECT p.*, c.name as category_name, " . ($hasProductsSupplierId ? "s.name" : "NULL") . " as supplier_name, st.quantity, st.location 
                       FROM products p 
                       LEFT JOIN stocks_categories c ON p.category_id = c.id 
                       " . ($hasProductsSupplierId ? "LEFT JOIN stocks_suppliers s ON p.supplier_id = s.id" : "") . " 
                       LEFT JOIN stock st ON p.id = st.product_id 
                       WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    redirect('catalogue.php');
}

// Fetch Images
$images = [];
if (!empty($product['image'])) {
    $images[] = ['image_name' => $product['image'], 'is_primary' => 1];
}

$page_title = $product['name'];
include 'includes/header.php';
?>

<style>
    /* Product Detail Styles matching Iconic Theme */
    .pdp-container {
        max-width: 1200px;
        margin: 0 auto;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    .breadcrumb-link {
        color: #666;
        text-decoration: none;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .breadcrumb-link:hover { text-decoration: underline; color: #000; }
    
    .pdp-title {
        font-weight: 300;
        font-size: 2rem;
        color: #000;
        margin-bottom: 0.5rem;
    }

    .pdp-brand {
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.9rem;
        color: #333;
        margin-bottom: 0.25rem;
    }

    .pdp-price {
        font-size: 1.5rem;
        font-weight: 600;
        color: #000;
        margin-bottom: 1rem;
    }
    
    .pdp-cost {
        font-size: 0.85rem;
        color: #888;
        font-weight: 400;
        margin-bottom: 0.5rem;
    }

    .pdp-code {
        font-size: 0.85rem;
        color: #666;
        margin-bottom: 1.5rem;
    }

    .gallery-main {
        width: 100%;
        background: #f8f9fa;
        aspect-ratio: 3/4;
        position: relative;
        overflow: hidden;
        margin-bottom: 1rem;
    }
    
    .gallery-main img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        cursor: zoom-in;
    }

    .gallery-thumbs {
        display: flex;
        gap: 10px;
        overflow-x: auto;
    }
    
    .thumb-item {
        width: 80px;
        aspect-ratio: 3/4;
        cursor: pointer;
        opacity: 0.6;
        transition: opacity 0.2s;
    }
    .thumb-item:hover, .thumb-item.active { opacity: 1; border: 1px solid #000; }
    .thumb-item img { width: 100%; height: 100%; object-fit: cover; }

    .meta-table td {
        padding: 0.5rem 0;
        vertical-align: top;
    }
    .meta-label {
        color: #666;
        width: 100px;
        font-size: 0.9rem;
    }
    .meta-value {
        font-weight: 500;
        color: #000;
        font-size: 0.9rem;
    }

    .btn-action {
        border-radius: 0;
        padding: 12px 24px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
</style>

<main class="main-content">
    <div class="stock-container pdp-container">
        
        <!-- Breadcrumb -->
        <div class="mb-4">
            <a href="catalogue.php" class="breadcrumb-link"><i class="fas fa-chevron-left me-1"></i> Catalogue</a>
        </div>

        <div class="row gx-5">
            <!-- Left Column: Images -->
            <div class="col-md-5 mb-4">
                <div class="gallery-main mb-2">
                    <?php 
                        $mainImgSrc = resolveProductImageUrl((int) $product['id'], $product['image'] ?? '', 'large');
                    ?>
                    <img src="<?php echo $mainImgSrc; ?>" id="mainImage" alt="Product Image" onerror="this.src='assets/images/no-image.png'; this.onerror=null;">
                </div>
                
                <?php if(count($images) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach($images as $index => $img): ?>
                    <div class="thumb-item <?php echo $index === 0 ? 'active' : ''; ?>" onclick="changeImage(this, '<?php echo resolveProductImageUrl($product['id'], $img['image_name'], 'large'); ?>')">
                        <img src="<?php echo resolveProductImageUrl($product['id'], $img['image_name'], 'thumbnail'); ?>" alt="Thumbnail">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Details -->
            <div class="col-md-7">
                <div class="mb-1 pdp-brand"><?php echo htmlspecialchars($product['supplier_name'] ?? 'Brand'); ?></div>
                <h1 class="pdp-title" style="font-size: 1.25rem;"><?php echo htmlspecialchars($product['name']); ?></h1>
                <div class="pdp-code">Code: <?php echo htmlspecialchars($product['product_code']); ?></div>

                <hr class="my-2" style="opacity: 0.1;">

                <?php 
                    $currency = $product['currency'] ?? 'USD';
                    $symbol = ($currency == 'TZS') ? 'TSh ' : '$';
                ?>
                <div class="d-flex align-items-baseline gap-3 mb-2">
                     <div class="pdp-price" style="font-size: 1.1rem; margin-bottom: 0;">
                        Price: <?php echo $symbol . number_format($product['unit_price'], 2); ?>
                    </div>
                    <div class="pdp-cost" style="font-size: 0.75rem; margin-bottom: 0;">Cost: <?php echo $symbol . number_format($product['cost_price'], 2); ?></div>
                </div>

                <div class="mb-3">
                    <?php if($product['quantity'] > 0): ?>
                        <span class="text-success fw-bold" style="font-size: 0.8rem;"><i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i> In Stock (<?php echo $product['quantity']; ?> available)</span>
                    <?php else: ?>
                        <span class="text-danger fw-bold" style="font-size: 0.8rem;"><i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i> Out of Stock</span>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 1px;">Description</h6>
                    <p class="text-muted" style="line-height: 1.4; font-size: 0.8rem;">
                        <?php echo nl2br(htmlspecialchars($product['description'] ?? '')); ?>
                    </p>
                </div>

                <table class="table table-borderless table-sm meta-table mb-3">
                    <tr>
                        <td class="meta-label">Category:</td>
                        <td class="meta-value"><?php echo htmlspecialchars($product['category_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="meta-label">Supplier:</td>
                        <td class="meta-value"><?php echo htmlspecialchars($product['supplier_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="meta-label">Location:</td>
                        <td class="meta-value"><?php echo htmlspecialchars($product['location'] ?? '-'); ?></td>
                    </tr>
                </table>

                <div class="d-grid gap-2 col-md-6">
                    <a href="modules/purchases/create.php?product_id=<?php echo $product['id']; ?>" class="btn btn-outline-dark btn-action btn-sm">Create PO</a>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function changeImage(element, src) {
    document.getElementById('mainImage').src = src;
    
    // Update active state
    document.querySelectorAll('.thumb-item').forEach(el => el.classList.remove('active'));
    element.classList.add('active');
}
</script>

<?php include 'includes/footer.php'; ?>
