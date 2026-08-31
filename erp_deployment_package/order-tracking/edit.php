<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
if ($userRole !== 'procurement' && $userRole !== 'admin') {
    die("Access Denied");
}

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM order_tracking WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    die("Record not found");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Shipment - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .form-container {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn-submit {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn-cancel {
            background-color: #6c757d;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }
        .row {
            display: flex;
            gap: 15px;
        }
        .col {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Edit Shipment</h2>
        <form action="save.php" method="POST">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            
            <div class="row">
                <div class="col form-group">
                    <label>Client Name *</label>
                    <input type="text" name="client" value="<?= htmlspecialchars($row['client']) ?>" required>
                </div>
                <div class="col form-group">
                    <label>Mobile</label>
                    <input type="text" name="mobile" value="<?= htmlspecialchars($row['mobile']) ?>">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Shipment No. *</label>
                    <input type="text" name="shipment_no" value="<?= htmlspecialchars($row['shipment_no']) ?>" required>
                </div>
                <div class="col form-group">
                    <label>Shipment Status</label>
                    <select name="shipment_status">
                        <option value="Pending" <?= $row['shipment_status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="LOADED" <?= $row['shipment_status'] == 'LOADED' ? 'selected' : '' ?>>LOADED</option>
                        <option value="SHIPPED" <?= $row['shipment_status'] == 'SHIPPED' ? 'selected' : '' ?>>SHIPPED</option>
                        <option value="ARRIVED" <?= $row['shipment_status'] == 'ARRIVED' ? 'selected' : '' ?>>ARRIVED</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Tracking Status</label>
                    <input type="text" name="tracking_status" value="<?= htmlspecialchars($row['tracking_status']) ?>">
                </div>
                <div class="col form-group">
                    <label>Packages</label>
                    <input type="number" name="packages" value="<?= htmlspecialchars($row['packages']) ?>">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>CBM</label>
                    <input type="number" step="0.001" name="cbm" value="<?= htmlspecialchars($row['cbm']) ?>">
                </div>
                <div class="col form-group">
                    <label>Total Value</label>
                    <input type="number" step="0.01" name="total_value" value="<?= htmlspecialchars($row['total_value']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3"><?= htmlspecialchars($row['description']) ?></textarea>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Shipment Date</label>
                    <input type="date" name="shipment_date" value="<?= $row['shipment_date'] ?>">
                </div>
                <div class="col form-group">
                    <label>ETD</label>
                    <input type="date" name="etd" value="<?= $row['etd'] ?>">
                </div>
                <div class="col form-group">
                    <label>ETA</label>
                    <input type="date" name="eta" value="<?= $row['eta'] ?>">
                </div>
            </div>

            <div style="margin-top: 20px;">
                <a href="index.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-submit">Update Shipment</button>
            </div>
        </form>
    </div>
</body>
</html>

