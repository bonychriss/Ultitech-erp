<?php
/** @var array $invoice */
/** @var array $company_settings */
/** @var array $items */
$docFontStack = function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company_settings ?? [])
    : "'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif";
?>
<div class="sheet-container" style="font-family: <?= $docFontStack ?>;">
    <?php if (($invoice['status'] ?? '') === 'paid'): ?>
    <div class="invoice-paid-watermark" aria-hidden="true"><span>PAID</span></div>
    <?php endif; ?>

    <div class="sheet" style="display: flex; flex-direction: column; min-height: 297mm;">
        <table style="width: 100%; margin-bottom: 24px; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top;">
                    <h1 class="sheet-title" style="margin: 0; font-size: 20pt; color: #1E272E;">Invoice # <?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <div class="quot-company-block">
                        <?php
                            if (!isset($brandingLogoUrl) || $brandingLogoUrl === '') {
                                $brandingLogoUrl = function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : '';
                            }
                        ?>
                        <?php if (!empty($brandingLogoUrl)): ?>
                        <img src="<?php echo htmlspecialchars($brandingLogoUrl); ?>" alt="Company Logo" style="max-height: 80px; margin-bottom: 10px;">
                        <?php endif; ?>
                        <h5 class="mb-1 fw-bold" style="margin: 0; font-size: 11pt; color: #111; font-weight: bold; text-transform: uppercase;"><?php echo htmlspecialchars($company_settings['company_name']); ?></h5>
                        <p class="text-muted mb-0" style="margin: 0; font-size: 9.5pt; color: #000;">
                            <?php echo htmlspecialchars($company_settings['company_address']); ?>
                        </p>
                        <?php if(!empty($company_settings['company_phone'])): ?>
                            <p class="text-muted mb-0" style="margin: 0; font-size: 9.5pt; color: #000;">Phone: <?php echo htmlspecialchars($company_settings['company_phone']); ?></p>
                        <?php endif; ?>
                        <?php if(!empty($company_settings['company_email'])): ?>
                            <p class="text-muted mb-0" style="margin: 0; font-size: 9.5pt; color: #000;">Email: <?php echo htmlspecialchars($company_settings['company_email']); ?></p>
                        <?php endif; ?>
                        <?php if(!empty($company_settings['company_tin'])): ?>
                            <p class="text-muted mb-0" style="margin: 0; font-size: 9.5pt; color: #000;">TIN: <?php echo htmlspecialchars($company_settings['company_tin']); ?></p>
                        <?php endif; ?>
                        <?php if(!empty($company_settings['company_vrn'])): ?>
                            <p class="text-muted mb-0" style="margin: 0; font-size: 9.5pt; color: #000;">VRN: <?php echo htmlspecialchars($company_settings['company_vrn']); ?></p>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>


        <div style="display: flex; flex-direction: column; flex: 1;">
            <div class="form-grid">
                <div class="left-col" style="width: 100%;">
                    <div class="form-value" style="font-size: 1rem; line-height: 1.4; color: #000;">
                        <strong style="font-size: 1.25rem; color: #000; font-weight: bold;"><?php echo htmlspecialchars((string) ($invoice['company_name'] ?? $invoice['contact_person'] ?? '-')); ?></strong><br>
                        <?php
                            $receiverAddress = trim((string) ($invoice['address'] ?? ''));
                            if ($receiverAddress === '') {
                                $receiverAddress = trim((string) ($invoice['company_address'] ?? ''));
                            }
                        ?>
                        <?php if ($receiverAddress !== ''): ?>
                            <span><?php echo nl2br(htmlspecialchars($receiverAddress)); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($invoice['email'])): ?>
                            <span><?php echo htmlspecialchars($invoice['email']); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($invoice['phone'])): ?>
                            <span><?php echo htmlspecialchars($invoice['phone']); ?></span><br>
                        <?php endif; ?>
                        <?php 
                        $custTaxId = $invoice['customer_tax_id'] ?? '';
                        $custTin = $invoice['tin'] ?? '';
                        $custVrn = $invoice['vrn'] ?? '';
                        if (empty($custTin) && empty($custVrn) && !empty($custTaxId)) {
                            if (strpos($custTaxId, '/') !== false) {
                                $parts = explode('/', $custTaxId);
                                $custTin = trim($parts[0]);
                                $custVrn = trim($parts[1]);
                            } else {
                                $custTin = $custTaxId;
                            }
                        }
                        ?>
                        <?php if (!empty($custTin)): ?>
                            <span>TIN: <?php echo htmlspecialchars($custTin); ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($custVrn)): ?>
                            <span>VRN: <?php echo htmlspecialchars($custVrn); ?> Tax ID: <?php echo htmlspecialchars($custTaxId); ?></span><br>
                        <?php elseif (!empty($custTaxId) && strpos($custTaxId, '/') === false): ?>
                            <span>TIN: <?php echo htmlspecialchars($custTaxId); ?></span><br>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <table style="width: 100%; border: 1px solid #000; border-collapse: collapse; margin: 10px 0;">
                <tr>
                    <td style="width: 33.33%; padding: 8px; border-right: 1px solid #ccc; vertical-align: top;">
                        <div style="font-weight: bold; font-size: 8.5pt; text-transform: uppercase; color: #000; margin-bottom: 4px;">Invoice Date</div>
                        <div style="font-size: 11pt; color: #111;"><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></div>
                    </td>
                    <td style="width: 33.33%; padding: 8px; border-right: 1px solid #ccc; vertical-align: top;">
                        <div style="font-weight: bold; font-size: 8.5pt; text-transform: uppercase; color: #000; margin-bottom: 4px;">Due Date</div>
                        <div style="font-size: 11pt; color: #111;"><?php echo !empty($invoice['due_date']) ? date('d/m/Y', strtotime((string) $invoice['due_date'])) : '-'; ?></div>
                    </td>
                    <td style="width: 33.33%; padding: 8px; vertical-align: top;">
                        <div style="font-weight: bold; font-size: 8.5pt; text-transform: uppercase; color: #000; margin-bottom: 4px;">Salesperson</div>
                        <div style="font-size: 11pt; color: #111;"><?php echo htmlspecialchars($invoice['salesperson'] ?? '-'); ?></div>
                    </td>
                </tr>
            </table>


            <div class="notebook">
                <table class="o-table" style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                    <thead>
                        <tr style="background-color: #ffcc00;">
                            <th style="width: 70px; padding: 10px; text-align: left; border-bottom: 2px solid #000; font-weight: normal;">PHOTO</th>
                            <th style="width: 14%; padding: 10px; text-align: left; border-bottom: 2px solid #000; font-weight: normal;">PRODUCT</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #000; font-weight: normal;">DESCRIPTION</th>
                            <th style="width: 70px; padding: 10px; text-align: center; border-bottom: 2px solid #000; font-weight: normal;">QTY</th>
                            <th style="width: 12%; padding: 10px; text-align: right; border-bottom: 2px solid #000; font-weight: normal;">UNIT PRICE</th>
                            <th style="width: 12%; padding: 10px; text-align: right; border-bottom: 2px solid #000; font-weight: normal;">SUBTOTAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td style="width: 70px; text-align: center; padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle;">

                                    <?php 
                                        $pid = (int)($item['product_id'] ?? 0);
                                        $img = $item['main_image'] ?? '';
                                        if ($pid > 0):
                                            $imgUrl = function_exists('sales_order_item_image_url')
                                                ? sales_order_item_image_url($item, 'medium')
                                                : app_url('/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode((string) $img));
                                    ?>
                                        <img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                                             alt="Product" 
                                             style="max-width: 60px; max-height: 60px; border-radius: 4px;" 
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div style="display: none; width: 60px; height: 60px; background: #eee; border-radius: 4px; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                    <?php else: ?>
                                        <div style="width: 60px; height: 60px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle;">
                                    <div style="font-weight: normal; color: #111;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                </td>
                                <td style="padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle; font-size: 0.85rem; color: #555;">
                                    <?php
                                        $desc = trim((string)($item['description'] ?? ($item['notes'] ?? '')));
                                        if ($desc !== '' && !empty($item['product_code'])) {
                                            $desc = preg_replace('/\s*\[' . preg_quote((string)$item['product_code'], '/') . '\]\s*$/u', '', $desc);
                                        }
                                        echo nl2br(htmlspecialchars($desc));
                                    ?>
                                </td>
                                <td style="width: 70px; text-align: center; padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle;">

                                    <?php echo number_format($item['quantity'], 2); ?>
                                </td>
                                <td style="text-align: right; padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle;">
                                    <?php echo number_format($item['unit_price'], 2); ?>
                                </td>
                                <td style="text-align: right; padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle;">
                                    <?php echo number_format((float) ($item['line_total'] ?? ((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0))), 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                <table class="o-totals-table">
                    <tr class="o-totals-muted">
                        <td>Untaxed Amount:</td>
                        <td><?php echo ($invoice['currency'] ?? 'TZS') . ' ' . number_format($invoice['subtotal'], 2); ?></td>
                    </tr>
                    <?php if (isset($invoice['discount_amount']) && (float)$invoice['discount_amount'] > 0): ?>
                    <tr class="o-totals-muted">
                        <td>Discount:</td>
                        <td>-<?php echo ($invoice['currency'] ?? 'TZS') . ' ' . number_format((float) $invoice['discount_amount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="o-totals-muted">
                        <td>Taxes:</td>
                        <td><?php echo ($invoice['currency'] ?? 'TZS') . ' ' . number_format($invoice['tax_amount'], 2); ?></td>
                    </tr>
                    <?php if (isset($invoice['shipping_charges']) && (float)$invoice['shipping_charges'] > 0): ?>
                    <tr class="o-totals-muted">
                        <td>Shipping:</td>
                        <td><?php echo ($invoice['currency'] ?? 'TZS') . ' ' . number_format((float) $invoice['shipping_charges'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="o-totals-grand">
                        <td>Total:</td>
                        <td><?php echo ($invoice['currency'] ?? 'TZS') . ' ' . number_format($invoice['total_amount'], 2); ?></td>
                    </tr>
                    <tr class="o-totals-due">
                        <td>Amount Due:</td>
                        <td><?php
                            $balanceDue = $invoice['balance_due'] ?? $invoice['total_amount'] ?? 0;
                            echo ($invoice['currency'] ?? 'TZS') . ' ' . number_format((float) $balanceDue, 2);
                        ?></td>
                    </tr>
                </table>
            </div>
            <?php if (!empty($company_settings['bank_details'])): ?>
                <div class="quot-bank-details" style="margin-top: 18px;">
                    <div style="font-weight: 700; margin-bottom: 6px; color: #111827;">Payment details</div>
                    <div style="white-space: pre-wrap; color: #4b5563; line-height: 1.5;"><?php echo htmlspecialchars($company_settings['bank_details']); ?></div>
                </div>
            <?php endif; ?>
            <?php include __DIR__ . '/../includes/document-footer-message.php'; ?>
        </div>
    </div>
</div>
<?php
$salesDocTermsNumber = (string) ($invoice['invoice_number'] ?? '');
$salesDocTermsTypeLabel = 'Invoice';
$salesDocTermsSignatureUrl = $signatureUrl ?? '';
include __DIR__ . '/../includes/document-settings-second-page.php';
?>
