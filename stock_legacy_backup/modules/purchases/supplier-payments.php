<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$page_title = 'Supplier Payments';

$suppliers = [];
try {
    $suppliers = $pdo->query("SELECT id, name FROM stocks_suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $suppliers = [];
}

$payments = [
    ['no' => 'PAY-2026-0012', 'date' => '2026-05-20', 'supplier' => 'SAFETY & SECURITY LTD', 'bill' => 'BILL-2026-0456', 'method' => 'Bank Transfer', 'account' => 'CRDB Bank PLC - Main Operating (TZS)', 'amount' => 120000000, 'status' => 'Posted', 'by' => 'Admin'],
    ['no' => 'PAY-2026-0011', 'date' => '2026-05-19', 'supplier' => 'NITRO EXPLOSIVES LTD', 'bill' => 'BILL-2026-0452', 'method' => 'Bank Transfer', 'account' => 'NMB Bank PLC - Payroll (TZS)', 'amount' => 190000000, 'status' => 'Posted', 'by' => 'Admin'],
    ['no' => 'PAY-2026-0010', 'date' => '2026-05-18', 'supplier' => 'ABC OFFICE SUPPLIES', 'bill' => 'BILL-2026-0448', 'method' => 'Bank Transfer', 'account' => 'NMB Bank PLC - Payroll (TZS)', 'amount' => 36750000, 'status' => 'Posted', 'by' => 'Admin'],
    ['no' => 'PAY-2026-0009', 'date' => '2026-05-17', 'supplier' => 'DURA BUILD LTD', 'bill' => 'BILL-2026-0445', 'method' => 'Bank Transfer', 'account' => 'CRDB Bank PLC - Main Operating (TZS)', 'amount' => 56000000, 'status' => 'Partial', 'by' => 'Admin'],
    ['no' => 'PAY-2026-0008', 'date' => '2026-05-16', 'supplier' => 'TANZANIA CABLES LTD', 'bill' => 'BILL-2026-0442', 'method' => 'Mobile Money', 'account' => 'Airtel Money - UGET Account', 'amount' => 12300000, 'status' => 'Posted', 'by' => 'Admin'],
    ['no' => 'PAY-2026-0007', 'date' => '2026-05-15', 'supplier' => 'PETROLEUM SUPPLIES LTD', 'bill' => 'BILL-2026-0438', 'method' => 'Bank Transfer', 'account' => 'CRDB Bank PLC - Main Operating (TZS)', 'amount' => 169000000, 'status' => 'Posted', 'by' => 'Admin'],
];

include '../../includes/header.php';
?>
<style>
    .pay-shell { font-family: "Inter", "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; }
    .pay-wrap { padding: 12px 14px 20px; }
    .pay-top { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .pay-title { margin: 0; font-size: 34px; font-weight: 800; color: #0b1f5d; line-height: 1.1; }
    .pay-sub { margin: 5px 0 0; font-size: 14px; color: #64748b; }
    .pay-bc { margin-top: 8px; font-size: 12px; color: #94a3b8; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .pay-bc a { color: #2563eb; text-decoration: none; font-weight: 700; }
    .pay-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .pay-btn { border: 1px solid #dbe2ea; background: #fff; border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 700; color: #0f172a; text-decoration: none; display: inline-flex; gap: 6px; align-items: center; }
    .pay-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pay-search { height: 36px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; min-width: 220px; }
    .pay-kpis { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
    .pay-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; }
    .pay-kpi .l { font-size: 11px; color: #64748b; font-weight: 700; }
    .pay-kpi .v { margin-top: 4px; font-size: 25px; font-weight: 800; color: #0f172a; line-height: 1.05; }
    .pay-kpi .s { font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .pay-grid { display: grid; grid-template-columns: 1.75fr 1fr; gap: 10px; }
    .pay-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
    .pay-card-h { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 14px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .pay-card-b { padding: 12px; }
    .pay-filters { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
    .pay-fg label { display: block; font-size: 11px; color: #64748b; font-weight: 700; margin-bottom: 4px; }
    .pay-ctl { width: 100%; height: 36px; border: 1px solid #dbe2ea; border-radius: 7px; padding: 0 10px; font-size: 12px; }
    .pay-table-wrap { overflow: auto; border: 1px solid #eef2f7; border-radius: 8px; }
    .pay-table { width: 100%; min-width: 1000px; border-collapse: collapse; font-size: 12px; }
    .pay-table th, .pay-table td { border-bottom: 1px solid #eef2f7; padding: 8px; vertical-align: middle; }
    .pay-table th { background: #fafafa; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 800; white-space: nowrap; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .st { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; }
    .st.posted { background: #dcfce7; color: #166534; }
    .st.partial { background: #fef3c7; color: #92400e; }
    .pay-kv { display: grid; grid-template-columns: 1fr auto; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eef2f7; font-size: 12px; color: #64748b; }
    .pay-kv:last-child { border-bottom: 0; }
    .pay-kv b { color: #0f172a; }
    .pay-chart { height: 180px; border: 1px dashed #dbe2ea; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 12px; }
    @media (max-width: 1300px) { .pay-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .pay-grid { grid-template-columns: 1fr; } }
    @media (max-width: 700px) { .pay-filters { grid-template-columns: 1fr; } .pay-kpis { grid-template-columns: 1fr; } .pay-title { font-size: 28px; } }
</style>

<main class="main-content pay-shell">
    <div class="pay-wrap">
        <div class="pay-top">
            <div>
                <h1 class="pay-title">Supplier Payments</h1>
                <p class="pay-sub">Record, track, and manage payments made to suppliers.</p>
                <nav class="pay-bc">
                    <a href="../../dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="../purchases/index.php">Purchases &amp; Payables</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Supplier Payments</span>
                </nav>
            </div>
            <div class="pay-tools">
                <input type="search" class="pay-search" placeholder="Search payments...">
                <a href="#" class="pay-btn"><i class="fas fa-file-import"></i> Import</a>
                <a href="#" class="pay-btn"><i class="fas fa-file-export"></i> Export</a>
                <a href="#" class="pay-btn primary"><i class="fas fa-plus"></i> New Payment</a>
            </div>
        </div>

        <section class="pay-kpis">
            <article class="pay-kpi"><div class="l">Total Payments</div><div class="v">1,245,780,250.00</div><div class="s">All time payments</div></article>
            <article class="pay-kpi"><div class="l">Unpaid Supplier Bills</div><div class="v">785,430,120.00</div><div class="s">From 32 bills</div></article>
            <article class="pay-kpi"><div class="l">This Month Payments</div><div class="v">152,890,000.00</div><div class="s">May 2026</div></article>
            <article class="pay-kpi"><div class="l">Draft Payments</div><div class="v">62,450,000.00</div><div class="s">6 payments</div></article>
            <article class="pay-kpi"><div class="l">Total Outstanding</div><div class="v">1,034,220,120.00</div><div class="s">Across all suppliers</div></article>
        </section>

        <div class="pay-grid">
            <section class="pay-card">
                <div class="pay-card-b">
                    <div class="pay-filters">
                        <div class="pay-fg">
                            <label>Supplier</label>
                            <select class="pay-ctl">
                                <option>All Suppliers</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option><?php echo htmlspecialchars((string) $sup['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pay-fg"><label>Status</label><select class="pay-ctl"><option>All Statuses</option><option>Posted</option><option>Draft</option><option>Partial</option></select></div>
                        <div class="pay-fg"><label>Payment Method</label><select class="pay-ctl"><option>All Methods</option><option>Bank Transfer</option><option>Cash</option><option>Mobile Money</option></select></div>
                        <div class="pay-fg"><label>Date Range</label><input class="pay-ctl" value="May 1, 2026 - May 20, 2026"></div>
                        <div class="pay-fg"><label>Search Voucher / Reference</label><input class="pay-ctl" placeholder="Search payment no or ref..."></div>
                    </div>

                    <div class="pay-table-wrap">
                        <table class="pay-table">
                            <thead>
                                <tr>
                                    <th>#</th><th>Payment No</th><th>Date</th><th>Supplier</th><th>Bill Reference</th>
                                    <th>Payment Method</th><th>Bank/Cash Account</th><th class="num">Amount (TZS)</th><th>Status</th><th>Created By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $i => $row): ?>
                                    <?php $s = strtolower((string) $row['status']); ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><?php echo htmlspecialchars($row['no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime((string) $row['date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($row['supplier'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($row['bill'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($row['method'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($row['account'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="num"><?php echo number_format((float) $row['amount'], 2); ?></td>
                                        <td><span class="st <?php echo $s === 'partial' ? 'partial' : 'posted'; ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['by'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <aside>
                <div class="pay-card" style="margin-bottom:10px;">
                    <div class="pay-card-h"><i class="fas fa-user-tie"></i> Supplier Summary</div>
                    <div class="pay-card-b">
                        <div style="font-size:14px;font-weight:800;color:#0f172a;">SAFETY &amp; SECURITY LTD</div>
                        <div class="pay-kv"><span>Supplier Code</span><b>SUP-0007</b></div>
                        <div class="pay-kv"><span>Total Bills</span><b>TZS 245,000,000.00</b></div>
                        <div class="pay-kv"><span>Total Paid</span><b>TZS 178,125,000.00</b></div>
                        <div class="pay-kv"><span>Outstanding</span><b style="color:#dc2626;">TZS 66,875,000.00</b></div>
                    </div>
                </div>

                <div class="pay-card" style="margin-bottom:10px;">
                    <div class="pay-card-h"><i class="fas fa-receipt"></i> Posting Information</div>
                    <div class="pay-card-b">
                        <div class="pay-kv"><span>Status</span><b><span class="st posted">Posted</span></b></div>
                        <div class="pay-kv"><span>Created By</span><b>System Admin</b></div>
                        <div class="pay-kv"><span>Created At</span><b><?php echo htmlspecialchars(date('d/m/Y h:i A'), ENT_QUOTES, 'UTF-8'); ?></b></div>
                        <div class="pay-kv"><span>Journal Entry</span><b>JE-2026-0912</b></div>
                    </div>
                </div>

                <div class="pay-card">
                    <div class="pay-card-h"><i class="fas fa-chart-pie"></i> Payment Method Breakdown</div>
                    <div class="pay-card-b">
                        <div class="pay-chart">Chart placeholder (Bank 72%, Cash 18%, Mobile 10%)</div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
