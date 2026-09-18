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
    'name' => 'Mail',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'frontend\controllers',
    'homeUrl' => ['/app/index'],
    'components' => [
        'assetManager' => [
            'appendTimestamp' => true,
        ],
        'request' => [
            'csrfParam' => '_csrf-frontend',
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'user' => [
            'identityClass' => \common\models\User::class,
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-frontend', 'httpOnly' => true],
        ],
        'session' => [
            // this is the name of the session cookie used for login on the frontend
            'name' => 'advanced-frontend',
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
                'api/signup' => 'api/signup',
                'api/logout' => 'api/logout',
                'api/sign-out-mailbox' => 'api/sign-out-mailbox',
                'api/folders' => 'api/folders',
                'api/messages' => 'api/messages',
                'api/messages/<id:\d+>' => 'api/message',
                'api/send' => 'api/send',
                'api/draft' => 'api/draft',
                'api/star/<id:\d+>' => 'api/star',
                'api/trash/<id:\d+>' => 'api/trash',
                'api/archive/<id:\d+>' => 'api/archive',
                'api/bulk' => 'api/bulk',
                'api/sync' => 'api/sync',
                'api/attachments/<id:\d+>' => 'api/attachment',
                'api/accounts' => 'api/accounts',
                'api/accounts/<id:\d+>' => 'api/account',
                'api/available-mailboxes' => 'api/available-mailboxes',
                'api/claim-mailbox' => 'api/claim-mailbox',
                'api/open-mailbox' => 'api/open-mailbox',
                'api/pool-accounts' => 'api/pool-accounts',
                'api/pool-accounts/<id:\d+>' => 'api/pool-account',
                'mail' => 'mail/index',
                'mail/attachment/<id:\d+>' => 'mail/attachment',
                'mail/<action:\w+>' => 'mail/<action>',
                'account/<action:\w+>' => 'account/<action>',
            ],
        ],
    ],
    'params' => $params,
];
