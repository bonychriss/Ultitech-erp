<?php
declare(strict_types=1);

/**
 * Import revenue — Laravel Revenue desk.
 */
$_GET['desk'] = 'import';
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'revenue';
}
require __DIR__ . '/revenue_entries.php';
