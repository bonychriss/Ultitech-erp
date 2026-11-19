<?php
/**
 * Physical alias so /ultimate/login and /ultimate/login.php work (on-disk ultimate/ folder).
 * Live URL: https://ultitech.io/ultimate/login
 */
define('ERP_SKIP_SYSTEM_FONT_OB', true);
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'login.php';
