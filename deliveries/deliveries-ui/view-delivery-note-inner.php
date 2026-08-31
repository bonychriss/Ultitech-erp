<style>
#delivery-note-content {
    --primary: #1e3a8a;
    --accent: #eab308;
    --text-main: #1f2937;
    --text-muted: #6b7280;
    --border-light: #f3f4f6;
    --border-med: #e5e7eb;
    --white: #ffffff;
    --ov-doc-font-stack: <?= htmlspecialchars($docFontStack, ENT_QUOTES, 'UTF-8') ?>;
    font-family: var(--ov-doc-font-stack);
    color: var(--text-main);
    font-size: 13px;
    line-height: 1.5;
    -webkit-print-color-adjust: exact;
}

#delivery-note-content .page-container {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    background: var(--white);
    padding: 15mm 20mm;
    box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    box-sizing: border-box;
    position: relative;
}

#delivery-note-content .flex { display: flex; }
#delivery-note-content .justify-between { justify-content: space-between; }
#delivery-note-content .items-center { align-items: center; }
#delivery-note-content .items-start { align-items: flex-start; }
#delivery-note-content .text-right { text-align: right; }
#delivery-note-content .text-center { text-align: center; }
#delivery-note-content .font-bold { font-weight: 700; }
#delivery-note-content .uppercase { text-transform: uppercase; }
#delivery-note-content .italic { font-style: italic; }

#delivery-note-content .header-top { margin-bottom: 40px; }
#delivery-note-content .company-info h1 {
    color: var(--primary);
    font-size: 22px;
    margin: 0 0 5px 0;
    font-weight: 800;
    letter-spacing: -0.5px;
}
#delivery-note-content .company-info p {
    color: var(--text-muted);
    margin: 0;
    font-size: 12px;
}
#delivery-note-content .logo-box img {
    max-height: 80px;
    width: auto;
}

#delivery-note-content .doc-title-section {
    padding-bottom: 15px;
    margin-bottom: 30px;
}
#delivery-note-content .dn-number {
    font-size: 24px;
    font-weight: 800;
    color: var(--primary);
    margin-bottom: 15px;
}

#delivery-note-content .info-card h3 {
    font-size: 11px;
    color: var(--text-muted);
    margin: 0 0 8px 0;
    letter-spacing: 1px;
    padding-bottom: 5px;
}
#delivery-note-content .info-content { font-size: 14px; }
#delivery-note-content .info-content .name {
    font-weight: 700;
    font-size: 16px;
    display: block;
    margin-bottom: 4px;
}

#delivery-note-content .meta-strip {
    background: #fafafb;
    border: 1px solid var(--border-med);
    border-radius: 4px;
    padding: 12px 20px;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
#delivery-note-content .meta-item {
    font-size: 12px;
    color: var(--text-main);
}
#delivery-note-content .meta-item strong {
    color: var(--text-muted);
    margin-right: 5px;
}

#delivery-note-content .items-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 40px;
}
#delivery-note-content .items-table thead th {
    background: var(--accent);
    color: var(--text-main);
    padding: 12px 15px;
    text-align: left;
    font-weight: 700;
    font-size: 12px;
    border: none;
}
#delivery-note-content .items-table tbody td {
    padding: 12px 15px;
    border-bottom: 1px solid var(--border-light);
    vertical-align: middle;
}
#delivery-note-content .items-table tbody tr:last-child td {
    border-bottom: 1px solid var(--border-med);
}
#delivery-note-content .col-center { text-align: center !important; }
#delivery-note-content .sku-cell { color: var(--text-muted); font-family: monospace; }
#delivery-note-content .received-cell { font-family: initial; letter-spacing: 2px; color: var(--border-med); }

#delivery-note-content .legal-caveat {
    margin-bottom: 60px;
    color: var(--text-muted);
    font-size: 13px;
}

#delivery-note-content .signature-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
}
#delivery-note-content .sig-box { text-align: center; }
#delivery-note-content .sig-line {
    border-top: 1.5px solid var(--text-main);
    margin-bottom: 10px;
}
#delivery-note-content .sig-label {
    font-weight: 700;
    font-size: 13px;
}
#delivery-note-content .sig-sub {
    font-size: 11px;
    color: var(--text-muted);
}
#delivery-note-content .signature-img {
    max-width: 180px;
    max-height: 80px;
    margin-bottom: -15px;
}

@media print {
    #delivery-note-content .page-container {
        box-shadow: none;
        padding: 10mm 15mm;
        width: 100%;
        margin: 0;
        border: none;
    }
}
</style>

<div class="page-container">
    <div class="header-top flex justify-between items-start">
        <div class="company-info">
            <div class="logo-box" style="margin-bottom: 10px;">
                <?php if ($dnLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($dnLogoUrl) ?>"
                     alt="<?= htmlspecialchars($dnCompanyName) ?>"
                     style="max-height: 70px; max-width: 220px; object-fit: contain;">
                <?php endif; ?>
            </div>
            <div style="font-size: 12px; font-weight: 700; color: #000; margin-bottom: 5px;"><?= htmlspecialchars(strtoupper($dnCompanyName)) ?></div>
            <p>
                <?php if ($dnCompanyAddress !== ''): ?>
                    <?= nl2br(htmlspecialchars($dnCompanyAddress)) ?><br>
                <?php endif; ?>
                <?php if ($dnCompanyPhone !== ''): ?>
                    <span style="color: var(--text-muted);">Contact: <?= htmlspecialchars($dnCompanyPhone) ?></span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="doc-title-section flex justify-between items-start" style="margin-top: 20px;">
        <div></div>
        <div class="right-ident-block" style="text-align: left; min-width: 200px;">
            <div class="dn-number">#<?= htmlspecialchars((string) $note['note_number']) ?></div>
            <div class="info-card">
                <h3 class="uppercase" style="margin-bottom: 5px; font-size: 11px; color: var(--text-muted);">To</h3>
                <div class="info-content" style="font-size: 13px;">
                    <span class="name"><?= htmlspecialchars((string) ($note['customer_name'] ?? '')) ?></span>
                    <?= nl2br(htmlspecialchars((string) ($note['delivery_address'] ?? ''))) ?><br>
                    <span style="color: var(--text-muted);">Contact: <?= htmlspecialchars((string) ($note['customer_phone'] ?? '')) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="meta-strip">
        <div class="meta-item">
            <strong>Order Date:</strong> <?= date('d/m/Y', strtotime((string) $note['delivery_date'])) ?>
        </div>
        <div class="meta-item">
            <strong>Salesperson:</strong> <?= htmlspecialchars($salespersonName !== '' ? $salespersonName : '') ?>
        </div>
        <div class="meta-item">
            <strong>Ref No:</strong> PO-<?= htmlspecialchars(substr((string) $note['note_number'], 3)) ?>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 10%;">IMAGE</th>
                <?php if ($hasSkus): ?><th style="width: 15%;">SKU</th><?php endif; ?>
                <th style="width: <?= $hasSkus ? '30%' : '45%' ?>;">PRODUCT</th>
                <th style="width: 15%;" class="col-center">UNIT</th>
                <th style="width: 15%;" class="col-center">ORDERED</th>
                <th style="width: 15%;" class="col-center">DELIVERED</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items !== []): ?>
                <?php $i = 0; foreach ($items as $item): ?>
                <tr>
                    <td class="col-center">
                        <?php
                        $itemImageUrl = trim((string) ($item['image_url'] ?? ''));
                        if ($itemImageUrl === '' && !empty($item['product_id']) && !empty($item['main_image'])) {
                            if (function_exists('sales_load_stock_image_helpers')) {
                                sales_load_stock_image_helpers();
                            }
                            if (function_exists('stock_product_list_image_url')) {
                                $itemImageUrl = (string) stock_product_list_image_url(
                                    (int) $item['product_id'],
                                    (string) $item['main_image'],
                                    'medium'
                                );
                            } elseif (function_exists('sales_product_image_url')) {
                                $itemImageUrl = (string) sales_product_image_url(
                                    (int) $item['product_id'],
                                    (string) $item['main_image'],
                                    'medium'
                                );
                            } elseif (function_exists('app_url')) {
                                $itemImageUrl = app_url(
                                    'stock/product_image.php?' . http_build_query([
                                        'product_id' => (int) $item['product_id'],
                                        'size' => 'medium',
                                        'file' => basename((string) $item['main_image']),
                                    ])
                                );
                            }
                        }
                        ?>
                        <?php if ($itemImageUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($itemImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                 style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #eee;"
                                 alt="Item"
                                 onerror="this.style.display='none';this.nextElementSibling&&(this.nextElementSibling.style.display='flex');">
                            <div style="display:none;width: 40px; height: 40px; background: #f3f4f6; border-radius: 4px; margin: 0 auto; align-items: center; justify-content: center; color: #d1d5db; font-size: 10px;">IMG</div>
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 4px; margin: 0 auto; display: flex; align-items: center; justify-content: center; color: #d1d5db; font-size: 10px;">IMG</div>
                        <?php endif; ?>
                    </td>
                    <?php if ($hasSkus): ?>
                    <td class="sku-cell"><?= (!empty($item['sku']) && $item['sku'] !== '-') ? htmlspecialchars((string) $item['sku']) : 'S-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) ?></td>
                    <?php endif; ?>
                    <td class="font-bold"><?= htmlspecialchars((string) $item['description']) ?></td>
                    <td class="col-center"><?= htmlspecialchars((string) ($item['unit'] ?? 'pckge')) ?></td>
                    <td class="col-center font-bold"><?= htmlspecialchars((string) $item['qty']) ?></td>
                    <td class="col-center received-cell font-bold"><?= htmlspecialchars((string) $item['qty']) ?></td>
                </tr>
                <?php $i++; endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?= $hasSkus ? 6 : 5 ?>" class="text-center" style="padding: 40px; color: var(--text-muted);">No items found in this delivery note.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="legal-caveat italic text-center">
        "Received the above goods in good order and condition."
    </div>

    <div class="signature-section">
        <div class="sig-box">
            <?php if (!empty($note['authorized_signature_path'])): ?>
                <img src="<?= app_url($note['authorized_signature_path']) ?>" class="signature-img" alt="Authorized Signature">
            <?php endif; ?>
            <div class="sig-line"></div>
            <div class="sig-label">Authorized Signatory</div>
            <div class="sig-sub">(<?= htmlspecialchars($dnCompanyName) ?>)</div>
        </div>
        <div class="sig-box">
            <?php if (!empty($note['receiver_signature_path'])): ?>
                <img src="<?= app_url($note['receiver_signature_path']) ?>" class="signature-img" alt="Receiver Signature">
            <?php endif; ?>
            <div class="sig-line"></div>
            <div class="sig-label">Receiver's Name & Signature</div>
            <div class="sig-sub">(Client's Official Stamp)</div>
        </div>
    </div>

    <?php if (!empty($publicBrand['website'])): ?>
    <div style="margin-top: 50px; text-align: center; color: var(--text-muted); font-size: 11px;">
        Visit our website at <a href="<?= htmlspecialchars((string) $publicBrand['website']) ?>" style="color: var(--primary); text-decoration: none; font-weight: 600;"><?= htmlspecialchars(preg_replace('#^https?://#i', '', (string) $publicBrand['website'])) ?></a>
    </div>
    <?php endif; ?>
</div>
