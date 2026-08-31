<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once './functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

require __DIR__ . '/catalogue-ui/render-catalogue.php';
