<?php
/** @var array $order */
/** @var array $company_settings */
/** @var array $items */
/** @var string $currency */
$displayOrderNumber = (string) (($displayOrderNumber ?? '') !== '' ? $displayOrderNumber : ($order['order_number'] ?? ''));
$displayOrderNumber = preg_replace('/-OLD-\d+$/i', '', $displayOrderNumber) ?: $displayOrderNumber;
$taxCalculationMode = trim((string) getCompanySetting('tax_calculation_mode', 'exclusive'));
if (!in_array($taxCalculationMode, ['exclusive', 'inclusive'], true)) {
    $taxCalculationMode = 'exclusive';
}
?>
<div class="sheet-container" style="font-family: 'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 13px;">
    <div class="sheet" style="display: flex; flex-direction: column; min-height: 297mm; font-family: inherit;">
        <div class="sheet-header-title quot-sheet-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
            <h1 class="sheet-title">
                <?php echo ($order['status'] === 'draft' || $order['status'] === 'quotation') ? 'Quotation' : 'Sales Order'; ?> #
                <?php echo htmlspecialchars($displayOrderNumber); ?>
            </h1>
            <div class="quot-company-block" style="text-align: right;">
                <?php 
                    $logoPath = !empty($company_settings['company_logo_url']) ? $company_settings['company_logo_url'] : '/assets/images/' . ($company_settings['company_logo'] ?: 'Untitled.jpg');
                ?>
                <img src="<?php echo $logoPath; ?>" alt="Company Logo" style="max-height: 80px; margin-bottom: 10px;" onerror="this.style.display='none'">
                <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($company_settings['company_name']); ?></h5>
                <p class="text-muted mb-0" style="white-space: pre-line; color: #000;"><?php echo htmlspecialchars($company_settings['company_address']); ?><?php if(!empty($company_settings['company_location'])): ?>, <?php echo htmlspecialchars($company_settings['company_location']); ?><?php endif; ?></p>
                <?php if(!empty($company_settings['company_tin'])): ?>
                    <p class="text-muted mb-0" style="color: #000;">TIN: <?php echo htmlspecialchars($company_settings['company_tin']); ?></p>
                <?php endif; ?>
                <?php if(!empty($company_settings['company_vrn'])): ?>
                    <p class="text-muted mb-0" style="color: #000;">VRN: <?php echo htmlspecialchars($company_settings['company_vrn']); ?></p>
                <?php endif; ?>
                <?php if(!empty($company_settings['company_phone'])): ?>
                    <p class="text-muted mb-0" style="color: #000;">Phone: <?php echo htmlspecialchars($company_settings['company_phone']); ?></p>
                <?php endif; ?>
                <?php if(!empty($company_settings['company_email'])): ?>
                    <p class="text-muted mb-0" style="color: #000;">Email: <?php echo htmlspecialchars($company_settings['company_email']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; flex: 1;">
            <div class="form-grid">
                <div class="left-col" style="width: 100%;">
                    <div class="form-value" style="font-size: 0.92rem; line-height: 1.35; color: #000;">
                        <strong style="font-size: 1.08rem; color: #000; font-weight: bold;"><?php echo htmlspecialchars((string) ($order['company_name'] ?? $order['contact_person'] ?? '-')); ?></strong><br>
                        <?php
                            $receiverAddress = trim((string) ($order['address'] ?? ''));
                            if ($receiverAddress === '') {
                                $receiverAddress = trim((string) ($order['company_address'] ?? ''));
                            }
                        ?>
                        <?php if ($receiverAddress !== ''): ?>
                            <span><?php echo nl2br(htmlspecialchars($receiverAddress)); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($order['email'])): ?>
                            <span><?php echo htmlspecialchars($order['email']); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($order['phone'])): ?>
                            <span><?php echo htmlspecialchars($order['phone']); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($order['tin'])): ?>
                            <span>TIN: <?php echo htmlspecialchars($order['tin']); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($order['vrn'])): ?>
                            <span>VRN: <?php echo htmlspecialchars($order['vrn']); ?></span><br>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="date-info-bar" style="margin-top: 10px; margin-bottom: 10px; border: 1px solid #000; display: flex; text-align: left;">
                <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                    <div style="font-weight: bold; font-size: 0.72rem;">Invoice Date</div>
                    <div style="font-size: 0.78rem;"><?php echo date('d/m/Y', strtotime($order['quote_date'])); ?></div>
                </div>
                <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                    <div style="font-weight: bold; font-size: 0.72rem;">Due Date</div>
                    <div style="font-size: 0.78rem;"><?php echo !empty($order['valid_until']) ? date('d/m/Y', strtotime($order['valid_until'])) : '-'; ?></div>
                </div>
                <div style="flex: 1; padding: 4px 8px;">
                    <div style="font-weight: bold; font-size: 0.72rem;">Salesperson</div>
                    <div style="font-size: 0.78rem;"><?php echo htmlspecialchars($order['salesperson'] ?? 'System Admin'); ?></div>
                </div>
            </div>

            <div class="notebook">
                <table class="o-table">
                    <thead>
                        <tr>
                            <th class="num" style="width: 6%;">S/N</th>
                            <th style="width: 10%;">Image</th>
                            <th style="width: 16%;">Product</th>
                            <th style="width: 29%;">Description</th>
                            <th class="num" style="width: 10%;">Qty</th>
                            <th class="num" style="width: 14.5%;">Unit Price</th>
                            <th class="num" style="width: 14.5%;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $itemIdx => $item): ?>
                            <tr>
                                <td class="num"><?php echo $itemIdx + 1; ?></td>
                                <td style="padding: 4px 6px;">
                                    <?php 
                                        $pid = (int)($item['product_id'] ?? 0);
                                        $img = $item['main_image'] ?? '';
                                        if ($pid > 0):
                                            $imgUrl = function_exists('sales_order_item_image_url')
                                                ? sales_order_item_image_url($item, 'medium')
                                                : (function_exists('sales_product_image_url')
                                                    ? sales_product_image_url($pid, (string) $img, 'medium')
                                                    : app_url('/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode((string) $img)));
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                             style="width: 60px; height: 60px; object-fit: cover;" 
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div style="display: none; width: 60px; height: 60px; background: #eee; border-radius: 4px; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                    <?php else: ?>
                                        <div style="width: 60px; height: 60px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo nl2br(htmlspecialchars($item['description'] ?? '')); ?></td>
                                <td class="num"><?php echo number_format($item['quantity'], 2); ?></td>
                                <td class="num"><?php echo number_format($item['unit_price'], 2); ?></td>
                                <td class="num"><?php echo number_format((float) ($item['line_total'] ?? ((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0))), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                <table class="o-totals-table" style="width: 300px;">
                    <tr><td>Subtotal</td><td class="num"><?php echo number_format($order['subtotal'], 2); ?></td></tr>
                    <?php if (isset($order['discount_amount']) && (float)$order['discount_amount'] > 0): ?>
                    <tr><td>Discount</td><td class="num">-<?php echo number_format((float) $order['discount_amount'], 2); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($taxCalculationMode === 'exclusive' && (float) ($order['tax_amount'] ?? 0) > 0): ?>
                    <tr><td>Tax</td><td class="num"><?php echo number_format((float) $order['tax_amount'], 2); ?></td></tr>
                    <?php endif; ?>
                    <?php if (isset($order['shipping_charges']) && (float)$order['shipping_charges'] > 0): ?>
                    <tr><td>Shipping</td><td class="num"><?php echo number_format((float) $order['shipping_charges'], 2); ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand-total" style="border-top: 2px solid #000; font-weight: bold;">
                        <td>Total</td><td class="num"><?php echo number_format($order['total_amount'], 2); ?></td>
                    </tr>
                </table>
            </div>
            <?php if (!empty($company_settings['bank_details'])): ?>
                <div class="quot-bank-details" style="margin-top: 18px;">
                    <div style="font-weight: 700; margin-bottom: 6px; color: #111827; font-size: 0.84rem;">Payment details</div>
                    <div style="white-space: pre-wrap; color: #4b5563; line-height: 1.45; font-size: 0.82rem;"><?php echo htmlspecialchars($company_settings['bank_details']); ?></div>
                </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/document-footer-message.php'; ?>
        </div>
    </div>
</div>
