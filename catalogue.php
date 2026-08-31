<?php
session_start();
require_once 'config/database.php';
require_once 'config/functions.php';

// Fetch Categories for Filter
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Filter Logic
$whereClause = "WHERE 1=1";
$params = [];

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
$sql = "SELECT p.*, s.quantity, c.name as category_name, sup.name as supplier_name
        FROM products p 
        LEFT JOIN stock s ON p.id = s.product_id 
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers sup ON p.supplier_id = sup.id
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
        grid-template-columns: repeat(5, 1fr);
        gap: 20px;
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
        padding-bottom: 125%; /* 3:4 Aspect Ratio */
        overflow: hidden;
        background: #f8f9fa;
        margin-bottom: 12px;
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
        font-size: 13px; 
        font-weight: 700; 
        color: #333; 
        text-transform: capitalize; 
        margin-bottom: 4px; 
        display: block;
        line-height: 1.2;
    }
    .p-name { 
        font-size: 13px; 
        font-weight: 400; 
        color: #555; 
        margin-bottom: 6px; 
        line-height: 1.4;
        white-space: nowrap; 
        overflow: hidden; 
        text-overflow: ellipsis; 
    }
    .p-price { 
        font-size: 13px; 
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
    @media (max-width: 1200px) { .product-grid { grid-template-columns: repeat(4, 1fr); } }
    @media (max-width: 992px) { .product-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px) { .product-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; } }
    @media (max-width: 576px) { .product-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; } }
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
                    $imgSrc = 'assets/images/no-image.png'; // Fallback
                    if ($product['main_image']) {
                        $imgSrc = "uploads/products/" . $product['id'] . "/medium/" . $product['main_image'];
                    }
                    // Check if file exists to avoid broken images if path is wrong
                    if (!file_exists($imgSrc) && $product['main_image']) {
                         // Attempt raw path if needed, or keep fallback
                    }
                    ?>
                    <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                    
                    
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
                        <div class="text-muted fw-normal" style="font-size: 0.75rem;">Cost: <?php echo $symbol . number_format($product['buying_price'], 2); ?></div>
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
