<?php
/**
 * Physical alias so /ultimate/suggest and /ultimate/suggest.php work (on-disk ultimate/ folder).
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'suggest.php';
