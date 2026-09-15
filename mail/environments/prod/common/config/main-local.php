<?php

return [
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                'viewPath' => '@common/mail',
            ],
        ],
    ],
    'components' => [
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => 'mysql:host=localhost;dbname=ultimate_mail_app',
            'username' => 'ultimate_mail_user',
            'password' => 'Baddyman123!',
            'charset' => 'utf8mb4',
        ],
        'mailer' => \yii\mail\MailerInterface::class,
    ],
];
