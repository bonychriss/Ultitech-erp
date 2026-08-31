<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/petty-cash-lib.php';

$voucherId = (int) ($_GET['id'] ?? 0);

pettyCashRenderReactPage('view-voucher', 'Voucher', [
    'voucher_id' => $voucherId,
]);
