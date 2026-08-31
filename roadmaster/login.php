<?php
/**
 * Physical alias so /roadmaster/login and /roadmaster/login.php work (on-disk roadmaster/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'roadmaster';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'login.php';
