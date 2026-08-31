<?php
/**
 * Roadmaster Spares mail bridge
 * Upload this folder to: public_html/staff/roadmaster/ (Roadmaster cPanel)
 * Public URL: https://roadmasterspares.com/staff/roadmaster
 *
 * Mailbox: sales@roadmasterspares.com
 */
return [
    'brand' => 'Roadmaster',
    'domain' => 'roadmasterspares.com',
    'mailbox_email' => 'sales@roadmasterspares.com',
    'from_name' => 'ROADMASTER SPARES LIMITED',

    // Use this same key in Ultitech ? Email settings ? Remote Bridges
    'api_key' => 'Ultitech_Roadmaster_Bridge_9mKp2xR7vN4wQ6tY',

    'imap_host' => 'mail.roadmasterspares.com',
    'imap_port' => '993',
    'imap_user' => 'sales@roadmasterspares.com',
    'imap_pass' => 'Tj5fee6d1',
    'imap_ssl' => 'ssl',
    'imap_folder' => 'INBOX',

    'smtp_host' => 'mail.roadmasterspares.com',
    'smtp_port' => '465',
    'smtp_user' => 'sales@roadmasterspares.com',
    'smtp_pass' => 'Tj5fee6d1',
    'smtp_secure' => 'ssl',
];
