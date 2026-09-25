<?php
/**
 * Physical alias so /ultimate/notifications.php works (on-disk ultimate/ folder).
 * Bridges into notifications.php (Laravel + React notifications centre).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'notifications.php';
