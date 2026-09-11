<?php
/**
 * Local smoke: boot cashbook schema + init payload against tenant DB.
 */
require __DIR__ . '/includes/functions.php';

// Mimic logged-in ultimate session enough for tenant switch if possible
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require __DIR__ . '/erp-laravel/vendor/autoload.php';
$app = require __DIR__ . '/erp-laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Use same DB as local env
$dbName = defined('DB_NAME') ? (string) DB_NAME : '';
echo "Default DB_NAME={$dbName}\n";

try {
    global $pdo;
    if ($pdo instanceof PDO) {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        echo "PDO DATABASE()={$dbName}\n";
    }
} catch (Throwable $e) {
    echo "PDO err: {$e->getMessage()}\n";
}

if ($dbName !== '') {
    config(['database.default' => 'mysql']);
    if (defined('DB_HOST')) config(['database.connections.mysql.host' => DB_HOST]);
    if (defined('DB_USER')) config(['database.connections.mysql.username' => DB_USER]);
    if (defined('DB_PASS')) config(['database.connections.mysql.password' => DB_PASS]);
    config(['database.connections.mysql.database' => $dbName]);
    app('db')->purge('mysql');
}

try {
    App\Domains\CashBook\Schema::ensure();
    echo "Schema::ensure OK\n";
    $exists = Illuminate\Support\Facades\DB::select("SHOW TABLES LIKE 'cash_book_delete_requests'");
    echo 'delete_requests table: ' . (count($exists) ? 'YES' : 'NO') . "\n";
    $svc = new App\Domains\CashBook\CashBookService();
    $books = $svc->listBooks('active');
    echo 'books=' . count($books) . "\n";
    $pending = $svc->listPendingDeleteRequests();
    echo 'pending_deletes=' . count($pending) . "\n";
    echo "OK\n";
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
