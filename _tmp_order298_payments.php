<?php
$dbs = ['ultimate_live_copy', 'ultimate_trading-35313030f83f'];
foreach ($dbs as $dbName) {
    echo "=== DB {$dbName} ===\n";
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname={$dbName};charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        echo $e->getMessage() . "\n";
        continue;
    }

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $interesting = array_values(array_filter($tables, static function ($t) {
        return preg_match('/invoice|revenue|sales_order|account_transaction/i', (string) $t);
    }));
    echo "interesting tables: " . implode(', ', $interesting) . "\n";

    if (!in_array('sales_orders', $tables, true) && !in_array('invoices', $tables, true)) {
        continue;
    }

    $orderId = 298;
    if (in_array('sales_orders', $tables, true)) {
        $st = $pdo->prepare('SELECT id, order_number, status FROM sales_orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        echo "ORDER:\n";
        print_r($st->fetch(PDO::FETCH_ASSOC));
    }

    if (!in_array('invoices', $tables, true)) {
        continue;
    }

    $inv = $pdo->prepare('SELECT id, invoice_number, status, amount_paid, total_amount, balance_due, order_id FROM invoices WHERE order_id = ? LIMIT 5');
    $inv->execute([$orderId]);
    $invoices = $inv->fetchAll(PDO::FETCH_ASSOC);
    echo "INVOICES:\n";
    print_r($invoices);

    foreach ($invoices as $i) {
        $iid = (int) $i['id'];
        if (in_array('revenue_entries', $tables, true)) {
            $st = $pdo->prepare('SELECT id, total_paid, payment_status, attachment, source_invoice_id FROM revenue_entries WHERE source_invoice_id = ?');
            $st->execute([$iid]);
            $entries = $st->fetchAll(PDO::FETCH_ASSOC);
            echo "REVENUE_ENTRIES for invoice {$iid}:\n";
            print_r($entries);
        } else {
            $entries = [];
        }

        if (in_array('revenue_collections', $tables, true) && $entries) {
            $st = $pdo->prepare(
                'SELECT id, entry_id, collection_date, amount_collected
                 FROM revenue_collections
                 WHERE entry_id IN (SELECT id FROM revenue_entries WHERE source_invoice_id = ?)
                 ORDER BY id DESC'
            );
            $st->execute([$iid]);
            echo "COLLECTIONS:\n";
            print_r($st->fetchAll(PDO::FETCH_ASSOC));
        }

        if (in_array('account_transactions', $tables, true)) {
            $st = $pdo->prepare(
                "SELECT id, amount, description, reference_type, reference_id, transaction_date
                 FROM account_transactions
                 WHERE type = 'credit'
                   AND (
                     (reference_type IN ('revenue_collection', 'revenue_entry') AND reference_id IN (
                       SELECT id FROM revenue_entries WHERE source_invoice_id = ?
                     ))
                     OR (reference_type = 'invoice_payment' AND reference_id = ?)
                   )
                 ORDER BY id DESC
                 LIMIT 20"
            );
            try {
                $st->execute([$iid, $iid]);
                echo "TXNS:\n";
                print_r($st->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) {
                echo "TXNS error: " . $e->getMessage() . "\n";
            }
        }

        foreach ($entries as $entry) {
            $eid = (int) $entry['id'];
            $files = glob(__DIR__ . '/uploads/revenue/payments/pay_' . $eid . '_*') ?: [];
            echo "PROOF FILES for entry {$eid}:\n";
            print_r(array_map('basename', $files));
        }
    }
}
