<?php
require __DIR__ . '/includes/config.php';

function dumpRows(PDO $pdo, string $label, string $sql, array $params = []): void {
    echo "=== {$label} ===\n";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "(none)\n\n";
        return;
    }
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "\n";
}

$db = $pdo ?? null;
if (!($db instanceof PDO)) {
    fwrite(STDERR, "No PDO\n");
    exit(1);
}

dumpRows(
    $db,
    'PV id=621',
    "SELECT id, voucher_no, status, total_amount, payee_name, purpose, is_paid, paid_at, payment_account_id, description, date_created
     FROM payment_vouchers WHERE id = ?",
    [621]
);

dumpRows(
    $db,
    'PV number like %453%',
    "SELECT id, voucher_no, status, total_amount, payee_name, purpose, is_paid, paid_at, payment_account_id, description, date_created
     FROM payment_vouchers WHERE voucher_no LIKE ? OR voucher_no LIKE ? LIMIT 10",
    ['%453%', '%PV/UGT/2026/453%']
);

dumpRows(
    $db,
    'PV payee LAPTOPSTORE',
    "SELECT id, voucher_no, status, total_amount, payee_name, purpose, is_paid, paid_at, payment_account_id, description, date_created
     FROM payment_vouchers WHERE payee_name LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 10",
    ['%LAPTOPSTORE%', '%LAPTOPSTORE%']
);

if ($db->query("SHOW TABLES LIKE 'account_transactions'")->fetch()) {
    dumpRows(
        $db,
        'account_transactions for PV 621 / 453 / LAPTOPSTORE',
        "SELECT id, account_id, type, amount, reference_type, reference_id, description, transaction_date, created_at
         FROM account_transactions
         WHERE (reference_type IN ('payment_voucher','voucher','expense_voucher') AND reference_id IN (621))
            OR description LIKE ?
            OR description LIKE ?
            OR description LIKE ?
         ORDER BY id DESC LIMIT 30",
        ['%621%', '%453%', '%LAPTOPSTORE%']
    );
}
