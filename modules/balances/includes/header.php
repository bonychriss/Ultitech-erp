<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Balances' : 'Balances | Staff'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">
    <?php if (function_exists('renderSystemFontHeadMarkup')) {
        renderSystemFontHeadMarkup();
    } ?>

    <style>
        @media (max-width: 768px) {
            .employee-header .header-content { position: relative; min-height: 48px; }
            .employee-header .header-left { position: absolute; top: 8px; left: 8px; z-index: 5; gap: 0 !important; }
            .employee-header .header-left .btn { margin: 0 !important; }
        }
    </style>
    <?php if (!empty($tlHeadMarkup)) { echo $tlHeadMarkup . "\n"; } ?>
    <?php if (!empty($ldHeadMarkup)) { echo $ldHeadMarkup . "\n"; } ?>
    <?php if (!empty($tfHeadMarkup)) { echo $tfHeadMarkup . "\n"; } ?>
    <?php if (!empty($rcHeadMarkup)) { echo $rcHeadMarkup . "\n"; } ?>
</head>
<body class="dashboard<?= !empty($bodyExtraClass) ? ' ' . htmlspecialchars((string) $bodyExtraClass, ENT_QUOTES, 'UTF-8') : '' ?>">
<?php
// Capture the success flash before header_employee's global handler fires a SweetAlert toast.
// The balances Lottie overlay (footer) consumes this instead.
if (!empty($_SESSION['success']) && empty($_SESSION['bal_lottie_success'])) {
    $_SESSION['bal_lottie_success'] = (string) $_SESSION['success'];
    unset($_SESSION['success']);
}

$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';

$headerEmployeePath = __DIR__ . '/../../../includes/header_employee.php';
if (!is_file($headerEmployeePath)) {
    $headerEmployeePath = __DIR__ . '/../../../../includes/header_employee.php';
}
if (is_file($headerEmployeePath)) {
    include_once $headerEmployeePath;
} else {
    error_log('balances header: header_employee.php not found from ' . __DIR__);
}
?>
