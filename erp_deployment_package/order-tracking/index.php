<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
$canEdit = ($userRole === 'procurement' || $userRole === 'admin');

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
            overflow-x: hidden; /* Prevent body scroll if possible */
        }
        .main-header {
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
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
            table-layout: auto; /* Auto layout for better spacing */
        }
        th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            padding: 12px 15px; /* Increased padding */
            border: 1px solid #dee2e6;
            white-space: nowrap;
            text-align: center;
        }
        td {
            padding: 10px 15px; /* Increased padding */
            border: 1px solid #dee2e6;
            color: #333;
            font-size: 0.85rem; /* Slightly larger font */
            vertical-align: middle;
            white-space: nowrap;
            text-align: center;
        }
        tr:last-child td {
            border-bottom: 1px solid #dee2e6; /* Keep border */
        }
        tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 2px 6px;
            border-radius: 0; /* Sharp boxed edges */
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-loaded { background-color: #ffc107 !important; color: #000 !important; } /* Yellow */
        .status-shipped { background-color: #dc3545 !important; color: #fff !important; } /* Red */
        .status-arrived { background-color: #28a745 !important; color: #fff !important; } /* Green */
        .status-pending { background-color: #e2e3e5 !important; color: #383d41 !important; }

        .shipment-no {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #007bff;
            background: #e7f1ff;
            padding: 2px 4px;
            border-radius: 0; /* Sharp boxed edges */
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
        .action-icon-btn span {
            display: inline-block;
            width: 18px;
            height: 18px;
            position: relative;
        }
        
        /* Eye Icon */
        .icon-eye::before {
            content: 'ðŸ‘';
            font-size: 18px;
            color: #007bff;
        }
        .view-icon:hover .icon-eye::before {
            color: #0056b3;
        }
        
        /* Edit Icon */
        .icon-edit::before {
            content: 'âœ';
            font-size: 18px;
            color: #28a745;
        }
        .edit-icon:hover .icon-edit::before {
            color: #1e7e34;
        }
        
        /* Trash Icon */
        .icon-trash::before {
            content: 'ðŸ—‘';
            font-size: 18px;
            color: #dc3545;
        }
        .delete-icon:hover .icon-trash::before {
            color: #bd2130;
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
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back
            </a>
            <?php if ($canEdit): ?>
            <a href="create.php" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                New Shipment
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
                            <th title="Client Name">Client</th>
                            <th title="Mobile Number">Mobile</th>
                            <th title="Shipment Number">Shipment No.</th>
                            <th title="Shipment Status">Status</th>
                            <th title="Tracking Status">Track</th>
                            <th title="Packages">Pkgs</th>
                            <th title="CBM">CBM</th>
                            <th title="Total Value">Value</th>
                            <th title="Description">Desc</th>
                            <th title="Shipment Date">Shipment Date</th>
                            <th title="Estimated Time of Departure">ETD</th>
                            <th title="Estimated Time of Arrival">ETA</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trackings as $row): ?>
                        <tr>
                            <td title="<?= htmlspecialchars($row['client']) ?>"><span class="client-name"><?= htmlspecialchars($row['client']) ?></span></td>
                            <td title="<?= htmlspecialchars($row['mobile']) ?>"><?= htmlspecialchars($row['mobile']) ?></td>
                            <td title="<?= htmlspecialchars($row['shipment_no']) ?>"><span class="shipment-no"><?= htmlspecialchars($row['shipment_no']) ?></span></td>
                            <td>
                                <?php
                                $sClass = 'status-pending';
                                $s = strtolower($row['shipment_status']);
                                if (strpos($s, 'loaded') !== false) $sClass = 'status-loaded';
                                elseif (strpos($s, 'shipped') !== false) $sClass = 'status-shipped';
                                elseif (strpos($s, 'arrived') !== false) $sClass = 'status-arrived';
                                ?>
                                <span class="status-badge <?= $sClass ?>"><?= htmlspecialchars($row['shipment_status']) ?></span>
                            </td>
                            <td title="<?= htmlspecialchars($row['tracking_status']) ?>"><?= htmlspecialchars($row['tracking_status']) ?></td>
                            <td><?= htmlspecialchars($row['packages']) ?></td>
                            <td><?= htmlspecialchars($row['cbm']) ?></td>
                            <td><?= htmlspecialchars($row['total_value']) ?></td>
                            <td title="<?= htmlspecialchars($row['description']) ?>"><?= htmlspecialchars($row['description']) ?></td>
                            <td><?= htmlspecialchars($row['shipment_date']) ?></td>
                            <td><?= htmlspecialchars($row['etd']) ?></td>
                            <td><?= htmlspecialchars($row['eta']) ?></td>
                            <td style="text-align: right;">
                                <a href="view.php?id=<?= $row['id'] ?>" class="action-icon-btn view-icon" title="View Full Details">
                                    <span class="icon-eye"></span>
                                </a>
                                <?php if ($canEdit): ?>
                                <a href="edit.php?id=<?= $row['id'] ?>" class="action-icon-btn edit-icon" title="Edit / Update Status">
                                    <span class="icon-edit"></span>
                                </a>
                                <a href="delete.php?id=<?= $row['id'] ?>" class="action-icon-btn delete-icon" onclick="return confirm('Are you sure?')" title="Delete">
                                    <span class="icon-trash"></span>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($trackings)): ?>
                        <tr>
                            <td colspan="13" style="text-align:center; padding: 20px; color: #6c757d;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 10px; display: block; margin-left: auto; margin-right: auto; opacity: 0.5;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                                No shipments found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

