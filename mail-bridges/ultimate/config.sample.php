<?php
/**
 * Ultimate.co.tz mail bridge - copy to config.php and fill real values.
 * Deploy under: /home/ultimate/public_html/staff/mail-bridge/
 * Public URL:   https://ultimate.co.tz/staff/mail-bridge
 */
return [
    'brand' => 'Ultimate',
    'domain' => 'ultimate.co.tz',
    'mailbox_email' => 'sales@ultimate.co.tz',
    'from_name' => 'Ultimate Sales',

    // Long random secret Ultitech will send as X-Api-Key
    'api_key' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',

    // Usually mail.ultimate.co.tz or the same host cPanel shows for "Incoming Server"
    'imap_host' => 'mail.ultimate.co.tz',
    'imap_port' => '993',
    'imap_user' => 'sales@ultimate.co.tz',
    'imap_pass' => 'YOUR_MAILBOX_PASSWORD',
    'imap_ssl' => 'ssl',
    'imap_folder' => 'INBOX',

    'smtp_host' => 'mail.ultimate.co.tz',
    'smtp_port' => '465',
    'smtp_user' => 'sales@ultimate.co.tz',
    'smtp_pass' => 'YOUR_MAILBOX_PASSWORD',
    'smtp_secure' => 'ssl', // ssl (465) or tls (587)
];
