<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,
    'mail.secretKey' => 'mail-app-erp-secret-change-me',
    // Must match Ultitech includes/mail-sso.php → mail_sso_shared_secret()
    'mail.ssoSecret' => 'UltitechMailSso_v1_9mKp2xR7vN4wQ6tY_change_in_prod',
];
