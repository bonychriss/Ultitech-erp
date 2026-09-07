<?php
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
require __DIR__ . '/../includes/config.php';

echo "Control DB: " . $control_pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
$st = $control_pdo->query("SELECT id, company_slug, db_name, db_host FROM companies WHERE company_slug IN ('ultimate','roadmaster')");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo 'company ' . json_encode($r) . "\n";
}

// Check shared/main DB for roadmaster vouchers
$mainPdo = $pdo;
echo "\nDefault pdo DB: " . $mainPdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

if (defined('DATA_DB_NAME')) {
    echo 'DATA_DB_NAME=' . DATA_DB_NAME . "\n";
}

// Connect to data db explicitly
$dataDb = defined('DATA_DB_NAME') ? DATA_DB_NAME : DB_NAME;
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $dataDb . ';charset=utf8mb4';
    $dataPdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "\nData DB {$dataDb} payment_vouchers by company_id:\n";
    if (tableExists('payment_vouchers', $dataPdo)) {
        $hasCompany = columnExists('payment_vouchers', 'company_id', $dataPdo);
        echo 'has company_id column: ' . ($hasCompany ? 'yes' : 'no') . "\n";
        if ($hasCompany) {
            $st = $dataPdo->query('SELECT company_id, status, COUNT(*) c FROM payment_vouchers GROUP BY company_id, status ORDER BY company_id, c DESC');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                echo '  ' . json_encode($r) . "\n";
            }
        } else {
            echo 'total vouchers: ' . $dataPdo->query('SELECT COUNT(*) FROM payment_vouchers')->fetchColumn() . "\n";
        }
    }
} catch (Throwable $e) {
    echo 'data db error: ' . $e->getMessage() . "\n";
}

// Roadmaster tenant DB direct
$rm = $control_pdo->query("SELECT db_name, db_host FROM companies WHERE company_slug='roadmaster' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($rm) {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . $rm['db_name'] . ';charset=utf8mb4';
        $rmPdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "\nRoadmaster tenant DB {$rm['db_name']} voucher count: " . $rmPdo->query('SELECT COUNT(*) FROM payment_vouchers')->fetchColumn() . "\n";
        $st = $rmPdo->query('SELECT id, voucher_no, status FROM payment_vouchers ORDER BY id DESC LIMIT 5');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo '  ' . json_encode($r) . "\n";
        }
    } catch (Throwable $e) {
        echo 'roadmaster db error: ' . $e->getMessage() . "\n";
    }
}
