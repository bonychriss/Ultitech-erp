<?php
require_once '../../includes/functions.php';

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #fff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        }

        /* Layout & Container - specific overrides */
        .page-wrapper {
            margin-left: 220px !important;
            min-height: 100vh;
            padding: 15px !important;
            width: calc(100% - 220px) !important;
        }

        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0 !important;
                padding: 10px !important;
                width: 100% !important;
            }
        }

        .header {
            background: transparent !important;
            margin-bottom: 20px;
            padding: 0 !important;
            display: flex !important;
            justify-content: space-between;
            align-items: center;
            border: none !important;
        }

        .header h2 {
            font-size: 1.75rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            margin: 0 !important;
        }

        .container {
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* Card: Flat, no padding on container itself */
        .card {
            background: white;
            border-radius: 0;
            border: none !important;
            overflow: visible;
            box-shadow: none !important;
            width: 100%;
            max-width: 100% !important;
        }

        .card-header {
            padding: 0 0 20px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #4b5563;
            text-transform: uppercase;
            border-bottom: 2px solid #e5e7eb;
            background: #f8f9fa;
        }

        .table td {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #1f2937;
            vertical-align: middle;
        }

        .table tr:hover {
            background: #f8fafc;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: #1a73e8;
            color: white;
        }

        .btn-secondary {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background: #d1fae5;
            color: #059669;
        }

        .badge-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .product-image {
            width: 40px;
            height: 40px;
            background: #f1f3f4;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5f6368;
            font-size: 1.2rem;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="page-wrapper">
        <!-- Header -->
        <div class="header">
            <h2>Products</h2>
            <div class="header-actions">
                <a href="../index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="categories.php" class="btn btn-secondary">
                    <i class="fas fa-tags"></i> Categories
                </a>
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Product
                </a>
            </div>
        </div>

        <div class="container">
            <div class="card">
                <!-- Filter Toolbar -->
                <div class="card-header">
                    <form method="GET" style="display: flex; gap: 12px; width: 100%; align-items: center;">
                        <button type="submit" style="display: none;"></button> <!-- Implicit submit -->

                        <div style="position: relative; width: 300px;">
                            <i class="fas fa-search"
                                style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                            <input type="text" name="search" placeholder="Search products..."
                                value="<?= htmlspecialchars($search) ?>"
                                style="width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff;"
                                onchange="this.form.submit()">
                        </div>

                        <select name="category"
                            style="padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; color: #374151; min-width: 150px;"
                            onchange="this.form.submit()">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if (empty($products)): ?>
                    <div style="text-align: center; padding: 64px 24px; color: #5f6368;">
                        <div style="font-size: 4rem; margin-bottom: 16px;"><i class="fas fa-boxes"></i></div>
                        <h3 style="margin-bottom: 8px; font-weight: 500;">No products found</h3>
                        <p>Start by adding your first product or service</p>
                        <a href="create.php" class="btn btn-primary" style="margin-top: 16px;">
                            <i class="fas fa-plus"></i> Add Product
                        </a>
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
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($product['image_path'])): ?>
                                            <img src="../../<?= htmlspecialchars($product['image_path']) ?>" alt="Product Image"
                                                style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #e0e0e0;">
                                        <?php else: ?>
                                            <div class="product-image">
                                                <?= strtoupper(substr($product['name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($product['sku']) ?></td>
                                    <td>
                                        <div
                                            style="font-weight: 500; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;">
                                            <?= htmlspecialchars($product['name']) ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: #5f6368;">
                                            <?= htmlspecialchars(substr($product['description'], 0, 50)) ?>...
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($product['category_name'] ?? '-') ?></td>
                                    <td style="font-weight: 600;">TSh <?= number_format($product['unit_price'], 2) ?></td>
                                    <td>
                                        <?php if ($product['stock_quantity'] <= $product['reorder_level']): ?>
                                            <span style="color: #c5221f; font-weight: 500;"><?= $product['stock_quantity'] ?></span>
                                            <i class="fas fa-exclamation-circle" style="color: #c5221f; font-size: 0.7rem;"
                                                title="Low Stock"></i>
                                        <?php else: ?>
                                            <?= $product['stock_quantity'] ?>
                                        <?php endif; ?>
                                        <span style="font-size: 0.75rem; color: #5f6368;"><?= $product['unit'] ?></span>
                                    </td>
                                    <td>
                                        <span
                                            class="badge <?= ($product['status'] ?? 'active') === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= ucfirst($product['status'] ?? 'active') ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display: inline-flex; gap: 4px;">
                                            <a href="edit.php?id=<?= $product['id'] ?>" class="btn-icon"
                                                style="text-decoration: none; color: #6b7280; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s;"
                                                title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <!-- Additional actions -->
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>