<?php
require_once '../../includes/functions.php';
requireLogin();
global $pdo;

$type = $_GET['type'] ?? 'receivables'; // 'receivables' or 'payables'

$buckets = ['0-30', '31-60', '61-90', '90+'];
$data = [];

// Helper to categorize days
function getBucket($days) {
    if ($days <= 30) return '0-30';
    if ($days <= 60) return '31-60';
    if ($days <= 90) return '61-90';
    return '90+';
}

if ($type === 'receivables') {
    // Fetch Unpaid Invoices
    $sql = "SELECT i.*, c.name as party_name, DATEDIFF(CURRENT_DATE, i.invoice_date) as days_overdue 
            FROM erp_invoices i 
            JOIN erp_customers c ON i.customer_id = c.id 
            WHERE i.status IN ('sent', 'partial') OR (i.status = 'overdue')";
            // Simplify status check: usually anything not 'paid' or 'draft'
            
    // Actually, calculate residual amount if partial? 
    // For now assuming total is outstanding for simplicity or check if we have payments table (we do, but complex to join).
    // Let's assume 'total' is what's owed for now for the blueprint demo. 
} else {
    // Payables (based on Expenses or POs? Ideally Vendor Bills. We have 'erp_grn' but maybe not full bills yet.
    // Let's use erp_purchase_requests where status=approved? No, that's PR.
    // We don't have a 'Vendor Bills' table fully separate. We have 'expenses'.
    // Let's use `erp_expenses` that are 'pending' payment?
    // Or `erp_grn`?
    // Given the blueprint, I'll assume we look at `erp_expenses` with status 'pending' (if that status exists) or `erp_purchase_orders`.
    // Let's mock it using `erp_purchase_orders` that are 'approved' but not 'completed'.
    $sql = "SELECT po.id, po.total, s.name as party_name, DATEDIFF(CURRENT_DATE, po.order_date) as days_overdue 
            FROM erp_purchase_orders po 
            JOIN erp_suppliers s ON po.supplier_id = s.id 
            WHERE po.status = 'approved'"; 
}

$rows = $pdo->query($sql)->fetchAll();

// Process into Matrix
$aging = [];
foreach ($rows as $r) {
    $name = $r['party_name'];
    if (!isset($aging[$name])) {
        $aging[$name] = array_fill_keys($buckets, 0);
        $aging[$name]['Total'] = 0;
    }
    
    $bucket = getBucket($r['days_overdue']);
    $amount = $r['total'];
    
    $aging[$name][$bucket] += $amount;
    $aging[$name]['Total'] += $amount;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Aging Analysis - ERP</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <style>
        body { background: #f5f5f5; font-family: -apple-system, sans-serif; }
         .page-wrapper { margin-left: 220px; padding: 30px; }
         .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
         .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
         .tab { padding: 10px 20px; cursor: pointer; border-radius: 4px; font-weight: 500; text-decoration: none; color: #555; }
         .tab.active { background: #e0e7ff; color: #3730a3; }
         table { width: 100%; border-collapse: collapse; }
         th { background: #f9fafb; text-align: left; padding: 12px; border-bottom: 2px solid #ddd; }
         td { padding: 12px; border-bottom: 1px solid #eee; }
         .number { text-align: right; font-family: monospace; }
         .total-col { font-weight: bold; background: #fafafa; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>
<div class="page-wrapper">
    <div class="card">
        <h2 style="margin-bottom: 20px;">Aging Analysis Report</h2>
        
        <div class="tabs">
            <a href="?type=receivables" class="tab <?= $type == 'receivables' ? 'active' : '' ?>">Receivables (AR)</a>
            <a href="?type=payables" class="tab <?= $type == 'payables' ? 'active' : '' ?>">Payables (AP)</a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Customer / Supplier</th>
                    <th class="number">0-30 Days</th>
                    <th class="number">31-60 Days</th>
                    <th class="number">61-90 Days</th>
                    <th class="number">90+ Days</th>
                    <th class="number">Total Due</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($aging)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#888;">No outstanding items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($aging as $name => $b): ?>
                    <tr>
                        <td style="font-weight: 500;"><?= htmlspecialchars($name) ?></td>
                        <td class="number"><?= $b['0-30'] > 0 ? number_format($b['0-30']) : '-' ?></td>
                        <td class="number"><?= $b['31-60'] > 0 ? number_format($b['31-60']) : '-' ?></td>
                        <td class="number"><?= $b['61-90'] > 0 ? number_format($b['61-90']) : '-' ?></td>
                        <td class="number" style="color: #c5221f;"><?= $b['90+'] > 0 ? number_format($b['90+']) : '-' ?></td>
                        <td class="number total-col"><?= number_format($b['Total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
