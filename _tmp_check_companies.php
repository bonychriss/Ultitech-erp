<?php
require __DIR__ . '/includes/config.php';

function db_info(PDO $pdo, string $label): void {
    $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "=== $label ($name) ===\n";
    foreach (['products', 'sales_orders', 'payment_vouchers', 'brands', 'categories'] as $t) {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
            if (!$exists) {
                echo "$t: MISSING\n";
                continue;
            }
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $hasCid = (bool) $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'company_id'")->fetch();
            echo "$t: rows=$n company_id=" . ($hasCid ? 'yes' : 'no') . "\n";
        } catch (Throwable $e) {
            echo "$t: ERR " . $e->getMessage() . "\n";
        }
    }
}

global $control_pdo;
db_info($control_pdo, 'control');

$data = new PDO('mysql:host=127.0.0.1;dbname=new_trading_voucher-35313030c7e2;charset=utf8mb4', 'root', '');
db_info($data, 'data');
