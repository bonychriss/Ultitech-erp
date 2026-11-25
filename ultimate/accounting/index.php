<?php
/**
 * Directory index for /ultimate/accounting/ so Apache DirectorySlash
 * does not leave this folder without an entry (session/company context break).
 * Bridges into the physical ultimate/accounting.php stub ? app-root accounting.php.
 */
if (empty($_GET['company_slug'])) {
    $_GET['company_slug'] = 'ultimate';
}
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'accounting';
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'accounting.php';
