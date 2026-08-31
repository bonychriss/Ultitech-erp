<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Finance' : 'Finance | Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href="<?= htmlspecialchars(function_exists('app_url') ? app_url('/assets/css/style.css') : '/assets/css/style.css') ?>?v=<?= time() ?>" rel="stylesheet">
    <style>
        @media (max-width: 768px) {
            .employee-header .header-content { position: relative; min-height: 48px; }
            .employee-header .header-left { position: absolute; top: 8px; left: 8px; z-index: 5; gap: 0 !important; }
            .employee-header .header-left .btn { margin: 0 !important; }
        }
    </style>
    <?php if (!empty($sppdHeadMarkup)) { echo $sppdHeadMarkup . "\n"; } ?>
</head>
<body class="dashboard<?= !empty($bodyExtraClass) ? ' ' . htmlspecialchars((string) $bodyExtraClass, ENT_QUOTES, 'UTF-8') : '' ?>">
<?php
$__appBase = rtrim((string)(defined('APP_BASE_PATH') ? APP_BASE_PATH : ''), '/');
if (!isset($rootPath)) {
    $rootPath = $__appBase !== '' ? $__appBase . '/' : '/';
}
if (!isset($logoBase)) {
    $logoBase = $rootPath;
}
if (!isset($modulesLink) && function_exists('app_url')) {
    $modulesLink = app_url('/select-module.php');
}
// Budget module: hide core payment/voucher feed in the bell; keep in-app (system) notifications only.
$suppressHeaderPaymentVoucherNotifications = true;
include_once __DIR__ . '/../../../includes/header_employee.php';
?>
