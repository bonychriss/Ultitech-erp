<?php
/**
 * Shared invoice print/view layout (same sheets as invoices/print.php).
 *
 * Expected vars when included for sheets: $invoice, $items, $company_settings,
 * $currency, $invoiceLogoUrl, $invoiceOnly (bool).
 *
 * Or call sales_invoice_print_layout_html(...).
 */

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed> $company_settings
 * @param array<string, mixed> $options { invoice_only?: bool, include_styles?: bool, wrap_id?: string }
 */
function sales_invoice_print_layout_html(
    array $invoice,
    array $items,
    array $company_settings,
    string $currency,
    string $invoiceLogoUrl,
    array $options = []
): string {
    $invoiceOnly = !empty($options['invoice_only']);
    $includeStyles = array_key_exists('include_styles', $options) ? (bool) $options['include_styles'] : true;
    $wrapId = (string) ($options['wrap_id'] ?? 'invoice-content');
    $docFont = function_exists('sales_document_font_family_css')
        ? sales_document_font_family_css($company_settings)
        : 'Arial, sans-serif';

    // Keep each logical sheet within one printed A4 page (header + photos + wrapped descriptions).
    $first_page_limit = 6;
    $subsequent_page_limit = 9;
    $chunks = [];
    if (count($items) <= $first_page_limit) {
        $chunks[] = $items;
    } else {
        $chunks[] = array_slice($items, 0, $first_page_limit);
        $remaining = array_slice($items, $first_page_limit);
        if (!empty($remaining)) {
            $chunks = array_merge($chunks, array_chunk($remaining, $subsequent_page_limit));
        }
    }
    $total_pages = max(1, count($chunks));

    ob_start();
    if ($includeStyles) {
        ?>
<style id="ov-invoice-print-layout-css">
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?>.ov-invoice-print-layout {
    font-family: <?= $docFont ?>;
    font-size: 9.5pt;
    color: #000;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-container {
    width: min(210mm, 100%);
    max-width: 100%;
    min-width: 0;
    min-height: auto;
    padding: 8mm 12mm 10mm;
    margin: 3mm auto;
    background: #fff;
    box-shadow: 0 0 0.35cm rgba(0,0,0,0.12);
    position: relative;
    page-break-after: always;
    break-after: page;
    box-sizing: border-box;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-container:last-child {
    page-break-after: auto;
    break-after: auto;
}
@media screen {
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-container {
        min-height: 270mm;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-inner {
        display: flex;
        flex-direction: column;
        min-height: 252mm;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-page-footer,
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .document-footer-wrap {
        margin-top: auto;
    }
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-inner { width: 100%; }
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-header--continued { margin-bottom: 8px; }
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-title {
    font-size: 16pt;
    font-weight: bold;
    color: #333;
    margin: 0;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-page-footer {
    padding-top: 6px;
    margin-top: 8px;
    border-top: none;
    text-align: center;
    font-size: 9pt;
    color: #64748b;
    width: 100%;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .document-footer-wrap {
    padding-top: 8px;
    width: 100%;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 10px;
    table-layout: fixed;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table th {
    border-bottom: 2px solid #000;
    text-transform: uppercase;
    font-size: 9pt;
    font-weight: 400;
    background-color: #ffd700 !important;
    background: #ffd700 !important;
    color: #000 !important;
    padding: 4px 5px;
    vertical-align: middle;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table td {
    padding: 3px 5px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
    font-size: 9.5pt;
    word-break: normal;
    overflow-wrap: break-word;
    hyphens: manual;
    white-space: normal;
    line-height: 1.35;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table td.col-desc {
    width: 38%;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table .num,
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table th.num { text-align: right; }
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table td img {
    width: 56px !important;
    height: 56px !important;
    object-fit: cover;
    border-radius: 4px;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .totals-table td {
    padding: 2px 8px;
    font-size: 9.25pt;
    font-weight: 400;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .totals-table td:last-child {
    white-space: nowrap;
    overflow: visible;
    text-align: right;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .totals-table .grand-total {
    font-weight: 400 !important;
    border-top: 1px solid #ccc;
    font-size: 9.25pt !important;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .totals-area .totals-table tr:last-child td {
    font-weight: 400;
    font-size: 9.25pt;
    color: #b91c1c;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .invoice-paid-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-32deg);
    z-index: 6;
    pointer-events: none;
    user-select: none;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .invoice-paid-watermark span {
    display: inline-block;
    font-size: 5.5rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    line-height: 1;
    color: rgba(21, 128, 61, 0.2);
    text-transform: uppercase;
}
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .avoid-break,
#<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .document-footer-closing {
    page-break-inside: avoid;
    break-inside: avoid;
}
@media print {
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-container {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        min-height: auto !important;
        margin: 0 !important;
        box-shadow: none !important;
        padding: 0 !important;
        page-break-inside: avoid;
        break-inside: avoid-page;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .sheet-inner {
        display: block !important;
        min-height: 0 !important;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table {
        width: 100% !important;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table th,
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table td {
        word-break: normal !important;
        overflow-wrap: break-word !important;
        hyphens: manual !important;
        padding-top: 2px !important;
        padding-bottom: 2px !important;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table td img {
        width: 40px !important;
        height: 40px !important;
    }
    #<?= htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') ?> .o-table th {
        background: #ffd700 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>
        <?php
    }

    echo '<div id="' . htmlspecialchars($wrapId, ENT_QUOTES, 'UTF-8') . '" class="ov-invoice-print-layout">';
    echo '<div id="pdf-content">';

    foreach ($chunks as $page_idx => $page_items) {
        $is_last_page = ($page_idx === $total_pages - 1);
        ?>
        <div class="sheet-container">
            <?php if (($invoice['status'] ?? '') === 'paid' && $page_idx === 0): ?>
            <div class="invoice-paid-watermark" aria-hidden="true"><span>PAID</span></div>
            <?php endif; ?>

            <div class="sheet-inner">
                <div class="sheet-header<?= $page_idx === 0 ? '' : ' sheet-header--continued' ?>">
                    <h1 class="sheet-title">
                        Invoice # <?= htmlspecialchars((string) ($invoice['invoice_number'] ?? '')) ?>
                    </h1>
                    <div style="text-align: right; width: 60%;">
                        <?php if ($invoiceLogoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($invoiceLogoUrl) ?>" alt="Company Logo" style="max-height: <?= $page_idx === 0 ? '80px' : '44px' ?>; max-width: 220px; object-fit: contain; margin-bottom: <?= $page_idx === 0 ? '10px' : '0' ?>;" onerror="this.style.display='none'">
                        <?php endif; ?>

                        <?php if ($page_idx === 0): ?>
                        <div style="font-weight: bold; font-size: 1rem; color: #111; text-transform: uppercase;">
                            <?= htmlspecialchars((string) ($company_settings['company_name'] ?? '')) ?>
                        </div>
                        <div style="font-size: 0.9rem; color: #000; white-space: pre-line;"><?= htmlspecialchars((string) ($company_settings['company_address'] ?? '')) ?></div>
                        <?php if (!empty($company_settings['company_phone'])): ?>
                            <div style="font-size: 0.9rem; color: #000;">Phone: <?= htmlspecialchars((string) $company_settings['company_phone']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($company_settings['company_email'])): ?>
                            <div style="font-size: 0.9rem; color: #000;">Email: <?= htmlspecialchars((string) $company_settings['company_email']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($company_settings['company_tin'])): ?>
                            <div style="font-size: 0.9rem; color: #000;">TIN: <?= htmlspecialchars((string) $company_settings['company_tin']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($company_settings['company_vrn'])): ?>
                            <div style="font-size: 0.9rem; color: #000;">VRN: <?= htmlspecialchars((string) $company_settings['company_vrn']) ?></div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($page_idx === 0): ?>
                <div style="margin-bottom: 20px;">
                    <strong style="font-size: 1.1rem; color: #111;"><?= htmlspecialchars((string) ($invoice['company_name'] ?? $invoice['contact_person'] ?? '-')) ?></strong><br>
                    <?php
                        $receiverAddress = trim((string) ($invoice['address'] ?? ''));
                        if ($receiverAddress === '') {
                            $receiverAddress = trim((string) ($invoice['company_address'] ?? ''));
                        }
                    ?>
                    <?php if ($receiverAddress !== ''): ?>
                        <span style="color: #000;"><?= nl2br(htmlspecialchars($receiverAddress)) ?></span><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['email'])): ?>
                        <span style="color: #000;"><?= htmlspecialchars((string) $invoice['email']) ?></span><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['phone'])): ?>
                        <span style="color: #000;"><?= htmlspecialchars((string) $invoice['phone']) ?></span><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['tin'])): ?>
                        <span style="color: #000;">TIN: <?= htmlspecialchars((string) $invoice['tin']) ?></span><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['vrn'])): ?>
                        <span style="color: #000;">VRN: <?= htmlspecialchars((string) $invoice['vrn']) ?></span>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 10px; margin-bottom: 10px; border: 1px solid #000; display: flex; text-align: left;">
                    <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                        <div style="font-weight: bold; font-size: 0.8rem;">Invoice Date</div>
                        <div style="font-size: 0.85rem;"><?= !empty($invoice['invoice_date']) ? date('d/m/Y', strtotime((string) $invoice['invoice_date'])) : '-' ?></div>
                    </div>
                    <div style="flex: 1; padding: 4px 8px; border-right: 1px solid #ccc;">
                        <div style="font-weight: bold; font-size: 0.8rem;">Due Date</div>
                        <div style="font-size: 0.85rem;"><?= !empty($invoice['due_date']) ? date('d/m/Y', strtotime((string) $invoice['due_date'])) : '-' ?></div>
                    </div>
                    <div style="flex: 1; padding: 4px 8px;">
                        <div style="font-weight: bold; font-size: 0.8rem;">Salesperson</div>
                        <div style="font-size: 0.85rem;"><?= htmlspecialchars((string) ($invoice['salesperson'] ?? '-')) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <table class="o-table">
                    <colgroup>
                        <col style="width: 64px;">
                        <col style="width: 24%;">
                        <col style="width: 38%;">
                        <col style="width: 12%;">
                        <col style="width: 16%;">
                    </colgroup>
                    <thead>
                        <tr style="background-color: #ffd700;">
                            <th style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Photo</th>
                            <th style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Product</th>
                            <th style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Description</th>
                            <th class="num" style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Quantity</th>
                            <th class="num" style="background-color: #ffd700; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Unit Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($page_items as $item): ?>
                            <?php
                                $pid = (int) ($item['product_id'] ?? 0);
                                $img = $item['main_image'] ?? '';
                                $thumb = $page_idx === 0 ? 44 : 40;
                            ?>
                            <tr>
                                <td style="padding: 3px 5px;">
                                    <?php if ($pid > 0):
                                        $imgUrl = function_exists('sales_order_item_image_url')
                                            ? sales_order_item_image_url($item, 'medium')
                                            : (function_exists('app_url')
                                                ? app_url('/stock/product_image.php?product_id=' . $pid . '&size=medium&file=' . rawurlencode((string) $img))
                                                : '');
                                    ?>
                                        <img src="<?= htmlspecialchars((string) $imgUrl, ENT_QUOTES, 'UTF-8') ?>"
                                             alt="Product"
                                             style="width: <?= (int) $thumb ?>px; height: <?= (int) $thumb ?>px; object-fit: cover; border-radius: 4px;"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div style="display: none; width: <?= (int) $thumb ?>px; height: <?= (int) $thumb ?>px; background: #eee; border-radius: 4px; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                    <?php else: ?>
                                        <div style="width: <?= (int) $thumb ?>px; height: <?= (int) $thumb ?>px; background: #eee; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #aaa;">No Image</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 3px 5px;"><?= htmlspecialchars((string) ($item['product_name'] ?? '')) ?></td>
                                <td class="col-desc" style="padding: 3px 5px;">
                                    <?php
                                    $desc = trim((string) ($item['description'] ?? ''));
                                    $desc = str_replace(["\xC2\xAD", "\u{00AD}", '&shy;'], '', $desc);
                                    if ($desc !== '' && !empty($item['product_code'])) {
                                        $desc = preg_replace('/\s*\[' . preg_quote((string) $item['product_code'], '/') . '\]\s*$/u', '', $desc);
                                    }
                                    echo htmlspecialchars((string) $desc);
                                    ?>
                                </td>
                                <td class="num" style="padding: 4px 6px;"><?= htmlspecialchars((string) ($item['quantity'] ?? '')) ?></td>
                                <td class="num" style="padding: 4px 6px;"><?= number_format((float) ($item['unit_price'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($is_last_page): ?>
                <div class="totals-area" style="display: flex; justify-content: flex-end; margin-top: 5px;">
                    <table class="totals-table">
                        <tr>
                            <td>Untaxed Amount:</td>
                            <td><?= htmlspecialchars($currency) . ' ' . number_format((float) ($invoice['subtotal'] ?? 0), 2) ?></td>
                        </tr>
                        <?php if ((float) ($invoice['discount_amount'] ?? 0) > 0): ?>
                        <tr>
                            <td>Discount:</td>
                            <td>-<?= htmlspecialchars($currency) . ' ' . number_format((float) $invoice['discount_amount'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Taxes:</td>
                            <td><?= htmlspecialchars($currency) . ' ' . number_format((float) ($invoice['tax_amount'] ?? 0), 2) ?></td>
                        </tr>
                        <?php if ((float) ($invoice['shipping_charges'] ?? 0) > 0): ?>
                        <tr>
                            <td>Shipping:</td>
                            <td><?= htmlspecialchars($currency) . ' ' . number_format((float) $invoice['shipping_charges'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="grand-total">Total:</td>
                            <td class="grand-total"><?= htmlspecialchars($currency) . ' ' . number_format((float) ($invoice['total_amount'] ?? 0), 2) ?></td>
                        </tr>
                        <tr>
                            <td style="padding-top: 6px;">Amount Due:</td>
                            <td style="padding-top: 6px; font-weight: 400;"><?= htmlspecialchars($currency) . ' ' . number_format((float) ($invoice['balance_due'] ?? 0), 2) ?></td>
                        </tr>
                    </table>
                </div>

                <?php if (!empty($company_settings['bank_details'])): ?>
                <div style="margin-top: 20px; text-align: left; width: 60%;">
                    <div style="font-weight: bold; margin-bottom: 4px; border-bottom: 1px solid #eee; display: inline-block;">Bank Transfer Details</div>
                    <div style="white-space: pre-wrap; color: #555; font-size: 0.85rem; line-height: 1.4;"><?= htmlspecialchars((string) $company_settings['bank_details']) ?></div>
                    <?php if (!empty($company_settings['company_website'])): ?>
                    <div style="margin-top: 8px; font-size: 0.85rem;">
                        Visit our website at <a href="<?= htmlspecialchars((string) $company_settings['company_website']) ?>" target="_blank" rel="noreferrer" style="text-decoration: none; color: #008784; font-weight: bold;"><?= htmlspecialchars((string) $company_settings['company_website']) ?></a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($invoice['notes'])): ?>
                <div style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px;">
                    <strong>Terms & Notes:</strong><br>
                    <?= nl2br(htmlspecialchars((string) $invoice['notes'])) ?>
                </div>
                <?php endif; ?>

                <div class="document-footer-wrap avoid-break">
                    <?php include dirname(__DIR__, 2) . '/includes/document-footer-message.php'; ?>
                </div>
                <?php endif; ?>

                <?php if ($total_pages > 1): ?>
                <div class="sheet-page-footer avoid-break">
                    Page <?= (int) ($page_idx + 1) ?> of <?= (int) $total_pages ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    echo '</div></div>';
    return (string) ob_get_clean();
}
