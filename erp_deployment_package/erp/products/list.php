<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;

// Get all products
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'all';

$sql = "SELECT p.*, c.name as category_name 
        FROM erp_products p 
        LEFT JOIN erp_categories c ON p.category_id = c.id 
        WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?)";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam, $searchParam];
}

if ($category !== 'all') {
    $sql .= " AND p.category_id = ?";
    $params[] = $category;
}

$sql .= " ORDER BY p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT * FROM erp_categories ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        
        .header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.5rem; font-weight: 500; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        
        .card { background: white; border-radius: 8px; border: 1px solid #e0e0e0; overflow: hidden; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid #e0e0e0; }
        
        .filters { display: flex; gap: 12px; margin-bottom: 20px; }
        .search-box { flex: 1; max-width: 400px; }
        .search-box input { width: 100%; padding: 10px 16px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; }
        .filter-select { padding: 10px 16px; border: 1px solid #dadce0; border-radius: 4px; font-size: 0.875rem; background: white; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.75rem; font-weight: 500; color: #5f6368; text-transform: uppercase; border-bottom: 1px solid #e0e0e0; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f1f3f4; vertical-align: middle; }
        .table tr:hover { background: #f8f9fa; }
        
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; }
        .btn-primary { background: #1a73e8; color: white; }
        .btn-secondary { background: #fff; color: #202124; border: 1px solid #dadce0; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: #e6f4ea; color: #137333; }
        .badge-warning { background: #fef7e0; color: #b06000; }
        .badge-danger { background: #fce8e6; color: #c5221f; }
        
        .product-image { width: 40px; height: 40px; background: #f1f3f4; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #5f6368; font-size: 1.2rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ðŸ“¦ Products & Services</h1>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">â† Back to Dashboard</a>
            <a href="create.php" class="btn btn-primary">+ Add Product</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <div class="card-header">
                <form method="GET" class="filters">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <select name="category" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            
            <?php if (empty($products)): ?>
                <div style="text-align: center; padding: 64px 24px; color: #5f6368;">
                    <div style="font-size: 4rem; margin-bottom: 16px;">ðŸ“¦</div>
                    <h3>No products found</h3>
                    <p>Start by adding your first product or service</p>
                    <a href="create.php" class="btn btn-primary" style="margin-top: 16px;">+ Add Product</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Image</th>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td>
                                    <div class="product-image">
                                        <?= strtoupper(substr($product['name'], 0, 1)) ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($product['sku']) ?></td>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($product['name']) ?></div>
                                    <div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars(substr($product['description'], 0, 50)) ?>...</div>
                                </td>
                                <td><?= htmlspecialchars($product['category_name'] ?? '-') ?></td>
                                <td>TSh <?= number_format($product['unit_price'], 2) ?></td>
                                <td>
                                    <?php if ($product['stock_quantity'] <= $product['reorder_level']): ?>
                                        <span style="color: #c5221f; font-weight: 500;"><?= $product['stock_quantity'] ?></span>
                                    <?php else: ?>
                                        <?= $product['stock_quantity'] ?>
                                    <?php endif; ?>
                                    <span style="font-size: 0.75rem; color: #5f6368;"><?= $product['unit'] ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= $product['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= ucfirst($product['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit.php?id=<?= $product['id'] ?>" class="btn btn-secondary" style="padding: 4px 12px; font-size: 0.75rem;">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

