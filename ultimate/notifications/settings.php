<?php
/**
 * Physical alias so /ultimate/notifications/settings.php works.
 * Bridges into notifications/settings.php (Laravel + React notification settings).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'notifications' . DIRECTORY_SEPARATOR . 'settings.php';
