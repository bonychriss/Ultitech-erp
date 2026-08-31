<?php
/**
 * Physical alias so /ultimate/login and /ultimate/login.php work (on-disk ultimate/ folder).
 * Live URL: http://localhost/public_html/ultimate/login
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'login.php';
