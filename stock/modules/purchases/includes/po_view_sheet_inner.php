<?php
/** @var array $po */
/** @var array $company */
/** @var list<array<string, mixed>> $items */
/** @var string $companyLogoUrl */
/** @var string $userSignatureUrl */
/** @var string $userFullName */
/** @var callable $formatPoMoney */
/** @var callable $formatPoLineMoney */
/** @var string $noImageUrl */
/** @var string $displayPoNumber */
/** @var string $poCurrencyCode */

$displayPoNumber = (string) ($po['purchase_no'] ?? ('PO-' . ($po['id'] ?? '')));
$poCurrencyCode = strtoupper(trim((string) ($po['currency'] ?? 'USD')));
$docFontStack = function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company ?? [])
    : "'Arima', Arial, 'Helvetica Neue', Helvetica, sans-serif";

$resolveImage = static function (array $item): string {
    $imgPid = (int) ($item['image_product_id'] ?? 0);
    $imgFile = (string) ($item['product_image'] ?? '');
    if ($imgPid > 0 && function_exists('resolveStockPurchaseLineImageUrl')) {
        return resolveStockPurchaseLineImageUrl($imgPid, $imgFile);
    }
    return '';
};
?>
<div class="sheet-container" style="font-family: <?= htmlspecialchars($docFontStack, ENT_QUOTES, 'UTF-8') ?>; font-size: 13px;">
    <div class="sheet" style="display: flex; flex-direction: column; min-height: 297mm; font-family: inherit;">
        <div class="sheet-header-title quot-sheet-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
            <h1 class="sheet-title">
                Purchase Order #
                <?= htmlspecialchars($displayPoNumber) ?>
            </h1>
            <div class="quot-company-block" style="text-align: right;">
                <img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>"
                     alt="Company Logo"
                     crossorigin="anonymous"
                     style="max-height: 80px; margin-bottom: 10px;"
                     onerror="this.style.display='none'">
                <h5 class="mb-1 fw-bold"><?= htmlspecialchars((string) ($company['company_name'] ?? '')) ?></h5>
                <?php
                $fromAddress = trim((string) ($company['address'] ?? ''));
                $fromLocation = trim((string) ($company['company_location'] ?? $company['city'] ?? ''));
                $fromPhone = trim((string) ($company['phone'] ?? ''));
                $fromEmail = trim((string) ($company['email'] ?? ''));
                ?>
                <?php if ($fromAddress !== ''): ?>
                    <p class="text-muted mb-0" style="white-space: pre-line; color: #000;"><?= nl2br(htmlspecialchars($fromAddress)) ?></p>
                <?php endif; ?>
                <?php if ($fromLocation !== '' && strcasecmp($fromLocation, $fromAddress) !== 0): ?>
                    <p class="text-muted mb-0" style="color: #000;"><?= htmlspecialchars($fromLocation) ?></p>
                <?php endif; ?>
                <?php if ($fromPhone !== ''): ?>
                    <p class="text-muted mb-0" style="color: #000;">Phone: <?= htmlspecialchars($fromPhone) ?></p>
                <?php endif; ?>
                <?php if ($fromEmail !== ''): ?>
                    <p class="text-muted mb-0" style="color: #000;">Email: <?= htmlspecialchars($fromEmail) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; flex: 1;">
            <div class="form-grid">
                <div class="left-col" style="width: 100%;">
                    <div class="form-value" style="font-size: 0.92rem; line-height: 1.35; color: #000;">
                        <strong style="font-size: 1.08rem; color: #000; font-weight: bold;">
                            <?= htmlspecialchars((string) ($po['supplier_name'] ?? 'Supplier')) ?>
                        </strong><br>
                        <?php if (!empty($po['contact_person'])): ?>
                            <span><?= htmlspecialchars((string) $po['contact_person']) ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($po['supplier_phone'])): ?>
                            <span><?= htmlspecialchars((string) $po['supplier_phone']) ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($po['supplier_email'])): ?>
                            <span><?= htmlspecialchars((string) $po['supplier_email']) ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($po['supplier_address'])): ?>
                            <span><?= nl2br(htmlspecialchars((string) $po['supplier_address'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="date-info-bar" style="margin-top: 10px; margin-bottom: 10px; border: 1px solid #000; display: flex; text-align: left;">
                <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                    <div style="font-weight: bold; font-size: 0.72rem;">PO Date</div>
                    <div style="font-size: 0.78rem;"><?= date('d/m/Y', strtotime((string) ($po['created_at'] ?? 'now'))) ?></div>
                </div>
                <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                    <div style="font-weight: bold; font-size: 0.72rem;">Payment Terms</div>
                    <div style="font-size: 0.78rem;"><?= htmlspecialchars((string) ($company['default_payment_terms'] ?? 'Net 30')) ?></div>
                </div>
                <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                    <div style="font-weight: bold; font-size: 0.72rem;">Expected Delivery</div>
                    <div style="font-size: 0.78rem;"><?= date('d/m/Y', strtotime((string) ($po['created_at'] ?? 'now') . ' +30 days')) ?></div>
                </div>
                <div style="flex: 1; padding: 4px 8px;">
                    <div style="font-weight: bold; font-size: 0.72rem;">Currency</div>
                    <div style="font-size: 0.78rem;"><?= htmlspecialchars($poCurrencyCode) ?></div>
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
                            <th class="num price-col" style="width: 14.5%;">Unit Price</th>
                            <th class="num price-col" style="width: 14.5%;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $itemIdx => $item):
                        $imgUrl = $resolveImage($item);
                        $lineTotal = (float) ($item['total_amount'] ?? ((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0)));
                        ?>
                        <tr>
                            <td class="num"><?= $itemIdx + 1 ?></td>
                            <td style="padding: 4px 6px;">
                                <?php if ($imgUrl !== ''): ?>
                                    <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>"
                                         alt=""
                                         style="width: 60px; height: 60px; object-fit: cover;"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div style="display: none; width: 60px; height: 60px; background: #eee; border-radius: 4px; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                <?php else: ?>
                                    <div style="width: 60px; height: 60px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($item['product_name'] ?? 'Item')) ?>
                                <?php if (!empty($item['product_code'])): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars((string) $item['product_code']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= nl2br(htmlspecialchars((string) ($item['product_desc'] ?? ''))) ?></td>
                            <td class="num"><?= number_format((float) ($item['quantity'] ?? 0), 2) ?></td>
                            <td class="num price-col"><?= $formatPoLineMoney((float) ($item['unit_price'] ?? 0)) ?></td>
                            <td class="num price-col"><?= $formatPoLineMoney($lineTotal) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                <table class="o-totals-table price-col" style="width: 300px;">
                    <tr><td>Subtotal</td><td class="num"><?= $formatPoMoney((float) ($po['subtotal'] ?? 0)) ?></td></tr>
                    <?php if ((float) ($po['discount_amount'] ?? 0) > 0): ?>
                    <tr><td>Discount</td><td class="num">-<?= $formatPoMoney((float) $po['discount_amount']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float) ($po['tax_amount'] ?? 0) > 0): ?>
                    <tr><td>Tax (<?= htmlspecialchars((string) ($po['tax_percentage'] ?? '0')) ?>%)</td><td class="num"><?= $formatPoMoney((float) $po['tax_amount']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand-total" style="border-top: 2px solid #000; font-weight: bold;">
                        <td>Total</td><td class="num"><?= $formatPoMoney((float) ($po['total_amount'] ?? 0)) ?></td>
                    </tr>
                </table>
            </div>

            <?php
            $terms = trim((string) ($po['terms_conditions'] ?? ''));
            if ($terms === '') {
                $terms = trim((string) ($company['terms_and_conditions'] ?? ''));
            }
            if ($terms !== ''):
            ?>
                <div style="margin-top: 24px;">
                    <div style="font-weight: 700; margin-bottom: 6px; color: #111827; font-size: 0.84rem;">Terms &amp; Conditions</div>
                    <div style="white-space: pre-wrap; color: #4b5563; line-height: 1.45; font-size: 0.82rem;"><?= htmlspecialchars($terms) ?></div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 48px; position: relative; border-top: 1px solid #000; padding-top: 8px; width: 260px;">
                <?php if ($userSignatureUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($userSignatureUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Signature"
                         crossorigin="anonymous"
                         style="position: absolute; top: -55px; left: 15px; max-height: 70px; mix-blend-mode: multiply;">
                <?php endif; ?>
                <div style="font-weight: bold;">Authorized Signature</div>
                <div style="font-size: 0.82rem; color: #4b5563;"><?= htmlspecialchars($userFullName !== '' ? $userFullName : 'System User') ?></div>
                <div style="font-size: 0.82rem; color: #4b5563;">Date: <?= date('M d, Y') ?></div>
            </div>
        </div>
    </div>
</div>
