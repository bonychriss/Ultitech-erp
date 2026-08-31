<?php
require_once '../../includes/functions.php';
requireLogin();

global $pdo;
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT dn.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone 
                       FROM erp_delivery_notes dn 
                       JOIN erp_customers c ON dn.customer_id = c.id 
                       WHERE dn.id = ?");
$stmt->execute([$id]);
$delivery = $stmt->fetch();

if (!$delivery) die("Delivery note not found");

$items = $pdo->prepare("SELECT di.*, p.name as product_name, p.sku 
                        FROM erp_delivery_items di 
                        JOIN erp_products p ON di.product_id = p.id 
                        WHERE di.delivery_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Note <?= htmlspecialchars($delivery['delivery_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; padding: 20px; }
        .toolbar { background: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-radius: 8px; margin-bottom: 20px; max-width: 800px; margin: 0 auto 20px auto; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .delivery-container { background: white; max-width: 800px; margin: 0 auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .company-header { text-align: center; margin-bottom: 40px; border-bottom: 2px solid #f1f3f4; padding-bottom: 20px; }
        .company-header h1 { margin-bottom: 8px; color: #1a73e8; }
        .delivery-title { text-align: center; font-size: 1.5rem; font-weight: 600; margin-bottom: 30px; text-transform: uppercase; letter-spacing: 1px; }
        .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 30px; }
        .detail-group { margin-bottom: 12px; }
        .detail-label { color: #5f6368; font-size: 0.875rem; font-weight: 500; }
        .detail-value { color: #202124; font-weight: 600; }
        .table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        .table th { text-align: left; padding: 12px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 0.875rem; text-transform: uppercase; }
        .table td { padding: 12px; border-bottom: 1px solid #f1f3f4; }
        .text-right { text-align: right; }
        .btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: none; display: inline-block; color: white; background: #1a73e8; }
        .btn-secondary { background: #5f6368; }
        .signature-section { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 60px; }
        .signature-box { border-top: 2px solid #202124; padding-top: 8px; text-align: center; }
        @media print { body { background: white; padding: 0; } .toolbar { display: none; } .delivery-container { box-shadow: none; padding: 0; margin: 0; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <div><a href="delivery-notes.php" style="text-decoration: none; color: #202124;">â† Back to Deliveries</a></div>
        <div><button class="btn btn-secondary" onclick="window.print()">Print</button></div>
    </div>
    
    <div class="delivery-container">
        <div class="company-header">
            <h1><?= COMPANY_NAME ?></h1>
            <p>123 Business Street, City, Country</p>
        </div>
        
        <div class="delivery-title">Delivery Note</div>
        
        <div class="details-grid">
            <div>
                <div class="detail-group">
                    <div class="detail-label">Delivery Note #</div>
                    <div class="detail-value"><?= htmlspecialchars($delivery['delivery_number']) ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Date</div>
                    <div class="detail-value"><?= date('d M Y', strtotime($delivery['date'])) ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Driver</div>
                    <div class="detail-value"><?= htmlspecialchars($delivery['driver_name'] ?? '-') ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Vehicle</div>
                    <div class="detail-value"><?= htmlspecialchars($delivery['vehicle_number'] ?? '-') ?></div>
                </div>
            </div>
            <div>
                <div class="detail-group">
                    <div class="detail-label">Customer</div>
                    <div class="detail-value"><?= htmlspecialchars($delivery['customer_name']) ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Shipping Address</div>
                    <div class="detail-value"><?= nl2br(htmlspecialchars($delivery['shipping_address'] ?? '-')) ?></div>
                </div>
            </div>
        </div>
        
        <table class="table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Batch #</th>
                    <th class="text-right">Quantity</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 500;"><?= htmlspecialchars($item['product_name']) ?></div>
                            <div style="font-size: 0.75rem; color: #5f6368;"><?= htmlspecialchars($item['sku']) ?></div>
                        </td>
                        <td style="font-family: monospace;"><?= htmlspecialchars($item['batch_number'] ?? '-') ?></td>
                        <td class="text-right"><?= number_format($item['quantity'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($delivery['notes']): ?>
            <div style="margin-top: 30px; padding: 16px; background: #f8f9fa; border-radius: 4px;">
                <strong>Notes:</strong>
                <p style="margin-top: 8px;"><?= nl2br(htmlspecialchars($delivery['notes'])) ?></p>
            </div>
        <?php endif; ?>
        
        <div class="signature-section">
            <div class="signature-box">
                <div class="detail-label">Delivered By</div>
                <div style="margin-top: 40px;">_______________________</div>
                <div style="margin-top: 8px; font-size: 0.875rem;">Driver Signature</div>
            </div>
            <div class="signature-box">
                <div class="detail-label">Received By</div>
                <div style="margin-top: 40px;">_______________________</div>
                <div style="margin-top: 8px; font-size: 0.875rem;">Customer Signature</div>
            </div>
        </div>
        
        <div style="margin-top: 40px; text-align: center; font-size: 0.75rem; color: #5f6368;">
            <p>Generated on <?= date('d M Y H:i') ?></p>
        </div>
    </div>
</body>
</html>

