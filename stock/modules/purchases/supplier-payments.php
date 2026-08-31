<?php
require_once '../../config/database.php';
require_once '../../config/functions.php';
requireLogin();

$company_id = (int) (currentCompanyId() ?? 0);

// Handle POST payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    header('Content-Type: application/json');
    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
    $bank_account_id = isset($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : 0;
    $payment_method = isset($_POST['payment_method']) ? trim((string)$_POST['payment_method']) : '';
    $reference_no = isset($_POST['reference_no']) ? trim((string)$_POST['reference_no']) : '';
    $payment_date = isset($_POST['payment_date']) ? trim((string)$_POST['payment_date']) : date('Y-m-d');
    $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';

    if ($po_id <= 0 || $amount <= 0 || $bank_account_id <= 0 || empty($payment_method)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }

    try {
        // Fetch PO
        $stmt = $pdo->prepare("SELECT * FROM stocks_purchase_orders WHERE id = ?");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) {
            echo json_encode(['success' => false, 'message' => 'Purchase Order not found.']);
            exit;
        }

        if (strtolower($po['payment_status'] ?? '') === 'paid') {
            echo json_encode(['success' => false, 'message' => 'This Purchase Order has already been paid.']);
            exit;
        }

        // Fetch bank/cash financial account
        $stmt = $pdo->prepare("SELECT * FROM financial_accounts WHERE id = ?");
        $stmt->execute([$bank_account_id]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bank) {
            echo json_encode(['success' => false, 'message' => 'Selected bank/cash account not found.']);
            exit;
        }

        $pdo->beginTransaction();

        $po_company_id = (int)($po['company_id'] ?? $company_id);
        $user_id = (int)($_SESSION['user_id'] ?? 1);

        // Generate payment number PAY-YYYY-XXXX
        $year = date('Y');
        $payCount = $pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE YEAR(payment_date) = $year")->fetchColumn() + 1;
        $payment_number = sprintf("PAY-%s-%04d", $year, $payCount);

        // Update PO payment status to paid
        $stmt = $pdo->prepare("UPDATE stocks_purchase_orders SET payment_status = 'paid' WHERE id = ?");
        $stmt->execute([$po_id]);

        // Deduct from financial account balance
        $stmt = $pdo->prepare("UPDATE financial_accounts SET current_balance = current_balance - ? WHERE id = ?");
        $stmt->execute([$amount, $bank_account_id]);

        // Insert into supplier_payments
        $stmt = $pdo->prepare("
            INSERT INTO supplier_payments 
            (company_id, payment_number, supplier_id, purchase_order_id, payment_date, amount, currency, exchange_rate, bank_or_cash_account_id, payment_method, reference_no, status, created_by, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, NOW(), NOW())
        ");
        $exchange_rate = (float)($po['exchange_rate'] ?? 1.0);
        if ($exchange_rate <= 0) $exchange_rate = 1.0;
        $stmt->execute([
            $po_company_id,
            $payment_number,
            $po['supplier_id'],
            $po_id,
            $payment_date,
            $amount,
            $po['currency'] ?: 'USD',
            $exchange_rate,
            $bank_account_id,
            $payment_method,
            $reference_no,
            $user_id
        ]);
        $supplier_payment_id = $pdo->lastInsertId();

        // Insert into account_transactions (cash outflow log)
        $stmt = $pdo->prepare("
            INSERT INTO account_transactions 
            (company_id, account_id, transaction_date, type, amount, reference_type, reference_id, description, created_by, created_at, supplier_payment_id) 
            VALUES (?, ?, ?, 'credit', ?, 'purchase_order', ?, ?, ?, NOW(), ?)
        ");
        $desc = "Supplier Payment: " . $payment_number . " for PO #" . $po['po_number'];
        if (!empty($notes)) {
            $desc .= " (" . $notes . ")";
        }
        $stmt->execute([
            $po_company_id,
            $bank_account_id,
            $payment_date . ' ' . date('H:i:s'),
            $amount,
            $po_id,
            $desc,
            $user_id,
            $supplier_payment_id
        ]);

        // G/L DOUBLE-ENTRY BOOKKEEPING
        // 1. Resolve Debit Account: Accounts Payable (code 2000) or Purchases (5001)
        $debitAccId = 0;
        $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = '2000' LIMIT 1");
        $stmt->execute();
        $debitAccId = (int)$stmt->fetchColumn();
        if ($debitAccId <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = '5001' LIMIT 1");
            $stmt->execute();
            $debitAccId = (int)$stmt->fetchColumn();
        }

        // 2. Resolve Credit Account: Bank/Cash gl_account_id
        $creditAccId = (int)($bank['gl_account_id'] ?? 0);
        if ($creditAccId <= 0) {
            // Fallback based on type
            $fallbackCode = (strtolower($bank['type']) === 'bank') ? '1002' : '1001';
            $stmt = $pdo->prepare("SELECT id FROM erp_accounts WHERE code = ? LIMIT 1");
            $stmt->execute([$fallbackCode]);
            $creditAccId = (int)$stmt->fetchColumn();
        }

        if ($debitAccId > 0 && $creditAccId > 0) {
            // Generate Journal Entry Number JE-YYYY-XXXX
            $jeCount = $pdo->query("SELECT COUNT(*) FROM erp_journal_entries WHERE YEAR(date) = $year")->fetchColumn() + 1;
            $entry_number = sprintf("JE-%s-%04d", $year, $jeCount);

            // Insert Journal Entry Header
            $stmt = $pdo->prepare("
                INSERT INTO erp_journal_entries 
                (entry_number, date, description, status, created_by, reference) 
                VALUES (?, ?, ?, 'posted', ?, ?)
            ");
            $stmt->execute([
                $entry_number,
                $payment_date,
                "Supplier Payment for PO #" . $po['po_number'] . " via " . $bank['name'],
                $user_id,
                $po['po_number']
            ]);
            $journal_entry_id = $pdo->lastInsertId();

            // Insert Debit (Accounts Payable / Purchases)
            $stmt = $pdo->prepare("
                INSERT INTO erp_journal_items 
                (journal_id, account_id, debit, credit) 
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$journal_entry_id, $debitAccId, $amount]);

            // Insert Credit (Bank/Cash)
            $stmt = $pdo->prepare("
                INSERT INTO erp_journal_items 
                (journal_id, account_id, debit, credit) 
                VALUES (?, ?, 0, ?)
            ");
            $stmt->execute([$journal_entry_id, $creditAccId, $amount]);

            // Link Journal Entry back to Payment
            $stmt = $pdo->prepare("UPDATE supplier_payments SET journal_entry_id = ? WHERE id = ?");
            $stmt->execute([$journal_entry_id, $supplier_payment_id]);
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => "Payment registered successfully as $payment_number!"]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

$page_title = 'Supplier Payments';

// Fetch lists
$suppliers = [];
try {
    $suppliers = $pdo->query("SELECT id, name FROM stocks_suppliers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $suppliers = [];
}

$financialAccounts = [];
try {
    $financialAccounts = $pdo->query("SELECT id, name, type, current_balance, currency FROM financial_accounts WHERE status = 'active' AND type IN ('bank', 'cash', 'mobile') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $financialAccounts = [];
}

// Fetch real payments history
$payments = [];
try {
    $payments = $pdo->query("
        SELECT sp.*, ss.name as supplier_name, fa.name as account_name, po.po_number
        FROM supplier_payments sp
        LEFT JOIN stocks_suppliers ss ON sp.supplier_id = ss.id
        LEFT JOIN financial_accounts fa ON sp.bank_or_cash_account_id = fa.id
        LEFT JOIN stocks_purchase_orders po ON sp.purchase_order_id = po.id
        ORDER BY sp.payment_date DESC, sp.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $payments = [];
}

// Fetch unpaid/active POs
$unpaidPOs = [];
try {
    $unpaidPOs = $pdo->query("
        SELECT po.*, ss.name as supplier_name,
               COALESCE((SELECT SUM(qty_ordered * unit_cost) FROM stocks_po_items WHERE po_id = po.id), 0) as lines_total
        FROM stocks_purchase_orders po
        LEFT JOIN stocks_suppliers ss ON po.supplier_id = ss.id
        WHERE po.status IN ('Approved', 'Received')
          AND po.payment_status != 'paid'
        ORDER BY po.created_at DESC, po.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $unpaidPOs = [];
}

// Query KPIs
$kpi_total_payments = 0.0;
$kpi_outstanding = 0.0;
$kpi_month_payments = 0.0;

try {
    $kpi_total_payments = (float)$pdo->query("SELECT SUM(amount) FROM supplier_payments")->fetchColumn();
    $kpi_outstanding = (float)$pdo->query("SELECT SUM(total_amount) FROM stocks_purchase_orders WHERE status IN ('Approved', 'Received') AND payment_status != 'paid'")->fetchColumn();
    $kpi_month_payments = (float)$pdo->query("SELECT SUM(amount) FROM supplier_payments WHERE MONTH(payment_date) = MONTH(CURRENT_DATE()) AND YEAR(payment_date) = YEAR(CURRENT_DATE())")->fetchColumn();
} catch (Exception $e) {}

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
    .pay-btn { border: 1px solid #dbe2ea; background: #fff; border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 700; color: #0f172a; text-decoration: none; display: inline-flex; gap: 6px; align-items: center; cursor: pointer; }
    .pay-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pay-btn.sm-pay { background: #7c3aed; border-color: #7c3aed; color: #fff; padding: 4px 8px; border-radius: 6px; font-size: 11px; }
    .pay-btn.sm-pay:hover { background: #6d28d9; border-color: #6d28d9; }
    .pay-search { height: 36px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; min-width: 220px; }
    .pay-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 12px; }
    .pay-kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; }
    .pay-kpi .l { font-size: 11px; color: #64748b; font-weight: 700; }
    .pay-kpi .v { margin-top: 4px; font-size: 25px; font-weight: 800; color: #0f172a; line-height: 1.05; }
    .pay-kpi .s { font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .pay-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 10px; }
    .pay-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
    .pay-card-h { padding: 10px 12px; border-bottom: 1px solid #eef2f7; font-size: 14px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .pay-card-b { padding: 12px; }
    .pay-filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
    .pay-fg label { display: block; font-size: 11px; color: #64748b; font-weight: 700; margin-bottom: 4px; }
    .pay-ctl { width: 100%; height: 36px; border: 1px solid #dbe2ea; border-radius: 7px; padding: 0 10px; font-size: 12px; }
    .pay-table-wrap { overflow: auto; border: 1px solid #eef2f7; border-radius: 8px; }
    .pay-table { width: 100%; min-width: 800px; border-collapse: collapse; font-size: 12px; }
    .pay-table th, .pay-table td { border-bottom: 1px solid #eef2f7; padding: 8px; vertical-align: middle; }
    .pay-table th { background: #fafafa; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 800; white-space: nowrap; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .st { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; }
    .st.posted { background: #dcfce7; color: #166534; }
    .st.partial { background: #fef3c7; color: #92400e; }
    .st.unpaid { background: #fee2e2; color: #991b1b; }
    .pay-kv { display: grid; grid-template-columns: 1fr auto; gap: 10px; padding: 8px 0; border-bottom: 1px solid #eef2f7; font-size: 12px; color: #64748b; }
    .pay-kv:last-child { border-bottom: 0; }
    .pay-kv b { color: #0f172a; }
    .pay-tabs { display: flex; gap: 15px; margin-bottom: 12px; border-bottom: 2px solid #e2e8f0; }
    .pay-tab-btn { background: none; border: none; font-size: 14px; font-weight: 700; color: #64748b; border-bottom: 3px solid transparent; padding: 10px 5px; cursor: pointer; transition: all 0.2s; }
    .pay-tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; }
    @media (max-width: 1300px) { .pay-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .pay-grid { grid-template-columns: 1fr; } }
    @media (max-width: 700px) { .pay-filters { grid-template-columns: 1fr; } .pay-kpis { grid-template-columns: 1fr; } .pay-title { font-size: 28px; } }
</style>

<main class="main-content pay-shell">
    <div class="pay-wrap">
        <div class="pay-top">
            <div>
                <h1 class="pay-title">Supplier Payments</h1>
                <p class="pay-sub">Directly record, pay, and track balances for Purchase Orders.</p>
                <nav class="pay-bc">
                    <a href="../../dashboard.php">Home</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="../purchases/index.php">Purchases &amp; Payables</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Supplier Payments</span>
                </nav>
            </div>
            <div class="pay-tools">
                <button type="button" class="pay-btn" onclick="switchTab('unpaid')"><i class="fas fa-plus"></i> Record New Payment</button>
            </div>
        </div>

        <section class="pay-kpis">
            <article class="pay-kpi"><div class="l">Total Payments</div><div class="v">TZS <?= number_format($kpi_total_payments, 2) ?></div><div class="s">All time supplier payments</div></article>
            <article class="pay-kpi"><div class="l">This Month Payments</div><div class="v">TZS <?= number_format($kpi_month_payments, 2) ?></div><div class="s">Current month payments</div></article>
            <article class="pay-kpi"><div class="l">Total Outstanding POs</div><div class="v">TZS <?= number_format($kpi_outstanding, 2) ?></div><div class="s">Pending approval/receipt PO value</div></article>
            <article class="pay-kpi"><div class="l">Unpaid POs Count</div><div class="v"><?= count($unpaidPOs) ?></div><div class="s">Purchase Orders waiting for payment</div></article>
        </section>

        <div class="pay-grid">
            <section class="pay-card">
                <div class="pay-card-b">
                    <div class="pay-tabs">
                        <button type="button" class="pay-tab-btn active" id="btn-tab-logs" onclick="switchTab('logs')">Payment History</button>
                        <button type="button" class="pay-tab-btn" id="btn-tab-unpaid" onclick="switchTab('unpaid')">Unpaid Purchase Orders (<?= count($unpaidPOs) ?>)</button>
                    </div>

                    <!-- TAB 1: PAYMENT HISTORY -->
                    <div id="tab-logs">
                        <div class="pay-table-wrap">
                            <table class="pay-table">
                                <thead>
                                    <tr>
                                        <th>#</th><th>Payment No</th><th>Date</th><th>Supplier</th><th>PO Reference</th>
                                        <th>Payment Method</th><th>Bank/Cash Account</th><th class="num">Amount</th><th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($payments)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted" style="padding: 30px;">No payments have been logged yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($payments as $i => $row): ?>
                                            <tr>
                                                <td><?php echo $i + 1; ?></td>
                                                <td><b><?php echo htmlspecialchars($row['payment_number'], ENT_QUOTES, 'UTF-8'); ?></b></td>
                                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$row['payment_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($row['supplier_name'] ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php if ($row['po_number']): ?>
                                                        <a href="view_po.php?id=<?= (int)$row['purchase_order_id'] ?>" style="color:#2563eb; font-weight:600; text-decoration:none;"><?= htmlspecialchars($row['po_number'], ENT_QUOTES, 'UTF-8') ?></a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['payment_method'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($row['account_name'] ?: 'Default Account', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="num font-semibold text-green-600"><?= htmlspecialchars($row['currency']) ?> <?php echo number_format((float) $row['amount'], 2); ?></td>
                                                <td><span class="st posted">Posted</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB 2: UNPAID PURCHASE ORDERS -->
                    <div id="tab-unpaid" style="display: none;">
                        <div class="pay-table-wrap">
                            <table class="pay-table">
                                <thead>
                                    <tr>
                                        <th>PO Number</th><th>Date</th><th>Supplier</th><th>Procurement Status</th><th>Payment Status</th><th class="num">PO Amount</th><th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($unpaidPOs)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted" style="padding: 30px;">All approved Purchase Orders are paid. No outstanding PO payments.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($unpaidPOs as $po): 
                                            $poVal = (float)($po['total_amount'] ?: $po['lines_total']);
                                        ?>
                                            <tr>
                                                <td>
                                                    <a href="view_po.php?id=<?= $po['id'] ?>" style="color:#2563eb; font-weight:600; text-decoration:none;"><?= htmlspecialchars($po['po_number'], ENT_QUOTES, 'UTF-8') ?></a>
                                                </td>
                                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$po['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($po['supplier_name'] ?: 'Unknown Supplier', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php 
                                                    $wf = $po['procurement_workflow'] ?? PURCHASE_PROC_STANDARD;
                                                    $statusLabel = function_exists('purchaseDisplayStatusLabel')
                                                        ? purchaseDisplayStatusLabel($po['status'], $wf)
                                                        : $po['status'];
                                                    ?>
                                                    <span class="st" style="background:#e0f2fe; color:#0369a1;"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </td>
                                                <td><span class="st unpaid">Unpaid</span></td>
                                                <td class="num font-semibold text-slate-900"><?= htmlspecialchars($po['currency'] ?: 'USD') ?> <?php echo number_format($poVal, 2); ?></td>
                                                <td>
                                                    <button type="button" class="pay-btn sm-pay" onclick="openPayModal(<?= (int)$po['id'] ?>, '<?= htmlspecialchars($po['po_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($po['supplier_name'] ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?>', <?= $poVal ?>)">
                                                        <i class="fas fa-wallet"></i> Pay Now
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <aside>
                <div class="pay-card" style="margin-bottom:10px;">
                    <div class="pay-card-h"><i class="fas fa-university"></i> Bank &amp; Cash Balances</div>
                    <div class="pay-card-b">
                        <?php if (empty($financialAccounts)): ?>
                            <div class="text-muted text-center py-3">No active cash/bank accounts found.</div>
                        <?php else: ?>
                            <?php foreach ($financialAccounts as $acc): 
                                $typeLabel = ucfirst($acc['type']);
                                $balanceColor = ($acc['current_balance'] >= 0) ? '#16a34a' : '#dc2626';
                            ?>
                                <div class="pay-kv">
                                    <span><?php echo htmlspecialchars($acc['name'], ENT_QUOTES, 'UTF-8'); ?> <small class="text-muted">(<?php echo $typeLabel; ?>)</small></span>
                                    <b style="color: <?php echo $balanceColor; ?>;"><?php echo htmlspecialchars($acc['currency'] ?: 'TZS'); ?> <?php echo number_format($acc['current_balance'], 2); ?></b>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pay-card" style="margin-bottom:10px;">
                    <div class="pay-card-h"><i class="fas fa-receipt"></i> Simplified Cash Outflow</div>
                    <div class="pay-card-b">
                        <p class="small text-muted mb-0">Paying a Purchase Order directly affects balances by:</p>
                        <ul class="small text-muted mt-2 ps-3 mb-0" style="line-height:1.4;">
                            <li>Deducting the amount from the selected bank/cash account.</li>
                            <li>Inserting a cash outflow transaction in <i>account_transactions</i>.</li>
                            <li>Posting journal postings in the General Ledger: <b>Dr Accounts Payable (2000)</b> and <b>Cr Bank/Cash (1002/1001)</b>.</li>
                            <li>Updating the Purchase Order payment status to <b>Paid</b>.</li>
                        </ul>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<!-- Payment Modal -->
<div id="paymentModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: #f8fafc; margin: 10% auto; padding: 24px; border: 1px solid #dbe2ea; width: 500px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 20px;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0b1f5d;">Record Supplier Payment</h3>
            <span onclick="closeModal()" style="color: #64748b; float: right; font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
        </div>
        
        <form id="paymentForm">
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" id="modal_po_id" name="po_id">
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Purchase Order</label>
                <input type="text" id="modal_po_number" readonly style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; background: #e2e8f0; font-weight: 600;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Supplier</label>
                <input type="text" id="modal_supplier_name" readonly style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; background: #e2e8f0;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Amount Paid</label>
                    <input type="number" step="0.01" min="0.01" id="modal_amount" name="amount" required style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; font-weight: 600;">
                </div>
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Payment Date</label>
                    <input type="date" id="modal_payment_date" name="payment_date" required style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Bank/Cash Account</label>
                    <select id="modal_bank_account" name="bank_account_id" required style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; background: #fff;">
                        <option value="">-- Select Account --</option>
                        <?php foreach ($financialAccounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" data-balance="<?php echo (float)$acc['current_balance']; ?>" data-currency="<?php echo htmlspecialchars($acc['currency'] ?: 'TZS'); ?>">
                                <?php echo htmlspecialchars($acc['name']); ?> (<?php echo number_format($acc['current_balance'], 2); ?> <?php echo htmlspecialchars($acc['currency'] ?: 'TZS'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Payment Method</label>
                    <select name="payment_method" required style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; background: #fff;">
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Cheque">Cheque</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Reference / Transaction ID</label>
                <input type="text" name="reference_no" placeholder="e.g. TXN-123456789" style="width: 100%; height: 38px; border: 1px solid #dbe2ea; border-radius: 8px; padding: 0 10px; font-size: 13px; background: #fff;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">Notes / Memo</label>
                <textarea name="notes" rows="2" style="width: 100%; border: 1px solid #dbe2ea; border-radius: 8px; padding: 10px; font-size: 13px; resize: vertical; background: #fff;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                <button type="button" onclick="closeModal()" style="height: 38px; border: 1px solid #dbe2ea; background: #fff; border-radius: 8px; padding: 0 16px; font-size: 13px; font-weight: 700; color: #0f172a; cursor: pointer;">Cancel</button>
                <button type="submit" style="height: 38px; border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 8px; padding: 0 16px; font-size: 13px; font-weight: 700; cursor: pointer;">Save Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.pay-tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    if (tab === 'logs') {
        document.getElementById('btn-tab-logs').classList.add('active');
        document.getElementById('tab-logs').style.display = 'block';
        document.getElementById('tab-unpaid').style.display = 'none';
    } else {
        document.getElementById('btn-tab-unpaid').classList.add('active');
        document.getElementById('tab-logs').style.display = 'none';
        document.getElementById('tab-unpaid').style.display = 'block';
    }
}

function openPayModal(poId, poNumber, supplierName, amount) {
    document.getElementById('modal_po_id').value = poId;
    document.getElementById('modal_po_number').value = poNumber;
    document.getElementById('modal_supplier_name').value = supplierName;
    document.getElementById('modal_amount').value = amount;
    document.getElementById('modal_payment_date').value = new Date().toISOString().substring(0, 10);
    document.getElementById('paymentModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('paymentModal');
    if (event.target == modal) {
        closeModal();
    }
}

// Handle Form Submission
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch('supplier-payments.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during submission. Please try again.');
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
