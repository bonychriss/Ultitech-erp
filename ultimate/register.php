<?php
/**
 * Physical alias so /ultimate/register and /ultimate/register.php work (on-disk ultimate/ folder).
 * Live URL: http://localhost/public_html/ultimate/register
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'register.php';
