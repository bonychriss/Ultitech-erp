<?php
/**
 * Second-page terms/footer block for Ultimate standard documents (invoice, order, quotation).
 *
 * Expects: $company_settings, optional $docFontStack, $salesDocTermsNumber,
 * $salesDocTermsTypeLabel, $salesDocTermsSignatureUrl
 */
if (!function_exists('sales_document_has_settings_second_page')
    || !sales_document_has_settings_second_page($company_settings ?? [])) {
    return;
}

$sections = sales_document_settings_second_page_sections($company_settings ?? []);
if ($sections === []) {
    return;
}

$docFontStack = $docFontStack ?? (function_exists('sales_document_font_family_css')
    ? sales_document_font_family_css($company_settings ?? [])
    : "'Arima', Arial, sans-serif");
$salesDocTermsNumber = trim((string) ($salesDocTermsNumber ?? ''));
$salesDocTermsTypeLabel = trim((string) ($salesDocTermsTypeLabel ?? 'Document'));
$salesDocTermsSignatureUrl = trim((string) ($salesDocTermsSignatureUrl ?? ''));
$brandingLogoUrl = $brandingLogoUrl ?? (function_exists('getCompanyLogoUrl') ? getCompanyLogoUrl() : '');
?>
<div class="sheet-container doc-terms-sheet" style="font-family: <?= $docFontStack ?>;">
    <div class="sheet doc-terms-sheet-inner" style="display: flex; flex-direction: column;">
        <table style="width: 100%; margin-bottom: 24px; border-collapse: collapse;">
            <tr>
                <td style="vertical-align: top;">
                    <h1 class="sheet-title" style="margin: 0; font-size: 16pt; color: #1E272E;">Terms &amp; conditions</h1>
                    <?php if ($salesDocTermsNumber !== ''): ?>
                        <div style="margin-top: 6px; font-size: 10pt; color: #64748b;">
                            <?= htmlspecialchars($salesDocTermsTypeLabel) ?> #<?= htmlspecialchars($salesDocTermsNumber) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <?php if ($brandingLogoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars((string) $brandingLogoUrl, ENT_QUOTES, 'UTF-8') ?>"
                             alt="Company Logo"
                             crossorigin="anonymous"
                             style="max-height: 60px; margin-bottom: 8px;"
                             onerror="this.style.display='none'">
                    <?php endif; ?>
                    <div style="font-weight: bold; font-size: 10pt; color: #111; text-transform: uppercase;">
                        <?= htmlspecialchars((string) ($company_settings['company_name'] ?? '')) ?>
                    </div>
                </td>
            </tr>
        </table>

        <?php foreach ($sections as $section): ?>
            <div class="doc-terms-section" style="margin-bottom: 18px; page-break-inside: avoid;">
                <div style="font-weight: 700; font-size: 10pt; text-transform: uppercase; color: #111827; margin-bottom: 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px;">
                    <?= htmlspecialchars($section['title']) ?>
                </div>
                <div style="white-space: pre-wrap; color: #374151; line-height: 1.55; font-size: 10pt;">
                    <?= nl2br(htmlspecialchars($section['body'])) ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($salesDocTermsSignatureUrl !== ''): ?>
            <div style="display: flex; justify-content: flex-end; margin-top: auto; padding-top: 32px;">
                <div style="text-align: center; min-width: 180px;">
                    <img src="<?= htmlspecialchars($salesDocTermsSignatureUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Signature"
                         crossorigin="anonymous"
                         style="max-height: 70px; max-width: 180px; object-fit: contain;"
                         onerror="this.style.display='none'">
                    <div style="border-top: 1px solid #cbd5e1; margin-top: 8px; padding-top: 6px; font-weight: 700; font-size: 10pt; text-transform: uppercase; color: #334155;">
                        Authorized Signature
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
