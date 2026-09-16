<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../orders/includes/orders-lib.php';

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['active_module'] = 'sales';
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'sales';
}

salesQuoteRequestsRenderReactShell();
