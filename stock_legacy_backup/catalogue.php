<?php
// session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Fetch Categories for Filter
$categories = $pdo->query("SELECT * FROM stocks_categories ORDER BY name ASC")->fetchAll();

// Filter Logic
$whereClause = "WHERE 1=1";
$params = [];

// Schema tolerance: some installs don't have products.supplier_id
$hasProductsSupplierId = false;
$productCols = [];
$productImageCol = null;
try {
    $productCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    $hasProductsSupplierId = in_array('supplier_id', $productCols, true);
    if (in_array('image', $productCols, true)) {
        $productImageCol = 'image';
    } elseif (in_array('main_image', $productCols, true)) {
        $productImageCol = 'main_image';
    }
} catch (Throwable $e) {
    $hasProductsSupplierId = false;
    $productCols = [];
    $productImageCol = null;
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $whereClause .= " AND (p.name LIKE ? OR p.product_code LIKE ?)";
    $params[] = "%" . $_GET['search'] . "%";
    $params[] = "%" . $_GET['search'] . "%";
}

if (isset($_GET['category']) && !empty($_GET['category'])) {
    $whereClause .= " AND p.category_id = ?";
    $params[] = $_GET['category'];
}

// Fetch Products with Stock, Category, and Supplier (Brand proxy)
$sql = "SELECT p.*, s.quantity, c.name as category_name, " . ($hasProductsSupplierId ? "sup.name" : "NULL") . " as supplier_name
        FROM products p 
        LEFT JOIN stock s ON p.id = s.product_id 
        LEFT JOIN stocks_categories c ON p.category_id = c.id
        " . ($hasProductsSupplierId ? "LEFT JOIN stocks_suppliers sup ON p.supplier_id = sup.id" : "") . "
        $whereClause 
        ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$page_title = 'Product Catalogue';
include 'includes/header.php';
?>

<style>
    /* Iconic Card Style */
    .product-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr); /* Increased to 6 to make them smaller width-wise */
        gap: 15px; /* Reduced gap */
    }
    
    .product-card {
        position: relative;
        cursor: pointer;
        background: transparent;
        border: none;
    }

    .p-image-wrapper {
        position: relative;
        width: 100%;
        padding-bottom: 70%;
        overflow: hidden;
        background: #f8f9fa;
        margin-bottom: 8px;
        border-radius: 6px;
    }

    .p-image-wrapper img {
        position: absolute;
        top: 0; left: 0;
        width: 100%; height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }
    
    .product-card:hover .p-image-wrapper img {
        transform: scale(1.03);
    }

    /* Wishlist Removed */

    /* Details */
    .p-details { padding: 0 2px; }
    .p-brand { 
        font-size: 11px; 
        font-weight: 700; 
        color: #666; 
        text-transform: uppercase; 
        margin-bottom: 2px; 
        display: block;
        line-height: 1.1;
    }
    .p-name { 
        font-size: 12px; 
        font-weight: 500; 
        color: #333; 
        margin-bottom: 4px; 
        line-height: 1.3;
        white-space: nowrap; 
        overflow: hidden; 
        text-overflow: ellipsis; 
    }
    .p-price { 
        font-size: 12px; 
        font-weight: 700; 
        color: #111;
    }
    
    .badge-stock {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        background: rgba(255,255,255,0.9);
        color: #333;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        padding: 8px;
        text-align: center;
        transform: translateY(100%);
        transition: transform 0.3s;
    }
    .product-card:hover .badge-stock {
        transform: translateY(0);
    }

    /* Responsive */
    @media (max-width: 1600px) { .product-grid { grid-template-columns: repeat(5, 1fr); } } 
    @media (max-width: 1300px) { .product-grid { grid-template-columns: repeat(4, 1fr); } }
    @media (max-width: 992px) { .product-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px) { .product-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; } }
    @media (max-width: 576px) { .product-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; } }

    /* Mobile Header */
    @media (max-width: 768px) {
        .d-flex.justify-content-between.align-items-center.mb-4 {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 15px;
        }

        .d-flex.justify-content-between.align-items-center.mb-4 h4 {
            margin-bottom: 0;
        }

        .d-flex.gap-2 {
            width: 100%;
            flex-direction: column;
        }

        .d-flex.gap-2 .form-select {
            width: 100% !important;
        }

        .input-group.input-group-sm {
            width: 100%;
        }

        .input-group.input-group-sm .form-control {
            flex: 1;
        }

        .product-card {
            margin-bottom: 10px;
        }

        .p-details {
            padding: 0 5px;
        }

        .p-name {
            white-space: normal;
            overflow: visible;
            text-overflow: visible;
            line-height: 1.3;
        }

        .p-price {
            font-size: 12px;
        }

        .badge-stock {
            font-size: 10px;
            padding: 6px;
        }
    }
</style>

<main class="main-content">
    <div class="stock-container">
        <!-- Header & Filters -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">Product Catalogue</h4>
            <form method="GET" class="d-flex gap-2">
                <select name="category" class="form-select form-select-sm rounded-0" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php if(($cat['id'] == ($_GET['category'] ?? ''))) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control rounded-0" placeholder="Search..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    <button type="submit" class="btn btn-dark rounded-0"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>
        
        <!-- Product Grid -->
        <div class="product-grid">
            <?php foreach($products as $product): ?>
            <div class="product-card" onclick="window.location.href='product-detail.php?id=<?php echo $product['id']; ?>'">
                <div class="p-image-wrapper">
                    <?php 
                    $imgVal = null;
                    if ($productImageCol && array_key_exists($productImageCol, $product)) {
                        $imgVal = (string) $product[$productImageCol];
                    } elseif (isset($product['image'])) {
                        // Backward compatibility
                        $imgVal = (string) $product['image'];
                    }
                    $imgSrc = resolveProductImageUrl($product['id'], $imgVal, 'medium');
                    ?>
                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" onerror="this.src='assets/images/no-image.png'; this.onerror=null;">
                    
                    
                    <?php if($product['quantity'] <= 0): ?>
                        <div class="badge-stock text-danger">Out of Stock</div>
                    <?php else: ?>
                         <div class="badge-stock text-success">In Stock: <?php echo $product['quantity']; ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="p-details">
                    <?php 
                        // Brand Proxy: Use Supplier Name or Category if Supplier is generic/missing
                        $brand = $product['supplier_name'] ?? $product['category_name'] ?? 'Brand';
                    ?>
                    <span class="p-brand"><?php echo htmlspecialchars($brand); ?></span>
                    <div class="p-name" title="<?php echo htmlspecialchars($product['name']); ?>">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </div>
                    <?php 
                        $currency = $product['currency'] ?? 'USD';
                        $symbol = ($currency == 'TZS') ? 'TSh ' : '$';
                    ?>
                    <div class="p-price">
                        <?php if(in_array($_SESSION['role'] ?? '', ['admin', 'procurement'])): ?>
                            <div class="text-muted fw-normal" style="font-size: 0.75rem;">Cost: <?php echo $symbol . number_format($product['cost_price'], 2); ?></div>
                        <?php endif; ?>
                        <div>
                            Price: <?php echo $symbol . number_format($product['unit_price'], 2); ?>
                            <?php if(isset($product['compare_price']) && $product['compare_price'] > $product['unit_price']): ?>
                                <span class="text-muted text-decoration-line-through fw-normal ms-1" style="font-size: 11px;">
                                    <?php echo $symbol . number_format($product['compare_price'], 2); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if(isset($product['compare_price']) && $product['compare_price'] > $product['unit_price']): ?>
                        <div class="text-success fw-bold mt-1" style="font-size: 10px;">
                            <?php echo round((($product['compare_price'] - $product['unit_price']) / $product['compare_price']) * 100); ?>% OFF
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if(empty($products)): ?>
        <div class="text-center py-5">
            <i class="fas fa-box-open fa-3x text-muted mb-3 opacity-25"></i>
            <p class="text-muted">No products found matching your criteria.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
