<?php
// Route proxy to the real outstanding invoices module used by the project.
$moduleDirectory = dirname(__DIR__, 3) . '/erp/outstanding-invoices';
if (!chdir($moduleDirectory)) {
    throw new RuntimeException('Outstanding invoices module directory is unavailable.');
}
require_once $moduleDirectory . '/index.php';
