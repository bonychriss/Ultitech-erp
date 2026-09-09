<?php
/**
 * Physical alias so /ultimate/logout.php works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logout.php';
