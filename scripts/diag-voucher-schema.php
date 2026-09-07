<?php
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
require __DIR__ . '/../includes/config.php';

function connectDb($name) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$ultimateDb = 'new_trading_voucher-35313030c7e2';
$roadmasterDb = 'roadmaster_db-35313030b5e8';

$tables = ['payment_vouchers', 'approvals', 'voucher_items', 'users', 'document_sequences', 'payees'];

foreach (['ultimate' => $ultimateDb, 'roadmaster' => $roadmasterDb] as $label => $dbName) {
    echo "\n=== {$label} ({$dbName}) ===\n";
    try {
        $p = connectDb($dbName);
    } catch (Throwable $e) {
        echo 'connect error: ' . $e->getMessage() . "\n";
        continue;
    }
    foreach ($tables as $t) {
        $exists = tableExists($t, $p);
        echo "  {$t}: " . ($exists ? 'yes' : 'NO') . "\n";
        if (!$exists) continue;
        $cnt = (int) $p->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "    rows: {$cnt}\n";
    }
    if (tableExists('payment_vouchers', $p)) {
        $cols = $p->query('SHOW COLUMNS FROM payment_vouchers')->fetchAll(PDO::FETCH_COLUMN);
        $needed = ['status', 'applicant', 'department_manager', 'checked_by', 'general_manager', 'is_paid', 'is_posted', 'approved_by', 'company_id'];
        foreach ($needed as $c) {
            echo '    col ' . $c . ': ' . (in_array($c, $cols, true) ? 'yes' : 'MISSING') . "\n";
        }
    }
    if (tableExists('approvals', $p)) {
        $cols = $p->query('SHOW COLUMNS FROM approvals')->fetchAll(PDO::FETCH_COLUMN);
        $needed = ['voucher_id', 'approver_id', 'approver_name', 'role', 'status', 'signature_path', 'approved_at', 'company_id'];
        foreach ($needed as $c) {
            echo '    approvals.' . $c . ': ' . (in_array($c, $cols, true) ? 'yes' : 'MISSING') . "\n";
        }
    }
    if (tableExists('document_sequences', $p)) {
        $st = $p->query("SELECT * FROM document_sequences WHERE doc_type LIKE '%voucher%' OR doc_type LIKE '%payment%'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo '    sequence ' . json_encode($r) . "\n";
        }
    }
}
