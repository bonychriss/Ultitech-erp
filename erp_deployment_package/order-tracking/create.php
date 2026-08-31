<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
if ($userRole !== 'procurement' && $userRole !== 'admin') {
    die("Access Denied");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Shipment - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }
        .main-header {
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
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
        
        .form-container {
            max-width: 900px;
            margin: 0 auto 40px;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #eef0f3;
        }
        
        .form-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 0.95rem;
            transition: border-color 0.15s ease-in-out;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.2s;
            border: none;
        }
        .btn-submit {
            background-color: #007bff;
            color: white;
        }
        .btn-submit:hover {
            background-color: #0056b3;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        .btn-outline {
            background-color: transparent;
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 6px 12px;
            font-size: 0.9rem;
        }
        .btn-outline:hover {
            background-color: #6c757d;
            color: white;
        }

        .row {
            display: flex;
            gap: 20px;
        }
        .col {
            flex: 1;
        }
        
        .form-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
            <a href="index.php" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to List
            </a>
        </div>
    </header>

    <div class="form-container">
        <div class="form-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 5px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            Add New Shipment
        </div>
        
        <form action="save.php" method="POST">
            <div class="row">
                <div class="col form-group">
                    <label>Client Name <span style="color:red">*</span></label>
                    <input type="text" name="client" required placeholder="Enter client name">
                </div>
                <div class="col form-group">
                    <label>Mobile Number</label>
                    <input type="text" name="mobile" placeholder="Enter mobile number">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Shipment No. <span style="color:red">*</span></label>
                    <input type="text" name="shipment_no" required placeholder="e.g. SHP-2025-001">
                </div>
                <div class="col form-group">
                    <label>Shipment Status</label>
                    <select name="shipment_status">
                        <option value="Pending">Pending</option>
                        <option value="LOADED">LOADED</option>
                        <option value="SHIPPED">SHIPPED</option>
                        <option value="ARRIVED">ARRIVED</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Tracking Status</label>
                    <input type="text" name="tracking_status" value="NA" placeholder="Current location or status">
                </div>
                <div class="col form-group">
                    <label>No. of Packages</label>
                    <input type="number" name="packages" value="0" min="0">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>CBM (Volume)</label>
                    <input type="number" step="0.001" name="cbm" value="0.000" min="0">
                </div>
                <div class="col form-group">
                    <label>Total Value</label>
                    <input type="number" step="0.01" name="total_value" value="0.00" min="0">
                </div>
            </div>

            <div class="form-group">
                <label>Description / Goods Details</label>
                <textarea name="description" rows="3" placeholder="Enter details about the goods..."></textarea>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Shipment Date</label>
                    <input type="date" name="shipment_date">
                </div>
                <div class="col form-group">
                    <label>ETD (Estimated Departure)</label>
                    <input type="date" name="etd">
                </div>
                <div class="col form-group">
                    <label>ETA (Estimated Arrival)</label>
                    <input type="date" name="eta">
                </div>
            </div>

            <div class="form-actions">
                <a href="index.php" class="btn btn-cancel">Cancel</a>
                <button type="submit" class="btn btn-submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    Save Shipment
                </button>
            </div>
        </form>
    </div>
</body>
</html>

