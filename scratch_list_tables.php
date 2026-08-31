<?php
require_once 'includes/functions.php';
global $pdo;
$payments = $pdo->query("SELECT id, payment_number, amount, payment_date FROM supplier_payments LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "=== Count: " . count($payments) . " ===\n";
foreach ($payments as $p) {
    echo "{$p['id']} | {$p['payment_number']} | {$p['amount']} | {$p['payment_date']}\n";
}
