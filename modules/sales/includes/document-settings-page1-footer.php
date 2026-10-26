<?php
/**
 * Compact terms + signature for Ultimate standard documents (stays on page 1).
 * Payment details are already printed above — not repeated here.
 *
 * Expects: $company_settings, optional $signatureUrl / $salesDocTermsSignatureUrl
 */
$company_settings = is_array($company_settings ?? null) ? $company_settings : [];
$sigUrl = trim((string) ($salesDocTermsSignatureUrl ?? $signatureUrl ?? ''));

$footerBits = [];
$map = [
    'spare_terms' => 'Terms & conditions',
    'spare_validity' => 'Validity',
    'invoice_remarks' => 'Remarks',
    'spare_return_policy' => 'Return policy',
    'spare_thanks_note' => 'Thank you',
];
foreach ($map as $key => $title) {
    $body = trim((string) ($company_settings[$key] ?? ''));
    if ($body !== '' && !(function_exists('sales_document_is_blank_footer_text') && sales_document_is_blank_footer_text($body))) {
        $footerBits[] = ['title' => $title, 'body' => $body];
    }
}

if ($footerBits === [] && $sigUrl === '') {
    return;
}
?>
<div class="doc-page1-footer avoid-break" style="margin-top: 14px; clear: both;">
    <?php if ($footerBits !== []): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px 16px; margin-bottom: 12px;">
            <?php foreach ($footerBits as $bit): ?>
                <div style="page-break-inside: avoid;">
                    <div style="font-weight: 700; font-size: 0.78rem; text-transform: uppercase; color: #111827; margin-bottom: 3px;">
                        <?= htmlspecialchars((string) $bit['title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div style="white-space: pre-wrap; color: #4b5563; line-height: 1.4; font-size: 0.78rem;">
                        <?= nl2br(htmlspecialchars((string) $bit['body'], ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($sigUrl !== ''): ?>
        <div style="display: flex; justify-content: flex-end; margin-top: 8px;">
            <div style="text-align: center; min-width: 150px;">
                <img src="<?= htmlspecialchars($sigUrl, ENT_QUOTES, 'UTF-8') ?>"
                     alt="Signature"
                     crossorigin="anonymous"
                     style="max-height: 48px; max-width: 150px; object-fit: contain;"
                     onerror="this.style.display='none'">
                <div style="border-top: 1px solid #cbd5e1; margin-top: 4px; padding-top: 4px; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; color: #334155;">
                    Authorized Signature
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
