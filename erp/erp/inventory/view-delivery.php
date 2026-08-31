<?php require_once '../../includes/functions.php';
global $pdo;
$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT d.*, c.name as customer_name, c.address as customer_address 
                       FROM erp_delivery_orders d 
                       JOIN erp_customers c ON d.customer_id = c.id 
                       WHERE d.id = ?");
$stmt->execute([$id]);
$delivery = $stmt->fetch();
if (!$delivery)
    die("Delivery not found");

// Fetch Moves
$stmtMoves = $pdo->prepare("SELECT m.*, p.name as product_name, p.unit 
                            FROM erp_stock_moves m 
                            JOIN erp_products p ON m.product_id = p.id 
                            WHERE m.delivery_order_id = ?");
$stmtMoves->execute([$id]);
$moves = $stmtMoves->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Delivery <?= htmlspecialchars($delivery['delivery_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        /* Reusing Odoo styles... simplified for brevity */
        :root {
            --odoo-brand: #714B67;
            --odoo-action: #008784;
        }

        body {
            background: #f0f2f5;
            font-family: sans-serif;
            margin: 0;
        }

        .page-wrapper {
            margin-left: 220px;
            padding: 20px;
        }

        .sheet {
            background: white;
            padding: 40px;
            border: 1px solid #ccc;
            max-width: 900px;
            margin: 20px auto;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            cursor: pointer;
            border-radius: 4px;
            font-weight: bold;
        }

        .btn-primary {
            background: var(--odoo-brand);
            color: white;
        }

        .btn-action {
            background: var(--odoo-action);
            color: white;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            background: #eee;
            font-size: 0.8rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-badge.done {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.draft {
            background: #e2e3e5;
            color: #383d41;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border-bottom: 1px solid #eee;
            padding: 10px;
            text-align: left;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <main class="page-wrapper">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <div>
                <a href="../sales/view-sales-order.php?id=<?= $delivery['sales_order_id'] ?>"
                    style="text-decoration:none; color:#666;">&larr; Back to Order</a>
            </div>
            <div>
                <?php if ($delivery['status'] === 'draft'): ?>
                    <button onclick="validateDelivery(<?= $id ?>)" class="btn btn-action">Validate</button>
                <?php endif; ?>
                <span class="status-badge <?= $delivery['status'] ?>"><?= $delivery['status'] ?></span>
            </div>
        </div>

        <div class="sheet">
            <h1><?= htmlspecialchars($delivery['delivery_number']) ?></h1>
            <p><strong>Customer:</strong> <?= htmlspecialchars($delivery['customer_name']) ?></p>
            <p><strong>Date:</strong> <?= date('d/m/Y', strtotime($delivery['date'])) ?></p>

            <h3>Operations</h3>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Demand</th>
                        <th>Reserved</th>
                        <th>Done</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($moves as $move): ?>
                        <tr>
                            <td><?= htmlspecialchars($move['product_name']) ?></td>
                            <td><?= $move['quantity'] ?>     <?= $move['unit'] ?></td>
                            <td><?= $move['status'] === 'reserved' ? $move['quantity'] : '0.00' ?></td>
                            <td><?= $move['status'] === 'done' ? $move['quantity'] : '0.00' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
    <script>
        async function validateDelivery(id) {
            if (!confirm('Validate this delivery? Logic: Deduct Stock & Post COGS.')) return;

            const formData = new FormData();
            formData.append('action', 'validate_delivery');
            formData.append('id', id);

            // We need an API for this. Let's assume erp/api/inventory.php
            try {
                const response = await fetch('../api/inventory.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    alert('Delivery Validated!');
                    window.location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (e) { alert('Error: ' + e.message); }
        }
    </script>
</body>

</html>