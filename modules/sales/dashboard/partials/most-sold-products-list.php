<?php
/** @var list<array<string, mixed>> $products */
/** @var string $emptyMessage */
/** @var string $placeholderIcon */

if (!isset($placeholderIcon) || $placeholderIcon === '') {
    $placeholderIcon = 'fa-box';
}
if (!isset($emptyMessage) || $emptyMessage === '') {
    $emptyMessage = 'No product data yet';
}
$products = $products ?? [];

if (function_exists('sales_load_stock_image_helpers')) {
    sales_load_stock_image_helpers();
}

if ($products === []) {
    echo '<div class="text-center text-muted py-4 most-sold-empty">' . htmlspecialchars($emptyMessage) . '</div>';
    return;
}
?>
<div class="products-list">
    <?php foreach ($products as $index => $product): ?>
        <?php
        $rating = rand(35, 50) / 10;
        $fullStars = floor($rating);
        $halfStar = ($rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        $productId = (int) ($product['product_id'] ?? 0);
        $imageFile = trim((string) ($product['main_image'] ?? ''));
        if ($imageFile !== '' && preg_match('/^placeholder\.(jpe?g|png|gif|webp)$/i', $imageFile)) {
            $imageFile = '';
        }
        if ($productId > 0 && function_exists('sales_order_item_image_name')) {
            $salesDb = function_exists('sales_pdo') ? sales_pdo() : null;
            $resolvedFile = sales_order_item_image_name($product, $salesDb instanceof PDO ? $salesDb : null);
            if ($resolvedFile !== '') {
                $imageFile = $resolvedFile;
            }
        }
        $imageUrl = '';
        if ($productId > 0 && function_exists('stock_product_list_image_url')) {
            $imageUrl = stock_product_list_image_url($productId, $imageFile, 'thumbnail', '');
        }
        ?>
        <div class="product-item">
            <div class="product-rank"><?php echo $index + 1; ?></div>
            <div class="product-image">
                <?php if ($imageUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($imageUrl); ?>"
                         alt=""
                         loading="lazy"
                         onerror="this.onerror=null;var w=this.parentElement;w.innerHTML='<div class=&quot;product-placeholder&quot;><i class=&quot;fas <?php echo htmlspecialchars($placeholderIcon); ?>&quot;></i></div>';">
                <?php else: ?>
                    <div class="product-placeholder">
                        <i class="fas <?php echo htmlspecialchars($placeholderIcon); ?>"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="product-info">
                <div class="product-name"><?php echo htmlspecialchars((string) ($product['product_name'] ?? 'Product')); ?></div>
                <div class="product-rating">
                    <?php for ($i = 0; $i < $fullStars; $i++): ?><i class="fas fa-star text-warning"></i><?php endfor; ?>
                    <?php if ($halfStar): ?><i class="fas fa-star-half-alt text-warning"></i><?php endif; ?>
                    <?php for ($i = 0; $i < $emptyStars; $i++): ?><i class="far fa-star text-warning"></i><?php endfor; ?>
                    <span class="product-rating-val"><?php echo number_format($rating, 1); ?></span>
                </div>
                <div class="product-meta">
                    <?php if (!empty($product['top_customer_name'])): ?>
                        <i class="fas fa-user" style="font-size: 0.7rem;"></i> <?php echo htmlspecialchars((string) $product['top_customer_name']); ?>
                    <?php else: ?>
                        Product ID: <?php echo (int) ($product['product_id'] ?? 0); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
