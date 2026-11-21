<?php
$dnThBg = isset($dnThBg) ? (string) $dnThBg : '#ffd700';
$dnThColor = isset($dnThColor) ? (string) $dnThColor : '#000000';
?>
<style>
#delivery-note-content {
    --primary: #1e3a8a;
    --accent: #ffd700;
    --dn-th-bg: <?= htmlspecialchars($dnThBg, ENT_QUOTES, 'UTF-8') ?>;
    --dn-th-color: <?= htmlspecialchars($dnThColor, ENT_QUOTES, 'UTF-8') ?>;
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
    margin: 0 auto 1.25rem;
    background: var(--white);
    padding: 15mm 20mm;
    box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    box-sizing: border-box;
    position: relative;
    overflow: visible;
    display: flex;
    flex-direction: column;
}
#delivery-note-content .page-container:last-child {
    margin-bottom: 0;
}
#delivery-note-content .dn-body {
    flex: 0 0 auto;
}
#delivery-note-content .dn-qr-block {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    text-align: right;
    max-width: 280px;
    margin-left: auto;
    margin-top: 18px;
    flex-shrink: 0;
}
#delivery-note-content .dn-qr-frame {
    position: relative;
    width: 96px;
    height: 96px;
    flex-shrink: 0;
    background: #fff;
    padding: 8px;
    box-sizing: border-box;
}
#delivery-note-content .dn-qr-frame::before,
#delivery-note-content .dn-qr-frame::after {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    border: 2.5px solid #0f172a;
    pointer-events: none;
    box-sizing: border-box;
}
#delivery-note-content .dn-qr-frame .dn-qr-corner-tr,
#delivery-note-content .dn-qr-frame .dn-qr-corner-bl {
    position: absolute;
    width: 18px;
    height: 18px;
    border: 2.5px solid #0f172a;
    pointer-events: none;
    box-sizing: border-box;
}
#delivery-note-content .dn-qr-frame::before {
    top: 0;
    left: 0;
    border-right: 0;
    border-bottom: 0;
    border-top-left-radius: 10px;
}
#delivery-note-content .dn-qr-frame::after {
    bottom: 0;
    right: 0;
    border-left: 0;
    border-top: 0;
    border-bottom-right-radius: 10px;
}
#delivery-note-content .dn-qr-frame .dn-qr-corner-tr {
    top: 0;
    right: 0;
    border-left: 0;
    border-bottom: 0;
    border-top-right-radius: 10px;
}
#delivery-note-content .dn-qr-frame .dn-qr-corner-bl {
    bottom: 0;
    left: 0;
    border-right: 0;
    border-top: 0;
    border-bottom-left-radius: 10px;
}
#delivery-note-content .dn-qr-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    background: #fff;
    border: 0;
    border-radius: 0;
    padding: 0;
}
#delivery-note-content .dn-qr-copy {
    max-width: 140px;
}
#delivery-note-content .dn-qr-title {
    margin: 0;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.35;
    color: #334155;
}
#delivery-note-content .dn-qr-hint {
    display: none;
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

#delivery-note-content .header-top { margin-bottom: 18px; }
#delivery-note-content .right-ident-block { text-align: left; min-width: 200px; }
#delivery-note-content .company-info {
    max-width: 280px;
}
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
    line-height: 1.45;
}
#delivery-note-content .company-address {
    display: block;
    margin: 0 0 4px;
}
#delivery-note-content .company-address-line {
    display: block;
}
#delivery-note-content .logo-box img {
    max-height: 80px;
    width: auto;
}

#delivery-note-content .doc-title-section {
    padding-bottom: 0;
    margin-bottom: 0;
}
#delivery-note-content .dn-number {
    font-size: 24px;
    font-weight: 800;
    color: var(--primary);
    margin-bottom: 8px;
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
    table-layout: fixed;
    margin-bottom: 40px;
}
#delivery-note-content .items-table thead {
    display: table-header-group;
}
#delivery-note-content .items-table thead th {
    background: var(--dn-th-bg) !important;
    background-color: var(--dn-th-bg) !important;
    color: var(--dn-th-color) !important;
    padding: 10px;
    text-align: left;
    font-weight: normal;
    font-size: 9pt;
    text-transform: uppercase;
    border: none;
    border-bottom: 2px solid #000;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}
#delivery-note-content .items-table thead th.col-center {
    text-align: center;
}
#delivery-note-content .items-table tbody td {
    padding: 10px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
    color: #111;
}
#delivery-note-content .items-table tbody tr:last-child td {
    border-bottom: 1px solid #eee;
}
/* Keep each product row whole across page breaks (print / html2pdf). */
#delivery-note-content .items-table tr {
    page-break-inside: avoid;
    break-inside: avoid;
}
#delivery-note-content .col-center { text-align: center !important; }
#delivery-note-content .sku-cell { color: #111; font-weight: normal; }
#delivery-note-content .desc-cell { font-size: 0.85rem; color: #555; }
#delivery-note-content .received-cell {
    text-align: center;
    vertical-align: middle;
}
#delivery-note-content .received-fill {
    display: inline-block;
    min-width: 3.25rem;
    border-bottom: 1px solid #9ca3af;
    height: 1.1em;
    vertical-align: baseline;
    font-weight: normal;
}
#delivery-note-content .dn-item-img {
    max-width: 60px;
    max-height: 60px;
    width: 60px;
    height: 60px;
    object-fit: contain;
    border-radius: 4px;
    display: block;
    margin: 0 auto;
}
#delivery-note-content .dn-item-img-fallback {
    width: 60px;
    height: 60px;
    background: #eee;
    border-radius: 4px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: #aaa;
}

#delivery-note-content .dn-closing {
    margin-top: auto;
    padding-top: 28px;
    flex: 0 0 auto;
    page-break-inside: avoid;
    break-inside: avoid;
}

#delivery-note-content .legal-caveat {
    margin-bottom: 16px;
    color: var(--text-muted);
    font-size: 13px;
}

#delivery-note-content .signature-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
    padding-top: 8px;
}
#delivery-note-content .sig-box {
    text-align: center;
    /* Same on screen and print so users can sign with room above the line */
    padding-top: 48px;
    min-height: 96px;
}
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
    #delivery-note-content {
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Keep the same sheet padding as on-screen so print matches the page view. */
    #delivery-note-content .page-container {
        box-shadow: none !important;
        padding: 12mm 15mm !important;
        width: 100% !important;
        max-width: 100% !important;
        min-height: 0 !important;
        height: auto !important;
        margin: 0 !important;
        border: none !important;
        overflow: visible !important;
        background: #fff !important;
        display: block !important;
    }

    /* Prefer keeping signatures + QR with remaining page-1 space; move as a block if needed. */
    #delivery-note-content .dn-closing {
        margin-top: 18mm !important;
        padding-top: 8mm !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-before: auto;
        break-before: auto;
    }

    #delivery-note-content .dn-qr-block {
        position: static !important;
        margin-top: 10mm !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    #delivery-note-content .dn-qr-frame {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    #delivery-note-content .header-top,
    #delivery-note-content .meta-strip,
    #delivery-note-content .dn-closing,
    #delivery-note-content .signature-section,
    #delivery-note-content .sig-box {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    #delivery-note-content .sig-box {
        padding-top: 22mm;
        min-height: 38mm;
    }

    #delivery-note-content .items-table {
        page-break-inside: auto;
    }

    #delivery-note-content .items-table tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    #delivery-note-content .items-table thead {
        display: table-header-group !important;
    }

    #delivery-note-content .items-table thead th {
        background: var(--dn-th-bg) !important;
        background-color: var(--dn-th-bg) !important;
        color: var(--dn-th-color) !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
}
</style>

<div class="page-container">
    <div class="dn-body">
    <div class="header-top flex justify-between items-start">
        <div class="company-info">
            <div class="logo-box" style="margin-bottom: 10px;">
                <?php if ($dnLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($dnLogoUrl) ?>"
                     alt="<?= htmlspecialchars($dnCompanyName) ?>"
                     style="max-height: 70px; max-width: 220px; object-fit: contain;">
                <?php endif; ?>
            </div>
            <p>
                <?php if ($dnCompanyAddress !== ''): ?>
                    <?php
                    $dnAddressLines = preg_split('/\r\n|\r|\n/', (string) $dnCompanyAddress) ?: [];
                    $dnAddressLines = array_values(array_filter(array_map('trim', $dnAddressLines), static fn($l) => $l !== ''));
                    if (count($dnAddressLines) <= 1) {
                        $flat = $dnAddressLines[0] ?? trim((string) $dnCompanyAddress);
                        $dnAddressLines = preg_split('/\s*,\s*/', $flat) ?: [$flat];
                        $dnAddressLines = array_values(array_filter(array_map('trim', $dnAddressLines), static fn($l) => $l !== ''));
                    }
                    ?>
                    <span class="company-address">
                        <?php foreach ($dnAddressLines as $dnAddressLine): ?>
                            <span class="company-address-line"><?= htmlspecialchars($dnAddressLine) ?></span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
                <?php if ($dnCompanyPhone !== ''): ?>
                    <span style="color: var(--text-muted); display: block;">Contact: <?= htmlspecialchars($dnCompanyPhone) ?></span>
                <?php endif; ?>
            </p>
        </div>

        <div class="right-ident-block">
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
            <strong>Salesperson:</strong> <?= htmlspecialchars($salespersonName !== '' ? $salespersonName : '?') ?>
        </div>
        <div class="meta-item">
            <strong>Ref No:</strong> PO-<?= htmlspecialchars(substr((string) $note['note_number'], 3)) ?>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <?php
            $dnThFill = 'background-color:' . $dnThBg . ';background:' . $dnThBg . ';color:' . $dnThColor
                . ';-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;';
            ?>
            <tr style="background-color: <?= htmlspecialchars($dnThBg, ENT_QUOTES, 'UTF-8') ?>;">
                <th style="width: 48px; <?= htmlspecialchars($dnThFill, ENT_QUOTES, 'UTF-8') ?>" class="col-center">S/N</th>
                <th style="width: 70px; <?= htmlspecialchars($dnThFill, ENT_QUOTES, 'UTF-8') ?>">PHOTO</th>
                <th style="<?= htmlspecialchars($dnThFill, ENT_QUOTES, 'UTF-8') ?>">DESCRIPTION</th>
                <th style="width: 70px; <?= htmlspecialchars($dnThFill, ENT_QUOTES, 'UTF-8') ?>" class="col-center">QTY</th>
                <th style="width: 12%; <?= htmlspecialchars($dnThFill, ENT_QUOTES, 'UTF-8') ?>" class="col-center">DELIVERED</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items !== []): ?>
                <?php $i = 0; foreach ($items as $item): ?>
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
                $descRaw = trim((string) ($item['description'] ?? ''));
                $unitRaw = trim((string) ($item['unit'] ?? ''));
                $qtyRaw = (float) ($item['qty'] ?? 0);
                ?>
                <tr>
                    <td class="col-center"><?= (int) ($i + 1) ?></td>
                    <td class="col-center">
                        <?php if ($itemImageUrl !== ''): ?>
                            <img class="dn-item-img" src="<?= htmlspecialchars($itemImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                 alt="Product"
                                 onerror="this.style.display='none';this.nextElementSibling&&(this.nextElementSibling.style.display='flex');">
                            <div class="dn-item-img-fallback" style="display:none;">No Image</div>
                        <?php else: ?>
                            <div class="dn-item-img-fallback">No Image</div>
                        <?php endif; ?>
                    </td>
                    <td class="desc-cell">
                        <?= nl2br(htmlspecialchars($descRaw)) ?>
                        <?php if ($unitRaw !== ''): ?>
                            <div style="margin-top: 4px; font-size: 11px; color: #6b7280;">Unit: <?= htmlspecialchars($unitRaw) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="col-center"><?= htmlspecialchars(number_format($qtyRaw, 2)) ?></td>
                    <td class="col-center received-cell"><span class="received-fill" aria-hidden="true">&nbsp;</span></td>
                </tr>
                <?php $i++; endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center" style="padding: 40px; color: var(--text-muted);">No items found in this delivery note.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="legal-caveat italic text-center">
        "Received the above goods in good order and condition."
    </div>

    <?php if (!empty($publicBrand['website'])): ?>
    <?php
        $dnWebsiteHref = trim((string) $publicBrand['website']);
        if ($dnWebsiteHref !== '' && !preg_match('#^https?://#i', $dnWebsiteHref)) {
            $dnWebsiteHref = 'https://' . ltrim($dnWebsiteHref, '/');
        }
        $dnWebsiteLabel = preg_replace('#^https?://#i', '', (string) $publicBrand['website']);
        $dnWebsiteLabel = rtrim((string) $dnWebsiteLabel, '/');
    ?>
    <div style="margin-top: 28px; text-align: center; color: var(--text-muted); font-size: 11px;">
        Visit our full range website at
        <a href="<?= htmlspecialchars($dnWebsiteHref, ENT_QUOTES, 'UTF-8') ?>"
           style="color: var(--primary); text-decoration: none; font-weight: 600;">
            <?= htmlspecialchars((string) $dnWebsiteLabel, ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
    <?php endif; ?>
    </div><!-- /.dn-body -->

    <div class="dn-closing">
        <div class="signature-section">
            <div class="sig-box">
                <?php
                $authorizedSigPath = trim((string) ($note['authorized_signature_path'] ?? ''));
                $authorizedSigUrl = $authorizedSigPath !== '' && function_exists('deliveries_signature_public_url')
                    ? deliveries_signature_public_url($authorizedSigPath)
                    : ($authorizedSigPath !== '' && function_exists('app_url') ? app_url($authorizedSigPath) : '');
                ?>
                <?php if ($authorizedSigUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($authorizedSigUrl, ENT_QUOTES, 'UTF-8') ?>" class="signature-img" alt="Salesperson Signature" crossorigin="anonymous">
                <?php endif; ?>
                <div class="sig-line"></div>
                <div class="sig-label">Salesperson Signature</div>
                <div class="sig-sub">(<?= htmlspecialchars($salespersonName !== '' ? $salespersonName : $dnCompanyName) ?>)</div>
            </div>
            <div class="sig-box">
                <?php
                $receiverSigPath = trim((string) ($note['receiver_signature_path'] ?? ''));
                $receiverSigUrl = $receiverSigPath !== '' && function_exists('deliveries_signature_public_url')
                    ? deliveries_signature_public_url($receiverSigPath)
                    : ($receiverSigPath !== '' && function_exists('app_url') ? app_url($receiverSigPath) : '');
                ?>
                <?php if ($receiverSigUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($receiverSigUrl, ENT_QUOTES, 'UTF-8') ?>" class="signature-img" alt="Receiver Signature" crossorigin="anonymous">
                <?php endif; ?>
                <div class="sig-line"></div>
                <div class="sig-label">Receiver's Name & Signature</div>
                <div class="sig-sub">(Client's Official Stamp)</div>
            </div>
        </div>

        <?php if (!empty($dnQrUrl) && !empty($dnFeedbackUrl)): ?>
        <div class="dn-qr-block">
            <div class="dn-qr-copy">
                <p class="dn-qr-title">Share your feedback and discover more products.</p>
            </div>
            <div class="dn-qr-frame" aria-hidden="false">
                <span class="dn-qr-corner-tr" aria-hidden="true"></span>
                <span class="dn-qr-corner-bl" aria-hidden="true"></span>
                <img class="dn-qr-img"
                     src="<?= htmlspecialchars((string) $dnQrUrl, ENT_QUOTES, 'UTF-8') ?>"
                     alt="Feedback QR code"
                     crossorigin="anonymous"
                     referrerpolicy="no-referrer">
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
