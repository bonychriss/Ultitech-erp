<?php
// Bootstrap shared app auth + DB, then stock helpers.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';

// Start session to ensure auto-login works immediately
// session_start();
requireLogin();
redirect('dashboard.php');
?>
