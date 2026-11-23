<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/paths.php';
requireLogin();
redirect('dashboard.php');
