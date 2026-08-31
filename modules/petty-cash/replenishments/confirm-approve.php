<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/petty-cash-lib.php';

$repId = (int) ($_GET['rep_id'] ?? 0);
$viewOnly = isset($_GET['view']) && (string) $_GET['view'] === '1';

if ($repId <= 0) {
    header('Location: ' . pettyCashModuleUrl('replenishments/index.php'));
    exit;
}

pettyCashRenderReactPage('replenishment-confirm', $viewOnly ? 'Top-up request' : 'Confirm top-up', [
    'rep_id' => $repId,
    'view_only' => $viewOnly,
]);
