<?php
require_once '../../includes/config.php';
require_once 'functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

$invoice_id = $_GET['invoice_id'] ?? 0;
$order_id = $_GET['order_id'] ?? 0;
$return_url = $_GET['return'] ?? null;

// Fetch Company Settings
$company_settings = $pdo->query("SELECT * FROM sales_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$company_settings) {
    $company_settings = [
        'company_name' => 'Ultimate General Trading Company',
        'company_address' => 'Mikocheni B, Dar es salaam Tanzania',
        'company_logo' => 'Untitled.jpg'
    ];
}

$items = [];
$document_type = '';
$document_number = '';
$customer_name = '';

if ($invoice_id) {
    // Fetch invoice items
    $sql = "SELECT i.invoice_number, i.invoice_date, c.company_name
            FROM invoices i 
            JOIN customers c ON i.customer_id = c.id 
            WHERE i.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$invoice_id]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        $document_type = 'Invoice';
        $document_number = $doc['invoice_number'];
        $customer_name = $doc['company_name'];
        
        // Get invoice order_id first
        $stmtOrder = $pdo->prepare("SELECT order_id FROM invoices WHERE id = ?");
        $stmtOrder->execute([$invoice_id]);
        $invoiceData = $stmtOrder->fetch();
        $order_id = $invoiceData['order_id'] ?? 0;
    }
}

if ($order_id && $document_type === '') {
    // Fetch order items
    $sql = "SELECT so.order_number, so.quote_date, c.company_name
            FROM sales_orders so 
            JOIN customers c ON so.customer_id = c.id 
            WHERE so.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$order_id]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        $document_type = 'Quotation';
        $document_number = $doc['order_number'] ?? '';
        $customer_name = $doc['company_name'];
    }
}

// Fetch order items with product details if we have an order_id
if ($order_id) {
    $sqlItems = "SELECT soi.*, p.name as product_name, p.product_code, COALESCE(p.main_image, p.image) AS main_image, p.description as product_description
                 FROM sales_order_items soi 
                 LEFT JOIN products p ON soi.product_id = p.id 
                 WHERE soi.order_id = ?";
    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll();
}

if (empty($items)) {
    die("No products found for this document.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - <?php echo htmlspecialchars($document_number); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --odoo-brand: #714B67;
            --odoo-brand-dark: #5b3c53;
            --odoo-action: #008784;
            --odoo-gray: #f9f9f9;
            --odoo-border: #dee2e6;
        }

        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #374151;
        }

        .header-bar {
            background: white;
            border-bottom: 1px solid var(--odoo-border);
            padding: 15px 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .header-bar .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .header-bar .breadcrumb a {
            color: var(--odoo-action);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .header-bar .breadcrumb a:hover {
            text-decoration: underline;
        }

        .header-bar .breadcrumb .sep {
            color: #999;
        }

        .container-custom {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: white;
            border-radius: 8px;
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #111;
            margin: 0 0 10px 0;
        }

        .page-header .subtitle {
            color: #666;
            font-size: 0.95rem;
            margin: 0;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }

        .product-image-container {
            width: 100%;
            height: 250px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .product-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-image-container .no-image {
            color: #ccc;
            font-size: 3rem;
        }

        .product-info {
            padding: 20px;
        }

        .product-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #111;
            margin: 0 0 8px 0;
            line-height: 1.4;
        }

        .product-code {
            font-size: 0.85rem;
            color: #666;
            margin: 0 0 12px 0;
            font-family: 'Courier New', monospace;
        }

        .product-description {
            font-size: 0.95rem;
            color: #555;
            line-height: 1.6;
            margin: 0 0 15px 0;
            min-height: 60px;
        }

        .product-details {
            border-top: 1px solid #eee;
            padding-top: 15px;
            margin-top: 15px;
        }

        .product-detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .product-detail-label {
            color: #666;
            font-weight: 500;
        }

        .product-detail-value {
            color: #111;
            font-weight: 600;
        }

        .quantity-badge {
            display: inline-block;
            background: var(--odoo-action);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 10px;
        }

        .back-button {
            background: var(--odoo-action);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease;
        }

        .back-button:hover {
            background: var(--odoo-brand-dark);
            color: white;
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .products-grid {
                grid-template-columns: 1fr;
            }

            .container-custom {
                padding: 0 15px;
            }

            .page-header {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
    </style>
</head>

<body>
    <?php include '../../../includes/header_employee.php'; ?>

    <div class="header-bar">
        <div class="breadcrumb">
            <?php if ($return_url): ?>
                <a href="<?php echo htmlspecialchars(urldecode($return_url)); ?>">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <span class="sep">/</span>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($document_type); ?> Products</span>
        </div>
    </div>

    <div class="container-custom">
        <div class="page-header">
            <h1>
                <i class="fas fa-images me-2" style="color: var(--odoo-action);"></i>
                Product Catalog
            </h1>
            <p class="subtitle">
                <?php echo htmlspecialchars($document_type); ?> #<?php echo htmlspecialchars($document_number); ?> 
                <?php if ($customer_name): ?>
                    - <?php echo htmlspecialchars($customer_name); ?>
                <?php endif; ?>
            </p>
        </div>

        <?php if (!empty($items)): ?>
            <div class="products-grid">
                <?php foreach ($items as $item): ?>
                    <div class="product-card">
                        <div class="product-image-container">
                            <?php if (!empty($item['main_image']) && !empty($item['product_id'])): ?>
                                <img src="/stock/uploads/products/<?php echo (int)$item['product_id']; ?>/medium/<?php echo htmlspecialchars($item['main_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                     onerror="this.parentElement.innerHTML='<div class=\'no-image\'><i class=\'fas fa-image\'></i></div>'">
                            <?php else: ?>
                                <div class="no-image">
                                    <i class="fas fa-image"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <h3 class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></h3>
                            <?php if (!empty($item['product_code'])): ?>
                                <p class="product-code">Code: <?php echo htmlspecialchars($item['product_code']); ?></p>
                            <?php endif; ?>
                            
                            <div class="product-description">
                                <?php 
                                $description = !empty($item['description']) ? $item['description'] : ($item['product_description'] ?? '');
                                echo nl2br(htmlspecialchars($description ?: 'No description available.'));
                                ?>
                            </div>

                            <div class="product-details">
                                <?php if (!empty($item['quantity'])): ?>
                                    <div class="product-detail-row">
                                        <span class="product-detail-label">Quantity:</span>
                                        <span class="product-detail-value"><?php echo number_format($item['quantity']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($item['unit_price'])): ?>
                                    <div class="product-detail-row">
                                        <span class="product-detail-label">Unit Price:</span>
                                        <span class="product-detail-value"><?php echo number_format($item['unit_price'], 2); ?> TZS</span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($item['line_total'])): ?>
                                    <div class="product-detail-row">
                                        <span class="product-detail-label">Subtotal:</span>
                                        <span class="product-detail-value" style="color: var(--odoo-action);"><?php echo number_format($item['line_total'], 2); ?> TZS</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>No Products Found</h3>
                <p>There are no products associated with this document.</p>
            </div>
        <?php endif; ?>

        <?php if ($return_url): ?>
            <div style="text-align: center; margin-top: 40px; margin-bottom: 30px;">
                <a href="<?php echo htmlspecialchars(urldecode($return_url)); ?>" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($document_type); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
