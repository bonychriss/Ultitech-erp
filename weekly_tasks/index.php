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
$target = 'ai_assistant.php?' . http_build_query($params);
header('Location: ' . $target);
exit;
