<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

global $pdo;

// Date Range Filter
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-12 months'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

// Calculate previous period for comparison
$dateDiff = (strtotime($endDate) - strtotime($startDate)) / (24 * 60 * 60);
$prevStartDate = date('Y-m-d', strtotime($startDate . " - " . ($dateDiff + 1) . " days"));
$prevEndDate = date('Y-m-d', strtotime($startDate . " - 1 day"));

// Helper function for growth %
function getGrowth($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return (($current - $previous) / $previous) * 100;
}

// --- 1. CORE FINANCIAL METRICS ---
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_revenue,
        SUM(amount_paid) as total_paid,
        COUNT(*) as total_invoices,
        SUM(balance_due) as total_outstanding,
        AVG(total_amount) as avg_invoice_value
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$currentMetrics = $stmt->fetch();

// Previous period metrics
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_revenue,
        SUM(amount_paid) as total_paid,
        COUNT(*) as total_invoices
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
");
$stmt->execute([$prevStartDate, $prevEndDate]);
$prevMetrics = $stmt->fetch();

// --- 2. EXPENSES ANALYSIS ---
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_amount) as total_expenses,
        COUNT(*) as total_vouchers,
        AVG(total_amount) as avg_voucher_value
    FROM payment_vouchers 
    WHERE status = 'approved' AND date_created BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$expenseMetrics = $stmt->fetch();

// Previous period expenses
$stmt = $pdo->prepare("
    SELECT SUM(total_amount) as total_expenses
    FROM payment_vouchers 
    WHERE status = 'approved' AND date_created BETWEEN ? AND ?
");
$stmt->execute([$prevStartDate, $prevEndDate]);
$prevExpenses = $stmt->fetch();

// --- 3. PROFITABILITY ANALYSIS ---
$totalRevenue = $currentMetrics['total_revenue'] ?: 0;
$totalPaid = $currentMetrics['total_paid'] ?: 0;
$totalExpenses = $expenseMetrics['total_expenses'] ?: 0;
$netProfit = $totalPaid - $totalExpenses;
$profitMargin = $totalPaid > 0 ? ($netProfit / $totalPaid) * 100 : 0;
$outstandingReceivables = $totalRevenue - $totalPaid;

// --- 4. MONTHLY REVENUE TRENDS ---
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(invoice_date, '%Y-%m') as month,
        SUM(total_amount) as revenue,
        SUM(amount_paid) as cash_received,
        COUNT(*) as invoice_count
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$startDate, $endDate]);
$monthlyRevenue = $stmt->fetchAll();

// --- 5. EXPENSE ANALYSIS ---
$stmt = $pdo->prepare("
    SELECT 
        LEFT(description, 50) as expense_description,
        payee_name,
        SUM(total_amount) as total_expense,
        COUNT(*) as voucher_count,
        AVG(total_amount) as avg_expense
    FROM payment_vouchers 
    WHERE status = 'approved' AND date_created BETWEEN ? AND ?
    GROUP BY LEFT(description, 50), payee_name
    ORDER BY total_expense DESC
    LIMIT 10
");
$stmt->execute([$startDate, $endDate]);
$expenseCategories = $stmt->fetchAll();

// --- 6. TOP CUSTOMERS BY REVENUE ---
$stmt = $pdo->prepare("
    SELECT 
        c.id, c.company_name as customer_name, c.email,
        SUM(i.total_amount) as total_revenue,
        SUM(i.amount_paid) as total_paid,
        COUNT(i.id) as invoice_count,
        AVG(i.total_amount) as avg_invoice_value
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
    GROUP BY i.customer_id, c.id, c.company_name
    ORDER BY total_revenue DESC
    LIMIT 15
");
$stmt->execute([$startDate, $endDate]);
$topCustomers = $stmt->fetchAll();

// --- 7. PAYMENT STATUS BREAKDOWN ---
$stmt = $pdo->prepare("
    SELECT 
        status,
        COUNT(*) as count,
        SUM(total_amount) as total_amount,
        SUM(amount_paid) as total_paid,
        SUM(balance_due) as total_balance
    FROM invoices 
    WHERE invoice_date BETWEEN ? AND ?
    GROUP BY status
    ORDER BY total_amount DESC
");
$stmt->execute([$startDate, $endDate]);
$paymentStatus = $stmt->fetchAll();

// --- 8. CASH FLOW ANALYSIS ---
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(date_created, '%Y-%m') as month,
        SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END) as cash_out,
        0 as cash_in
    FROM payment_vouchers 
    WHERE date_created BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(date_created, '%Y-%m')
    
    UNION ALL
    
    SELECT 
        DATE_FORMAT(invoice_date, '%Y-%m') as month,
        0 as cash_out,
        SUM(amount_paid) as cash_in
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$cashFlowData = $stmt->fetchAll();

// Aggregate cash flow by month
$monthlyCashFlow = [];
foreach ($cashFlowData as $row) {
    if (!isset($monthlyCashFlow[$row['month']])) {
        $monthlyCashFlow[$row['month']] = ['month' => $row['month'], 'cash_in' => 0, 'cash_out' => 0];
    }
    $monthlyCashFlow[$row['month']]['cash_in'] += $row['cash_in'];
    $monthlyCashFlow[$row['month']]['cash_out'] += $row['cash_out'];
}
$monthlyCashFlow = array_values($monthlyCashFlow);

// --- 9. OVERDUE RECEIVABLES ---
$stmt = $pdo->prepare("
    SELECT 
        i.id, i.invoice_number, i.invoice_date, i.due_date, i.total_amount, i.balance_due,
        c.company_name as customer_name, c.email,
        DATEDIFF(NOW(), i.due_date) as days_overdue
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.balance_due > 0 AND i.due_date < NOW() AND i.status != 'cancelled'
    ORDER BY days_overdue DESC
    LIMIT 20
");
$stmt->execute();
$overdueReceivables = $stmt->fetchAll();

// --- 10. RECENT FINANCIAL ACTIVITIES ---
$recentActivities = [];
$stmt = $pdo->prepare("
    SELECT 
        'invoice' as type, id, invoice_number as reference, total_amount, 
        invoice_date as activity_date, status, customer_id
    FROM invoices 
    WHERE invoice_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    
    UNION ALL
    
    SELECT 
        'voucher' as type, id, voucher_no as reference, total_amount,
        date_created as activity_date, status, payee_name as customer_id
    FROM payment_vouchers 
    WHERE date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status = 'approved'
    
    ORDER BY activity_date DESC
    LIMIT 15
");
$stmt->execute();
$activities = $stmt->fetchAll();

foreach ($activities as $activity) {
    if ($activity['type'] == 'invoice') {
        $stmt = $pdo->prepare("SELECT company_name as name FROM customers WHERE id = ?");
        $stmt->execute([$activity['customer_id']]);
        $customer = $stmt->fetch();
        $activity['customer_name'] = $customer['name'] ?? 'Unknown';
    } else {
        $activity['customer_name'] = $activity['customer_id'];
    }
    $recentActivities[] = $activity;
}

// --- 11. BALANCE SHEET COMPONENTS ---
$stmt = $pdo->prepare("
    SELECT 
        'Assets' as section,
        'Cash & Bank' as category,
        COALESCE(SUM(amount_paid), 0) as amount
    FROM invoices 
    WHERE status = 'paid' AND invoice_date <= ?
    
    UNION ALL
    
    SELECT 
        'Assets' as section,
        'Accounts Receivable' as category,
        COALESCE(SUM(balance_due), 0) as amount
    FROM invoices 
    WHERE status != 'cancelled' AND balance_due > 0 AND invoice_date <= ?
    
    UNION ALL
    
    SELECT 
        'Liabilities' as section,
        'Accounts Payable' as category,
        COALESCE(SUM(total_amount), 0) as amount
    FROM payment_vouchers 
    WHERE status = 'approved' AND is_paid = 0 AND date_created <= ?
");
$stmt->execute([$endDate, $endDate, $endDate]);
$balanceSheetData = $stmt->fetchAll();

// Calculate totals
$totalAssets = 0;
$totalLiabilities = 0;
$totalEquity = 0;

foreach ($balanceSheetData as $item) {
    if ($item['section'] == 'Assets') {
        $totalAssets += $item['amount'];
    } elseif ($item['section'] == 'Liabilities') {
        $totalLiabilities += $item['amount'];
    }
}
$totalEquity = $totalAssets - $totalLiabilities;

// --- 12. INCOME STATEMENT (P&L) ---
$stmt = $pdo->prepare("
    SELECT 
        'Revenue' as category,
        SUM(total_amount) as amount
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'Cost of Sales' as category,
        SUM(soi.quantity * p.buying_price) as amount
    FROM sales_order_items soi
    JOIN sales_orders so ON soi.order_id = so.id
    JOIN products p ON soi.product_id = p.id
    JOIN invoices i ON so.id = i.order_id
    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'Operating Expenses' as category,
        SUM(total_amount) as amount
    FROM payment_vouchers 
    WHERE status = 'approved' AND date_created BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
$incomeStatementData = $stmt->fetchAll();

// Calculate P&L components
$totalRevenue = 0;
$costOfSales = 0;
$operatingExpenses = 0;
$grossProfit = 0;
$netIncome = 0;

foreach ($incomeStatementData as $item) {
    switch ($item['category']) {
        case 'Revenue':
            $totalRevenue += $item['amount'];
            break;
        case 'Cost of Sales':
            $costOfSales += $item['amount'];
            break;
        case 'Operating Expenses':
            $operatingExpenses += $item['amount'];
            break;
    }
}
$grossProfit = $totalRevenue - $costOfSales;
$netIncome = $grossProfit - $operatingExpenses;

// --- 13. CASH FLOW STATEMENT ---
$stmt = $pdo->prepare("
    SELECT 
        'Operating' as flow_type,
        'Cash Inflows' as activity,
        SUM(amount_paid) as amount
    FROM invoices 
    WHERE status = 'paid' AND invoice_date BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'Operating' as flow_type,
        'Cash Outflows' as activity,
        SUM(total_amount) as amount
    FROM payment_vouchers 
    WHERE status = 'approved' AND is_paid = 1 AND date_created BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'Investing' as flow_type,
        'Asset Purchases' as activity,
        0 as amount  -- Placeholder for future asset tracking
");
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$cashFlowData = $stmt->fetchAll();

// Calculate cash flow components
$operatingInflows = 0;
$operatingOutflows = 0;
$investingActivities = 0;
$netCashFlow = 0;

foreach ($cashFlowData as $item) {
    switch ($item['activity']) {
        case 'Cash Inflows':
            $operatingInflows += $item['amount'];
            break;
        case 'Cash Outflows':
            $operatingOutflows += $item['amount'];
            break;
        case 'Asset Purchases':
            $investingActivities += $item['amount'];
            break;
    }
}
$netCashFlow = $operatingInflows - $operatingOutflows - $investingActivities;

// --- 14. AGING REPORTS ---
// Accounts Receivable Aging
$stmt = $pdo->prepare("
    SELECT 
        c.company_name as customer_name,
        i.invoice_number,
        i.invoice_date,
        i.due_date,
        i.balance_due,
        DATEDIFF(NOW(), i.due_date) as days_overdue,
        CASE 
            WHEN DATEDIFF(NOW(), i.due_date) <= 0 THEN 'Current'
            WHEN DATEDIFF(NOW(), i.due_date) <= 30 THEN '1-30 Days'
            WHEN DATEDIFF(NOW(), i.due_date) <= 60 THEN '31-60 Days'
            WHEN DATEDIFF(NOW(), i.due_date) <= 90 THEN '61-90 Days'
            ELSE '90+ Days'
        END as aging_bucket
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.balance_due > 0 AND i.status != 'cancelled'
    ORDER BY days_overdue DESC
    LIMIT 50");
$stmt->execute();
$arAging = $stmt->fetchAll();

// Accounts Payable Aging
$stmt = $pdo->prepare("
    SELECT 
        payee_name,
        voucher_no,
        date_created,
        total_amount,
        DATEDIFF(NOW(), date_created) as days_outstanding,
        CASE 
            WHEN is_paid = 1 THEN 'Paid'
            WHEN DATEDIFF(NOW(), date_created) <= 30 THEN 'Current'
            WHEN DATEDIFF(NOW(), date_created) <= 60 THEN '31-60 Days'
            WHEN DATEDIFF(NOW(), date_created) <= 90 THEN '61-90 Days'
            ELSE '90+ Days'
        END as aging_bucket
    FROM payment_vouchers 
    WHERE status = 'approved' AND is_paid = 0
    ORDER BY days_outstanding DESC
    LIMIT 50");
$stmt->execute();
$apAging = $stmt->fetchAll();

// --- 15. TRIAL BALANCE ---
$stmt = $pdo->prepare("
    SELECT 
        'Debits' as balance_type,
        SUM(total_amount) as amount
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
    
    UNION ALL
    
    SELECT 
        'Credits' as balance_type,
        SUM(total_amount) as amount
    FROM payment_vouchers 
    WHERE status = 'approved' AND date_created BETWEEN ? AND ?");
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$trialBalance = $stmt->fetchAll();

$totalDebits = 0;
$totalCredits = 0;
foreach ($trialBalance as $item) {
    if ($item['balance_type'] == 'Debits') {
        $totalDebits += $item['amount'];
    } else {
        $totalCredits += $item['amount'];
    }
}

// --- 16. PROFITABILITY ANALYSIS BY DIMENSIONS ---
$stmt = $pdo->prepare("
    (SELECT 
        'By Customer' as dimension,
        c.company_name as entity,
        SUM(i.total_amount) as revenue,
        SUM(i.total_amount) * 0.7 as cost,  -- Assuming 70% cost
        SUM(i.total_amount) - SUM(i.total_amount) * 0.7 as profit
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.status != 'cancelled' AND i.invoice_date BETWEEN ? AND ?
    GROUP BY c.id, c.company_name
    ORDER BY revenue DESC
    LIMIT 10)
    
    UNION ALL
    
    (SELECT 
        'By Month' as dimension,
        DATE_FORMAT(invoice_date, '%Y-%m') as entity,
        SUM(total_amount) as revenue,
        SUM(total_amount) * 0.7 as cost,
        SUM(total_amount) - SUM(total_amount) * 0.7 as profit
    FROM invoices 
    WHERE status != 'cancelled' AND invoice_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY entity DESC)");
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$profitabilityAnalysis = $stmt->fetchAll();

// Calculate growth metrics
$revenueGrowth = getGrowth($totalRevenue, $prevMetrics['total_revenue']);
$expenseGrowth = getGrowth($totalExpenses, $prevExpenses['total_expenses']);
$profitGrowth = getGrowth($netProfit, ($prevMetrics['total_paid'] - $prevExpenses['total_expenses']));

// Handle CSV Export
if ($_GET['action'] == 'export') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="comprehensive_financial_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, ['Comprehensive Financial Report - ' . date('Y-m-d')]);
    fputcsv($output, []);
    fputcsv($output, ['Period:', $startDate . ' to ' . $endDate]);
    fputcsv($output, []);
    
    // Balance Sheet
    fputcsv($output, ['BALANCE SHEET']);
    fputcsv($output, ['Section', 'Category', 'Amount']);
    foreach ($balanceSheetData as $item) {
        fputcsv($output, [$item['section'], $item['category'], $item['amount']]);
    }
    fputcsv($output, ['TOTAL ASSETS', '', $totalAssets]);
    fputcsv($output, ['TOTAL LIABILITIES', '', $totalLiabilities]);
    fputcsv($output, ['TOTAL EQUITY', '', $totalEquity]);
    fputcsv($output, []);
    
    // Income Statement
    fputcsv($output, ['INCOME STATEMENT (PROFIT & LOSS)']);
    fputcsv($output, ['Category', 'Amount']);
    foreach ($incomeStatementData as $item) {
        fputcsv($output, [$item['category'], $item['amount']]);
    }
    fputcsv($output, ['GROSS PROFIT', '', $grossProfit]);
    fputcsv($output, ['NET INCOME', '', $netIncome]);
    fputcsv($output, []);
    
    // Cash Flow Statement
    fputcsv($output, ['CASH FLOW STATEMENT']);
    fputcsv($output, ['Flow Type', 'Activity', 'Amount']);
    foreach ($cashFlowData as $item) {
        fputcsv($output, [$item['flow_type'], $item['activity'], $item['amount']]);
    }
    fputcsv($output, ['NET CASH FLOW', '', $netCashFlow]);
    fputcsv($output, []);
    
    // Aging Reports
    fputcsv($output, ['ACCOUNTS RECEIVABLE AGING']);
    fputcsv($output, ['Customer', 'Invoice #', 'Due Date', 'Balance', 'Days Overdue', 'Aging Bucket']);
    foreach ($arAging as $ar) {
        fputcsv($output, [
            $ar['customer_name'],
            $ar['invoice_number'],
            $ar['due_date'],
            $ar['balance_due'],
            $ar['days_overdue'],
            $ar['aging_bucket']
        ]);
    }
    fputcsv($output, []);
    
    fputcsv($output, ['ACCOUNTS PAYABLE AGING']);
    fputcsv($output, ['Payee', 'Voucher #', 'Date', 'Amount', 'Days Outstanding', 'Aging Bucket']);
    foreach ($apAging as $ap) {
        fputcsv($output, [
            $ap['payee_name'],
            $ap['voucher_no'],
            $ap['date_created'],
            $ap['total_amount'],
            $ap['days_outstanding'],
            $ap['aging_bucket']
        ]);
    }
    fputcsv($output, []);
    
    // Trial Balance
    fputcsv($output, ['TRIAL BALANCE']);
    fputcsv($output, ['Balance Type', 'Total Amount']);
    fputcsv($output, ['Total Debits', $totalDebits]);
    fputcsv($output, ['Total Credits', $totalCredits]);
    fputcsv($output, ['Difference', abs($totalDebits - $totalCredits)]);
    fputcsv($output, []);
    
    // Profitability Analysis
    fputcsv($output, ['PROFITABILITY ANALYSIS']);
    fputcsv($output, ['Dimension', 'Entity', 'Revenue', 'Cost', 'Profit']);
    foreach ($profitabilityAnalysis as $pa) {
        fputcsv($output, [
            $pa['dimension'],
            $pa['entity'],
            $pa['revenue'],
            $pa['cost'],
            $pa['profit']
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Dashboard - ERP System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .btn-back:hover {
            transform: translateX(-4px);
            border-color: var(--primary);
            color: var(--primary);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .header {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header p {
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-secondary);
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .filter-form input {
            border: none;
            background: transparent;
            padding: 4px 8px;
            font-size: 14px;
            outline: none;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-export {
            background: var(--success);
            color: white;
        }

        .btn-export:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .metric-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .metric-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .growth-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .growth-up {
            background: #dcfce7;
            color: #166534;
        }

        .growth-down {
            background: #fee2e2;
            color: #991b1b;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .chart-card.col-12 {
            grid-column: 1 / -1;
        }

        .chart-card.col-8 {
            grid-column: span 2;
        }

        .chart-card.col-4 {
            grid-column: span 1;
        }

        .chart-header {
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .chart-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .data-table {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        th {
            text-align: left;
            padding: 12px;
            background: var(--bg-secondary);
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 2px solid var(--border);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
        }

        tr:hover {
            background: var(--bg-secondary);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        .text-success { color: var(--success); }
        .text-warning { color: var(--warning); }
        .text-danger { color: var(--danger); }

        .customer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .customer-card {
            background: var(--bg-secondary);
            border-radius: 8px;
            padding: 16px;
            border: 1px solid var(--border);
            transition: all 0.2s;
        }

        .customer-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .customer-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .customer-stats {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.875rem;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-label {
            color: var(--text-secondary);
        }

        .stat-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .filter-form {
                width: 100%;
                justify-content: space-between;
            }

            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .chart-card.col-8,
            .chart-card.col-4 {
                grid-column: 1;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header" style="flex-direction: column; align-items: flex-start; gap: 15px;">
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i> Back to Dashboard</a>
            <div style="display: flex; justify-content: space-between; width: 100%; align-items: center;">
                <div>
                    <h1><i class="fas fa-chart-line"></i> Financial Dashboard</h1>
                    <p style="color: var(--text-secondary); margin-top: 8px;">Comprehensive financial analytics and insights</p>
                </div>
                <div class="header-actions">
                    <a href="?action=export&start_date=<?php echo $startDate ?>&end_date=<?php echo $endDate ?>" class="btn btn-export">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <form class="filter-form" method="GET">
                        <span style="font-size: 14px; font-weight: 500;">Period:</span>
                        <input type="date" name="start_date" value="<?php echo $startDate ?>">
                        <span style="color: var(--text-secondary);">to</span>
                        <input type="date" name="end_date" value="<?php echo $endDate ?>">
                        <button type="submit" class="btn btn-primary">Update</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Key Financial Metrics -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Revenue</div>
                <div class="metric-value">
                    TSh <?php echo number_format($totalRevenue, 0) ?>
                    <span class="growth-badge <?php echo $revenueGrowth >= 0 ? 'growth-up' : 'growth-down' ?>">
                        <i class="fas fa-arrow-<?php echo $revenueGrowth >= 0 ? 'up' : 'down' ?>"></i> <?php echo round(abs($revenueGrowth), 1) ?>%
                    </span>
                </div>
                <div class="metric-subtitle">From <?php echo number_format($currentMetrics['total_invoices']) ?> invoices</div>
            </div>

            <div class="metric-card success">
                <div class="metric-label">Net Profit</div>
                <div class="metric-value">
                    TSh <?php echo number_format($netProfit, 0) ?>
                    <span class="growth-badge <?php echo $profitGrowth >= 0 ? 'growth-up' : 'growth-down' ?>">
                        <i class="fas fa-arrow-<?php echo $profitGrowth >= 0 ? 'up' : 'down' ?>"></i> <?php echo round(abs($profitGrowth), 1) ?>%
                    </span>
                </div>
                <div class="metric-subtitle">Profit margin: <?php echo round($profitMargin, 1) ?>%</div>
            </div>

            <div class="metric-card warning">
                <div class="metric-label">Total Expenses</div>
                <div class="metric-value">
                    TSh <?php echo number_format($totalExpenses, 0) ?>
                    <span class="growth-badge <?php echo $expenseGrowth >= 0 ? 'growth-up' : 'growth-down' ?>">
                        <i class="fas fa-arrow-<?php echo $expenseGrowth >= 0 ? 'up' : 'down' ?>"></i> <?php echo round(abs($expenseGrowth), 1) ?>%
                    </span>
                </div>
                <div class="metric-subtitle">From <?php echo number_format($expenseMetrics['total_vouchers']) ?> vouchers</div>
            </div>

            <div class="metric-card danger">
                <div class="metric-label">Outstanding Receivables</div>
                <div class="metric-value">TSh <?php echo number_format($outstandingReceivables, 0) ?></div>
                <div class="metric-subtitle">Amount pending collection</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Revenue Trend -->
            <div class="chart-card col-8">
                <div class="chart-header">
                    <div class="chart-title">Revenue Trend</div>
                    <div class="chart-subtitle">Monthly revenue and cash received</div>
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <!-- Payment Status -->
            <div class="chart-card col-4">
                <div class="chart-header">
                    <div class="chart-title">Payment Status</div>
                    <div class="chart-subtitle">Invoice payment distribution</div>
                </div>
                <div class="chart-container">
                    <canvas id="paymentStatusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Cash Flow & Expense Categories -->
        <div class="charts-grid">
            <!-- Cash Flow Analysis -->
            <div class="chart-card col-8">
                <div class="chart-header">
                    <div class="chart-title">Cash Flow Analysis</div>
                    <div class="chart-subtitle">Monthly cash in vs cash out</div>
                </div>
                <div class="chart-container">
                    <canvas id="cashFlowChart"></canvas>
                </div>
            </div>

            <!-- Expense Categories -->
            <div class="chart-card col-4">
                <div class="chart-header">
                    <div class="chart-title">Expense Analysis</div>
                    <div class="chart-subtitle">Top expense items by amount</div>
                </div>
                <div class="chart-container">
                    <canvas id="expenseCategoriesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Financial Statements Section -->
        <div class="charts-grid">
            <!-- Balance Sheet -->
            <div class="chart-card col-6">
                <div class="chart-header">
                    <div class="chart-title">📊 Balance Sheet</div>
                    <div class="chart-subtitle">Assets, Liabilities & Equity as of <?php echo date('M d, Y', strtotime($endDate)) ?></div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th>Category</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($balanceSheetData as $item): ?>
                            <tr>
                                <td><strong><?php echo $item['section'] ?></strong></td>
                                <td><?php echo $item['category'] ?></td>
                                <td style="font-weight: 600;">TSh <?php echo number_format($item['amount'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="border-top: 2px solid var(--border); background: var(--bg-secondary);">
                                <td><strong>TOTAL ASSETS</strong></td>
                                <td></td>
                                <td style="font-weight: 700; color: var(--primary);">TSh <?php echo number_format($totalAssets, 0) ?></td>
                            </tr>
                            <tr style="background: var(--bg-secondary);">
                                <td><strong>TOTAL LIABILITIES</strong></td>
                                <td></td>
                                <td style="font-weight: 700; color: var(--warning);">TSh <?php echo number_format($totalLiabilities, 0) ?></td>
                            </tr>
                            <tr style="background: var(--bg-secondary);">
                                <td><strong>TOTAL EQUITY</strong></td>
                                <td></td>
                                <td style="font-weight: 700; color: var(--success);">TSh <?php echo number_format($totalEquity, 0) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Income Statement -->
            <div class="chart-card col-6">
                <div class="chart-header">
                    <div class="chart-title">📈 Income Statement (P&L)</div>
                    <div class="chart-subtitle">Profit & Loss for period <?php echo date('M d, Y', strtotime($startDate)) ?> - <?php echo date('M d, Y', strtotime($endDate)) ?></div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeStatementData as $item): ?>
                            <tr>
                                <td><?php echo $item['category'] ?></td>
                                <td style="font-weight: 600;">TSh <?php echo number_format($item['amount'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="border-top: 2px solid var(--border); background: var(--bg-secondary);">
                                <td><strong>GROSS PROFIT</strong></td>
                                <td style="font-weight: 700; color: var(--success);">TSh <?php echo number_format($grossProfit, 0) ?></td>
                            </tr>
                            <tr style="background: var(--bg-secondary);">
                                <td><strong>NET INCOME</strong></td>
                                <td style="font-weight: 700; color: <?php echo $netIncome >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">TSh <?php echo number_format($netIncome, 0) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Cash Flow Statement -->
        <div class="chart-card col-12">
            <div class="chart-header">
                <div class="chart-title">💰 Cash Flow Statement</div>
                <div class="chart-subtitle">Cash movement analysis for the period</div>
            </div>
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>Flow Type</th>
                            <th>Activity</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashFlowData as $item): ?>
                        <tr>
                            <td><span class="badge <?php echo $item['flow_type'] == 'Operating' ? 'badge-success' : 'badge-info' ?>"><?php echo $item['flow_type'] ?></span></td>
                            <td><?php echo $item['activity'] ?></td>
                            <td style="font-weight: 600; color: <?php echo $item['activity'] == 'Cash Inflows' ? 'var(--success)' : 'var(--danger)' ?>;">TSh <?php echo number_format($item['amount'], 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 2px solid var(--border); background: var(--bg-secondary);">
                            <td><strong>NET CASH FLOW</strong></td>
                            <td></td>
                            <td style="font-weight: 700; color: <?php echo $netCashFlow >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">TSh <?php echo number_format($netCashFlow, 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Aging Reports -->
        <div class="charts-grid">
            <!-- AR Aging -->
            <div class="chart-card col-6">
                <div class="chart-header">
                    <div class="chart-title">📅 Accounts Receivable Aging</div>
                    <div class="chart-subtitle">Outstanding customer invoices by age</div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Invoice #</th>
                                <th>Balance</th>
                                <th>Days</th>
                                <th>Bucket</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($arAging, 0, 10) as $ar): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ar['customer_name']) ?></td>
                                <td><?php echo htmlspecialchars($ar['invoice_number']) ?></td>
                                <td style="font-weight: 600; color: var(--danger);">TSh <?php echo number_format($ar['balance_due'], 0) ?></td>
                                <td style="color: var(--warning);"><?php echo $ar['days_overdue'] ?></td>
                                <td><span class="badge <?php 
                                    echo $ar['aging_bucket'] == 'Current' ? 'badge-success' : 
                                         ($ar['aging_bucket'] == '90+ Days' ? 'badge-danger' : 'badge-warning'); 
                                ?>"><?php echo $ar['aging_bucket'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- AP Aging -->
            <div class="chart-card col-6">
                <div class="chart-header">
                    <div class="chart-title">📅 Accounts Payable Aging</div>
                    <div class="chart-subtitle">Outstanding payment vouchers by age</div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Payee</th>
                                <th>Voucher #</th>
                                <th>Amount</th>
                                <th>Days</th>
                                <th>Bucket</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($apAging, 0, 10) as $ap): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ap['payee_name']) ?></td>
                                <td><?php echo htmlspecialchars($ap['voucher_no']) ?></td>
                                <td style="font-weight: 600;">TSh <?php echo number_format($ap['total_amount'], 0) ?></td>
                                <td style="color: var(--warning);"><?php echo $ap['days_outstanding'] ?></td>
                                <td><span class="badge <?php 
                                    echo $ap['aging_bucket'] == 'Paid' ? 'badge-success' : 
                                         ($ap['aging_bucket'] == '90+ Days' ? 'badge-danger' : 'badge-warning'); 
                                ?>"><?php echo $ap['aging_bucket'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Trial Balance & Profitability -->
        <div class="charts-grid">
            <!-- Trial Balance -->
            <div class="chart-card col-4">
                <div class="chart-header">
                    <div class="chart-title">⚖️ Trial Balance</div>
                    <div class="chart-subtitle">Debits vs Credits verification</div>
                </div>
                <div style="padding: 20px; text-align: center;">
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 8px;">Total Debits</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary);">TSh <?php echo number_format($totalDebits, 0) ?></div>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 8px;">Total Credits</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--success);">TSh <?php echo number_format($totalCredits, 0) ?></div>
                    </div>
                    <div style="padding: 15px; background: var(--bg-secondary); border-radius: 8px;">
                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 8px;">Difference</div>
                        <div style="font-size: 1.25rem; font-weight: 700; color: <?php echo abs($totalDebits - $totalCredits) > 0 ? 'var(--warning)' : 'var(--success)' ?>;">TSh <?php echo number_format(abs($totalDebits - $totalCredits), 0) ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px;">
                            <?php echo abs($totalDebits - $totalCredits) > 0 ? '⚠️ Out of Balance' : '✅ Balanced' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profitability Analysis -->
            <div class="chart-card col-8">
                <div class="chart-header">
                    <div class="chart-title">📊 Profitability Analysis</div>
                    <div class="chart-subtitle">Performance by different dimensions</div>
                </div>
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Dimension</th>
                                <th>Entity</th>
                                <th>Revenue</th>
                                <th>Cost</th>
                                <th>Profit</th>
                                <th>Margin %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($profitabilityAnalysis as $pa): ?>
                            <tr>
                                <td><span class="badge badge-info"><?php echo $pa['dimension'] ?></span></td>
                                <td><?php echo htmlspecialchars($pa['entity']) ?></td>
                                <td style="font-weight: 600;">TSh <?php echo number_format($pa['revenue'], 0) ?></td>
                                <td style="color: var(--danger);">TSh <?php echo number_format($pa['cost'], 0) ?></td>
                                <td style="color: <?php echo $pa['profit'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>; font-weight: 600;">TSh <?php echo number_format($pa['profit'], 0) ?></td>
                                <td style="font-weight: 600; color: <?php echo ($pa['revenue'] > 0 && ($pa['profit'] / $pa['revenue']) * 100) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?php echo round(($pa['revenue'] > 0 ? ($pa['profit'] / $pa['revenue']) * 100 : 0), 1) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="chart-card col-12">
            <div class="chart-header">
                <div class="chart-title">🏆 Top Customers by Revenue</div>
                <div class="chart-subtitle">Highest revenue generating customers</div>
            </div>
            <div class="customer-grid">
                <?php foreach ($topCustomers as $customer): ?>
                <div class="customer-card">
                    <div class="customer-name"><?php echo htmlspecialchars($customer['customer_name']) ?></div>
                    <div class="customer-stats">
                        <div class="stat-item">
                            <span class="stat-label">Total Revenue:</span>
                            <span class="stat-value">TSh <?php echo number_format($customer['total_revenue'], 0) ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Invoices:</span>
                            <span class="stat-value"><?php echo number_format($customer['invoice_count']) ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Average:</span>
                            <span class="stat-value">TSh <?php echo number_format($customer['avg_invoice_value'], 0) ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Email:</span>
                            <span class="stat-value" style="font-size: 0.75rem;"><?php echo htmlspecialchars($customer['email']) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Overdue Receivables -->
        <?php if (!empty($overdueReceivables)): ?>
        <div class="chart-card col-12">
            <div class="chart-header">
                <div class="chart-title">⚠️ Overdue Receivables</div>
                <div class="chart-subtitle">Invoices with overdue payments</div>
            </div>
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Balance</th>
                            <th>Days Overdue</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overdueReceivables as $overdue): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($overdue['invoice_number']) ?></td>
                            <td><?php echo htmlspecialchars($overdue['customer_name']) ?></td>
                            <td><?php echo date('M d, Y', strtotime($overdue['due_date'])) ?></td>
                            <td>TSh <?php echo number_format($overdue['total_amount'], 0) ?></td>
                            <td class="text-danger">TSh <?php echo number_format($overdue['balance_due'], 0) ?></td>
                            <td class="text-danger"><?php echo number_format($overdue['days_overdue']) ?> days</td>
                            <td><span class="badge badge-danger">OVERDUE</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Activities -->
        <div class="chart-card col-12">
            <div class="chart-header">
                <div class="chart-title">📋 Recent Financial Activities</div>
                <div class="chart-subtitle">Latest invoices and payment vouchers</div>
            </div>
            <div class="data-table">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Customer/Payee</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivities as $activity): ?>
                        <tr>
                            <td><?php echo date('M j, Y H:i', strtotime($activity['activity_date'])) ?></td>
                            <td>
                                <span class="badge <?php echo $activity['type'] == 'invoice' ? 'badge-success' : 'badge-warning' ?>">
                                    <?php echo strtoupper($activity['type']) ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($activity['reference']) ?></td>
                            <td><?php echo htmlspecialchars($activity['customer_name']) ?></td>
                            <td style="font-weight: 600;">TSh <?php echo number_format($activity['total_amount'], 0) ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $activity['status'] == 'paid' ? 'badge-success' : 
                                         ($activity['status'] == 'approved' ? 'badge-success' : 'badge-warning'); 
                                ?>">
                                    <?php echo strtoupper($activity['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentActivities)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                No recent financial activities found
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- JavaScript for Charts -->
    <script>
        // Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($m) { return date('M Y', strtotime($m['month'] . '-01')); }, $monthlyRevenue)) ?>,
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode(array_map(function($m) { return $m['revenue']; }, $monthlyRevenue)) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Cash Received',
                    data: <?php echo json_encode(array_map(function($m) { return $m['cash_received']; }, $monthlyRevenue)) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { padding: 15, font: { size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'TSh ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Payment Status Chart
        const paymentCtx = document.getElementById('paymentStatusChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($p) { return ucfirst($p['status']); }, $paymentStatus)) ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(function($p) { return $p['total_amount']; }, $paymentStatus)) ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, font: { size: 11 } }
                    }
                }
            }
        });

        // Cash Flow Chart
        const cashFlowCtx = document.getElementById('cashFlowChart').getContext('2d');
        new Chart(cashFlowCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($m) { return date('M Y', strtotime($m['month'] . '-01')); }, $monthlyCashFlow)) ?>,
                datasets: [{
                    label: 'Cash In',
                    data: <?php echo json_encode(array_map(function($m) { return $m['cash_in']; }, $monthlyCashFlow)) ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 6
                }, {
                    label: 'Cash Out',
                    data: <?php echo json_encode(array_map(function($m) { return $m['cash_out']; }, $monthlyCashFlow)) ?>,
                    backgroundColor: '#ef4444',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { padding: 15, font: { size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'TSh ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Expense Categories Chart
        const expenseCtx = document.getElementById('expenseCategoriesChart').getContext('2d');
        new Chart(expenseCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($e) { return $e['expense_description'] ?: 'Other'; }, $expenseCategories)) ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(function($e) { return $e['total_expense']; }, $expenseCategories)) ?>,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, font: { size: 11 } }
                    }
                }
            }
        });
    </script>
</body>
</html>
