<?php
// session_start();
require_once '../../config/functions.php';

// Unset all session variables
$_SESSION = array();

// Destroy the session.
session_destroy();

redirect('/stock/modules/auth/login.php');
exit;
?>
