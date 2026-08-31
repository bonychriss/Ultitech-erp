<?php
/**
 * Physical alias so /ultimate/debug_todo_index.php works when the ultimate/ directory exists on disk.
 */
if (!defined('ULTITECH_DIAGNOSTIC_SCRIPT')) {
    define('ULTITECH_DIAGNOSTIC_SCRIPT', true);
}
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = basename(dirname(__DIR__));
}

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'debug_todo_index.php';
