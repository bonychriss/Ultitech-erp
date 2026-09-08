<?php
/**
 * Physical alias so /ultimate/select-module works (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'select-module.php';
