<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$dest = function_exists('user_profile_settings_url') ? user_profile_settings_url() : app_url('/employee/account.php');
header('Location: ' . $dest, true, 302);
exit;
