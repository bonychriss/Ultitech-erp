<?php
/**
 * Alias entry for Weekly Mission under /ultimate/todo/.
 */
if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'todo';
}

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'todo' . DIRECTORY_SEPARATOR . 'weekly_mission.php';
