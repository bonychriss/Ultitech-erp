<?php
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/erp-laravel/vendor/autoload.php';
$app = require __DIR__ . '/erp-laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = 'new_trading_voucher-35313030c7e2';
config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => DB_HOST,
    'database.connections.mysql.username' => DB_USER,
    'database.connections.mysql.password' => DB_PASS,
    'database.connections.mysql.database' => $db,
]);
app('db')->purge('mysql');

try {
    App\Domains\CashBook\Schema::ensure();
    echo "ensure ok\n";
    $c = Illuminate\Support\Facades\DB::table('cash_books')->count();
    echo "books={$c}\n";
    $t = Illuminate\Support\Facades\DB::select("SHOW TABLES LIKE 'cash_book_delete_requests'");
    echo 'del_table=' . (count($t) ? 'yes' : 'no') . "\n";
    $svc = new App\Domains\CashBook\CashBookService();
    $books = $svc->listBooks('active');
    echo 'list=' . count($books) . "\n";
    foreach ($books as $b) {
        echo ($b['name'] ?? '') . ' del=' . json_encode($b['delete_request'] ?? null) . "\n";
    }
    $pending = $svc->listPendingDeleteRequests();
    echo 'pending=' . count($pending) . "\n";
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}
