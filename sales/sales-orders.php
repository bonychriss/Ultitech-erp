<?php 
// Clear opcache to prevent caching issues
if (function_exists('opcache_reset')) { opcache_reset(); }
require_once '../../includes/functions.php';
requireLogin();
global $pdo;

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES,'UTF-8'); } }

$orders = [];
$error_message = '';

try {
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';

    // Inspect columns just in case
    $cols = [];
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM erp_sales_orders");
        $cols = array_map(fn($r) => $r['Field'], $colStmt->fetchAll());
    } catch (Throwable $e) { /* ignore */ }

    // erp_sales_orders usually has 'order_date'
    $orderCol = 'order_date';

    $sql = "SELECT s.*, c.name AS customer_name FROM erp_sales_orders s JOIN erp_customers c ON s.customer_id = c.id WHERE 1=1";
    $params = [];
    
    // Status Filter
    if ($status !== 'all') { 
        $sql .= " AND s.status = ?"; 
        $params[] = $status; 
    }
    
    // Search Filter
    if (!empty($search)) {
        $sql .= " AND (s.order_number LIKE ? OR c.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

} catch (PDOException $e) {
    $error_message = 'Database Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Orders - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#fff; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        
        .page-wrapper { margin-left: 220px !important; min-height: 100vh; padding: 15px !important; width: calc(100% - 220px) !important; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0 !important; padding: 10px !important; width: 100% !important; } }
        
        .header { background: transparent !important; margin-bottom: 20px; padding: 0 !important; display: flex !important; justify-content: space-between; align-items: center; border: none !important; }
        .header h2 { font-size: 1.75rem; font-weight: 600; color: #1f2937; margin: 0; }
        .header-actions { display: flex; gap: 12px; margin: 0 !important; }

        .container { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
        
        .card { background: white; border-radius: 0; border: none !important; overflow: visible; box-shadow: none !important; width: 100%; max-width: 100% !important; }
        
        .filters-toolbar { padding: 0 0 20px 0; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: transparent; }
        
        .search-box { position: relative; width: 300px; }
        .search-box input { width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; }
        .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        
        .filter-select { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; background: #fff; color: #374151; min-width: 160px; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { text-align: left; padding: 12px 16px; font-size: 0.8rem; font-weight: 600; color: #4b5563; text-transform: uppercase; border-bottom: 2px solid #e5e7eb; background: #f8f9fa; }
        .table td { padding: 16px; border-bottom: 1px solid #f3f4f6; color: #1f2937; vertical-align: middle; }
        .table tr:hover { background:#f8fafc; } 
        
        .btn { padding:8px 16px; border-radius:6px; text-decoration:none; font-size:0.9rem; font-weight:500; cursor:pointer; border:none; display:inline-flex; align-items: center; gap: 6px; transition: all 0.2s; } 
        .btn-primary { background:#1a73e8; color:white; } 
        .btn-primary:hover { background: #1557b0; }
        .btn-secondary { background:#fff; color:#374151; border:1px solid #d1d5db; } 
        .btn-secondary:hover { background: #f3f4f6; }
        
        .btn-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; color: #6b7280; background: transparent; transition: background 0.2s; }
        .btn-icon:hover { background: #f3f4f6; color: #111827; }
        
        .dropdown { position: relative; display: inline-block; }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); z-index: 50; min-width: 140px; margin-top: 4px; overflow: hidden; }
        .dropdown.active .dropdown-menu { display: block; }
        .dropdown-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: #374151; text-decoration: none; font-size: 0.85rem; width: 100%; text-align: left; cursor: pointer; transition: background 0.1s; border: none; background: none; }
        .dropdown-item:hover { background: #f3f4f6; }
        .dropdown-item i { width: 16px; text-align: center; color: #6b7280; }
        
        .badge { display:inline-block; padding:4px 10px; border-radius:99px; font-size:0.75rem; font-weight:500; } 
        .badge-warning { background:#fef3c7; color:#d97706; } 
        .badge-success { background:#d1fae5; color:#059669; } 
        .badge-info { background:#dbeafe; color:#2563eb; } 
        .badge-danger { background:#fee2e2; color:#dc2626; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <!-- Header -->
    <div class="header">
        <h2>Sales Orders</h2>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="create-sales-order.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Sales Order
            </a>
        </div>
    </div>

    <!-- Main Card -->
    <div class="container">
        <?php if ($error_message): ?>
            <div class="card" style="padding: 16px; background: #fee2e2; color: #dc2626; border-color: #fecaca; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <!-- Filter Toolbar -->
            <form method="GET" class="filters-toolbar">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="search" placeholder="Search Order # or Customer..." value="<?= h($search) ?>" onchange="this.form.submit()">
                </div>
                
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all">All Status</option>
                    <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="confirmed" <?= $status == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </form>

            <table class="table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Order Date</th>
                        <th>Delivery Due</th>
                        <th style="text-align: right;">Amount</th>
                        <th>Status</th>
                        <th>Delivery</th>
                        <th>Invoice</th>
                        <th style="width: 100px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 48px; color: #6b7280;">
                                <div style="font-size: 3rem; margin-bottom: 16px; color: #d1d5db;"><i class="fas fa-box-open"></i></div>
                                <h3 style="margin-bottom: 8px; font-weight: 500;">No sales orders found</h3>
                                <p>Create orders to track sales and deliverables.</p>
                                <a href="create-sales-order.php" class="btn btn-primary" style="margin-top: 16px;">
                                    <i class="fas fa-plus"></i> New Sales Order
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td style="font-weight: 500; font-family: monospace; color: #111827;"><?= htmlspecialchars($o['order_number']) ?></td>
                                <td><?= htmlspecialchars($o['customer_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($o['order_date'])) ?></td>
                                <td><?= isset($o['delivery_date']) ? date('M d, Y', strtotime($o['delivery_date'])) : '-' ?></td>
                                <td style="font-weight: 600; text-align: right;">TSh <?= number_format($o['total_amount'], 2) ?></td>
                                <td>
                                    <?php $statusClass = ['draft' => 'badge-warning', 'confirmed' => 'badge-success', 'cancelled' => 'badge-danger']; ?>
                                    <span class="badge <?= $statusClass[$o['status']] ?? 'badge-info' ?>"><?= ucfirst($o['status']) ?></span>
                                </td>
                                <td>
                                     <?php $delClass = ['pending' => 'badge-warning', 'partial' => 'badge-info', 'delivered' => 'badge-success']; ?>
                                     <span class="badge <?= $delClass[$o['delivery_status']] ?? '' ?>"><?= ucfirst($o['delivery_status']) ?></span>
                                </td>
                                <td>
                                     <?php $invClass = ['not_invoiced' => 'badge-warning', 'partial' => 'badge-info', 'invoiced' => 'badge-success']; ?>
                                     <span class="badge <?= $invClass[$o['invoice_status']] ?? '' ?>"><?= ucwords(str_replace('_', ' ', $o['invoice_status'])) ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <div class="dropdown">
                                        <a href="view-sales-order.php?id=<?= $o['id'] ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button class="btn-icon" onclick="toggleDropdown(this)">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a href="view-sales-order.php?id=<?= $o['id'] ?>" class="dropdown-item">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <!-- Logic for actions could be added here -->
                                            
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function toggleDropdown(btn) {
        document.querySelectorAll('.dropdown.active').forEach(el => {
            if (el !== btn.parentElement) el.classList.remove('active');
        });
        btn.parentElement.classList.toggle('active');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown.active').forEach(el => el.classList.remove('active'));
        }
    });
</script>
</body>
</html>
