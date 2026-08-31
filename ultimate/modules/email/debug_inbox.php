<?php
/**
 * Physical alias for /ultimate/modules/email/debug_inbox.php
 * when the ultimate/ tree exists on disk (avoids 404 before rewrite).
 */
if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'debug_inbox.php';
