<?php require_once '../../includes/functions.php';
global $pdo;
$id = $_GET['id'] ?? 0;
// JOIN with Customers
$stmt = $pdo->prepare("SELECT s.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address 
                       FROM erp_sales_orders s 
                       JOIN erp_customers c ON s.customer_id = c.id 
                       WHERE s.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order)
    die("Sales Order not found");

// Fetch Items
$items = $pdo->prepare("SELECT si.*, p.name as product_name 
                        FROM erp_sales_order_items si 
                        JOIN erp_products p ON si.product_id = p.id 
                        WHERE si.order_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

// Fetch Delivery Count (for Smart Button)
$delCount = 0;
try {
    $stmtDel = $pdo->prepare("SELECT COUNT(*) FROM erp_delivery_orders WHERE sales_order_id = ?");
    $stmtDel->execute([$id]);
    $delCount = $stmtDel->fetchColumn();
    // Get first delivery ID for link if count is 1
    $delId = 0;
    if ($delCount > 0) {
        $stmtDelId = $pdo->prepare("SELECT id FROM erp_delivery_orders WHERE sales_order_id = ? LIMIT 1");
        $stmtDelId->execute([$id]);
        $delId = $stmtDelId->fetchColumn();
    }
} catch (Exception $e) {
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Sales Order <?= htmlspecialchars($order['order_number']) ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Odoo-style CSS Variables (reused) */
        :root {
            --odoo-brand: #714B67;
            --odoo-action: #008784;
            --odoo-border: #dee2e6;
        }

        body {
            background: #f0f2f5;
            font-family: -apple-system, sans-serif;
            color: #374151;
            margin: 0;
        }

        .page-wrapper {
            margin-left: 220px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0;
            }
        }

        .control-panel,
        .action-bar {
            background: white;
            border-bottom: 1px solid var(--odoo-border);
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .breadcrumb {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .breadcrumb a {
            text-decoration: none;
            color: #4b5563;
        }

        .btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--odoo-brand);
            color: white;
        }

        .btn-secondary {
            background: white;
            color: #374151;
            border-color: #d1d5db;
        }

        .smart-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 1px solid #d1d5db;
            background: white;
            padding: 5px 15px;
            border-radius: 3px;
            color: #4b5563;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            min-width: 80px;
            margin-left: 10px;
            cursor: pointer;
        }

        .smart-button:hover {
            background: #f8f9fa;
        }

        .smart-button i {
            font-size: 1.2rem;
            color: var(--odoo-action);
            margin-bottom: 2px;
        }

        .sheet-container {
            max-width: 960px;
            margin: 24px auto;
            width: 100%;
            padding: 0 16px;
        }

        .sheet {
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 32px 40px;
            min-height: 600px;
        }

        .o-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .o-table th {
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
            padding: 8px;
        }

        .o-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px;
        }

        .num {
            text-align: right;
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>

    <main class="page-wrapper">
        <!-- Control Panel -->
        <div class="control-panel">
            <div class="breadcrumb">
                <a href="../index.php">Dashboard</a> / <span>Sales Orders</span> / <span
                    style="font-weight:600;"><?= htmlspecialchars($order['order_number']) ?></span>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div style="display:flex; gap:10px; align-items:center;">
                <!-- Workflow Buttons -->
                <?php if ($order['invoice_status'] === 'to_invoice'): ?>
                    <button onclick="createInvoice(<?= $id ?>)" class="btn btn-primary">Create Invoice</button>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="window.print()">Print</button>
            </div>

            <!-- Smart Buttons (Right Side) -->
            <div style="display:flex;">
                <?php if ($delCount > 0): ?>
                    <a href="../inventory/view-delivery.php?id=<?= $delId ?>" class="smart-button">
                        <i class="fa fa-truck"></i>
                        <span><?= $delCount ?> Delivery</span>
                    </a>
                <?php endif; ?>

                <!-- Invoices Smart Button could go here too -->
            </div>
        </div>

        <!-- Sheet -->
        <div class="sheet-container">
            <div class="sheet">
                <div style="display:flex; justify-content:space-between; margin-bottom:30px;">
                    <h1 style="margin:0; font-size:2rem; color:var(--odoo-brand);">
                        <?= htmlspecialchars($order['order_number']) ?></h1>

                    <div style="text-align:right;">
                        <h3 style="margin:0;"><?= htmlspecialchars($order['customer_name']) ?></h3>
                        <p style="color:#666; margin:5px 0;"><?= htmlspecialchars($order['customer_email']) ?></p>
                        <p style="color:#666; margin:0;"><?= htmlspecialchars($order['customer_phone']) ?></p>
                    </div>
                </div>

                <div style="display:flex; gap:40px; margin-bottom:30px;">
                    <div>
                        <span style="font-weight:600; display:block; color:#666;">Order Date</span>
                        <span><?= date('d/m/Y', strtotime($order['order_date'])) ?></span>
                    </div>
                    <div>
                        <span style="font-weight:600; display:block; color:#666;">Salesperson</span>
                        <span><?= $_SESSION['user_name'] ?? 'Admin' ?></span> <!-- Placeholder -->
                    </div>
                </div>

                <!-- Lines -->
                <table class="o-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Description</th>
                            <th class="num">Quantity</th>
                            <th class="num">Unit Price</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td><?= htmlspecialchars($item['description']) ?></td>
                                <td class="num"><?= $item['quantity'] ?></td>
                                <td class="num"><?= number_format($item['unit_price'], 2) ?></td>
                                <td class="num"><?= number_format($item['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top:20px; text-align:right;">
                    <strong style="font-size:1.1rem;">Total: TSh
                        <?= number_format($order['total_amount'], 2) ?></strong>
                </div>

            </div>
        </div>
    </main>

    <script>
        async function createInvoice(id) {
            alert('TODO: Invoice Creation Logic from SO (can reuse convert_to_invoice logic but pointing to SO)');
            // Ideally we create invoice linked to SO
        }
    </script>
</body>

</html>