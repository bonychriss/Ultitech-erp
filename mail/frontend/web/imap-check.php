<?php
/**
 * Quick IMAP capability check. Delete after use.
 * https://ultimate.co.tz/staff/mail/frontend/web/imap-check.php
 */
header('Content-Type: text/plain; charset=UTF-8');
echo 'PHP ' . PHP_VERSION . "\n";
echo 'extension_loaded(imap): ' . (extension_loaded('imap') ? 'yes' : 'NO') . "\n";
echo 'function_exists(imap_open): ' . (function_exists('imap_open') ? 'yes' : 'NO') . "\n";
echo 'openssl: ' . (extension_loaded('openssl') ? 'yes' : 'NO') . "\n";
echo 'allow_url_fopen: ' . (ini_get('allow_url_fopen') ? 'yes' : 'no') . "\n";
if (function_exists('imap_open')) {
    echo "IMAP OK — sync can use real mailboxes.\n";
} else {
    echo "IMAP MISSING — enable 'imap' in cPanel Select PHP Version → Extensions.\n";
}
