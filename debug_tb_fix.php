<?php
/**
 * Temporary: Ultimate trial balance clean-up (2026-11-12). Preview by default; apply=1 / --apply writes.
 * CLI: php debug_tb_fix.php --db=ultimate_live_copy [--apply]
 * Web: /debug_tb_fix.php?key=...&company=ultimate[&apply=1]
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'tbfix-9Xw3Pq7Z') {
    http_response_code(404);
    exit;
}
@set_time_limit(600);
if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gl/gl_reports.php';

$cliOpts = $isCli ? getopt('', ['db:', 'apply']) : [];
$apply = $isCli ? array_key_exists('apply', $cliOpts) : (($_GET['apply'] ?? '') === '1');
if ($isCli) {
    $pdo = connectToTenantDatabase((string) $cliOpts['db']);
} else {
    $control = $GLOBALS['control_pdo'] ?? $GLOBALS['pdo'];
    $st = $control->prepare('SELECT db_name, db_host, db_user, db_pass FROM companies WHERE company_slug = ? LIMIT 1');
    $st->execute([(string) ($_GET['company'] ?? '')]);
    $co = $st->fetch(PDO::FETCH_ASSOC);
    if (!$co) {
        exit("company not found\n");
    }
    $pdo = connectToTenantDatabase($co['db_name'], $co['db_host'] ?: null, $co['db_user'] ?: null, $co['db_pass'] ?: null);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $pdo;

$n = static fn ($v): string => number_format((float) $v, 2);
$q = static function (string $sql, array $p = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
echo 'DB ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . ($apply ? '  APPLY' : '  PREVIEW (nothing is written)') . "\n";

$bakSuffix = '_bak_tbfix_20261112_';
$backup = static function (string $table, string $where) use ($pdo, $apply, $bakSuffix): void {
    $bak = $bakSuffix . $table;
    if (!$apply) {
        return;
    }
    $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($bak))->fetchColumn();
    if (!$exists) {
        $pdo->exec("CREATE TABLE `{$bak}` AS SELECT * FROM `{$table}` WHERE {$where}");
    }
};

/* 1. Hand-typed revenue entries that duplicate an invoice ---------------- */
echo "\n== 1. Hand-typed revenue entries duplicating invoices\n";
$invoices = [];
foreach ($q("SELECT i.invoice_number, i.total_amount, COALESCE(c.company_name, '') cust FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id") as $r) {
    $invoices[$r['invoice_number']] = $r;
}
$norm = static fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/\b(limited|ltd|co|company|t)\b/i', '', $s)));
$sameCustomer = static function (string $a, string $b) use ($norm): bool {
    $a = $norm($a);
    $b = $norm($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b || str_contains($a, substr($b, 0, 8)) || str_contains($b, substr($a, 0, 8))) {
        return true;
    }
    similar_text($a, $b, $pct);
    return $pct >= 80;
};
$void = [];
$unclear = [];
foreach ($q("SELECT id, voucher_number, entry_date, customer_name, amount_total, total_paid, narration, approval_status
             FROM revenue_entries WHERE (source_invoice_id IS NULL OR source_invoice_id = 0) ORDER BY id") as $re) {
    if (in_array(strtolower((string) $re['approval_status']), ['void', 'voided'], true)) {
        continue;
    }
    $num = null;
    if (preg_match('/INV[\/\- ]*(?:2026)?[\/\- ]*0*(\d{1,5})/i', (string) $re['narration'], $m)) {
        $num = 'INV-2026-' . str_pad($m[1], 4, '0', STR_PAD_LEFT);
    }
    $inv = $num ? ($invoices[$num] ?? null) : null;
    if ($inv && abs((float) $inv['total_amount'] - (float) $re['amount_total']) < 1 && $sameCustomer($inv['cust'], (string) $re['customer_name'])) {
        $void[(int) $re['id']] = ['re' => $re, 'inv' => $num];
    } else {
        $unclear[] = ['re' => $re, 'inv' => $num, 'inv_row' => $inv];
    }
}
echo '  To void: ' . count($void) . ' entries, ' . $n(array_sum(array_map(static fn ($v) => (float) $v['re']['amount_total'], $void))) . "\n";
echo '  Left for review: ' . count($unclear) . ' entries, ' . $n(array_sum(array_map(static fn ($v) => (float) $v['re']['amount_total'], $unclear))) . "\n";
foreach ($unclear as $u) {
    $re = $u['re'];
    printf("    %-16s %s %-30s %14s  note: %s  -> %s\n", $re['voucher_number'], $re['entry_date'], mb_strimwidth((string) $re['customer_name'], 0, 30), $n($re['amount_total']),
        mb_strimwidth((string) $re['narration'], 0, 40), $u['inv_row'] ? ($u['inv'] . ' is ' . $u['inv_row']['cust'] . ' ' . $n($u['inv_row']['total_amount'])) : (($u['inv'] ?? 'no number') . ' does not exist'));
}
if ($apply && $void) {
    $backup('revenue_entries', 'id IN (' . implode(',', array_keys($void)) . ')');
    $up = $pdo->prepare("UPDATE revenue_entries SET approval_status = 'Voided', narration = CONCAT(COALESCE(narration, ''), ?) WHERE id = ?");
    foreach ($void as $id => $v) {
        $up->execute([' [Duplicate of invoice ' . $v['inv'] . ', voided 2026-11-12]', $id]);
    }
    echo "  Voided.\n";
}

/* 2. Test expense of 99,999,999.99 ---------------------------------------- */
echo "\n== 2. Test expense EXP-20260917-452\n";
$exp = $q("SELECT id, amount, status FROM erp_expenses WHERE expense_number = 'EXP-20260917-452' AND ABS(amount - 99999999.99) < 0.01");
if (!$exp) {
    echo "  Not found (already removed?).\n";
} else {
    $expId = (int) $exp[0]['id'];
    $txs = $q("SELECT id, account_id, type, amount FROM account_transactions WHERE reference_type = 'expense' AND reference_id = ?", [$expId]);
    echo "  Expense #{$expId} status {$exp[0]['status']}, " . count($txs) . " money movements to remove\n";
    foreach ($txs as $t) {
        echo "    tx #{$t['id']} account #{$t['account_id']} {$t['type']} {$n($t['amount'])}\n";
    }
    if ($apply) {
        $backup('erp_expenses', "id = {$expId}");
        $backup('account_transactions', "reference_type = 'expense' AND reference_id = {$expId}");
        $backup('financial_accounts', '1 = 1');
        $pdo->beginTransaction();
        foreach ($txs as $t) {
            // current_balance moves +amount for credits and -amount for debits; undo that.
            $undo = $t['type'] === 'credit' ? -(float) $t['amount'] : (float) $t['amount'];
            $pdo->prepare('UPDATE financial_accounts SET current_balance = COALESCE(current_balance, 0) + ? WHERE id = ?')->execute([$undo, (int) $t['account_id']]);
            $pdo->prepare('DELETE FROM account_transactions WHERE id = ?')->execute([(int) $t['id']]);
        }
        $pdo->prepare("UPDATE erp_expenses SET status = 'deleted', is_posted = 0, description = CONCAT('[Test entry removed 2026-11-12] ', COALESCE(description, '')) WHERE id = ?")->execute([$expId]);
        $pdo->commit();
        echo "  Removed.\n";
    }
}

/* 3. Voucher categories -> ledger accounts --------------------------------- */
echo "\n== 3. Voucher category mapping\n";
$findAccount = static function (string $name, string $type) use ($q): int {
    $r = $q('SELECT id FROM erp_accounts WHERE LOWER(TRIM(name)) = LOWER(?) AND LOWER(type) = ? ORDER BY id LIMIT 1', [$name, $type]);
    return (int) ($r[0]['id'] ?? 0);
};
$ensureAccount = static function (string $name, string $type, string $code) use ($pdo, $q, $findAccount, $apply): int {
    $id = $findAccount($name, $type);
    if ($id > 0 || !$apply) {
        return $id;
    }
    $c = $code;
    for ($i = 0; $q('SELECT 1 FROM erp_accounts WHERE code = ?', [$c]); $i++) {
        $c = (string) ((int) $code + $i + 1);
    }
    $pdo->prepare("INSERT INTO erp_accounts (code, name, type, description, status, is_postable) VALUES (?, ?, ?, 'Voucher category account', 'active', 1)")
        ->execute([$c, $name, $type]);
    return (int) $pdo->lastInsertId();
};
$map = [
    'Procurement & Supplies' => ['Sales Cost of Goods Sold', 'expense', '6000'],
    'Employee Costs' => ['Salaries & Wages', 'expense', '5005'],
    'Operational Expenses' => ['Operating Expenses', 'expense', '5012'],
    'Logistics & Delivery' => ['Travel & Transport', 'expense', '5002'],
    'Sales & Marketing' => ['Marketing & Advertising', 'expense', '5006'],
    'Administration & Management' => ['Professional Fees', 'expense', '5007'],
    'Others / Miscellaneous' => ['Miscellaneous Expenses', 'expense', '5010'],
    'Projects & Capital Expenditure (CAPEX)' => ['Fixed Assets', 'asset', '1500'],
    'Tax & Compliance' => ['Tax Payable', 'liability', '2200'],
    'Financial Obligations' => ['Loans Payable', 'liability', '2300'],
];
if ($apply) {
    gl_ensure_schema($pdo);
    $backup('gl_category_map', '1 = 1');
}
foreach ($map as $category => [$name, $type, $code]) {
    $accId = $ensureAccount($name, $type, $code);
    echo "  {$category} -> {$name} ({$type})" . ($accId ? " #{$accId}" : ' (will be created)') . "\n";
    if ($apply && $accId) {
        $pdo->prepare('INSERT INTO gl_category_map (category, account_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)')->execute([$category, $accId]);
    }
}

/* 4. Vouchers paid before bank records ------------------------------------ */
echo "\n== 4. Vouchers paid before bank records\n";
$cutoff = '2026-06-15';
$ub = $q("SELECT COUNT(*) n, COALESCE(SUM(total_amount), 0) a, MIN(d) first_d, MAX(d) last_d FROM (
            SELECT pv.total_amount, DATE(COALESCE(pv.paid_at, pv.approved_at, pv.date_created, pv.created_at)) d
            FROM payment_vouchers pv
            WHERE LOWER(pv.status) = 'approved' AND pv.is_paid = 1
              AND NOT EXISTS (SELECT 1 FROM account_transactions t WHERE t.reference_type = 'payment_voucher' AND t.reference_id = pv.id)) x")[0];
$after = $q("SELECT COUNT(*) n FROM payment_vouchers pv WHERE LOWER(pv.status) = 'approved' AND pv.is_paid = 1
             AND DATE(COALESCE(pv.paid_at, pv.approved_at, pv.date_created, pv.created_at)) > ?
             AND NOT EXISTS (SELECT 1 FROM account_transactions t WHERE t.reference_type = 'payment_voucher' AND t.reference_id = pv.id)", [$cutoff])[0]['n'];
echo "  Paid with no money movement: {$ub['n']} vouchers, {$n($ub['a'])}, {$ub['first_d']} to {$ub['last_d']}; after cutoff {$cutoff}: {$after}\n";
if ($apply) {
    gl_meta_set($pdo, 'unbanked_pv_cutoff', $cutoff);
}

/* 5. Build the ledger and switch the reports ------------------------------ */
echo "\n== 5. Ledger\n";
if ($apply) {
    $res = gl_bridge_sync_all($pdo);
    echo "  Sync: {$res['posted']} posted, {$res['reversed']} reversed " . json_encode($res['by_type']) . "\n";
    foreach (array_slice($res['errors'], 0, 10) as $e) {
        echo "  ERROR {$e}\n";
    }
    if (!$res['errors']) {
        gl_meta_set($pdo, 'autosync', '1');
        gl_meta_set($pdo, 'report_source', 'ledger');
        echo "  Reports now read from the ledger.\n";
    }
} else {
    echo "  (preview: sync not run)\n";
}
echo "\nDone.\n";
