<?php
require_once __DIR__ . '/core/Auth.php';
use Core\Auth;

Auth::logout();
header("Location: login.php");
exit;
