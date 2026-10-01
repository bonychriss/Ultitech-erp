<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/public_html/tmp-lead-insight.php';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/weekly-tasks-ui/lib.php';
$pdo = $GLOBALS['pdo'];
$data = defined('DATA_DB_NAME') ? DATA_DB_NAME : '';
if ($data !== '') {
    $pdo->exec('USE `' . str_replace('`', '', $data) . '`');
}
$snap = weeklyTasksUiProcurementSnapshot($pdo, [-1, -2, -3]);
echo weeklyTasksUiLeadInsight($snap['leadRows']) . "\n";
