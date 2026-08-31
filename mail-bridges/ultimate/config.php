<?php
/**
 * Ultimate.co.tz mail bridge
 * Upload this file to: /home/ultimate/public_html/staff/ultimate/config.php
 * Public URL: https://ultimate.co.tz/staff/ultimate
 *
 * IMPORTANT: Replace YOUR_MAILBOX_PASSWORD below with the real
 * password for sales@ultimate.co.tz before using.
 */
return [
    'brand' => 'Ultimate',
    'domain' => 'ultimate.co.tz',
    'mailbox_email' => 'sales@ultimate.co.tz',
    'from_name' => 'ULTIMATE GENERAL TRADING',

    // Use this same key in Ultitech ? Email settings ? Remote Bridges
    'api_key' => 'Ultitech_Ultimate_Bridge_7kQm9xP2vL4nR8wH',

    'imap_host' => 'mail.ultimate.co.tz',
    'imap_port' => '993',
    'imap_user' => 'sales@ultimate.co.tz',
    'imap_pass' => 'ULTIMATESALES@2026!',
    'imap_ssl' => 'ssl',
    'imap_folder' => 'INBOX',

    'smtp_host' => 'mail.ultimate.co.tz',
    'smtp_port' => '465',
    'smtp_user' => 'sales@ultimate.co.tz',
    'smtp_pass' => 'ULTIMATESALES@2026!',
    'smtp_secure' => 'ssl',
];
