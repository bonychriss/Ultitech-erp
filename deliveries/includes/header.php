<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Deliveries' : 'Deliveries | Staff Portal'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= time() ?>" rel="stylesheet">

    <style>
        .deliveries-module-wrap { width: 100%; }
        @media (max-width: 768px) {
            .employee-header .header-content { position: relative; min-height: 48px; }
            .employee-header .header-left { position: absolute; top: 8px; left: 8px; z-index: 5; gap: 0 !important; }
            .employee-header .header-left .btn { margin: 0 !important; }
        }
    </style>
</head>
<body class="dashboard">
<?php
$rootPath = '/';
$logoBase = '/';
$modulesLink = '/select-module.php';

include_once __DIR__ . '/../../includes/header_employee.php';
?>
