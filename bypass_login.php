<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['department'] = 'Finance';
$_SESSION['name'] = 'System Admin';
$_SESSION['email'] = 'admin@example.com';
header("Location: revenue_entries.php?module=revenue");
exit;
?>
