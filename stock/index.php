<?php
require_once 'config/functions.php';
// Start session to ensure auto-login works immediately
// session_start();
requireLogin();
redirect('dashboard.php');
?>
