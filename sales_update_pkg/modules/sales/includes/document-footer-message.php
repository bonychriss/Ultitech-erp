<?php
/**
 * Closing line for invoices & quotations (Sales → Settings → Document closing message).
 * Expects $company_settings from sales_settings.
 */
if (!isset($company_settings) || !is_array($company_settings)) {
    return;
}
$__dfm = trim((string) ($company_settings['document_footer_message'] ?? ''));
if ($__dfm === '') {
    return;
}
?>
<div class="document-footer-closing avoid-break" style="margin-top: 14px; text-align: center; font-size: 13px; line-height: 1.5; font-weight: 700; letter-spacing: 0.02em; color: #15803d; clear: both;">
    <?= nl2br(htmlspecialchars($__dfm, ENT_QUOTES, 'UTF-8')) ?>
</div>
