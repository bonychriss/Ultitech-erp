<?php
if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = file_exists(__DIR__ . '/../env.local.php') ? 'localhost' : 'ultitech.io';
}
$_GET['company_slug'] = 'roadmaster';
$_SERVER['REQUEST_URI'] = '/x';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../stock/config/database.php';
foreach ($pdo->query('SELECT id, name FROM categories ORDER BY name') as $r) {
    echo $r['id'] . '|' . $r['name'] . PHP_EOL;
}
