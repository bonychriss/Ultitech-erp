<?php
/**
 * Bootstrap DB + main app (same pattern as stock/config/database.php).
 */
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($pdo)) {
    die('Database connection failed. Check includes/config.php');
}

ensureAttendanceClockModuleSchema();
