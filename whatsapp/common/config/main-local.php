<?php

declare(strict_types=1);

$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
$path = str_replace('\\', '/', __DIR__);

$isRoadmaster = str_contains($host, 'roadmasterspares.com')
    || str_contains($path, '/home/roady/');

$isUltimate = str_contains($host, 'ultimate.co.tz')
    || str_contains($path, '/home/ultimate/');

$isLive = $isRoadmaster || $isUltimate || str_contains($path, '/public_html/');

if ($isRoadmaster) {
    $db = [
        'class' => \yii\db\Connection::class,
        'dsn' => 'mysql:host=localhost;dbname=roady_wa_bot',
        'username' => 'roady_mail',
        'password' => '3R-8)fo?ZEXW',
        'charset' => 'utf8mb4',
    ];
} elseif ($isLive) {
    $db = [
        'class' => \yii\db\Connection::class,
        'dsn' => 'mysql:host=localhost;dbname=ultimate_wa_bot',
        'username' => 'ultimate_mail_user',
        'password' => 'Baddyman123!',
        'charset' => 'utf8mb4',
    ];
} else {
    $db = [
        'class' => \yii\db\Connection::class,
        'dsn' => 'mysql:host=localhost;dbname=whatsapp_bot',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ];
}

return [
    'components' => [
        'db' => $db,
    ],
];
