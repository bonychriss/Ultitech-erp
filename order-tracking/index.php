<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
$userDept = $_SESSION['department'] ?? '';
// Allow employees to edit for now, alongside admin and procurement
$canEdit = ($userRole === 'procurement' || $userRole === 'admin' || $userRole === 'employee' || strtolower($userDept) === 'procurement');

// Fetch all tracking records
try {
    $stmt = $pdo->query("SELECT * FROM order_tracking ORDER BY created_at DESC");
    $trackings = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching records: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Tracking - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            overflow-x: hidden;
            /* Prevent body scroll if possible */
        }

        .main-header {
            background: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .company-logo {
            height: 40px;
            width: auto;
        }

        .page-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin: 0;
            border-left: 1px solid #ddd;
            padding-left: 15px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .tracking-container {
            padding: 0 15px;
            width: 100%;
            box-sizing: border-box;
        }

        .btn {
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
            border: 1px solid #007bff;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-outline {
            background-color: transparent;
            color: #6c757d;
            border: 1px solid #6c757d;
        }

        .btn-outline:hover {
            background-color: #6c757d;
            color: white;
        }

        /* Table Styles */
        .table-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            border: 1px solid #eef0f3;
            width: 100%;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            /* Auto layout for better spacing */
        }

        th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            padding: 12px 15px;
            /* Increased padding */
            border: 1px solid #dee2e6;
            white-space: nowrap;
            text-align: center;
        }

        td {
            padding: 10px 15px;
            /* Increased padding */
            border: 1px solid #dee2e6;
            color: #333;
            font-size: 0.85rem;
            /* Slightly larger font */
            vertical-align: middle;
            white-space: nowrap;
            text-align: center;
        }

        tr:last-child td {
            border-bottom: 1px solid #dee2e6;
            /* Keep border */
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        /* Status Badges */
        .status-badge {
            padding: 2px 6px;
            border-radius: 0;
            /* Sharp boxed edges */
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-loaded {
            background-color: #ffc107 !important;
            color: #000 !important;
        }

        /* Yellow */
        .status-shipped {
            background-color: #dc3545 !important;
            color: #fff !important;
        }

        /* Red */
        .status-arrived {
            background-color: #28a745 !important;
            color: #fff !important;
        }

        /* Green */
        .status-pending {
            background-color: #e2e3e5 !important;
            color: #383d41 !important;
        }

        .shipment-no {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #007bff;
            background: #e7f1ff;
            padding: 2px 4px;
            border-radius: 0;
            /* Sharp boxed edges */
            border: 1px solid #b8daff;
            font-size: 0.75rem;
        }

        .client-name {
            font-weight: 600;
            color: #2c3e50;
        }

        .action-icon-btn {
            display: inline-block;
            margin: 0 5px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .action-icon-btn i {
            display: inline-block;
            font-size: 16px;
            width: 18px;
            text-align: center;
        }

        /* Hover Effects */
        .view-icon:hover i {
            color: #0056b3 !important;
        }

        .edit-icon:hover i {
            color: #1e7e34 !important;
        }

        .delete-icon:hover i {
            color: #bd2130 !important;
        }
    </style>
</head>

<body>
    <header class="main-header">
        <div class="header-left">
            <img src="../assets/images/Untitled.jpg" alt="<?= COMPANY_NAME ?>" class="company-logo">
            <h1 class="page-title">Order Tracking System</h1>
        </div>
        <div class="header-actions">
            <a href="../select-module.php" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back
            </a>
            <?php if ($canEdit): ?>
                <a href="create.php" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Add Track
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="tracking-container">
        <div class="table-card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th title="Supplier Name">Supplier</th>
                            <th title="Contact Number">Contact</th>
                            <th title="Invoice Number">Invoice Number</th>
                            <th title="Tracking Status">Track</th>
                            <th title="Packages">Pkgs</th>
                            <th title="CBM">CBM</th>
                            <th title="Total Value">Value</th>
                            <th title="Description">Desc</th>
                            <th title="Shipment Date">Shipment Date</th>
                            <th title="Shipper">Shipper</th>
                            <th title="Estimated Cost of Clearance">ECC</th>
                            <th title="Estimated Time of Departure">ETD</th>
                            <th title="Estimated Time of Arrival">ETA</th>
                            <th title="Shipment Status">Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trackings as $row): ?>
                            <tr>
                                <td title="<?= htmlspecialchars($row['client']) ?>"><span
                                        class="client-name"><?= htmlspecialchars($row['client']) ?></span></td>
                                <td title="<?= htmlspecialchars($row['mobile']) ?>"><?= htmlspecialchars($row['mobile']) ?>
                                </td>
                                <td title="<?= htmlspecialchars($row['shipment_no']) ?>"><span
                                        class="shipment-no"><?= htmlspecialchars($row['shipment_no']) ?></span></td>
                                <td title="<?= htmlspecialchars($row['tracking_status']) ?>">
                                    <?= htmlspecialchars($row['tracking_status']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['packages']) ?></td>
                                <td><?= htmlspecialchars($row['cbm']) ?></td>
                                <td><?= htmlspecialchars($row['total_value']) ?></td>
                                <td title="<?= htmlspecialchars($row['description']) ?>">
                                    <?= htmlspecialchars($row['description']) ?>
                                </td>
                                <td><?= htmlspecialchars($row['shipment_date']) ?></td>
                                <td title="<?= htmlspecialchars($row['shipper'] ?? '') ?>">
                                    <?= htmlspecialchars($row['shipper'] ?? '') ?>
                                </td>
                                <td><?= htmlspecialchars($row['ecc'] ?? '0.00') ?></td>
                                <td><?= htmlspecialchars($row['etd']) ?></td>
                                <td><?= htmlspecialchars($row['eta']) ?></td>
                                <td>
                                    <?php
                                    $sClass = 'status-pending';
                                    $s = strtolower($row['shipment_status']);
                                    if (strpos($s, 'loaded') !== false)
                                        $sClass = 'status-loaded';
                                    elseif (strpos($s, 'shipped') !== false)
                                        $sClass = 'status-shipped';
                                    elseif (strpos($s, 'arrived') !== false)
                                        $sClass = 'status-arrived';
                                    ?>
                                    <span
                                        class="status-badge <?= $sClass ?>"><?= htmlspecialchars($row['shipment_status']) ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <a href="view.php?id=<?= $row['id'] ?>" class="action-icon-btn view-icon"
                                        title="View Full Details">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                            fill="none" stroke="#007bff" stroke-width="2" stroke-linecap="round"
                                            stroke-linejoin="round" class="feather feather-eye">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                            <circle cx="12" cy="12" r="3"></circle>
                                        </svg>
                                    </a>
                                    <?php
                                    $isArrived = (strpos(strtolower($row['shipment_status']), 'arrived') !== false);
                                    if ($canEdit && !$isArrived):
                                        ?>
                                        <a href="edit.php?id=<?= $row['id'] ?>" class="action-icon-btn edit-icon"
                                            title="Edit / Update Status">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                fill="none" stroke="#28a745" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" class="feather feather-edit">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                        </a>
                                        <a href="delete.php?id=<?= $row['id'] ?>" class="action-icon-btn delete-icon"
                                            onclick="return confirm('Are you sure?')" title="Delete">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                fill="none" stroke="#dc3545" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round" class="feather feather-trash-2">
                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                <path
                                                    d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                </path>
                                                <line x1="10" y1="11" x2="10" y2="17"></line>
                                                <line x1="14" y1="11" x2="14" y2="17"></line>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($trackings)): ?>
                            <tr>
                                <td colspan="13" style="text-align:center; padding: 20px; color: #6c757d;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"
                                        stroke-linejoin="round"
                                        style="margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto; opacity: 0.5;">
                                        <path
                                            d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                                        </path>
                                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                    </svg>
                                    No shipments found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if (isset($_SESSION['success_msg'])): ?>
        <div id="successToast" style="
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: #28a745; 
            color: white; 
            padding: 15px 25px; 
            border-radius: 4px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
            z-index: 9999; 
            display: flex; 
            align-items: center; 
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            ">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($_SESSION['success_msg']) ?></span>
        </div>
        <script>
            setTimeout(function () {
                const toast = document.getElementById('successToast');
                if (toast) {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => toast.remove(), 500);
                }
            }, 3000); // Hide after 3 seconds
        </script>
        <style>
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }

                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        </style>
        <?php unset($_SESSION['success_msg']); ?>
    <?php endif; ?>
</body>

</html>