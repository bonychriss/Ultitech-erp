<?php
// SMTP Configuration
// Please fill in your email provider's details here.

define('SMTP_HOST', 'mail.ultimate.co.tz');      // Local cPanel host
define('SMTP_PORT', 465);                   // 465 for SSL with local mail
define('SMTP_USER', 'ultimatesystem@ultimate.co.tz'); // New local email
define('SMTP_PASS', 'Baddyman@12345.');    // New password
define('SMTP_FROM_EMAIL', 'ultimatesystem@ultimate.co.tz'); // "From" address must match user
define('SMTP_FROM_NAME', 'Ultimate System'); // "From" name
define('SMTP_SECURE', 'ssl');               // 'ssl' for port 465

// NOTE: For Gmail, you MUST use an "App Password" if 2FA is enabled.
// Go to Google Account > Security > 2-Step Verification > App passwords.
