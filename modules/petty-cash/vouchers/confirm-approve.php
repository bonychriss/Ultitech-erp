<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/petty-cash-lib.php';

$voucherId = (int) ($_GET['voucher_id'] ?? $_GET['id'] ?? 0);
if ($voucherId <= 0) {
    header('Location: ' . pettyCashModuleUrl('vouchers/index.php'));
    exit;
}

pettyCashRenderReactPage('view-voucher', 'Voucher', [
    'voucher_id' => $voucherId,
]);
