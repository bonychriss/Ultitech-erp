<?php
/**
 * Roadmaster Spares mail bridge  copy to config.php and fill real values.
 * Upload this whole folder to the Roadmaster cPanel (e.g. public_html/mail-bridge/).
 */
return [
    'brand' => 'Roadmaster',
    'domain' => 'roadmasterspares.com',
    'mailbox_email' => 'sales@roadmasterspares.com',
    'from_name' => 'ROADMASTER SPARES LIMITED',

    // Long random secret Ultitech will send as X-Api-Key
    'api_key' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',

    // Usually mail.roadmasterspares.com or the host cPanel shows for "Incoming Server"
    'imap_host' => 'mail.roadmasterspares.com',
    'imap_port' => '993',
    'imap_user' => 'sales@roadmasterspares.com',
    'imap_pass' => 'YOUR_MAILBOX_PASSWORD',
    'imap_ssl' => 'ssl',
    'imap_folder' => 'INBOX',

    'smtp_host' => 'mail.roadmasterspares.com',
    'smtp_port' => '465',
    'smtp_user' => 'sales@roadmasterspares.com',
    'smtp_pass' => 'YOUR_MAILBOX_PASSWORD',
    'smtp_secure' => 'ssl', // ssl (465) or tls (587)
];
