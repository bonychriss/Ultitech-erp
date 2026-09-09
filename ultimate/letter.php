<?php
/**
 * Physical alias so /ultimate/letter and /ultimate/modules/letter/index work.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'letter';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'letter.php';
