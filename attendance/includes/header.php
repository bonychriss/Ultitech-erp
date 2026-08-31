<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Attendance' : 'Attendance | Staff Portal'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php
    $__attRoot = function_exists('app_url') ? rtrim(app_url('/'), '/') . '/' : '/';
    $__attStockCss = function_exists('app_url') ? app_url('/stock/assets/css/style.css') : '/stock/assets/css/style.css';
    $__attAppCss = function_exists('app_url') ? app_url('/assets/css/style.css') : '/assets/css/style.css';
    ?>
    <link href="<?= htmlspecialchars($__attStockCss, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars($__attAppCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= time() ?>" rel="stylesheet">
    <?php if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>

    <style>
        .stock-container { width: 100%; }
        body.dashboard .layout-main-wrapper { align-items: stretch; }
        body.dashboard .layout-main-wrapper > .flex-grow-1 { min-height: 0; display: flex; flex-direction: column; }
        body.dashboard main.main-content { flex: 1 1 auto; min-height: 0; overflow: visible !important; }
        main.main-content #root { width: 100%; }
    </style>
</head>
<body class="dashboard<?= !empty($bodyExtraClass) ? ' ' . htmlspecialchars((string) $bodyExtraClass, ENT_QUOTES, 'UTF-8') : '' ?>">
<?php
$rootPath = $__attRoot;
$logoBase = $rootPath;
$modulesLink = function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php';
$hideHeaderCompanyBranding = true;

include_once __DIR__ . '/../../includes/header_employee.php';
?>
