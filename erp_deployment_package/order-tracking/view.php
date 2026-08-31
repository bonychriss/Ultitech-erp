<?php
require_once '../includes/functions.php';
requireLogin();

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
    <title>Shipment Details - <?= htmlspecialchars($row['shipment_no']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: white;
            padding: 20px;
        }
        .print-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 20px;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .header img {
            height: 60px;
        }
        .header-text {
            flex: 1;
        }
        .company-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #000;
            margin-bottom: 3px;
        }
        .document-title {
            font-size: 1.8rem;
            color: #000;
            font-weight: 700;
            font-family: 'Comic Sans MS', cursive, sans-serif;
            letter-spacing: 1px;
        }
        .shipment-header {
            background: white;
            color: #000;
            padding: 15px 0;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #000;
        }
        .shipment-no {
            font-size: 1.2rem;
            font-weight: 600;
            color: #000;
        }
        .shipment-status {
            padding: 0;
            border-radius: 0;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            color: #000;
            background: transparent;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .details-table td {
            padding: 10px 15px;
            border: 1px solid #000;
            vertical-align: top;
        }
        .details-table td:first-child {
            font-weight: 600;
            width: 40%;
            background: #f5f5f5;
        }
        .details-table td:last-child {
            width: 60%;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            color: #666;
            font-size: 0.85rem;
        }
        .no-print {
            margin-bottom: 20px;
            text-align: left;
        }
        .btn {
            display: inline-block;
            padding: 5px 12px;
            margin: 0 5px 0 0;
            text-decoration: none;
            border-radius: 0;
            font-weight: 500;
            font-size: 0.85rem;
            border: 2px solid #000;
            background: white;
            color: #000;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .btn:hover {
            background: #000;
            color: white;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .print-container {
                box-shadow: none;
                padding: 20px;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <a href="index.php" class="btn">← Back to List</a>
        <button onclick="window.print()" class="btn">🖨 Print</button>
    </div>

    <div class="print-container">
        <div class="header">
            <img src="../assets/images/Untitled.jpg" alt="Company Logo">
            <div class="header-text">
                <div class="document-title">Order Tracking System</div>
            </div>
        </div>

        <div class="shipment-header">
            <div class="shipment-no">Shipment: <?= htmlspecialchars($row['shipment_no']) ?></div>
            <div>
                <span class="shipment-status"><?= htmlspecialchars($row['shipment_status']) ?></span>
            </div>
        </div>

        <table class="details-table">
            <tr>
                <td>Client Name</td>
                <td><?= htmlspecialchars($row['client']) ?></td>
            </tr>
            <tr>
                <td>Mobile</td>
                <td><?= htmlspecialchars($row['mobile']) ?></td>
            </tr>
            <tr>
                <td>Tracking Status</td>
                <td><?= htmlspecialchars($row['tracking_status']) ?></td>
            </tr>
            <tr>
                <td>Packages</td>
                <td><?= htmlspecialchars($row['packages']) ?></td>
            </tr>
            <tr>
                <td>CBM (Volume)</td>
                <td><?= htmlspecialchars($row['cbm']) ?></td>
            </tr>
            <tr>
                <td>Total Value</td>
                <td><?= htmlspecialchars($row['total_value']) ?></td>
            </tr>
            <tr>
                <td>Shipment Date</td>
                <td><?= htmlspecialchars($row['shipment_date']) ?></td>
            </tr>
            <tr>
                <td>ETD (Estimated Departure)</td>
                <td><?= htmlspecialchars($row['etd']) ?></td>
            </tr>
            <tr>
                <td>ETA (Estimated Arrival)</td>
                <td><?= htmlspecialchars($row['eta']) ?></td>
            </tr>
            <tr>
                <td>Created At</td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
            <?php if (!empty($row['description'])): ?>
            <tr>
                <td>Description / Goods Details</td>
                <td><?= nl2br(htmlspecialchars($row['description'])) ?></td>
            </tr>
            <?php endif; ?>
        </table>


        <div class="footer">
            <p>Generated on <?= date('Y-m-d H:i:s') ?></p>
            <p>ULTIMATE GENERAL TRADING - Order Tracking System</p>
        </div>
    </div>
</body>
</html>
