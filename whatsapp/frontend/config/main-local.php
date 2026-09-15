<?php

$host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
$path = str_replace('\\', '/', __DIR__);
$isLive = str_contains($host, 'roadmasterspares.com')
    || str_contains($host, 'ultimate.co.tz')
    || str_contains($path, '/public_html/')
    || str_contains($path, '/home/roady/')
    || str_contains($path, '/home/ultimate/');

$config = [
    'components' => [
        'request' => [
            'cookieValidationKey' => $isLive
                ? 'WaB0tLiveCookieKey9xK2pQ7wR'
                : 'WaB0tLocalCookieKeyBfJtycS7sI',
        ],
    ],
];

if (!defined('YII_ENV') || YII_ENV_DEV) {
    if (!$isLive) {
        $config['bootstrap'][] = 'debug';
        $config['modules']['debug'] = [
            'class' => \yii\debug\Module::class,
        ];
        $config['bootstrap'][] = 'gii';
        $config['modules']['gii'] = [
            'class' => \yii\gii\Module::class,
        ];
    }
}

return $config;
