<?php
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/erp-laravel/vendor/autoload.php';
$app = require __DIR__ . '/erp-laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['erp.app_root' => str_replace('\\', '/', __DIR__)]);

$shell = new App\Domains\CashBook\CashBookShell();
$apiBase = rtrim(app_url('/modules/petty-cash/api'), '/');
$vd = $shell->viewData([
    'apiBase' => $apiBase,
    'cashBookPage' => 'books',
    'pageTitle' => 'Cash Book',
    'headerTitle' => 'Cash Book',
    'companySlug' => 'ultimate',
    'backUrl' => '',
    'bookId' => 0,
]);
if ($vd === null) {
    echo "NULL viewData\n";
    exit(1);
}
if (preg_match('/window\.__CASHBOOK_API_BASE__ = (.+?);window\.__CASHBOOK_PAGE_BASE__ = (.+?);/', $vd['headMarkup'], $m)) {
    echo "API_BASE={$m[1]}\n";
    echo "PAGE_BASE={$m[2]}\n";
} else {
    echo "NO MATCH\n";
}
echo "apiBase var={$apiBase}\n";
