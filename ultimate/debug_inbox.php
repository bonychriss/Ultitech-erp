<?php
/**
 * Physical alias so /ultimate/debug_inbox.php works (company rewrite + on-disk ultimate/).
 * Live URL: https://ultitech.io/ultimate/debug_inbox.php
 */
if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'debug_inbox.php';
