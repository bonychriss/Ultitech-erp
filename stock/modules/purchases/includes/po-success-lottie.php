<?php
/**
 * PO create success — reuses supplier mobile Lottie overlay.
 */
$supplier_lottie_show = !empty($po_lottie_show);
$supplier_lottie_message = trim((string) ($po_lottie_message ?? 'Purchase Order created successfully!'));
$supplier_lottie_view_url = trim((string) ($po_lottie_view_url ?? ''));
$supplier_lottie_view_label = trim((string) ($po_lottie_view_label ?? 'View purchase order'));
$supplier_lottie_okay_label = trim((string) ($po_lottie_okay_label ?? 'Continue'));

require __DIR__ . '/../../suppliers/includes/supplier-success-lottie.php';
