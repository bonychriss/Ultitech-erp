<?php

declare(strict_types=1);

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php',
);

return [
    'id' => 'app-frontend',
    'name' => 'WhatsApp Bot',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'frontend\controllers',
    'homeUrl' => ['/app/index'],
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-wa',
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'user' => [
            'identityClass' => \common\models\User::class,
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-wa', 'httpOnly' => true],
        ],
        'session' => [
            'name' => 'whatsapp-frontend',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                '' => 'app/index',
                'app' => 'app/index',
                'app/<path:.*>' => 'app/index',
                'api/bootstrap' => 'api/bootstrap',
                'api/login' => 'api/login',
                'api/logout' => 'api/logout',
                'api/contacts' => 'api/contacts',
                'api/contacts/<id:\d+>' => 'api/contact',
                'api/send' => 'api/send',
                'api/messages' => 'api/messages',
                'api/campaigns' => 'api/campaigns',
                'api/campaigns/<id:\d+>/send' => 'api/campaign-send',
                'api/settings' => 'api/settings',
                'api/webhook' => 'api/webhook',
            ],
        ],
    ],
    'params' => $params,
];
