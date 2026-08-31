<?php
/**
 * Balances forms � same Lottie success overlay as Petty Cash (voucher-success.json).
 */
if (!isset($bal_lottie_flash_captured)) {
    $bal_lottie_flash_captured = true;
    $bal_lottie_pending = $_SESSION['bal_lottie_success'] ?? ($_SESSION['success'] ?? '');
    if (empty($bal_lottie_show_success) && $bal_lottie_pending !== '') {
        $bal_lottie_show_success = true;
        $bal_lottie_success_message = trim((string) $bal_lottie_pending);
        unset($_SESSION['bal_lottie_success'], $_SESSION['success']);
    }
}
$bal_lottie_show_success = !empty($bal_lottie_show_success);
$bal_lottie_success_message = trim((string) ($bal_lottie_success_message ?? ''));

$pc_lottie_show_success = $bal_lottie_show_success;
$pc_lottie_success_message = $bal_lottie_success_message !== ''
    ? $bal_lottie_success_message
    : 'Saved successfully!';
$pc_lottie_submit_message = 'Saving...';
$pc_lottie_form_id = 'transferForm';
$pc_lottie_form_ids = ['transferForm', 'balancesCoaForm', 'balancesCategoryForm'];
$pc_lottie_skip_validation = true;
$pc_lottie_redirect = (string) ($bal_lottie_redirect ?? '');
$pc_lottie_view_url = (string) ($bal_lottie_view_url ?? '');
$pc_lottie_okay_label = (string) ($bal_lottie_okay_label ?? 'Close');
$pc_lottie_view_label = (string) ($bal_lottie_view_label ?? 'View');
$pc_lottie_desktop_minimal = false;
$pc_lottie_mobile_only = isset($pc_lottie_mobile_only) ? (bool) $pc_lottie_mobile_only : true;

$balancesLottieBase = function_exists('app_url')
    ? rtrim((string) app_url('/modules/balances'), '/')
    : '/modules/balances';
$pc_lottie_json_href_override = $balancesLottieBase . '/assets/lottie/voucher-success.json';
$pc_lottie_lottie_file = dirname(__DIR__) . '/assets/lottie/voucher-success.json';

$pettyCashOverlay = dirname(__DIR__, 3) . '/erp/petty-cash/includes/lottie-success-overlay.php';
if (is_readable($pettyCashOverlay)) {
    include $pettyCashOverlay;
}
