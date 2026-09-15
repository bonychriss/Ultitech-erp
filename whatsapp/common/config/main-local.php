<?php

declare(strict_types=1);

$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
$path = str_replace('\\', '/', __DIR__);

$isLocal = str_contains(strtolower($path), '/xampp/')
    || str_contains($host, 'localhost')
    || str_contains($host, '127.0.0.1');

$isRoadmaster = !$isLocal && (
    str_contains($host, 'roadmasterspares.com')
    || str_contains($path, '/home/roady/')
);

$isUltimate = !$isLocal && (
    str_contains($host, 'ultimate.co.tz')
    || str_contains($host, 'ultitech.io')
    || str_contains($path, '/home/ultimate/')
    || str_contains($path, '/home/sites/')
    || str_contains($path, '/public_html/')
);

if ($isRoadmaster) {
    $db = [
        'class' => \yii\db\Connection::class,
        'dsn' => 'mysql:host=localhost;dbname=roady_mail-353036334069',
        'username' => 'roady_mail',
        'password' => '3R-8)fo?ZEXW',
        'charset' => 'utf8mb4',
        'tablePrefix' => 'wb_',
    ];
} elseif ($isUltimate) {
    // Reuse ERP StackCP MySQL from public_html/env.php (no localhost socket).
    $envFile = dirname(__DIR__, 3) . '/env.php';
    $dbHost = 'sdb-86.hosting.stackcp.net';
    $dbName = 'ultimate_trading-35313030f83f';
    $dbUser = 'ultimategeneraltrading';
    $dbPass = '';
    if (is_file($envFile)) {
        /** @noinspection PhpIncludeInspection */
        include $envFile;
        $dbHost = (string) ($DB_HOST ?? $dbHost);
        $dbName = (string) ($DB_NAME ?? $dbName);
        $dbUser = (string) ($DB_USER ?? $dbUser);
        $dbPass = (string) ($DB_PASS ?? '');
    }
    $db = [
        'class' => \yii\db\Connection::class,
        'dsn' => 'mysql:host=' . $dbHost . ';dbname=' . $dbName,
        'username' => $dbUser,
        'password' => $dbPass,
        'charset' => 'utf8mb4',
        'tablePrefix' => 'wb_',
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
