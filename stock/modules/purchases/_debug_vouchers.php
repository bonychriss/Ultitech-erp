<?php
/**
 * Temporary diagnostic  delete after testing.
 * Access: stock/modules/purchases/_debug_vouchers.php?po_id=113
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 3) . '/modules/balances/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$poId = (int) ($_GET['po_id'] ?? 113);
$companyId = (int) (currentCompanyId() ?? 0);

echo "company_id session: $companyId\n\n";

foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $i => $pvPdo) {
    try {
        $db = $pvPdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        $db = '?';
    }
    echo "PDO #$i database: $db\n";
    if (!tableExists('payment_vouchers', $pvPdo)) {
        echo "  (no payment_vouchers table)\n\n";
        continue;
    }
    $approved = (int) $pvPdo->query("SELECT COUNT(*) FROM payment_vouchers WHERE LOWER(TRIM(status)) = 'approved'")->fetchColumn();
    echo "  approved: $approved\n";
    $cols = $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (in_array('purpose', $cols, true)) {
        $sp = (int) $pvPdo->query("SELECT COUNT(*) FROM payment_vouchers WHERE LOWER(TRIM(status)) = 'approved' AND LOWER(TRIM(purpose)) = 'stock_purchase'")->fetchColumn();
        echo "  approved + purpose stock_purchase: $sp\n";
    }
    echo "\n";
}

$pvPdo = stockPurchasePaymentVouchersPdo($pdo);
echo "Primary PV PDO: " . $pvPdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
$sample = $pvPdo->query("SELECT id, voucher_no, status, purpose, payment_purpose, is_paid, linked_stock_po_id FROM payment_vouchers WHERE LOWER(TRIM(purpose)) = 'stock_purchase' OR LOWER(TRIM(payment_purpose)) = 'stock_purchase' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "stock_purchase sample rows:\n";
print_r($sample);

foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $i => $pvPdo) {
    $db = $pvPdo->query('SELECT DATABASE()')->fetchColumn();
    if (!tableExists('payment_vouchers', $pvPdo)) {
        continue;
    }
    $pvCols = $pvPdo->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $paramsFb = [];
    $whereFb = ["LOWER(TRIM(COALESCE(pv.status, ''))) = 'approved'"];
    if ($poId > 0 && in_array('linked_stock_po_id', $pvCols, true)) {
        $whereFb[] = '(pv.linked_stock_po_id IS NULL OR pv.linked_stock_po_id = 0 OR pv.linked_stock_po_id = ?)';
        $paramsFb[] = $poId;
    }
    $rows = queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereFb, $paramsFb);
    echo "PDO #$i ($db) fallback query rows: " . count($rows) . "\n";
    foreach (array_slice($rows, 0, 3) as $r) {
        echo '  id=' . ($r['id'] ?? '') . ' purpose=' . resolvePaymentVoucherPurposeFromRow($r) . ' paid=' . ($r['is_paid'] ?? '') . ' linked=' . ($r['linked_stock_po_id'] ?? '') . "\n";
    }
    $paramsAw = [];
    $whereAw = buildStockPurchaseDeskAwaitingPoWhereParts($pvCols, $companyId, $paramsAw);
    $rowsAw = queryStockPurchasePaymentVoucherPickerRows($pvPdo, $pvCols, $whereAw, $paramsAw);
    echo "  awaiting_po query rows: " . count($rowsAw) . "\n";
}

$paramsCls = [];
$pvPdoErp = null;
foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $c) {
    if (tableExists('payment_vouchers', $c)) {
        $n = (int) $c->query("SELECT COUNT(*) FROM payment_vouchers WHERE LOWER(TRIM(status))='approved'")->fetchColumn();
        if ($n > 0) {
            $pvPdoErp = $c;
            break;
        }
    }
}
foreach (stockPurchasePaymentVoucherPdoCandidates($pdo) as $c) {
    if (!tableExists('payment_vouchers', $c)) {
        continue;
    }
    $db = $c->query('SELECT DATABASE()')->fetchColumn();
    $cols = $c->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $sel = ['id', 'voucher_no', 'status'];
    foreach (['purpose', 'payment_purpose', 'voucher_purpose', 'is_paid', 'linked_stock_po_id', 'company_id'] as $col) {
        if (in_array($col, $cols, true)) {
            $sel[] = $col;
        }
    }
    $purposeWhere = buildPaymentVoucherStockPurchasePurposeWhereSql('pv', $cols);
    $sql = 'SELECT ' . implode(', ', array_map(static fn ($x) => "pv.`$x`", $sel)) . " FROM payment_vouchers pv WHERE LOWER(TRIM(pv.status))='approved' AND ($purposeWhere) LIMIT 10";
    echo "Raw stock_purchase rows on $db:\n";
    try {
        foreach ($c->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            print_r($row);
        }
    } catch (Throwable $e) {
        echo '  ERR: ' . $e->getMessage() . "\n";
    }
    break;
}

if ($pvPdoErp) {
    $pvCols = $pvPdoErp->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $whereCls = buildStockPurchasePoClassificationVoucherWhereParts($pvPdoErp, $companyId, $poId, $paramsCls, $pvCols);
    $rowsCls = queryStockPurchasePaymentVoucherPickerRows($pvPdoErp, $pvCols, $whereCls, $paramsCls);
    echo "buildStockPurchasePoClassificationVoucherWhereParts count: " . count($rowsCls) . "\n";
    foreach ($rowsCls as $r) {
        echo '  ' . ($r['voucher_no'] ?? $r['id']) . ' | ' . resolvePaymentVoucherPurposeFromRow($r) . "\n";
    }
}

$list = fetchStockPurchasePoVouchersForClassificationEdit($pdo, $companyId, $poId, []);
echo "fetchStockPurchasePoVouchersForClassificationEdit count: " . count($list) . "\n";
foreach (array_slice($list, 0, 10) as $pv) {
    echo '  - ' . ($pv['voucher_no'] ?? $pv['id']) . ' | ' . ($pv['payee_name'] ?? '') . ' | purpose=' . resolvePaymentVoucherPurposeFromRow($pv) . ' | paid=' . ($pv['is_paid'] ?? '') . ' | linked_po=' . ($pv['linked_stock_po_id'] ?? '') . "\n";
}
