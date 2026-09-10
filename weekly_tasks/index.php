<?php
/**
 * Performance module landing — AI Assistant.
 */
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$params = $_GET;
if (!isset($params['module'])) {
    $params['module'] = 'tasks';
}
// Absolute company URL — relative Location breaks under /{slug}/weekly_tasks/ proxies.
$target = company_url('weekly_tasks/ai_assistant.php') . '?' . http_build_query($params);
header('Location: ' . $target);
exit;
